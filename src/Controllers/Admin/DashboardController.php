<?php

declare(strict_types=1);

namespace GafferSearch\Controllers\Admin;

use GafferSearch\Services\IndexBusyException;
use GafferSearch\Services\IndexRegistry;
use GafferSearch\Services\IndexService;
use GafferSearch\Services\SearchConfig;
use GafferSearch\Support\ConfigLocation;
use GafferSearch\Support\Requirements;

/** @internal Not part of the public API (see API.md): may change in any version. */
class DashboardController
{

    public function __construct(
        private readonly IndexService $index,
        private readonly IndexRegistry $registry,
        private readonly string $templateDir,
        private readonly SearchConfig $config,
        private readonly string $searchUrl,
    ) {}

    public function render(): void
    {
        $names = $this->registry->names();

        $stats = $this->index->stats();
        $this->renderTemplate('dashboard.php', [
            'indexes'         => $stats,
            'index_defs'      => $this->registry->all(),
            'orphan_indexes'  => $this->index->orphanStats(),
            'has_system_cron' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'search_url'      => $this->searchUrl,
            'config_path'     => $this->config->path(),
            'config_env'      => ConfigLocation::ENV,
            'config_dir'      => ConfigLocation::dirForContentDir(WP_CONTENT_DIR),
            'config_findable' => $this->config->endpointsCanFindIt(),
            'config_error'    => get_transient('gaffer_search_config_write_error') ?: null,
            'requirement_problems' => Requirements::problems(),
            'next_rebuilds'   => array_combine(
                $names,
                array_map(
                    fn(string $n): ?string => $this->formatScheduled(wp_next_scheduled('gaffer_search_cron_rebuild_' . $n)),
                    $names,
                ),
            ),
            'cron_errors'     => array_combine(
                $names,
                array_map(fn(string $n) => get_transient('gaffer_search_cron_error_' . $n) ?: null, $names),
            ),
            'queued_updates'  => array_combine($names, array_map(fn(string $n): int => $this->index->pendingUpdates($n)['count'], $names)),
            'synonym_warnings' => array_combine(
                $names,
                array_map(fn(string $n) => get_transient('gaffer_search_synonym_skipped_' . $n) ?: null, $names),
            ),
            'cron_overdue'    => array_combine(
                $names,
                array_map(function (string $n): bool {
                    $next = wp_next_scheduled('gaffer_search_cron_rebuild_' . $n);
                    return $next !== false && $next < time();
                }, $names),
            ),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function renderTemplate(string $relativePath, array $data): void
    {
        extract($data, EXTR_SKIP); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- template variables are a fixed, known set built above
        include $this->templateDir . '/' . $relativePath;
    }

    public function handleRebuild(): void
    {
        check_ajax_referer('gaffer_search');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $name = sanitize_key($_POST['index_name'] ?? '');
        if (!$name || !$this->registry->has($name)) {
            wp_send_json_error('Unknown index.');
        }

        try {
            $counts = $this->index->build($name);
            delete_transient('gaffer_search_cron_error_' . $name);
            wp_send_json_success(['counts' => $counts]);
        } catch (IndexBusyException $e) {
            wp_send_json_error($e->elsewhere ? 'This index is already being rebuilt on another server.' : 'This index is already being rebuilt.');
        } catch (\Throwable $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function handleProgress(): void
    {
        check_ajax_referer('gaffer_search');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $name = sanitize_key($_POST['index_name'] ?? '');
        if (!$name) {
            wp_send_json_error('Unknown index.');
        }

        // A running Verify (`what=verify`): documents read so far of about how many.
        if (sanitize_key(wp_unslash($_POST['what'] ?? '')) === 'verify') {
            $v = get_transient("gaffer_search_verify_progress_{$name}");
            wp_send_json_success(is_array($v) ? ['count' => (int) ($v['done'] ?? 0), 'total' => (int) ($v['total'] ?? 0)] : ['count' => null, 'total' => null]);
        }

        $v = get_transient("gaffer_search_build_progress_{$name}");
        wp_send_json_success(['count' => $v !== false ? (int) $v : null]);
    }

    public function handleSearch(): void
    {
        check_ajax_referer('gaffer_search');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $name = sanitize_key($_POST['index_name'] ?? '');
        $q    = sanitize_text_field(wp_unslash($_POST['q'] ?? ''));

        if (!$name || !$this->registry->has($name)) {
            wp_send_json_error('Unknown index.');
        }

        if ($q === '') {
            wp_send_json_error('Empty query.');
        }

        try {
            wp_send_json_success($this->index->search($name, $q));
        } catch (\Throwable $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function handleDelete(): void
    {
        check_ajax_referer('gaffer_search');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $name    = sanitize_key($_POST['index_name'] ?? '');
        $isOrphan = !empty($_POST['orphan']); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above

        if (!$name) {
            wp_send_json_error('Unknown index.');
        }

        if ($isOrphan) {
            if (!$this->index->isOrphan($name)) {
                wp_send_json_error('Orphan not found.');
            }
            if ($this->registry->has($name)) {
                wp_send_json_error('Cannot delete a registered index via the orphan path.');
            }
            $this->index->deleteOrphan($name);
        } elseif ($this->registry->has($name)) {
            $this->index->deleteIndex($name);
        } else {
            wp_send_json_error('Unknown index.');
        }

        wp_send_json_success();
    }

    /** Sign a facet-search token from the facet token builder (same request shape as above). */
    public function handleCleanupTemp(): void
    {
        check_ajax_referer('gaffer_search');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        try {
            // Age 0: a build that is still running holds an flock() on its temp files, and
            // Fuzor never deletes those — so everything else is safe to remove right away.
            $deleted = $this->index->cleanupTempFiles(0);
            wp_send_json_success(['deleted' => array_map(basename(...), $deleted)]);
        } catch (\Throwable $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function handleVerify(): void
    {
        check_ajax_referer('gaffer_search');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $name = sanitize_key($_POST['index_name'] ?? '');
        if (!$name || !$this->registry->has($name)) {
            wp_send_json_error('Unknown index.');
        }

        try {
            wp_send_json_success($this->index->verify($name));
        } catch (\Throwable $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    private function formatScheduled(int|false $timestamp): ?string
    {
        return $timestamp ? (wp_date('Y-m-d H:i:s', $timestamp) ?: null) : null;
    }
}
