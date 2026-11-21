<?php

declare(strict_types=1);

namespace GafferSearch\Tests\Unit;

use GafferSearch\Controllers\Admin\NetworkController;
use GafferSearch\Support\IndexSlug;
use GafferSearch\Tests\TestCase;

/** The network page's row per index, from a site's endpoint config. */
final class NetworkControllerTest extends TestCase
{
    public function test_an_index_with_a_file_per_language_sums_its_files(): void
    {
        $slug    = IndexSlug::compute(self::INDEX, self::SEARCH_SECRET);
        $summary = NetworkController::summary(self::dir(), ['nl' => $slug, 'en' => $slug, 'de' => 'never-built']);

        self::assertTrue($summary['built']);
        self::assertSame(24, $summary['documents'], 'both language entries point at the 12-document fixture');
        self::assertSame(filemtime(self::indexPath()), $summary['last_modified']);
    }

    public function test_a_format_1_config_has_one_slug_per_index(): void
    {
        $summary = NetworkController::summary(self::dir(), IndexSlug::compute(self::INDEX, self::SEARCH_SECRET));

        self::assertTrue($summary['built']);
        self::assertSame(12, $summary['documents']);
    }

    public function test_an_unbuilt_index_has_no_numbers(): void
    {
        self::assertSame(
            ['built' => false, 'documents' => null, 'last_modified' => null],
            NetworkController::summary(self::dir(), ['' => 'never-built']),
        );
    }
}
