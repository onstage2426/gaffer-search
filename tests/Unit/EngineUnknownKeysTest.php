<?php

declare(strict_types=1);

namespace GafferSearch\Tests\Unit;

use GafferSearch\Engine;
use GafferSearch\Services\IndexRegistry;
use GafferSearch\Tests\TestCase;

/** Contract rule 3: a key this engine doesn't know (a newer Gaffer's) is ignored, never undefines. */
final class EngineUnknownKeysTest extends TestCase
{
    public function test_an_unknown_key_is_a_problem_but_the_index_is_defined(): void
    {
        $definition = ['news' => ['type' => 'posts', 'grouping' => ['by' => 'brand']]];

        self::assertSame(['news: unknown key "grouping"'], (new Engine())->problems($definition));
        (new Engine())->define($definition);
        self::assertNotNull((new IndexRegistry())->builder('news'));
    }

    public function test_a_definition_without_a_known_type_is_still_refused(): void
    {
        (new Engine())->define(['nope' => ['type' => 'pages']]);

        self::assertNull((new IndexRegistry())->builder('nope'));
    }
}
