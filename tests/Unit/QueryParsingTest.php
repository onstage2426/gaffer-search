<?php

declare(strict_types=1);

namespace GafferSearch\Tests\Unit;

use Fuzor\FacetExclude;
use Fuzor\FacetRange;
use Fuzor\Filter;
use GafferSearch\Support\AllValues;
use GafferSearch\Support\FilterQuery;
use GafferSearch\Support\SortQuery;
use GafferSearch\Tests\TestCase;

/** filter[] and sort[]: the one syntax URLs, tokens and searches from PHP share. */
final class QueryParsingTest extends TestCase
{
    public function test_filter_values_parse_to_scalars_lists_ranges_and_exclusions(): void
    {
        $filter = FilterQuery::parse([
            'color'      => 'Rood',
            'brand'      => ['Stella', 'Rosa'],
            'single'     => ['Rosa'],
            'price'      => ['min' => '10', 'max' => '50.5'],
            'from'       => ['min' => '10'],
            'to'         => ['max' => '20'],
            'visibility' => ['not' => ['outofstock', 'exclude-from-search']],
            'hidden'     => ['not' => 'exclude-from-catalog'],
            'notcheap'   => ['not' => ['max' => '0']],
        ]);

        self::assertSame('Rood', $filter['color']);
        self::assertSame(['Stella', 'Rosa'], $filter['brand']);
        self::assertSame('Rosa', $filter['single'], 'a one-value list is the value itself');
        self::assertEquals(FacetRange::between(10, 50.5), $filter['price']);
        self::assertEquals(FacetRange::min(10), $filter['from']);
        self::assertEquals(FacetRange::max(20), $filter['to']);
        self::assertEquals(new FacetExclude(['outofstock', 'exclude-from-search']), $filter['visibility']);
        self::assertEquals(new FacetExclude('exclude-from-catalog'), $filter['hidden']);
        self::assertEquals(new FacetExclude(FacetRange::max(0)), $filter['notcheap']);
    }

    public function test_invalid_and_empty_filter_input_is_dropped(): void
    {
        self::assertSame([], FilterQuery::parse([
            'empty'     => '',
            'emptylist' => ['', ''],
            'norange'   => ['min' => '', 'max' => ''],
            'nested'    => [['a']],
            '!!!'       => 'x',
        ]));
        self::assertSame(['brand_1' => 'Rosa'], FilterQuery::parse(['br-and_1<>' => 'Rosa']), 'field names keep only [a-z0-9_]');
    }

    public function test_serialize_is_the_inverse_of_parse(): void
    {
        $filter = [
            'brand'      => ['Stella', 'Rosa'],
            'price'      => FacetRange::between(10, 50),
            'visibility' => new FacetExclude(['outofstock', 'exclude-from-search']),
            'color'      => 'Rood',
        ];

        self::assertEquals($filter, FilterQuery::parse(FilterQuery::serialize($filter)));
    }

    public function test_serialize_refuses_ranges_the_url_syntax_cannot_carry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FilterQuery::serialize(['price' => FacetRange::gt(10)]);
    }

    public function test_normalize_keeps_objects_and_parses_the_rest(): void
    {
        $strict = FacetRange::gt(10);
        $filter = FilterQuery::normalize(['price' => $strict, 'brand' => ['Rosa'], 'visibility' => ['not' => 'outofstock']]);

        self::assertSame($strict, $filter['price'], 'strict ranges survive (PHP callers only)');
        self::assertSame('Rosa', $filter['brand']);
        self::assertEquals(new FacetExclude('outofstock'), $filter['visibility']);
    }

    public function test_sort_accepts_only_field_and_direction(): void
    {
        self::assertSame(
            ['price:asc', 'title_sort:desc'],
            SortQuery::parse(['price:asc', 'TITLE_SORT:DESC', 'price', 'price:up', 'a b:asc', '']),
        );
    }

    public function test_groups_and_all_values_parse_from_a_query_string_and_serialize_back(): void
    {
        parse_str('filter[tags][all][]=sale&filter[tags][all][]=new&filter[any][0][on_sale]=1&filter[any][1][category]=jurken'
            . '&filter[not][color]=rood&filter[not][brand]=x&filter[all][0][any][0][price][max]=20&filter[all][0][any][1][size]=s', $query);
        $filter = FilterQuery::parse($query['filter']);

        self::assertEquals(new AllValues(['sale', 'new']), $filter['tags']);
        self::assertSame([['on_sale' => '1'], ['category' => 'jurken']], $filter['any']);
        self::assertSame(['color' => 'rood', 'brand' => 'x'], $filter['not']);
        self::assertEquals([['any' => [['price' => FacetRange::max(20)], ['size' => 's']]]], $filter['all']);
        self::assertEquals($filter, FilterQuery::parse(FilterQuery::serialize($filter)), 'a token holds what parses back to the same filter');
        self::assertSame(['tags', 'on_sale', 'category', 'color', 'brand', 'price', 'size'], FilterQuery::fields($filter));
        self::assertSame(['tags' => ['sale', 'new'], 'on_sale' => ['1'], 'category' => ['jurken'], 'color' => ['rood'], 'brand' => ['x'], 'size' => ['s']], FilterQuery::values($filter));
    }

    public function test_empty_too_deep_and_too_long_groups_are_left_out(): void
    {
        self::assertSame([], FilterQuery::parse(['any' => [], 'all' => [[]], 'not' => [], 'tags' => ['all' => []]]));
        self::assertSame('one', FilterQuery::parse(['tags' => ['all' => ['one']]])['tags'], 'all of one value is that value');
        self::assertSame([], FilterQuery::parse(['any' => 'x', 'not' => 'y']), 'a group is a list or a map, not a value');

        $deep = ['color' => 'rood'];
        for ($i = 0; $i < FilterQuery::MAX_DEPTH; $i++) {
            $deep = ['not' => $deep];
        }
        self::assertNotSame([], FilterQuery::parse($deep));
        self::assertSame([], FilterQuery::parse(['not' => $deep]), 'deeper than MAX_DEPTH');

        $many = FilterQuery::parse(['any' => array_map(static fn(int $i): array => ['size' => (string) $i], range(1, 50))]);
        self::assertCount(FilterQuery::MAX_ENTRIES, $many['any']);
    }

    public function test_without_groups_fuzor_gets_the_map_and_a_lock_replaces_the_visitor_field(): void
    {
        $visitor = FilterQuery::parse(['brand' => 'pumpkin', 'color' => 'rood']);
        $locked  = FilterQuery::parse(['brand' => 'stella']);
        self::assertSame(['color' => 'rood', 'brand' => 'stella'], FilterQuery::fuzor($visitor, $locked));

        $grouped = FilterQuery::fuzor(FilterQuery::parse(['brand' => 'pumpkin', 'any' => [['brand' => 'pumpkin'], ['color' => 'rood']]]), $locked);
        self::assertEquals(Filter::all(['brand' => 'stella'], Filter::any(['brand' => 'pumpkin'], ['color' => 'rood'])), $grouped, 'the visitor\'s group applies on top of the lock: it only narrows');
    }
}
