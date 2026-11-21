<?php

declare(strict_types=1);

namespace GafferSearch\Services;

use Fuzor\SchemaConfig;
use GafferSearch\BuildContext;
use GafferSearch\Builders\IndexBuilderInterface;

/**
 * What goes into an index: its builder (IndexRegistry), the schema, the documents with the
 * definition's `document` closure applied (the theme's fields and cards) and the labels
 * written next to the file. Used by builds, updates and verify alike, so all three read the
 * same documents.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class Documents
{
    public function __construct(private readonly IndexRegistry $registry) {}

    public function builderFor(string $name): IndexBuilderInterface
    {
        $builder = $this->registry->builder($name);
        if ($builder === null) {
            throw new \RuntimeException("No index registered as '{$name}'."); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $builder;
    }
    public function schemaFor(string $name, BuildContext $context): SchemaConfig
    {
        return self::withoutDuplicates($this->builderFor($name)->schema($context));
    }
    /**
     * $schema with each field listed once: a `gaffer_search_index_schema` filter that appends a field the
     * builder already declares (`[...$schema->filterableAttributes, 'brand']`) must not list it twice.
     */
    private static function withoutDuplicates(SchemaConfig $schema): SchemaConfig
    {
        $unique = static fn(?array $fields): ?array => $fields !== null ? array_values(array_unique($fields)) : null;
        if ($unique($schema->filterableAttributes) === $schema->filterableAttributes
            && $unique($schema->sortableAttributes) === $schema->sortableAttributes
            && $unique($schema->searchableAttributes) === $schema->searchableAttributes) {
            return $schema;
        }
        return new SchemaConfig(
            stemmer:              $schema->stemmer,
            store:                $schema->store,
            filterableAttributes: $unique($schema->filterableAttributes) ?? [],
            sortableAttributes:   $unique($schema->sortableAttributes) ?? [],
            searchableAttributes: $unique($schema->searchableAttributes),
            stripHtml:            $schema->stripHtml,
            stopWords:            $schema->stopWords,
        );
    }
    public function context(?string $language): BuildContext
    {
        return new BuildContext(get_current_blog_id(), $language);
    }
    /**
     * The builder's documents, each passed through the `gaffer_search_index_document` filter —
     * return a changed array to adjust a document, or null to leave it out of the index.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function generateDocs(string $name, BuildContext $context): \Generator
    {
        foreach ($this->builderFor($name)->documents($context) as $doc) {
            $doc = $this->decorate($doc, $name);
            if (is_array($doc)) {
                yield $doc;
            }
        }
    }
    /**
     * The builder's current documents for these IDs, through the same filter as generateDocs().
     * One the theme's `document` closure throws for is left out and its ID added to $failed
     * (logged): one broken product must not stop every later change from reaching the index.
     * A full build still fails on it (contract: the old index stays, status() has the error).
     *
     * @param  list<int> $ids
     * @param  list<int> $failed
     * @return \Generator<int, array<string, mixed>>
     */
    public function generateDocsFor(string $name, BuildContext $context, array $ids, array &$failed): \Generator
    {
        foreach ($this->builderFor($name)->documentsFor($context, ...$ids) as $doc) {
            try {
                $doc = $this->decorate($doc, $name);
            } catch (\Throwable $e) {
                $failed[] = (int) ($doc['id'] ?? 0);
                error_log("Gaffer Search: index \"{$name}\" kept document " . (int) ($doc['id'] ?? 0) . " as stored: its document closure threw {$e->getMessage()}"); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                continue;
            }
            if (is_array($doc)) {
                yield $doc;
            }
        }
    }
    /**
     * The names to write next to an index: the definition's `labels` callable's (contract 1)
     * for the values of fields without terms, and the builder's term names, which win for a
     * taxonomy field. Only string names of scalar values are kept.
     *
     * @return array<string, array<string, string>>
     */
    public function labelsFor(string $name, BuildContext $context): array
    {
        $labels   = [];
        $callable = $this->registry->get($name)?->labels;
        foreach ($callable !== null ? (array) $callable($name) : [] as $field => $names) {
            foreach (is_array($names) ? $names : [] as $value => $label) {
                if (is_scalar($label)) {
                    $labels[ (string) $field][ (string) $value] = (string) $label;
                }
            }
        }

        return [...$labels, ...$this->builderFor($name)->labels($context)];
    }
    /**
     * A built document with what the definition's `document` callable adds (contract 1).
     *
     * @param  array<string, mixed> $doc
     * @return array<string, mixed>|null
     */
    private function decorate(array $doc, string $name): ?array
    {
        $definition = $this->registry->get($name);
        if ($definition?->document === null) {
            return $doc;
        }
        $doc = ($definition->document)($doc, $name);
        if (!is_array($doc)) {
            return null;
        }
        // The declared fields (document_fields) as their type, as meta values are: numbers sort
        // and filter as numbers. One the closure leaves out (or gives null, or a number field
        // something that isn't a number) is missing, never 0.
        foreach ($definition->fields as $field => $type) {
            $value  = $doc[$field] ?? null;
            $usable = $type === 'string' ? is_scalar(...) : is_numeric(...);
            $value  = is_array($value) ? array_values(array_filter($value, $usable)) : $value;
            if ($value === null || $value === [] || (!is_array($value) && !$usable($value))) {
                unset($doc[$field]);
                continue;
            }
            $cast        = static fn(mixed $v): int|float|string => match ($type) {
                'int'   => (int) $v,
                'float' => (float) $v,
                default => (string) $v,
            };
            $doc[$field] = is_array($value) ? array_map($cast, $value) : $cast($value);
        }

        return $doc;
    }
}
