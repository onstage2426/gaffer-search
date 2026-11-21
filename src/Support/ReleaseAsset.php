<?php

declare(strict_types=1);

namespace GafferSearch\Support;

/**
 * Which update the updater may offer: only a release's built zip (`gaffer-search.zip`, with
 * `vendor/` and the generated updater). The updater would fall back to the release's source
 * zipball when the asset is missing (a release made by hand, an upload that hasn't finished),
 * and that one has no `vendor/`: the plugin's first line would then fail on every request, the
 * whole site down. Without the asset the check offers nothing, and the next check tries again.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class ReleaseAsset
{
    /** The file the release workflow attaches (`.github/workflows/release.yml`). */
    public const string NAME = 'gaffer-search.zip';

    /**
     * The updater's detection strategies (filter `hu_vcs_update_detection_strategies-gaffer-search`):
     * only the latest release, and only when its download is the built zip.
     *
     * @param array<mixed> $strategies
     * @return array<string, callable(): mixed>
     */
    public static function strategies(array $strategies): array
    {
        $latest = $strategies['latest_release'] ?? null;
        if (!is_callable($latest)) {
            return [];
        }

        $release = static function () use ($latest): mixed {
            $reference = $latest();

            return is_object($reference) && self::isBuiltZip(is_string($reference->downloadUrl ?? null) ? $reference->downloadUrl : '') ? $reference : null;
        };

        return ['latest_release' => $release];
    }

    /** Whether a download URL is a release's built zip, not a source archive. */
    public static function isBuiltZip(string $url): bool
    {
        return preg_match('#^https://github\.com/[^/]+/[^/]+/releases/download/[^/]+/' . preg_quote(self::NAME, '#') . '$#', $url) === 1;
    }
}
