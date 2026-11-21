<?php

declare(strict_types=1);

namespace GafferSearch\Tests;

use Fuzor\Index;
use Fuzor\SchemaConfig;
use GafferSearch\IndexDefinition;
use GafferSearch\Builders\IndexBuilderInterface;
use GafferSearch\BuildContext;
use GafferSearch\Support\IndexSlug;
use GafferSearch\Support\Labels;
use GafferSearch\Watch;
use GafferSearchTestWordPress;

/**
 * Every test starts with fresh WordPress state (tests/stubs/wordpress.php) whose settings point
 * at the fixture index: index "products", built once per run from self::documents().
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    public const string SEARCH_SECRET = 'search-secret-for-tests';
    public const string TOKEN_SECRET  = 'token-secret-for-tests';
    public const string INDEX         = 'products';
    public const array FACETS         = ['brand', 'category', 'color', 'price', 'visibility', 'stock_status', 'title_sort'];

    private static ?string $dir = null;

    protected function setUp(): void
    {
        GafferSearchTestWordPress::reset(self::dir());
        update_option('gaffer_search_secret', self::SEARCH_SECRET);
        update_option('gaffer_search_token_secret', self::TOKEN_SECRET);
        update_option('gaffer_search_options', ['index_dir' => self::dir()]);
        $_GET = [];
        \GafferSearch\Support\Language\Languages::reset();
    }

    /** The directory holding the fixture index (and anything else a test writes). */
    public static function dir(): string
    {
        if (self::$dir === null) {
            self::$dir = sys_get_temp_dir() . '/gaffer-search-tests-' . getmypid();
            if (!is_dir(self::$dir)) {
                mkdir(self::$dir, 0777, true);
            }
            self::build(self::indexPath());
            register_shutdown_function(static fn() => self::remove(self::$dir));
        }
        return self::$dir;
    }

    public static function indexPath(): string
    {
        return self::dir() . '/' . IndexSlug::compute(self::INDEX, self::SEARCH_SECRET) . '.db';
    }

    /** Register the fixture index (product_cat → category, pa_kleur → color). */
    protected static function registerIndex(): void
    {
        self::register(new IndexDefinition(name: self::INDEX, label: 'Products', description: 'Fixture'), new FixtureBuilder());
    }

    /** Register an index as Engine::define() does. */
    protected static function register(IndexDefinition $definition, IndexBuilderInterface $builder): void
    {
        \GafferSearch\Services\IndexRegistry::register($definition, $builder);
    }

    /**
     * Twelve products. Taxonomy fields hold slugs (names in self::labels()). Product 12 is
     * hidden from the catalog, 11 from search, 10 is out of stock; 3 has a title with markup
     * characters (highlighting must escape them). 8 is for customer group "vip" only, 9 for
     * "vip" and "pro"; the rest is public.
     *
     * @return list<array<string, mixed>>
     */
    public static function documents(): array
    {
        $rows = [
            [1, 'Rode jurk', 'Een lange rode jurk van zijde.', 129.95, 'stella', ['kleding', 'jurken'], 'rood'],
            [2, 'Blauwe jurk', 'Een korte blauwe jurk.', 89.50, 'stella', ['kleding', 'jurken'], 'blauw'],
            [3, 'Jurk <b>deluxe</b> & co', 'Feestelijke jurk met kant.', 249.00, 'rosa-clara', ['kleding', 'jurken'], 'wit'],
            [4, 'Witte sluier', 'Sluier van tule, drie meter lang.', 59.00, 'rosa-clara', ['accessoires'], 'wit'],
            [5, 'Rode schoenen', 'Pumps met een lage hak.', 79.00, 'pumpkin', ['schoenen'], 'rood'],
            [6, 'Zwarte schoenen', 'Sneakers voor de hele dag.', 99.00, 'pumpkin', ['schoenen'], 'zwart'],
            [7, 'Tiara', 'Tiara met kristallen.', 39.95, 'rosa-clara', ['accessoires'], 'zilver'],
            [8, 'Clutch', 'Kleine tas voor het feest.', 45.00, 'stella', ['accessoires'], 'wit'],
            [9, 'Bolero', 'Kanten bolero voor koude dagen.', 69.00, 'rosa-clara', ['kleding'], 'wit'],
            [10, 'Gouden jurk', 'Een glanzende gouden jurk.', 199.00, 'stella', ['kleding', 'jurken'], 'goud'],
            [11, 'Geheime jurk', 'Alleen in de winkel.', 150.00, 'stella', ['kleding', 'jurken'], 'rood'],
            [12, 'Verborgen tiara', 'Niet in de catalogus.', 35.00, 'rosa-clara', ['accessoires'], 'zilver'],
        ];

        return array_map(static fn(array $r): array => [
            'id'           => $r[0],
            'title'        => $r[1],
            'title_sort'   => mb_strtolower($r[1]),
            'description'  => $r[2],
            'price'        => $r[3],
            'brand'        => $r[4],
            'category'     => $r[5],
            'color'        => $r[6],
            'url'          => "https://example.test/product/{$r[0]}/",
            'visibility'   => match ($r[0]) {
                10      => ['outofstock'],
                11      => ['exclude-from-search'],
                12      => ['exclude-from-catalog'],
                default => [],
            },
            'stock_status' => $r[0] === 10 ? 'outofstock' : 'instock',
        ], $rows);
    }

    /** @return array<string, array<string, string>> */
    public static function labels(): array
    {
        return [
            'brand'    => ['stella' => 'Stella', 'rosa-clara' => 'Rosa Clará', 'pumpkin' => 'Pumpkin'],
            'category' => ['kleding' => 'Kleding', 'jurken' => 'Jurken', 'accessoires' => 'Accessoires', 'schoenen' => 'Schoenen'],
            'color'    => ['rood' => 'Rood', 'blauw' => 'Blauw', 'wit' => 'Wit', 'zwart' => 'Zwart', 'zilver' => 'Zilver', 'goud' => 'Goud'],
        ];
    }

    private static function build(string $path): void
    {
        Labels::write($path, self::labels());
        // Published the way IndexService builds: the path links to a version file.
        Index::rebuild($path, static fn(Index $new) => $new->insert(self::documents()), schema: new SchemaConfig(
            filterableAttributes: ['id', ...array_diff(self::FACETS, ['title_sort'])],
            sortableAttributes:   ['id', ...self::FACETS],
            searchableAttributes: ['title', 'description'],
        ))->close();
    }

    private static function remove(?string $dir): void
    {
        if ($dir === null || !is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}

/** The fixture index's builder: only what the registry and Locks::term() ask. */
final class FixtureBuilder implements IndexBuilderInterface
{
    public function schema(BuildContext $context): SchemaConfig
    {
        return new SchemaConfig(filterableAttributes: TestCase::FACETS, sortableAttributes: TestCase::FACETS, searchableAttributes: ['title', 'description']);
    }

    public function documents(BuildContext $context): iterable
    {
        return TestCase::documents();
    }

    public function documentsFor(BuildContext $context, int ...$ids): iterable
    {
        return array_values(array_filter(TestCase::documents(), static fn(array $doc): bool => in_array($doc['id'], $ids, true)));
    }

    public function labels(BuildContext $context): array
    {
        return TestCase::labels();
    }

    public function watches(): Watch
    {
        return new Watch();
    }

    public function synonyms(): array
    {
        return [];
    }

    public function chunkSize(): int
    {
        return 100;
    }

    public function fieldForTaxonomy(string $taxonomy): ?string
    {
        return ['product_cat' => 'category', 'pa_kleur' => 'color'][$taxonomy] ?? null;
    }

    public function config(): array
    {
        return [];
    }
}
