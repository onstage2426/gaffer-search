<?php

declare(strict_types=1);

namespace GafferSearch\Tests\Unit;

use Fuzor\Index;
use GafferSearch\IndexDefinition;
use GafferSearch\Services\IndexBusyException;
use GafferSearch\Services\IndexRegistry;
use GafferSearch\Services\IndexService;
use GafferSearch\Services\SettingsService;
use GafferSearch\Support\SharedLock;
use GafferSearch\Tests\FixtureBuilder;
use GafferSearch\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/** Full rebuilds across web servers: one builds and publishes, the others are refused. */
final class SharedLockTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    public function test_get_lock_decides(): void
    {
        $wpdb = self::database('1');
        self::assertTrue(SharedLock::acquire('build|1|products'));
        self::assertMatchesRegularExpression("/^SELECT GET_LOCK\\('gaffer_search_[0-9a-f]{32}', 0\\)$/", $wpdb->queries[0]);
        SharedLock::release('build|1|products');
        self::assertStringStartsWith('SELECT RELEASE_LOCK(', $wpdb->queries[1]);

        self::database('0');
        self::assertFalse(SharedLock::acquire('build|1|products'), 'held by another connection');

        self::database(null);
        self::assertTrue(SharedLock::acquire('build|1|products'), 'no GET_LOCK(): build as without the lock');
    }

    #[RunInSeparateProcess]
    public function test_a_rebuild_held_on_another_server_is_refused_and_one_that_runs_publishes(): void
    {
        define('GAFFER_SEARCH_SNAPSHOT_DIR', self::dir() . '/snapshots');
        self::register(new IndexDefinition(name: 'shared', label: 'Shared', description: ''), new FixtureBuilder());
        $service = new IndexService(new SettingsService(), new IndexRegistry());

        self::database('0');
        try {
            $service->build('shared');
            self::fail('A second server built.');
        } catch (IndexBusyException $e) {
            self::assertTrue($e->elsewhere);
            self::assertFalse($service->isBuilt('shared'), 'refused before it built anything');
        }

        self::database('1');
        $service->build('shared');
        $snapshot = self::dir() . '/snapshots/1/' . $service->fileSlug('shared', null) . '.g1.db';
        self::assertSame(12, (new Index($snapshot, readonly: true))->count());
        self::assertSame(1, get_option('gaffer_search_snapshot_shared')['generation']);
    }

    /** A $wpdb whose GET_LOCK() answers $lock ('1' taken, '0' held elsewhere, null no such function). */
    private static function database(?string $lock): object
    {
        return $GLOBALS['wpdb'] = new class ($lock) {
            public string $prefix      = 'wp_';
            public string $base_prefix = 'wp_';
            /** @var list<string> */
            public array $queries = [];

            public function __construct(private readonly ?string $lock) {}

            public function prepare(string $query, mixed ...$args): string
            {
                return vsprintf(str_replace('%s', "'%s'", $query), $args);
            }

            public function get_var(string $query): mixed
            {
                $this->queries[] = $query;
                return str_contains($query, 'GET_LOCK') ? $this->lock : 0;
            }

            public function query(string $query): int
            {
                $this->queries[] = $query;
                return 1;
            }
        };
    }
}
