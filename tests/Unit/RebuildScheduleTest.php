<?php

declare(strict_types=1);

namespace GafferSearch\Tests\Unit;

use GafferSearch\Bootstrap;
use GafferSearch\Tests\TestCase;
use GafferSearchTestWordPress;

/** The periodic rebuild's schedule (Bootstrap::syncCronEvent()). */
final class RebuildScheduleTest extends TestCase
{
    public function test_a_built_index_is_first_rebuilt_one_interval_from_now(): void
    {
        $before = time();
        $this->sync('daily', built: true);

        self::assertGreaterThanOrEqual($before + 86400, GafferSearchTestWordPress::$events['hook']['time'], 'reactivating the plugin must not rebuild a built index');
    }

    public function test_an_unbuilt_index_is_built_right_away(): void
    {
        $this->sync('daily', built: false);

        self::assertLessThanOrEqual(time(), GafferSearchTestWordPress::$events['hook']['time']);
    }

    public function test_an_unchanged_schedule_keeps_its_time_and_another_interval_starts_over(): void
    {
        GafferSearchTestWordPress::$events['hook'] = ['time' => 123, 'interval' => 'daily'];
        $this->sync('daily', built: true);
        self::assertSame(123, GafferSearchTestWordPress::$events['hook']['time']);

        $this->sync('hourly', built: true);
        self::assertSame('hourly', GafferSearchTestWordPress::$events['hook']['interval']);
        self::assertGreaterThan(time() + 3000, GafferSearchTestWordPress::$events['hook']['time']);

        $this->sync('', built: true);
        self::assertArrayNotHasKey('hook', GafferSearchTestWordPress::$events, 'no interval: no schedule');
    }

    private function sync(string $interval, bool $built): void
    {
        $bootstrap = (new \ReflectionClass(Bootstrap::class))->newInstanceWithoutConstructor();
        (new \ReflectionMethod($bootstrap, 'syncCronEvent'))->invoke($bootstrap, 'hook', $interval, $built);
    }
}
