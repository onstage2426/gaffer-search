<?php

declare(strict_types=1);

namespace GafferSearch\Tests\Unit;

use Fuzor\FacetExclude;
use GafferSearch\Engine;
use GafferSearch\Services\Locks;
use GafferSearch\Services\PhpSearch;
use GafferSearch\Services\Tokens;
use GafferSearch\Support\Token;
use GafferSearch\Tests\TestCase;
use GafferSearchTestWordPress;
use WP_Term;

/** What Engine::token() and search() stand on: Tokens, PhpSearch, Locks. */
final class TokensAndSearchTest extends TestCase
{
    public function test_indexes_register_on_their_action(): void
    {
        self::registerIndex();
        self::assertSame(['products'], (new \GafferSearch\Services\IndexRegistry())->names());
        self::assertSame([], GafferSearchTestWordPress::$doingItWrong);
    }

    public function test_tokens_carry_their_params_and_serialize_filter_objects(): void
    {
        $search = Token::verify(Tokens::sign(['index' => 'products', 'filter' => ['visibility' => new FacetExclude('outofstock')]]), self::TOKEN_SECRET);

        self::assertSame(['index' => 'products', 'filter' => ['visibility' => ['not' => 'outofstock']], 'site' => 1, 'c' => 1], $search);

        GafferSearchTestWordPress::$site = 4;
        self::assertSame(4, Token::verify(Tokens::sign(['index' => 'products', 'site' => 1]), self::TOKEN_SECRET)['site'], 'always the signing site, never the caller\'s');
    }

    public function test_a_token_without_a_filter_locks_none(): void
    {
        self::registerIndex();

        self::assertArrayNotHasKey('filter', Token::verify(Tokens::sign(['index' => 'products']), self::TOKEN_SECRET));
        self::assertSame(12, PhpSearch::run('products', '', ['limit' => 20])['total']);
    }

    public function test_on_a_site_with_languages_tokens_and_searches_use_the_current_language(): void
    {
        $adapter = new class implements \GafferSearch\Language\LanguageAdapter {
            public string $current = 'en-us';
            public function languages(): array { return ['nl', 'en-us']; }
            public function current(): ?string { return $this->current; }
            public function switch(string $language): void { $this->current = $language; }
            public function postSql(string $alias, string $language): array { return ['', '1=1']; }
            public function translations(array $postIds): array { return $postIds; }
        };
        \GafferSearch\Support\Language\Languages::set($adapter);

        self::assertSame('en-us', Token::verify(Tokens::sign(['index' => 'products', 'language' => 'nl']), self::TOKEN_SECRET)['language'], 'the current language, never the caller\'s');

        $adapter->current = 'all';
        self::assertSame('nl', \GafferSearch\Support\Language\Languages::current(), 'an unlisted current language: the first one');

        $adapter->current = 'en-us';
        copy(self::indexPath(), self::dir() . '/' . \GafferSearch\Support\IndexSlug::compute('products', self::SEARCH_SECRET, 'en-us') . '.db');
        self::assertSame(5, PhpSearch::run('products', 'jurk')['total'], 'the en-us file');
        $adapter->current = 'nl';
        self::assertSame(0, PhpSearch::run('products', 'jurk')['total'], 'no nl file: the empty result');
    }

    public function test_search_reads_the_index_from_the_settings(): void
    {
        self::assertSame(5, PhpSearch::run('products', 'jurk')['total']);
    }

    public function test_an_unbuilt_index_returns_the_empty_shape(): void
    {
        $result = PhpSearch::run('nope', 'jurk', ['offset' => 12]);

        self::assertSame(0, $result['total']);
        self::assertSame(12, $result['offset']);
    }

    public function test_term_lock_uses_the_indexed_field_and_the_decoded_slug(): void
    {
        self::registerIndex();

        self::assertSame(['category' => 'café-jurken'], Locks::term('products', new WP_Term(5, 'product_cat', 'Café &amp; Jurken', 'caf%c3%a9-jurken')));
        self::assertSame(Locks::NOTHING, Locks::term('products', new WP_Term(6, 'post_tag', 'Tag', 'tag')), 'a taxonomy the index does not store: nothing, never everything');
        self::assertSame(Locks::NOTHING, Locks::term('unknown', new WP_Term(5, 'product_cat', 'Jurken', 'jurken')), 'an index not defined in this request');
        self::assertSame(0, PhpSearch::run('products', '', ['filter' => Locks::NOTHING])['total']);
    }

    public function test_visibility_lock_follows_the_woocommerce_setting(): void
    {
        self::assertEquals(['visibility' => new FacetExclude(['exclude-from-search'])], Locks::visibility('search'));
        self::assertEquals(['visibility' => new FacetExclude(['exclude-from-catalog'])], Locks::visibility('catalog'));

        update_option('woocommerce_hide_out_of_stock_items', 'yes');
        self::assertEquals(['visibility' => new FacetExclude(['exclude-from-catalog', 'outofstock'])], Locks::visibility('catalog'));
    }

    public function test_a_ttl_becomes_a_signed_expiry(): void
    {
        $before = time();
        $token  = Token::verify(Tokens::sign(['index' => 'products', 'ttl' => 3600, 'expires' => 1]), self::TOKEN_SECRET);

        self::assertArrayNotHasKey('ttl', $token);
        self::assertGreaterThanOrEqual($before + 3600, $token['expires']);
        self::assertLessThanOrEqual(time() + 3600, $token['expires']);
        self::assertArrayNotHasKey('expires', Token::verify(Tokens::sign(['index' => 'products', 'expires' => 1]), self::TOKEN_SECRET), 'only through ttl');
        self::assertArrayNotHasKey('expires', Token::verify(Tokens::sign(['index' => 'products', 'ttl' => 0]), self::TOKEN_SECRET));
    }

    public function test_endpoint_url(): void
    {
        self::assertStringEndsWith('/gaffer-search/endpoints/search.php', (new Engine())->endpoint());
    }
}
