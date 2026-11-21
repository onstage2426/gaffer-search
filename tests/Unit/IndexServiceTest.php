<?php

declare(strict_types=1);

namespace GafferSearch\Tests\Unit;

use Fuzor\Index;
use Fuzor\SchemaConfig;
use GafferSearch\BuildContext;
use GafferSearch\IndexDefinition;
use GafferSearch\Language\LanguageAdapter;
use GafferSearch\Services\IndexBusyException;
use GafferSearch\Services\IndexRegistry;
use GafferSearch\Services\IndexService;
use GafferSearch\Services\SettingsService;
use GafferSearch\Services\State;
use GafferSearch\Tests\FixtureBuilder;
use GafferSearch\Tests\TestCase;
use GafferSearchTestWordPress;

/** IndexService parts that need no WordPress content. */
final class IndexServiceTest extends TestCase
{
    public function test_a_builder_that_changed_since_its_build_is_reported(): void
    {
        self::registerIndex();
        self::register(new IndexDefinition(name: 'unbuilt', label: 'Unbuilt', description: ''), new FixtureBuilder());
        $service = new IndexService(new SettingsService(), new IndexRegistry());

        self::assertSame(['products'], $service->changedBuilders(), 'built without a recorded signature: rebuilt; an unbuilt index is not listed');

        update_option('gaffer_search_built_products', ['signature' => 'built-with-another-builder']);
        self::assertSame(['products'], $service->changedBuilders());
    }

    public function test_a_changed_index_version_is_a_changed_builder(): void
    {
        self::registerIndex();
        $service   = new IndexService(new SettingsService(), new IndexRegistry());
        $signature = new \ReflectionMethod($service, 'signature');
        update_option('gaffer_search_built_products', ['signature' => $signature->invoke($service, 'products')]);
        self::assertSame([], $service->changedBuilders());

        self::register(new IndexDefinition(name: 'products', label: 'Products', description: 'Fixture', version: 'card-v1'), new FixtureBuilder());
        $service = new IndexService(new SettingsService(), new IndexRegistry());
        self::assertSame(['products'], $service->changedBuilders(), 'a definition with another version rebuilds once');

        update_option('gaffer_search_built_products', ['signature' => $signature->invoke($service, 'products')]);
        self::assertSame([], $service->changedBuilders());
    }

    public function test_the_file_format_is_part_of_the_signature(): void
    {
        self::registerIndex();
        $service   = new IndexService(new SettingsService(), new IndexRegistry());
        $signature = (new \ReflectionMethod($service, 'signature'))->invoke($service, 'products');

        // The signature as it was before FILE_FORMAT: an index built then rebuilds once.
        $builder = (new IndexRegistry())->builder('products');
        self::assertNotNull($builder);
        $before = md5( (string) wp_json_encode([get_class($builder), $builder->config(), get_object_vars($builder->schema(new \GafferSearch\BuildContext(get_current_blog_id())))], JSON_PARTIAL_OUTPUT_ON_ERROR));
        self::assertNotSame($before, $signature);

        update_option('gaffer_search_built_products', ['signature' => $before]);
        self::assertSame(['products'], $service->changedBuilders(), 'built before the format: rebuilt');
        update_option('gaffer_search_built_products', ['signature' => $signature]);
        self::assertSame([], $service->changedBuilders());
    }

    public function test_what_an_index_was_built_from_is_one_option_per_index_not_autoloaded(): void
    {
        self::registerIndex();
        $service   = new IndexService(new SettingsService(), new IndexRegistry());
        $signature = (new \ReflectionMethod($service, 'signature'))->invoke($service, 'products');
        update_option('gaffer_search_built_signatures', ['products' => $signature, 'other' => 'x'], true);
        update_option('gaffer_search_built_versions', ['products' => 'v1'], true);

        self::assertSame([], $service->changedBuilders(), 'the old arrays are read once');
        self::assertSame('v1', $service->builtVersion('products'));
        self::assertFalse(get_option('gaffer_search_built_signatures'), 'and removed');
        self::assertSame(['signature' => 'x', 'version' => ''], get_option('gaffer_search_built_other'));

        State::recordBuilt('products', 'new', 'v2', 1.5, 2.25);
        self::assertSame(['signature' => 'new', 'version' => 'v2', 'started' => '1.500000', 'duration' => '2.2500'], get_option('gaffer_search_built_products'));
        self::assertFalse(GafferSearchTestWordPress::$autoload['gaffer_search_built_products'] ?? true, 'never in alloptions');
    }

    public function test_a_build_replaces_a_file_this_gaffer_search_cannot_open(): void
    {
        self::register(new IndexDefinition(name: 'older', label: 'Older', description: ''), new FixtureBuilder());
        $service = new IndexService(new SettingsService(), new IndexRegistry());
        $path    = $service->pathFor('older', null);
        // As a development build of the same schema revision wrote it: an info key missing.
        Index::rebuild($path, static fn(Index $new) => $new->insert([['id' => 1, 'title' => 'Oud']]), schema: new SchemaConfig())->close();
        (new \PDO('sqlite:' . $path))->exec("DELETE FROM info WHERE key = 'stop_words'");

        (new \ReflectionMethod($service, 'buildOne'))->invoke($service, 'older', null);

        self::assertSame(12, (new Index($path, readonly: true))->search('')->totalHits);
    }

    public function test_a_build_is_refused_before_it_writes_while_another_holds_a_later_language(): void
    {
        self::register(new IndexDefinition(name: 'bilingual', label: 'Bilingual', description: ''), new FixtureBuilder());
        \GafferSearch\Support\Language\Languages::set(new TwoLanguages());
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public function get_var(string $query): int
            {
                return 0;
            }
        };
        $service = new IndexService(new SettingsService(), new IndexRegistry());

        try {
            // As a build busy with the second language holds it.
            $lock = fopen(self::dir() . '/' . $service->fileSlug('bilingual', 'en') . '.lock', 'c');
            self::assertNotFalse($lock);
            self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
            try {
                $service->build('bilingual');
                self::fail('A second build ran.');
            } catch (IndexBusyException) {
                self::assertFileDoesNotExist($service->pathFor('bilingual', 'nl'), 'nothing built before the refusal');
            }

            flock($lock, LOCK_UN);
            fclose($lock);
            self::assertSame(['bilingual' => 24], $service->build('bilingual'));
        } finally {
            unset($GLOBALS['wpdb']);
            $service->deleteIndex('bilingual');
        }
    }
}

/** A site with two languages, as WPML reports them. */
final class TwoLanguages implements LanguageAdapter
{
    public function languages(): array
    {
        return ['nl', 'en'];
    }

    public function current(): ?string
    {
        return 'nl';
    }

    public function switch(string $language): void {}

    public function postSql(string $alias, string $language): array
    {
        return ['', '1=1'];
    }

    public function translations(array $postIds): array
    {
        return $postIds;
    }
}
