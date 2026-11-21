<?php

declare(strict_types=1);

namespace GafferSearch\Support\Language;

use GafferSearch\Language\LanguageAdapter;

/**
 * The current site's language adapter: WPML's when WPML is active on it, otherwise none —
 * or whatever the `gaffer_search_language_adapter` filter returns (another language plugin). Resolved
 * once per site per request.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class Languages
{
    /** @var array<int, LanguageAdapter> site ID → adapter */
    private static array $adapters = [];

    /** @internal Tests: the adapter of the current site. */
    public static function set(LanguageAdapter $adapter): void
    {
        self::$adapters[get_current_blog_id()] = $adapter;
    }

    public static function adapter(): LanguageAdapter
    {
        $site = get_current_blog_id();
        if (!isset(self::$adapters[$site])) {
            self::$adapters[$site] = self::wpmlActive() ? new WpmlLanguages() : new NoLanguages();
        }
        return self::$adapters[$site];
    }

    /**
     * The languages an index has a file for: the site's language codes, or [null] — one file,
     * no language — on a site without languages.
     *
     * @return list<?string>
     */
    public static function codes(): array
    {
        $languages = self::adapter()->languages();
        return $languages === [] ? [null] : $languages;
    }

    /**
     * The language whose file a request reads: the current language, or the site's first
     * language when the plugin reports none or one it doesn't list (WPML's "all"); null on a
     * site without languages.
     */
    public static function current(): ?string
    {
        $languages = self::adapter()->languages();
        if ($languages === []) {
            return null;
        }
        $current = self::adapter()->current();
        return in_array($current, $languages, true) ? $current : $languages[0];
    }

    /** For tests and long-running processes: resolve again on the next call. */
    public static function reset(): void
    {
        self::$adapters = [];
    }

    /**
     * WPML loaded *and* active on the current site: on a network, WPML stays loaded when code
     * switches to a site that doesn't run it.
     */
    private static function wpmlActive(): bool
    {
        if (!defined('ICL_SITEPRESS_VERSION')) {
            return false;
        }
        $plugin = 'sitepress-multilingual-cms/sitepress.php';
        return in_array($plugin, (array) get_option('active_plugins', []), true)
            || (is_multisite() && isset(( (array) get_site_option('active_sitewide_plugins', []))[$plugin]));
    }
}
