<?php

declare(strict_types=1);

namespace GafferSearch\Tests\Unit;

use GafferSearch\Support\FilterQuery;
use GafferSearch\Support\SearchRunner;
use GafferSearch\Tests\TestCase;

/** What a public endpoint must not let one request cost, and what a lock must never lose. */
final class RequestLimitsTest extends TestCase
{
    public function test_a_search_text_is_searched_with_each_word_once_and_at_most_max_words(): void
    {
        $warnings = [];
        self::assertSame('rode jurk', SearchRunner::searchText('rode jurk', $warnings));
        self::assertSame('jurk', SearchRunner::searchText(str_repeat('jurk ', 400), $warnings));
        self::assertSame([], $warnings);

        $many = implode(' ', array_map(fn(int $i): string => "w{$i}", range(1, 40)));
        self::assertCount(SearchRunner::MAX_WORDS, explode(' ', SearchRunner::searchText($many, $warnings)));
        self::assertSame('', SearchRunner::searchText("\xff\xfe", $warnings));
        self::assertCount(2, $warnings);
    }

    public function test_a_visitor_filter_past_the_condition_budget_loses_its_groups_only(): void
    {
        $any = array_map(fn(int $i): array => ['color' => ['not' => "c{$i}"]], range(1, 20));
        $raw = ['brand' => 'stella', 'any' => [['any' => $any], ['any' => $any], ['any' => $any], ['any' => $any]]];

        [$filter, $warnings] = FilterQuery::visitor($raw);
        self::assertSame(['brand' => 'stella'], $filter);
        self::assertCount(1, $warnings);

        [$small, $none] = FilterQuery::visitor(['brand' => 'stella', 'any' => [['color' => 'rood'], ['color' => 'blauw']]]);
        self::assertArrayHasKey('any', $small);
        self::assertSame([], $none);
    }

    public function test_a_lock_past_the_group_limits_does_not_fit(): void
    {
        $deep = ['brand' => 'stella'];
        for ($i = 0; $i < 5; $i++) {
            $deep = ['not' => $deep];
        }
        self::assertFalse(FilterQuery::fits($deep));
        self::assertFalse(FilterQuery::fits(['all' => array_fill(0, 21, ['tag' => 'x'])]));
        self::assertTrue(FilterQuery::fits(['all' => array_fill(0, 20, ['tag' => 'x']), 'brand' => 'stella']));
    }

    public function test_a_range_bound_that_is_not_finite_is_left_out(): void
    {
        self::assertSame([], FilterQuery::parse(['price' => ['min' => '1e999']]));
    }
}
