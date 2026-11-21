<?php

declare(strict_types=1);

namespace GafferSearch\Tests\Unit;

use GafferSearch\Engine;
use GafferSearch\Tests\TestCase;

/** Products' standard fields are built in: a theme's meta line for one is a problem with the fix in it. */
final class EngineStandardFieldsTest extends TestCase
{
    public function test_a_meta_line_for_a_standard_field_or_its_meta_key_is_a_problem(): void
    {
        $problems = (new Engine())->problems(['products' => ['type' => 'products', 'facets' => ['price', 'stock_status', 'rating'], 'sortable' => ['sku'], 'meta' => [
            '_price'        => ['price', 'float'],
            '_stock_status' => 'availability',
            'my_price'      => ['regular_price', 'float'],
            '_stock'        => ['stock_quantity', 'int'],
        ]]]);

        self::assertSame([
            'products: remove the meta line "_price" => "price": products have price, regular_price, sale_price, stock_status, sku, rating built in, read from WC_Product',
            'products: remove the meta line "_stock_status" => "availability": products have price, regular_price, sale_price, stock_status, sku, rating built in, read from WC_Product',
            'products: remove the meta line "my_price" => "regular_price": products have price, regular_price, sale_price, stock_status, sku, rating built in, read from WC_Product',
        ], $problems, 'by field name and by WooCommerce\'s meta key; other meta stays; the standard fields can be facets and sorts');
    }

    public function test_a_definition_with_a_built_in_meta_line_is_still_defined_with_the_built_in_field(): void
    {
        (new Engine())->define(['shop' => ['type' => 'products', 'facets' => ['price'], 'meta' => ['_price' => ['price', 'float'], '_stock' => ['stock_quantity', 'int']]]]);

        $builder = (new \GafferSearch\Services\IndexRegistry())->builder('shop');
        self::assertNotNull($builder, 'a plugin update before the theme\'s must not undefine its index');
        self::assertSame(['_stock' => ['field' => 'stock_quantity', 'type' => 'int']], $builder->config()['meta'] ?? null, 'the built-in price wins; other meta stays');
    }

    public function test_posts_may_map_those_names(): void
    {
        self::assertSame([], (new Engine())->problems(['news' => ['type' => 'posts', 'meta' => ['_price' => ['price', 'float']], 'facets' => ['price']]]));
    }

    public function test_a_document_field_cannot_take_a_standard_name(): void
    {
        self::assertSame(['products: fields names "rating", which the index has already'], (new Engine())->problems(['products' => ['type' => 'products', 'fields' => ['rating' => 'float'], 'document' => static fn(array $d): array => $d]]));
    }
}
