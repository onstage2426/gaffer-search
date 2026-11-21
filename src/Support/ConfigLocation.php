<?php

declare(strict_types=1);

namespace GafferSearch\Support;

/**
 * Where the generated endpoint configs live, resolved the same way by both sides. There is one
 * file per site, `{config dir}/sites/{site ID}.php` (a single site is site 1), because every
 * site of a network has its own secrets, index folder and indexes:
 *
 *  - WordPress writes the current site's file under forContentDir(WP_CONTENT_DIR):
 *    {WP_CONTENT_DIR}/gaffer-search/sites/{id}.php.
 *  - The endpoints, which load no WordPress and so have no constants, try candidates(): the
 *    folder that contains the plugins folder, derived from their own path, with the site ID
 *    the request's token names.
 *
 * Both honor the GAFFER_SEARCH_CONFIG_DIR environment variable first — an explicit config folder
 * for layouts the derivation can't see (WP_PLUGIN_DIR outside WP_CONTENT_DIR, or a symlinked
 * plugin folder served through nginx's $realpath_root).
 *
 * The files live outside the plugin folder so replacing the plugin (a manual update) can't
 * delete them, and outside uploads/ so a server that serves .php files there as plain text
 * can't expose a token secret.
 *
 * No WordPress functions here — the endpoints use this before anything else is loaded.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class ConfigLocation
{
    public const string ENV      = 'GAFFER_SEARCH_CONFIG_DIR';
    public const string RELATIVE = 'gaffer-search';

    /** The config folder set through the GAFFER_SEARCH_CONFIG_DIR environment variable, if any. */
    public static function fromEnvironment(): ?string
    {
        foreach ([getenv(self::ENV), $_SERVER[self::ENV] ?? null, $_ENV[self::ENV] ?? null] as $value) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- a server-set environment variable, not request input; no WordPress (wp_unslash) is loaded here
            if (is_string($value) && $value !== '') {
                return rtrim($value, '/\\');
            }
        }
        return null;
    }

    /** The config folder WordPress writes to. */
    public static function dirForContentDir(string $contentDir): string
    {
        return self::fromEnvironment() ?? rtrim($contentDir, '/\\') . '/' . self::RELATIVE;
    }

    /** The file WordPress writes for one site. */
    public static function forContentDir(string $contentDir, int $site): string
    {
        return self::file(self::dirForContentDir($contentDir), $site);
    }

    /**
     * Paths the endpoints try for a site, in order. With GAFFER_SEARCH_CONFIG_DIR set that is the
     * only one — an explicit location is never second-guessed. Otherwise the content folder is
     * derived as the parent of the plugins folder, first from SCRIPT_FILENAME (the path as the
     * web server sees it, which keeps symlinks), then from the endpoint's own __DIR__ (which PHP
     * resolves through symlinks).
     *
     * @param  string      $endpointDir    The endpoints/ directory (__DIR__ in an endpoint).
     * @param  string|null $scriptFilename $_SERVER['SCRIPT_FILENAME'].
     * @return list<string>
     */
    public static function candidates(string $endpointDir, ?string $scriptFilename, int $site): array
    {
        $env = self::fromEnvironment();
        if ($env !== null) {
            return [self::file($env, $site)];
        }

        $candidates = [];
        if ($scriptFilename !== null && $scriptFilename !== '' && basename(dirname($scriptFilename)) === basename($endpointDir)) {
            $candidates[] = self::file(dirname($scriptFilename, 4) . '/' . self::RELATIVE, $site);
        }
        $candidates[] = self::file(dirname($endpointDir, 3) . '/' . self::RELATIVE, $site);

        return array_values(array_unique($candidates));
    }

    /**
     * Load one site's config for the endpoints, or null when no candidate exists.
     *
     * @return array<string, mixed>|null
     */
    public static function load(string $endpointDir, ?string $scriptFilename, int $site): ?array
    {
        foreach (self::candidates($endpointDir, $scriptFilename, $site) as $path) {
            if (is_file($path)) {
                return self::read($path);
            }
        }
        return null;
    }

    /**
     * One config file, with its `index_dir` absolute again (see indexDirFor()).
     *
     * @return array<string, mixed>|null
     */
    public static function read(string $path): ?array
    {
        $config = require $path;
        if (!is_array($config)) {
            return null;
        }
        $dir = $config['index_dir'] ?? null;
        if (is_string($dir) && $dir !== '' && !str_starts_with($dir, '/') && !preg_match('#^[A-Za-z]:[\\\\/]#', $dir)) {
            $config['index_dir'] = dirname($path) . '/' . $dir;
        }
        return $config;
    }

    /**
     * The index folder as a config file stores it: relative to the file's folder when both are
     * inside the content folder (the default, uploads/gaffer-search), else as it is (GAFFER_SEARCH_INDEX_DIR).
     * The same WordPress seen through different absolute paths (a web server in a container and
     * a CLI on the host) then writes the same file, instead of each rewriting it with paths the
     * other can't open.
     */
    public static function indexDirFor(string $configFile, string $indexDir, string $contentDir): string
    {
        $content = rtrim($contentDir, '/\\') . '/';
        $from    = dirname($configFile) . '/';
        if (!str_starts_with($indexDir . '/', $content) || !str_starts_with($from, $content)) {
            return $indexDir;
        }
        return str_repeat('../', substr_count(substr($from, strlen($content)), '/')) . substr($indexDir, strlen($content));
    }

    private static function file(string $dir, int $site): string
    {
        return "{$dir}/sites/{$site}.php";
    }
}
