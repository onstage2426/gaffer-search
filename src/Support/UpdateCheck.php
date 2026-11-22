<?php

declare(strict_types=1);

namespace GafferSearch\Support;

/**
 * Whether the updater still finds the plugin's releases. A failed check (GitHub's rate limit, a
 * moved repository, a release without its built zip) looks to the updater like "no update", so
 * a site could stay on an old release for months with nobody told: `status()` reports when a
 * check last found the latest release (feature `update_checks`), and Gaffer's health line turns
 * orange when that's long ago.
 *
 * Network options, like the updater's own state: one updater serves a network. One option per
 * key (State's rule), never autoloaded (network options aren't, also on a single site).
 *
 * @internal
 */
final class UpdateCheck
{
    public const string SLUG = 'gaffer-search';

    /** Days without a successful check before it's worth saying (checks run every 12 to 72 hours). */
    public const int STALE_DAYS = 7;

    private const string OK    = 'gaffer_search_update_ok';
    private const string ERROR = 'gaffer_search_update_error';

    /** The updater's hooks: its result filter runs only when a check found an installable release. */
    public static function register(): void
    {
        add_filter('hu_request_update_result-' . self::SLUG, [self::class, 'found']);
        add_action('hu_api_error', [self::class, 'failed'], 10, 4);
    }

    /** The updater found the latest release and its built zip: passes the update through. */
    public static function found(mixed $update): mixed
    {
        update_site_option(self::OK, time());

        return $update;
    }

    /** One of the updater's API requests failed: the first error of a check is the cause, later ones its summary. */
    public static function failed(mixed $error, mixed $response, mixed $url, mixed $slug): void
    {
        if ($slug !== self::SLUG) {
            return;
        }
        $last = get_site_option(self::ERROR);
        if (is_array($last) && is_numeric($last['time'] ?? null) && time() - (int) $last['time'] < 5) {
            return;
        }
        $message = $error instanceof \WP_Error ? $error->get_error_message() : 'the request failed';
        update_site_option(self::ERROR, ['message' => $message, 'time' => time()]);
    }

    /**
     * When a check last found the latest release (Unix time); before the first one, when this
     * plugin version first ran (so a site whose checks never succeed still goes stale).
     */
    public static function checked(): int
    {
        $ok = get_site_option(self::OK);
        if (is_numeric($ok)) {
            return (int) $ok;
        }
        $now = time();
        update_site_option(self::OK, $now);

        return $now;
    }

    /** The last failed check's error, when it came after the last successful one. */
    public static function error(): ?string
    {
        $error = get_site_option(self::ERROR);
        if (!is_array($error) || !is_string($error['message'] ?? null) || !is_numeric($error['time'] ?? null)) {
            return null;
        }

        return (int) $error['time'] > self::checked() ? $error['message'] : null;
    }

    /** @return list<string> */
    public static function options(): array
    {
        return [self::OK, self::ERROR];
    }
}
