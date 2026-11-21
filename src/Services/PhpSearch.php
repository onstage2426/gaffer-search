<?php

declare(strict_types=1);

namespace GafferSearch\Services;

use Fuzor\Index;
use GafferSearch\Support\IndexSlug;
use GafferSearch\Support\Language\Languages;
use GafferSearch\Support\SearchRunner;
use GafferSearch\Support\SortQuery;

/**
 * A search from PHP (Engine::search()): the same SearchRunner path as the endpoint, on the
 * current language's file.
 *
 * @internal
 */
final class PhpSearch
{
    /**
     * $params holds the locked options plus offset and sort; $visitorFilter the visitor's own
     * filter (already parsed), kept apart so SearchRunner can leave its undeclared fields out
     * while an undeclared locked field fails. An index that isn't built, or a failed search,
     * returns the empty shape.
     *
     * @param  array<string, mixed> $params
     * @param  array<string, mixed> $visitorFilter Already parsed (FilterQuery::parse()).
     * @return array<string, mixed>
     */
    public static function run(string $index, string $query, array $params = [], array $visitorFilter = []): array
    {
        $path    = self::path($index, new SettingsService());
        $options = SearchRunner::options($params);
        $offset  = is_numeric($params['offset'] ?? null) ? (int) $params['offset'] : 0;
        $sort    = SortQuery::parse( (array) ($params['sort'] ?? []));

        if (!Index::exists($path)) {
            return SearchRunner::empty($query, $options, $offset);
        }

        try {
            return SearchRunner::run($path, $query, $options, $offset, $sort, $visitorFilter);
        } catch (\Throwable $e) {
            error_log('Gaffer Search: the search on index "' . $index . '" failed: ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return SearchRunner::empty($query, $options, $offset);
        }
    }

    /** The current language's index file of $index. */
    public static function path(string $index, SettingsService $settings): string
    {
        return $settings->get('index_dir') . '/' . IndexSlug::compute($index, $settings->searchSecret(), Languages::current()) . '.db';
    }
}
