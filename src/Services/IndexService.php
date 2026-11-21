<?php

declare(strict_types=1);

namespace GafferSearch\Services;

use Fuzor\Index;
use Fuzor\SchemaConfig;
use GafferSearch\BuildContext;
use GafferSearch\Builders\IndexBuilderInterface;
use GafferSearch\Support\IndexSlug;
use GafferSearch\Support\Labels;
use GafferSearch\Support\ProtectedDir;
use GafferSearch\Support\SharedLock;
use GafferSearch\Support\Requirements;
use GafferSearch\Support\Language\Languages;
use GafferSearch\Support\SearchRunner;

/** @internal Not part of the public API (see API.md): may change in any version. */
class IndexService
{
    /** @var array<string, Index> "{name}|{language}" → open index */
    private array $indexes = [];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly IndexRegistry $registry,
        private readonly UpdateQueue $queue = new UpdateQueue(),
    ) {}

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
                $locks  = $this->acquireLocks($n);
                $shared = null;
                try {
                    $shared = $this->acquireSharedLock($n);
                    // Everything this build reads is read after this moment: a full rebuild
                    // requested before it is done by it (ChangeTracker::schedule(), satisfiedBy()).
                    $started = microtime(true);
                    try {
                        foreach (Languages::codes() as $language) {
                            $counts[$n] += $this->buildOne($n, $language);
                        }
                    } finally {
                        // Set by buildOne() for the whole build (all languages), so isBuilding()
                        // has no gap between two languages' files.
                        delete_transient("gaffer_search_build_progress_{$n}");
                    }
                    set_transient("gaffer_search_build_duration_{$n}", round(microtime(true) - $start, 4), MONTH_IN_SECONDS);
                    $this->recordSignature($n, $this->signature($n), $started);
                    $generation = $this->settings->snapshotDir() !== null ? $this->publishSnapshot($n, $position) : 0;
                    $this->writeState($n, ['position' => $position, 'generation' => $generation, 'log' => $this->queue->id()]);
                    // A full build from WP-CLI or the dashboard clears an earlier failure too.
                    delete_transient('gaffer_search_cron_error_' . $n);
                } finally {
                    if ($shared !== null) {
                        SharedLock::release($shared);
                    }
                    $this->releaseLocks($locks);
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
     *  1. With GAFFER_SEARCH_SNAPSHOT_DIR, a full rebuild newer than this server's copy (built on another
     *     server) is copied in first, with the log position it was built at.
     *  2. A copy whose position is older than the pruned part of the log has missed changes: it
     *     is rebuilt in full. So is one whose position was recorded in another log (another ID:
     *     the database was copied from another environment) or lies past the log's head.
     *  3. Up to $limit log rows after the position: those documents are rebuilt from the database
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
     * @return array{updated: int, deleted: int, remaining: int, imported: bool, rebuilt: bool} remaining: log rows still after the position
     * @throws IndexBusyException When the index is being rebuilt on this server.
     * @throws \RuntimeException  When the server lacks a requirement (Requirements).
     */
    public function applyUpdates(string $name, int $limit = 1000): array
    {
        Requirements::assertMet();
        $result   = ['updated' => 0, 'deleted' => 0, 'remaining' => 0, 'imported' => false, 'rebuilt' => false];
        $manifest = $this->snapshotManifest($name);
        $state    = $this->readState($name);

        $locks = $this->acquireLocks($name);
        try {
            if ($manifest !== null && ($state === null || !$this->isBuilt($name) || $manifest['generation'] > $state['generation'])
                && $this->snapshotComplete($name, $manifest['generation'])) {
                $this->importSnapshot($name, $manifest['generation']);
                $state = ['position' => $manifest['position'], 'generation' => $manifest['generation'], 'log' => $this->queue->id()];
                $this->writeState($name, $state);
                $result['imported'] = true;
            }
            if (!$this->isBuilt($name)) {
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
            $this->releaseLocks($locks);
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
                $context = $this->context($language);
                $path    = $this->pathFor($name, $language);
                $failed  = [];
                $docs    = $ids === [] ? [] : iterator_to_array($this->generateDocsFor($name, $context, $ids, $failed), false);
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
                unset($this->indexes["{$name}|{$language}"]);

                if ($labels || Labels::lacksNames(Labels::read($path), $docs)) {
                    Labels::write($path, $this->labelsFor($name, $context));
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
     * This server's position in the update log, the ID of that log (UpdateQueue::id(); null in a
     * state written before logs had one) and the snapshot generation its files come from.
     *
     * @return array{position: int, generation: int, log: ?string}|null null: never recorded (no build since)
     */
    private function readState(string $name): ?array
    {
        $raw  = @file_get_contents($this->statePath($name)); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) && isset($data['position'], $data['generation'])
            ? ['position' => (int) $data['position'], 'generation' => (int) $data['generation'], 'log' => is_string($data['log'] ?? null) ? $data['log'] : null]
            : null;
    }

    /** @param array{position: int, generation: int, log: ?string} $state */
    private function writeState(string $name, array $state): void
    {
        $path = $this->statePath($name);
        $tmp  = $path . '.tmp-' . bin2hex(random_bytes(4));
        file_put_contents($tmp, (string) wp_json_encode($state)); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        rename($tmp, $path); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic replace
    }

    /** Next to the index files, so it belongs to this server's copy (GAFFER_SEARCH_INDEX_DIR). */
    private function statePath(string $name): string
    {
        return $this->indexDir() . '/' . $this->fileSlug($name, null) . '.state.json';
    }

    /**
     * Copy a full rebuild into the snapshot folder for the other servers, as generation n + 1,
     * and announce it (option `gaffer_search_snapshot_{name}`: generation, log position, time). The
     * previous generation stays, for a server that is copying it right now; older ones go.
     *
     * @return int The new generation.
     * @throws \RuntimeException When the snapshot folder can't be created.
     */
    private function publishSnapshot(string $name, int $position): int
    {
        $dir = (string) $this->settings->snapshotDir();
        if (!ProtectedDir::ensure($dir)) {
            throw new \RuntimeException("Cannot create the snapshot folder {$dir}."); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        // From the database, not the option cache: another server may have published since
        // this process read it (a long WP-CLI run).
        wp_cache_delete("gaffer_search_snapshot_{$name}", 'options');
        wp_cache_delete('notoptions', 'options');
        $generation = ($this->snapshotManifest($name)['generation'] ?? 0) + 1;

        foreach (Languages::codes() as $language) {
            $local  = $this->pathFor($name, $language);
            $target = $this->snapshotPath($name, $language, $generation);
            $index  = new Index($local, readonly: true);
            $index->snapshotTo($target);
            $index->close();
            if (is_file(Labels::path($local))) {
                copy(Labels::path($local), Labels::path($target));
            }
            foreach (glob($dir . '/' . $this->fileSlug($name, $language) . '.g*.db') ?: [] as $old) {
                if (preg_match('/\.g(\d+)\.db$/', $old, $m) === 1 && (int) $m[1] < $generation - 1) {
                    Index::remove($old);
                    wp_delete_file(Labels::path($old));
                }
            }
        }

        update_option("gaffer_search_snapshot_{$name}", ['generation' => $generation, 'position' => $position, 'time' => time()], false);
        return $generation;
    }

    /**
     * Replace this server's files with a published generation (the caller holds the locks).
     * Index::install() copies each file next to its target, checks that it is a whole index (a
     * copy cut short on a full disk is refused), and publishes it like a rebuild, so readers see
     * the old file or the new one, never part of either.
     *
     * @throws \RuntimeException When a file of the generation is missing.
     */
    private function importSnapshot(string $name, int $generation): void
    {
        $dir = $this->indexDir();
        ProtectedDir::ensure($dir);
        foreach (Languages::codes() as $language) {
            $source = $this->snapshotPath($name, $language, $generation);
            $local  = $this->pathFor($name, $language);
            if (!Index::exists($source)) {
                throw new \RuntimeException("Snapshot generation {$generation} of index '{$name}' is missing in {$source}."); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            Index::install($source, $local);
            if (is_file(Labels::path($source))) {
                $tmp = Labels::path($local) . '.tmp-' . bin2hex(random_bytes(4));
                copy(Labels::path($source), $tmp);
                rename($tmp, Labels::path($local)); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic replace
            }
            unset($this->indexes["{$name}|{$language}"]);
        }
    }

    /** @return array{generation: int, position: int, time: int}|null The last published full rebuild; null without GAFFER_SEARCH_SNAPSHOT_DIR or before the first. */
    private function snapshotManifest(string $name): ?array
    {
        if ($this->settings->snapshotDir() === null) {
            return null;
        }
        $raw = get_option("gaffer_search_snapshot_{$name}");
        return is_array($raw) && isset($raw['generation'], $raw['position'])
            ? ['generation' => (int) $raw['generation'], 'position' => (int) $raw['position'], 'time' => (int) ($raw['time'] ?? 0)]
            : null;
    }

    private function snapshotPath(string $name, ?string $language, int $generation): string
    {
        return $this->settings->snapshotDir() . '/' . $this->fileSlug($name, $language) . ".g{$generation}.db";
    }

    /**
     * Whether a published generation has every language's file (file stats only): one published
     * before a language was added can't be imported; a build makes the next generation.
     */
    private function snapshotComplete(string $name, int $generation): bool
    {
        foreach (Languages::codes() as $language) {
            if (!file_exists($this->snapshotPath($name, $language, $generation))) {
                return false;
            }
        }
        return true;
    }

    /**
     * With snapshots, the full-rebuild lock of an index across this site's servers (SharedLock):
     * every server can start a rebuild (its cron, WP-cron, the dashboard), one builds and
     * publishes, the others import its generation.
     *
     * @return string|null The lock's key to release; null without snapshots (no lock).
     * @throws IndexBusyException When another server is rebuilding the index.
     */
    private function acquireSharedLock(string $name): ?string
    {
        if ($this->settings->snapshotDir() === null) {
            return null;
        }
        $key = 'build|' . get_current_blog_id() . '|' . $name;
        if (!SharedLock::acquire($key)) {
            throw new IndexBusyException("Index '{$name}' is already being rebuilt on another server.", elsewhere: true); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $key;
    }

    /**
     * Every language's build lock of an index.
     *
     * @return list<resource>
     * @throws \RuntimeException  When the index folder can't be created.
     * @throws IndexBusyException
     */
    private function acquireLocks(string $name): array
    {
        $dir = $this->indexDir();
        if (!ProtectedDir::ensure($dir)) {
            // wp_mkdir_p() refuses a path with ../ in it (WP-CLI with a relative --path) and fails
            // on permissions: name the folder, not the lock file that can't be opened in it.
            throw new \RuntimeException("Cannot create the index folder {$dir}" . (str_contains($dir, '..') ? ' (a path with ../ in it: run WP-CLI with an absolute --path)' : ': check that it is writable') . '.'); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        $locks = [];
        try {
            foreach (Languages::codes() as $language) {
                $locks[] = $this->acquireLock($name, $language);
            }
        } catch (IndexBusyException $e) {
            $this->releaseLocks($locks);
            throw $e;
        }
        return $locks;
    }

    /** The file slug of one language's file of an index (null: the site has no languages). */
    public function fileSlug(string $name, ?string $language): string
    {
        return IndexSlug::compute($name, $this->settings->searchSecret(), $language);
    }

    /** Every language's file exists. */
    public function isBuilt(string $name): bool
    {
        foreach (Languages::codes() as $language) {
            if (!Index::exists($this->pathFor($name, $language))) {
                return false;
            }
        }
        return true;
    }

    public function isOrphan(string $slug): bool
    {
        if (!Index::exists($this->indexDir() . '/' . $slug . '.db')) {
            return false;
        }
        return !in_array($slug, $this->knownSlugs(), true);
    }

    /** @return list<string> The file slugs of every registered index in every language. */
    private function knownSlugs(): array
    {
        $slugs = [];
        foreach ($this->registry->names() as $name) {
            foreach (Languages::codes() as $language) {
                $slugs[] = $this->fileSlug($name, $language);
            }
        }
        return $slugs;
    }

    public function deleteOrphan(string $slug): void
    {
        $this->removeIndexFiles($this->indexDir() . '/' . $slug . '.db');
        @unlink($this->indexDir() . '/' . $slug . '.lock'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    }

    /**
     * Admin-dashboard preview search — the same SearchRunner path the endpoint uses.
     *
     * @return array<string, mixed>
     * @throws \RuntimeException When the index hasn't been built yet.
     */
    public function search(string $name, string $query, int $limit = 10): array
    {
        if (!$this->isBuilt($name)) {
            throw new \RuntimeException("Index '{$name}' not built. Run a rebuild from the Dashboard."); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return SearchRunner::run($this->pathFor($name, Languages::current()), $query, SearchRunner::options(['limit' => $limit]));
    }

    public function deleteIndex(string $name): void
    {
        foreach (Languages::codes() as $language) {
            $this->removeIndexFiles($this->pathFor($name, $language));
            unset($this->indexes["{$name}|{$language}"]);
        }
        delete_transient("gaffer_search_build_duration_{$name}");
        delete_transient("gaffer_search_synonym_skipped_{$name}");
        $this->recordSignature($name, null);
        @unlink($this->statePath($name)); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    }

    /**
     * The registered indexes a request should have built (Bootstrap schedules them, and the job
     * asks again before it builds): one that isn't built on this server (a new index, a deleted
     * file, a language added; with GAFFER_SEARCH_SNAPSHOT_DIR only when there's no whole
     * published generation to copy in, which `update` does), and one whose builder changed
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
                $built = $built && file_exists($this->pathFor($name, $language));
            }
            if ($built ? !$this->changed($name) : $this->importable($name)) {
                continue;
            }
            if ($backOff && $this->failedRecently($name)) {
                continue;
            }
            $names[] = $name;
        }
        return $names;
    }

    /** With snapshots: a published generation this server can copy in instead of building. */
    private function importable(string $name): bool
    {
        $manifest = $this->snapshotManifest($name);
        return $manifest !== null && $this->snapshotComplete($name, $manifest['generation']);
    }

    /** The last scheduled build or update of the index failed less than an hour ago. */
    private function failedRecently(string $name): bool
    {
        $error = get_transient('gaffer_search_cron_error_' . $name);
        return is_array($error) && (int) ($error['time'] ?? 0) > time() - HOUR_IN_SECONDS;
    }

    /**
     * Whether a full build that started after $requested (a microtime) finished: a rebuild
     * requested before it is done (a second one would read the same content).
     */
    public function satisfiedBy(string $name, float $requested): bool
    {
        $started = self::built($name)['started'] ?? null;
        return $started !== null && (float) $started > $requested && $this->isBuilt($name);
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
        $recorded = self::built($name)['signature'] ?? null;
        return $recorded !== null ? $recorded !== $this->signature($name) : $this->isBuilt($name);
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

    /**
     * What an index was last built from: one option per index, not autoloaded. Never one array
     * for all indexes in an autoloaded option: with a persistent object cache (Redis) a build
     * holds the `alloptions` it loaded minutes earlier and writes it back whole, so another
     * process's newer value was lost and the index rebuilt over and over (a live
     * multisite, 2026-11-04). Builds of one index are serialized by its build lock.
     */
    private const string BUILT_OPTION = 'gaffer_search_built_';

    /** @return array{signature?: string, version?: string, started?: string} started: when the build began (microtime) */
    private static function built(string $name): array
    {
        self::migrateBuiltOptions();
        $built = get_option(self::BUILT_OPTION . $name, []);
        return is_array($built) ? array_filter($built, is_string(...)) : [];
    }

    private function recordSignature(string $name, ?string $signature, ?float $started = null): void
    {
        self::migrateBuiltOptions();
        if ($signature === null) {
            delete_option(self::BUILT_OPTION . $name);
            return;
        }
        $definition = $this->registry->get($name);
        $built      = ['signature' => $signature, 'version' => $definition !== null ? $definition->version : ''];
        if ($started !== null) {
            $built['started'] = sprintf('%.6F', $started);
        }
        update_option(self::BUILT_OPTION . $name, $built, false);
    }

    /** The arrays of before 2026-11-05 (gaffer_search_built_signatures/_versions) as one option per index, once. */
    private static function migrateBuiltOptions(): void
    {
        $signatures = get_option('gaffer_search_built_signatures');
        $versions   = get_option('gaffer_search_built_versions');
        if ($signatures === false && $versions === false) {
            return;
        }
        foreach (is_array($signatures) ? $signatures : [] as $name => $signature) {
            if (get_option(self::BUILT_OPTION . $name) === false) {
                add_option(self::BUILT_OPTION . $name, ['signature' => (string) $signature, 'version' => (string) (( (array) $versions)[$name] ?? '')], '', false);
            }
        }
        delete_option('gaffer_search_built_signatures');
        delete_option('gaffer_search_built_versions');
    }

    /**
     * Remove the {path}.tmp-* files a rebuild leaves behind when its process is killed mid-build
     * — for every registered index, every orphan, and temp files whose index file no longer
     * exists at all (a killed first build). Fuzor never touches a build that is still running.
     *
     * @return list<string> The deleted paths.
     */
    public function cleanupTempFiles(int $minAgeSeconds = 3600): array
    {
        $dir = $this->indexDir();
        if (!is_dir($dir)) {
            return [];
        }

        $paths = array_map(fn(string $slug): string => $this->indexDir() . "/{$slug}.db", $this->knownSlugs());
        foreach (glob($dir . '/*.db') ?: [] as $file) {
            $paths[] = $file;
        }
        foreach (glob($dir . '/*.db.tmp-*') ?: [] as $file) {
            $paths[] = (string) preg_replace('/\.tmp-[0-9a-f]+.*$/', '', $file);
        }

        $deleted = [];
        foreach (array_unique($paths) as $path) {
            $deleted = [...$deleted, ...Index::cleanupTempFiles($path, $minAgeSeconds)];
        }
        return $deleted;
    }

    /**
     * Compare an index with what its builder produces right now — the proof that automatic
     * rebuilds (or anything else) left it in sync. Reads every document from both sides, with
     * documents() (a full build's path), never documentsFor(): the updates are what it checks.
     * Takes as long as reading the builder's documents, so it runs without a time limit and
     * reports its progress in the `gaffer_search_verify_progress_{$name}` transient (done, total: the
     * documents read so far, and how many the index holds), which the dashboard polls.
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
        // not in admin-ajax (the dashboard's Verify) or a CLI script.
        wp_raise_memory_limit('admin');
        set_time_limit(0);

        $transient = "gaffer_search_verify_progress_{$name}";
        $total     = 0;
        foreach (Languages::codes() as $language) {
            $total += Index::exists($this->pathFor($name, $language)) ? $this->indexFor($name, $language)->count() : 0;
        }
        $done = 0;
        set_transient($transient, ['done' => 0, 'total' => $total], 300);

        try {
            foreach (Languages::codes() as $language) {
                if ($language !== null) {
                    $adapter->switch($language);
                }
                $expected = [];
                foreach ($this->generateDocs($name, $this->context($language)) as $doc) {
                    $expected[ (int) ($doc['id'] ?? 0)] = self::fingerprint($doc);
                    $done++;
                    if ($done % 250 === 0) {
                        set_transient($transient, ['done' => $done, 'total' => $total], 300);
                    }
                }
                $result['checked'] += count($expected);

                foreach ($this->indexFor($name, $language)->stream(500) as $id => $stored) {
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
            delete_transient($transient);
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
                foreach ($ids === [] ? [] : $this->generateDocsFor($name, $this->context($language), $ids, $failed) as $doc) {
                    $expected[ (int) ($doc['id'] ?? 0)] = self::fingerprint($doc);
                }
                $stored = $ids === [] ? [] : $this->indexFor($name, $language)->getMany(...$ids);
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
        if (!Index::exists($this->pathFor($name, $language))) {
            return [];
        }
        $half  = max(1, intdiv($count, 2));
        $types = $this->builderFor($name)->watches()->postTypes;
        $ids   = [];
        if ($types !== []) {
            $in  = implode(',', array_fill(0, count($types), '%s'));
            $ids = array_map(intval(...), (array) $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$in}) ORDER BY RAND() LIMIT %d", ...[...$types, $half]))); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery -- the table name and one %s per post type
        }
        $index = $this->indexFor($name, $language);
        $total = $index->count();
        if ($total > 0) {
            $result = $index->search('', new \Fuzor\SearchOptions(limit: $half, offset: random_int(0, max(0, $total - $half)), attributesToRetrieve: ['id']));
            foreach ($result->hits as $hit) {
                $ids[] = (int) ($hit['id'] ?? 0);
            }
        }
        return array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
    }

    /** @return array<string, array<string, mixed>> Per index name. */
    public function stats(): array
    {
        $result = [];

        foreach ($this->registry->names() as $name) {
            $exists        = true;
            $indexCount    = null;
            $lastModified  = null;
            $schema        = null;
            $schemaVersion = null;
            $languages     = [];

            foreach (Languages::codes() as $language) {
                $path = $this->pathFor($name, $language);
                if (!Index::exists($path)) {
                    $exists = false;
                    if ($language !== null) {
                        $languages[$language] = null;
                    }
                    continue;
                }
                try {
                    $idx          = $this->indexFor($name, $language);
                    $count        = $idx->count();
                    $indexCount   = ($indexCount ?? 0) + $count;
                    $lastModified = max($lastModified ?? 0, Index::lastModified($path));
                    if ($language !== null) {
                        $languages[$language] = $count;
                    }
                    // The current language's file describes the index (all files share the
                    // builder's fields).
                    if ($schema === null || $language === Languages::current()) {
                        $schemaVersion = $idx->schemaVersion;
                        $schema        = [
                            'searchable_fields' => $idx->searchableAttributes,
                            'filterable_fields' => $idx->filterableAttributes,
                            'sortable_fields'   => $idx->sortableAttributes,
                        ];
                    }
                } catch (\Throwable) {
                    // Corrupt or unreadable index; report nulls.
                }
            }

            $duration = get_transient("gaffer_search_build_duration_{$name}");

            $result[$name] = [
                'exists'              => $exists,
                'total_documents'     => $indexCount,
                'languages'           => $languages,
                'last_modified'       => $lastModified,
                'build_duration'      => $duration !== false ? $this->formatDuration( (float) $duration) : null,
                'schema'              => $schema,
                'schema_version'      => $schemaVersion,
            ];
        }

        return $result;
    }

    /**
     * Index files that exist on disk but have no registered definition.
     *
     * @return array<string, array{name: string, total_documents: int|null, last_modified: int|null}>
     */
    public function orphanStats(): array
    {
        $dir = $this->indexDir();
        if (!is_dir($dir)) {
            return [];
        }

        $knownSlugs = array_fill_keys($this->knownSlugs(), true);

        $orphans = [];
        foreach (glob($dir . '/*.db') ?: [] as $file) {
            $slug = basename($file, '.db');
            if (isset($knownSlugs[$slug])) {
                continue;
            }
            // Strip trailing _<12hexchars> to recover the readable name.
            $displayName = (string) preg_replace('/_[0-9a-f]{12}$/', '', $slug);
            try {
                $idx            = new Index($file, readonly: true);
                $orphans[$slug] = [
                    'name'            => $displayName,
                    'total_documents' => $idx->count(),
                    'last_modified'   => Index::lastModified($file),
                ];
            } catch (\Throwable) {
                $orphans[$slug] = ['name' => $displayName, 'total_documents' => null, 'last_modified' => null];
            }
        }

        return $orphans;
    }

    /** One language's file of an index (null: the site has no languages). */
    public function pathFor(string $name, ?string $language): string
    {
        return $this->indexDir() . '/' . $this->fileSlug($name, $language) . '.db';
    }

    private function context(?string $language): BuildContext
    {
        return new BuildContext(get_current_blog_id(), $language);
    }

    private function indexDir(): string
    {
        return (string) $this->settings->get('index_dir');
    }

    private function removeIndexFiles(string $path): void
    {
        Labels::delete($path);
        if (is_dir(dirname($path))) {
            // The path, every published version, their sidecars, and leftover temp files.
            Index::remove($path);
        }
    }

    private function formatDuration(float $seconds): string
    {
        if ($seconds >= 3600) {
            return sprintf('%dh %dm', (int) ($seconds / 3600), (int) (( (int) $seconds % 3600) / 60));
        }
        if ($seconds >= 60) {
            return sprintf('%dm %ds', (int) ($seconds / 60), (int) $seconds % 60);
        }
        if ($seconds >= 1) {
            return sprintf('%.1fs', $seconds);
        }
        $ms = $seconds * 1000;
        if ($ms >= 1) {
            return sprintf('%.0fms', $ms);
        }
        return sprintf('%.0fμs', $ms * 1000);
    }

    private function builderFor(string $name): IndexBuilderInterface
    {
        $builder = $this->registry->builder($name);
        if ($builder === null) {
            throw new \RuntimeException("No index registered as '{$name}'."); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $builder;
    }

    private function schemaFor(string $name, BuildContext $context): SchemaConfig
    {
        return self::withoutDuplicates($this->builderFor($name)->schema($context));
    }

    /**
     * $schema with each field listed once: a `gaffer_search_index_schema` filter that appends a field the
     * builder already declares (`[...$schema->filterableAttributes, 'brand']`) must not list it twice.
     */
    private static function withoutDuplicates(SchemaConfig $schema): SchemaConfig
    {
        $unique = static fn(?array $fields): ?array => $fields !== null ? array_values(array_unique($fields)) : null;
        if ($unique($schema->filterableAttributes) === $schema->filterableAttributes
            && $unique($schema->sortableAttributes) === $schema->sortableAttributes
            && $unique($schema->searchableAttributes) === $schema->searchableAttributes) {
            return $schema;
        }
        return new SchemaConfig(
            stemmer:              $schema->stemmer,
            store:                $schema->store,
            filterableAttributes: $unique($schema->filterableAttributes) ?? [],
            sortableAttributes:   $unique($schema->sortableAttributes) ?? [],
            searchableAttributes: $unique($schema->searchableAttributes),
            stripHtml:            $schema->stripHtml,
            stopWords:            $schema->stopWords,
        );
    }

    /**
     * The builder's documents, each passed through the `gaffer_search_index_document` filter —
     * return a changed array to adjust a document, or null to leave it out of the index.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function generateDocs(string $name, BuildContext $context): \Generator
    {
        foreach ($this->builderFor($name)->documents($context) as $doc) {
            $doc = $this->decorate($doc, $name);
            if (is_array($doc)) {
                yield $doc;
            }
        }
    }

    /**
     * The builder's current documents for these IDs, through the same filter as generateDocs().
     * One the theme's `document` closure throws for is left out and its ID added to $failed
     * (logged): one broken product must not stop every later change from reaching the index.
     * A full build still fails on it (contract: the old index stays, status() has the error).
     *
     * @param  list<int> $ids
     * @param  list<int> $failed
     * @return \Generator<int, array<string, mixed>>
     */
    private function generateDocsFor(string $name, BuildContext $context, array $ids, array &$failed): \Generator
    {
        foreach ($this->builderFor($name)->documentsFor($context, ...$ids) as $doc) {
            try {
                $doc = $this->decorate($doc, $name);
            } catch (\Throwable $e) {
                $failed[] = (int) ($doc['id'] ?? 0);
                error_log("Gaffer Search: index \"{$name}\" kept document " . (int) ($doc['id'] ?? 0) . " as stored: its document closure threw {$e->getMessage()}"); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                continue;
            }
            if (is_array($doc)) {
                yield $doc;
            }
        }
    }

    /**
     * The names to write next to an index: the definition's `labels` callable's (contract 1)
     * for the values of fields without terms, and the builder's term names, which win for a
     * taxonomy field. Only string names of scalar values are kept.
     *
     * @return array<string, array<string, string>>
     */
    private function labelsFor(string $name, BuildContext $context): array
    {
        $labels   = [];
        $callable = $this->registry->get($name)?->labels;
        foreach ($callable !== null ? (array) $callable($name) : [] as $field => $names) {
            foreach (is_array($names) ? $names : [] as $value => $label) {
                if (is_scalar($label)) {
                    $labels[ (string) $field][ (string) $value] = (string) $label;
                }
            }
        }

        return [...$labels, ...$this->builderFor($name)->labels($context)];
    }

    /**
     * A built document with what the definition's `document` callable adds (contract 1).
     *
     * @param  array<string, mixed> $doc
     * @return array<string, mixed>|null
     */
    private function decorate(array $doc, string $name): ?array
    {
        $definition = $this->registry->get($name);
        if ($definition?->document === null) {
            return $doc;
        }
        $doc = ($definition->document)($doc, $name);
        if (!is_array($doc)) {
            return null;
        }
        // The declared fields (document_fields) as their type, as meta values are: numbers sort
        // and filter as numbers. One the closure leaves out (or gives null, or a number field
        // something that isn't a number) is missing, never 0.
        foreach ($definition->fields as $field => $type) {
            $value  = $doc[$field] ?? null;
            $usable = $type === 'string' ? is_scalar(...) : is_numeric(...);
            $value  = is_array($value) ? array_values(array_filter($value, $usable)) : $value;
            if ($value === null || $value === [] || (!is_array($value) && !$usable($value))) {
                unset($doc[$field]);
                continue;
            }
            $cast        = static fn(mixed $v): int|float|string => match ($type) {
                'int'   => (int) $v,
                'float' => (float) $v,
                default => (string) $v,
            };
            $doc[$field] = is_array($value) ? array_map($cast, $value) : $cast($value);
        }

        return $doc;
    }

    /** The definition `version` the index was last built from, null when unknown. */
    public function builtVersion(string $name): ?string
    {
        return self::built($name)['version'] ?? null;
    }

    /** Whether a build of the index is running (its progress is reported while it does). */
    public function isBuilding(string $name): bool
    {
        return get_transient("gaffer_search_build_progress_{$name}") !== false;
    }

    /** @param list<resource> $locks */
    private function releaseLocks(array $locks): void
    {
        foreach ($locks as $lock) {
            flock($lock, LOCK_UN);
            fclose($lock); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- flock() needs a native handle
        }
    }

    /**
     * Build one language's file of an index (null: the site has no languages). The caller holds
     * the index's build locks (acquireLocks()).
     */
    private function buildOne(string $name, ?string $language): int
    {
        $builder = $this->builderFor($name);
        if ($language !== null) {
            Languages::adapter()->switch($language);
        }
        $context = $this->context($language);

        $dir = $this->indexDir();
        ProtectedDir::ensure($dir);

        wp_raise_memory_limit('admin');
        set_time_limit(0);

        $path      = $this->pathFor($name, $language);
        $schema    = $this->schemaFor($name, $context);
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
            // memory follows the chunk, not the index; the dashboard's progress is updated once
            // per builder batch.
            $new->insert($this->generateDocs($name, $context), progress: function (int $done) use ($name, $chunkSize, &$count): void {
                $count = $done;
                if ($done % $chunkSize === 0) {
                    set_transient("gaffer_search_build_progress_{$name}", $done, 300);
                }
            });

            // Before the new index is swapped in, so its slugs never go out without names.
            Labels::write($path, $this->labelsFor($name, $context));
        };

        // rebuild() opens the old file first (except one of a schema revision it rejects
        // outright): a file this Fuzor can't open — written by a development build of the
        // same revision, or damaged — would fail every build, so it goes.
        if (Index::exists($path) && !self::opens($path)) {
            $this->removeIndexFiles($path);
        }

        // Removed by build() when every language is done.
        set_transient("gaffer_search_build_progress_{$name}", 0, 300);
        // rebuild() builds into a temp file and swaps it in atomically — also for the very
        // first build, so a reader never sees a half-built index and a killed build leaves
        // only temp files (which the next build sweeps).
        $index = Index::rebuild($path, $populate, schema: $schema);

        if ($skipped !== []) {
            set_transient("gaffer_search_synonym_skipped_{$name}", $skipped, MONTH_IN_SECONDS);
            error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                "Gaffer Search: index '{$name}' skipped " . count($skipped)
                . ' synonym term(s) (multi-word terms are unsupported): ' . implode(', ', $skipped),
            );
        } else {
            delete_transient("gaffer_search_synonym_skipped_{$name}");
        }

        $this->indexes["{$name}|{$language}"] = $index;
        return $count;
    }

    /**
     * An exclusive, non-blocking lock per index, held for the whole build. flock() is released
     * by the OS when a process dies, so a killed build never leaves the index locked — unlike a
     * transient or object-cache flag, which also only works across processes with a persistent
     * object cache.
     *
     * @return resource
     * @throws \RuntimeException  When the lock file can't be opened.
     * @throws IndexBusyException When another build holds the lock.
     */
    private function acquireLock(string $name, ?string $language)
    {
        $handle = fopen($this->indexDir() . '/' . $this->fileSlug($name, $language) . '.lock', 'c'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if ($handle === false) {
            throw new \RuntimeException("Cannot open the build lock for index '{$name}'."); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- flock() needs a native handle
            throw new IndexBusyException("Index '{$name}' is already being rebuilt."); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $handle;
    }

    /** Whether this Fuzor opens the index at $path. */
    private static function opens(string $path): bool
    {
        try {
            // A file missing info keys also raises "Undefined array key" warnings first.
            (@new Index($path, readonly: true))->close(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function indexFor(string $name, ?string $language): Index
    {
        $key = "{$name}|{$language}";
        if (!isset($this->indexes[$key])) {
            $path = $this->pathFor($name, $language);
            if (!Index::exists($path)) {
                throw new \RuntimeException("Index '{$name}' not built. Run a rebuild from the Dashboard."); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            $this->indexes[$key] = new Index($path, readonly: true);
        }

        return $this->indexes[$key];
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
