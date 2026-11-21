<?php

declare(strict_types=1);

namespace GafferSearch\Cli;

use GafferSearch\Services\ChangeTracker;
use GafferSearch\Services\IndexRegistry;
use GafferSearch\Services\SearchConfig;
use GafferSearch\Services\SettingsService;
use WP_CLI;

/**
 * `wp gaffer-search rotate-secret <token|search>` — replace a secret (docs/hosting.md, "Rotating
 * secrets"). `token`: every token signed before stops verifying, so pages in a page cache fail
 * their searches until the cache is cleared. `search`: the index files get new names, every index
 * is rebuilt, and searches find nothing until that's done.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class RotateSecretCommand
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly IndexRegistry $registry,
    ) {}

    /**
     * Replace the token signing secret or the search secret.
     *
     * ## OPTIONS
     *
     * <secret>
     * : Which one.
     * ---
     * options:
     *   - token
     *   - search
     * ---
     *
     * [--yes]
     * : Don't ask for confirmation.
     *
     * ## EXAMPLES
     *
     *     wp gaffer-search rotate-secret token --yes && wp rocket clean --confirm
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc = []): void
    {
        $which = $args[0] ?? '';
        WP_CLI::confirm($which === 'token'
            ? 'Every page cached with a token signed before fails its searches until the page cache is cleared. Rotate the token secret?'
            : 'Every index is rebuilt under a new file name, and searches find nothing until it is. Rotate the search secret?', $assoc);

        if ($which === 'token') {
            $this->settings->rotateTokenSecret();
        } else {
            $this->settings->rotateSearchSecret();
        }
        (new SearchConfig($this->settings, $this->registry))->write();
        if ($which === 'search') {
            foreach ($this->registry->names() as $name) {
                ChangeTracker::schedule($name, 0);
            }
            WP_CLI::success('Rotated. Rebuilds are scheduled (WP-cron); wp gaffer-search rebuild does them now, and wp gaffer-search clean --delete removes the old files.');
            return;
        }
        WP_CLI::success('Rotated. Clear the page cache now.');
    }
}
