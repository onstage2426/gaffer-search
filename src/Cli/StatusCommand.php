<?php

declare(strict_types=1);

namespace GafferSearch\Cli;

use GafferSearch\Engine;
use GafferSearch\Support\Requirements;
use GafferSearch\Support\UpdateCheck;
use WP_CLI;

/**
 * `wp gaffer-search status [--format=json]` — every defined index's state, for monitoring: built,
 * documents, a build running, the last build's error, the content changes not applied yet.
 * Exits 1 when an index isn't built, has an error, or has a change waiting longer than
 * STALE_AFTER seconds (the updates don't run here); 0 otherwise.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class StatusCommand
{
    public const int STALE_AFTER = 900;

    public function __construct(private readonly Engine $engine) {}

    /**
     * Every defined index's state; exits 1 when one isn't built, has an error, or has updates waiting over 15 minutes, or this PHP lacks a requirement.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp gaffer-search status
     *     wp gaffer-search status --format=json
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc = []): void
    {
        $status   = $this->engine->status();
        $now      = time();
        $rows     = [];
        // What this PHP lacks (an old SQLite, no pdo_sqlite): no index can be built or searched.
        $problems = Requirements::problems();
        $ok       = $problems === [];
        foreach ($status as $name => $index) {
            $problem = self::problem($index, $now);
            $ok      = $ok && $problem === null;
            $rows[]  = ['index' => $name, ...$index, 'ok' => $problem === null, 'problem' => $problem];
        }

        if (($assoc['format'] ?? 'table') === 'json') {
            WP_CLI::log( (string) wp_json_encode(['ok' => $ok, 'requirements' => $problems, 'indexes' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        } elseif ($rows === []) {
            WP_CLI::warning('No index is defined in this request (the theme defines them: is it active?).');
        } else {
            $table = array_map(static fn(array $row): array => [
                'index'     => $row['index'],
                'built'     => $row['built'] ? 'yes' : 'no',
                'documents' => $row['documents'],
                'building'  => $row['building'] ? 'yes' : 'no',
                'pending'   => $row['pending'] . ($row['pending_since'] !== null ? ' (' . human_time_diff( (int) $row['pending_since'], $now) . ')' : ''),
                'problem'   => $row['problem'] ?? '',
            ], $rows);
            $columns = ['index', 'built', 'documents', 'building', 'pending', 'problem'];
            $widths  = array_map(static fn(string $c): int => max(strlen($c), ...array_map(static fn(array $r): int => strlen( (string) $r[ $c ]), $table)), $columns);
            foreach ([array_combine($columns, $columns), ...$table] as $row) {
                $cells = array_map(static fn(string $c, int $w): string => str_pad( (string) $row[ $c ], $w), $columns, $widths);
                WP_CLI::log(rtrim(implode('  ', $cells)));
            }
        }

        if (($assoc['format'] ?? 'table') !== 'json') {
            foreach ($problems as $problem) {
                WP_CLI::warning($problem);
            }
            $checked = UpdateCheck::checked();
            if ($checked < $now - UpdateCheck::STALE_DAYS * 86400) {
                $error = UpdateCheck::error();
                WP_CLI::warning('The updater last found the plugin\'s releases ' . human_time_diff($checked, $now) . ' ago' . ($error !== null ? " ({$error})" : '') . ': this site gets no plugin updates (docs/hosting.md, "Plugin updates").');
            }
        }

        WP_CLI::halt($ok && $rows !== [] ? 0 : 1);
    }

    /**
     * Why an index needs attention, null when it doesn't.
     *
     * @param array<string, mixed> $index
     */
    public static function problem(array $index, int $now): ?string
    {
        return match (true) {
            !($index['built'] ?? false) => ($index['building'] ?? false) ? 'not built yet (building now)' : 'not built',
            is_string($index['error'] ?? null) => (string) $index['error'],
            is_array($index['served'] ?? null) && $index['served'] !== [] => 'a visitor can download files from ' . implode(', ', $index['served']) . ' (the index holds hidden documents too): block it in the web server (docs/hosting.md)',
            is_int($index['pending_since'] ?? null) && $now - $index['pending_since'] > self::STALE_AFTER => 'changes waiting since ' . gmdate('Y-m-d H:i', $index['pending_since']) . ' UTC: the updates don\'t run (WP-cron or wp gaffer-search update)',
            default => null,
        };
    }
}
