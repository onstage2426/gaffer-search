<?php

declare(strict_types=1);

namespace GafferSearch\Tests\Unit;

use GafferSearch\Support\ReleaseAsset;
use GafferSearch\Tests\TestCase;

/** A source zipball has no vendor/: the updater offers only a release's built zip. */
final class ReleaseAssetTest extends TestCase
{
    public function test_only_the_built_zip_of_a_release_is_offered(): void
    {
        self::assertTrue(ReleaseAsset::isBuiltZip('https://github.com/onstage2426/gaffer-search/releases/download/v0.3.0/gaffer-search.zip'));
        self::assertFalse(ReleaseAsset::isBuiltZip('https://api.github.com/repos/onstage2426/gaffer-search/zipball/v0.3.0'), 'the source of a release without its asset');
        self::assertFalse(ReleaseAsset::isBuiltZip('https://github.com/onstage2426/gaffer-search/archive/refs/tags/v0.3.0.zip'));
        self::assertFalse(ReleaseAsset::isBuiltZip('https://github.com/onstage2426/gaffer-search/releases/download/v0.3.0/checksums.txt'), 'another asset');
    }

    public function test_a_release_without_its_zip_offers_nothing(): void
    {
        $reference = static fn(string $url): object => (object) ['downloadUrl' => $url];
        $strategies = static fn(string $url): array => ReleaseAsset::strategies([
            'latest_release' => static fn(): object => $reference($url),
            'latest_tag' => static fn(): object => $reference('https://github.com/onstage2426/gaffer-search/archive/refs/tags/v9.zip'),
        ]);

        self::assertSame(['latest_release'], array_keys($strategies('x')), 'never a tag or a branch');
        self::assertNull($strategies('https://api.github.com/repos/onstage2426/gaffer-search/zipball/v0.4.0')['latest_release']());
        self::assertIsObject($strategies('https://github.com/onstage2426/gaffer-search/releases/download/v0.4.0/gaffer-search.zip')['latest_release']());
        self::assertSame([], ReleaseAsset::strategies([]));
    }
}
