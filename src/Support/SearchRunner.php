<?php

declare(strict_types=1);

namespace GafferSearch\Support;

use Fuzor\Config;
use Fuzor\FacetOrder;
use Fuzor\Index;
use Fuzor\Limits;
use Fuzor\MatchingStrategy;
use Fuzor\SearchOptions;
use Fuzor\TypoTolerance;

/**
 * The one search path shared by endpoints/search.php (HTTP, no WordPress boot) and
 * gaffer_search() (PHP). Both hand it the same two kinds of input and get the same response
 * back, so the two can never drift in limits, hit shape, or which fields are HTML:
 *
 *  - options — operator-controlled settings: a signed token's payload for the endpoint,
 *    the caller's $params for gaffer_search(). Normalized by options().
 *  - request — the free params: query, offset, sort, filter.
 *
 * Response contract: every stored field in a hit is plain text (the builders store text, not
 * HTML). Highlighted output lives only in `formatted` — `title` and `snippet`, both safe HTML
 * (Fuzor's escapeFormatted), meant for x-html / |raw.
 *
 * No WordPress functions here — the HTTP endpoints load this without booting WordPress.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class SearchRunner
{
    public const int MAX_LIMIT  = 100;
    public const int MAX_OFFSET = 10_000;
    public const int MAX_DOCS   = 5_000;

    /** Matches facets are counted over exactly (`facet_count_docs`): the default and the most, and the least. */
    public const int FACET_COUNT_DOCS     = 50_000;
    public const int MIN_FACET_COUNT_DOCS = 1_000;

    /** Facet values per field: the default, and the most `facet_values` may ask for. */
    public const int FACET_VALUES     = 100;
    public const int MAX_FACET_VALUES = 1_000;

    /** Field weights when the options name none: a match in the title outranks one in the description. */
    public const array DEFAULT_BOOSTS = ['title' => 3.0];

    /** Stored fields left out of hits unless `attributes` asks for them: long text the snippet already covers. */
    public const array LONG_FIELDS = ['content', 'description', 'excerpt', 'short_description'];

    /**
     * Normalize operator-controlled options, clamping everything to the same bounds for both
     * paths. Unknown keys are ignored.
     *
     * @param  array<string, mixed> $raw
     * @return array{
     *     limit: int, as_you_type: bool, facets: list<string>, facet_stats: list<string>, max_docs: int, facet_count_docs: int, boosts: array<string, float>,
     *     matching_strategy: 'last'|'all'|'frequency', typos_on_numbers: bool,
     *     highlight: list<string>, highlight_pre_tag: string, highlight_post_tag: string,
     *     crop: list<string>, crop_length: int, crop_marker: string,
     *     filter: array<string, mixed>, attributes: list<string>|null, default_sort: list<string>,
     *     limits: list<int>,
     *     facet_values: array<string, int>
     * }
     */
    public static function options(array $raw): array
    {
        $limit = self::clampInt($raw['limit'] ?? null, 1, self::MAX_LIMIT, 10);
        return [
            'limit'              => $limit,
            'as_you_type'        => filter_var($raw['as_you_type'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'facets'             => self::fields($raw['facets'] ?? []),
            'facet_stats'        => self::fields($raw['facet_stats'] ?? []),
            'max_docs'           => self::clampInt($raw['max_docs'] ?? null, 1, self::MAX_DOCS, 500),
            'facet_count_docs'   => self::clampInt($raw['facet_count_docs'] ?? null, self::MIN_FACET_COUNT_DOCS, self::FACET_COUNT_DOCS, self::FACET_COUNT_DOCS),
            'boosts'             => is_array($raw['boosts'] ?? null) ? self::boosts($raw['boosts']) : self::DEFAULT_BOOSTS,
            'matching_strategy'  => MatchingStrategy::tryFrom(self::string($raw['matching_strategy'] ?? ''))->value ?? MatchingStrategy::Last->value,
            'typos_on_numbers'   => filter_var($raw['typos_on_numbers'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'highlight'          => self::fields($raw['highlight'] ?? ['title', 'content', 'description']),
            'highlight_pre_tag'  => self::string($raw['highlight_pre_tag'] ?? '<mark>'),
            'highlight_post_tag' => self::string($raw['highlight_post_tag'] ?? '</mark>'),
            'crop'               => self::fields($raw['crop'] ?? ['content', 'description']),
            'crop_length'        => self::clampInt($raw['crop_length'] ?? null, 50, 1000, 200),
            'crop_marker'        => self::string($raw['crop_marker'] ?? '…'),
            'filter'             => FilterQuery::normalize(is_array($raw['filter'] ?? null) ? $raw['filter'] : []),
            'attributes'         => isset($raw['attributes']) && is_array($raw['attributes']) ? self::fields($raw['attributes']) : null,
            'default_sort'       => SortQuery::parse(is_array($raw['default_sort'] ?? null) ? $raw['default_sort'] : []),
            'limits'             => self::limits($raw['limits'] ?? null, $limit),
            'facet_values'       => self::facetValues($raw['facet_values'] ?? null),
        ];
    }

    /** Words of a search text that are searched; the rest are left out (with a warning). */
    public const int MAX_WORDS = 32;

    /**
     * The text searched for $query: valid UTF-8 (else nothing), each word once, at most
     * MAX_WORDS words. A search's cost grows with the square of its words, repeats the most
     * (one word 400 times took minutes on 30k products): a public endpoint can't take that.
     * The response still echoes the query as sent.
     *
     * @param list<string> $warnings
     */
    public static function searchText(string $query, array &$warnings = []): string
    {
        if (!mb_check_encoding($query, 'UTF-8')) {
            $warnings[] = 'Query: not valid UTF-8, searched as empty.';
            return '';
        }
        $words  = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $unique = array_values(array_unique($words));
        if (count($unique) > self::MAX_WORDS) {
            $warnings[] = 'Query: only its first ' . self::MAX_WORDS . ' different words are searched.';
            $unique     = array_slice($unique, 0, self::MAX_WORDS);
        }

        return count($unique) === count($words) ? $query : implode(' ', $unique);
    }

    /**
     * The options with the request's own `limit` applied when the options' `limits` offer it;
     * any other limit, or none, keeps the options' own.
     *
     * @param  array<string, mixed> $options Output of options().
     * @return array<string, mixed>
     */
    public static function choose(array $options, mixed $limit): array
    {
        $limits = is_array($options['limits'] ?? null) ? $options['limits'] : [];
        if (is_numeric($limit) && in_array( (int) $limit, $limits, true)) {
            $options['limit'] = (int) $limit;
        }
        return $options;
    }

    /**
     * Run a search against the index file at $path.
     *
     * $filter is the request's own filter; a field locked in $options['filter'] replaces the
     * request's value for that field, so a visitor can narrow other fields but never widen a
     * locked one.
     *
     * Without a query and a sort, the options' `default_sort` applies (a shop's own order when
     * browsing, relevance once the visitor types).
     *
     * The request's sort specs and filter fields, and the options' facets, that the index
     * doesn't declare (sortable / filterable) are left out with a line in `warnings`, the way
     * other invalid input is ignored. A locked filter is never left out: dropping it would widen
     * the results, so an undeclared one fails the search.
     *
     * @param  array<string, mixed>  $options Output of options().
     * @param  list<string>          $sort    Already-parsed sort specs (SortQuery::parse()).
     * @param  array<string, mixed>  $filter  Already-parsed filter (FilterQuery::parse()/normalize()).
     * @return array<string, mixed>           See empty() for the shape.
     */
    public static function run(string $path, string $query, array $options, int $offset = 0, array $sort = [], array $filter = []): array
    {
        $offset = self::clampInt($offset, 0, self::MAX_OFFSET, 0);

        $index = new Index(
            $path,
            config: new Config(
                limits:            new Limits(
                    candidatesPerWord: $options['max_docs'],
                    facetCountDocs:    $options['facet_count_docs'],
                    // Every page the clamped offset and limit can ask for stays reachable.
                    totalHits:         self::MAX_OFFSET + self::MAX_LIMIT,
                ),
                // One more than any field may show, so a cut field is told apart from a full one.
                maxValuesPerFacet: max([self::FACET_VALUES, ...array_values($options['facet_values'] ?? [])]) + 1,
                fieldBoosts:       $options['boosts'],
                typoTolerance:     new TypoTolerance(disableOnNumbers: !$options['typos_on_numbers']),
            ),
            readonly: true,
        );

        if ($sort === [] && trim($query) === '') {
            $sort = is_array($options['default_sort'] ?? null) ? SortQuery::parse($options['default_sort']) : [];
        }

        $warnings = [];
        $sortable = self::declared(array_map(fn(string $spec): string => explode(':', $spec)[0], $sort), $index->sortableAttributes, 'Sort', $warnings);
        $sort     = array_values(array_filter($sort, fn(string $spec): bool => in_array(explode(':', $spec)[0], $sortable, true)));
        $filter   = self::declaredFilter($filter, $index->filterableAttributes, $warnings);
        $facets   = self::declared($options['facets'], $index->filterableAttributes, 'Facet', $warnings);
        // Only the range (a slider's ends): a field also in `facets` gets it from its facet.
        $stats    = self::declared(array_values(array_diff($options['facet_stats'] ?? [], $options['facets'])), $index->filterableAttributes, 'Facet stats', $warnings);

        $searchOptions = new SearchOptions(
            asYouType:             $options['as_you_type'],
            limit:                 $options['limit'],
            offset:                $offset,
            filter:                FilterQuery::fuzor($filter, $options['filter']),
            facets:                $facets,
            facetStatsOnly:        $stats,
            sortFacetValuesBy:     ['*' => FacetOrder::Count],
            sort:                  $sort,
            matchingStrategy:      MatchingStrategy::from($options['matching_strategy']),
            attributesToHighlight: $options['highlight'] !== [] ? $options['highlight'] : null,
            highlightPreTag:       $options['highlight_pre_tag'],
            highlightPostTag:      $options['highlight_post_tag'],
            attributesToCrop:      $options['crop'] !== [] ? $options['crop'] : null,
            cropLength:            $options['crop_length'],
            cropMarker:            $options['crop_marker'],
            attributesToRetrieve:  $options['attributes'],
            escapeFormatted:       true,
        );

        $raw = $index->search(self::searchText($query, $warnings), $searchOptions);

        $facetValues = $raw->facetDistribution;
        $truncated   = [];
        foreach ($facetValues as $field => $counts) {
            $max = ($options['facet_values'] ?? [])[$field] ?? ($options['facet_values'] ?? [])['*'] ?? self::FACET_VALUES;
            if (count($counts) > $max) {
                $facetValues[$field] = array_slice($counts, 0, $max, true);
                $truncated[]         = (string) $field;
            }
        }

        $labels  = Labels::read($path);
        $results = [];
        foreach ($raw->hits as $hit) {
            $results[] = self::shapeHit($hit, $options, $labels);
        }

        return [
            'query'             => $raw->query,
            'total'             => $raw->totalHits,
            'offset'            => $offset,
            'limit'             => $options['limit'],
            'results'           => $results,
            'facets'            => $facetValues,
            'facetStats'        => $raw->facetStats,
            'labels'            => self::responseLabels($labels, $facetValues, array_merge_recursive(FilterQuery::values(FilterQuery::unlocked($filter, $options['filter'])), FilterQuery::values($options['filter']))),
            'exhaustive'        => $raw->exhaustive,
            'approximateFacets' => $raw->approximateFacets,
            'truncatedFacets'   => $truncated,
            'warnings'          => [...$warnings, ...$raw->warnings],
        ];
    }

    /**
     * The response shape with no results — for an index that isn't built yet, or a failed
     * search, so callers always get the same keys.
     *
     * @param  array<string, mixed> $options Output of options().
     * @return array<string, mixed>
     */
    public static function empty(string $query, array $options, int $offset = 0): array
    {
        return [
            'query'             => $query,
            'total'             => 0,
            'offset'            => self::clampInt($offset, 0, self::MAX_OFFSET, 0),
            'limit'             => $options['limit'],
            'results'           => [],
            'facets'            => [],
            'facetStats'        => [],
            'labels'            => [],
            'exhaustive'        => true,
            'approximateFacets' => [],
            'truncatedFacets'   => [],
            'warnings'          => [],
        ];
    }

    /**
     * Prepare a response for json_encode(): facet maps become JSON objects even when empty or
     * when their keys happen to be sequential numbers ("0", "1", … from a numeric facet), which
     * json_encode() would otherwise turn into a list.
     *
     * @param  array<string, mixed> $response
     * @return array<string, mixed>
     */
    public static function forJson(array $response): array
    {
        $facets = is_array($response['facets'] ?? null) ? $response['facets'] : [];
        $stats  = is_array($response['facetStats'] ?? null) ? $response['facetStats'] : [];

        $response['facets']     = (object) array_map(fn(mixed $d): object => (object) (is_array($d) ? $d : []), $facets);
        $response['facetStats'] = (object) $stats;
        $response['labels']     = (object) array_map(fn(mixed $l): object => (object) (is_array($l) ? $l : []), is_array($response['labels'] ?? null) ? $response['labels'] : []);
        $response['results']    = array_map(static function (mixed $hit): mixed {
            if (is_array($hit) && is_array($hit['labels'] ?? null)) {
                $hit['labels'] = (object) $hit['labels'];
            }
            return $hit;
        }, is_array($response['results'] ?? null) ? $response['results'] : []);
        return $response;
    }

    /**
     * The names of the slug values this response shows outside the hits: every facet value and
     * every filtered value (the active-filter chips), per field that has labels.
     *
     * @param  array<string, array<string, string>> $labels
     * @param  array<string, array<string, int>>    $facets
     * @param  array<string, list<string>>          $filtered FilterQuery::values() of the filters.
     * @return array<string, array<string, string>>
     */
    private static function responseLabels(array $labels, array $facets, array $filtered): array
    {
        $out = [];
        foreach ($labels as $field => $names) {
            $values = [...array_map(strval(...), array_keys($facets[$field] ?? [])), ...$filtered[$field] ?? []];
            foreach ($values as $value) {
                if (isset($names[$value])) {
                    $out[$field][$value] = $names[$value];
                }
            }
        }
        return $out;
    }

    /**
     * The visitor's filter without what names a field the index doesn't declare filterable (with
     * a line in `warnings`): a field's condition, or a whole group that names one anywhere.
     *
     * @param  array<string, mixed> $filter
     * @param  list<string>         $declared
     * @param  list<string>         $warnings
     * @return array<string, mixed>
     */
    private static function declaredFilter(array $filter, array $declared, array &$warnings): array
    {
        foreach ($filter as $key => $value) {
            $named = FilterQuery::fields([$key => $value]);
            if (self::declared($named, $declared, 'Filter', $warnings) !== $named) {
                unset($filter[$key]);
            }
        }
        return $filter;
    }

    /**
     * @param  array<string, mixed>                 $hit
     * @param  array<string, mixed>                 $options
     * @param  array<string, array<string, string>> $labels
     * @return array<string, mixed>
     */
    private static function shapeHit(array $hit, array $options, array $labels): array
    {
        $formatted = is_array($hit['_formatted'] ?? null) ? $hit['_formatted'] : [];
        unset($hit['_formatted']);

        if ($options['attributes'] === null) {
            foreach (self::LONG_FIELDS as $field) {
                unset($hit[$field]);
            }
        }

        $title = isset($formatted['title']) && is_string($formatted['title'])
            ? $formatted['title']
            : htmlspecialchars(is_string($hit['title'] ?? null) ? $hit['title'] : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $snippet = '';
        foreach ($options['crop'] as $field) {
            if (isset($formatted[$field]) && is_string($formatted[$field]) && $formatted[$field] !== '') {
                $snippet = $formatted[$field];
                break;
            }
        }

        $hit['formatted'] = ['title' => $title, 'snippet' => $snippet];

        $hit['labels'] = [];
        foreach ($labels as $field => $names) {
            if (is_string($hit[$field] ?? null) || is_array($hit[$field] ?? null)) {
                $hit['labels'][$field] = Labels::name($names, $hit[$field]);
            }
        }
        return $hit;
    }

    /**
     * The token's `facet_values`: one number for every facet field (stored as '*'), or field =>
     * number (the others keep the default), clamped to 1–MAX_FACET_VALUES.
     *
     * @return array<string, int>
     */
    private static function facetValues(mixed $raw): array
    {
        if (is_numeric($raw)) {
            return ['*' => self::clampInt($raw, 1, self::MAX_FACET_VALUES, self::FACET_VALUES)];
        }
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $field => $max) {
            if (is_string($field) && $field !== '') {
                $out[$field] = self::clampInt($max, 1, self::MAX_FACET_VALUES, self::FACET_VALUES);
            }
        }
        return $out;
    }

    /**
     * The page sizes a request may choose: the token's `limits`, clamped like `limit`, with
     * `limit` itself always among them. [] when the options offer no choice.
     *
     * @return list<int>
     */
    private static function limits(mixed $raw, int $limit): array
    {
        if (!is_array($raw) || $raw === []) {
            return [];
        }
        $out = array_map(fn(mixed $value): int => self::clampInt($value, 1, self::MAX_LIMIT, $limit), $raw);
        $out = array_values(array_unique([$limit, ...$out]));
        sort($out);
        return $out;
    }

    /** @return list<string> */
    private static function fields(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $field) {
            $clean = is_scalar($field) ? (string) preg_replace('/[^a-z0-9_]/i', '', (string) $field) : '';
            if ($clean !== '') {
                $out[] = $clean;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * The fields the index declares; a warning for each other one.
     *
     * @param  list<string> $fields
     * @param  list<string> $declared
     * @param  list<string> $warnings
     * @return list<string>
     */
    private static function declared(array $fields, array $declared, string $what, array &$warnings): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (in_array($field, $declared, true)) {
                $out[] = $field;
            } else {
                $warnings[] = "{$what} field '{$field}' is not declared in the index; ignored.";
            }
        }
        return $out;
    }

    /**
     * @param  array<mixed>         $raw
     * @return array<string, float> field → weight, from 0 to 10; fields cleaned like fields().
     */
    private static function boosts(array $raw): array
    {
        $out = [];
        foreach ($raw as $field => $weight) {
            $clean = (string) preg_replace('/[^a-z0-9_]/i', '', (string) $field);
            if ($clean !== '' && is_numeric($weight)) {
                $out[$clean] = self::clampFloat($weight, 0.0, 10.0, 1.0);
            }
        }
        return $out;
    }

    private static function clampInt(mixed $value, int $min, int $max, int $default): int
    {
        return max($min, min($max, is_numeric($value) ? (int) $value : $default));
    }

    private static function clampFloat(mixed $value, float $min, float $max, float $default): float
    {
        return max($min, min($max, is_numeric($value) ? (float) $value : $default));
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
