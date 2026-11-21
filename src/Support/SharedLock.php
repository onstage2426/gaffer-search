<?php

declare(strict_types=1);

namespace GafferSearch\Support;

/**
 * A lock every web server of a site sees: MySQL's GET_LOCK() on the shared database. Like
 * flock(), it never waits and goes away with its holder — the server releases it when the
 * connection closes, so a killed build doesn't leave it behind.
 *
 * GET_LOCK() names are global to the database server, so the name includes the database and
 * table prefix. A database that has no GET_LOCK() (an error, not a refusal) doesn't lock: the
 * caller goes ahead as without the lock.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class SharedLock
{
    /** Take the lock; false when another connection holds it. */
    public static function acquire(string $key): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::name($key)));
        return $result === null || (string) $result !== '0';
    }

    public static function release(string $key): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::name($key)));
    }

    /** At most 64 characters, as MySQL allows. */
    private static function name(string $key): string
    {
        global $wpdb;
        return 'gaffer_search_' . md5( (defined('DB_NAME') ? (string) DB_NAME : '') . '|' . $wpdb->base_prefix . '|' . $key);
    }
}
