<?php

declare(strict_types=1);

namespace GafferSearch\Tests\Unit;

use GafferSearch\BuildContext;
use GafferSearch\Builders\ContentIndexBuilder;
use GafferSearch\Support\Labels;
use GafferSearch\Builders\ProductIndexBuilder;
use GafferSearch\Watch;
use GafferSearch\Tests\TestCase;

/** The parts of per-document updates that need no database (the queue and job are tested on real sites). */
final class UpdatesTest extends TestCase
{
    public function test_a_parent_post_type_counts_as_watched_and_updates_its_parent(): void
    {
        $watch = new Watch(postTypes: ['product'], parentPostTypes: ['product_variation']);

        self::assertTrue($watch->watchesPostType('product_variation'));
        self::assertTrue($watch->updatesParent('product_variation'));
        self::assertFalse($watch->updatesParent('product'));
        self::assertFalse($watch->watchesPostType('post'));
    }

    public function test_products_update_through_their_variations_content_does_not(): void
    {
        self::assertSame(['product_variation'], (new ProductIndexBuilder())->watches()->parentPostTypes);
        self::assertSame(['product'], (new ProductIndexBuilder())->watches()->postTypes);
        self::assertSame([], (new ContentIndexBuilder())->watches()->parentPostTypes);
        self::assertSame([], (new ProductIndexBuilder(postTypes: ['course']))->watches()->parentPostTypes);
    }

    public function test_a_document_with_a_value_without_a_name_needs_new_labels(): void
    {
        $labels = ['brand' => ['stella' => 'Stella'], 'color' => []];

        self::assertFalse(Labels::lacksNames($labels, [['id' => 1, 'brand' => 'stella', 'price' => 10.0]]));
        self::assertTrue(Labels::lacksNames($labels, [['id' => 1, 'brand' => ['stella', 'nieuw']]]));
        self::assertTrue(Labels::lacksNames($labels, [['id' => 1, 'color' => ['rood']]]), 'a field that had no terms yet');
        self::assertFalse(Labels::lacksNames($labels, [['id' => 1, 'sku' => 'X']]), 'fields without labels are not checked');
    }

    public function test_labels_list_every_taxonomy_field(): void
    {
        self::assertSame(['color' => []], (new ContentIndexBuilder(taxonomy: ['pa_kleur' => 'color']))->labels(new BuildContext(1)));
    }
}
