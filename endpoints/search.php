<?php

declare(strict_types=1);

$start = microtime(true);

/**
 * Search endpoint — no WordPress boot required.
 * URL: /wp-content/plugins/<plugin-folder>/endpoints/search.php?q=…&token=<signed-token>
 *
 * Locked params come from a signed token (Services\Tokens, SearchRunner::options()).
 * Free params: q, offset, sort[], filter[], and limit when the token offers it (`limits`).
 *
 * The search itself — limits, hit shape, highlighting — is SearchRunner, the same code
 * gaffer_search() runs in PHP.
 */

use GafferSearch\Support\SearchRunner;
use GafferSearch\Support\SortQuery;

require_once __DIR__ . '/search-shared.php';

$options = SearchRunner::choose(SearchRunner::options($opts), $_GET['limit'] ?? null);
$q       = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$offset  = isset($_GET['offset']) && is_numeric($_GET['offset']) ? (int) $_GET['offset'] : 0;
$rawSort = $_GET['sort'] ?? [];
$sort    = SortQuery::parse(is_array($rawSort) ? $rawSort : []);

try {
    try {
        $response = SearchRunner::run($path, $q, $options, $offset, $sort, $filter);
    } catch (\Throwable $first) {
        // The version opened was swapped out and deleted mid-request (see gaffer_search_settled()): once more on the current one.
        if (!gaffer_search_settled($path)) {
            throw $first;
        }
        $response = SearchRunner::run($path, $q, $options, $offset, $sort, $filter);
    }
} catch (\Throwable $e) {
    error_log('Gaffer Search: search error for query ' . json_encode($q, JSON_INVALID_UTF8_SUBSTITUTE) . ': ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    gaffer_search_error('search_failed', 'Search unavailable.', 503, SearchRunner::forJson(SearchRunner::empty($q, $options, $offset)));
}

$response['warnings'] = [...$response['warnings'], ...$filterWarnings, ...gaffer_search_ignored_params(['q', 'offset', 'limit', 'sort', 'filter'])];
$response['time']     = strval(round((microtime(true) - $start) * 1000, 2)) . 'ms';
gaffer_search_exit(SearchRunner::forJson($response));
