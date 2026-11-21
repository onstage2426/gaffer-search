<?php

declare(strict_types=1);

namespace GafferSearch\Services;

use GafferSearch\Builders\IndexBuilderInterface;
use GafferSearch\IndexDefinition;

/**
 * The indexes registered through gaffer_search_register_index(), each with its builder. Read at
 * init:0 (see Bootstrap::lateInit()), right after the gaffer_search_register_indexes action.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
class IndexRegistry
{
    /** @var array<string, array{definition: IndexDefinition, builder: IndexBuilderInterface}> */
    private static array $registered = [];

    /** @var array<string, array{definition: IndexDefinition, builder: IndexBuilderInterface}> */
    private array $entries;

    public function __construct()
    {
        $this->entries = self::$registered;
    }

    /** gaffer_search_register_index(): a later registration of the same name replaces the earlier one. */
    public static function register(IndexDefinition $definition, IndexBuilderInterface $builder): void
    {
        self::$registered[$definition->name] = ['definition' => $definition, 'builder' => $builder];
    }

    /** Forget every registration (tests). */
    public static function reset(): void
    {
        self::$registered = [];
    }

    /** @return array<string, IndexDefinition> */
    public function all(): array
    {
        return array_map(fn(array $entry): IndexDefinition => $entry['definition'], $this->entries);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->entries);
    }

    public function get(string $name): ?IndexDefinition
    {
        return $this->entries[$name]['definition'] ?? null;
    }

    public function builder(string $name): ?IndexBuilderInterface
    {
        return $this->entries[$name]['builder'] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }
}
