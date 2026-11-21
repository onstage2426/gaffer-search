<?php

declare(strict_types=1);

namespace GafferSearch\Tests\Unit;

use GafferSearch\Support\ProtectedDir;
use GafferSearch\Tests\TestCase;

/** Index files hold every stored document, also the ones a token hides: their folder is never served. */
final class ProtectedDirTest extends TestCase
{
    public function test_a_folder_gets_a_deny_rule_and_an_index_file_also_when_it_exists_already(): void
    {
        $dir = self::dir() . '/protected-' . uniqid();
        self::assertTrue(ProtectedDir::ensure($dir));
        self::assertStringContainsString('Require all denied', (string) file_get_contents("{$dir}/.htaccess"));
        self::assertFileExists("{$dir}/index.php");

        unlink("{$dir}/.htaccess");
        ProtectedDir::ensure($dir);
        self::assertFileExists("{$dir}/.htaccess", 'restored in an existing folder');
    }
}
