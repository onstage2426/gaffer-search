<?php

declare(strict_types=1);

namespace GafferSearch\Tests;

use GafferSearch\Engine;
use GafferSearch\Support\SearchRunner;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * The public API (API.md) as text, compared with the committed api.txt: a change fails here
 * until api.txt is updated on purpose (`UPDATE_API=1 composer test`), so it shows up as a diff
 * in review. Public is the engine contract (Gaffer's side: docs/contract.md, held by
 * tests/contract/), the hosting side (WP-CLI, wp-config.php constants) and nothing else: every
 * class and function is internal.
 */
final class ApiTest extends TestCase
{
    private const string SNAPSHOT = __DIR__ . '/../api.txt';

    public function test_public_api_matches_the_snapshot(): void
    {
        $api = self::api();
        if (getenv('UPDATE_API') === '1') {
            file_put_contents(self::SNAPSHOT, $api);
        }
        self::assertFileExists(self::SNAPSHOT, 'Create it with: UPDATE_API=1 composer test');
        self::assertSame(
            (string) file_get_contents(self::SNAPSHOT),
            $api,
            "The public API changed. If that's intended, update API.md and the snapshot: UPDATE_API=1 composer test",
        );
    }

    /** Every class in src/ says it's internal, where an editor shows it. */
    public function test_every_class_is_marked_internal(): void
    {
        $unmarked = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $code = (string) file_get_contents($file->getPathname());
            if (str_contains($file->getPathname(), '/WpHubUpdater/') || preg_match('/^namespace ([^;]+);.*?^(?:final |abstract |readonly )*(?:class|interface|trait|enum) (\w+)/ms', $code, $m) !== 1) {
                continue;
            }
            $class = "{$m[1]}\\{$m[2]}";
            if (!str_contains((string) (new ReflectionClass($class))->getDocComment(), '@internal')) {
                $unmarked[] = $class;
            }
        }
        self::assertSame([], $unmarked, 'Mark every class @internal: the contract is the public API.');
    }

    /** No gaffer_search_*() function is public either. */
    public function test_every_function_is_marked_internal(): void
    {
        $unmarked = array_values(array_filter(
            get_defined_functions()['user'],
            static fn(string $f): bool => str_starts_with($f, 'gaffer_search_') && !str_contains((string) (new \ReflectionFunction($f))->getDocComment(), '@internal'),
        ));
        self::assertSame([], $unmarked);
    }

    public static function api(): string
    {
        $root  = dirname(__DIR__);
        $lines = ["# Contract 1: apply_filters('gaffer_search_engine', null, 1)"];
        $methods = array_filter((new ReflectionClass(Engine::class))->getMethods(ReflectionMethod::IS_PUBLIC), static fn(ReflectionMethod $m): bool => !$m->isConstructor() && !$m->isStatic());
        usort($methods, static fn(ReflectionMethod $a, ReflectionMethod $b): int => $a->getName() <=> $b->getName());
        foreach ($methods as $method) {
            $lines[] = self::signature($method, $method->getName());
        }

        $lines[] = '';
        $lines[] = '# Search response (search(), the endpoint)';
        $lines[] = implode(', ', [...array_keys(SearchRunner::empty('', SearchRunner::options([]))), 'time']);

        $lines[] = '';
        $lines[] = '# Endpoint error codes';
        preg_match_all('/->fail\(\s*\'([a-z_]+)\'/', (string) file_get_contents("{$root}/src/Support/SearchEndpoint.php"), $codes);
        $codes = array_unique($codes[1]);
        sort($codes);
        $lines[] = implode(', ', $codes);

        $lines[] = '';
        $lines[] = '# WP-CLI';
        preg_match_all("/add_command\\('([^']+)'/", self::sources("{$root}/src"), $commands);
        sort($commands[1]);
        array_push($lines, ...array_map(static fn(string $c): string => "wp {$c}", $commands[1]));

        $lines[] = '';
        $lines[] = '# wp-config.php constants and the server environment';
        preg_match_all("/\\b(GAFFER_SEARCH_[A-Z_]+)\\b/", self::sources("{$root}/src") . self::sources("{$root}/endpoints"), $constants);
        $constants = array_unique($constants[1]);
        sort($constants);
        array_push($lines, ...$constants);

        return implode("\n", $lines) . "\n";
    }

    private static function signature(ReflectionFunctionAbstract $f, string $name): string
    {
        $params = implode(', ', array_map(static function (ReflectionParameter $p): string {
            $type    = $p->getType();
            $out     = ($type !== null ? self::type($type) . ' ' : '') . ($p->isVariadic() ? '...' : '') . '$' . $p->getName();
            return $p->isDefaultValueAvailable() ? $out . ' = ' . self::value($p->getDefaultValue()) : $out;
        }, $f->getParameters()));
        $return = $f->getReturnType();

        return "{$name}({$params})" . ($return !== null ? ': ' . self::type($return) : '');
    }

    private static function value(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_array($value) => '[' . implode(', ', array_map(self::value(...), $value)) . ']',
            default => var_export($value, true),
        };
    }

    private static function type(\ReflectionType $type): string
    {
        return $type instanceof ReflectionNamedType && $type->allowsNull() && $type->getName() !== 'mixed' && $type->getName() !== 'null'
            ? '?' . $type->getName()
            : (string) $type;
    }

    private static function sources(string $dir): string
    {
        $all = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            // src/WpHubUpdater: the prefixed updater library a release (or deploy.sh) generates.
            if ($file->getExtension() === 'php' && !str_contains($file->getPathname(), '/WpHubUpdater/')) {
                $all .= file_get_contents($file->getPathname());
            }
        }
        return $all;
    }
}
