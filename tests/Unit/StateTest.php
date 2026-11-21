<?php

declare(strict_types=1);

namespace GafferSearch\Tests\Unit;

use GafferSearch\Services\State;
use GafferSearch\Tests\TestCase;
use GafferSearchTestWordPress;

/** What the plugin keeps per index: options, never autoloaded, and no transients. */
final class StateTest extends TestCase
{
    public function test_a_build_is_running_while_its_heartbeat_is_recent(): void
    {
        self::assertFalse(State::building('products'));
        State::heartbeat('products', 200);
        self::assertTrue(State::building('products'));

        // A build killed five minutes ago (out of memory): no longer running.
        update_option('gaffer_search_building_products', ['done' => 200, 'time' => time() - State::BUILDING_STALE - 1]);
        self::assertFalse(State::building('products'));

        State::heartbeat('products', 300);
        State::stopBuilding('products');
        self::assertFalse(State::building('products'));
    }

    public function test_an_error_stays_until_cleared(): void
    {
        self::assertNull(State::error('products'));
        State::setError('products', 'boom');
        self::assertSame('boom', State::error('products')['message'] ?? null);
        State::clearError('products');
        self::assertNull(State::error('products'));
    }

    public function test_nothing_is_autoloaded_and_forget_removes_an_index(): void
    {
        State::recordBuilt('products', 'sig', 'v1', 1.0, 2.0);
        State::setError('products', 'boom');
        State::heartbeat('products', 1);
        State::request('products');
        State::setDrift('products', ['differing' => 0, 'checked' => 10, 'time' => time()]);
        State::setServed([]);

        foreach (['built', 'error', 'building', 'rebuild_requested', 'drift'] as $key) {
            self::assertFalse(GafferSearchTestWordPress::$autoload["gaffer_search_{$key}_products"] ?? true, $key);
        }
        self::assertFalse(GafferSearchTestWordPress::$autoload['gaffer_search_served'] ?? true);

        State::forget('products');
        self::assertSame([], State::built('products'));
        self::assertNull(State::error('products'));
        self::assertNull(State::requested('products'));
        self::assertNull(State::drift('products'));
        self::assertSame([], State::served(), 'per site: not an index\'s');
    }
}
