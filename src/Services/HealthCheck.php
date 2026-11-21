<?php

declare(strict_types=1);

namespace GafferSearch\Services;

use GafferSearch\Support\ConfigLocation;

/**
 * A daily check of what nothing else notices (WP-cron job HOOK, per site):
 *
 *  - **drift:** a sample of each index's documents (IndexService::sampleIds()) compared with
 *    what the builder produces now (verifyIds()). A write that fires no hook (direct SQL, an
 *    import that bypasses WordPress) leaves the index outdated without a log row; the documents
 *    found go into the update log, as `verify --fix` does, and their number is reported.
 *  - **served files:** a probe file in each folder the plugin protects (index files, endpoint
 *    configs), fetched over HTTP like a visitor. The deny `.htaccess` only covers
 *    Apache and LiteSpeed: on nginx without its rule an index (hidden documents too) downloads.
 *
 * Results are options (not autoloaded), read by Engine::status() (feature `health_checks`).
 *
 * @internal
 */
final class HealthCheck
{
    public const string HOOK = 'gaffer_search_health_check';

    /** How many documents per index a check compares. */
    public const int SAMPLE = 60;

    private const string DRIFT_OPTION  = 'gaffer_search_drift_';
    private const string SERVED_OPTION = 'gaffer_search_served';

    public function __construct(
        private readonly IndexService $index,
        private readonly IndexRegistry $registry,
        private readonly SettingsService $settings,
    ) {}

    /** Schedule the daily job, unless it is (cheap: the cron option is loaded anyway). */
    public static function schedule(): void
    {
        if (wp_next_scheduled(self::HOOK) === false) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
        }
    }

    public function run(): void
    {
        foreach ($this->registry->names() as $name) {
            try {
                $this->checkDrift($name);
            } catch (\Throwable $e) {
                error_log("Gaffer Search: the sample check of index \"{$name}\" failed: " . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
        }
        $this->checkServed();
    }

    /** @return array{differing: int, checked: int, time: int} */
    public function checkDrift(string $name): array
    {
        $ids = $this->index->sampleIds($name, self::SAMPLE);
        if ($ids === []) {
            return ['differing' => 0, 'checked' => 0, 'time' => time()];
        }
        $found     = $this->index->verifyIds($name, $ids);
        $differing = array_values(array_unique([...$found['missing'], ...$found['extra'], ...$found['stale']]));
        $this->index->queueUpdates($name, $differing);
        if ($differing !== []) {
            ChangeTracker::scheduleUpdate();
        }
        $result = ['differing' => count($differing), 'checked' => count($ids), 'time' => time()];
        update_option(self::DRIFT_OPTION . $name, $result, false);
        return $result;
    }

    /**
     * The folders a visitor could fetch a probe file from (paths relative to the content folder);
     * null when none could be checked (no folder inside the web root, or every request failed).
     *
     * @return list<string>|null
     */
    public function checkServed(): ?array
    {
        $served  = [];
        $checked = 0;
        foreach ($this->folders() as $dir) {
            $url = self::urlFor($dir);
            if ($url === null || !is_dir($dir)) {
                continue;
            }
            $secret = bin2hex(random_bytes(8));
            $file   = $dir . '/gaffer-search-probe-' . $secret . '.txt';
            if (file_put_contents($file, $secret) === false) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                continue;
            }
            try {
                $response = wp_remote_get($url . '/' . basename($file), ['timeout' => 10, 'redirection' => 0, 'sslverify' => false, 'cookies' => []]);
            } finally {
                wp_delete_file($file);
            }
            if (is_wp_error($response)) {
                continue;
            }
            $checked++;
            if (wp_remote_retrieve_response_code($response) === 200 && trim(wp_remote_retrieve_body($response)) === $secret) {
                $served[] = self::relative($dir);
            }
        }
        $result = $checked === 0 ? null : $served;
        update_option(self::SERVED_OPTION, ['served' => $result, 'time' => time()], false);
        return $result;
    }

    /**
     * The last sample check of an index: how many documents differed from the database (they
     * were put in the update log), how many were compared, when; null before the first one.
     *
     * @return array{differing: int, checked: int, time: int}|null
     */
    public static function drift(string $name): ?array
    {
        $value = get_option(self::DRIFT_OPTION . $name);
        return is_array($value) && isset($value['differing'], $value['checked'], $value['time'])
            ? ['differing' => (int) $value['differing'], 'checked' => (int) $value['checked'], 'time' => (int) $value['time']]
            : null;
    }

    /**
     * The folders the last check could fetch a file from (relative to the content folder); an
     * empty list when none; null when nothing was checked yet or could be.
     *
     * @return list<string>|null
     */
    public static function served(): ?array
    {
        $value = get_option(self::SERVED_OPTION);
        return is_array($value) && is_array($value['served'] ?? null) ? array_values(array_map(strval(...), $value['served'])) : null;
    }

    /** Remove the job and its results on this site (deactivation leaves the results; uninstall). */
    public static function forget(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /** @return list<string> */
    private function folders(): array
    {
        return array_values(array_unique(array_filter([
            (string) $this->settings->get('index_dir'),
            dirname(ConfigLocation::forContentDir(WP_CONTENT_DIR, get_current_blog_id())),
            ConfigLocation::dirForContentDir(WP_CONTENT_DIR),
        ])));
    }

    /** The URL of a folder inside the uploads or content folder; null outside them (not served). */
    private static function urlFor(string $dir): ?string
    {
        $uploads = wp_upload_dir(null, false);
        foreach ([[ (string) $uploads['basedir'], (string) $uploads['baseurl']], [WP_CONTENT_DIR, content_url()]] as [$base, $url]) {
            $base = untrailingslashit($base);
            if ($base !== '' && str_starts_with($dir . '/', $base . '/')) {
                return untrailingslashit($url) . substr($dir, strlen($base));
            }
        }
        return null;
    }

    private static function relative(string $dir): string
    {
        $content = untrailingslashit(WP_CONTENT_DIR);
        return str_starts_with($dir, $content . '/') ? substr($dir, strlen($content) + 1) : basename($dir);
    }
}
