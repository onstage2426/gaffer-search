<?php

declare(strict_types=1);

namespace GafferSearch\Tests\Unit;

use GafferSearch\Builders\ContentIndexBuilder;
use GafferSearch\Engine;
use GafferSearch\IndexDefinition;
use GafferSearch\Services\ChangeTracker;
use GafferSearch\Services\IndexRegistry;
use GafferSearch\Services\IndexService;
use GafferSearch\Services\SettingsService;
use GafferSearch\Services\UpdateQueue;
use GafferSearch\Support\Language\Languages;
use GafferSearch\Tests\FixtureBuilder;
use GafferSearch\Tests\TestCase;
use GafferSearchTestWordPress;

/** When an index is built in full, and how a server's position in the update log moves. */
final class IndexLifecycleTest extends TestCase
{
    private const string NAME = 'lifecycle';

    private MemoryQueue $queue;
    private IndexService $service;

    protected function setUp(): void
    {
        parent::setUp();
        self::register(new IndexDefinition(name: self::NAME, label: 'Lifecycle', description: ''), new FixtureBuilder());
        $this->queue   = new MemoryQueue();
        $this->service = new IndexService(new SettingsService(), new IndexRegistry(), $this->queue);
    }

    protected function tearDown(): void
    {
        $this->service->deleteIndex(self::NAME);
        Languages::reset();
    }

    public function test_an_index_that_is_not_built_needs_a_build_unless_its_build_just_failed(): void
    {
        self::assertSame([self::NAME], $this->service->needsBuild(), 'a new index');

        set_transient('gaffer_search_cron_error_' . self::NAME, ['message' => 'boom', 'time' => time() - 60]);
        self::assertSame([], $this->service->needsBuild(), 'no build on every request while it fails');
        self::assertSame([self::NAME], $this->service->needsBuild(backOff: false), 'the job itself asks without the back-off');
        set_transient('gaffer_search_cron_error_' . self::NAME, ['message' => 'boom', 'time' => time() - 2 * HOUR_IN_SECONDS]);
        self::assertSame([self::NAME], $this->service->needsBuild(), 'tried again an hour later');

        $this->service->build(self::NAME);
        self::assertSame([], $this->service->needsBuild());

        unlink($this->service->pathFor(self::NAME, null));
        self::assertSame([self::NAME], $this->service->needsBuild(), 'its file deleted');
    }

    public function test_a_language_added_needs_a_build(): void
    {
        Languages::set(new TwoLanguages());
        $this->service->build(self::NAME);
        self::assertSame([], $this->service->needsBuild());

        \Fuzor\Index::remove($this->service->pathFor(self::NAME, 'en'));
        self::assertSame([self::NAME], $this->service->needsBuild(), 'one language without its file');
    }

    public function test_a_moved_site_or_new_permalinks_rebuild(): void
    {
        update_option('home', 'https://staging.example');
        $this->service->build(self::NAME);
        self::assertSame([], $this->service->needsBuild());

        update_option('home', 'https://example.com');
        self::assertSame([self::NAME], $this->service->needsBuild(), 'documents hold the old URLs');
        $this->service->build(self::NAME);

        update_option('permalink_structure', '/%postname%/');
        self::assertSame([self::NAME], $this->service->needsBuild());
    }

    public function test_a_rebuild_requested_before_a_build_started_is_done_by_it(): void
    {
        $before = microtime(true);
        usleep(1000);
        $this->service->build(self::NAME);
        usleep(1000);

        self::assertTrue($this->service->satisfiedBy(self::NAME, $before));
        self::assertFalse($this->service->satisfiedBy(self::NAME, microtime(true)), 'a request after the build started (a change it may not have read)');
    }

    public function test_a_quiet_index_moves_to_the_head_and_stays_ahead_of_the_pruning(): void
    {
        $this->queue->add('other', 1, 2, 3);
        $this->service->build(self::NAME);
        self::assertSame(3, $this->state()['position']);

        $this->queue->add('other', 4, 5, 6);
        $result = $this->service->applyUpdates(self::NAME);
        self::assertSame(6, $this->state()['position'], 'nothing for this index up to the head');
        self::assertFalse($result['rebuilt']);

        $this->queue->prunedTo = 5;
        self::assertFalse($this->service->applyUpdates(self::NAME)['rebuilt'], 'a day without changes is no full rebuild');
    }

    public function test_the_rows_of_the_index_are_applied_and_the_position_moves_to_the_last(): void
    {
        $this->service->build(self::NAME);
        $this->queue->add(self::NAME, 1, 2);
        $this->queue->add('other', 7);

        $result = $this->service->applyUpdates(self::NAME);

        self::assertSame([2, 0, false], [$result['updated'], $result['deleted'], $result['rebuilt']]);
        self::assertSame(2, $this->state()['position'], 'the last row of this index');
        $this->service->applyUpdates(self::NAME);
        self::assertSame(3, $this->state()['position'], 'then the head');
    }

    public function test_a_position_in_another_log_rebuilds(): void
    {
        $this->service->build(self::NAME);
        self::assertSame('log-a', $this->state()['log']);

        // The database was copied from another environment: its log has another ID.
        $this->queue->logId = 'log-b';
        self::assertTrue($this->service->applyUpdates(self::NAME)['rebuilt']);
        self::assertSame('log-b', $this->state()['log']);
        self::assertSame(['count' => 0, 'since' => null], $this->service->pendingUpdates(self::NAME));
    }

    public function test_a_position_past_the_head_rebuilds(): void
    {
        $this->queue->add('other', 1, 2, 3);
        $this->service->build(self::NAME);
        $this->queue->rows = [];
        $this->queue->next = 1;

        self::assertTrue($this->service->applyUpdates(self::NAME)['rebuilt'], 'a shorter log than the position: another one');
    }

    public function test_a_state_from_before_log_ids_is_trusted_once_and_gets_the_id(): void
    {
        $this->service->build(self::NAME);
        $path = (new \ReflectionMethod($this->service, 'statePath'))->invoke($this->service, self::NAME);
        file_put_contents($path, (string) json_encode(['position' => 0, 'generation' => 0]));

        self::assertFalse($this->service->applyUpdates(self::NAME)['rebuilt'], 'every server of a site would rebuild at once after the update');
        self::assertSame('log-a', $this->state()['log']);
    }

    public function test_a_renamed_term_re_renders_the_posts_that_have_it_or_a_descendant_when_cards_may_show_it(): void
    {
        GafferSearchTestWordPress::$hierarchical = ['product_cat'];
        GafferSearchTestWordPress::$terms[5]     = new \WP_Term(5, 'product_cat', 'Jurken', 'jurken');
        GafferSearchTestWordPress::$parents      = [6 => 5];
        GafferSearchTestWordPress::$postTerms    = [1 => ['product_cat' => [5]], 2 => ['product_cat' => [6]], 3 => ['product_cat' => [7]]];
        $builder = new ContentIndexBuilder(taxonomy: ['product_cat' => 'category']);

        self::register(new IndexDefinition(name: 'plain', label: 'Plain', description: ''), $builder);
        self::register(new IndexDefinition(name: 'cards', label: 'Cards', description: '', document: static fn(array $doc): array => $doc), $builder);
        $tracker = new ChangeTracker(new IndexRegistry(), $this->queue);

        (new \ReflectionMethod($tracker, 'beforeTermEdit'))->invoke($tracker, 5, 'product_cat');
        GafferSearchTestWordPress::$terms[5]->name = 'Trouwjurken';
        (new \ReflectionMethod($tracker, 'onTermEdited'))->invoke($tracker, 5, 'product_cat');
        $ids = array_map(array_keys(...), (new \ReflectionProperty($tracker, 'ids'))->getValue($tracker));

        self::assertSame([UpdateQueue::LABELS], $ids['plain'], 'documents hold slugs: only the names');
        self::assertEqualsCanonicalizing([1, 2, UpdateQueue::LABELS], $ids['cards'], 'a card may show the name, a descendant\'s its ancestors\'');
    }

    public function test_a_definitions_problems_are_logged_once_per_change(): void
    {
        $log      = self::dir() . '/define.log';
        $previous = ini_set('error_log', $log);
        try {
            (new Engine())->define(['broken' => ['type' => 'products', 'facets' => 'brand']]);
            (new Engine())->define(['broken' => ['type' => 'products', 'facets' => 'brand']]);
            self::assertCount(1, file($log) ?: [], 'not on every request');

            (new Engine())->define(['broken' => ['type' => 'products', 'facets' => 'brand'], 'also' => ['type' => 'pages']]);
            self::assertCount(3, file($log) ?: [], 'a change is logged');
            self::assertFalse(GafferSearchTestWordPress::$autoload['gaffer_search_define_logged'] ?? true);
        } finally {
            ini_set('error_log', (string) $previous);
            @unlink($log);
        }
    }

    /** @return array{position: int, log: ?string} */
    private function state(): array
    {
        $state = (new \ReflectionMethod($this->service, 'readState'))->invoke($this->service, self::NAME);
        self::assertIsArray($state);
        return $state;
    }
}

/** The update log in memory: rows (id, index, post), an ID, the pruned position. */
final class MemoryQueue extends UpdateQueue
{
    /** @var list<array{id: int, index: string, post: int}> */
    public array $rows = [];
    public int $next = 1;
    public int $prunedTo = 0;
    public string $logId = 'log-a';

    public function id(): string
    {
        return $this->logId;
    }

    public function add(string $index, int ...$ids): bool
    {
        foreach ($ids as $id) {
            $this->rows[] = ['id' => $this->next++, 'index' => $index, 'post' => $id];
        }
        return true;
    }

    public function head(): int
    {
        return max([$this->prunedTo, ...array_column($this->rows, 'id')]);
    }

    public function pruned(): int
    {
        return $this->prunedTo;
    }

    public function after(string $index, int $position, int $limit): array
    {
        $rows = array_slice(array_values(array_filter($this->rows, static fn(array $row): bool => $row['index'] === $index && $row['id'] > $position)), 0, $limit);
        return ['ids' => array_values(array_unique(array_column($rows, 'post'))), 'last' => $rows === [] ? $position : $rows[count($rows) - 1]['id']];
    }

    public function pendingAfter(string $index, int $position): array
    {
        $count = count($this->after($index, $position, PHP_INT_MAX)['ids']);
        return ['count' => $count, 'since' => $count > 0 ? time() : null];
    }
}
