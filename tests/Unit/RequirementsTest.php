<?php

declare(strict_types=1);

namespace GafferSearch\Tests\Unit;

use GafferSearch\Support\Requirements;
use GafferSearch\Tests\TestCase;

/** An old SQLite fails on Fuzor's STRICT tables with a bare syntax error: it is reported by name instead. */
final class RequirementsTest extends TestCase
{
    public function test_an_sqlite_older_than_fuzors_minimum_is_reported_with_its_version(): void
    {
        $problems = Requirements::problemsFor('3.37.2');
        self::assertCount(1, $problems);
        self::assertStringContainsString('SQLite ' . Requirements::SQLITE_MIN . ' or newer', $problems[0]);
        self::assertStringContainsString('uses SQLite 3.37.2', $problems[0]);
    }

    public function test_the_minimum_and_newer_versions_pass(): void
    {
        self::assertSame([], Requirements::problemsFor(Requirements::SQLITE_MIN));
        self::assertSame([], Requirements::problemsFor('3.50.4'));
    }

    public function test_a_php_without_pdo_sqlite_is_reported(): void
    {
        self::assertStringContainsString('pdo_sqlite', Requirements::problemsFor(null)[0]);
    }

    public function test_the_php_running_the_tests_meets_them(): void
    {
        self::assertSame([], Requirements::problems());
        Requirements::assertMet();
    }
}
