<?php

declare(strict_types=1);

namespace GafferSearch\Services;

use Fuzor\Index;
use GafferSearch\BuildContext;
use GafferSearch\Support\Labels;
use GafferSearch\Support\ProtectedDir;
use GafferSearch\Support\Requirements;
use GafferSearch\Support\Language\Languages;
use GafferSearch\Support\SearchRunner;

/**
 * Builds an index and keeps it in step with the content: full builds (every language's file,
 * built into a temp file and swapped in), the update log applied from this server's position
 * (`{index}.state.json`), which indexes need a build (missing, or built from another
 * definition: signature()), and the search for `wp gaffer-search search`. The files are
 * IndexFiles', the documents Documents', the checks against the database Verifier's.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
class IndexService
{
    private readonly IndexFiles $files;
    private readonly Documents $documents;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly IndexRegistry $registry,
        private readonly UpdateQueue $queue = new UpdateQueue(),
    ) {
        $this->files     = new IndexFiles($settings, $registry);
        $this->documents = new Documents($registry);
    }

    /** This site's index files on this server. */
    public function files(): IndexFiles
    {
        return $this->files;
    }

    /** What goes into an index. */
    public function documents(): Documents
    {
        return $this->documents;
    }

    /**
     * Rebuild one index, or every registered index — on a site with languages, every
     * language's file, each built with the site switched to that language (and switched back
     * afterwards).
     *
     * @return array<string, int> index name → documents indexed (all languages)
     * @throws IndexBusyException When an index is already being rebuilt.
     * @throws \RuntimeException  When the server lacks a requirement (Requirements).
     */
    public function build(?string $name = null): array
    {
        Requirements::assertMet();
        $names    = $name !== null ? [$name] : $this->registry->names();
        $counts   = [];
        $adapter  = Languages::adapter();
        $original = $adapter->current();

        try {
            foreach ($names as $n) {
                $start      = microtime(true);
                // Every change logged before this point is in what the build reads.
                $position   = $this->queue->head();
                $counts[$n] = 0;
                // Every language's lock before the first file: a second build is refused before it
                // builds anything, also while this one is on a later language.
                $locks = $this->files->acquireLocks($n);
                try {
                    // Everything this build reads is read after this moment: a full rebuild
                    // requested before it is done by it (ChangeTracker::schedule(), satisfiedBy()).
                    $started = microtime(true);
                    try {
                        foreach (Languages::codes() as $language) {
                            $counts[$n] += $this->buildOne($n, $language);
                        }
                    } finally {
                        // Beaten by buildOne() for the whole build (all languages), so building()
                        // has no gap between two languages' files.
                        State::stopBuilding($n);
                    }
                    $signature = $this->signature($n);
                    if ($signature !== null) {
                        State::recordBuilt($n, $signature, $this->registry->get($n)->version ?? '', $started, microtime(true) - $start);
                    }
                    $this->writeState($n, ['position' => $position, 'log' => $this->queue->id()]);
                    // A full build from WP-CLI clears an earlier failure too.
                    State::clearError($n);
                } finally {
                    $this->files->releaseLocks($locks);
                }
            }
        } finally {
            if ($original !== null) {
                $adapter->switch($original);
            }
        }

        do_action('gaffer_search_indexes_built', $names);
        return $counts;
    }

    /**
     * Bring this server's copy of an index up to date with the update log (UpdateQueue):
     *
     *  1. A copy whose position is older than the pruned part of the log has missed changes: it
     *     is rebuilt in full. So is one whose position was recorded in another log (another ID:
     *     the database was copied from another environment) or lies past the log's head.
     *  2. Up to $limit log rows after the position: those documents are rebuilt from the database
     *     — in every language's file, each with the site switched to that language — the ones the
     *     builder returns upserted, the rest deleted (deleted, unpublished, or in another
     *     language: a post ID is in one language's file only). The labels file is rewritten when
     *     a document has a value it has no name for, or a term was renamed.
     *
     * The position moves only after the files are written: a failed update is tried again. With
     * no rows of this index after it, it moves to the log's head, so a quiet index never falls
     * behind the pruned part (which rebuilt it in full every day).
     * Holds every language's build lock: a build and an update never write at the same time.
     *
     * @return array{updated: int, deleted: int, remaining: int, rebuilt: bool} remaining: log rows still after the position
     * @throws IndexBusyException When the index is being rebuilt on this server.
     * @throws \RuntimeException  When the server lacks a requirement (Requirements).
     */
    public function applyUpdates(string $name, int $limit = 1000): array
    {
        Requirements::assertMet();
        $result = ['updated' => 0, 'deleted' => 0, 'remaining' => 0, 'rebuilt' => false];
        $state  = $this->readState($name);

        $locks = $this->files->acquireLocks($name);
        try {
            if (!$this->files->isBuilt($name)) {
                // Nothing to update: the first build reads everything.
                return $result;
            }
            $log  = $this->queue->id();
            // Before reading the rows: every row of this index up to here is in what after() reads.
            $head = $this->queue->head();
            // No position (files copied in by hand): which changes they miss is unknown. A state
            // without a log ID was written before logs had one: its position is trusted once and
            // gets the ID with the next write (all of a site's servers would otherwise rebuild
            // at once after the update).
            if ($state !== null && ($state['log'] === null || $state['log'] === $log)
                && $state['position'] >= $this->queue->pruned() && $state['position'] <= $head) {
                $batch = $this->queue->after($name, $state['position'], $limit);
                $moved = false;
                if ($batch['last'] !== $state['position']) {
                    [$result['updated'], $result['deleted']] = $this->updateDocuments($name, $batch['ids']);
                    $state['position'] = $batch['last'];
                    $moved             = true;
                } elseif ($head > $state['position']) {
                    // Nothing for this index up to the head: stand there, ahead of the pruning.
                    $state['position'] = $head;
                    $moved             = true;
                }
                if ($moved || $state['log'] === null) {
                    $state['log'] = $log;
                    $this->writeState($name, $state);
                }
                $result['remaining'] = $this->queue->countAfter($name, $state['position']);
                return $result;
            }
        } finally {
            $this->files->releaseLocks($locks);
        }

        // Behind the pruned part of the log, or in another log: changes were missed.
        $this->build($name);
        $result['rebuilt'] = true;
        return $result;
    }

    /**
     * Put documents into the update log, as a change to them would (verify --fix: what changed
     * while nothing tracked it). Every server applies them with its next update.
     *
     * @param list<int> $ids
     */
    public function queueUpdates(string $name, array $ids): bool
    {
        return $ids === [] || $this->queue->add($name, ...$ids);
    }

    /**
     * Log rows this server hasn't applied to an index yet, and when the oldest was written (none
     * when it has no position: the next build covers them).
     *
     * @return array{count: int, since: ?int}
     */
    public function pendingUpdates(string $name): array
    {
        $state = $this->readState($name);
        // A position in another log: the next update rebuilds, nothing is waiting in this one.
        return $state === null || ($state['log'] !== null && $state['log'] !== $this->queue->id())
            ? ['count' => 0, 'since' => null]
            : $this->queue->pendingAfter($name, $state['position']);
    }

    /**
     * Rebuild these documents in every language's file (the caller holds the locks).
     *
     * @param  list<int>          $claimed Post IDs; UpdateQueue::LABELS for "rewrite the labels".
     * @return array{0: int, 1: int} [upserted, deleted]
     */
    private function updateDocuments(string $name, array $claimed): array
    {
        wp_raise_memory_limit('admin');
        // With their translations: a translation plugin may have synced a change to them unseen.
        $ids      = Languages::adapter()->translations(array_values(array_filter($claimed, fn(int $id): bool => $id !== UpdateQueue::LABELS)));
        $labels   = in_array(UpdateQueue::LABELS, $claimed, true);
        $adapter  = Languages::adapter();
        $original = $adapter->current();
        $updated  = 0;
        $deleted  = 0;

        try {
            foreach (Languages::codes() as $language) {
                if ($language !== null) {
                    $adapter->switch($language);
                }
                $context = $this->documents->context($language);
                $path    = $this->files->pathFor($name, $language);
                $failed  = [];
                $docs    = $ids === [] ? [] : iterator_to_array($this->documents->generateDocsFor($name, $context, $ids, $failed), false);
                // A document whose closure threw keeps its stored version: neither updated nor gone.
                $missing = array_values(array_diff($ids, array_map(fn(array $doc): int => (int) ($doc['id'] ?? 0), $docs), $failed));

                $index = new Index($path);
                // Only the ones in this file: a post is in one language's file.
                $gone = $missing === [] ? [] : array_keys(array_filter($index->hasMany(...$missing)));
                if ($docs !== []) {
                    $index->upsert($docs);
                }
                if ($gone !== []) {
                    $index->delete(...$gone);
                }
                $index->close();
                $this->files->forget($name, $language);

                if ($labels || Labels::lacksNames(Labels::read($path), $docs)) {
                    Labels::write($path, $this->documents->labelsFor($name, $context));
                }
                $updated += count($docs);
                $deleted += count($gone);
            }
        } finally {
            if ($original !== null) {
                $adapter->switch($original);
            }
        }
        return [$updated, $deleted];
    }

    /**
     * This server's position in the update log and the ID of that log (UpdateQueue::id(); null
     * in a state written before logs had one). Older states also hold a `generation`: ignored.
     *
     * @return array{position: int, log: ?string}|null null: never recorded (no build since)
     */
    private function readState(string $name): ?array
    {
        $raw  = @file_get_contents($this->statePath($name)); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) && isset($data['position'])
            ? ['position' => (int) $data['position'], 'log' => is_string($data['log'] ?? null) ? $data['log'] : null]
            : null;
    }

    /**
     * @param array{position: int, log: ?string} $state
     * @throws \RuntimeException When the file can't be written: the build or update fails, and is tried again.
     */
    private function writeState(string $name, array $state): void
    {
        $path = $this->statePath($name);
        $tmp  = $path . '.tmp-' . bin2hex(random_bytes(4));
        // A failed write (a full disk) keeps the previous state rather than an empty one, which
        // would read as "no position" and rebuild in full.
        if (file_put_contents($tmp, (string) wp_json_encode($state)) === false) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            throw new \RuntimeException("Gaffer Search couldn't write {$path}."); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        rename($tmp, $path); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic replace
    }

    /** Next to the index files, so it belongs to this server's copy (GAFFER_SEARCH_INDEX_DIR). */
    private function statePath(string $name): string
    {
        return $this->files->indexDir() . '/' . $this->files->fileSlug($name, null) . '.state.json';
    }







    /**
     * A search for `wp gaffer-search search`: the same SearchRunner path the endpoint uses.
     *
     * @return array<string, mixed>
     * @throws \RuntimeException When the index hasn't been built yet.
     */
    public function search(string $name, string $query, int $limit = 10): array
    {
        if (!$this->files->isBuilt($name)) {
            throw new \RuntimeException("Index '{$name}' not built. wp gaffer-search rebuild builds it."); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return SearchRunner::run($this->files->pathFor($name, Languages::current()), $query, SearchRunner::options(['limit' => $limit]));
    }

    public function deleteIndex(string $name): void
    {
        foreach (Languages::codes() as $language) {
            $this->files->removeIndexFiles($this->files->pathFor($name, $language));
            $this->files->forget($name, $language);
        }
        State::forget($name);
        @unlink($this->statePath($name)); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    }

    /**
     * The registered indexes a request should have built (Bootstrap schedules them, and the job
     * asks again before it builds): one that isn't built on this server (a new index, a deleted
     * file, a language added), and one whose builder changed
     * (changedBuilders()). With $backOff, not one whose last scheduled build failed less than an
     * hour ago: a build that fails isn't started again on every request.
     *
     * Cheap enough for every request: file stats and the signature; options only for an index
     * that needs a build.
     *
     * @return list<string>
     */
    public function needsBuild(bool $backOff = true): array
    {
        $names = [];
        foreach ($this->registry->names() as $name) {
            $built = true;
            foreach (Languages::codes() as $language) {
                // A stat, not Index::exists() (which opens the file): this runs on every request.
                $built = $built && file_exists($this->files->pathFor($name, $language));
            }
            if ($built && !$this->changed($name)) {
                continue;
            }
            if ($backOff && $this->failedRecently($name)) {
                continue;
            }
            $names[] = $name;
        }
        return $names;
    }

    /** The last scheduled build or update of the index failed less than an hour ago. */
    private function failedRecently(string $name): bool
    {
        $error = State::error($name);
        return $error !== null && $error['time'] > time() - HOUR_IN_SECONDS;
    }

    /**
     * Whether a full build that started after $requested (a microtime) finished: a rebuild
     * requested before it is done (a second one would read the same content).
     */
    public function satisfiedBy(string $name, float $requested): bool
    {
        $started = State::built($name)['started'] ?? null;
        return $started !== null && (float) $started > $requested && $this->files->isBuilt($name);
    }

    /**
     * The registered indexes whose builder changed since their files were built: a theme added a
     * facet, a meta field… Their files have the old schema (the new field can't be
     * filtered or sorted on) until they are rebuilt. A built index with no recorded signature
     * counts as changed; an unbuilt one isn't listed.
     *
     * @return list<string>
     */
    public function changedBuilders(): array
    {
        $changed = [];
        foreach ($this->registry->names() as $name) {
            if ($this->changed($name)) {
                $changed[] = $name;
            }
        }
        return $changed;
    }

    private function changed(string $name): bool
    {
        $recorded = State::built($name)['signature'] ?? null;
        return $recorded !== null ? $recorded !== $this->signature($name) : $this->files->isBuilt($name);
    }

    /**
     * The Fuzor file layout this plugin's indexes are built in. Bump it in the commit that moves
     * Fuzor's lock to a version whose files need a rebuild (a new column, a changed way of
     * storing values): it's part of every signature(), so each index rebuilds once after the
     * update. Until that rebuild is done, the old file is still what's searched.
     *
     * 2: Fuzor e5d62c4 (2026-11-09) reads `facet_keys.has_strings`, which older files don't have.
     * 3: Fuzor 781d65c (2026-11-12) reads `facet_keys.row_count` and the `facet_counts_number` index.
     * 4: Fuzor f8f1d59 (2026-11-15) reads the stemmer from the file's info, which older files don't have.
     */
    public const int FILE_FORMAT = 4;

    /**
     * A hash of what decides an index's files: its builder's config() and schema(), the theme's
     * `gaffer_search_index_version` (what its documents depend on besides the content: the
     * template of a rendered card, say), FILE_FORMAT, and what the documents' URLs are made of
     * (urls()): a database moved from staging to live keeps no staging URLs.
     */
    private function signature(string $name): ?string
    {
        $builder = $this->registry->builder($name);
        if ($builder === null) {
            return null;
        }
        // Without a language: the schema's language differs per language, the builder doesn't.
        $schema  = get_object_vars($builder->schema(new BuildContext(get_current_blog_id())));
        $parts   = [get_class($builder), $builder->config(), $schema];
        $definition = $this->registry->get($name);
        $version    = ($definition !== null ? $definition->version : '');
        // Only when set: an index built before the filter existed keeps its signature.
        if ($version !== '') {
            $parts[] = $version;
        }
        $parts[] = 'format ' . self::FILE_FORMAT;
        $parts[] = self::urls();
        return md5( (string) wp_json_encode($parts, JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * The options a permalink is made of: the site's address and the permalink settings
     * (WordPress's, WooCommerce's bases). The option, not home_url(): a language plugin filters
     * that per request's language, which would change the signature with the page's language.
     *
     * @return array<string, mixed>
     */
    private static function urls(): array
    {
        return [
            'home'      => get_option('home'),
            'structure' => get_option('permalink_structure'),
            'category'  => get_option('category_base'),
            'tag'       => get_option('tag_base'),
            'products'  => get_option('woocommerce_permalinks'),
        ];
    }




















    /** The definition `version` the index was last built from, null when unknown. */
    public function builtVersion(string $name): ?string
    {
        return State::built($name)['version'] ?? null;
    }

    /** Whether a build of the index is running (its heartbeat is recent). */
    public function isBuilding(string $name): bool
    {
        return State::building($name);
    }


    /**
     * Build one language's file of an index (null: the site has no languages). The caller holds
     * the index's build locks (acquireLocks()).
     */
    private function buildOne(string $name, ?string $language): int
    {
        $builder = $this->documents->builderFor($name);
        if ($language !== null) {
            Languages::adapter()->switch($language);
        }
        $context = $this->documents->context($language);

        $dir = $this->files->indexDir();
        ProtectedDir::ensure($dir);

        wp_raise_memory_limit('admin');
        set_time_limit(0);

        $path      = $this->files->pathFor($name, $language);
        $schema    = $this->documents->schemaFor($name, $context);
        $chunkSize = max(1, $builder->chunkSize());
        $synonyms  = $builder->synonyms();
        $count     = 0;
        $skipped   = [];

        // Synonyms are set on the new file before it replaces the old one, so a rebuild never
        // serves the new documents with stale synonyms — and removing them from the builder
        // actually removes them (rebuild() would otherwise carry the old ones over).
        $populate = function (Index $new) use ($name, $context, $path, $chunkSize, $synonyms, &$count, &$skipped): void {
            $new->clearSynonyms();
            if ($synonyms !== []) {
                $skipped = $new->setSynonyms(
                    equivalences: (array) ($synonyms['equivalences'] ?? []),
                    oneWay:       (array) ($synonyms['oneWay']       ?? []),
                );
            }

            // insert() reads the generator a chunk at a time (Config::$insertChunkSize), so
            // memory follows the chunk, not the index; the progress (isBuilding()) is updated
            // once per builder batch.
            $new->insert($this->documents->generateDocs($name, $context), progress: function (int $done) use ($name, $chunkSize, &$count): void {
                $count = $done;
                if ($done % $chunkSize === 0) {
                    State::heartbeat($name, $done);
                }
            });

            // Before the new index is swapped in, so its slugs never go out without names.
            Labels::write($path, $this->documents->labelsFor($name, $context));
        };

        // rebuild() opens the old file first (except one of a schema revision it rejects
        // outright): a file this Fuzor can't open — written by a development build of the
        // same revision, or damaged — would fail every build, so it goes.
        if (Index::exists($path) && !IndexFiles::opens($path)) {
            $this->files->removeIndexFiles($path);
        }

        // Removed by build() when every language is done.
        State::heartbeat($name, 0);
        // rebuild() builds into a temp file and swaps it in atomically — also for the very
        // first build, so a reader never sees a half-built index and a killed build leaves
        // only temp files (which the next build sweeps).
        $index = Index::rebuild($path, $populate, schema: $schema);

        if ($skipped !== []) {
            error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                "Gaffer Search: index '{$name}' skipped " . count($skipped)
                . ' synonym term(s) (multi-word terms are unsupported): ' . implode(', ', $skipped),
            );
        }

        $this->files->remember($name, $language, $index);
        return $count;
    }




}
