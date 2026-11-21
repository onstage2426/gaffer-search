<?php

declare(strict_types=1);

namespace GafferSearch\Builders;

use GafferSearch\BuildContext;
use GafferSearch\Support\Language\Languages;
use GafferSearch\Support\TaxonomyAncestors;

/**
 * Builder for WooCommerce product indexes.
 *
 * Bypasses WP_Query — one batched SQL query per chunk, JOINing meta and taxonomy tables
 * directly. Handles on_sale computation and thumbnail URL resolution automatically. URLs come
 * from get_permalink(), so every WooCommerce permalink setting (a custom base, %product_cat%,
 * translated slugs) gives the URL WordPress itself links to; each batch first loads its posts
 * and their terms into WordPress's cache (one query each), so that costs no query per product.
 *
 * Usage:
 *   add_action('gaffer_search_register_indexes', function (): void {
 *       gaffer_search_register_index(
 *           new IndexDefinition(name: 'products', label: 'Products', description: 'WooCommerce products.'),
 *           new ProductIndexBuilder(
 *               postTypes:     ['product'],
 *               postStatuses:  ['publish'],
 *               searchable:    ['title', 'description'],
 *               facets:        ['price', 'stock_status', 'on_sale'],
 *               meta:          ['_stock' => ['stock_quantity', 'int']],
 *               taxonomy:      ['product_brand'], // indexed as 'product_brand'; or 'product_brand' => 'brand' to rename
 *               sortable:      ['title'],
 *               chunkSize:     2500,
 *               thumbnailSize: 'medium_large',
 *           ),
 *       );
 *   });
 *
 * Documents: id, title, description, short_description (all plain text), url, thumbnail,
 * date, visibility, menu_order and total_sales (sort-only: WooCommerce's default and
 * "popularity" orders), the standard fields (STANDARD_FIELDS), plus one field per
 * meta/taxonomy mapping (taxonomy fields hold term slugs) and a {field}_sort key per sortable
 * field. See PostIndexBuilder for the rules they follow.
 *
 * The standard fields come from WC_Product, never from post meta (WooCommerce may move products
 * to tables of their own): `price`, `regular_price`, `sale_price` and `rating` (floats),
 * `stock_status` and `sku` (strings), each missing when the product has no value (no sale
 * price, no SKU, no reviews); `on_sale` ('yes'/'no', WC_Product::is_on_sale(): the "Sale!"
 * badge). A variable product's prices are one variation's, of those WooCommerce shows
 * (get_variation_prices()): its cheapest, or with `variationPrice: 'default'` the one its default
 * attributes select (the price its product page starts with), falling back to the cheapest when
 * there's none (no default attributes, or no shown variation matches them).
 *
 * `inStock: ['pa_maat' => 'size_in_stock']` adds, per attribute taxonomy, a facet field with the
 * values a customer can order: of a variable product, those of its published variations that are
 * in stock or on backorder (a variation set to "any" value counts every value the product has);
 * of any other product, its values when it is in stock or on backorder itself, else none. Its
 * labels are the attribute's names. Per attribute, not per combination: "size M" and "blue" can
 * come from different variations.
 *
 * `visibility` holds the product's WooCommerce `product_visibility` terms as they are
 * (exclude-from-search, exclude-from-catalog, outofstock, featured, rated-N). Hide products
 * with a locked exclusion in the token, e.g. the searchbar's
 * `'filter' => ['visibility' => new FacetExclude('exclude-from-search')]`.
 *
 * @internal Not part of the public API (the engine contract, docs/contract.md, is): may change in any version.
 */
class ProductIndexBuilder extends PostIndexBuilder
{
    /** Separator for GROUP_CONCAT: a control character, so a term name can't contain it (a comma can). */
    private const string SEPARATOR = "\x1f";

    protected const array DEFAULT_POST_TYPES = ['product'];

    /** The fields every product document has from WC_Product, with their types. */
    public const array STANDARD_FIELDS = [
        'price'         => 'float',
        'regular_price' => 'float',
        'sale_price'    => 'float',
        'stock_status'  => 'string',
        'sku'           => 'string',
        'rating'        => 'float',
    ];

    /** The post meta WooCommerce keeps the standard fields in today: watched, never read. */
    public const array STANDARD_META_KEYS = ['_price', '_regular_price', '_sale_price', '_stock_status', '_sku', '_wc_average_rating'];

    /**
     * @param list<string>                                  $postTypes
     * @param list<string>                                  $postStatuses
     * @param list<string>|null                             $searchable
     * @param list<string>                                  $facets
     * @param array<string, string|array{0: string, 1?: string}> $meta
     * @param array<int|string, string>                     $taxonomy
     * @param list<string>                                  $sortable
     * @param array{equivalences?: list<list<string>>, oneWay?: array<string, list<string>>} $synonyms
     * @param array<string, string>                         $inStock     attribute taxonomy → field with the values that can be ordered
     * @param list<string>                                  $directTerms
     * @param string                                        $variationPrice 'min' (a variable product's lowest prices) or 'default' (its default variation's).
     *
     * @throws \InvalidArgumentException For another $variationPrice.
     */
    public function __construct(
        array $postTypes = [],
        array $postStatuses = ['publish'],
        ?array $searchable = null,
        array $facets = [],
        array $meta = [],
        array $taxonomy = [],
        array $sortable = [],
        int $chunkSize = 1000,
        array $synonyms = [],
        protected readonly string $thumbnailSize = '',
        protected readonly array $inStock = [],
        array $directTerms = [],
        protected readonly string $variationPrice = 'min',
    ) {
        if (!in_array($variationPrice, ['min', 'default'], true)) {
            throw new \InvalidArgumentException("ProductIndexBuilder: variationPrice must be 'min' or 'default', got '{$variationPrice}'."); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        parent::__construct($postTypes, $postStatuses, $searchable, $facets, $meta, $taxonomy, $sortable, $chunkSize, $synonyms, $directTerms);
    }

    public function documents(BuildContext $context): iterable
    {
        return $this->products($context, []);
    }

    public function documentsFor(BuildContext $context, int ...$ids): iterable
    {
        foreach (array_chunk(array_values(array_unique($ids)), $this->chunkSize()) as $chunk) {
            yield from $this->products($context, $chunk);
        }
    }

    public function config(): array
    {
        return ['type' => 'product'] + parent::config() + [
            'thumbnailSize' => $this->thumbnailSize,
            'inStock'       => $this->inStock,
            'variationPrice' => $this->variationPrice,
            'standard'       => array_keys(self::STANDARD_FIELDS),
        ];
    }

    protected function extraFacetFields(): array
    {
        return ['visibility', 'on_sale', ...array_keys(self::STANDARD_FIELDS), ...array_values($this->inStock)];
    }

    protected function extraSortFields(): array
    {
        return ['menu_order', 'total_sales'];
    }

    protected function extraWatchedMetaKeys(): array
    {
        // Product saves arrive through clean_post_cache; these catch the writes that bypass it
        // (WCML's price and stock sync, a sale starting in WooCommerce's cron). A variation's
        // stock or attribute values change what its product can be ordered in, its prices the
        // product's.
        return [
            'total_sales',
            ...self::STANDARD_META_KEYS,
            ...array_map(fn(string $taxonomy): string => "attribute_{$taxonomy}", array_keys($this->inStock)),
            ...($this->variationPrice === 'default' ? ['_default_attributes'] : []),
        ];
    }

    public function labels(BuildContext $context): array
    {
        $labels = parent::labels($context);
        foreach ($this->inStock as $taxonomy => $field) {
            $labels[$field] = [];
            $terms          = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
            foreach (is_array($terms) ? $terms : [] as $term) {
                $labels[$field][$this->termSlug($term->slug)] = $this->termName($term->name);
            }
        }
        return $labels;
    }

    protected function parentPostTypes(): array
    {
        // A variation change (its price, its stock) changes what the parent product shows.
        return in_array('product', $this->postTypes, true) ? ['product_variation'] : [];
    }

    protected function extraWatchedTaxonomies(): array
    {
        return ['product_visibility', ...array_keys($this->inStock)];
    }

    /**
     * Every product of the index's types and statuses in the context's language, or only these
     * IDs, in batches of chunkSize() rows.
     *
     * @param  list<int> $ids [] = all.
     * @return \Generator<array<string, mixed>>
     */
    private function products(BuildContext $context, array $ids): \Generator
    {
        global $wpdb;

        [$selectParts, $joinParts] = $this->buildSqlParts($wpdb);
        [$langJoin, $langWhere]    = $context->language !== null
            ? Languages::adapter()->postSql('p', $context->language)
            : ['', '1=1'];

        $dynamicSelect = ', ' . implode(', ', $selectParts);
        $dynamicJoins  = implode("\n            ", $joinParts);

        $uploadBase = wp_get_upload_dir()['baseurl'];
        $batchSize  = $this->chunkSize();
        // Keyset paging: each batch starts after the last ID of the one before (an OFFSET reads
        // and skips every earlier row again, and a post deleted mid-build shifted it).
        $lastId     = 0;

        $inTypes    = implode("','", array_map(esc_sql(...), $this->postTypes));
        $inStatuses = implode("','", array_map(esc_sql(...), $this->postStatuses));
        $onlyIds    = $ids === [] ? '' : 'AND p.ID IN (' . implode(',', array_map(intval(...), $ids)) . ')';

        do {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT
                        p.ID, p.post_title, p.post_content, p.post_excerpt,
                        p.post_date, p.post_date_gmt, p.menu_order,
                        thumb_meta.meta_value AS thumb_id, thumb_file.meta_value AS thumb_path
                        {$dynamicSelect}
                     FROM {$wpdb->posts} p
                     LEFT JOIN {$wpdb->postmeta} thumb_meta
                        ON thumb_meta.post_id = p.ID AND thumb_meta.meta_key = '_thumbnail_id'
                     LEFT JOIN {$wpdb->postmeta} thumb_file
                        ON thumb_file.post_id = thumb_meta.meta_value AND thumb_file.meta_key = '_wp_attached_file'
                     {$dynamicJoins}
                     {$langJoin}
                     WHERE p.ID > %d AND p.post_type IN ('{$inTypes}') AND p.post_status IN ('{$inStatuses}') {$onlyIds} AND {$langWhere}
                     GROUP BY p.ID
                     ORDER BY p.ID
                     LIMIT %d",
                    $lastId,
                    $batchSize,
                ),
                ARRAY_A,
            );
            // phpcs:enable
            $rows = is_array($rows) ? $rows : [];

            // The posts, their terms and meta in WordPress's cache, so get_permalink() (including
            // WooCommerce's %product_cat% lookup) and wc_get_product() read from memory instead
            // of querying; the variations' too, for a variable product's prices.
            $batchIds = array_values(array_map(fn(array $row): int => (int) $row['ID'], $rows));
            _prime_post_caches($batchIds, true, true);
            $this->primeVariations($batchIds);

            $variations = $this->variationsOf($batchIds);
            foreach ($rows as $row) {
                yield $this->buildDoc($row, $uploadBase, $variations[ (int) $row['ID']] ?? null);
            }
            $this->releaseBatch();

            $lastId   = $batchIds === [] ? $lastId : $batchIds[count($batchIds) - 1];
            $rowCount = count($rows);
        } while ($rowCount === $batchSize);
    }

    /** @return array{list<string>, list<string>} SELECT and JOIN parts */
    private function buildSqlParts(\wpdb $wpdb): array
    {
        $select = [];
        $join   = [];
        $sep    = self::SEPARATOR;

        // A subquery per meta key, not a JOIN: a key with several rows (a variable product's
        // `_price`) would otherwise give whichever row MySQL picks for the GROUP BY.
        foreach ($this->metaMap() as $metaKey => ['field' => $field, 'type' => $type]) {
            // Prefixed like the taxonomy columns: a bare field name can be an SQL keyword
            // (`range`) or one of the post's own columns.
            $select[] = $this->metaSql($wpdb, $metaKey, $type !== 'string') . ' AS meta_' . $this->alias($field);
        }
        $select[] = $this->metaSql($wpdb, 'total_sales', true) . ' AS fz_total_sales';

        $terms = [...$this->taxonomyMap(), 'product_visibility' => 'visibility'];
        foreach ($this->inStock as $taxonomy => $field) {
            $terms[$taxonomy . "\0in_stock"] = $field;
        }
        foreach ($terms as $key => $field) {
            // An attribute can be both a taxonomy field and an inStock field: one subquery each.
            $taxonomy = explode("\0", (string) $key)[0];
            $a        = $this->alias($field);
            // Hierarchical taxonomies need the term ID (to walk ancestors in buildDoc() via
            // TaxonomyAncestors); everything else the slug.
            $column = $taxonomy !== 'product_visibility' && $this->withAncestors($taxonomy) ? 't.term_id' : 't.slug';
            $select[] = "(SELECT GROUP_CONCAT(DISTINCT {$column} ORDER BY t.name SEPARATOR '{$sep}')
                          FROM {$wpdb->term_relationships} tr
                          INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = '" . esc_sql($taxonomy) . "'
                          INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                          WHERE tr.object_id = p.ID) AS tax_{$a}";
        }

        return [$select, $join];
    }

    /**
     * A subquery for one meta value of the product `p`: the lowest number of its rows when
     * $numeric (rows that aren't a number are skipped), else its first row (get_post_meta(…, true)).
     */
    private function metaSql(\wpdb $wpdb, string $metaKey, bool $numeric): string
    {
        $key = esc_sql($metaKey);
        return $numeric
            ? "(SELECT MIN(pm.meta_value + 0) FROM {$wpdb->postmeta} pm WHERE pm.post_id = p.ID AND pm.meta_key = '{$key}' AND pm.meta_value REGEXP '^-?[0-9]*[.]?[0-9]+$')"
            : "(SELECT pm.meta_value FROM {$wpdb->postmeta} pm WHERE pm.post_id = p.ID AND pm.meta_key = '{$key}' ORDER BY pm.meta_id LIMIT 1)";
    }

    /**
     * @param array<string, mixed>                          $row
     * @param list<array{stock: string, attributes: array<string, string>}>|null $variations The product's published variations; null: it has none.
     * @return array<string, mixed>
     */
    private function buildDoc(array $row, string $uploadBase, ?array $variations): array
    {
        $doc = [
            'id'                => (int) $row['ID'],
            'title'             => $this->text( (string) $row['post_title']),
            'description'       => $this->text( (string) $row['post_content']),
            'short_description' => $this->text( (string) $row['post_excerpt']),
            'url'               => (string) get_permalink( (int) $row['ID']),
            'thumbnail'         => $this->resolveThumbnail($row, $uploadBase),
            'date'              => $this->timestamp( (string) $row['post_date_gmt'], (string) $row['post_date']),
            'visibility'        => $this->split($row['tax_visibility'] ?? null),
            'menu_order'        => (int) $row['menu_order'],
            'total_sales'       => (int) ($row['fz_total_sales'] ?? 0),
        ];

        $product = wc_get_product( (int) $row['ID']);
        $doc     = [...$doc, ...($product instanceof \WC_Product ? $this->standardFields($product) : [])];
        foreach ($this->metaMap() as ['field' => $field, 'type' => $type]) {
            $doc[$field] = $this->castMeta($row['meta_' . $this->alias($field)] ?? null, $type);
        }

        foreach ($this->taxonomyMap() as $taxonomy => $field) {
            $values = $this->split($row['tax_' . $this->alias($field)] ?? null);
            if ($this->withAncestors($taxonomy)) {
                $slugs = [];
                foreach ($values as $termId) {
                    $slugs = [...$slugs, ...TaxonomyAncestors::expand( (int) $termId, $taxonomy)];
                }
                $doc[$field] = array_values(array_unique($slugs));
            } else {
                $doc[$field] = array_map($this->termSlug(...), $values);
            }
        }

        foreach ($this->inStock as $taxonomy => $field) {
            $own         = array_map($this->termSlug(...), $this->split($row['tax_' . $this->alias($field)] ?? null));
            $doc[$field] = $this->orderableValues($taxonomy, $own, $product instanceof \WC_Product ? $product->get_stock_status() : '', $variations);
        }

        return $this->finalize($doc);
    }

    /**
     * The standard fields of one product (see the class docblock), missing ones left out.
     *
     * @return array<string, float|string>
     */
    private function standardFields(\WC_Product $product): array
    {
        $prices = $product;
        if ($product instanceof \WC_Product_Variable) {
            $shown = $product->get_variation_prices(false)['price'] ?? [];
            // Ordered by price: the first is the cheapest.
            $id     = ($this->variationPrice === 'default' ? $this->defaultVariation($product, array_map(intval(...), array_keys($shown))) : null)
                ?? array_key_first($shown);
            $prices = $id !== null ? wc_get_product( (int) $id) : null;
        }

        $number = static fn(mixed $value): ?float => is_numeric($value) ? (float) $value : null;
        $text   = static fn(mixed $value): ?string => is_scalar($value) && (string) $value !== '' ? (string) $value : null;
        $rating = $product->get_review_count() > 0 ? $number($product->get_average_rating()) : null;

        return array_filter([
            'price'         => $prices instanceof \WC_Product ? $number($prices->get_price()) : null,
            'regular_price' => $prices instanceof \WC_Product ? $number($prices->get_regular_price()) : null,
            'sale_price'    => $prices instanceof \WC_Product ? $number($prices->get_sale_price()) : null,
            'stock_status'  => $text($product->get_stock_status()),
            'sku'           => $text($product->get_sku()),
            'rating'        => $rating,
            'on_sale'       => $product->is_on_sale() ? 'yes' : 'no',
        ], static fn(mixed $value): bool => $value !== null);
    }

    /**
     * The variation a variable product's default attributes select, of the $shown ones: the first
     * in menu order (get_children()) that selects() them, as WooCommerce's
     * find_matching_product_variation() picks it. Null without default attributes or a match.
     *
     * @param list<int> $shown The variations get_variation_prices() lists.
     */
    private function defaultVariation(\WC_Product_Variable $product, array $shown): ?int
    {
        $defaults = $product->get_default_attributes();
        if ($defaults === []) {
            return null;
        }
        foreach ($product->get_children() as $id) {
            $variation = in_array( (int) $id, $shown, true) ? wc_get_product( (int) $id) : null;
            if ($variation instanceof \WC_Product_Variation && self::selects($variation->get_attributes(), $defaults)) {
                return (int) $id;
            }
        }
        return null;
    }

    /**
     * Whether a variation's attributes ('' = any value) are what the default attributes select:
     * every attribute has a default, and is that default or "any".
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $defaults
     */
    private static function selects(array $attributes, array $defaults): bool
    {
        if ($attributes === []) {
            return false;
        }
        foreach ($attributes as $name => $value) {
            $default = $defaults[$name] ?? '';
            if (!is_scalar($default) || (string) $default === '' || ( (string) $value !== '' && (string) $value !== (string) $default)) {
                return false;
            }
        }
        return true;
    }

    /**
     * The published variations of these products and their meta in WordPress's cache.
     *
     * @param list<int> $parentIds
     */
    private function primeVariations(array $parentIds): void
    {
        if ($parentIds === []) {
            return;
        }
        global $wpdb;
        $parents = implode(',', array_map(intval(...), $parentIds));
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- IDs are ints
        $ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product_variation' AND post_parent IN ({$parents})");
        // phpcs:enable
        if ($ids !== []) {
            _prime_post_caches(array_map(intval(...), $ids), false, true);
        }
    }

    /**
     * The values of one attribute a customer can order (see the class docblock).
     *
     * @param  list<string>                                                        $own        The product's own terms of the attribute (slugs).
     * @param  list<array{stock: string, attributes: array<string, string>}>|null $variations
     * @return list<string>
     */
    private function orderableValues(string $taxonomy, array $own, string $stockStatus, ?array $variations): array
    {
        if ($variations === null) {
            return self::orderable($stockStatus) ? $own : [];
        }
        $values = [];
        foreach ($variations as $variation) {
            if (!self::orderable($variation['stock'])) {
                continue;
            }
            $value = $this->termSlug($variation['attributes']["attribute_{$taxonomy}"] ?? '');
            // '' is "any value": every value the product has.
            foreach ($value === '' ? $own : (in_array($value, $own, true) ? [$value] : []) as $slug) {
                $values[$slug] = true;
            }
        }
        return array_keys($values);
    }

    private static function orderable(string $stockStatus): bool
    {
        return $stockStatus === 'instock' || $stockStatus === 'onbackorder';
    }

    /**
     * The published variations of these products, in menu order, with their stock status and
     * their attribute values for the inStock taxonomies — one query per batch.
     *
     * @param  list<int> $parentIds
     * @return array<int, list<array{stock: string, attributes: array<string, string>}>> product ID → its variations
     */
    private function variationsOf(array $parentIds): array
    {
        $metaKeys = array_map(fn(string $taxonomy): string => "attribute_{$taxonomy}", array_keys($this->inStock));
        if ($metaKeys === [] || $parentIds === []) {
            return [];
        }
        global $wpdb;
        $keys    = implode("','", array_map(esc_sql(...), array_unique($metaKeys)));
        $parents = implode(',', array_map(intval(...), $parentIds));
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- IDs are ints, keys escaped
        $rows = $wpdb->get_results(
            "SELECT v.ID, v.post_parent, st.meta_value AS stock, pm.meta_key, pm.meta_value
             FROM {$wpdb->posts} v
             LEFT JOIN {$wpdb->postmeta} st ON st.post_id = v.ID AND st.meta_key = '_stock_status'
             LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = v.ID AND pm.meta_key IN ('{$keys}')
             WHERE v.post_type = 'product_variation' AND v.post_status = 'publish'
               AND v.post_parent IN ({$parents})
             ORDER BY v.menu_order, v.ID, pm.meta_id",
            ARRAY_A,
        );
        // phpcs:enable

        $byVariation = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $id                              = (int) $row['ID'];
            $byVariation[$id]['parent']      = (int) $row['post_parent'];
            $byVariation[$id]['stock']       = (string) $row['stock'];
            $byVariation[$id]['attributes']??= [];
            $key = (string) $row['meta_key'];
            if (str_starts_with($key, 'attribute_')) {
                $byVariation[$id]['attributes'][$key] = (string) $row['meta_value'];
            }
        }

        $byParent = [];
        foreach ($byVariation as $variation) {
            $byParent[$variation['parent']][] = [
                'stock'      => $variation['stock'],
                'attributes' => $variation['attributes'],
            ];
        }
        return $byParent;
    }

    /** @return list<string> */
    private function split(mixed $concatenated): array
    {
        return is_string($concatenated) && $concatenated !== '' ? explode(self::SEPARATOR, $concatenated) : [];
    }

    /** @param array<string, mixed> $row */
    private function resolveThumbnail(array $row, string $uploadBase): string
    {
        if (empty($row['thumb_path'])) {
            return '';
        }

        if ($this->thumbnailSize !== '' && !empty($row['thumb_id'])) {
            $sized = wp_get_attachment_image_url( (int) $row['thumb_id'], $this->thumbnailSize);
            if ($sized !== false) {
                return $sized;
            }
        }

        return "{$uploadBase}/{$row['thumb_path']}";
    }

    private function alias(string $name): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_]/', '', $name);
    }
}
