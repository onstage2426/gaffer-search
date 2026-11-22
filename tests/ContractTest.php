<?php

declare(strict_types=1);

namespace GafferSearch\Tests;

use GafferSearch\Engine;
use GafferSearch\Support\ConfigLocation;
use GafferSearch\Support\IndexSlug;
use GafferSearch\Support\Token;
use GafferSearchTestWordPress;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_Term;

/**
 * Contract 1 (docs/contract.md), held by the cases in tests/contract/: each is a call and what
 * its answer must contain. A case without `expect` is recorded from the real answer with
 * RECORD_CONTRACT=1; a recorded case is never edited or deleted, so a failure here means a change
 * breaks the contract.
 */
final class ContractTest extends TestCase
{
    /** sha256 of every recorded case: a recorded case is never edited or deleted. */
    private const string MANIFEST = __DIR__ . '/contract/MANIFEST';

    /** Keys whose values change per run: recorded as "*" (present, any value). */
    private const array VOLATILE = ['time', 'expires', 'updates_checked'];

    /**
     * Keys whose items are text for people: the contract promises a list of strings (a
     * misconfiguration was seen), not their wording, which partly comes from the library (Fuzor
     * renamed `Config::$maxDocs` to `Limits::$candidatesPerWord` in c3bdccf). Compared by count.
     */
    private const array PROSE = ['warnings'];

    /** @var array{0: resource, 1: string}|null */
    private static ?array $server = null;

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server[0]);
            proc_close(self::$server[0]);
            self::$server = null;
        }
    }

    /** @return iterable<string, array{string}> */
    public static function cases(): iterable
    {
        foreach (glob(__DIR__ . '/contract/*.json') ?: [] as $file) {
            yield basename($file, '.json') => [$file];
        }
    }

    public function test_recorded_cases_are_never_edited_or_deleted(): void
    {
        foreach (self::manifest() as $name => $hash) {
            $file = __DIR__ . "/contract/{$name}";
            self::assertFileExists($file, "{$name} was recorded: deleting it means a change broke contract 1");
            self::assertSame($hash, hash_file('sha256', $file), "{$name} was recorded: editing it means a change broke contract 1. Add a new case instead.");
        }
        foreach (glob(__DIR__ . '/contract/*.json') ?: [] as $file) {
            $case = json_decode( (string) file_get_contents($file), true);
            if (is_array($case) && array_key_exists('expect', $case)) {
                self::assertArrayHasKey(basename($file), self::manifest(), basename($file) . ' has an expectation but isn\'t in tests/contract/MANIFEST: record cases with RECORD_CONTRACT=1');
            }
        }
    }

    /** @return array<string, string> case file => sha256 */
    private static function manifest(): array
    {
        $manifest = [];
        foreach (is_file(self::MANIFEST) ? file(self::MANIFEST, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] : [] as $line) {
            [$hash, $name] = explode('  ', $line, 2) + [1 => ''];
            $manifest[$name] = $hash;
        }
        return $manifest;
    }

    #[DataProvider('cases')]
    public function test_the_contract_holds(string $file): void
    {
        $case = json_decode( (string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        self::registerIndex();
        foreach ($case['setup']['terms'] ?? [] as $term) {
            GafferSearchTestWordPress::$terms[ (int) $term['id']] = new WP_Term( (int) $term['id'], $term['taxonomy'], $term['name'], $term['slug']);
            GafferSearchTestWordPress::$parents[ (int) $term['id']] = (int) ($term['parent'] ?? 0);
        }
        foreach ($case['setup']['options'] ?? [] as $name => $value) {
            update_option($name, $value);
        }
        if (isset($case['setup']['build'])) {
            self::build($case['setup']['build']);
        }

        $actual = self::plain($this->call($case));

        if (!array_key_exists('expect', $case)) {
            if (getenv('RECORD_CONTRACT') !== '1') {
                self::fail(basename($file) . ' has no expectation yet: record it with RECORD_CONTRACT=1 composer test');
            }
            $case['expect'] = $case['call'] === 'problems' ? $case['expect_contains'] ?? [] : self::recorded($actual);
            file_put_contents($file, json_encode($case, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n");
            file_put_contents(self::MANIFEST, hash_file('sha256', $file) . '  ' . basename($file) . "\n", FILE_APPEND);
            self::markTestSkipped('recorded ' . basename($file));
        }

        if ($case['call'] === 'problems') {
            // Messages are words, not shapes: each expected piece appears in one of them.
            self::assertCount(count($case['expect']) === 0 ? 0 : count($actual), $actual);
            foreach ($case['expect'] as $piece) {
                self::assertNotEmpty(array_filter($actual, static fn(string $m): bool => str_contains($m, $piece)), "no problem mentions \"{$piece}\": " . implode(' | ', $actual));
            }
            return;
        }
        self::assertContract($case['expect'], $actual, basename($file));
    }

    /**
     * @param array<string, mixed> $case
     */
    private function call(array $case): mixed
    {
        $engine = new Engine();
        $args   = $case['args'] ?? [];

        return match ($case['call']) {
            'search'   => $engine->search(...$args),
            'token'    => ['payload' => Token::verify($engine->token(...$args), self::TOKEN_SECRET)],
            'problems' => $engine->problems(...$args),
            'supports' => array_map($engine->supports(...), $args),
            'status'   => $engine->status(),
            'define_then_status' => (static function (Engine $engine, array $indexes): array {
                $engine->define($indexes);
                return $engine->status();
            })($engine, ...$args),
            'endpoint' => $this->endpoint($case),
            default    => self::fail("unknown call {$case['call']}"),
        };
    }

    /**
     * The browser side: a GET to search.php with a token the engine signed (or `raw_token`, or a
     * token signed from `signed` as an older plugin did) and the visitor's query.
     *
     * @param array<string, mixed> $case
     * @return array{status: int, body: array<string, mixed>}
     */
    private function endpoint(array $case): array
    {
        $token = match (true) {
            isset($case['raw_token']) => (string) $case['raw_token'],
            isset($case['signed'])    => Token::sign($case['signed'], self::TOKEN_SECRET),
            default                   => (new Engine())->token(...$case['token']),
        };
        $context = stream_context_create(['http' => ['ignore_errors' => true]]);
        $body    = (string) file_get_contents(self::server() . '/endpoints/search.php?' . http_build_query(['token' => $token, ...($case['query'] ?? [])]), false, $context);
        $status  = (int) explode(' ', (http_get_last_response_headers() ?? [''])[0] . '  ')[1];

        return ['status' => $status, 'body' => (array) json_decode($body, true)];
    }

    /**
     * An index defined through Engine::define() and built by the real builders from fixture
     * posts: for what the contract promises about builds (`direct_terms`, `fields`). `posts`
     * have `id`, `title`, `content`, `terms` (taxonomy => term IDs), `meta` and `document` (what
     * the definition's `document` closure adds to that post); `terms` have `parent`. `labels` is
     * what the definition's `labels` closure returns.
     *
     * @param array{index: string, definition: array<string, mixed>, posts: list<array<string, mixed>>, hierarchical?: list<string>, labels?: array<string, array<string, string>>} $build
     */
    private static function build(array $build): void
    {
        GafferSearchTestWordPress::$hierarchical = $build['hierarchical'] ?? [];
        $documents = [];
        foreach ($build['posts'] as $row) {
            $post               = new \WP_Post();
            $post->ID           = (int) $row['id'];
            $post->post_title   = (string) ($row['title'] ?? '');
            $post->post_content = (string) ($row['content'] ?? '');
            GafferSearchTestWordPress::$posts[$post->ID]     = $post;
            GafferSearchTestWordPress::$postTerms[$post->ID] = $row['terms'] ?? [];
            GafferSearchTestWordPress::$meta[$post->ID]      = $row['meta'] ?? [];
            $documents[$post->ID]                            = $row['document'] ?? [];
        }
        $definition = $build['definition'];
        if (isset($definition['fields'])) {
            $definition['document'] = static fn(array $document): array => [...$document, ...$documents[(int) $document['id']]];
        }
        if (isset($build['labels'])) {
            $labels               = $build['labels'];
            $definition['labels'] = static fn(string $index): array => $labels;
        }

        (new Engine())->define([$build['index'] => $definition]);
        $service = new \GafferSearch\Services\IndexService(new \GafferSearch\Services\SettingsService(), new \GafferSearch\Services\IndexRegistry());
        (new \ReflectionMethod($service, 'buildOne'))->invoke($service, $build['index'], null);
    }

    /** A JSON round trip: the answer as the other side sees it. */
    private static function plain(mixed $value): mixed
    {
        return json_decode( (string) json_encode($value, JSON_PRESERVE_ZERO_FRACTION), true);
    }

    private static function recorded(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = in_array($key, self::VOLATILE, true) ? '*' : self::recorded($item);
        }
        return $value;
    }

    /** $expected is a subset of $actual: maps may have more keys, lists match by position and length. */
    private static function assertContract(mixed $expected, mixed $actual, string $path): void
    {
        if ($expected === '*') {
            return;
        }
        if (!is_array($expected) || $expected === []) {
            self::assertSame($expected, $actual, "{$path}: different value");
            return;
        }
        self::assertIsArray($actual, "{$path}: expected an array");
        if (array_is_list($expected)) {
            self::assertCount(count($expected), $actual, "{$path}: different number of items");
        }
        foreach ($expected as $key => $item) {
            self::assertArrayHasKey($key, $actual, "{$path}.{$key}: missing");
            if (in_array($key, self::PROSE, true) && is_array($item) && array_is_list($item)) {
                self::assertIsList($actual[$key], "{$path}.{$key}: expected a list");
                self::assertCount(count($item), $actual[$key], "{$path}.{$key}: different number of items");
                self::assertContainsOnlyString($actual[$key], "{$path}.{$key}: expected strings");
                continue;
            }
            self::assertContract($item, $actual[$key], "{$path}.{$key}");
        }
    }

    /** search.php on PHP's built-in server, with a config for the fixture index and an unbuilt one. */
    private static function server(): string
    {
        if (self::$server !== null) {
            return self::$server[1];
        }
        $config = self::dir() . '/contract-config';
        @mkdir("{$config}/sites", 0777, true);
        file_put_contents("{$config}/sites/1.php", '<?php return ' . var_export([
            'format'       => 2,
            'index_dir'    => self::dir(),
            'token_secret' => self::TOKEN_SECRET,
            'indexes'      => [
                'products' => ['' => IndexSlug::compute('products', self::SEARCH_SECRET)],
                'unbuilt'  => ['' => IndexSlug::compute('unbuilt', self::SEARCH_SECRET)],
            ],
        ], true) . ';');

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name   = (string) stream_socket_get_name($socket, false);
        $port   = (int) substr($name, strrpos($name, ':') + 1);
        fclose($socket);
        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', dirname(__DIR__)],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            null,
            [...getenv(), ConfigLocation::ENV => $config],
        );
        self::assertIsResource($process);
        for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
            usleep(50_000);
        }
        self::$server = [$process, "http://127.0.0.1:{$port}"];

        return self::$server[1];
    }
}
