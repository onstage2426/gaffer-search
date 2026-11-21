<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/gaffer-search.php',
        __DIR__ . '/endpoints',
    ])
    ->withPhpSets(php85: true)
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
        SetList::EARLY_RETURN,
    ])
    ->withSkip([
        // IndexService::noGeneratorRegistered() intentionally contains an unreachable `yield`
        // to make PHP treat it as a generator function so it returns a lazy Generator object.
        \Rector\DeadCode\Rector\Stmt\RemoveUnreachableStatementRector::class => [
            __DIR__ . '/src/Services/IndexService.php',
        ],
    ]);
