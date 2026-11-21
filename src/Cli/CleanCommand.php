<?php

declare(strict_types=1);

namespace GafferSearch\Cli;

use GafferSearch\Services\IndexFiles;
use WP_CLI;

/**
 * `wp gaffer-search clean [--delete]` — the files in the index folder no definition uses: index
 * files of an index the theme no longer defines (or of a rotated search secret), and temp files
 * a killed build left (older than an hour; a running build's are never touched). Lists them;
 * `--delete` removes them.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class CleanCommand
{
    public function __construct(private readonly IndexFiles $files) {}

    /**
     * List index files no definition uses and leftover temp files; --delete removes them.
     *
     * ## OPTIONS
     *
     * [--delete]
     * : Delete what is listed.
     *
     * ## EXAMPLES
     *
     *     wp gaffer-search clean
     *     wp gaffer-search clean --delete
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc = []): void
    {
        $delete  = isset($assoc['delete']);
        $orphans = $this->files->orphanStats();
        foreach ($orphans as $slug => $orphan) {
            WP_CLI::log(sprintf('%s: index "%s" no definition uses, %s documents%s', $slug, $orphan['name'], $orphan['total_documents'] ?? '?', $delete ? ' (deleted)' : ''));
            if ($delete) {
                $this->files->deleteOrphan($slug);
            }
        }
        $temp = $delete ? $this->files->cleanupTempFiles() : $this->files->tempFiles();
        foreach ($temp as $file) {
            WP_CLI::log(basename($file) . ': a killed build\'s temp file' . ($delete ? ' (deleted)' : ''));
        }
        if ($orphans === [] && $temp === []) {
            WP_CLI::success('Nothing to clean.');
        } elseif (!$delete) {
            WP_CLI::log('wp gaffer-search clean --delete removes them.');
        }
    }
}
