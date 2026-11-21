<?php

declare(strict_types=1);

namespace GafferSearch\Services;

use Fuzor\Index;
use GafferSearch\Support\Language\Languages;
use GafferSearch\Support\Requirements;

/**
 * Whether an index holds what its builder produces now: all of it (verify(), `wp gaffer-search
 * verify`), these documents (verifyIds(), after `--fix`), or a sample (sampleIds(), the daily
 * health check).
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
class Verifier
{
    public function __construct(
        private readonly IndexFiles $files,
        private readonly Documents $documents,
    ) {}

    /**
     * Compare an index with what its builder produces right now — the proof that automatic
     * rebuilds (or anything else) left it in sync. Reads every document from both sides, with
     * documents() (a full build's path), never documentsFor(): the updates are what it checks.
     * Takes as long as reading the builder's documents, so it runs without a time limit.
     *
     * @return array{checked: int, missing: list<int>, extra: list<int>, stale: list<int>}
     *   missing — produced by the builder but not in the index; extra — in the index but no
     *   longer produced; stale — in both, with different content.
     */
    public function verify(string $name): array
    {
        Requirements::assertMet();
        $adapter  = Languages::adapter();
        $original = $adapter->current();
        $result   = ['checked' => 0, 'missing' => [], 'extra' => [], 'stale' => []];

        // Like a build: WordPress only raises the limit itself in wp-admin pages and WP-cron,
        // not in a CLI script.
        wp_raise_memory_limit('admin');
        set_time_limit(0);

        try {
            foreach (Languages::codes() as $language) {
                if ($language !== null) {
                    $adapter->switch($language);
                }
                $expected = [];
                foreach ($this->documents->generateDocs($name, $this->documents->context($language)) as $doc) {
                    $expected[ (int) ($doc['id'] ?? 0)] = self::fingerprint($doc);
                }
                $result['checked'] += count($expected);

                foreach ($this->files->indexFor($name, $language)->stream(500) as $id => $stored) {
                    if (!isset($expected[$id])) {
                        $result['extra'][] = $id;
                        continue;
                    }
                    if ($expected[$id] !== self::fingerprint($stored)) {
                        $result['stale'][] = $id;
                    }
                    unset($expected[$id]);
                }
                $result['missing'] = [...$result['missing'], ...array_keys($expected)];
            }
        } finally {
            if ($original !== null) {
                $adapter->switch($original);
            }
        }

        return $result;
    }
    /**
     * verify() for these documents only, through documentsFor() (the updates' path): whether
     * the documents verify --fix updated are written as the builder produces them now. Seconds
     * instead of a full verify; a builder whose full and per-document output differ only shows
     * in a full verify.
     *
     * @param  list<int> $ids
     * @return array{checked: int, missing: list<int>, extra: list<int>, stale: list<int>}
     */
    public function verifyIds(string $name, array $ids): array
    {
        $adapter  = Languages::adapter();
        $original = $adapter->current();
        $result   = ['checked' => 0, 'missing' => [], 'extra' => [], 'stale' => []];
        try {
            foreach (Languages::codes() as $language) {
                if ($language !== null) {
                    $adapter->switch($language);
                }
                $expected = [];
                $failed   = [];
                foreach ($ids === [] ? [] : $this->documents->generateDocsFor($name, $this->documents->context($language), $ids, $failed) as $doc) {
                    $expected[ (int) ($doc['id'] ?? 0)] = self::fingerprint($doc);
                }
                $stored = $ids === [] ? [] : $this->files->indexFor($name, $language)->getMany(...$ids);
                foreach (array_diff($ids, $failed) as $id) {
                    if (isset($expected[$id]) && !isset($stored[$id])) {
                        $result['missing'][] = $id;
                    } elseif (!isset($expected[$id]) && isset($stored[$id])) {
                        $result['extra'][] = $id;
                    } elseif (isset($expected[$id]) && $expected[$id] !== self::fingerprint($stored[$id])) {
                        $result['stale'][] = $id;
                    }
                }
                $result['checked'] += count($expected);
            }
        } finally {
            if ($original !== null) {
                $adapter->switch($original);
            }
        }

        return $result;
    }
    /**
     * Documents for a sample check (HealthCheck): about half drawn at random from the watched
     * posts in the database (what the index may be missing or hold outdated), half a slice of
     * the index at a random place (what it may hold that's gone). The first language's file
     * stands for the others: verifyIds() checks every language. Empty when the index isn't built.
     *
     * @return list<int>
     */
    public function sampleIds(string $name, int $count): array
    {
        global $wpdb;
        $language = Languages::codes()[0] ?? null;
        if (!Index::exists($this->files->pathFor($name, $language))) {
            return [];
        }
        $half  = max(1, intdiv($count, 2));
        $types = $this->documents->builderFor($name)->watches()->postTypes;
        $ids   = [];
        if ($types !== []) {
            $in  = implode(',', array_fill(0, count($types), '%s'));
            $ids = array_map(intval(...), (array) $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$in}) ORDER BY RAND() LIMIT %d", ...[...$types, $half]))); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery -- the table name and one %s per post type
        }
        $index = $this->files->indexFor($name, $language);
        $total = $index->count();
        if ($total > 0) {
            $result = $index->search('', new \Fuzor\SearchOptions(limit: $half, offset: random_int(0, max(0, $total - $half)), attributesToRetrieve: ['id']));
            foreach ($result->hits as $hit) {
                $ids[] = (int) ($hit['id'] ?? 0);
            }
        }
        return array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
    }
    /**
     * A content hash that ignores key order, for verify().
     *
     * @param array<string, mixed> $doc
     */
    private static function fingerprint(array $doc): string
    {
        $sort = static function (mixed $value) use (&$sort): mixed {
            if (!is_array($value)) {
                return $value;
            }
            if (!array_is_list($value)) {
                ksort($value);
            }
            return array_map($sort, $value);
        };
        return md5( (string) wp_json_encode($sort($doc), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
