<?php

declare(strict_types=1);

namespace GafferSearch\Tests\Unit;

use GafferSearch\BuildContext;
use GafferSearch\Builders\ProductIndexBuilder;
use GafferSearch\Tests\TestCase;

/** ProductIndexBuilder's own fields: the standard fields, the default variation, its sort orders. */
final class ProductBuilderTest extends TestCase
{
    public function test_the_standard_fields_are_always_there_and_their_meta_watched(): void
    {
        $builder = new ProductIndexBuilder();
        $schema  = $builder->schema(new BuildContext(1));

        foreach ([...array_keys(ProductIndexBuilder::STANDARD_FIELDS), 'on_sale'] as $field) {
            self::assertContains($field, $schema->filterableAttributes, $field);
            self::assertContains($field, $schema->sortableAttributes, $field);
        }
        foreach (ProductIndexBuilder::STANDARD_META_KEYS as $key) {
            self::assertContains($key, $builder->watches()->metaKeys, 'writes that bypass clean_post_cache: ' . $key);
        }
    }

    public function test_woocommerces_orders_are_sortable_only(): void
    {
        $schema = (new ProductIndexBuilder())->schema(new BuildContext(1));

        self::assertContains('menu_order', $schema->sortableAttributes);
        self::assertContains('total_sales', $schema->sortableAttributes);
        self::assertNotContains('menu_order', $schema->filterableAttributes);
        self::assertContains('total_sales', (new ProductIndexBuilder())->watches()->metaKeys);
    }

    public function test_text_is_indexed_as_written(): void
    {
        $schema = (new ProductIndexBuilder())->schema(new BuildContext(1));

        self::assertNull($schema->stemmer);
        self::assertSame([], $schema->stopWords);
    }

    public function test_the_default_variation_is_the_one_woocommerces_default_attributes_select(): void
    {
        $small = ['pa_formaat' => 'klein', 'kleur' => 'rood'];
        $big   = ['pa_formaat' => 'doos-2-kg', 'kleur' => ''];

        self::assertTrue($this->selects($big, ['pa_formaat' => 'doos-2-kg', 'kleur' => 'rood']), '"any" colour matches');
        self::assertFalse($this->selects($small, ['pa_formaat' => 'doos-2-kg', 'kleur' => 'rood']));
        self::assertTrue($this->selects($small, ['pa_formaat' => 'klein', 'kleur' => 'rood']));
        self::assertFalse($this->selects($big, ['pa_formaat' => 'doos-2-kg']), 'every attribute needs a default, as on the product page');
        self::assertFalse($this->selects($small, ['pa_formaat' => 'middel', 'kleur' => 'rood']));
        self::assertFalse($this->selects($small, []));
        self::assertFalse($this->selects([], ['pa_formaat' => 'klein']), 'a variation without attributes');
    }

    public function test_variation_price_is_an_explicit_choice(): void
    {
        $default = new ProductIndexBuilder(variationPrice: 'default');

        self::assertSame('default', $default->config()['variationPrice']);
        self::assertSame('min', (new ProductIndexBuilder())->config()['variationPrice']);
        self::assertContains('_default_attributes', $default->watches()->metaKeys);
        self::assertNotContains('_default_attributes', (new ProductIndexBuilder())->watches()->metaKeys);

        $this->expectException(\InvalidArgumentException::class);
        new ProductIndexBuilder(variationPrice: 'max');
    }

    /**
     * @param array<string, string> $attributes
     * @param array<string, string> $defaults
     */
    private function selects(array $attributes, array $defaults): bool
    {
        return (bool) (new \ReflectionMethod(ProductIndexBuilder::class, 'selects'))->invoke(null, $attributes, $defaults);
    }
}
