<?php

declare(strict_types=1);

namespace GafferSearch\Support;

use Fuzor\FacetExclude;
use Fuzor\FacetRange;
use Fuzor\Filter;

/**
 * Parses the plugin's filter[] contract into the shape Fuzor\Index::search()/searchBoolean()/
 * facetSearch() expect, and serializes that shape back for signing into a token.
 *
 *   filter[field]=value                         — exact string match
 *   filter[field][]=Casio&filter[field][]=Seiko — OR across multiple string values
 *   filter[field][all][]=sale&…[all][]=new      — every one of the values (AllValues)
 *   filter[field][min]=x&filter[field][max]=y   — numeric range (Fuzor\FacetRange)
 *   filter[field][not]=value                    — exclusion (Fuzor\FacetExclude)
 *   filter[field][not][]=a&filter[field][not][]=b
 *   filter[field][not][min]=x&…[not][max]=y      — excluded numeric range
 *
 * and the groups (feature `filter_groups`), whose entries are filters of this same shape:
 *
 *   filter[any][0][on_sale]=1&filter[any][1][category]=jurken — at least one entry holds
 *   filter[all][0][…]&filter[all][1][…]                       — every entry holds
 *   filter[not][category]=jurken&filter[not][color]=rood      — the entry doesn't hold
 *
 * so `any`, `all` and `not` are never field names. Groups nest up to MAX_DEPTH levels, with up to
 * MAX_ENTRIES entries each; more, or an empty group, is left out like other invalid input.
 *
 * The same array shape is what a signed token's locked `filter` holds, so one parser serves
 * the HTTP endpoints, tokens, and searches from PHP (server-side rendering) alike. Parsed, a
 * filter is a map of field conditions (strings, lists, FacetRange, FacetExclude, AllValues) plus
 * the group keys: `any`/`all` a list of parsed filters, `not` one. fuzor() makes Fuzor's filter
 * of it.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class FilterQuery
{
    /** The group keys: never field names. */
    public const array GROUPS = ['any', 'all', 'not'];

    public const int MAX_DEPTH   = 4;
    public const int MAX_ENTRIES = 20;
    /** Conditions in a visitor's filter, groups included: past this its groups are left out. */
    public const int MAX_CONDITIONS = 64;

    /**
     * Parse filter[] input, or a filter PHP callers pass (values that are already FacetRange,
     * FacetExclude or AllValues objects are kept as they are, including strict gt/lt ranges,
     * which the filter[] syntax can't express).
     *
     * @param  array<mixed> $raw
     * @return array<string, mixed>
     */
    public static function parse(array $raw, int $depth = 0): array
    {
        $filter = [];

        foreach ($raw as $field => $value) {
            $field = (string) preg_replace('/[^a-z0-9_]/i', '', (string) $field);
            if ($field === '') {
                continue;
            }

            if (in_array($field, self::GROUPS, true)) {
                $group = $depth < self::MAX_DEPTH && is_array($value) ? self::parseGroup($field, $value, $depth + 1) : null;
                if ($group !== null) {
                    $filter[$field] = $group;
                }
                continue;
            }

            if ($value instanceof FacetRange || $value instanceof FacetExclude || $value instanceof AllValues) {
                $filter[$field] = $value;
                continue;
            }

            if (is_array($value) && array_key_exists('not', $value)) {
                $excluded = self::parseValue($value['not']);
                if ($excluded !== null) {
                    $filter[$field] = new FacetExclude($excluded);
                }
                continue;
            }

            if (is_array($value) && array_key_exists('all', $value)) {
                $values = is_array($value['all']) ? self::strings($value['all']) : self::strings([$value['all']]);
                if ($values !== []) {
                    $filter[$field] = count($values) === 1 ? $values[0] : new AllValues($values);
                }
                continue;
            }

            $parsed = self::parseValue($value);
            if ($parsed !== null) {
                $filter[$field] = $parsed;
            }
        }

        return $filter;
    }

    /**
     * A visitor's filter[] (the endpoint's, or the visitor's part of a search() from PHP): parse(),
     * and when it holds more than MAX_CONDITIONS conditions, without its groups (a warning
     * says so): nested `any`/`not` groups are what a search's cost grows with.
     *
     * @param  array<mixed> $raw
     * @return array{array<string, mixed>, list<string>} the filter, warnings
     */
    public static function visitor(array $raw): array
    {
        $filter = self::parse($raw);
        if (self::conditions($filter) <= self::MAX_CONDITIONS) {
            return [$filter, []];
        }

        return [
            array_diff_key($filter, array_flip(self::GROUPS)),
            ['Filter: more than ' . self::MAX_CONDITIONS . ' conditions; its any/all/not groups were left out.'],
        ];
    }

    /**
     * Whether parse() keeps all of $raw's groups: none deeper than MAX_DEPTH, none with more
     * than MAX_ENTRIES entries. A locked filter (a token's, a term's) must: leaving part of a
     * lock out would show more than it allows.
     *
     * @param array<mixed> $raw
     */
    public static function fits(array $raw, int $depth = 0): bool
    {
        foreach ($raw as $field => $value) {
            if (!in_array( (string) $field, self::GROUPS, true) || !is_array($value)) {
                continue;
            }
            if ($depth >= self::MAX_DEPTH) {
                return false;
            }
            $entries = (string) $field === 'not' ? [$value] : array_values($value);
            if (count($entries) > self::MAX_ENTRIES) {
                return false;
            }
            foreach ($entries as $entry) {
                if (is_array($entry) && !self::fits($entry, $depth + 1)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<mixed> $filter a parsed filter */
    private static function conditions(array $filter): int
    {
        $count = 0;
        foreach ($filter as $field => $value) {
            if ( (string) $field === 'not' && is_array($value)) {
                $count += self::conditions($value);
            } elseif (in_array( (string) $field, self::GROUPS, true) && is_array($value)) {
                foreach ($value as $entry) {
                    $count += is_array($entry) ? self::conditions($entry) : 1;
                }
            } else {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Same as parse(): the name PHP callers' filters go through.
     *
     * @param  array<string, mixed> $filter
     * @return array<string, mixed>
     */
    public static function normalize(array $filter): array
    {
        return self::parse($filter);
    }

    /**
     * Inverse of parse(): turn a parsed filter, possibly holding FacetRange/FacetExclude/AllValues
     * objects, back into the plain filter[] array shape, so it can be JSON-encoded into a signed
     * token and parsed again on the other side.
     *
     * @param  array<string, mixed> $filter
     * @return array<string, mixed>
     * @throws \InvalidArgumentException For a value the filter[] syntax can't express (a strict gt/lt range).
     */
    public static function serialize(array $filter): array
    {
        $out = [];
        foreach ($filter as $field => $value) {
            $field = (string) $field;
            $out[$field] = match (true) {
                $field === 'not' && is_array($value)                          => self::serialize($value),
                in_array($field, ['any', 'all'], true) && is_array($value)    => array_map(static fn(mixed $entry): array => self::serialize(is_array($entry) ? $entry : []), array_values($value)),
                $value instanceof FacetExclude                                => ['not' => self::serializeValue($value->filter)],
                $value instanceof AllValues                                   => ['all' => $value->values],
                is_array($value) && array_key_exists('not', $value)           => ['not' => self::serializeValue($value['not'])],
                default                                                       => self::serializeValue($value),
            };
        }
        return $out;
    }

    /**
     * Fuzor's filter for a search: the visitor's filter and the locked one, both parsed. A field
     * the locked filter names replaces the visitor's condition on it; the groups of both apply
     * on top (AND), so a visitor's group can only narrow a lock. Without groups or AllValues this
     * is a filter map, as before groups existed: the same results, speed and facet counts.
     *
     * @param  array<string, mixed> $visitor
     * @param  array<string, mixed> $locked
     * @return array<string, mixed>|Filter
     */
    public static function fuzor(array $visitor, array $locked = []): array|Filter
    {
        $plain  = [];
        $extras = [];
        foreach ([self::unlocked($visitor, $locked), $locked] as $filter) {
            [$map, $more] = self::split($filter);
            $plain  = [...$plain, ...$map];
            $extras = [...$extras, ...$more];
        }
        if ($extras === []) {
            return $plain;
        }
        return Filter::all(...($plain !== [] ? [$plain] : []), ...$extras);
    }

    /**
     * The visitor's filter without the fields the locked filter names (the lock replaces them).
     *
     * @param  array<string, mixed> $visitor
     * @param  array<string, mixed> $locked
     * @return array<string, mixed>
     */
    public static function unlocked(array $visitor, array $locked): array
    {
        return array_diff_key($visitor, array_flip(array_diff(array_map(strval(...), array_keys($locked)), self::GROUPS)));
    }

    /**
     * Every field a parsed filter names, groups included.
     *
     * @param  array<string, mixed> $filter
     * @return list<string>
     */
    public static function fields(array $filter): array
    {
        $fields = [];
        foreach ($filter as $field => $value) {
            $field  = (string) $field;
            $named  = match (true) {
                $field === 'not' && is_array($value)                       => self::fields($value),
                in_array($field, ['any', 'all'], true) && is_array($value) => array_merge([], ...array_map(static fn(mixed $entry): array => self::fields(is_array($entry) ? $entry : []), $value)),
                default                                                    => [$field],
            };
            $fields = [...$fields, ...$named];
        }
        return array_values(array_unique($fields));
    }

    /**
     * The plain values a parsed filter names per field, groups included (the active-filter chips'
     * labels): equals, lists, AllValues and exclusions; ranges have none.
     *
     * @param  array<string, mixed> $filter
     * @return array<string, list<string>>
     */
    public static function values(array $filter): array
    {
        $out = [];
        foreach ($filter as $field => $value) {
            $field = (string) $field;
            $found = match (true) {
                $field === 'not' && is_array($value)                       => self::values($value),
                in_array($field, ['any', 'all'], true) && is_array($value) => array_merge_recursive([], ...array_map(static fn(mixed $entry): array => self::values(is_array($entry) ? $entry : []), $value)),
                default                                                    => [$field => self::plainValues($value)],
            };
            foreach ($found as $name => $values) {
                $out[$name] = array_values(array_unique([...$out[$name] ?? [], ...$values]));
            }
        }
        return array_filter($out, static fn(array $values): bool => $values !== []);
    }

    /**
     * A parsed filter as a map of plain conditions and the Fuzor entries for the rest (each
     * AllValues, each group).
     *
     * @param  array<string, mixed> $filter
     * @return array{0: array<string, mixed>, 1: list<Filter>}
     */
    private static function split(array $filter): array
    {
        $map    = [];
        $extras = [];
        foreach ($filter as $field => $value) {
            $field = (string) $field;
            if ($field === 'not' && is_array($value)) {
                $extras[] = Filter::not(self::entry($value));
            } elseif (in_array($field, ['any', 'all'], true) && is_array($value)) {
                $entries  = array_map(static fn(mixed $entry): array|Filter => self::entry(is_array($entry) ? $entry : []), array_values($value));
                $extras[] = $field === 'any' ? Filter::any(...$entries) : Filter::all(...$entries);
            } elseif ($value instanceof AllValues) {
                $extras[] = Filter::all(...array_map(static fn(string $one): array => [$field => $one], $value->values));
            } else {
                $map[$field] = $value;
            }
        }
        return [$map, $extras];
    }

    /**
     * One parsed filter as a Fuzor entry: its map, or Filter::all() of the map and the rest.
     *
     * @param  array<string, mixed> $filter
     * @return array<string, mixed>|Filter
     */
    private static function entry(array $filter): array|Filter
    {
        [$map, $extras] = self::split($filter);
        return $extras === [] ? $map : Filter::all(...($map !== [] ? [$map] : []), ...$extras);
    }

    /**
     * A group's entries parsed: `any`/`all` a list of filters, `not` one filter. Null when
     * nothing valid is left (an empty group is left out, not "matches nothing").
     *
     * @param  array<mixed> $value
     * @return array<mixed>|null
     */
    private static function parseGroup(string $group, array $value, int $depth): ?array
    {
        if ($group === 'not') {
            $entry = self::parse($value, $depth);
            return $entry !== [] ? $entry : null;
        }
        $entries = [];
        foreach (array_slice(array_values($value), 0, self::MAX_ENTRIES) as $entry) {
            $parsed = is_array($entry) ? self::parse($entry, $depth) : [];
            if ($parsed !== []) {
                $entries[] = $parsed;
            }
        }
        return $entries !== [] ? $entries : null;
    }

    /** @return list<string> */
    private static function plainValues(mixed $value): array
    {
        $value = $value instanceof FacetExclude ? $value->filter : $value;
        return match (true) {
            $value instanceof AllValues => $value->values,
            is_string($value)           => [$value],
            is_array($value)            => array_values(array_filter($value, is_string(...))),
            default                     => [],
        };
    }

    /**
     * @param  array<mixed> $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn(mixed $v): string => is_scalar($v) ? (string) $v : '', $values),
            fn(string $v): bool => $v !== '',
        )));
    }

    /** @return string|list<string>|FacetRange|null */
    private static function parseValue(mixed $value): string|array|FacetRange|null
    {
        if (!is_array($value)) {
            return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
        }

        if (array_key_exists('min', $value) || array_key_exists('max', $value)) {
            // A bound that isn't a finite number (1e999 is INF) is left out, like other invalid input.
            $min = isset($value['min']) && is_scalar($value['min']) && $value['min'] !== '' && is_finite( (float) $value['min']) ? (float) $value['min'] : null;
            $max = isset($value['max']) && is_scalar($value['max']) && $value['max'] !== '' && is_finite( (float) $value['max']) ? (float) $value['max'] : null;
            return match (true) {
                $min !== null && $max !== null => FacetRange::between($min, $max),
                $min !== null                  => FacetRange::min($min),
                $max !== null                  => FacetRange::max($max),
                default                        => null,
            };
        }

        $values = array_values(array_filter(
            array_map(fn(mixed $v): string => is_scalar($v) ? (string) $v : '', $value),
            fn(string $v): bool => $v !== '',
        ));
        return match (count($values)) {
            0       => null,
            1       => $values[0],
            default => $values,
        };
    }

    private static function serializeValue(mixed $value): mixed
    {
        if ($value instanceof FacetRange) {
            if ($value->gt !== null || $value->lt !== null) {
                throw new \InvalidArgumentException('Strict FacetRange bounds (gt/lt) cannot be expressed as filter[] parameters; use min/max.');
            }
            return array_filter(['min' => $value->gte, 'max' => $value->lte], fn(?float $v): bool => $v !== null);
        }
        if (is_array($value) && (array_key_exists('min', $value) || array_key_exists('max', $value))) {
            return array_intersect_key($value, ['min' => true, 'max' => true]);
        }
        if (is_array($value)) {
            return array_values(array_map(fn(mixed $v): string => is_scalar($v) ? (string) $v : '', $value));
        }
        return is_scalar($value) ? (string) $value : '';
    }
}
