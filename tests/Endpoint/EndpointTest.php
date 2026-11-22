<?php

declare(strict_types=1);

namespace GafferSearch\Tests\Endpoint;

use Fuzor\Index;
use GafferSearch\Support\ConfigLocation;
use GafferSearch\Support\IndexSlug;
use GafferSearch\Support\Token;
use GafferSearch\Tests\TestCase;

/**
 * The HTTP contract of endpoints/search.php (docs/js.md "Response shapes"
 * and "Errors"), through PHP's built-in web server on the real endpoint files, with a config
 * written the way WordPress writes it.
 */
final class EndpointTest extends TestCase
{
    private const string SITE_2_SECRET = 'token-secret-of-site-2';

    /** @var array<string, array{0: resource, 1: string}> server key => [process, base URL] */
    private static array $servers = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$servers as [$process]) {
            proc_terminate($process);
            proc_close($process);
        }
        self::$servers = [];
    }

    public function test_search_answers_the_documented_response(): void
    {
        [$status, $headers, $body] = $this->get('search', ['q' => 'jurk', 'token' => $this->token(['facets' => ['brand', 'price']])]);

        self::assertSame(200, $status);
        self::assertSame(['query', 'total', 'offset', 'limit', 'results', 'facets', 'facetStats', 'labels', 'exhaustive', 'approximateFacets', 'truncatedFacets', 'warnings', 'time'], array_keys($body));
        self::assertSame(5, $body['total']);
        self::assertSame(['stella' => 4, 'rosa-clara' => 1], $body['facets']['brand']);
        self::assertSame(['stella' => 'Stella', 'rosa-clara' => 'Rosa Clará'], $body['labels']['brand']);
        self::assertSame(['title', 'formatted'], array_values(array_intersect(['title', 'formatted'], array_keys($body['results'][0]))));
        self::assertStringStartsWith('application/json', $headers['content-type']);
        self::assertSame('nosniff', $headers['x-content-type-options']);
        self::assertSame('noindex, nofollow', $headers['x-robots-tag']);
        self::assertStringContainsString('s-maxage=300', $headers['cache-control']);
        self::assertArrayNotHasKey('access-control-allow-origin', $headers);
    }

    public function test_free_params_and_locked_params(): void
    {
        $token = $this->token(['limit' => 2, 'filter' => ['brand' => 'rosa-clara']]);
        [, , $body] = $this->get('search', ['token' => $token, 'offset' => '1', 'sort' => ['price:asc'], 'filter' => ['brand' => 'stella', 'color' => 'wit']]);

        self::assertSame(3, $body['total'], 'brand from the token replaces the visitor\'s; color still applies');
        self::assertSame([9, 3], array_column($body['results'], 'id'));
        self::assertSame([1, 2], [$body['offset'], $body['limit']]);
    }

    public function test_params_the_endpoint_does_not_read_are_named_in_warnings(): void
    {
        $token  = $this->token(['facets' => ['brand']]);
        $params = ['q' => 'jurk', 'token' => $token, 'product' => '705', 'attributes' => ['attribute_pa_formaat' => 'doos'], 'utm_source' => 'mail', 'gclid' => 'x', 'filter' => ['brand' => 'stella']];
        [, , $body] = $this->get('search', $params);
        self::assertCount(1, $body['warnings']);
        self::assertStringStartsWith('Parameters this endpoint doesn\'t read: product, attributes; ignored.', $body['warnings'][0]);
        self::assertSame(4, $body['total'], 'still answered');

        [, , $body] = $this->get('search', ['q' => 'jurk', 'token' => $token, 'offset' => '0', 'sort' => ['price:asc'], 'utm_campaign' => 'x']);
        self::assertSame([], $body['warnings']);
    }

    public function test_empty_facets_are_json_objects(): void
    {
        $raw = $this->raw('search', ['q' => 'zzzz', 'token' => $this->token()]);

        self::assertStringContainsString('"facets":{}', $raw);
        self::assertStringContainsString('"facetStats":{}', $raw);
        self::assertStringContainsString('"labels":{}', $raw);
    }

    public function test_a_repeated_request_with_its_etag_is_not_modified(): void
    {
        $query = ['q' => 'jurk', 'token' => $this->token()];
        [, $headers] = $this->get('search', $query);

        [$status] = $this->get('search', $query, ['If-None-Match: ' . $headers['etag']]);
        self::assertSame(304, $status);

        // A proxy that compresses the answer (nginx's gzip) sends it on as a weak ETag.
        [$status] = $this->get('search', $query, ['If-None-Match: "other", W/' . $headers['etag']]);
        self::assertSame(304, $status);
    }

    public function test_a_write_still_in_the_wal_changes_the_etag(): void
    {
        $query = ['q' => 'jurk', 'token' => $this->token()];
        [, $before] = $this->get('search', $query);

        // The open connection keeps the write in the version file's -wal (no checkpoint yet).
        $index = new Index(self::indexPath());
        $index->upsert([self::documents()[0]]);
        [, $after] = $this->get('search', $query);
        $index->close();

        self::assertNotSame($before['etag'], $after['etag']);
    }

    public function test_an_expiring_token_works_until_then_and_is_never_shared(): void
    {
        [$status, $headers, $body] = $this->get('search', ['token' => $this->token(['expires' => time() + 30])]);

        self::assertSame([200, 12], [$status, $body['total']]);
        self::assertMatchesRegularExpression('/^private, max-age=([0-9]|[12][0-9]|30)$/', $headers['cache-control'], 'never cached past the expiry');
        self::assertSame('no-store', $headers['cdn-cache-control']);
    }

    /** @return iterable<string, array{0: string, 1: array<string, mixed>, 2: int, 3: string}> */
    public static function errors(): iterable
    {
        yield 'no token'                    => ['search', [], 400, 'token_required'];
        yield 'forged token'                => ['search', ['token' => Token::sign(['index' => 'products', 'site' => 1], 'wrong-secret')], 400, 'invalid_token'];
        yield 'token without a site'        => ['search', ['token' => Token::sign(['index' => 'products'], self::TOKEN_SECRET)], 400, 'invalid_token'];
        yield 'claims another site'         => ['search', ['token' => Token::sign(['index' => 'products', 'site' => 2], self::TOKEN_SECRET)], 400, 'invalid_token'];
        yield 'a site without a config'     => ['search', ['token' => Token::sign(['index' => 'products', 'site' => 3], self::TOKEN_SECRET)], 503, 'config_missing'];
        yield 'token without index'         => ['search', ['token' => ['limit' => 5]], 400, 'index_missing'];
        yield 'unregistered index'          => ['search', ['token' => ['index' => 'posts']], 503, 'index_unknown'];
        yield 'registered, not built'       => ['search', ['token' => ['index' => 'unbuilt']], 503, 'index_not_built'];
        yield 'not an index file'           => ['search', ['token' => ['index' => 'broken']], 503, 'index_not_built'];
        yield 'damaged index'               => ['search', ['token' => ['index' => 'damaged'], 'q' => 'jurk'], 503, 'search_failed'];
        yield 'expired token'               => ['search', ['token' => ['index' => 'products', 'expires' => time() - 1]], 400, 'token_expired'];
    }

    /** @param array<string, mixed> $query */
    #[\PHPUnit\Framework\Attributes\DataProvider('errors')]
    public function test_errors_answer_with_their_code(string $endpoint, array $query, int $status, string $code): void
    {
        if (is_array($query['token'] ?? null)) {
            $query['token'] = Token::sign($query['token'] + ['site' => 1], self::TOKEN_SECRET);
        }

        [$actual, $headers, $body] = $this->get($endpoint, $query);

        self::assertSame([$status, $code], [$actual, $body['code'] ?? null]);
        self::assertIsString($body['error']);
        self::assertSame('no-store', $headers['cache-control'], 'errors are never cached');
    }

    public function test_an_unbuilt_index_answers_with_an_empty_responses_keys(): void
    {
        [$status, , $body] = $this->get('search', ['q' => 'jurk', 'token' => Token::sign(['index' => 'unbuilt', 'site' => 1], self::TOKEN_SECRET)]);

        self::assertSame([503, 'index_not_built'], [$status, $body['code']]);
        self::assertSame(['jurk', 0, [], [], []], [$body['query'], $body['total'], $body['results'], $body['facets'], $body['warnings']], 'a client that reads the results shows none');
    }

    public function test_each_site_has_its_own_config_secret_and_indexes(): void
    {
        $token = Token::sign(['index' => 'products', 'site' => 2, 'language' => 'nl', 'facets' => ['brand']], self::SITE_2_SECRET);
        [$status, , $body] = $this->get('search', ['token' => $token, 'q' => 'jurk']);

        self::assertSame([200, 5], [$status, $body['total']], 'site 2\'s own copy of the index, verified with site 2\'s secret');
        self::assertSame('Stella', $body['labels']['brand']['stella'], 'its labels file too');

        [$status, , $body] = $this->get('search', ['token' => Token::sign(['index' => 'unbuilt', 'site' => 2, 'language' => 'nl'], self::SITE_2_SECRET)]);
        self::assertSame([503, 'index_unknown'], [$status, $body['code']], 'site 1\'s indexes are not site 2\'s');
    }

    public function test_the_tokens_language_picks_the_file(): void
    {
        $search = fn(array $claims): array => $this->get('search', ['token' => Token::sign(['index' => 'products', 'site' => 2, ...$claims], self::SITE_2_SECRET)])[2];

        self::assertSame(['Red dress'], array_column($search(['language' => 'en-us'])['results'], 'title'));
        self::assertSame(12, $search(['language' => 'nl'])['total']);
        self::assertSame('language_unknown', $search(['language' => 'de'])['code'] ?? null, 'a language without a file: a stale page');
        self::assertSame('language_unknown', $search([])['code'] ?? null, 'no language on a site with languages');
        [$status] = $this->get('search', ['token' => Token::sign(['index' => 'products', 'site' => 2, 'language' => 'de'], self::SITE_2_SECRET)]);
        self::assertSame(400, $status);
    }

    public function test_without_a_config_the_endpoint_says_so(): void
    {
        [$status, , $body] = $this->get('search', ['token' => $this->token()], [], 'unconfigured');

        self::assertSame([503, 'config_missing'], [$status, $body['code']]);
    }

    public function test_the_health_check_says_whether_the_sites_config_is_found(): void
    {
        [$status, $headers, $body] = $this->get('search', ['check' => '1']);
        self::assertSame([200, ['ok' => true]], [$status, $body]);
        self::assertSame('no-store', $headers['cache-control']);
        self::assertSame(200, $this->get('search', ['check' => '2'])[0], 'site 2 has its own config');
        self::assertSame([503, 'config_missing'], [$this->get('search', ['check' => '7'])[0], $this->get('search', ['check' => '7'])[2]['code']], 'a site without one');
        self::assertSame('config_missing', $this->get('search', ['check' => '1'], [], 'unconfigured')[2]['code']);
    }

    /** @param array<string, mixed> $params */
    public function test_the_visitor_chooses_among_the_tokens_limits(): void
    {
        $token = $this->token(['limit' => 2, 'limits' => [4]]);

        [, , $body] = $this->get('search', ['token' => $token, 'limit' => '4']);
        self::assertSame([4, 4], [$body['limit'], count($body['results'])]);

        [, , $body] = $this->get('search', ['token' => $token, 'limit' => '50', 'view' => 'list']);
        self::assertSame(2, $body['limit'], 'not offered: the token\'s own');
        self::assertStringContainsString("doesn't read: view;", implode(' ', $body['warnings']));
    }

    private function token(array $params = []): string
    {
        return Token::sign(['index' => 'products', 'site' => 1, ...$params], self::TOKEN_SECRET);
    }

    /**
     * @param  array<string, mixed> $query
     * @param  list<string>          $headers
     * @return array{0: int, 1: array<string, string>, 2: array<string, mixed>}
     */
    private function get(string $endpoint, array $query, array $headers = [], string $server = 'configured'): array
    {
        $body = $this->raw($endpoint, $query, $headers, $server, $responseHeaders);

        $status = (int) explode(' ', $responseHeaders[0] ?? '')[1];
        $parsed = [];
        foreach (array_slice($responseHeaders, 1) as $line) {
            [$name, $value] = array_map(trim(...), explode(':', $line, 2)) + [1 => ''];
            $parsed[strtolower($name)] = $value;
        }

        return [$status, $parsed, $body === '' ? [] : (array) json_decode($body, true, flags: JSON_THROW_ON_ERROR)];
    }

    /**
     * @param array<string, mixed> $query
     * @param list<string>         $headers
     * @param list<string>|null    $responseHeaders
     */
    private function raw(string $endpoint, array $query, array $headers = [], string $server = 'configured', ?array &$responseHeaders = null): string
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'header' => implode("\r\n", $headers)]]);
        $body    = (string) file_get_contents(self::server($server) . "/endpoints/{$endpoint}.php?" . http_build_query($query), false, $context);

        $responseHeaders = http_get_last_response_headers() ?? [];
        return $body;
    }

    /** A built-in web server on the plugin folder; "configured" has the fixture config, "unconfigured" none. */
    private static function server(string $key): string
    {
        if (isset(self::$servers[$key])) {
            return self::$servers[$key][1];
        }

        $config = self::dir() . "/{$key}-config";
        if ($key === 'configured') {
            mkdir("{$config}/sites", 0777, true);
            file_put_contents(self::dir() . '/' . IndexSlug::compute('broken', self::SEARCH_SECRET) . '.db', 'not a database');
            // A real index missing a table a search reads: it passes Index::exists(), then fails.
            $damaged = self::dir() . '/' . IndexSlug::compute('damaged', self::SEARCH_SECRET) . '.db';
            copy(self::indexPath(), $damaged);
            (new \PDO("sqlite:{$damaged}"))->exec('DROP TABLE doclist');
            file_put_contents("{$config}/sites/1.php", '<?php return ' . var_export([
                'index_dir'    => self::dir(),
                'token_secret' => self::TOKEN_SECRET,
                'indexes'      => [
                    'products' => ['' => IndexSlug::compute('products', self::SEARCH_SECRET)],
                    'unbuilt'  => ['' => IndexSlug::compute('unbuilt', self::SEARCH_SECRET)],
                    'broken'   => ['' => IndexSlug::compute('broken', self::SEARCH_SECRET)],
                    'damaged'  => ['' => IndexSlug::compute('damaged', self::SEARCH_SECRET)],
                ],
            ], true) . ';');

            // Site 2 of a network, with languages: its own secrets and folder, an nl file (a copy
            // of the fixture) and an en file with one document of its own.
            $site2 = self::dir() . '/site-2';
            @mkdir($site2);
            $nl = IndexSlug::compute('products', 'search-secret-of-site-2', 'nl');
            $en = IndexSlug::compute('products', 'search-secret-of-site-2', 'en-us');
            copy(self::indexPath(), "{$site2}/{$nl}.db");
            copy(\GafferSearch\Support\Labels::path(self::indexPath()), \GafferSearch\Support\Labels::path("{$site2}/{$nl}.db"));
            $english = new \Fuzor\Index("{$site2}/{$en}.db", force: true, schema: new \Fuzor\SchemaConfig(filterableAttributes: ['id', 'brand'], sortableAttributes: ['id', 'brand'], searchableAttributes: ['title']));
            $english->insert([['id' => 101, 'title' => 'Red dress', 'brand' => 'stella']]);
            $english->close();
            file_put_contents("{$config}/sites/2.php", '<?php return ' . var_export([
                // Relative to the config file's folder (as SearchConfig writes it inside wp-content).
                'index_dir'    => '../../site-2',
                'token_secret' => self::SITE_2_SECRET,
                'indexes'      => ['products' => ['nl' => $nl, 'en-us' => $en]],
            ], true) . ';');
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port   = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);

        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', dirname(__DIR__, 2)],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            null,
            [...getenv(), ConfigLocation::ENV => $config],
        );
        self::assertIsResource($process);

        $url = "http://127.0.0.1:{$port}";
        for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
            usleep(50_000);
        }
        self::$servers[$key] = [$process, $url];
        return $url;
    }
}
