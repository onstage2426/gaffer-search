<?php

declare(strict_types=1);

namespace GafferSearch;

/**
 * @internal Not part of the public API (the engine contract, docs/contract.md, is): may change in any version.
 */
class IndexDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $description,
        public readonly string $rebuildInterval = '',
        // Contract 1 (Engine::define()): what the documents depend on besides the content, the
        // callable that adds fields (rendered cards) to each document, and the types of the
        // fields it adds besides cards (document_fields): field => 'string'|'int'|'float', and
        // the callable that names the values of fields without terms (labels).
        public readonly string $version = '',
        public readonly ?\Closure $document = null,
        /** @var array<string, string> */
        public readonly array $fields = [],
        public readonly ?\Closure $labels = null,
    ) {}
}
