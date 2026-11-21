<?php

declare(strict_types=1);

namespace GafferSearch\Cli;

use GafferSearch\Services\IndexRegistry;
use GafferSearch\Services\IndexService;
use WP_CLI;

/**
 * `wp gaffer-search search <index> [<query>]` — a search of this site's file of the index in the
 * current language (`--url` picks the site): the hits' IDs and titles, the total and the time.
 * For debugging; Gaffer's `gaffer-search` MCP tool searches with filters and facets.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class SearchCommand
{
    public function __construct(
        private readonly IndexService $index,
        private readonly IndexRegistry $registry,
    ) {}

    /**
     * Search an index.
     *
     * ## OPTIONS
     *
     * <index>
     * : The index name.
     *
     * [<query>]
     * : The search text. Default: everything.
     *
     * [--limit=<limit>]
     * : Hits to show.
     * ---
     * default: 10
     * ---
     *
     * [--format=<format>]
     * : table or json (the whole response).
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp gaffer-search search products "rode jurk"
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc = []): void
    {
        $name = $args[0] ?? '';
        if (!$this->registry->has($name)) {
            WP_CLI::error("No index registered as '{$name}'. Registered: " . implode(', ', $this->registry->names()));
        }
        $start = microtime(true);
        try {
            $response = $this->index->search($name, $args[1] ?? '', max(1, (int) ($assoc['limit'] ?? 10)));
        } catch (\Throwable $e) {
            WP_CLI::error($e->getMessage());
            return;
        }
        if (($assoc['format'] ?? 'table') === 'json') {
            WP_CLI::log( (string) wp_json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            return;
        }
        foreach ( (array) ($response['results'] ?? []) as $hit) {
            $hit = (array) $hit;
            WP_CLI::log(sprintf('%6d  %s', (int) ($hit['id'] ?? 0), wp_strip_all_tags( (string) (( (array) ($hit['formatted'] ?? []))['title'] ?? $hit['title'] ?? ''))));
        }
        WP_CLI::log(sprintf('%d found in %.1f ms.', (int) ($response['total'] ?? 0), (microtime(true) - $start) * 1000));
        foreach ( (array) ($response['warnings'] ?? []) as $warning) {
            WP_CLI::warning( (string) $warning);
        }
    }
}
