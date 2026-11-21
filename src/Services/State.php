<?php

declare(strict_types=1);

namespace GafferSearch\Services;

/**
 * What the plugin keeps about an index (and the site) in the database, in one place: one option
 * per key, never autoloaded. Never one array for several keys: with a persistent object cache
 * a long process (a build) writes back the whole `alloptions` it loaded at its start, and a
 * value another process wrote meanwhile is lost (a live multisite rebuilt in a loop, 2026-11-04).
 * No transients for state that decides anything: an object cache may evict them.
 *
 * Elsewhere, each with its own owner: the settings and secrets (SettingsService), the update
 * log's bookkeeping (UpdateQueue), the schedules (`gaffer_search_known_indexes`, Bootstrap),
 * and per server, next to the index files: the log position (`{index}.state.json`,
 * IndexService), the labels (Labels) and the build locks.
 *
 * | Option                                   | Written by                            |
 * |------------------------------------------|---------------------------------------|
 * | `gaffer_search_built_{index}`            | a finished build                      |
 * | `gaffer_search_error_{index}`            | a failed scheduled build or update    |
 * | `gaffer_search_building_{index}`         | a running build, every batch          |
 * | `gaffer_search_rebuild_requested_{index}`| ChangeTracker::schedule()             |
 * | `gaffer_search_drift_{index}`            | the daily health check                |
 * | `gaffer_search_served`                   | the daily health check (per site)     |
 *
 * @internal
 */
final class State
{
    /** A building heartbeat older than this is a build that died (killed, out of memory). */
    public const int BUILDING_STALE = 300;

    /**
     * What the index was last built from: its signature (IndexService), the definition's
     * `version`, when the build started (a microtime, as a string) and how long it took (s).
     *
     * @return array{signature?: string, version?: string, started?: string, duration?: string}
     */
    public static function built(string $index): array
    {
        self::migrateBuiltArrays();
        $built = get_option("gaffer_search_built_{$index}", []);
        return is_array($built) ? array_filter($built, is_string(...)) : [];
    }

    public static function recordBuilt(string $index, string $signature, string $version, float $started, float $duration): void
    {
        update_option("gaffer_search_built_{$index}", [
            'signature' => $signature,
            'version'   => $version,
            'started'   => sprintf('%.6F', $started),
            'duration'  => sprintf('%.4F', $duration),
        ], false);
    }

    /**
     * The last scheduled build's or update's failure, until a build or update succeeds.
     *
     * @return array{message: string, time: int}|null
     */
    public static function error(string $index): ?array
    {
        $error = get_option("gaffer_search_error_{$index}");
        return is_array($error) && is_string($error['message'] ?? null) ? ['message' => $error['message'], 'time' => (int) ($error['time'] ?? 0)] : null;
    }

    public static function setError(string $index, string $message): void
    {
        update_option("gaffer_search_error_{$index}", ['message' => $message, 'time' => time()], false);
    }

    public static function clearError(string $index): void
    {
        delete_option("gaffer_search_error_{$index}");
    }

    /** A build of the index is running: its heartbeat is recent. */
    public static function building(string $index): bool
    {
        $beat = get_option("gaffer_search_building_{$index}");
        return is_array($beat) && (int) ($beat['time'] ?? 0) > time() - self::BUILDING_STALE;
    }

    /** A running build's heartbeat: documents written so far. */
    public static function heartbeat(string $index, int $done): void
    {
        update_option("gaffer_search_building_{$index}", ['done' => $done, 'time' => time()], false);
    }

    public static function stopBuilding(string $index): void
    {
        delete_option("gaffer_search_building_{$index}");
    }

    /** When a full rebuild was last asked for (a microtime); null: never. */
    public static function requested(string $index): ?float
    {
        $time = get_option("gaffer_search_rebuild_requested_{$index}");
        return is_numeric($time) ? (float) $time : null;
    }

    public static function request(string $index): void
    {
        update_option("gaffer_search_rebuild_requested_{$index}", sprintf('%.6F', microtime(true)), false);
    }

    /**
     * The last daily sample check: documents that differed, documents compared, when.
     *
     * @return array{differing: int, checked: int, time: int}|null
     */
    public static function drift(string $index): ?array
    {
        $value = get_option("gaffer_search_drift_{$index}");
        return is_array($value) && isset($value['differing'], $value['checked'], $value['time'])
            ? ['differing' => (int) $value['differing'], 'checked' => (int) $value['checked'], 'time' => (int) $value['time']]
            : null;
    }

    /** @param array{differing: int, checked: int, time: int} $drift */
    public static function setDrift(string $index, array $drift): void
    {
        update_option("gaffer_search_drift_{$index}", $drift, false);
    }

    /**
     * The plugin's folders a visitor could fetch files from, per the last check; null: unknown.
     *
     * @return list<string>|null
     */
    public static function served(): ?array
    {
        $value = get_option('gaffer_search_served');
        return is_array($value) && is_array($value['served'] ?? null) ? array_values(array_map(strval(...), $value['served'])) : null;
    }

    /** @param list<string>|null $served */
    public static function setServed(?array $served): void
    {
        update_option('gaffer_search_served', ['served' => $served, 'time' => time()], false);
    }

    /** Everything about an index (its files were deleted). */
    public static function forget(string $index): void
    {
        foreach (['built', 'error', 'building', 'rebuild_requested', 'drift'] as $key) {
            delete_option("gaffer_search_{$key}_{$index}");
        }
    }

    /** The arrays of before 2026-11-05 (gaffer_search_built_signatures/_versions) as one option per index, once. */
    private static function migrateBuiltArrays(): void
    {
        $signatures = get_option('gaffer_search_built_signatures');
        $versions   = get_option('gaffer_search_built_versions');
        if ($signatures === false && $versions === false) {
            return;
        }
        foreach (is_array($signatures) ? $signatures : [] as $name => $signature) {
            if (get_option("gaffer_search_built_{$name}") === false) {
                add_option("gaffer_search_built_{$name}", ['signature' => (string) $signature, 'version' => (string) (( (array) $versions)[$name] ?? '')], '', false);
            }
        }
        delete_option('gaffer_search_built_signatures');
        delete_option('gaffer_search_built_versions');
    }
}
