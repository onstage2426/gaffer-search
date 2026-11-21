<?php

declare(strict_types=1);

namespace GafferSearch\Cli;

use GafferSearch\Services\IndexRegistry;
use GafferSearch\Services\IndexService;
use WP_CLI;

/**
 * `wp gaffer-search verify [<index>] [--fix]` — compare an index with what its builder produces
 * right now, and report documents that are missing, extra, or stale. The proof that automatic
 * rebuilds kept an index in sync with the database; exits non-zero when anything differs, so it
 * can run in a cron job or deploy check. With --fix the differing documents go into the update
 * log and are applied (an index built before a switch, content changed meanwhile untracked).
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class VerifyCommand
{
    private const int SAMPLE = 20;

    public function __construct(
        private readonly IndexService $index,
        private readonly IndexRegistry $registry,
    ) {}

    /**
     * Compare an index with what its builder produces now; exits non-zero when anything differs.
     *
     * ## OPTIONS
     *
     * [<index>]
     * : The index name. Default: every registered index.
     *
     * [--fix]
     * : Update the documents that differ (through the update log, so every server gets them), then check those again.
     *
     * ## EXAMPLES
     *
     *     wp gaffer-search verify products
     *     wp gaffer-search verify products --fix
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc = []): void
    {
        $fix   = isset($assoc['fix']);
        $names = isset($args[0]) ? [$args[0]] : $this->registry->names();
        $clean = true;

        foreach ($names as $name) {
            if (!$this->registry->has($name)) {
                WP_CLI::error("No index registered as '{$name}'. Registered: " . implode(', ', $this->registry->names()));
            }
            if (!$this->index->isBuilt($name)) {
                WP_CLI::warning("{$name}: not built yet.");
                $clean = false;
                continue;
            }

            $pending = $this->index->pendingUpdates($name);
            if ($pending['count'] > 0) {
                // Unapplied changes show up below as differences; say where they come from.
                WP_CLI::warning(sprintf(
                    '%s: %d change(s) in the update log not applied yet, the oldest from %d minute(s) ago. WP-cron applies them; '
                    . 'when it doesn\'t run (a password-protected or offline site, DISABLE_WP_CRON without a system cron), '
                    . 'wp gaffer-search update does it now.',
                    $name,
                    $pending['count'],
                    intdiv(time() - (int) $pending['since'], 60),
                ));
            }

            $result = $this->index->verify($name);
            $issues = count($result['missing']) + count($result['extra']) + count($result['stale']);

            if ($issues === 0) {
                WP_CLI::log(WP_CLI::colorize("%G{$name}%n: in sync ({$result['checked']} documents)."));
                continue;
            }

            WP_CLI::log(WP_CLI::colorize("%R{$name}%n: {$issues} difference(s) across {$result['checked']} documents."));
            foreach (['missing' => 'missing from the index', 'extra' => 'in the index but no longer produced', 'stale' => 'outdated in the index'] as $key => $label) {
                if ($result[$key] !== []) {
                    WP_CLI::log(sprintf('  %d %s: %s%s', count($result[$key]), $label, implode(', ', array_slice($result[$key], 0, self::SAMPLE)), count($result[$key]) > self::SAMPLE ? ', …' : ''));
                }
            }
            $clean = $fix && $this->fix($name, array_values(array_unique([...$result['missing'], ...$result['extra'], ...$result['stale']]))) && $clean;
        }

        if (!$clean) {
            WP_CLI::halt(1);
        }
    }

    /**
     * Update these documents through the update log, then verify again.
     *
     * @param list<int> $ids
     */
    private function fix(string $name, array $ids): bool
    {
        if (!$this->index->queueUpdates($name, $ids)) {
            WP_CLI::warning("{$name}: could not add the documents to the update log.");
            return false;
        }
        do {
            $applied = $this->index->applyUpdates($name);
        } while ($applied['remaining'] > 0);
        // Only the fixed ones again: a second full verify takes as long as the first, and on a busy
        // shop finds the edits made meanwhile (the update log has those).
        $result = $this->index->verifyIds($name, $ids);
        $issues = count($result['missing']) + count($result['extra']) + count($result['stale']);
        WP_CLI::log($issues === 0
            ? WP_CLI::colorize("%G{$name}%n: fixed " . count($ids) . ' document(s).')
            : WP_CLI::colorize("%R{$name}%n: {$issues} of the " . count($ids) . " document(s) still differ after updating them: rebuild it (wp gaffer-search rebuild {$name})."));

        return $issues === 0;
    }
}
