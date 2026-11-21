<?php

declare(strict_types=1);

namespace GafferSearch\Tests\Unit;

use GafferSearch\Services\IndexRegistry;
use GafferSearch\Services\SearchConfig;
use GafferSearch\Services\SettingsService;
use GafferSearch\Tests\TestCase;

final class SearchConfigTest extends TestCase
{
    public function test_a_request_without_definitions_keeps_the_endpoints_indexes(): void
    {
        if (!defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', self::dir() . '/content');
        }
        self::registerIndex();
        $config = new SearchConfig(new SettingsService(), new IndexRegistry());
        self::assertTrue($config->write());
        $indexes = (require $config->path())['indexes'];
        self::assertArrayHasKey('products', $indexes);

        // The live theme, before its switch, defines nothing: the staged copy's index stays served.
        IndexRegistry::reset();
        $live = new SearchConfig(new SettingsService(), new IndexRegistry());
        self::assertTrue($live->isCurrent(), 'nothing to rewrite');
        self::assertTrue($live->write());
        self::assertSame($indexes, (require $live->path())['indexes']);
    }
}
