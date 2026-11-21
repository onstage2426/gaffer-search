<?php

declare(strict_types=1);

namespace GafferSearch\Support;

use Fuzor\Index;

/**
 * The browser's search (endpoints/search.php), without WordPress: the token names its site, whose
 * config (ConfigLocation) holds the secret it is verified with and the index files; the visitor
 * may change only q, offset, sort[], filter[] (fields the token doesn't lock) and limit (one of
 * the token's `limits`). The search itself is SearchRunner, as from PHP.
 *
 * `?check=<site>` (no token) is a health check: {"ok": true}, or config_missing.
 *
 * Every error answer is {"error": "<message>", "code": "<code>"} (docs/contract.md):
 *   config_missing (503)   the token's site has no endpoint config
 *   token_required (400)   no token parameter
 *   invalid_token (400)    the token names no site, or its signature doesn't verify with that
 *                          site's secret (e.g. the token secret was rotated)
 *   token_expired (400)    the token had a `ttl` and its time is up
 *   index_missing (400)    the token names no index
 *   language_unknown (400) the index has no file for the token's language
 *   index_unknown (503)    the token names an index that isn't registered
 *   index_not_built (503)  the index is registered but not built yet (with an empty answer's keys)
 *   search_failed (503)    the search threw (with an empty answer's keys; details in the PHP log)
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class SearchEndpoint
{
    /** The parameters this endpoint reads (besides token). */
    private const array KNOWN = ['q', 'offset', 'limit', 'sort', 'filter'];

    /** @var array<string, string> header name => value, sent with every answer */
    private array $headers = [
        'Content-Type'           => 'application/json; charset=utf-8',
        'X-Content-Type-Options' => 'nosniff',
        'X-Robots-Tag'           => 'noindex, nofollow',
        // A safe default; a public policy once the index is known (cache()).
        'Cache-Control'          => 'no-store',
    ];

    /**
     * @param string                $endpointDir The endpoints/ folder (__DIR__ there).
     * @param array<mixed>          $get         $_GET
     * @param array<string, mixed>  $server      $_SERVER
     */
    public function __construct(
        private readonly string $endpointDir,
        private readonly array $get,
        private readonly array $server,
    ) {}

    public function handle(): SearchEndpointResponse
    {
        $start = microtime(true);

        // The health check, without a token: answers {"ok": true} when PHP runs here and finds
        // that site's config, else config_missing. Says nothing else about the site.
        if (isset($this->get['check']) && !isset($this->get['token'])) {
            $site = is_numeric($this->get['check']) ? max(1, (int) $this->get['check']) : 1;
            return $this->config($site) === null
                ? $this->fail('config_missing', 'Search is not configured yet.', 503)
                : $this->answer(['ok' => true]);
        }

        // The token names its site (one config per site of a network; a single site is site 1).
        // The claim is read before the signature is checked only to pick the config — and so the
        // secret — to check it with: a token that claims another site fails with that site's secret.
        $token = $this->get['token'] ?? null;
        if (!is_string($token) || $token === '') {
            return $this->fail('token_required', 'A token is required.', 400);
        }
        $site = (int) (Token::claims($token)['site'] ?? 0);
        if ($site < 1) {
            return $this->fail('invalid_token', 'Invalid token.', 400);
        }
        $config = $this->config($site);
        if ($config === null) {
            return $this->fail('config_missing', 'Search is not configured yet.', 503);
        }

        $secret = (string) ($config['token_secret'] ?? '');
        $opts   = $secret !== '' ? Token::verify($token, $secret) : null;
        if ($opts === null) {
            return $this->fail('invalid_token', 'Invalid token.', 400);
        }
        $expires = isset($opts['expires']) && is_numeric($opts['expires']) ? (int) $opts['expires'] : null;
        if ($expires !== null && time() >= $expires) {
            return $this->fail('token_expired', 'The token has expired.', 400);
        }

        $name     = (string) preg_replace('/[^a-z0-9_\-]/i', '', (string) ($opts['index'] ?? ''));
        $indexDir = (string) ($config['index_dir'] ?? '');
        $indexes  = is_array($config['indexes'] ?? null) ? $config['indexes'] : [];
        if ($name === '') {
            return $this->fail('index_missing', 'The token names no index.', 400);
        }
        // name → language → file slug ('' on a site without languages); the token names its language.
        $slug = $indexes[$name][ (string) ($opts['language'] ?? '')] ?? null;
        if (!is_string($slug) && is_array($indexes[$name] ?? null)) {
            // The index is there, not in the token's language: the site gained or lost languages
            // since the page was cached. The page is stale, not the search down.
            return $this->fail('language_unknown', "Index '{$name}' has no file for this page's language.", 400);
        }
        if (!is_string($slug)) {
            return $this->fail('index_unknown', "Index '{$name}' not found.", 503);
        }

        $options = SearchRunner::choose(SearchRunner::options($opts), $this->get['limit'] ?? null);
        $q       = isset($this->get['q']) && is_string($this->get['q']) ? trim($this->get['q']) : '';
        $path    = $indexDir . '/' . $slug . '.db';
        if (!Index::exists($path) && !self::settled($path)) {
            // With an empty answer's keys, as search_failed: a client that reads the results shows none.
            return $this->fail('index_not_built', "Index '{$name}' not built yet.", 503, SearchRunner::forJson(SearchRunner::empty($q, SearchRunner::options($opts))));
        }

        if ($this->cache($path, $indexDir . '/' . $slug . '.labels.json', $expires)) {
            return new SearchEndpointResponse(304, $this->headers, null);
        }

        // The visitor's filter[] (FilterQuery::parse()). Fields locked in the token's own `filter`
        // replace these per field (SearchRunner).
        $rawFilter                 = $this->get['filter'] ?? [];
        [$filter, $filterWarnings] = FilterQuery::visitor(is_array($rawFilter) ? $rawFilter : []);
        $offset                    = isset($this->get['offset']) && is_numeric($this->get['offset']) ? (int) $this->get['offset'] : 0;
        $rawSort                   = $this->get['sort'] ?? [];
        $sort                      = SortQuery::parse(is_array($rawSort) ? $rawSort : []);

        try {
            try {
                $response = SearchRunner::run($path, $q, $options, $offset, $sort, $filter);
            } catch (\Throwable $first) {
                // The version opened was swapped out and deleted mid-request (settled()): once more on the current one.
                if (!self::settled($path)) {
                    throw $first;
                }
                $response = SearchRunner::run($path, $q, $options, $offset, $sort, $filter);
            }
        } catch (\Throwable $e) {
            error_log('Gaffer Search: search error for query ' . json_encode($q, JSON_INVALID_UTF8_SUBSTITUTE) . ': ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.WP.AlternativeFunctions.json_encode_json_encode
            return $this->fail('search_failed', 'Search unavailable.', 503, SearchRunner::forJson(SearchRunner::empty($q, $options, $offset)));
        }

        $response['warnings'] = [...$response['warnings'], ...$filterWarnings, ...$this->ignoredParams()];
        $response['time']     = strval(round((microtime(true) - $start) * 1000, 2)) . 'ms';
        return $this->answer(SearchRunner::forJson($response));
    }

    /** @return array<string, mixed>|null */
    private function config(int $site): ?array
    {
        $script = isset($this->server['SCRIPT_FILENAME']) ? (string) $this->server['SCRIPT_FILENAME'] : null;
        return ConfigLocation::load($this->endpointDir, $script, $site);
    }

    /** @param array<string, mixed> $data */
    private function answer(array $data, int $status = 200): SearchEndpointResponse
    {
        return new SearchEndpointResponse($status, $this->headers, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
    }

    /** @param array<string, mixed> $extra */
    private function fail(string $code, string $message, int $status, array $extra = []): SearchEndpointResponse
    {
        $this->headers['Cache-Control']     = 'no-store';
        $this->headers['CDN-Cache-Control'] = 'no-store';
        return $this->answer(['error' => $message, 'code' => $code] + $extra, $status);
    }

    /**
     * The caching headers, and whether the request's own copy is still fresh (a 304). The
     * validator covers the full request (same URL + same index state = identical answer): the
     * index file *and* its -wal (a write still in the WAL changes the WAL's size and mtime before
     * the main file's mtime moves; the inode changes when a rebuild swaps in a new file), and the
     * labels file (names are their own file: an update of names only changes nothing else).
     */
    private function cache(string $path, string $labels, ?int $expires): bool
    {
        clearstatcache();
        $file     = realpath($path) ?: $path; // The index path links to the current version; its -wal is named after that.
        $db       = @stat($file) ?: ['ino' => 0, 'size' => 0, 'mtime' => 0]; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $wal      = @stat($file . '-wal') ?: ['size' => 0, 'mtime' => 0]; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $names    = @stat($labels) ?: ['size' => 0, 'mtime' => 0]; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $modified = max( (int) $db['mtime'], (int) $wal['mtime'], (int) $names['mtime']);
        $state    = implode(':', [$db['ino'], $db['size'], $db['mtime'], $wal['size'], $wal['mtime'], $names['size'], $names['mtime']]);
        $etag     = '"' . substr(hash('sha256', $state . '|' . http_build_query(self::normalized($this->get))), 0, 16) . '"';

        $this->headers['Last-Modified'] = gmdate('D, d M Y H:i:s \G\M\T', $modified);
        $this->headers['ETag']          = $etag;
        $this->headers['Vary']          = 'Accept-Encoding';
        if ($expires === null) {
            $this->headers['Cache-Control']     = 'public, max-age=60, s-maxage=300, stale-while-revalidate=60';
            $this->headers['CDN-Cache-Control'] = 'public, max-age=300, stale-while-revalidate=60';
        } else {
            // An expiring token is one visitor's (a customer group's page): the browser may keep the
            // answer briefly, never past the expiry; shared caches don't keep it.
            $this->headers['Cache-Control']     = 'private, max-age=' . max(0, min(60, $expires - time()));
            $this->headers['CDN-Cache-Control'] = 'no-store';
        }

        $ifNoneMatch = trim( (string) ($this->server['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($ifNoneMatch !== '') {
            $tags = array_map(trim(...), explode(',', $ifNoneMatch));
            return in_array($etag, $tags, true) || in_array('*', $tags, true);
        }
        $ims = trim( (string) ($this->server['HTTP_IF_MODIFIED_SINCE'] ?? ''));
        return $ims !== '' && strtotime($ims) >= $modified;
    }

    /**
     * Whether the index at $path is there after a moment: a rebuild publishes by swapping a
     * symlink, and a request that resolved the link just before two quick swaps finds its version
     * deleted. Waiting once for the link to settle answers from the current version instead of a 503.
     */
    private static function settled(string $path): bool
    {
        if (!is_link($path)) {
            return false;
        }
        usleep(100_000);
        clearstatcache();

        return Index::exists($path);
    }

    /**
     * The request's parameters with named keys sorted (lists keep their order: sort[] is a
     * priority), so the same parameters in another order give the same validator.
     *
     * @param  array<mixed> $params
     * @return array<mixed>
     */
    private static function normalized(array $params): array
    {
        if (!array_is_list($params)) {
            ksort($params, SORT_STRING);
        }
        return array_map(static fn(mixed $value): mixed => is_array($value) ? self::normalized($value) : $value, $params);
    }

    /**
     * A warning naming the request's parameters the endpoint doesn't read (none: []). An unknown
     * parameter usually means a field that doesn't belong in the search form: a card's own form
     * nested in it (the HTML parser drops a nested <form> tag, so its fields join the outer form),
     * or a misspelled name. Campaign parameters (utm_*, gclid, …) aren't reported.
     *
     * @return list<string>
     */
    private function ignoredParams(): array
    {
        $ignored = array_filter(
            array_map(strval(...), array_keys($this->get)),
            static fn(string $name): bool => !in_array($name, [...self::KNOWN, 'token', '_'], true)
                && !str_starts_with($name, 'utm_')
                && !in_array($name, ['gclid', 'fbclid', 'msclkid'], true),
        );
        return $ignored === [] ? [] : [
            'Parameters this endpoint doesn\'t read: ' . implode(', ', $ignored) . '; ignored. A form inside the search form (a card\'s add-to-cart) '
            . 'adds its fields to it: keep results outside the form (createAlpineSearch({ form })).',
        ];
    }
}
