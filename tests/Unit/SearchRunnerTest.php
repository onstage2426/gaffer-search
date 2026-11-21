<?php

declare(strict_types=1);

namespace GafferSearch\Tests\Unit;

use Fuzor\Exceptions\QueryException;
use Fuzor\FacetExclude;
use Fuzor\Index;
use Fuzor\SchemaConfig;
use GafferSearch\Support\SearchRunner;
use GafferSearch\Tests\TestCase;

/** The search both the endpoint and gaffer_search() run: options, limits, hit shape. */
final class SearchRunnerTest extends TestCase
{
    public function test_options_default_and_clamp_every_token_key(): void
    {
        $defaults = SearchRunner::options([]);
        self::assertSame([10, true, 500, 200], [$defaults['limit'], $defaults['as_you_type'], $defaults['max_docs'], $defaults['crop_length']]);
        self::assertSame(['title', 'content', 'description'], $defaults['highlight']);
        self::assertNull($defaults['attributes']);

        $clamped = SearchRunner::options(['limit' => 1000, 'max_docs' => 0, 'crop_length' => 5, 'facets' => ['brand', 'br<and', 'brand', ''], 'as_you_type' => 'no']);
        self::assertSame([100, 1, 50, false], [$clamped['limit'], $clamped['max_docs'], $clamped['crop_length'], $clamped['as_you_type']]);
        self::assertSame(['brand'], $clamped['facets'], 'cleaned and de-duplicated');
    }

    public function test_facet_count_docs_cuts_the_counted_matches(): void
    {
        self::assertSame(50_000, SearchRunner::options([])['facet_count_docs']);
        self::assertSame([1_000, 50_000], [SearchRunner::options(['facet_count_docs' => 5])['facet_count_docs'], SearchRunner::options(['facet_count_docs' => 10 ** 6])['facet_count_docs']]);

        $path = self::dir() . '/facet-count.db';
        Index::rebuild($path, static fn(Index $new) => $new->insert(array_map(
            static fn(int $id): array => ['id' => $id, 'title' => "Jurk {$id}", 'brand' => $id % 2 === 0 ? 'stella' : 'pumpkin'],
            range(1, 1_100),
        )), schema: new SchemaConfig(filterableAttributes: ['id', 'brand'], searchableAttributes: ['title']))->close();

        $exact = SearchRunner::run($path, 'jurk', SearchRunner::options(['facets' => ['brand']]));
        self::assertSame([], $exact['approximateFacets']);
        self::assertEquals(['pumpkin' => 550, 'stella' => 550], $exact['facets']['brand']);

        $cut = SearchRunner::run($path, 'jurk', SearchRunner::options(['facets' => ['brand'], 'facet_count_docs' => 1_000]));
        self::assertSame(['brand'], $cut['approximateFacets']);
        self::assertSame(1_000, array_sum($cut['facets']['brand']));
    }

    public function test_a_search_returns_the_documented_shape(): void
    {
        $result = $this->search('jurk', ['facets' => ['brand', 'price']]);

        self::assertSame(['query', 'total', 'offset', 'limit', 'results', 'facets', 'facetStats', 'labels', 'exhaustive', 'approximateFacets', 'truncatedFacets', 'warnings'], array_keys($result));
        self::assertSame(5, $result['total']);
        self::assertEquals(['stella' => 4, 'rosa-clara' => 1], $result['facets']['brand']);
        self::assertSame(['brand' => ['stella' => 'Stella', 'rosa-clara' => 'Rosa Clará']], $result['labels'], 'names of the facet values shown');
        self::assertEqualsWithDelta(89.5, $result['facetStats']['price']['min'], 0.001);
        self::assertTrue($result['exhaustive']);
        self::assertSame([], $result['warnings']);
    }

    public function test_facet_values_cut_a_field_and_say_so(): void
    {
        self::assertSame([], $this->search('jurk', ['facets' => ['brand']])['truncatedFacets'], 'two values, under the default');

        $cut = $this->search('jurk', ['facets' => ['brand', 'color'], 'facet_values' => ['brand' => 1]]);
        self::assertEquals(['stella' => 4], $cut['facets']['brand'], 'the most frequent value stays');
        self::assertSame(['stella' => 'Stella'], $cut['labels']['brand'], 'no names for values cut');
        self::assertSame(['brand'], $cut['truncatedFacets']);

        self::assertSame(['brand', 'color'], $this->search('jurk', ['facets' => ['brand', 'color'], 'facet_values' => 1])['truncatedFacets'], 'a number counts for every field');
        self::assertSame(['*' => 1000], SearchRunner::options(['facet_values' => 5000])['facet_values'], 'clamped');
        self::assertSame(['size' => 1], SearchRunner::options(['facet_values' => ['size' => 0, '' => 5]])['facet_values']);
    }

    public function test_hits_are_plain_text_with_formatted_html_and_no_long_fields(): void
    {
        $hit = $this->search('deluxe')['results'][0];

        self::assertSame(3, $hit['id']);
        self::assertSame('Jurk <b>deluxe</b> & co', $hit['title'], 'stored fields stay plain text');
        self::assertSame('Jurk &lt;b&gt;<mark>deluxe</mark>&lt;/b&gt; &amp; co', $hit['formatted']['title'], 'formatted is escaped HTML with the highlight tags');
        self::assertArrayNotHasKey('description', $hit, 'long text fields are left out by default');
        self::assertArrayHasKey('snippet', $hit['formatted']);
    }

    public function test_hits_carry_the_names_of_their_slug_fields(): void
    {
        $hit = $this->search('sluier', ['attributes' => ['title', 'brand', 'category', 'price']])['results'][0];

        self::assertSame(['rosa-clara', ['accessoires']], [$hit['brand'], $hit['category']]);
        self::assertSame(['brand' => 'Rosa Clará', 'category' => ['Accessoires']], $hit['labels'], 'the same shape as the values');
    }

    public function test_an_index_without_a_labels_file_has_no_labels(): void
    {
        $copy = self::dir() . '/unlabelled.db';
        copy(self::indexPath(), $copy);
        $result = SearchRunner::run($copy, 'sluier', SearchRunner::options(['facets' => ['brand']]));

        self::assertSame([], $result['labels']);
        self::assertSame([], $result['results'][0]['labels']);
    }

    public function test_attributes_choose_the_returned_fields(): void
    {
        $hit = $this->search('sluier', ['attributes' => ['title', 'description']])['results'][0];

        self::assertSame(['id', 'title', 'description', 'formatted', 'labels'], array_keys($hit));
    }

    public function test_a_locked_filter_replaces_the_visitors_value_for_that_field_only(): void
    {
        $locked = SearchRunner::options(['filter' => ['brand' => 'rosa-clara'], 'limit' => 50]);
        $result = SearchRunner::run(self::indexPath(), '', $locked, 0, [], ['brand' => 'stella', 'color' => 'wit']);

        self::assertSame([9, 4, 3], $this->ids($result), 'brand from the token, color from the visitor');
        self::assertSame(['brand' => ['rosa-clara' => 'Rosa Clará'], 'color' => ['wit' => 'Wit']], $result['labels'], 'filtered values get their names too (the chips)');
    }

    public function test_exclusions_hide_documents_and_pass_documents_without_the_field(): void
    {
        $result = $this->search('', ['limit' => 50, 'filter' => ['visibility' => new FacetExclude(['exclude-from-catalog', 'outofstock'])]]);

        self::assertCount(10, $result['results']);
        self::assertNotContains(10, $this->ids($result));
        self::assertNotContains(12, $this->ids($result));
    }

    public function test_sort_offset_and_limit(): void
    {
        $options = SearchRunner::options(['limit' => 3]);

        self::assertSame([12, 7, 8], $this->ids(SearchRunner::run(self::indexPath(), '', $options, 0, ['price:asc'])));
        self::assertSame([4, 9, 5], $this->ids(SearchRunner::run(self::indexPath(), '', $options, 3, ['price:asc'])));
        self::assertSame(10_000, SearchRunner::run(self::indexPath(), '', $options, 99_999)['offset'], 'offset is clamped');
    }

    public function test_a_browse_without_sort_is_newest_first(): void
    {
        self::assertSame([12, 11, 10], $this->ids($this->search('', ['limit' => 3])));
    }

    public function test_default_sort_applies_to_a_browse_without_a_sort_only(): void
    {
        $options = SearchRunner::options(['limit' => 3, 'default_sort' => ['price:asc', 'bad spec']]);

        self::assertSame(['price:asc'], $options['default_sort'], 'parsed like sort[]');
        self::assertSame([12, 7, 8], $this->ids(SearchRunner::run(self::indexPath(), '', $options)), 'browsing: the default');
        self::assertSame([12, 11, 10], $this->ids(SearchRunner::run(self::indexPath(), '', $options, 0, ['id:desc'])), 'the visitor\'s sort wins');
        self::assertSame(
            $this->ids($this->search('jurk', ['limit' => 3])),
            $this->ids(SearchRunner::run(self::indexPath(), 'jurk', $options)),
            'a query keeps relevance',
        );
    }

    public function test_undeclared_request_fields_are_ignored_with_a_warning(): void
    {
        $result = SearchRunner::run(self::indexPath(), '', SearchRunner::options(['facets' => ['nope', 'brand']]), 0, ['nope:asc', 'price:asc'], ['nope' => 'x', 'title_sort' => 'tiara', 'color' => 'wit']);

        self::assertSame(4, $result['total'], 'only color applies; title_sort is sortable, not filterable');
        self::assertSame([8, 4, 9, 3], $this->ids($result), 'price:asc still applies');
        self::assertSame(['brand'], array_keys($result['facets']));
        self::assertCount(4, $result['warnings'], 'sort nope, filter nope and title_sort, facet nope');
    }

    public function test_an_undeclared_locked_filter_fails_instead_of_widening(): void
    {
        $this->expectException(QueryException::class);
        SearchRunner::run(self::indexPath(), '', SearchRunner::options(['filter' => ['nope' => 'x']]));
    }

    public function test_options_for_matching_typos_and_boosts(): void
    {
        $defaults = SearchRunner::options([]);
        self::assertSame(['last', false, ['title' => 3.0]], [$defaults['matching_strategy'], $defaults['typos_on_numbers'], $defaults['boosts']]);

        $set = SearchRunner::options(['matching_strategy' => 'all', 'typos_on_numbers' => '1', 'boosts' => ['title' => 99, 'desc<ription' => '2', 'sku' => 'x']]);
        self::assertSame(['all', true, ['title' => 10.0, 'description' => 2.0]], [$set['matching_strategy'], $set['typos_on_numbers'], $set['boosts']]);
        self::assertSame('last', SearchRunner::options(['matching_strategy' => 'nonsense'])['matching_strategy']);
    }

    public function test_matching_strategy_decides_which_documents_a_multi_word_query_returns(): void
    {
        $last = $this->search('witte jurk', ['limit' => 20]);
        $all  = $this->search('witte jurk', ['limit' => 20, 'matching_strategy' => 'all']);

        self::assertSame([4], $this->ids($last), 'last: every document with the first word');
        self::assertSame([], $this->ids($all), 'all: no document has both words');
    }

    public function test_the_empty_result_has_the_same_shape(): void
    {
        $empty = SearchRunner::empty('q', SearchRunner::options([]), 20);

        self::assertSame(array_keys($this->search('jurk')), array_keys($empty));
        self::assertSame(20, $empty['offset']);
    }

    public function test_json_output_keeps_empty_facets_objects(): void
    {
        $json = (string) json_encode(SearchRunner::forJson(SearchRunner::empty('', SearchRunner::options([]))));

        self::assertStringContainsString('"facets":{}', $json);
        self::assertStringContainsString('"facetStats":{}', $json);
    }

    /** @return array<string, mixed> */
    private function search(string $query, array $options = []): array
    {
        return SearchRunner::run(self::indexPath(), $query, SearchRunner::options($options));
    }

    /** @return list<int> */
    private function ids(array $result): array
    {
        $ids = array_column($result['results'], 'id');
        return $ids;
    }
}
