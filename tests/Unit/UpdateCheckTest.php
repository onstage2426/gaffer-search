<?php

declare(strict_types=1);

namespace GafferSearch\Tests\Unit;

use GafferSearch\Support\UpdateCheck;
use GafferSearch\Tests\TestCase;
use GafferSearchTestWordPress;

/** Whether the updater still finds the releases: a failed check otherwise looks like "no update". */
final class UpdateCheckTest extends TestCase
{
    public function test_before_any_check_the_clock_starts_when_the_plugin_first_ran(): void
    {
        $now = time();
        self::assertGreaterThanOrEqual($now, UpdateCheck::checked());
        self::assertSame(UpdateCheck::checked(), UpdateCheck::checked(), 'kept, not moved on every call');
        self::assertNull(UpdateCheck::error());
    }

    public function test_a_failure_after_the_last_success_is_reported_until_the_next_success(): void
    {
        update_site_option('gaffer_search_update_ok', time() - 86400);
        UpdateCheck::failed(null, null, 'https://api.github.com/x', 'gaffer-search');
        self::assertSame('the request failed', UpdateCheck::error());

        $update = (object) ['version' => '0.4.0'];
        self::assertSame($update, UpdateCheck::found($update), 'the update passes through');
        update_site_option('gaffer_search_update_error', ['message' => 'old', 'time' => time() - 10]);
        self::assertNull(UpdateCheck::error());
        self::assertGreaterThanOrEqual(time() - 1, UpdateCheck::checked());
    }

    public function test_another_plugins_updater_errors_dont_count(): void
    {
        UpdateCheck::failed(null, null, 'https://api.github.com/x', 'another-plugin');
        self::assertArrayNotHasKey('gaffer_search_update_error', GafferSearchTestWordPress::$options);
    }

    public function test_nothing_is_autoloaded(): void
    {
        UpdateCheck::checked();
        UpdateCheck::failed(null, null, '', 'gaffer-search');
        foreach (UpdateCheck::options() as $option) {
            self::assertFalse(GafferSearchTestWordPress::$autoload[$option] ?? true, $option);
        }
    }
}
