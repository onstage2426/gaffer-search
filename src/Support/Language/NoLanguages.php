<?php

declare(strict_types=1);

namespace GafferSearch\Support\Language;

use GafferSearch\Language\LanguageAdapter;

/**
 * A site without a language plugin: one index file per index, everything in it.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class NoLanguages implements LanguageAdapter
{
    public function languages(): array
    {
        return [];
    }

    public function current(): ?string
    {
        return null;
    }

    public function switch(string $language): void
    {
    }

    public function postSql(string $alias, string $language): array
    {
        return ['', '1=1'];
    }

    public function translations(array $postIds): array
    {
        return $postIds;
    }
}
