<?php

declare(strict_types=1);

/**
 * @var array<string, array{exists: bool, total_documents: int|null, last_modified: int|null, build_duration: string|null, schema: array{language: string|null, searchable_fields: array<int, string>|null, filterable_fields: array<int, string>, sortable_fields: array<int, string>}|null, schema_version: int|null}> $indexes
 * @var array<string, \GafferSearch\IndexDefinition> $index_defs
 * @var array<string, array{name: string, total_documents: int|null, last_modified: int|null}> $orphan_indexes
 * @var bool $has_system_cron
 * @var string $search_url
 * @var string $config_path
 * @var string $config_env
 * @var string $config_dir
 * @var bool $config_findable
 * @var string|null $config_error
 * @var array<int, string> $requirement_problems
 * @var array<string, string|null> $next_rebuilds
 * @var array<string, array{message: string, time: int}|null> $cron_errors
 * @var array<string, array<int, string>|null> $synonym_warnings
 * @var array<string, int> $queued_updates
 * @var array<string, bool> $cron_overdue
 */

$interval_labels = [
    'every_5_minutes'  => 'Every 5 min',
    'every_15_minutes' => 'Every 15 min',
    'every_30_minutes' => 'Every 30 min',
    'hourly'           => 'Hourly',
    'twicedaily'       => 'Twice daily',
    'daily'            => 'Daily',
];

?>
<div class="wrap">
    <h1 class="screen-reader-text"><?php echo esc_html__('Gaffer Search', 'gaffer-search'); ?></h1>
    <div class="gaffer-search-admin">
<div x-data="gafferSearchAdmin">

<?php if (!$has_system_cron) : ?>
    <div class="gaffer-search-notice gaffer-search-notice--warn">
        <svg fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.345 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
        <p><code>DISABLE_WP_CRON</code> <?php echo esc_html__("is not set, so rebuilds and updates after content changes run through WP-cron: only when the site gets traffic, and one run can hold up the next for a minute. Run WP-cron from a system cron instead, or run `wp gaffer-search update` every minute (on every web server, if there are several).", 'gaffer-search'); ?> <a href="https://developer.wordpress.org/plugins/cron/" target="_blank" rel="noopener"><?php echo esc_html__('Learn more', 'gaffer-search'); ?></a> · <a href="https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/" target="_blank" rel="noopener"><?php echo esc_html__('How to set it up', 'gaffer-search'); ?></a></p>
    </div>
<?php endif; ?>

<?php if ($requirement_problems) : ?>
    <div class="gaffer-search-notice gaffer-search-notice--error">
        <svg fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd"/></svg>
        <p><?php echo esc_html__('No index can be built on this server:', 'gaffer-search'); ?> <?php echo esc_html(implode(' ', $requirement_problems)); ?></p>
    </div>
<?php endif; ?>

<?php if ($config_error) : ?>
    <div class="gaffer-search-notice gaffer-search-notice--error">
        <svg fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd"/></svg>
        <p><?php echo esc_html__('The search endpoints\' config file could not be written, so search requests fail:', 'gaffer-search'); ?> <code><?php echo esc_html($config_error); ?></code>. <?php echo esc_html__('Make the folder writable for PHP:', 'gaffer-search'); ?> <code><?php echo esc_html(dirname($config_path)); ?></code></p>
    </div>
<?php endif; ?>

<?php if (!$config_findable) : ?>
    <div class="gaffer-search-notice gaffer-search-notice--warn">
        <svg fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.345 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
        <p><?php echo esc_html__('Your plugins folder is not directly inside your content folder, so the search endpoints (which run without WordPress) can\'t find their config by themselves. Set this environment variable for PHP, in your server or .env configuration:', 'gaffer-search'); ?> <code><?php echo esc_html($config_env . '=' . $config_dir); ?></code></p>
    </div>
<?php endif; ?>

    <template x-if="endpointCheck.status === 'missing' || endpointCheck.status === 'error'">
        <div class="gaffer-search-notice gaffer-search-notice--error">
            <svg fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd"/></svg>
            <p x-show="endpointCheck.status === 'missing'"><?php echo esc_html__('The search endpoint can\'t find its config file, so every search fails. Set this environment variable for PHP:', 'gaffer-search'); ?> <code><?php echo esc_html($config_env . '=' . $config_dir); ?></code></p>
            <p x-show="endpointCheck.status === 'error'"><?php echo esc_html__('The search endpoint did not answer as expected — check that your server allows PHP to run at this URL:', 'gaffer-search'); ?> <code x-text="endpointCheck.message"></code></p>
        </div>
    </template>


    <div class="gaffer-search-page-header">
        <h2 class="gaffer-search-page-title"><?php echo esc_html__('Gaffer Search', 'gaffer-search'); ?></h2>
        <p class="gaffer-search-page-subtitle"><?php echo esc_html__('The search indexes this site\'s theme defines (config/search.php, through Gaffer).', 'gaffer-search'); ?></p>
    </div>

    <div class="gaffer-search-tabs-nav">
        <button class="gaffer-search-tab" :class="tab === 'indexes' ? 'is-active' : ''" @click="setTab('indexes')"><?php echo esc_html__('Indexes', 'gaffer-search'); ?></button>
        <button class="gaffer-search-tab" :class="tab === 'settings' ? 'is-active' : ''" @click="setTab('settings')"><?php echo esc_html__('Settings', 'gaffer-search'); ?></button>
    </div>

    <!-- ── Indexes ─────────────────────────────────────────── -->
    <div x-show="tab === 'indexes'" x-cloak>

        <?php
        foreach ($index_defs as $name => $def) :
            $stat            = $indexes[$name];
            $cron_err        = $cron_errors[$name];
            $overdue         = $cron_overdue[$name];
            $synonym_skipped = $synonym_warnings[$name];
			?>

        <div class="gaffer-search-section">
            <h3 class="gaffer-search-section__title"><?php echo esc_html($def->label); ?></h3>
            <p class="gaffer-search-section__desc gaffer-search-mono"><?php echo esc_html($name); ?></p>

            <?php if ($overdue && !$cron_err) : ?>
            <div class="gaffer-search-notice gaffer-search-notice--warn" style="margin-bottom:.75rem;">
                <svg fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.345 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
                <p><?php echo esc_html__('Scheduled rebuild is overdue — check that your system cron is running.', 'gaffer-search'); ?></p>
            </div>
            <?php endif; ?>

            <?php if ($cron_err) : ?>
            <div class="gaffer-search-notice gaffer-search-notice--error" style="margin-bottom:.75rem;">
                <svg fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd"/></svg>
                <p><?php echo esc_html__('Last cron rebuild failed', 'gaffer-search'); ?> <span class="gaffer-search-mono">(<?php echo esc_html(wp_date('Y-m-d H:i', $cron_err['time'])); ?>)</span>: <code><?php echo esc_html($cron_err['message']); ?></code></p>
            </div>
            <?php endif; ?>

            <?php if ($synonym_skipped) : ?>
            <div class="gaffer-search-notice gaffer-search-notice--warn" style="margin-bottom:.75rem;">
                <svg fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.345 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
                <p><?php echo esc_html__('Skipped synonym term(s) — multi-word terms are unsupported:', 'gaffer-search'); ?> <code><?php echo esc_html(implode(', ', $synonym_skipped)); ?></code></p>
            </div>
            <?php endif; ?>

            <div class="gaffer-search-card"<?php if ($stat['exists']) : ?> x-data="{ searchOpen: false, q: '', loading: false, results: null, error: null, verify: { loading: false, result: null, error: null, progress: null } }"<?php endif; ?>>

                <div class="gaffer-search-stats-row">
                    <div class="gaffer-search-stat">
                        <span class="gaffer-search-stat__label"><?php echo esc_html__('Indexed', 'gaffer-search'); ?></span>
                        <span class="gaffer-search-stat__value gaffer-search-num"><?php echo ($stat['exists'] && $stat['total_documents'] !== null) ? esc_html( (string) $stat['total_documents']) : '—'; ?></span>
                    </div>
                    <div class="gaffer-search-stat">
                        <span class="gaffer-search-stat__label"><?php echo esc_html__('Last built', 'gaffer-search'); ?></span>
                        <span class="gaffer-search-stat__value gaffer-search-mono">
                            <?php if ($stat['last_modified']) : ?>
								<?php echo esc_html(wp_date('Y-m-d H:i', $stat['last_modified'])); ?>
                            <?php else : ?>—<?php endif; ?>
                        </span>
                    </div>
                    <?php if (!empty($stat['build_duration'])) : ?>
                    <div class="gaffer-search-stat">
                        <span class="gaffer-search-stat__label"><?php echo esc_html__('Build time', 'gaffer-search'); ?></span>
                        <span class="gaffer-search-stat__value gaffer-search-mono"><?php echo esc_html($stat['build_duration']); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($queued_updates[$name])) : ?>
                    <div class="gaffer-search-stat">
                        <span class="gaffer-search-stat__label"><?php echo esc_html__('Queued updates', 'gaffer-search'); ?></span>
                        <span class="gaffer-search-stat__value gaffer-search-mono"><?php echo esc_html(number_format_i18n($queued_updates[$name])); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="gaffer-search-stat">
                        <span class="gaffer-search-stat__label"><?php echo esc_html__('Auto-rebuild', 'gaffer-search'); ?></span>
                        <span class="gaffer-search-stat__value gaffer-search-mono"><?php echo esc_html($interval_labels[$def->rebuildInterval] ?? '—'); ?></span>
                    </div>
                    <?php if (!empty($next_rebuilds[$name])) : ?>
                    <div class="gaffer-search-stat">
                        <span class="gaffer-search-stat__label"><?php echo esc_html__('Next rebuild', 'gaffer-search'); ?></span>
                        <span class="gaffer-search-stat__value gaffer-search-mono"><?php echo esc_html($next_rebuilds[$name]); ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($stat['exists']) : ?>
                <div x-show="searchOpen" class="gaffer-search-preview" style="padding:1rem 1.25rem;">
                    <div style="display:flex;gap:.5rem;">
                        <input type="text" class="gaffer-search-input" placeholder="<?php echo esc_attr__('Search query…', 'gaffer-search'); ?>"
                            x-model="q"
                            @keydown.enter="runSearch('<?php echo esc_attr($name); ?>', q, $data)"
                            style="flex:1;">
                        <button type="button" class="gaffer-search-btn gaffer-search-btn--primary" @click="runSearch('<?php echo esc_attr($name); ?>', q, $data)"><?php echo esc_html__('Search', 'gaffer-search'); ?></button>
                        <button type="button" class="gaffer-search-btn gaffer-search-btn--ghost" x-show="results !== null" @click="results = null; q = ''" x-cloak><?php echo esc_html__('Clear', 'gaffer-search'); ?></button>
                    </div>
                    <p class="gaffer-search-preview__hint" x-show="loading" style="margin-top:.75rem;"><?php echo esc_html__('Searching…', 'gaffer-search'); ?></p>
                    <p class="gaffer-search-preview__error" x-show="error" x-text="error" style="margin-top:.75rem;"></p>
                    <template x-if="results !== null">
                        <div style="margin-top:.75rem;">
                            <p class="gaffer-search-hint" style="margin-bottom:.5rem;" x-text="`${results.total} result(s)`"></p>
                            <pre class="gaffer-search-preview__pre" x-text="JSON.stringify(results.results, null, 2)"></pre>
                        </div>
                    </template>
                </div>
                <div class="gaffer-search-preview" style="padding:1rem 1.25rem;" x-show="verify.loading || verify.result || verify.error" x-cloak>
                    <p class="gaffer-search-preview__hint" x-show="verify.loading"><?php echo esc_html__('Comparing the index with the database…', 'gaffer-search'); ?> <span x-show="verify.progress" x-text="verify.progress ? `${verify.progress.count.toLocaleString()} <?php echo esc_js(__('of about', 'gaffer-search')); ?> ${verify.progress.total.toLocaleString()} <?php echo esc_js(__('documents read', 'gaffer-search')); ?>` : ''"></span></p>
                    <p class="gaffer-search-preview__error" x-show="verify.error" x-text="verify.error"></p>
                    <template x-if="verify.result">
                        <div>
                            <p class="gaffer-search-hint" x-show="!verify.result.missing.length && !verify.result.extra.length && !verify.result.stale.length" x-text="`In sync — ${verify.result.checked} documents checked.`"></p>
                            <template x-if="verify.result.missing.length || verify.result.extra.length || verify.result.stale.length">
                                <div>
                                    <p class="gaffer-search-preview__error"><?php echo esc_html__('Out of sync — rebuild the index to fix it.', 'gaffer-search'); ?></p>
                                    <pre class="gaffer-search-preview__pre" x-text="JSON.stringify(verify.result, null, 2)"></pre>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
                <?php endif; ?>

                <div class="gaffer-search-card__footer">
                    <?php if ($stat['exists']) : ?>
                    <button type="button" class="gaffer-search-btn gaffer-search-btn--ghost" @click="searchOpen = !searchOpen" x-text="searchOpen ? '<?php echo esc_attr__('Hide search', 'gaffer-search'); ?>' : '<?php echo esc_attr__('Search', 'gaffer-search'); ?>'"></button>
                    <button type="button" class="gaffer-search-btn gaffer-search-btn--ghost" @click="verifyIndex('<?php echo esc_attr($name); ?>', verify)" :disabled="verify.loading" x-text="verify.loading ? '<?php echo esc_attr__('Verifying…', 'gaffer-search'); ?>' : '<?php echo esc_attr__('Verify', 'gaffer-search'); ?>'"><?php echo esc_html__('Verify', 'gaffer-search'); ?></button>
                    <?php endif; ?>
                    <button type="button" class="gaffer-search-btn gaffer-search-btn--primary" style="margin-left:auto;" @click="rebuild('<?php echo esc_attr($name); ?>')"><?php echo $stat['exists'] ? esc_html__('Rebuild', 'gaffer-search') : esc_html__('Build', 'gaffer-search'); ?></button>
                    <button type="button" class="gaffer-search-btn gaffer-search-btn--danger" @click="resetIndex('<?php echo esc_attr($name); ?>')"><?php echo esc_html__('Reset', 'gaffer-search'); ?></button>
                </div>

            </div>
        </div>

        <?php endforeach; ?>

        <!-- ── Orphaned indexes ─────────────────────────────── -->
        <?php if ($orphan_indexes) : ?>
        <div class="gaffer-search-section" style="margin-top:1.5rem;">
            <h3 class="gaffer-search-section__title"><?php echo esc_html__('Orphaned indexes', 'gaffer-search'); ?></h3>
            <p class="gaffer-search-section__desc"><?php echo esc_html__('These index files exist on disk but the theme defines no such index. They are safe to delete.', 'gaffer-search'); ?></p>

            <?php foreach ($orphan_indexes as $name => $orphan) : ?>
            <div class="gaffer-search-card gaffer-search-card--warn" style="margin-bottom:.75rem;">
                <div class="gaffer-search-stats-row">
                    <div class="gaffer-search-stat">
                        <span class="gaffer-search-stat__label"><?php echo esc_html__('Name', 'gaffer-search'); ?></span>
                        <span class="gaffer-search-stat__value gaffer-search-mono"><?php echo esc_html($orphan['name']); ?></span>
                    </div>
                    <div class="gaffer-search-stat">
                        <span class="gaffer-search-stat__label"><?php echo esc_html__('Indexed', 'gaffer-search'); ?></span>
                        <span class="gaffer-search-stat__value gaffer-search-num"><?php echo $orphan['total_documents'] !== null ? esc_html( (string) $orphan['total_documents']) : '—'; ?></span>
                    </div>
                    <div class="gaffer-search-stat">
                        <span class="gaffer-search-stat__label"><?php echo esc_html__('Last built', 'gaffer-search'); ?></span>
                        <span class="gaffer-search-stat__value gaffer-search-mono">
                            <?php echo $orphan['last_modified'] ? esc_html(wp_date('Y-m-d H:i', $orphan['last_modified'])) : '—'; ?>
                        </span>
                    </div>
                </div>
                <div class="gaffer-search-card__footer">
                    <button type="button" class="gaffer-search-btn gaffer-search-btn--danger" @click="resetIndex('<?php echo esc_attr($name); ?>', true)"><?php echo esc_html__('Delete', 'gaffer-search'); ?></button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="gaffer-search-card" style="margin-top: 1.25rem;">
            <div class="gaffer-search-card__footer">
                <span class="gaffer-search-status"
                    :class="{
                        'gaffer-search-status--loading': statusType === 'loading',
                        'gaffer-search-status--success': statusType === 'success',
                        'gaffer-search-status--error':   statusType === 'error',
                        'gaffer-search-status--hidden':  !status
                    }"
                    x-text="status || '·'"></span>
                <button type="button" class="gaffer-search-btn gaffer-search-btn--ghost" style="margin-left:auto;" @click="cleanupTemp()" title="<?php echo esc_attr__('Delete temporary files left by builds that were killed before they finished. A build that is still running is never touched.', 'gaffer-search'); ?>"><?php echo esc_html__('Remove leftover temp files', 'gaffer-search'); ?></button>
            </div>
        </div>


    </div><!-- /indexes -->

    <!-- ── Settings ──────────────────────────────────────── -->
    <div x-show="tab === 'settings'" x-cloak style="display:none;" id="settings">

        <div class="gaffer-search-section">
            <h3 class="gaffer-search-section__title"><?php echo esc_html__('Secret management', 'gaffer-search'); ?></h3>

            <div class="gaffer-search-card">
                <div class="gaffer-search-form-row">
                    <span class="gaffer-search-label"><?php echo esc_html__('Search secret', 'gaffer-search'); ?></span>
                    <p class="gaffer-search-hint"><?php echo esc_html__('Used to derive index filenames. Rotating makes all existing index files orphans (listed on the Indexes tab, safe to delete) and schedules a rebuild of every index right away; searches return nothing until it finishes.', 'gaffer-search'); ?></p>
                    <button type="button" class="gaffer-search-btn gaffer-search-btn--danger" style="margin-top:.5rem;" @click="rotateSecret('search')"><?php echo esc_html__('Rotate search secret', 'gaffer-search'); ?></button>
                </div>
                <div class="gaffer-search-form-row">
                    <span class="gaffer-search-label"><?php echo esc_html__('Token signing secret', 'gaffer-search'); ?></span>
                    <p class="gaffer-search-hint"><?php echo esc_html__('Used to sign options tokens. Rotating invalidates all existing tokens: every page cached with an old token (WP Rocket, a CDN) fails its searches until that cache is cleared, and open tabs fail until they are refreshed. New pages get tokens signed with the new secret.', 'gaffer-search'); ?></p>
                    <button type="button" class="gaffer-search-btn gaffer-search-btn--danger" style="margin-top:.5rem;" @click="rotateSecret('token')"><?php echo esc_html__('Rotate token secret', 'gaffer-search'); ?></button>
                </div>
            </div>
        </div>

    </div><!-- /settings -->

</div>
    </div>
</div>
