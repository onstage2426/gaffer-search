<?php

declare(strict_types=1);

namespace GafferSearch\Tests\Unit;

use Fuzor\SchemaConfig;
use GafferSearch\BuildContext;
use GafferSearch\Builders\IndexBuilderInterface;
use GafferSearch\IndexDefinition;
use GafferSearch\Services\HealthCheck;
use GafferSearch\Services\IndexRegistry;
use GafferSearch\Services\IndexService;
use GafferSearch\Services\SettingsService;
use GafferSearch\Tests\TestCase;
use GafferSearch\Watch;

/** The daily sample check: documents that changed without a hook are found and queued. */
final class HealthCheckTest extends TestCase
{
    private const string NAME = 'health';

    public function test_a_document_changed_without_a_hook_is_found_queued_and_reported(): void
    {
        DriftingBuilder::$renamed = false;
        self::register(new IndexDefinition(name: self::NAME, label: 'Health', description: ''), new DriftingBuilder());
        $queue   = new MemoryQueue();
        $service = new SampledIndexService(new SettingsService(), new IndexRegistry(), $queue);
        $check   = new HealthCheck($service, new IndexRegistry(), new SettingsService());
        try {
            self::assertNull(HealthCheck::drift(self::NAME), 'nothing before the first check');

            $service->build(self::NAME);
            self::assertSame(0, $check->checkDrift(self::NAME)['differing'], 'in step with its builder');

            // A write that fired no hook: the builder now produces another title for post 2.
            DriftingBuilder::$renamed = true;
            $result = $check->checkDrift(self::NAME);

            self::assertSame(1, $result['differing']);
            self::assertSame(3, $result['checked']);
            self::assertSame([['id' => 1, 'index' => self::NAME, 'post' => 2]], $queue->rows, 'put in the update log, as verify --fix does');
            self::assertSame(1, HealthCheck::drift(self::NAME)['differing'] ?? null);
        } finally {
            $service->deleteIndex(self::NAME);
        }
    }

    public function test_nothing_is_served_before_a_check(): void
    {
        self::assertNull(HealthCheck::served());
    }
}

/** IndexService with a fixed sample (the real one reads $wpdb). */
final class SampledIndexService extends IndexService
{
    public function sampleIds(string $name, int $count): array
    {
        return [1, 2, 3];
    }
}

final class DriftingBuilder implements IndexBuilderInterface
{
    public static bool $renamed = false;

    public function schema(BuildContext $context): SchemaConfig
    {
        return new SchemaConfig(filterableAttributes: ['brand'], sortableAttributes: [], searchableAttributes: ['title']);
    }

    public function documents(BuildContext $context): iterable
    {
        return [
            ['id' => 1, 'title' => 'One', 'brand' => 'a'],
            ['id' => 2, 'title' => self::$renamed ? 'Two, renamed in SQL' : 'Two', 'brand' => 'a'],
            ['id' => 3, 'title' => 'Three', 'brand' => 'b'],
        ];
    }

    public function documentsFor(BuildContext $context, int ...$ids): iterable
    {
        return array_values(array_filter([...$this->documents($context)], static fn(array $doc): bool => in_array($doc['id'], $ids, true)));
    }

    public function labels(BuildContext $context): array
    {
        return [];
    }

    public function watches(): Watch
    {
        return new Watch();
    }

    public function synonyms(): array
    {
        return [];
    }

    public function chunkSize(): int
    {
        return 100;
    }

    public function fieldForTaxonomy(string $taxonomy): ?string
    {
        return null;
    }

    public function config(): array
    {
        return [];
    }
}
