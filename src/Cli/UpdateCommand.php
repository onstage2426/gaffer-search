<?php

declare(strict_types=1);

namespace GafferSearch\Cli;

use GafferSearch\Services\IndexBusyException;
use GafferSearch\Services\IndexRegistry;
use GafferSearch\Services\IndexService;
use GafferSearch\Services\UpdateQueue;
use WP_CLI;

/**
 * `wp gaffer-search update [<index>]` — bring this server's indexes up to date: copy in a full rebuild
 * another server published (GAFFER_SEARCH_SNAPSHOT_DIR), then apply every change in the update log since
 * this server's position. Run it every minute from each web server's system cron
 * (`* * * * * wp gaffer-search update --url=…`): WP-cron events live in the shared database, so only one
 * server would run them. Quiet when there's nothing to do; exits non-zero when an update fails.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class UpdateCommand
{
    public function __construct(
        private readonly IndexService $index,
        private readonly IndexRegistry $registry,
    ) {}

    /**
     * Apply the update log (and a rebuild another server published) to this server's indexes.
     *
     * ## OPTIONS
     *
     * [<index>]
     * : The index name. Default: every registered index.
     *
     * ## EXAMPLES
     *
     *     wp gaffer-search update --url=https://example.com
     *
     * @param list<string> $args
     */
    public function __invoke(array $args): void
    {
        $names = isset($args[0]) ? [$args[0]] : $this->registry->names();
        $ok    = true;
        foreach ($names as $name) {
            if (!$this->registry->has($name)) {
                WP_CLI::error("No index registered as '{$name}'. Registered: " . implode(', ', $this->registry->names()));
            }
            try {
                do {
                    $result = $this->index->applyUpdates($name);
                    $done   = array_filter([
                        $result['imported'] ? 'copied the published rebuild' : null,
                        $result['rebuilt'] ? 'rebuilt (its position was behind the update log, or in another one: a copied database)' : null,
                        $result['updated'] + $result['deleted'] > 0 ? "{$result['updated']} updated, {$result['deleted']} removed" : null,
                    ]);
                    if ($done !== []) {
                        WP_CLI::log("{$name}: " . implode('; ', $done) . '.');
                    }
                } while ($result['remaining'] > 0);
            } catch (IndexBusyException) {
                WP_CLI::log("{$name}: being rebuilt; its changes are applied on the next run.");
            } catch (\Throwable $e) {
                WP_CLI::warning("{$name}: {$e->getMessage()}");
                $ok = false;
            }
        }
        (new UpdateQueue())->prune();
        if (!$ok) {
            WP_CLI::halt(1);
        }
    }
}
