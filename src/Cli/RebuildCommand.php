<?php

declare(strict_types=1);

namespace GafferSearch\Cli;

use GafferSearch\Services\IndexBusyException;
use GafferSearch\Services\IndexRegistry;
use GafferSearch\Services\IndexService;
use WP_CLI;

/**
 * `wp gaffer-search rebuild [<index>]` — rebuild one index, or every registered index, in full: every
 * language's file. For a
 * system cron instead of WP-cron, and after a deploy. Every index is attempted; the exit code
 * (docs/hosting.md): 0 every index built, 1 one failed, 2 one skipped (a build already running)
 * or no index defined.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class RebuildCommand
{
    public const int BUILT   = 0;
    public const int FAILED  = 1;
    public const int SKIPPED = 2;

    public function __construct(
        private readonly IndexService $index,
        private readonly IndexRegistry $registry,
    ) {}

    /**
     * Rebuild an index in full: every language's file, then published for the other web servers.
     * Exits 0 when built, 1 when a build failed, 2 when one was skipped (already running) or no index is defined.
     *
     * ## OPTIONS
     *
     * [<index>]
     * : The index name. Default: every registered index.
     *
     * ## EXAMPLES
     *
     *     wp gaffer-search rebuild products
     *
     * @param list<string> $args
     */
    public function __invoke(array $args): void
    {
        if (isset($args[0]) && !$this->registry->has($args[0])) {
            WP_CLI::error("No index registered as '{$args[0]}'. Registered: " . implode(', ', $this->registry->names()));
        }
        $names = isset($args[0]) ? [$args[0]] : $this->registry->names();
        if ($names === []) {
            WP_CLI::warning('No index is defined in this request (the theme defines them: is it active?). Nothing built.');
            WP_CLI::halt(self::SKIPPED);
        }

        $outcomes = [];
        foreach ($names as $name) {
            $outcomes[] = $this->rebuild($name);
        }

        WP_CLI::halt(self::exit_code($outcomes));
    }

    /**
     * @param list<int> $outcomes
     */
    public static function exit_code(array $outcomes): int
    {
        return match (true) {
            in_array(self::FAILED, $outcomes, true) => self::FAILED,
            in_array(self::SKIPPED, $outcomes, true) => self::SKIPPED,
            default => self::BUILT,
        };
    }

    private function rebuild(string $name): int
    {
        $start = microtime(true);
        try {
            $count = $this->index->build($name)[$name] ?? 0;
        } catch (IndexBusyException) {
            // Not a failure: the running build reads the same content.
            WP_CLI::warning("{$name}: already being rebuilt; skipped.");

            return self::SKIPPED;
        } catch (\Throwable $e) {
            WP_CLI::warning("{$name}: the build failed, the old index stays: " . $e->getMessage());

            return self::FAILED;
        }
        WP_CLI::log(sprintf('%s: %d documents in %.1fs.', $name, $count, microtime(true) - $start));

        return self::BUILT;
    }
}
