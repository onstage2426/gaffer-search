<?php

declare(strict_types=1);

namespace GafferSearch\Tests\Unit;

use GafferSearch\Bootstrap;
use GafferSearch\Services\ChangeTracker;
use GafferSearch\Services\HealthCheck;
use GafferSearch\Tests\TestCase;
use GafferSearchTestWordPress;

final class DeactivateTest extends TestCase
{
    public function test_deactivating_on_a_single_site_clears_its_jobs_without_switching_sites(): void
    {
        update_option('gaffer_search_known_indexes', ['products', 'posts']);

        Bootstrap::deactivate();

        self::assertSame([
            'gaffer_search_cron_rebuild_products',
            'gaffer_search_cron_rebuild_posts',
            ChangeTracker::REBUILD_HOOK,
            ChangeTracker::UPDATE_HOOK,
            ChangeTracker::ENSURE_HOOK,
            HealthCheck::HOOK,
        ], GafferSearchTestWordPress::$unscheduled);
    }
}
