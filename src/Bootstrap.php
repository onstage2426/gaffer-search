<?php

declare(strict_types=1);

namespace GafferSearch;

use GafferSearch\Services\ChangeTracker;
use GafferSearch\Services\HealthCheck;
use GafferSearch\Services\IndexBusyException;
use GafferSearch\Services\IndexRegistry;
use GafferSearch\Services\IndexService;
use GafferSearch\Services\SearchConfig;
use GafferSearch\Services\SettingsService;
use GafferSearch\Services\State;
use GafferSearch\Services\UpdateQueue;
use GafferSearch\Support\ConfigLocation;
use GafferSearch\Support\ProtectedDir;
use GafferSearch\Support\Requirements;

/** @internal Not part of the public API (see API.md): may change in any version. */
class Bootstrap
{
    /** Network-wide: every site's cron jobs; otherwise the current site's. */
    public static function deactivate(bool $networkWide = false): void
    {
        self::onSites($networkWide, function (): void {
            foreach ( (array) get_option('gaffer_search_known_indexes', []) as $name) {
                wp_clear_scheduled_hook('gaffer_search_cron_rebuild_' . (string) $name);
            }
            wp_unschedule_hook(ChangeTracker::REBUILD_HOOK);
            wp_unschedule_hook(ChangeTracker::UPDATE_HOOK);
            wp_unschedule_hook(ChangeTracker::ENSURE_HOOK);
            wp_unschedule_hook(HealthCheck::HOOK);
        });
    }

    /** The updater's options (its state and caches; network options on multisite) and its check job. */
    private const array UPDATER_OPTIONS = ['hu_state-gaffer-search', 'hu_api_cache-gaffer-search', 'hu_metadata_cache-gaffer-search'];
    private const string UPDATER_CRON  = 'hu_cron_check_updates-gaffer-search';

    /**
     * Remove everything the plugin made, on every site (uninstall.php): scheduled jobs, index
     * files with their versions, labels, positions and temp files, the update log table, the options and transients, the updater's state and caches, and the
     * endpoint configs. Other web servers keep their own index folders
     * (GAFFER_SEARCH_INDEX_DIR) until they are cleaned up by hand.
     */
    public static function uninstall(): void
    {
        global $wpdb;
        self::deactivate(true);

        self::onSites(true, function (int $site) use ($wpdb): void {
            $settings = new SettingsService();
            self::removeOwnFiles( (string) $settings->get('index_dir'));
            (new UpdateQueue())->drop();
            $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'gaffer\\_search\\_%' OR option_name LIKE '\\_transient\\_gaffer\\_search\\_%' OR option_name LIKE '\\_transient\\_timeout\\_gaffer\\_search\\_%'",
            );
            wp_delete_file(ConfigLocation::forContentDir(WP_CONTENT_DIR, $site));
            array_map(delete_option(...), self::UPDATER_OPTIONS);
            wp_clear_scheduled_hook(self::UPDATER_CRON);
        });
        if (is_multisite()) {
            array_map(delete_site_option(...), self::UPDATER_OPTIONS);
        }

        $root = ConfigLocation::dirForContentDir(WP_CONTENT_DIR);
        self::removeOwnFiles(dirname(ConfigLocation::forContentDir(WP_CONTENT_DIR, 1)));
        self::removeOwnFiles($root);
    }

    /**
     * Run $fn on every site of the network ($all) or on the current site, switched to it.
     * switch_to_blog() only exists on multisite: a single site just runs $fn.
     *
     * @param callable(int): void $fn
     */
    private static function onSites(bool $all, callable $fn): void
    {
        if (!is_multisite()) {
            $fn(get_current_blog_id());
            return;
        }
        foreach ($all ? get_sites(['fields' => 'ids', 'number' => 0]) : [get_current_blog_id()] as $site) {
            switch_to_blog( (int) $site);
            try {
                $fn( (int) $site);
            } finally {
                restore_current_blog();
            }
        }
    }

    /** Gaffer Search's files in a folder of its own, then the folder when nothing else is left in it. */
    private static function removeOwnFiles(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        foreach (glob("{$dir}/*.db") ?: [] as $index) {
            try {
                \Fuzor\Index::remove($index);
            } catch (\Throwable) {
                // Not an index: not ours to delete.
            }
        }
        // Temp files: a killed build's (Index::remove() takes those of an index that still
        // exists), and an interrupted write of a labels file, a state or an endpoint config.
        foreach (['*.labels.json', '*.state.json', '*.lock', '*.db.tmp-*', '*.json.tmp-*', '*.php.tmp-*', '.htaccess', 'index.php'] as $pattern) {
            foreach (glob("{$dir}/{$pattern}") ?: [] as $file) {
                wp_delete_file($file);
            }
        }
        @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- only when empty
    }

    private SettingsService $settings;
    private IndexRegistry $registry;
    private IndexService $index;

    public function __construct(private readonly string $pluginFile) {}

    public function init(): void
    {
        $this->settings = new SettingsService();

        // Contract 1 for Gaffer (docs/contract.md): from plugins_loaded on, before the theme boots.
        $engine = new Engine();
        add_filter('gaffer_search_engine', static fn(mixed $found, mixed $contract = null): mixed => (int) $contract === Engine::CONTRACT ? $engine : $found, 10, 2);

        // Registry construction is deferred to init:0, after the theme's Gaffer (functions.php, after
        // plugins_loaded) has defined the indexes through the contract (Engine::define()).
        add_action('init', fn() => $this->lateInit(), 0);

        add_filter('cron_schedules', fn(array $s): array => $this->registerCronIntervals($s));
        add_action('update_option_gaffer_search_options', fn() => $this->writeSearchConfig());
        add_action('upgrader_process_complete', fn($u, array $opts) => $this->onUpgrade($opts), 10, 2);

        // Multisite: a deleted site's endpoint config goes with it (WordPress removes its uploads,
        // and so its index files).
        add_action('wp_delete_site', function (\WP_Site $site): void {
            wp_delete_file(ConfigLocation::forContentDir(WP_CONTENT_DIR, (int) $site->blog_id));
        });
        // And its update log, with WordPress's own tables.
        add_filter('wpmu_drop_tables', static function (mixed $tables, mixed $siteId = 0): mixed {
            global $wpdb;
            return is_array($tables) ? [...$tables, $wpdb->get_blog_prefix( (int) $siteId) . UpdateQueue::TABLE] : $tables;
        }, 10, 2);
    }

    public function activate(): void
    {
        // Without them no index can be built: refuse to activate rather than fail on every build.
        $problems = Requirements::problems();
        if ($problems !== []) {
            wp_die(
                esc_html(implode(' ', $problems)),
                esc_html__('Gaffer Search can\'t be activated', 'gaffer-search'),
                ['back_link' => true],
            );
        }
        if (!isset($this->settings)) {
            $this->settings = new SettingsService();
        }
        // The plugin loads during the activation request, after init (and after the theme, which
        // found no engine yet): the next request defines the indexes and sets up their schedules.
        if (!isset($this->registry)) {
            $this->registry = new IndexRegistry();
            $this->index    = new IndexService($this->settings, $this->registry);
        }
        $this->ensureCronSchedules();
        $this->writeSearchConfig();
    }

    private function lateInit(): void
    {
        $this->registry = new IndexRegistry();
        $this->index    = new IndexService($this->settings, $this->registry);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('gaffer-search describe', new \GafferSearch\Cli\DescribeCommand($this->index, $this->registry));
            \WP_CLI::add_command('gaffer-search verify', new \GafferSearch\Cli\VerifyCommand($this->index, $this->registry));
            \WP_CLI::add_command('gaffer-search rebuild', new \GafferSearch\Cli\RebuildCommand($this->index, $this->registry));
            \WP_CLI::add_command('gaffer-search update', new \GafferSearch\Cli\UpdateCommand($this->index, $this->registry));
            \WP_CLI::add_command('gaffer-search status', new \GafferSearch\Cli\StatusCommand(new Engine()));
            \WP_CLI::add_command('gaffer-search search', new \GafferSearch\Cli\SearchCommand($this->index, $this->registry));
            \WP_CLI::add_command('gaffer-search clean', new \GafferSearch\Cli\CleanCommand($this->index));
            \WP_CLI::add_command('gaffer-search rotate-secret', new \GafferSearch\Cli\RotateSecretCommand($this->settings, $this->registry));
        }

        // The endpoints can't work without their config; write it on first boot, and again
        // whenever it's missing (a fresh content folder, a restore that skipped it).
        if (!$this->searchConfig()->isCurrent()) {
            $this->writeSearchConfig();
        }

        // Also outside wp-admin: a site of a network may never see an admin, and network
        // activation sets nothing up per site.
        if ( (array) get_option('gaffer_search_known_indexes', []) !== $this->registry->names()) {
            $this->ensureCronSchedules();
        }

        foreach ($this->registry->names() as $name) {
            add_action('gaffer_search_cron_rebuild_' . $name, fn() => $this->runScheduledBuild($name));
        }
        add_action(ChangeTracker::REBUILD_HOOK, fn($name) => $this->runRequestedBuild( (string) $name));
        add_action(ChangeTracker::ENSURE_HOOK, fn($name) => $this->runEnsureBuild( (string) $name));
        add_action(ChangeTracker::UPDATE_HOOK, fn() => $this->runScheduledUpdates());
        add_action(HealthCheck::HOOK, fn() => (new HealthCheck($this->index, $this->registry, $this->settings))->run());
        if ($this->registry->names() !== []) {
            HealthCheck::schedule();
        }
        // Per site, on its first request with this plugin version (network activation sets up nothing per site).
        $queue = new UpdateQueue();
        if ($queue->install()) {
            // An older log was replaced: its changes are lost, so read everything again.
            foreach ($this->registry->names() as $name) {
                ChangeTracker::schedule($name, 0);
            }
        }
        (new ChangeTracker($this->registry, $queue))->register();

        // An index that isn't built here (new, its file deleted, a language added), or whose
        // builder changed (a new facet, a meta field: until it's rebuilt the file has the old
        // schema). Not one whose build failed in the last hour (no build loop).
        foreach ($this->index->needsBuild() as $name) {
            ChangeTracker::ensure($name);
        }

        add_action('gaffer_search_indexes_built', fn() => $this->writeSearchConfig());
        add_action('admin_init', fn() => $this->ensureCronSchedules());
        // A folder made before the protection files existed, or one they were deleted from.
        add_action('admin_init', function (): void {
            if (is_dir( (string) $this->settings->get('index_dir'))) {
                ProtectedDir::ensure( (string) $this->settings->get('index_dir'));
            }
        });
    }

    /**
     * @param  array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    private function registerCronIntervals(array $schedules): array
    {
        return $schedules + [
            'every_5_minutes'  => ['interval' => 300, 'display' => __('Every 5 minutes', 'gaffer-search')],
            'every_15_minutes' => ['interval' => 900, 'display' => __('Every 15 minutes', 'gaffer-search')],
            'every_30_minutes' => ['interval' => 1800, 'display' => __('Every 30 minutes', 'gaffer-search')],
        ];
    }

    /**
     * The full rebuild ChangeTracker::schedule() asked for (a content change that needs one, a
     * rotated secret, a replaced log). Skipped when a build that started after the last request
     * has finished since: it read everything the request was about. Requests during a running
     * build are later than its start, so they still build.
     */
    private function runRequestedBuild(string $name): void
    {
        $requested = State::requested($name);
        if ($requested !== null && $this->index->satisfiedBy($name, $requested)) {
            return;
        }
        $this->runScheduledBuild($name, retry: fn() => ChangeTracker::schedule($name, retry: true));
    }

    /**
     * The build a request found missing (IndexService::needsBuild()): asked again first, so a
     * build that did it meanwhile (the one that was running when the requests came in) is not
     * repeated. A running build is waited for; the next request schedules what's still needed.
     */
    private function runEnsureBuild(string $name): void
    {
        if (in_array($name, $this->index->needsBuild(backOff: false), true)) {
            $this->runScheduledBuild($name, retry: fn() => ChangeTracker::ensure($name, ChangeTracker::DELAY_SECONDS));
        }
    }

    /**
     * A rebuild from cron: the recurring schedule, or one of the jobs above. When the index is
     * already being rebuilt here, $retry schedules the job again (a minute later); a recurring
     * one simply waits for its next run.
     *
     * @param (callable(): void)|null $retry
     */
    private function runScheduledBuild(string $name, ?callable $retry = null): void
    {
        if (!$this->registry->has($name)) {
            return;
        }
        try {
            $this->index->build($name);
            State::clearError($name);
        } catch (IndexBusyException) {
            if ($retry !== null) {
                $retry();
            }
        } catch (\Throwable $e) {
            State::setError($name, $e->getMessage());
            error_log('Gaffer Search: scheduled rebuild of "' . $name . '" failed: ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

    /**
     * The update job ChangeTracker schedules: bring every index up to date with the update log
     * (IndexService::applyUpdates()). An index being rebuilt is tried again shortly; more rows
     * than one run takes start the next run right away. Then the log's old rows are pruned.
     */
    private function runScheduledUpdates(): void
    {
        foreach ($this->registry->names() as $name) {
            try {
                if ($this->index->applyUpdates($name)['remaining'] > 0) {
                    ChangeTracker::scheduleUpdate(0);
                }
            } catch (IndexBusyException) {
                ChangeTracker::scheduleUpdate(30);
            } catch (\Throwable $e) {
                State::setError($name, $e->getMessage());
                error_log('Gaffer Search: update of "' . $name . '" failed: ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
        }
        (new UpdateQueue())->prune();
    }

    private function ensureCronSchedules(): void
    {
        $currentNames = $this->registry->names();
        // A request without definitions (the active theme isn't on config/search.php yet: the live
        // theme while a staged copy is built) leaves the schedules alone, as it leaves the indexes.
        if ($currentNames === []) {
            return;
        }

        // Clear cron hooks for indexes that were previously known but are no longer registered.
        foreach ( (array) get_option('gaffer_search_known_indexes', []) as $name) {
            if (!$this->registry->has( (string) $name)) {
                wp_clear_scheduled_hook('gaffer_search_cron_rebuild_' . (string) $name);
                wp_clear_scheduled_hook(ChangeTracker::REBUILD_HOOK, [ (string) $name]);
                wp_clear_scheduled_hook(ChangeTracker::ENSURE_HOOK, [ (string) $name]);
            }
        }

        update_option('gaffer_search_known_indexes', $currentNames, false);

        foreach ($currentNames as $name) {
            $def = $this->registry->get($name);
            $this->syncCronEvent(
                'gaffer_search_cron_rebuild_' . $name,
                $def instanceof \GafferSearch\IndexDefinition ? $def->rebuildInterval : '',
                $this->index->isBuilt($name),
            );
        }
    }

    private function searchConfig(): SearchConfig
    {
        return new SearchConfig($this->settings, $this->registry);
    }

    /**
     * The periodic rebuild: its first run is right away only for an index that isn't built yet
     * (its first build), else one interval from now. Reactivating the plugin (which clears the
     * schedule) or changing the interval doesn't rebuild a built index then.
     */
    private function syncCronEvent(string $hook, string $interval, bool $built): void
    {
        $next = wp_next_scheduled($hook);

        if ($interval === '') {
            if ($next !== false) {
                wp_clear_scheduled_hook($hook);
            }
            return;
        }

        if ($next !== false && wp_get_schedule($hook) === $interval) {
            return;
        }

        if ($next !== false) {
            wp_clear_scheduled_hook($hook);
        }

        $seconds = (int) (wp_get_schedules()[$interval]['interval'] ?? 0);
        wp_schedule_event(time() + ($built ? $seconds : 0), $interval, $hook);
    }

    /** @param array<string, mixed> $options */
    private function onUpgrade(array $options): void
    {
        if (($options['action'] ?? '') !== 'update' || ($options['type'] ?? '') !== 'plugin') {
            return;
        }
        $slug = plugin_basename($this->pluginFile);
        foreach ($options['plugins'] ?? [] as $plugin) {
            if ($plugin === $slug) {
                $this->writeSearchConfig();
                return;
            }
        }
    }

    private function writeSearchConfig(): void
    {
        if (!isset($this->registry)) {
            return;
        }
        $this->searchConfig()->write();
    }
}
