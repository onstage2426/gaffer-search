<?php

declare(strict_types=1);

namespace GafferSearch\Tests\Unit;

use GafferSearch\BuildContext;
use GafferSearch\Builders\ProductIndexBuilder;
use GafferSearch\Tests\TestCase;

/** ProductIndexBuilder's `inStock` option: per attribute, the values a customer can order. */
final class InStockTest extends TestCase
{
    private const array SIZES = ['s', 'm', 'l'];

    public function test_a_product_without_variations_has_its_values_while_it_can_be_ordered(): void
    {
        self::assertSame(self::SIZES, $this->values('instock', null));
        self::assertSame(self::SIZES, $this->values('onbackorder', null));
        self::assertSame([], $this->values('outofstock', null));
    }

    public function test_a_variable_product_has_the_values_of_its_orderable_variations(): void
    {
        $variations = [
            $this->variation('instock', 's'),
            $this->variation('outofstock', 'm'),
            $this->variation('onbackorder', 'l'),
            $this->variation('instock', 'xl'),
        ];
        self::assertSame(['s', 'l'], $this->values('outofstock', $variations), 'its own stock status doesn\'t count; xl isn\'t one of its values');
        self::assertSame([], $this->values('instock', [$this->variation('outofstock', 's')]));
        self::assertSame([], $this->values('instock', []), 'variable without published variations');
    }

    public function test_a_variation_for_any_value_counts_them_all(): void
    {
        self::assertSame(self::SIZES, $this->values('outofstock', [$this->variation('instock', ''), $this->variation('instock', 's')]));
        self::assertSame(self::SIZES, $this->values('outofstock', [['stock' => 'instock', 'attributes' => []]]), 'no attribute row at all');
        self::assertSame(['m'], $this->values('outofstock', [$this->variation('instock', 'm')]));
    }

    public function test_each_attribute_is_a_facet_field_watched_and_labelled(): void
    {
        $builder = new ProductIndexBuilder(inStock: ['pa_maat' => 'size_in_stock']);

        self::assertContains('size_in_stock', $builder->schema(new BuildContext(1))->filterableAttributes);
        self::assertContains('_stock_status', $builder->watches()->metaKeys);
        self::assertContains('attribute_pa_maat', $builder->watches()->metaKeys);
        self::assertContains('pa_maat', $builder->watches()->taxonomies);
        self::assertSame([], $builder->labels(new BuildContext(1))['size_in_stock']);
        self::assertNotContains('attribute_pa_maat', (new ProductIndexBuilder())->watches()->metaKeys);
    }

    /**
     * @param  list<array{stock: string, attributes: array<string, string>}>|null $variations
     * @return list<string>
     */
    private function values(string $stock, ?array $variations): array
    {
        $method = new \ReflectionMethod(ProductIndexBuilder::class, 'orderableValues');
        return $method->invoke(new ProductIndexBuilder(inStock: ['pa_maat' => 'size_in_stock']), 'pa_maat', self::SIZES, $stock, $variations);
    }

    /** @return array{stock: string, attributes: array<string, string>} */
    private function variation(string $stock, string $size): array
    {
        return ['stock' => $stock, 'attributes' => ['attribute_pa_maat' => $size]];
    }
}
