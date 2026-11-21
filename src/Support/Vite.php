<?php

declare(strict_types=1);

namespace GafferSearch\Support;

/** @internal Not part of the public API (see API.md): may change in any version. */
class Vite
{
    /** @var array<string, mixed> */
    protected static array $manifest;
    protected static string $pluginDir;
    protected static string $pluginUrl;

    public static function init(string $pluginFile): void
    {
        self::$pluginDir = plugin_dir_path($pluginFile);
        self::$pluginUrl = rtrim(plugin_dir_url($pluginFile), '/');
    }

    public static function url(string $asset): string
    {
        $manifest = self::manifest();

        return isset($manifest[$asset])
            ? self::$pluginUrl . '/assets/dist/' . $manifest[$asset]['file']
            : '';
    }

    public static function ver(string $asset): ?string
    {
        $path = self::path($asset);
        if ($path === null) {
            return null;
        }

        $mtime = filemtime($path);
        return $mtime !== false ? (string) $mtime : null;
    }

    /** @return string[] */
    public static function css(string $asset): array
    {
        $manifest = self::manifest();

        return array_map(
            fn(string $file): string => self::$pluginUrl . '/assets/dist/' . $file,
            $manifest[$asset]['css'] ?? [],
        );
    }

    private static function path(string $asset): ?string
    {
        $manifest = self::manifest();

        return isset($manifest[$asset])
            ? self::$pluginDir . 'assets/dist/' . $manifest[$asset]['file']
            : null;
    }

    /** @return array<string, mixed> */
    private static function manifest(): array
    {
        if (!isset(self::$manifest)) {
            $file     = self::$pluginDir . 'assets/dist/.vite/manifest.json';
            $contents = file_exists($file) ? file_get_contents($file) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local filesystem path, not a remote URL.

            self::$manifest = is_string($contents) && json_validate($contents)
                ? json_decode($contents, true)
                : [];
        }

        return self::$manifest;
    }
}
