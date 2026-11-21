<?php

declare(strict_types=1);

namespace GafferSearch\Builders;

use Fuzor\HtmlText;
use Fuzor\SchemaConfig;
use Fuzor\Tokenizer;
use GafferSearch\BuildContext;
use GafferSearch\Watch;

/**
 * Shared base of ContentIndexBuilder and ProductIndexBuilder: the constructor options both
 * take, the schema, change watching, and the rules every document follows.
 *
 *  - Text fields (title, content, excerpt, descriptions) are stored as plain text: shortcodes
 *    removed, then HTML converted to its visible text with Fuzor\HtmlText::toText() — once,
 *    from the raw WordPress value. Never run a stored value through it again: it decodes
 *    entities, so a second pass strips text such as a literal "<b>".
 *  - Taxonomy fields hold term slugs, URL-decoded ("rosa-clara"): unique per taxonomy and
 *    stable when a term is renamed. labels() maps them to the decoded names ("Fit & Flare",
 *    not "Fit &amp; Flare"), which responses carry as `labels`.
 *  - Meta values are typed: `meta: ['_price' => ['price', 'float'], '_sku' => 'sku']` — a bare
 *    field name is text; 'int' and 'float' are stored as numbers, which numeric sorting, range
 *    filters, and facetStats need.
 *  - Facet fields (`facets`, taxonomy fields, `id`, `date`, and the product
 *    builder's own) can be filtered on and sorted by. `sortable: ['title']` adds a `title_sort`
 *    key (Tokenizer::sortKey(): lowercase, accents folded) that can only be sorted by, for a
 *    human A–Z sort: sort[]=title_sort:asc.
 *  - `id` and `date` (the post date as a Unix timestamp) are always facet fields, so sort[]=
 *    date:desc gives "newest first" on search results too, and `id` works in filters and
 *    exclusions ("related products, but not this one").
 *  - Taxonomy fields are always facet fields. A hierarchical taxonomy also stores every
 *    assigned term's ancestors' slugs (TaxonomyAncestors), so a parent term's filter matches posts
 *    assigned only to a child term — unless it's listed in `directTerms`: then only the
 *    assigned terms, for sites whose term pages show only the posts assigned to that term.
 *
 * @internal Not part of the public API (the engine contract, docs/contract.md, is): may change in any version.
 */
abstract class PostIndexBuilder implements IndexBuilderInterface
{
    private const array META_TYPES = ['string', 'int', 'float'];

    /** Post types indexed when the constructor gets none. */
    protected const array DEFAULT_POST_TYPES = ['post'];

    /** @var list<string> */
    protected readonly array $postTypes;

    /** @var array<string, array{field: string, type: string}> meta key → field + type */
    private readonly array $metaMap;

    /** @var array<string, string> taxonomy slug → indexed field */
    private readonly array $taxonomyMap;

    /**
     * @param list<string>                                  $postTypes    [] = the builder's default (post / product).
     * @param list<string>                                  $postStatuses
     * @param list<string>|null                             $searchable   null = every field that isn't a facet field
     * @param list<string>                                  $facets       Extra facet fields (taxonomy fields, id and date are added automatically).
     * @param array<string, string|array{0: string, 1?: string}> $meta   meta key → field, or → [field, 'string'|'int'|'float'].
     * @param array<int|string, string>                     $taxonomy     ['category'] indexes under the slug; ['post_tag' => 'tags'] renames.
     * @param list<string>                                  $sortable     Text fields that get a `{field}_sort` sort key.
     * @param array{equivalences?: list<list<string>>, oneWay?: array<string, list<string>>} $synonyms
     * @param list<string>                                  $directTerms  Hierarchical taxonomies stored with only the assigned terms, without their ancestors.
     */
    public function __construct(
        array $postTypes = [],
        protected readonly array $postStatuses = ['publish'],
        protected readonly ?array $searchable = null,
        protected readonly array $facets = [],
        array $meta = [],
        array $taxonomy = [],
        protected readonly array $sortable = [],
        protected readonly int $chunkSize = 1000,
        protected readonly array $synonyms = [],
        protected readonly array $directTerms = [],
    ) {
        $this->postTypes   = $postTypes !== [] ? $postTypes : array_values(static::DEFAULT_POST_TYPES);
        $this->metaMap     = self::normalizeMeta($meta);
        $this->taxonomyMap = self::normalizeTaxonomy($taxonomy);
    }

    public function schema(BuildContext $context): SchemaConfig
    {
        // Filterable: what filter[], facets and facet search use. The {field}_sort keys are
        // only sorted on (one value per document; counting them is wasted work).
        $filterable = array_values(array_unique([
            'id',
            'date',
            ...$this->facets,
            ...array_values($this->taxonomyMap),
            ...$this->extraFacetFields(),
        ]));
        // Sortable: every facet field too (price, date, a brand A–Z), plus the sort keys.
        $sortable = array_values(array_unique([
            ...$filterable,
            ...array_map(fn(string $field): string => "{$field}_sort", $this->sortable),
            ...$this->extraSortFields(),
        ]));

        return new SchemaConfig(
            store:                true,
            filterableAttributes: $filterable,
            sortableAttributes:   $sortable,
            searchableAttributes: $this->searchable,
        );
    }

    public function labels(BuildContext $context): array
    {
        $labels = [];
        foreach ($this->taxonomyMap as $taxonomy => $field) {
            // Every field, even one without terms yet: Labels::lacksNames() checks the fields listed.
            $labels[$field] ??= [];
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
            foreach (is_array($terms) ? $terms : [] as $term) {
                $labels[$field][$this->termSlug($term->slug)] = $this->termName($term->name);
            }
        }
        return $labels;
    }

    public function watches(): Watch
    {
        return new Watch(
            postTypes:       $this->postTypes,
            parentPostTypes: $this->parentPostTypes(),
            metaKeys:        array_values(array_unique([...array_keys($this->metaMap), '_thumbnail_id', ...$this->extraWatchedMetaKeys()])),
            taxonomies:      array_values(array_unique([...array_keys($this->taxonomyMap), ...$this->extraWatchedTaxonomies()])),
        );
    }

    public function synonyms(): array
    {
        return $this->synonyms;
    }

    /** @return int<1, max> */
    public function chunkSize(): int
    {
        return max(1, $this->chunkSize);
    }

    public function fieldForTaxonomy(string $taxonomy): ?string
    {
        return $this->taxonomyMap[$taxonomy] ?? null;
    }

    public function config(): array
    {
        return [
            'postTypes'    => $this->postTypes,
            'postStatuses' => $this->postStatuses,
            'searchable'   => $this->searchable,
            'facets'       => $this->facets,
            'meta'         => $this->metaMap,
            'taxonomy'     => $this->taxonomyMap,
            'sortable'     => $this->sortable,
            'chunkSize'    => $this->chunkSize,
            'synonyms'     => $this->synonyms,
            'directTerms'  => $this->directTerms,
        ];
    }

    /** @return list<string> Facet fields a subclass always adds (e.g. product visibility). */
    protected function extraFacetFields(): array
    {
        return [];
    }

    /** @return list<string> Fields a subclass always adds that can only be sorted by (e.g. menu_order). */
    protected function extraSortFields(): array
    {
        return [];
    }

    /** Whether $taxonomy's field also stores the ancestors of the assigned terms. */
    protected function withAncestors(string $taxonomy): bool
    {
        return is_taxonomy_hierarchical($taxonomy) && !in_array($taxonomy, $this->directTerms, true);
    }

    /** @return list<string> Post types whose changes update their parent's document (Watch::$parentPostTypes). */
    protected function parentPostTypes(): array
    {
        return [];
    }

    /** @return list<string> */
    protected function extraWatchedMetaKeys(): array
    {
        return [];
    }

    /** @return list<string> */
    protected function extraWatchedTaxonomies(): array
    {
        return [];
    }

    /** @return array<string, array{field: string, type: string}> */
    protected function metaMap(): array
    {
        return $this->metaMap;
    }

    /** @return array<string, string> */
    protected function taxonomyMap(): array
    {
        return $this->taxonomyMap;
    }

    /**
     * Call after each batch: the batch's posts, meta and terms were loaded into WordPress's
     * in-memory object cache and nothing evicts them, so a build of 100k products would keep
     * them all (~5 KB each). Flushes the runtime cache only (a persistent cache keeps its data);
     * anything still needed is read again.
     */
    protected function releaseBatch(): void
    {
        if (function_exists('wp_cache_supports') && wp_cache_supports('flush_runtime')) {
            wp_cache_flush_runtime();
        }
    }

    /** Raw WordPress content → plain visible text. Call once, on the raw database value. */
    protected function text(?string $html): string
    {
        return $html === null || $html === '' ? '' : HtmlText::toText(strip_shortcodes($html));
    }

    /** WordPress stores term names with & encoded as &amp;; labels hold the plain name. */
    protected function termName(string $name): string
    {
        return html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** WordPress stores non-ASCII slugs URL-encoded ("caf%c3%a9"); index them readable ("café"). */
    protected function termSlug(string $slug): string
    {
        return rawurldecode($slug);
    }

    /** @return string|list<string>|int|float|null */
    protected function castMeta(mixed $value, string $type): string|array|int|float|null
    {
        if ($type === 'int' || $type === 'float') {
            if (!is_numeric($value)) {
                return null;
            }
            return $type === 'int' ? (int) $value : (float) $value;
        }
        if (is_array($value)) {
            return array_values(array_map(fn(mixed $v): string => is_scalar($v) ? (string) $v : '', $value));
        }
        return is_scalar($value) ? (string) $value : '';
    }

    /** The post date as a Unix timestamp, from post_date_gmt (unset for drafts) or post_date in the site timezone. */
    protected function timestamp(string $gmt, string $local): ?int
    {
        try {
            if ($gmt !== '' && !str_starts_with($gmt, '0000-00-00')) {
                return (new \DateTimeImmutable($gmt, new \DateTimeZone('UTC')))->getTimestamp();
            }
            if ($local !== '' && !str_starts_with($local, '0000-00-00')) {
                return (new \DateTimeImmutable($local, wp_timezone()))->getTimestamp();
            }
        } catch (\Exception) {
            // Unparseable date: leave the field out rather than index a wrong one.
        }
        return null;
    }

    /**
     * Last step for every document: add the `{field}_sort` keys.
     *
     * @param  array<string, mixed> $doc
     * @return array<string, mixed>
     */
    protected function finalize(array $doc): array
    {
        foreach ($this->sortable as $field) {
            if (isset($doc[$field]) && is_string($doc[$field])) {
                $doc["{$field}_sort"] = Tokenizer::sortKey($doc[$field]);
            }
        }
        return $doc;
    }

    /**
     * @param  array<string, string|array{0: string, 1?: string}> $meta
     * @return array<string, array{field: string, type: string}>
     */
    private static function normalizeMeta(array $meta): array
    {
        $map = [];
        foreach ($meta as $key => $spec) {
            [$field, $type] = is_array($spec) ? [$spec[0], $spec[1] ?? 'string'] : [$spec, 'string'];
            if ($field === '') {
                continue;
            }
            if (!in_array($type, self::META_TYPES, true)) {
                _doing_it_wrong(
                    static::class . '::__construct',
                    sprintf("Meta field '%s' has unknown type '%s'; use 'string', 'int', or 'float'. Indexed as 'string'.", esc_html($field), esc_html($type)),
                    '0.2',
                );
                $type = 'string';
            }
            $map[ (string) $key] = ['field' => $field, 'type' => $type];
        }
        return $map;
    }

    /**
     * @param  array<int|string, string> $taxonomy
     * @return array<string, string>
     */
    private static function normalizeTaxonomy(array $taxonomy): array
    {
        $map = [];
        foreach ($taxonomy as $key => $value) {
            $map[is_int($key) ? $value : $key] = $value;
        }
        return $map;
    }
}
