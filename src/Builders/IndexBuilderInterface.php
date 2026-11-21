<?php

declare(strict_types=1);

namespace GafferSearch\Builders;

use Fuzor\SchemaConfig;
use GafferSearch\BuildContext;
use GafferSearch\Watch;

/**
 * Builds one index's documents. IndexService calls these methods directly: schema(),
 * documents() and labels() on every (re)build, documentsFor() to update single documents,
 * watches() to decide which content changes should trigger an update, and config() for the
 * admin dashboard.
 *
 * Every method that reads content gets a BuildContext: the site and language this build is
 * for. IndexService has already switched to that site and language (so WP_Query, permalinks
 * and term queries follow it); a builder with its own queries filters on `$context->language`
 * (null: the site has no languages, index everything).
 *
 * ContentIndexBuilder and ProductIndexBuilder are the built-in implementations; a custom
 * builder (an external data source, a post type with unusual fields) implements this directly.
 *
 * @internal Not part of the public API (the engine contract, docs/contract.md, is): may change in any version.
 */
interface IndexBuilderInterface
{
    /** The index schema: language, searchable, filterable and sortable fields. */
    public function schema(BuildContext $context): SchemaConfig;

    /**
     * Every document of the index. Each one needs an integer `id`. Store plain text, not HTML:
     * hits and gaffer_search_get() return stored values as they are. Taxonomy fields hold term slugs;
     * their names come from labels().
     *
     * @return iterable<array<string, mixed>>
     */
    public function documents(BuildContext $context): iterable;

    /**
     * The current documents for these IDs, exactly as documents() would build them. An ID
     * that shouldn't be in the index (deleted, unpublished, another post type) is left out:
     * the caller deletes it from the index.
     *
     * @return iterable<array<string, mixed>>
     */
    public function documentsFor(BuildContext $context, int ...$ids): iterable;

    /**
     * Display names of slug values, per field: `['brand' => ['rosa-clara' => 'Rosa Clará']]`.
     * Responses add them as `labels`; return [] when no field holds slugs.
     *
     * @return array<string, array<string, string>>
     */
    public function labels(BuildContext $context): array;

    /** The post types, meta keys, and taxonomies whose changes should trigger an update. */
    public function watches(): Watch;

    /**
     * @return array{equivalences?: list<list<string>>, oneWay?: array<string, list<string>>}
     */
    public function synonyms(): array;

    /** Documents inserted per batch during a build. */
    public function chunkSize(): int;

    /** The indexed field a taxonomy's terms are stored under, or null when this index doesn't include it. */
    public function fieldForTaxonomy(string $taxonomy): ?string;

    /**
     * Read-only description shown in, and pre-filled into, the admin dashboard's code
     * generator. Return [] if there's nothing to show.
     *
     * @return array<string, mixed>
     */
    public function config(): array;
}
