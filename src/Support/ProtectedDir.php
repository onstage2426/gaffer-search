<?php

declare(strict_types=1);

namespace GafferSearch\Support;

/**
 * A folder of the plugin's own files (index files, endpoint configs) that the web
 * server must not serve: an index holds every stored document, also the ones a token hides. The
 * folder gets an Apache deny rule and an index file against directory listings; on nginx (which
 * ignores .htaccess) the folder has to be blocked in the server config, or kept outside the web
 * root (GAFFER_SEARCH_INDEX_DIR).
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class ProtectedDir
{
    private const array FILES = [
        '.htaccess' => "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n",
        'index.php' => "<?php\n// Silence is golden.\n",
    ];

    /** Create the folder if needed and add whichever protection file is missing. False when it can't be created. */
    public static function ensure(string $dir): bool
    {
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return false;
        }
        foreach (self::FILES as $name => $content) {
            if (!is_file("{$dir}/{$name}")) {
                file_put_contents("{$dir}/{$name}", $content); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            }
        }
        return true;
    }
}
