<?php

declare(strict_types=1);

namespace GafferSearch\Services;

/**
 * The current site's log of documents to update: (index name, post ID) rows in its own table,
 * appended by ChangeTracker and applied by IndexService::applyUpdates().
 *
 * A log, not a queue: rows are never taken off it by the server that applies them. Every web
 * server keeps its own position (the last row ID it applied, next to its index files), so
 * several servers can each apply the same changes to their own local copy; one server is the
 * case with one position. Rows are pruned a day after they were written (prune()); a server
 * whose position is older than the pruned part has missed changes and rebuilds.
 *
 * Post ID 0 (LABELS) means "the labels changed" (a term renamed): the update rewrites the labels
 * file without touching documents.
 *
 * The log has an ID (id(), an option next to the table, so a database copy takes it along).
 * A server records it with its position: a position recorded with another ID is in another log
 * (the database was copied from another environment), so that server rebuilds in full.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
class UpdateQueue
{
    /** Bumped when the table changes; a site with another version recreates it on its next request. */
    public const int VERSION = 2;

    public const int LABELS = 0;

    /** How long rows stay: longer than any server may go without applying them. */
    public const int RETENTION = 86_400;

    private const int INSERT_CHUNK = 500;

    /** The log's ID: an option, not autoloaded (read by the update job only). */
    private const string ID_OPTION = 'gaffer_search_queue_id';

    /** The table's name after the site's prefix. */
    public const string TABLE = 'gaffer_search_queue';

    public function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Create the table on a site that doesn't have this version of it (per site of a network).
     * An older version is dropped, not migrated: its rows had no order; the indexes are rebuilt.
     *
     * @return bool Whether an older table was replaced (the caller schedules rebuilds).
     */
    public function install(): bool
    {
        $installed = (int) get_option('gaffer_search_queue_version', 0);
        if ($installed === self::VERSION) {
            return false;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        if ($installed !== 0) {
            $wpdb->query("DROP TABLE IF EXISTS {$this->table()}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
        }
        dbDelta("CREATE TABLE {$this->table()} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  index_name varchar(64) NOT NULL,
  post_id bigint(20) unsigned NOT NULL,
  created_at int(10) unsigned NOT NULL,
  PRIMARY KEY  (id),
  KEY index_id (index_name,id),
  KEY created_at (created_at)
) {$wpdb->get_charset_collate()};");
        update_option('gaffer_search_queue_version', self::VERSION, true);
        delete_option('gaffer_search_queue_pruned');
        // A new table is a new log: positions recorded in the old one mean nothing here.
        update_option(self::ID_OPTION, self::newId(), false);
        return $installed !== 0;
    }

    /**
     * The log's ID, made on first use (a site whose table is older than the ID). It lives in
     * the database with the rows, so a copy of the database (live to staging) brings the
     * source's ID along, which a server's recorded position doesn't match.
     */
    public function id(): string
    {
        $id = get_option(self::ID_OPTION);
        if (is_string($id) && $id !== '') {
            return $id;
        }
        if (add_option(self::ID_OPTION, self::newId(), '', false)) {
            return (string) get_option(self::ID_OPTION);
        }
        // Another process made it first.
        wp_cache_delete(self::ID_OPTION, 'options');
        wp_cache_delete('notoptions', 'options');
        return (string) get_option(self::ID_OPTION);
    }

    private static function newId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /** Drop the table (uninstall). */
    public function drop(): void
    {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$this->table()}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
    }

    /** @return bool False when the rows couldn't be written (the caller falls back to a full rebuild). */
    public function add(string $index, int ...$ids): bool
    {
        global $wpdb;
        $now = time();
        foreach (array_chunk(array_values(array_unique($ids)), self::INSERT_CHUNK) as $chunk) {
            $values = implode(',', array_map(fn(int $id): string => $wpdb->prepare('(%s,%d,%d)', $index, $id, $now), $chunk));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- values prepared above
            if ($wpdb->query("INSERT INTO {$this->table()} (index_name, post_id, created_at) VALUES {$values}") === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * The newest position: where a build that starts now stands (every change up to here is in
     * what it reads).
     */
    public function head(): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return max( (int) $wpdb->get_var("SELECT MAX(id) FROM {$this->table()}"), $this->pruned());
    }

    /**
     * Up to $limit rows of an index after $position, oldest first.
     *
     * @return array{ids: list<int>, last: int} the distinct post IDs, and the position of the last row read ($position when none)
     */
    public function after(string $index, int $position, int $limit): array
    {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, post_id FROM {$this->table()} WHERE index_name = %s AND id > %d ORDER BY id LIMIT %d",
            $index,
            $position,
            $limit,
        ), ARRAY_A);
        // phpcs:enable

        $ids  = [];
        $last = $position;
        foreach ($rows as $row) {
            $ids[ (int) $row['post_id']] = true;
            $last                        = (int) $row['id'];
        }
        return ['ids' => array_keys($ids), 'last' => $last];
    }

    /** Rows of an index after $position. */
    public function countAfter(string $index, int $position): int
    {
        return $this->pendingAfter($index, $position)['count'];
    }

    /**
     * Rows of an index after $position, and when the oldest of them was written.
     *
     * @return array{count: int, since: ?int} since: a Unix time, null when there are none
     */
    public function pendingAfter(string $index, int $position): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row   = (array) $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS n, MIN(created_at) AS since FROM {$this->table()} WHERE index_name = %s AND id > %d", $index, $position), ARRAY_A);
        $count = (int) ($row['n'] ?? 0);
        return ['count' => $count, 'since' => $count > 0 && is_numeric($row['since'] ?? null) ? (int) $row['since'] : null];
    }

    /** The last position pruned: a server behind it has missed rows. */
    public function pruned(): int
    {
        return (int) get_option('gaffer_search_queue_pruned', 0);
    }

    /** Delete the rows older than RETENTION (from the start of the log, so it stays without gaps). */
    public function prune(): void
    {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $upTo = (int) $wpdb->get_var($wpdb->prepare("SELECT MAX(id) FROM {$this->table()} WHERE created_at < %d", time() - self::RETENTION));
        if ($upTo > 0) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$this->table()} WHERE id <= %d", $upTo));
            update_option('gaffer_search_queue_pruned', max($upTo, $this->pruned()), false); // Not autoloaded: see IndexService::BUILT_OPTION.
        }
        // phpcs:enable
    }
}
