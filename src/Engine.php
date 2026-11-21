<?php

declare(strict_types=1);

namespace GafferSearch;

use GafferSearch\Builders\ContentIndexBuilder;
use GafferSearch\Builders\IndexBuilderInterface;
use GafferSearch\Builders\ProductIndexBuilder;
use GafferSearch\Services\HealthCheck;
use GafferSearch\Services\IndexRegistry;
use GafferSearch\Services\IndexService;
use GafferSearch\Services\Locks;
use GafferSearch\Services\PhpSearch;
use GafferSearch\Services\SettingsService;
use GafferSearch\Services\Tokens;
use GafferSearch\Support\FilterQuery;

/**
 * Contract 1: what Gaffer gets from apply_filters('gaffer_search_engine', null, 1). The
 * specification is docs/contract.md and the fixtures in tests/contract/ hold it; this class only
 * translates between the contract's plain data and the plugin's internals. Its methods and their
 * shapes only ever grow (see "The rules" in the contract).
 *
 * @internal The contract is public, this class isn't: Gaffer never names it.
 */
final class Engine
{
    public const int CONTRACT = 1;

    /**
     * Features added after contract 1's first release (docs/contract.md, "Features").
     *
     * @var list<string>
     */
    private array $features = ['facet_stats', 'filter_groups', 'pending_updates', 'search_codes', 'health_checks'];

    private const array TYPES = ['products', 'posts'];

    /** The definition keys contract 1 knows, per type. */
    private const array KEYS = [
        'common'   => ['type', 'label', 'searchable', 'facets', 'sortable', 'meta', 'taxonomies', 'direct_terms', 'fields', 'synonyms', 'rebuild', 'version', 'document', 'labels'],
        'products' => ['thumbnail_size', 'variation_price'],
        'posts'    => ['post_types'],
    ];

    /** The fields every document of a type has, besides the ones `meta` and `taxonomies` map. */
    private const array FIELDS = [
        'products' => ['id', 'title', 'url', 'thumbnail', 'date', 'description', 'short_description', 'visibility', 'menu_order', 'total_sales', 'on_sale', 'price', 'regular_price', 'sale_price', 'stock_status', 'sku', 'rating'],
        'posts'    => ['id', 'title', 'url', 'thumbnail', 'date', 'content', 'excerpt', 'post_type'],
    ];

    private const array SCHEDULES = ['', 'hourly', 'twicedaily', 'daily'];

    /** The types a `fields` entry may have (as `meta`'s). */
    private const array FIELD_TYPES = ['string', 'int', 'float'];

    public function supports(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    /**
     * Registers the site's indexes for this request. A definition with problems() is skipped
     * (and logged) rather than registered half-right: doctor reports it. One exception: a `meta`
     * line for a field products have built in is left out (logged; problems() still names it),
     * so a theme written before the field was built in keeps its index, with the built-in field.
     * Likewise unknown keys (`language`, stemming gone with Fuzor 2's stemmers, among them) are
     * ignored. A validation rule added after 1.0 must drop what it refuses, never the index.
     *
     * @param array<string, mixed> $indexes
     */
    public function define(array $indexes): void
    {
        self::$notes = [];
        try {
            $this->register($indexes);
        } finally {
            self::logOnce(self::$notes);
        }
    }

    /**
     * What define() logged: kept until the request ends, so they go to the log once per change
     * (logOnce()), not on every request.
     *
     * @var list<string>
     */
    private static array $notes = [];

    /** The option holding a hash of the last logged notes (and when): not autoloaded. */
    private const string LOGGED_OPTION = 'gaffer_search_define_logged';

    /**
     * Log a definition's notes when they differ from the last ones logged, or a day later: the
     * definition is read on every request, and the same lines on every request buried the log.
     * An option only when there's something to log (no query on a site whose definitions are fine).
     *
     * @param list<string> $notes
     */
    private static function logOnce(array $notes): void
    {
        if ($notes === []) {
            return;
        }
        $hash   = md5(implode("\n", $notes));
        $logged = get_option(self::LOGGED_OPTION);
        if (is_array($logged) && ($logged['hash'] ?? null) === $hash && (int) ($logged['time'] ?? 0) > time() - DAY_IN_SECONDS) {
            return;
        }
        foreach ($notes as $note) {
            error_log($note); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
        update_option(self::LOGGED_OPTION, ['hash' => $hash, 'time' => time()], false);
    }

    /** @param array<string, mixed> $indexes */
    private function register(array $indexes): void
    {
        foreach ($indexes as $name => $definition) {
            $name = (string) $name;
            if (is_array($definition) && in_array($definition['type'] ?? null, self::TYPES, true)) {
                // Unknown keys are ignored (contract rule 3): a key a newer Gaffer or theme sends
                // never undefines an index here; problems() still names it for doctor.
                $definition = array_intersect_key($definition, array_flip([...self::KEYS['common'], ...self::KEYS[$definition['type']]]));
                $definition = self::withoutTaxonomySearchable($name, self::withoutBuiltInMeta($name, $definition));
            }
            if (!is_array($definition) || $this->problemsOf($name, $definition) !== []) {
                self::$notes[] = "Gaffer Search: index \"{$name}\" isn't defined: its definition has problems (php gaffer doctor lists them).";
                continue;
            }
            IndexRegistry::register(
                new IndexDefinition(
                    name: $name,
                    label: is_string($definition['label'] ?? null) ? $definition['label'] : $name,
                    description: '',
                    rebuildInterval: (string) ($definition['rebuild'] ?? ''),
                    version: (string) ($definition['version'] ?? ''),
                    document: ($definition['document'] ?? null) instanceof \Closure ? $definition['document'] : null,
                    fields: array_map(strval(...), (array) ($definition['fields'] ?? [])),
                    labels: ($definition['labels'] ?? null) instanceof \Closure ? $definition['labels'] : null,
                ),
                self::builder($definition),
            );
        }
    }

    /**
     * @param array<string, mixed> $indexes
     * @return list<string>
     */
    public function problems(array $indexes): array
    {
        $problems = [];
        foreach ($indexes as $name => $definition) {
            $problems = [...$problems, ...(is_array($definition) ? [...$this->problemsOf( (string) $name, $definition), ...self::advice( (string) $name, $definition)] : ["{$name}: the definition is not an array"])];
        }

        return $problems;
    }

    /** @return array<string, array{built: bool, documents: int, version: ?string, building: bool, error: ?string, pending: int, pending_since: ?int, drift: ?int, drift_checked: ?int, served: ?list<string>}> */
    public function status(): array
    {
        $service = $this->service();
        $stats   = $service->stats();
        $status  = [];
        $served  = HealthCheck::served();
        foreach ((new IndexRegistry())->names() as $name) {
            $drift         = HealthCheck::drift($name);
            $error         = get_transient('gaffer_search_cron_error_' . $name);
            $pending       = $service->pendingUpdates($name);
            $status[$name] = [
                'built'     => (bool) ($stats[$name]['exists'] ?? false),
                'documents' => (int) ($stats[$name]['total_documents'] ?? 0),
                'version'   => $service->builtVersion($name),
                'building'  => $service->isBuilding($name),
                'error'     => is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : null,
                'pending'       => $pending['count'],
                'pending_since' => $pending['since'],
                'drift'         => $drift !== null ? $drift['differing'] : null,
                'drift_checked' => $drift !== null ? $drift['time'] : null,
                'served'        => $served,
            ];
        }

        return $status;
    }

    public function endpoint(): string
    {
        return plugins_url('endpoints/search.php', dirname(__DIR__) . '/gaffer-search.php');
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function search(string $index, array $request): array
    {
        // The request's filter is the visitor's (contract: the first page of an archive), as the
        // endpoint reads filter[]: visibility and term win over it, a field the index doesn't
        // filter on is left out with a warning (as a lock, it found nothing).
        [$visitor, $warnings] = FilterQuery::visitor(is_array($request['filter'] ?? null) ? $request['filter'] : []);
        $response = PhpSearch::run($index, is_scalar($request['query'] ?? null) ? trim( (string) $request['query']) : '', [
            ...$this->locked($index, [...$request, 'filter' => []]),
            'offset' => is_numeric($request['offset'] ?? null) ? (int) $request['offset'] : 0,
            'sort'   => self::strings($request['sort'] ?? []),
        ], $visitor);
        $response['warnings'] = [...(array) ($response['warnings'] ?? []), ...$warnings];

        return $response;
    }

    /** @param array<string, mixed> $request */
    public function token(string $index, array $request): string
    {
        return Tokens::sign(['index' => $index, ...$this->locked($index, $request), ...self::ttl($request)]);
    }

    /**
     * The request's locked part as the plugin's search params: the filter with the visibility
     * and the term archive's lock added.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function locked(string $index, array $request): array
    {
        $raw    = is_array($request['filter'] ?? null) ? $request['filter'] : [];
        $filter = FilterQuery::parse($raw);
        if (!FilterQuery::fits($raw)) {
            // Part of a lock would be left out (a group past the limits): the lock finds nothing.
            error_log("Gaffer Search: a filter for index \"{$index}\" has groups deeper than " . FilterQuery::MAX_DEPTH . ' or longer than ' . FilterQuery::MAX_ENTRIES . ': it finds nothing.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            $filter = Locks::NOTHING;
        }
        $use    = $request['visibility'] ?? null;
        if ($use === 'search' || $use === 'catalog') {
            $filter = [...$filter, ...Locks::visibility($use)];
        }
        $filter = [...$filter, ...$this->termFilter($index, $request)];

        return array_filter([
            'limit'        => is_numeric($request['limit'] ?? null) ? (int) $request['limit'] : null,
            'limits'       => is_array($request['limits'] ?? null) ? array_values(array_map(intval(...), array_filter($request['limits'], is_numeric(...)))) ?: null : null,
            'facet_values' => is_array($request['facet_values'] ?? null) ? array_map(intval(...), array_filter($request['facet_values'], is_numeric(...))) ?: null : null,
            'max_docs'     => is_numeric($request['max_docs'] ?? null) ? (int) $request['max_docs'] : null,
            'default_sort' => self::strings($request['default_sort'] ?? []) ?: null,
            'facets'       => self::strings($request['facets'] ?? []) ?: null,
            'facet_stats'  => self::strings($request['facet_stats'] ?? []) ?: null,
            'attributes'   => isset($request['attributes']) ? self::strings($request['attributes']) : null,
            'filter'       => $filter ?: null,
        ], static fn(mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, string>
     */
    private function termFilter(string $index, array $request): array
    {
        if (!isset($request['term'])) {
            return [];
        }
        $term = is_numeric($request['term']) ? get_term( (int) $request['term']) : null;

        // A term that doesn't exist locks to nothing: dropping the lock would widen the search.
        return $term instanceof \WP_Term ? Locks::term($index, $term) : Locks::NOTHING;
    }

    /**
     * @param array<string, mixed> $request
     * @return array{ttl?: int}
     */
    private static function ttl(array $request): array
    {
        return is_numeric($request['ttl'] ?? null) && (int) $request['ttl'] > 0 ? ['ttl' => (int) $request['ttl']] : [];
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_map(strval(...), array_filter($value, is_scalar(...)))) : [];
    }

    /**
     * @param array<string, mixed> $definition
     * @return list<string>
     */
    private function problemsOf(string $name, array $definition): array
    {
        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            return ["\"{$name}\": an index name is lowercase letters, digits and underscores"];
        }
        $type = $definition['type'] ?? null;
        if (!in_array($type, self::TYPES, true)) {
            return ["{$name}: type must be one of " . implode(', ', self::TYPES) . ', not ' . (is_string($type) ? "\"{$type}\"" : 'missing')];
        }
        if ($type === 'products' && !class_exists('WooCommerce')) {
            return ["{$name}: type products needs WooCommerce"];
        }

        $problems = [];
        $known    = [...self::KEYS['common'], ...self::KEYS[$type]];
        foreach (array_diff(array_keys($definition), $known) as $key) {
            $problems[] = $key === 'language'
                ? "{$name}: remove \"language\": text is indexed as written, without stemming (the index is used without it)"
                : "{$name}: unknown key \"{$key}\"" . self::suggest( (string) $key, $known);
        }
        foreach (['searchable', 'facets', 'sortable', 'post_types'] as $key) {
            if (isset($definition[$key]) && (!is_array($definition[$key]) || !array_is_list($definition[$key]) || array_filter($definition[$key], is_string(...)) !== $definition[$key])) {
                $problems[] = "{$name}: {$key} must be a list of strings";
            }
        }
        foreach (['meta', 'taxonomies', 'fields'] as $key) {
            if (isset($definition[$key]) && !is_array($definition[$key])) {
                $problems[] = "{$name}: {$key} must be a map";
            }
        }
        if (isset($definition['direct_terms']) && (!is_array($definition['direct_terms']) || !array_is_list($definition['direct_terms']) || array_filter($definition['direct_terms'], is_string(...)) !== $definition['direct_terms'])) {
            $problems[] = "{$name}: direct_terms must be a list of strings";
        }
        foreach (is_array($definition['fields'] ?? null) ? $definition['fields'] : [] as $field => $fieldType) {
            if (!preg_match('/^[a-z0-9_]+$/', (string) $field) || !in_array($fieldType, self::FIELD_TYPES, true)) {
                $problems[] = "{$name}: fields maps a field name ([a-z0-9_]+) to " . implode(', ', self::FIELD_TYPES) . ", not \"{$field}\" => " . (is_string($fieldType) ? "\"{$fieldType}\"" : gettype($fieldType));
            }
        }
        if (isset($definition['fields']) && !isset($definition['document'])) {
            $problems[] = "{$name}: fields are what the document closure adds, and there is none";
        }
        if (!in_array($definition['rebuild'] ?? '', self::SCHEDULES, true)) {
            $problems[] = "{$name}: rebuild must be one of hourly, twicedaily, daily or ''";
        }
        if (isset($definition['document']) && !$definition['document'] instanceof \Closure) {
            $problems[] = "{$name}: document must be a closure";
        }
        if (isset($definition['labels']) && !$definition['labels'] instanceof \Closure) {
            $problems[] = "{$name}: labels must be a closure";
        }
        if (isset($definition['variation_price']) && !in_array($definition['variation_price'], ['min', 'default'], true)) {
            $problems[] = "{$name}: variation_price must be min or default";
        }
        if (isset($definition['synonyms']) && (!is_array($definition['synonyms']) || array_diff(array_keys($definition['synonyms']), ['equivalences', 'one_way']) !== [])) {
            $problems[] = "{$name}: synonyms has the keys equivalences and one_way";
        }
        if ($problems !== []) {
            return $problems;
        }

        // The fields a document will have: the type's own and the mapped ones.
        $fields = self::FIELDS[$type];
        foreach ( (array) ($definition['meta'] ?? []) as $metaKey => $field) {
            $field = is_array($field) ? (string) ($field[0] ?? '') : (string) $field;
            if ($type === 'products' && self::isBuiltIn( (string) $metaKey, $field)) {
                $problems[] = "{$name}: remove the meta line \"{$metaKey}\" => \"{$field}\": products have " . implode(', ', array_keys(ProductIndexBuilder::STANDARD_FIELDS)) . ' built in, read from WC_Product';
                continue;
            }
            if (in_array($field, $fields, true)) {
                $problems[] = "{$name}: meta maps \"{$metaKey}\" to \"{$field}\", which the index has already";
            }
            $fields[] = $field;
        }
        foreach ( (array) ($definition['taxonomies'] ?? []) as $field) {
            $fields[] = (string) $field;
        }
        foreach ( (array) ($definition['fields'] ?? []) as $field => $fieldType) {
            if (in_array($field, $fields, true)) {
                $problems[] = "{$name}: fields names \"{$field}\", which the index has already";
            }
            $fields[] = (string) $field;
        }
        foreach (array_intersect(FilterQuery::GROUPS, $fields) as $field) {
            $problems[] = "{$name}: a field can't be named \"{$field}\": any, all and not are a filter's groups";
        }
        foreach ( (array) ($definition['direct_terms'] ?? []) as $taxonomy) {
            if (!array_key_exists($taxonomy, (array) ($definition['taxonomies'] ?? []))) {
                $problems[] = "{$name}: direct_terms names \"{$taxonomy}\", which taxonomies doesn't map" . self::suggest($taxonomy, array_map(strval(...), array_keys( (array) ($definition['taxonomies'] ?? []))));
            }
        }
        foreach (array_intersect( (array) ($definition['searchable'] ?? []), self::taxonomyFields($definition)) as $field) {
            $problems[] = "{$name}: searchable names \"{$field}\", a taxonomy field (a list of slugs, which text search can't read): add a string field with the term names to `fields` (filled by the document closure) and search that";
        }
        foreach (['searchable', 'facets', 'sortable'] as $key) {
            foreach ( (array) ($definition[$key] ?? []) as $field) {
                if (!in_array($field, $fields, true)) {
                    $problems[] = "{$name}: {$key} names \"{$field}\", which isn't a field of the index" . self::suggest($field, $fields);
                }
            }
        }

        return $problems;
    }

    /**
     * Problems that leave the index defined (define() doesn't check them): a definition that
     * works, but not as its author thinks.
     *
     * @param array<string, mixed> $definition
     * @return list<string>
     */
    private static function advice(string $name, array $definition): array
    {
        $type     = $definition['type'] ?? null;
        $problems = [];
        // sortable gives text a {field}_sort key; a number sorts as itself once it's a facet.
        $numbers = $type === 'products' ? array_keys(array_diff(ProductIndexBuilder::STANDARD_FIELDS, ['string'])) : [];
        foreach ( (array) ($definition['meta'] ?? []) as $field) {
            if (is_array($field) && in_array($field[1] ?? 'string', ['int', 'float'], true)) {
                $numbers[] = (string) ($field[0] ?? '');
            }
        }
        foreach ( (array) ($definition['fields'] ?? []) as $field => $fieldType) {
            if (in_array($fieldType, ['int', 'float'], true)) {
                $numbers[] = (string) $field;
            }
        }
        foreach (array_intersect( (array) ($definition['sortable'] ?? []), $numbers) as $field) {
            $problems[] = "{$name}: sortable names \"{$field}\", a number: sortable is for text (a {$field}_sort key); put it in facets to sort on {$field}";
        }

        return $problems;
    }

    /** Whether a products `meta` line maps to a built-in field or reads WooCommerce's meta key for one. */
    private static function isBuiltIn(string $metaKey, string $field): bool
    {
        return in_array($field, array_keys(ProductIndexBuilder::STANDARD_FIELDS), true) || in_array($metaKey, ProductIndexBuilder::STANDARD_META_KEYS, true);
    }

    /**
     * The definition without its `meta` lines for built-in product fields (logged: logOnce()).
     *
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private static function withoutBuiltInMeta(string $name, array $definition): array
    {
        if (($definition['type'] ?? null) !== 'products' || !is_array($definition['meta'] ?? null)) {
            return $definition;
        }
        foreach ($definition['meta'] as $metaKey => $field) {
            $field = is_array($field) ? (string) ($field[0] ?? '') : (string) $field;
            if (self::isBuiltIn( (string) $metaKey, $field)) {
                unset($definition['meta'][$metaKey]);
                self::$notes[] = "Gaffer Search: index \"{$name}\" ignores its meta line \"{$metaKey}\" => \"{$field}\": that field is built in (php gaffer doctor --wp).";
            }
        }
        return $definition;
    }

    /**
     * The fields `taxonomies` maps to.
     *
     * @param array<string, mixed> $definition
     * @return list<string>
     */
    private static function taxonomyFields(array $definition): array
    {
        return array_values(array_map(strval(...), is_array($definition['taxonomies'] ?? null) ? $definition['taxonomies'] : []));
    }

    /**
     * The definition without taxonomy fields in `searchable` (logged): text search can't read a
     * list of slugs, and an index defined before problems() named it keeps working.
     *
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private static function withoutTaxonomySearchable(string $name, array $definition): array
    {
        if (!is_array($definition['searchable'] ?? null)) {
            return $definition;
        }
        $taxonomies = self::taxonomyFields($definition);
        foreach (array_intersect($definition['searchable'], $taxonomies) as $field) {
            self::$notes[] = "Gaffer Search: index \"{$name}\" doesn't search its taxonomy field \"{$field}\" (php gaffer doctor --wp).";
        }
        $definition['searchable'] = array_values(array_diff($definition['searchable'], $taxonomies));

        return $definition;
    }

    /** @param list<string> $choices */
    private static function suggest(string $value, array $choices): string
    {
        $best = null;
        foreach ($choices as $choice) {
            $distance = levenshtein($value, $choice);
            if ($distance <= 2 && ($best === null || $distance < $best[0])) {
                $best = [$distance, $choice];
            }
        }

        return $best !== null ? " (did you mean \"{$best[1]}\"?)" : '';
    }

    /** @param array<string, mixed> $definition */
    private static function builder(array $definition): IndexBuilderInterface
    {
        $common = [
            'searchable' => isset($definition['searchable']) ? array_values( (array) $definition['searchable']) : null,
            'facets'     => array_values( (array) ($definition['facets'] ?? [])),
            'meta'       => (array) ($definition['meta'] ?? []),
            'taxonomy'   => (array) ($definition['taxonomies'] ?? []),
            'sortable'   => array_values( (array) ($definition['sortable'] ?? [])),
            'synonyms'   => self::synonyms( (array) ($definition['synonyms'] ?? [])),
            'directTerms' => array_values( (array) ($definition['direct_terms'] ?? [])),
        ];

        return $definition['type'] === 'products'
            ? new ProductIndexBuilder(
                ...$common,
                thumbnailSize: (string) ($definition['thumbnail_size'] ?? ''),
                variationPrice: (string) ($definition['variation_price'] ?? 'min'),
            )
            : new ContentIndexBuilder(
                ...$common,
                postTypes: array_values( (array) ($definition['post_types'] ?? ['post'])),
            );
    }

    /**
     * Contract 1's synonyms as the builders take them.
     *
     * @param array<mixed> $synonyms
     * @return array{equivalences?: list<list<string>>, oneWay?: array<string, list<string>>}
     */
    private static function synonyms(array $synonyms): array
    {
        $out          = [];
        $equivalences = [];
        foreach ( (array) ($synonyms['equivalences'] ?? []) as $group) {
            $equivalences[] = self::strings($group);
        }
        if ($equivalences !== []) {
            $out['equivalences'] = $equivalences;
        }
        $oneWay = [];
        foreach ( (array) ($synonyms['one_way'] ?? []) as $word => $alternatives) {
            $oneWay[ (string) $word] = self::strings($alternatives);
        }
        if ($oneWay !== []) {
            $out['oneWay'] = $oneWay;
        }

        return $out;
    }

    private function service(): IndexService
    {
        return new IndexService(new SettingsService(), new IndexRegistry());
    }
}
