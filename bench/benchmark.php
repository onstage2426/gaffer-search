<?php

declare(strict_types=1);

/**
 * Fuzor at big-site scale: builds a generated catalog the way IndexService does
 * (Index::rebuild(), chunks of 2,500), then times typical archive and search requests through
 * SearchRunner — the code the endpoint and gaffer_search() run — plus document updates.
 *
 *   php bench/benchmark.php [products=100000] [--runs=15] [--json=bench/results/x.json]
 *
 * The catalog is deterministic (seeded), shaped like ProductIndexBuilder's documents:
 * plain-text title/description, price float, brand, hierarchical category (with ancestors),
 * colors, sizes, visibility, stock status, title_sort. No WordPress needed.
 */

use Fuzor\FacetExclude;
use Fuzor\FacetRange;
use Fuzor\FacetSearchQuery;
use Fuzor\Index;
use Fuzor\SchemaConfig;
use GafferSearch\Support\SearchRunner;

require dirname(__DIR__) . '/vendor/autoload.php';

$args     = array_slice($argv, 1);
$option   = static fn(string $name): ?string => array_values(preg_filter("/^--{$name}=/", '', $args))[0] ?? null;
$products = (int) (array_values(array_filter($args, is_numeric(...)))[0] ?? 100_000);
$runs     = max(1, (int) ($option('runs') ?? 15));
$jsonOut  = $option('json');

$dir  = sys_get_temp_dir() . '/gaffer-search-bench-' . getmypid();
$path = "{$dir}/products.db";
mkdir($dir);
register_shutdown_function(static function () use ($dir): void {
    array_map(unlink(...), glob("{$dir}/*") ?: []);
    rmdir($dir);
});

$report = ['products' => $products, 'php' => PHP_VERSION, 'date' => date('c'), 'runs' => $runs];

// Build ---------------------------------------------------------------------------------------
$schema = new SchemaConfig(
    filterableAttributes: ['id', 'date', 'price', 'brand', 'category', 'color', 'size', 'visibility', 'stock_status', 'on_sale'],
    sortableAttributes:   ['id', 'date', 'price', 'brand', 'category', 'color', 'size', 'visibility', 'stock_status', 'on_sale', 'title_sort'],
    searchableAttributes: ['title', 'description', 'sku'],
);

$start = microtime(true);
$index = Index::rebuild($path, static function (Index $new) use ($products): void {
    $buffer = [];
    foreach (catalog($products) as $doc) {
        $buffer[] = $doc;
        if (count($buffer) >= 2500) {
            $new->insert($buffer);
            $buffer = [];
        }
    }
    if ($buffer !== []) {
        $new->insert($buffer);
    }
}, schema: $schema);
$index->close();
$report['build'] = [
    'seconds'        => round(microtime(true) - $start, 2),
    'file_mb'        => round(filesize($path) / 1_048_576, 1),
    'peak_memory_mb' => round(memory_get_peak_usage(true) / 1_048_576, 1),
];
printf("Built %s products in %.1f s, %.1f MB on disk, peak memory %.1f MB\n\n", number_format($products), $report['build']['seconds'], $report['build']['file_mb'], $report['build']['peak_memory_mb']);

// Requests ------------------------------------------------------------------------------------
$archiveFacets = ['brand', 'category', 'color', 'size', 'price'];
$catalogHidden = ['visibility' => new FacetExclude(['exclude-from-catalog', 'outofstock'])];
$searchHidden  = ['visibility' => new FacetExclude('exclude-from-search')];

$requests = [
    'archive: page 1, no facets'              => ['', ['limit' => 24, 'filter' => $catalogHidden]],
    'archive: page 1, 5 facets'               => ['', ['limit' => 24, 'facets' => $archiveFacets, 'filter' => $catalogHidden]],
    'archive: category, 5 facets'             => ['', ['limit' => 24, 'facets' => $archiveFacets, 'filter' => $catalogHidden + ['category' => 'Jurken']]],
    'archive: category + 2 filters, 5 facets' => ['', ['limit' => 24, 'facets' => $archiveFacets, 'filter' => $catalogHidden + ['category' => 'Jurken', 'color' => ['Rood', 'Wit'], 'price' => FacetRange::between(50, 300)]]],
    'archive: sorted by price'                => ['', ['limit' => 24, 'facets' => $archiveFacets, 'filter' => $catalogHidden], 0, ['price:asc']],
    'archive: sorted by name'                 => ['', ['limit' => 24, 'filter' => $catalogHidden], 0, ['title_sort:asc']],
    'archive: deep page (offset 9,000)'       => ['', ['limit' => 24, 'facets' => $archiveFacets, 'filter' => $catalogHidden], 9000],
    'search: "jurk", 3 facets'                => ['jurk', ['limit' => 12, 'facets' => ['brand', 'category', 'price'], 'filter' => $searchHidden]],
    'search: as you type "ju"'                => ['ju', ['limit' => 12, 'filter' => $searchHidden]],
    'search: "rode lange jurk"'               => ['rode lange jurk', ['limit' => 12, 'facets' => ['brand'], 'filter' => $searchHidden]],
    'search: typo "jruk"'                     => ['jruk', ['limit' => 12, 'filter' => $searchHidden]],
    'search: rare word'                       => ['parelmoer', ['limit' => 12, 'filter' => $searchHidden]],
    'search: SKU'                             => ['FZ-04217', ['limit' => 12]],
];

$results = [];
printf("%-42s %9s %9s %9s %6s  %s\n", 'request', 'median', 'p95', 'total', 'exact', 'top hit');
foreach ($requests as $label => $request) {
    [$query, $options, $offset, $sort] = $request + [2 => 0, 3 => []];
    $options = SearchRunner::options($options);
    $times   = [];
    $result  = [];
    for ($i = 0; $i < $runs; $i++) {
        $t      = hrtime(true);
        $result = SearchRunner::run($path, $query, $options, $offset, $sort);
        $times[] = (hrtime(true) - $t) / 1e6;
    }
    $top             = $result['results'][0] ?? null;
    $results[$label] = ['median_ms' => percentile($times, 50), 'p95_ms' => percentile($times, 95), 'total' => $result['total'], 'exhaustive' => $result['exhaustive'], 'top_hit' => $top === null ? null : "{$top['sku']} {$top['title']}"];
    printf("%-42s %7.1fms %7.1fms %9s %6s  %s\n", $label, $results[$label]['median_ms'], $results[$label]['p95_ms'], number_format($result['total']), $result['exhaustive'] ? 'yes' : 'no', mb_strimwidth((string) $results[$label]['top_hit'], 0, 40, '…'));
}

$facetTimes = [];
for ($i = 0; $i < $runs; $i++) {
    $t = hrtime(true);
    (new Index($path, readonly: true))->facetSearch(new FacetSearchQuery(facetName: 'brand', facetQuery: 'st', filter: $catalogHidden, limit: 20));
    $facetTimes[] = (hrtime(true) - $t) / 1e6;
}
$results['facet search: brand "st"'] = ['median_ms' => percentile($facetTimes, 50), 'p95_ms' => percentile($facetTimes, 95)];
printf("%-42s %7.1fms %7.1fms\n", 'facet search: brand "st"', $results['facet search: brand "st"']['median_ms'], $results['facet search: brand "st"']['p95_ms']);
$report['requests'] = $results;

// Updates (the per-product path of phase F) ---------------------------------------------------
echo "\n";
$report['updates'] = [];
foreach ([1, 100, 1000] as $n) {
    $docs = iterator_to_array(catalog($n, seed: 2), false);
    foreach ($docs as $i => &$doc) {
        $doc['id'] = 1 + ($i * 37) % $products; // existing products, spread over the catalog
    }
    unset($doc);
    $index = new Index($path);
    $t     = hrtime(true);
    $index->upsert($docs);
    $ms = (hrtime(true) - $t) / 1e6;
    $index->close();
    $report['updates']["upsert {$n}"] = round($ms, 1);
    printf("%-42s %7.1fms\n", "upsert {$n} existing product(s)", $ms);
}

if ($jsonOut !== null) {
    @mkdir(dirname($jsonOut), 0777, true);
    file_put_contents($jsonOut, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    echo "\nWrote {$jsonOut}\n";
}

// ---------------------------------------------------------------------------------------------

/** @param list<float> $values */
function percentile(array $values, int $p): float
{
    sort($values);
    return round($values[(int) min(count($values) - 1, ceil($p / 100 * count($values)) - 1)], 2);
}

/** @return Generator<array<string, mixed>> */
function catalog(int $count, int $seed = 1): Generator
{
    mt_srand($seed);
    $pick = static fn(array $list): mixed => $list[mt_rand(0, count($list) - 1)];

    $nouns      = ['jurk', 'trouwjurk', 'sluier', 'tiara', 'schoen', 'pump', 'sneaker', 'clutch', 'bolero', 'jas', 'blazer', 'rok', 'blouse', 'top', 'broek', 'jumpsuit', 'colbert', 'stropdas', 'riem', 'ketting', 'oorbel', 'armband', 'handschoen', 'sjaal', 'hoed'];
    $adjectives = ['rode', 'witte', 'zwarte', 'lange', 'korte', 'klassieke', 'moderne', 'elegante', 'vintage', 'luxe', 'zachte', 'glanzende', 'kanten', 'zijden', 'satijnen', 'romantische', 'strakke', 'wijde', 'getailleerde', 'boheemse'];
    $words      = ['met', 'van', 'en', 'voor', 'een', 'de', 'het', 'mooie', 'stof', 'kant', 'tule', 'zijde', 'satijn', 'parels', 'kristallen', 'borduursel', 'knopen', 'rits', 'mouwen', 'hals', 'rug', 'sleep', 'taille', 'pasvorm', 'comfortabel', 'feest', 'bruiloft', 'gala', 'avond', 'zomer', 'winter', 'handgemaakt', 'Italiaans', 'duurzaam', 'katoen', 'linnen', 'wol', 'leer', 'suède', 'fluweel'];
    $colors     = ['Rood', 'Wit', 'Ivoor', 'Zwart', 'Blauw', 'Groen', 'Roze', 'Goud', 'Zilver', 'Beige', 'Champagne', 'Blush', 'Grijs', 'Paars', 'Geel', 'Oranje', 'Bruin', 'Navy', 'Mint', 'Bordeaux'];
    $sizes      = ['32', '34', '36', '38', '40', '42', '44', '46', '48', '50', 'XS', 'S', 'M', 'L', 'XL'];
    $brands     = array_map(static fn(int $i): string => 'Merk ' . chr(65 + $i % 26) . intdiv($i, 26), range(0, 299));
    $tree       = ['Kleding' => ['Jurken', 'Rokken', 'Tops', 'Jassen', 'Broeken'], 'Schoenen' => ['Pumps', 'Sneakers', 'Laarzen'], 'Accessoires' => ['Sieraden', 'Tassen', 'Haaraccessoires', 'Sluiers'], 'Heren' => ['Pakken', 'Overhemden', 'Stropdassen']];

    for ($id = 1; $id <= $count; $id++) {
        $parent   = $pick(array_keys($tree));
        $child    = $pick($tree[$parent]);
        $title    = ucfirst($pick($adjectives)) . ' ' . $pick($nouns) . ' ' . $pick($words) . ' ' . $pick($words);
        $sentence = static fn(): string => implode(' ', array_map(static fn() => $pick(mt_rand(0, 3) === 0 ? $nouns : $words), range(1, mt_rand(8, 16)))) . '.';
        $price    = round(mt_rand(995, 49995) / 100, 2);
        $hidden   = mt_rand(1, 100);

        yield [
            'id'           => $id,
            'title'        => $title,
            'title_sort'   => mb_strtolower($title),
            'description'  => implode(' ', array_map(static fn() => $sentence(), range(1, mt_rand(3, 8)))) . ($id % 997 === 0 ? ' Knopen van parelmoer.' : ''),
            'sku'          => sprintf('FZ-%05d', $id),
            'url'          => "https://example.test/product/{$id}/",
            'date'         => 1_600_000_000 + $id * 600,
            'price'        => $price,
            'on_sale'      => mt_rand(1, 10) === 1 ? 'yes' : 'no',
            'brand'        => $pick($brands),
            'category'     => [$parent, $child],
            'color'        => array_values(array_unique([$pick($colors), $pick($colors)])),
            'size'         => array_values(array_unique(array_map(static fn() => $pick($sizes), range(1, mt_rand(1, 6))))),
            'visibility'   => match (true) {
                $hidden <= 2 => ['exclude-from-catalog'],
                $hidden <= 4 => ['exclude-from-search'],
                $hidden <= 9 => ['outofstock'],
                default      => [],
            },
            'stock_status' => $hidden > 4 && $hidden <= 9 ? 'outofstock' : 'instock',
        ];
    }
}
