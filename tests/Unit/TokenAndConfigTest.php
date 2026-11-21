<?php

declare(strict_types=1);

namespace GafferSearch\Tests\Unit;

use GafferSearch\Support\ConfigLocation;
use GafferSearch\Support\Token;
use GafferSearch\Tests\TestCase;

final class TokenAndConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(ConfigLocation::ENV);
    }

    public function test_a_token_verifies_with_its_secret_only(): void
    {
        $token = Token::sign(['index' => 'products', 'limit' => 24], 'secret');

        self::assertSame(['index' => 'products', 'limit' => 24], Token::verify($token, 'secret'));
        self::assertNull(Token::verify($token, 'other-secret'));
    }

    public function test_claims_read_the_payload_without_verifying(): void
    {
        self::assertSame(['site' => 2, 'index' => 'products'], Token::claims(Token::sign(['site' => 2, 'index' => 'products'], 'any-secret')));
        self::assertNull(Token::claims('not-a-token'));
        self::assertNull(Token::claims(''));
    }

    public function test_a_tampered_or_malformed_token_does_not_verify(): void
    {
        $token            = Token::sign(['index' => 'products', 'limit' => 24], 'secret');
        [$payload, $sig]  = explode('.', $token);
        $forged           = rtrim(strtr(base64_encode((string) json_encode(['index' => 'products', 'limit' => 100])), '+/', '-_'), '=');

        self::assertNull(Token::verify("{$forged}.{$sig}", 'secret'));
        self::assertNull(Token::verify($payload, 'secret'), 'no signature');
        self::assertNull(Token::verify('', 'secret'));
        self::assertNull(Token::verify('.', 'secret'));
    }

    public function test_the_environment_variable_is_the_only_candidate_when_set(): void
    {
        putenv(ConfigLocation::ENV . '=/srv/config/gaffer-search/');

        self::assertSame(['/srv/config/gaffer-search/sites/3.php'], ConfigLocation::candidates('/var/www/wp-content/plugins/gaffer-search/endpoints', null, 3));
        self::assertSame('/srv/config/gaffer-search/sites/1.php', ConfigLocation::forContentDir('/var/www/wp-content', 1));
        self::assertSame('/srv/config/gaffer-search', ConfigLocation::dirForContentDir('/var/www/wp-content'));
    }

    public function test_endpoints_derive_the_content_folder_from_their_own_path(): void
    {
        self::assertSame('/var/www/wp-content/gaffer-search/sites/2.php', ConfigLocation::forContentDir('/var/www/wp-content/', 2));
        self::assertSame(
            ['/srv/site/wp-content/gaffer-search/sites/1.php', '/var/www/wp-content/gaffer-search/sites/1.php'],
            ConfigLocation::candidates('/var/www/wp-content/plugins/gaffer-search/endpoints', '/srv/site/wp-content/plugins/gaffer-search/endpoints/search.php', 1),
            'the web server\'s own (symlinked) path first',
        );
    }

    public function test_load_returns_null_without_a_config_file(): void
    {
        putenv(ConfigLocation::ENV . '=' . self::dir() . '/missing');

        self::assertNull(ConfigLocation::load(__DIR__, null, 1));
    }

    public function test_the_index_folder_is_stored_relative_to_the_config_inside_the_content_folder(): void
    {
        $config = '/var/www/wp-content/gaffer-search/sites/1.php';

        self::assertSame('../../uploads/gaffer-search', ConfigLocation::indexDirFor($config, '/var/www/wp-content/uploads/gaffer-search', '/var/www/wp-content/'));
        self::assertSame('/srv/gaffer-search/1', ConfigLocation::indexDirFor($config, '/srv/gaffer-search/1', '/var/www/wp-content'), 'GAFFER_SEARCH_INDEX_DIR stays absolute');
        self::assertSame('/var/www/wp-content-old/x', ConfigLocation::indexDirFor($config, '/var/www/wp-content-old/x', '/var/www/wp-content'));
        self::assertSame(
            '/var/www/wp-content/uploads/gaffer-search',
            ConfigLocation::indexDirFor('/srv/config/gaffer-search/sites/1.php', '/var/www/wp-content/uploads/gaffer-search', '/var/www/wp-content'),
            'a config outside the content folder (GAFFER_SEARCH_CONFIG_DIR) keeps it absolute',
        );
    }

    public function test_read_resolves_a_relative_index_folder_against_the_file(): void
    {
        $dir = self::dir() . '/read-config/sites';
        @mkdir($dir, 0777, true);
        file_put_contents("{$dir}/1.php", "<?php return ['index_dir' => '../../uploads/gaffer-search'];");
        file_put_contents("{$dir}/2.php", "<?php return ['index_dir' => '/srv/gaffer-search/2'];");

        self::assertSame("{$dir}/../../uploads/gaffer-search", ConfigLocation::read("{$dir}/1.php")['index_dir'] ?? null);
        self::assertSame('/srv/gaffer-search/2', ConfigLocation::read("{$dir}/2.php")['index_dir'] ?? null);
    }
}
