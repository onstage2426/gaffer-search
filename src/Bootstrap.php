<?php

declare(strict_types=1);

namespace GafferSearch;

use GafferSearch\Controllers\Admin\DashboardController;
use GafferSearch\Controllers\Admin\NetworkController;
use GafferSearch\Services\ChangeTracker;
use GafferSearch\Services\HealthCheck;
use GafferSearch\Services\IndexBusyException;
use GafferSearch\Services\IndexRegistry;
use GafferSearch\Services\IndexService;
use GafferSearch\Services\SearchConfig;
use GafferSearch\Services\SettingsService;
use GafferSearch\Services\UpdateQueue;
use GafferSearch\Support\ConfigLocation;
use GafferSearch\Support\ProtectedDir;
use GafferSearch\Support\Requirements;
use GafferSearch\Support\Vite;

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
     * files with their versions, labels, positions and temp files, this server's snapshot
     * folder, the update log table, the options and transients, the updater's state and caches, and the
     * endpoint configs. Other web servers keep their own index folders
     * (GAFFER_SEARCH_INDEX_DIR) until they are cleaned up by hand.
     */
    public static function uninstall(): void
    {
        global $wpdb;
        self::deactivate(true);

        self::onSites(true, function (int $site) use ($wpdb): void {
            $settings = new SettingsService();
            foreach ([ (string) $settings->get('index_dir'), (string) $settings->snapshotDir()] as $dir) {
                self::removeOwnFiles($dir);
            }
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

    private readonly string $pluginDir;
    private ?DashboardController $dashboard = null;

    private SettingsService $settings;
    private IndexRegistry $registry;
    private IndexService $index;

    public function __construct(private readonly string $pluginFile)
    {
        $this->pluginDir = dirname($pluginFile);
    }

    public function init(): void
    {
        Vite::init($this->pluginFile);

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
        add_filter('plugin_action_links_' . plugin_basename($this->pluginFile), fn(array $l): array => $this->addSettingsLink($l));

        // Multisite: a deleted site's endpoint config goes with it (WordPress removes its uploads,
        // and so its index files); Network Admin → Settings → Gaffer Search lists every site.
        add_action('wp_delete_site', function (\WP_Site $site): void {
            wp_delete_file(ConfigLocation::forContentDir(WP_CONTENT_DIR, (int) $site->blog_id));
        });
        // And its update log, with WordPress's own tables.
        add_filter('wpmu_drop_tables', static function (mixed $tables, mixed $siteId = 0): mixed {
            global $wpdb;
            return is_array($tables) ? [...$tables, $wpdb->get_blog_prefix( (int) $siteId) . UpdateQueue::TABLE] : $tables;
        }, 10, 2);
        add_action('network_admin_menu', fn() => $this->registerNetworkMenu());
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
        add_action('wp_ajax_gaffer_search_rotate_search_secret', fn() => $this->handleRotateSecret($this->rotateSearchSecret(...)));
        add_action('wp_ajax_gaffer_search_rotate_token_secret', fn() => $this->handleRotateSecret($this->rotateTokenSecret(...)));
        add_action('admin_init', fn() => $this->ensureCronSchedules());
        // A folder made before the protection files existed, or one they were deleted from.
        add_action('admin_init', function (): void {
            if (is_dir( (string) $this->settings->get('index_dir'))) {
                ProtectedDir::ensure( (string) $this->settings->get('index_dir'));
            }
        });
        add_action('admin_menu', fn() => $this->registerMenu());
        add_action('admin_enqueue_scripts', fn(string $hook) => $this->enqueueAssets($hook));
        add_action('wp_ajax_gaffer_search_rebuild', fn() => $this->dashboard()->handleRebuild());
        add_action('wp_ajax_gaffer_search', fn() => $this->dashboard()->handleSearch());
        add_action('wp_ajax_gaffer_search_delete_index', fn() => $this->dashboard()->handleDelete());
        add_action('wp_ajax_gaffer_search_progress', fn() => $this->dashboard()->handleProgress());
        add_action('wp_ajax_gaffer_search_cleanup_temp', fn() => $this->dashboard()->handleCleanupTemp());
        add_action('wp_ajax_gaffer_search_verify', fn() => $this->dashboard()->handleVerify());
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
        $requested = ChangeTracker::requested($name);
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
            delete_transient('gaffer_search_cron_error_' . $name);
        } catch (IndexBusyException $e) {
            // Built on another server: this one imports that build with its next update.
            if ($retry !== null && !$e->elsewhere) {
                $retry();
            }
        } catch (\Throwable $e) {
            set_transient('gaffer_search_cron_error_' . $name, ['message' => $e->getMessage(), 'time' => time()], WEEK_IN_SECONDS);
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
                set_transient('gaffer_search_cron_error_' . $name, ['message' => $e->getMessage(), 'time' => time()], WEEK_IN_SECONDS);
                error_log('Gaffer Search: update of "' . $name . '" failed: ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
        }
        (new UpdateQueue())->prune();
    }

    /**
     * @param  array<int|string, string> $links
     * @return array<int|string, string>
     */
    private function addSettingsLink(array $links): array
    {
        array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=gaffer-search')) . '">' . __('Settings', 'default') . '</a>');
        return $links;
    }

    private function registerMenu(): void
    {
        add_options_page(
            __('Gaffer Search', 'gaffer-search'),
            __('Gaffer Search', 'gaffer-search'),
            'manage_options',
            'gaffer-search',
            fn() => $this->dashboard()->render(),
        );
    }

    private function registerNetworkMenu(): void
    {
        add_submenu_page(
            'settings.php',
            __('Gaffer Search', 'gaffer-search'),
            __('Gaffer Search', 'gaffer-search'),
            'manage_network_options',
            'gaffer-search-network',
            fn() => (new NetworkController($this->pluginDir . '/templates'))->render(),
        );
    }

    private function enqueueAssets(string $hook): void
    {
        if ($hook !== 'settings_page_gaffer-search') {
            return;
        }

        wp_enqueue_script(
            'gaffer-search-admin',
            Vite::url('assets/js/admin/admin.js'),
            [],
            Vite::ver('assets/js/admin/admin.js'),
            true,
        );

        foreach (Vite::css('assets/js/admin/admin.js') as $i => $cssUrl) {
            wp_enqueue_style("gaffer-search-admin-{$i}", $cssUrl, [], Vite::ver('assets/js/admin/admin.js'));
        }
        wp_localize_script('gaffer-search-admin', 'gafferSearch', [
            'nonce'          => wp_create_nonce('gaffer_search'),
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'searchUrl'      => (new Engine())->endpoint(),
            'siteId'         => get_current_blog_id(),
        ]);
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

    private function dashboard(): DashboardController
    {
        return $this->dashboard ??= new DashboardController(
            $this->index,
            $this->registry,
            $this->pluginDir . '/templates',
            $this->searchConfig(),
            (new Engine())->endpoint(),
        );
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

    private function handleRotateSecret(callable $rotate): void
    {
        check_ajax_referer('gaffer_search');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }
        $rotate();
        wp_send_json_success();
    }

    /**
     * Every token signed before stops verifying, so pages cached with one fail their searches
     * until the cache is cleared.
     */
    private function rotateTokenSecret(): void
    {
        $this->settings->rotateTokenSecret();
        $this->searchConfig()->write();
    }

    /**
     * Every index file gets a new name, so all indexes are rebuilt; searches find nothing until
     * they are.
     */
    private function rotateSearchSecret(): void
    {
        $this->settings->rotateSearchSecret();
        $this->searchConfig()->write();
        foreach ($this->registry->names() as $name) {
            ChangeTracker::schedule($name, 0);
        }
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
