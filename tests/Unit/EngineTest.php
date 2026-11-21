<?php

declare(strict_types=1);

namespace GafferSearch\Tests\Unit;

use GafferSearch\Builders\ContentIndexBuilder;
use GafferSearch\Engine;
use GafferSearch\Services\Documents;
use GafferSearch\Services\IndexRegistry;
use GafferSearch\Tests\TestCase;

final class EngineTest extends TestCase
{
    public function test_gaffer_finds_contract_1_through_the_filter_only(): void
    {
        $engine = new Engine();
        add_filter('gaffer_search_engine', static fn(mixed $found, mixed $contract = null): mixed => (int) $contract === Engine::CONTRACT ? $engine : $found, 10, 2);

        self::assertSame($engine, apply_filters('gaffer_search_engine', null, 1));
        self::assertNull(apply_filters('gaffer_search_engine', null, 2));
        self::assertFalse($engine->supports('anything'));
    }

    public function test_define_registers_definitions_with_their_version_and_document_callable(): void
    {
        $document = static fn(array $doc, string $index): array => [...$doc, 'card' => "<p>{$doc['id']}</p>"];

        (new Engine())->define([
            'news'   => ['type' => 'posts', 'post_types' => ['post', 'page'], 'label' => 'News', 'rebuild' => 'daily', 'version' => 'abc', 'document' => $document, 'synonyms' => ['equivalences' => [['auto', 'wagen']], 'one_way' => ['tv' => ['televisie']]]],
            'broken' => ['type' => 'pages'],
        ]);
        $registry = new IndexRegistry();

        self::assertSame(['news'], $registry->names());
        self::assertSame('News', $registry->get('news')?->label);
        self::assertSame('daily', $registry->get('news')?->rebuildInterval);
        self::assertSame('abc', $registry->get('news')?->version);
        self::assertSame($document, $registry->get('news')?->document);
        $builder = $registry->builder('news');
        self::assertInstanceOf(ContentIndexBuilder::class, $builder);
        self::assertSame(['equivalences' => [['auto', 'wagen']], 'oneWay' => ['tv' => ['televisie']]], $builder->synonyms());
    }

    public function test_direct_terms_and_typed_document_fields(): void
    {
        $document = static fn(array $doc, string $index): array => [...$doc, 'price_m2' => '12.50', 'sizes' => ['30', 40], 'gone' => null];

        (new Engine())->define([
            'news' => ['type' => 'posts', 'taxonomies' => ['category' => 'category'], 'direct_terms' => ['category'], 'fields' => ['price_m2' => 'float', 'sizes' => 'int', 'gone' => 'string'], 'facets' => ['price_m2'], 'document' => $document],
        ]);
        $registry = new IndexRegistry();

        self::assertSame(['category'], $registry->builder('news')?->config()['directTerms'] ?? null);
        self::assertSame(['price_m2' => 'float', 'sizes' => 'int', 'gone' => 'string'], $registry->get('news')?->fields);

        $documents = new Documents($registry);
        $decorate  = new \ReflectionMethod($documents, 'decorate');
        self::assertSame(['id' => 1, 'price_m2' => 12.5, 'sizes' => [30, 40]], $decorate->invoke($documents, ['id' => 1], 'news'), 'cast to their types; a missing value is left out');
    }
}
