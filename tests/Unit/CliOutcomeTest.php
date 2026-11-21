<?php

declare(strict_types=1);

namespace GafferSearch\Tests\Unit;

use GafferSearch\Cli\RebuildCommand;
use GafferSearch\Cli\StatusCommand;
use GafferSearch\Tests\TestCase;

final class CliOutcomeTest extends TestCase
{
    public function test_rebuild_exit_code_a_failure_wins_over_a_skip(): void
    {
        self::assertSame(0, RebuildCommand::exit_code([0, 0]));
        self::assertSame(2, RebuildCommand::exit_code([0, 2]));
        self::assertSame(1, RebuildCommand::exit_code([2, 1, 0]));
    }

    public function test_status_names_what_needs_attention(): void
    {
        $now     = 1_000_000;
        $healthy = ['built' => true, 'documents' => 5, 'building' => false, 'error' => null, 'pending' => 0, 'pending_since' => null];

        self::assertNull(StatusCommand::problem($healthy, $now));
        self::assertNull(StatusCommand::problem([...$healthy, 'pending' => 3, 'pending_since' => $now - 60], $now), 'changes from a minute ago: the job runs soon');
        self::assertStringContainsString('waiting since', (string) StatusCommand::problem([...$healthy, 'pending' => 3, 'pending_since' => $now - 3600], $now));
        self::assertNull(StatusCommand::problem([...$healthy, 'served' => []], $now), 'checked: nothing served');
        self::assertStringContainsString('uploads/gaffer-search', (string) StatusCommand::problem([...$healthy, 'served' => ['uploads/gaffer-search']], $now));
        self::assertSame('not built yet (building now)', StatusCommand::problem([...$healthy, 'built' => false, 'building' => true], $now));
        self::assertSame('boom', StatusCommand::problem([...$healthy, 'error' => 'boom'], $now));
    }
}
