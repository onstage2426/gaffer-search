<?php

declare(strict_types=1);

namespace GafferSearch\Services;

use Fuzor\Index;
use GafferSearch\Support\IndexSlug;
use GafferSearch\Support\Labels;
use GafferSearch\Support\Language\Languages;
use GafferSearch\Support\ProtectedDir;

/**
 * An index's files on this server: their names (IndexSlug, per language), the index folder,
 * opening them (kept open for the request), the build locks, removing them, the files no
 * definition uses (orphans, a killed build's temp files), and their statistics.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class IndexFiles
{
    /** @var array<string, Index> "{name}|{language}" → open index */
    private array $indexes = [];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly IndexRegistry $registry,
    ) {}

    /** Keep an index this request just built open (stats() reads it). */
    public function remember(string $name, ?string $language, Index $index): void
    {
        $this->indexes["{$name}|{$language}"] = $index;
    }

    /** Close an index this request opened: it was written (an update) or removed. */
    public function forget(string $name, ?string $language): void
    {
        unset($this->indexes["{$name}|{$language}"]);
    }

    public function indexDir(): string
    {
        return (string) $this->settings->get('index_dir');
    }
    /** The file slug of one language's file of an index (null: the site has no languages). */
    public function fileSlug(string $name, ?string $language): string
    {
        return IndexSlug::compute($name, $this->settings->searchSecret(), $language);
    }
    /** One language's file of an index (null: the site has no languages). */
    public function pathFor(string $name, ?string $language): string
    {
        return $this->indexDir() . '/' . $this->fileSlug($name, $language) . '.db';
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
    /** Whether this Fuzor opens the index at $path. */
    public static function opens(string $path): bool
    {
        try {
            // A file missing info keys also raises "Undefined array key" warnings first.
            (@new Index($path, readonly: true))->close(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
    public function indexFor(string $name, ?string $language): Index
    {
        $key = "{$name}|{$language}";
        if (!isset($this->indexes[$key])) {
            $path = $this->pathFor($name, $language);
            if (!Index::exists($path)) {
                throw new \RuntimeException("Index '{$name}' not built. wp gaffer-search rebuild builds it."); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            $this->indexes[$key] = new Index($path, readonly: true);
        }

        return $this->indexes[$key];
    }
    /**
     * Every language's build lock of an index.
     *
     * @return list<resource>
     * @throws \RuntimeException  When the index folder can't be created.
     * @throws IndexBusyException
     */
    public function acquireLocks(string $name): array
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
    /** @param list<resource> $locks */
    public function releaseLocks(array $locks): void
    {
        foreach ($locks as $lock) {
            flock($lock, LOCK_UN);
            fclose($lock); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- flock() needs a native handle
        }
    }
    public function removeIndexFiles(string $path): void
    {
        Labels::delete($path);
        if (is_dir(dirname($path))) {
            // The path, every published version, their sidecars, and leftover temp files.
            Index::remove($path);
        }
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
    public function isOrphan(string $slug): bool
    {
        if (!Index::exists($this->indexDir() . '/' . $slug . '.db')) {
            return false;
        }
        return !in_array($slug, $this->knownSlugs(), true);
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
    public function deleteOrphan(string $slug): void
    {
        $this->removeIndexFiles($this->indexDir() . '/' . $slug . '.db');
        @unlink($this->indexDir() . '/' . $slug . '.lock'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    }
    /**
     * The temp files cleanupTempFiles() would delete now (`wp gaffer-search clean` without
     * --delete): a killed build's, older than $minAgeSeconds.
     *
     * @return list<string>
     */
    public function tempFiles(int $minAgeSeconds = 3600): array
    {
        $files = [];
        foreach (glob($this->indexDir() . '/*.db.tmp-*') ?: [] as $file) {
            if (is_file($file) && time() - (int) filemtime($file) >= $minAgeSeconds) {
                $files[] = $file;
            }
        }
        return $files;
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

            $duration = State::built($name)['duration'] ?? null;

            $result[$name] = [
                'exists'              => $exists,
                'total_documents'     => $indexCount,
                'languages'           => $languages,
                'last_modified'       => $lastModified,
                'build_duration'      => $duration !== null ? $this->formatDuration( (float) $duration) : null,
                'schema'              => $schema,
                'schema_version'      => $schemaVersion,
            ];
        }

        return $result;
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
}
