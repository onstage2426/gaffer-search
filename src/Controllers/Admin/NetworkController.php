<?php

declare(strict_types=1);

namespace GafferSearch\Controllers\Admin;

use Fuzor\Index;
use GafferSearch\Support\ConfigLocation;

/**
 * Network Admin → Settings → Gaffer Search: every site of the network with its indexes, read
 * from each site's endpoint config (what its last build registered) and the index files. Read
 * only: a site's indexes are defined by its own theme, so building happens on that site's own
 * dashboard (linked per row) or its cron.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class NetworkController
{
    public function __construct(private readonly string $templateDir) {}

    public function render(): void
    {
        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'default'));
        }

        $plugin = 'gaffer-search/gaffer-search.php';
        $sites  = [];
        foreach (get_sites(['number' => 0]) as $site) {
            $id = (int) $site->blog_id;
            switch_to_blog($id);

            $config  = ConfigLocation::forContentDir(WP_CONTENT_DIR, $id);
            $data    = is_file($config) ? ConfigLocation::read($config) : null;
            $indexes = [];
            foreach (is_array($data) && is_array($data['indexes'] ?? null) ? $data['indexes'] : [] as $name => $slugs) {
                $indexes[ (string) $name] = self::summary( (string) ($data['index_dir'] ?? ''), $slugs);
            }

            $sites[] = [
                'id'        => $id,
                'name'      => (string) get_bloginfo('name'),
                // From the site record, not home_url(): a language plugin active on the current
                // site filters home_url() for every site it's switched to.
                'url'       => set_url_scheme('http://' . $site->domain . $site->path),
                'active'    => is_plugin_active_for_network($plugin) || in_array($plugin, (array) get_option('active_plugins', []), true),
                'dashboard' => admin_url('options-general.php?page=gaffer-search'),
                'indexes'   => $indexes,
            ];

            restore_current_blog();
        }

        include $this->templateDir . '/network.php';
    }

    /**
     * One index of a site's endpoint config: its files (one per language: language → slug since
     * config format 2, a single slug before) summed — documents of every built file, the
     * latest change.
     *
     * @return array{built: bool, documents: ?int, last_modified: ?int}
     */
    public static function summary(string $indexDir, mixed $slugs): array
    {
        $summary = ['built' => false, 'documents' => null, 'last_modified' => null];
        foreach (is_array($slugs) ? $slugs : [$slugs] as $slug) {
            if (!is_string($slug) || $slug === '') {
                continue;
            }
            $path = $indexDir . '/' . $slug . '.db';
            if (!Index::exists($path)) {
                continue;
            }
            $summary['built'] = true;
            $count = self::count($path);
            if ($count !== null) {
                $summary['documents'] = ($summary['documents'] ?? 0) + $count;
            }
            $modified = Index::lastModified($path);
            if ($modified > ($summary['last_modified'] ?? 0)) {
                $summary['last_modified'] = $modified;
            }
        }
        return $summary;
    }

    private static function count(string $path): ?int
    {
        try {
            return (new Index($path, readonly: true))->count();
        } catch (\Throwable) {
            return null;
        }
    }
}
