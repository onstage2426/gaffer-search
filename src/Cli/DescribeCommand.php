<?php

declare(strict_types=1);

namespace GafferSearch\Cli;

use GafferSearch\Services\IndexRegistry;
use GafferSearch\Services\IndexService;
use WP_CLI;

/**
 * `wp gaffer-search describe [<index>]` — schema introspection for a registered index
 * (facet fields, searchable fields, document count, last build).
 * Reuses IndexService::stats(), the same data source the admin dashboard's
 * Indexes tab already reads — no new capability needed from the index library.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class DescribeCommand
{
    public function __construct(
        private readonly IndexService $index,
        private readonly IndexRegistry $registry,
    ) {}

    /**
     * Show an index's schema: facet fields, searchable fields, document count, last build.
     *
     * ## OPTIONS
     *
     * [<index>]
     * : The index name. Default: every registered index.
     *
     * ## EXAMPLES
     *
     *     wp gaffer-search describe products
     *
     * @param list<string> $args
     */
    public function __invoke(array $args): void
    {
        $stats = $this->index->stats();
        $name  = $args[0] ?? null;

        if ($name === null) {
            foreach ($stats as $n => $s) {
                $this->printOne($n, $s);
            }
            return;
        }

        if (!$this->registry->has($name)) {
            WP_CLI::error("No index registered as '{$name}'. Registered: " . implode(', ', $this->registry->names()));
        }

        $this->printOne($name, $stats[$name]);
    }

    /** @param array<string, mixed> $stats */
    private function printOne(string $name, array $stats): void
    {
        WP_CLI::log(WP_CLI::colorize("%G{$name}%n"));

        if (!$stats['exists']) {
            WP_CLI::log('  not built yet');
            WP_CLI::log('');
            return;
        }

        $schema = $stats['schema'];
        WP_CLI::log('  filterable fields: ' . ($schema['filterable_fields'] !== [] ? implode(', ', $schema['filterable_fields']) : '(none)'));
        WP_CLI::log('  sortable fields:   ' . ($schema['sortable_fields'] !== [] ? implode(', ', $schema['sortable_fields']) : '(none)'));
        WP_CLI::log(
            '  searchable fields: '
            . ($schema['searchable_fields'] !== null ? implode(', ', $schema['searchable_fields']) : '(all fields not filterable or sortable)'),
        );
        WP_CLI::log('  documents:         ' . $stats['total_documents']);
        WP_CLI::log('  schema revision:   ' . ($stats['schema_version'] ?? '(unknown)'));
        WP_CLI::log(
            '  last built:        '
            . ($stats['last_modified'] ? gmdate('Y-m-d H:i:s', $stats['last_modified']) . ' UTC' : '(unknown)'),
        );
        WP_CLI::log('');
    }
}
