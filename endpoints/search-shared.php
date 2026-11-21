<?php

declare(strict_types=1);

/**
 * The search endpoint's bootstrap — no WordPress boot.
 *
 * Caller must set $start = microtime(true) before including.
 * Provides: $pluginCfg, $opts (the verified token payload), $path (the index file), $filter
 * Defines:  gaffer_search_exit(), gaffer_search_error()
 *
 * `?check=<site>` (no token) is the dashboard's health check: {"ok": true}, or config_missing.
 *
 * Every error response is {"error": "<message>", "code": "<code>"} with one of these codes:
 *   config_missing (503)   the token's site has no endpoint config — open that site once, or
 *                          see the dashboard's notice about GAFFER_SEARCH_CONFIG_DIR
 *   token_required (400)   no token parameter
 *   invalid_token (400)    the token names no site, or its signature doesn't verify with that
 *                          site's secret (e.g. the token secret was rotated)
 *   token_expired (400)    the token had a `ttl` and its time is up: reload the page for a new one
 *   index_missing (400)    the token names no index
 *   index_unknown (503)    the token names an index that isn't registered
 *   index_not_built (503)  the index is registered but hasn't been built yet (with an empty
 *                          SearchResponse's keys, as search_failed)
 * plus, from search.php itself: search_failed (503).
 */

use Fuzor\Index;
use GafferSearch\Support\ConfigLocation;
use GafferSearch\Support\FilterQuery;
use GafferSearch\Support\SearchRunner;
use GafferSearch\Support\Token;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Headers that apply to every response path — emit before any exit.
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store'); // safe default; overridden to a public policy after index verification

function gaffer_search_exit(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function gaffer_search_error(string $code, string $message, int $status, array $extra = []): never
{
    header('Cache-Control: no-store');
    header('CDN-Cache-Control: no-store');
    gaffer_search_exit(['error' => $message, 'code' => $code] + $extra, $status);
}

/**
 * The request's parameters with named keys sorted (lists keep their order: sort[] is a
 * priority), so the same parameters in another order give the same validator.
 *
 * @param  array<mixed> $params
 * @return array<mixed>
 */
function gaffer_search_normalized_params(array $params): array
{
    if (!array_is_list($params)) {
        ksort($params, SORT_STRING);
    }
    return array_map(fn(mixed $value): mixed => is_array($value) ? gaffer_search_normalized_params($value) : $value, $params);
}

/**
 * A warning naming the request's parameters the endpoint doesn't read (none: []). An unknown
 * parameter usually means a field that doesn't belong in the search form: a card's own form
 * nested in it (the HTML parser drops a nested <form> tag, so its fields join the outer form),
 * or a misspelled name. Campaign parameters (utm_*, gclid, …) aren't reported.
 *
 * @param  list<string> $known The endpoint's own parameters.
 * @return list<string>
 */
function gaffer_search_ignored_params(array $known): array
{
    $ignored = array_filter(
        array_map(strval(...), array_keys($_GET)),
        fn(string $name): bool => !in_array($name, [...$known, 'token', '_'], true)
            && !str_starts_with($name, 'utm_')
            && !in_array($name, ['gclid', 'fbclid', 'msclkid'], true),
    );
    return $ignored === [] ? [] : [
        'Parameters this endpoint doesn\'t read: ' . implode(', ', $ignored) . '; ignored. A form inside the search form (a card\'s add-to-cart) '
        . 'adds its fields to it: keep results outside the form (createAlpineSearch({ form })).',
    ];
}

// The dashboard's health check, without a token: `?check=<site>` answers {"ok": true} when PHP
// runs here and finds that site's config, else config_missing. Says nothing else about the site.
if (isset($_GET['check']) && !isset($_GET['token'])) {
    $checkSite = is_numeric($_GET['check']) ? max(1, (int) $_GET['check']) : 1;
    if (ConfigLocation::load(__DIR__, isset($_SERVER['SCRIPT_FILENAME']) ? (string) $_SERVER['SCRIPT_FILENAME'] : null, $checkSite) === null) {
        gaffer_search_error('config_missing', 'Search is not configured yet.', 503);
    }
    gaffer_search_exit(['ok' => true]);
}

// The token names its site (one config per site of a network; a single site is site 1). The
// claim is read before the signature is checked only to pick the config — and so the secret —
// to check it with: a token that claims another site fails verification with that site's secret.
if (!isset($_GET['token']) || !is_string($_GET['token']) || $_GET['token'] === '') {
    gaffer_search_error('token_required', 'A token is required.', 400);
}
$site = (int) (Token::claims($_GET['token'])['site'] ?? 0);
if ($site < 1) {
    gaffer_search_error('invalid_token', 'Invalid token.', 400);
}

$pluginCfg = ConfigLocation::load(__DIR__, isset($_SERVER['SCRIPT_FILENAME']) ? (string) $_SERVER['SCRIPT_FILENAME'] : null, $site);
if ($pluginCfg === null) {
    gaffer_search_error('config_missing', 'Search is not configured yet.', 503);
}

// Verify the signed token with its site's secret.
$secret = (string) ($pluginCfg['token_secret'] ?? '');
$opts   = $secret !== '' ? Token::verify($_GET['token'], $secret) : null;
if ($opts === null) {
    gaffer_search_error('invalid_token', 'Invalid token.', 400);
}
$expires = isset($opts['expires']) && is_numeric($opts['expires']) ? (int) $opts['expires'] : null;
if ($expires !== null && time() >= $expires) {
    gaffer_search_error('token_expired', 'The token has expired.', 400);
}

// Resolve index path.
$name     = (string) preg_replace('/[^a-z0-9_\-]/i', '', (string) ($opts['index'] ?? ''));
$indexDir = (string) ($pluginCfg['index_dir'] ?? '');
$indexes  = is_array($pluginCfg['indexes'] ?? null) ? $pluginCfg['indexes'] : [];

if ($name === '') {
    gaffer_search_error('index_missing', 'The token names no index.', 400);
}
// name → language → file slug ('' on a site without languages); the token names its language.
$slug = $indexes[$name][ (string) ($opts['language'] ?? '')] ?? null;
if (!is_string($slug) && is_array($indexes[$name] ?? null)) {
    // The index is there, not in the token's language: the site gained or lost languages since
    // the page was cached. The page is stale, not the search down.
    gaffer_search_error('language_unknown', "Index '{$name}' has no file for this page's language.", 400);
}
if (!is_string($slug)) {
    gaffer_search_error('index_unknown', "Index '{$name}' not found.", 503);
}
/**
 * Whether the index at $path is there after a moment: a rebuild publishes by swapping a symlink,
 * and a request that resolved the link just before two quick swaps finds its version deleted.
 * Waiting once for the link to settle answers it from the current version instead of a 503.
 */
function gaffer_search_settled(string $path): bool
{
    if (!is_link($path)) {
        return false;
    }
    usleep(100_000);
    clearstatcache();

    return Index::exists($path);
}

$path = $indexDir . '/' . $slug . '.db';

if (!Index::exists($path) && !gaffer_search_settled($path)) {
    // With an empty answer's keys, as search_failed: a client that reads the results shows none.
    $q = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
    gaffer_search_error('index_not_built', "Index '{$name}' not built yet.", 503, SearchRunner::forJson(SearchRunner::empty($q, SearchRunner::options($opts))));
}

// Caching — the validator covers the full request (same URL + same index state = identical
// response). It's built from the index file *and* its -wal: a write that is still in the WAL
// changes the WAL's size and mtime before the main file's mtime moves (that only happens on a
// checkpoint), and the inode changes when a rebuild swaps in a new file.
clearstatcache();
$file     = realpath($path) ?: $path; // the index path links to the current version; its -wal is named after that
$db       = @stat($file) ?: ['ino' => 0, 'size' => 0, 'mtime' => 0];
$wal      = @stat($file . '-wal') ?: ['size' => 0, 'mtime' => 0];
// The names (labels) are their own file: an update of names only changes nothing else.
$names    = @stat($indexDir . '/' . $slug . '.labels.json') ?: ['size' => 0, 'mtime' => 0];
$modified = max( (int) $db['mtime'], (int) $wal['mtime'], (int) $names['mtime']);
$state    = implode(':', [$db['ino'], $db['size'], $db['mtime'], $wal['size'], $wal['mtime'], $names['size'], $names['mtime']]);
$etag     = '"' . substr(hash('sha256', $state . '|' . http_build_query(gaffer_search_normalized_params($_GET))), 0, 16) . '"';
header('Last-Modified: ' . gmdate('D, d M Y H:i:s \G\M\T', $modified));
header('ETag: ' . $etag);
header('Vary: Accept-Encoding');
if ($expires === null) {
    header('Cache-Control: public, max-age=60, s-maxage=300, stale-while-revalidate=60');
    header('CDN-Cache-Control: public, max-age=300, stale-while-revalidate=60');
} else {
    // An expiring token is one visitor's (a customer group's page): the browser may keep the
    // answer briefly, never past the expiry; shared caches don't keep it.
    header('Cache-Control: private, max-age=' . max(0, min(60, $expires - time())));
    header('CDN-Cache-Control: no-store');
}

// Conditional request — return 304 if client/CDN already has a fresh copy.
$ifNoneMatch = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
if ($ifNoneMatch !== '') {
    $tags = array_map(trim(...), explode(',', $ifNoneMatch));
    if (in_array($etag, $tags, true) || in_array('*', $tags, true)) {
        http_response_code(304);
        exit;
    }
} elseif (($ims = trim($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '')) !== '') {
    if (strtotime($ims) >= $modified) {
        http_response_code(304);
        exit;
    }
}

// The visitor's filter[] — see FilterQuery::parse(). Fields locked in the token's own `filter`
// replace these per field (SearchRunner).
$rawFilter = $_GET['filter'] ?? [];
[$filter, $filterWarnings] = FilterQuery::visitor(is_array($rawFilter) ? $rawFilter : []);
