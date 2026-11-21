<?php

declare(strict_types=1);

namespace GafferSearch\Tests\Unit;

use GafferSearch\Engine;
use GafferSearch\Services\IndexRegistry;
use GafferSearch\Tests\TestCase;

/** `language` (stemming) went with Fuzor 2's stemmers: a theme that still sets it keeps its index. */
final class EngineLanguageTest extends TestCase
{
    public function test_language_is_a_problem_with_the_fix_in_it(): void
    {
        self::assertSame(
            ['news: remove "language": text is indexed as written, without stemming (the index is used without it)'],
            (new Engine())->problems(['news' => ['type' => 'posts', 'language' => 'nl']]),
        );
    }

    public function test_a_definition_with_language_is_still_defined(): void
    {
        (new Engine())->define(['news' => ['type' => 'posts', 'language' => 'nl']]);

        self::assertNotNull((new IndexRegistry())->builder('news'), 'a plugin update before the theme\'s must not undefine its index');
    }
}
