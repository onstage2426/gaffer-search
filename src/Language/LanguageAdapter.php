<?php

declare(strict_types=1);

namespace GafferSearch\Language;

/**
 * How Fuzor sees a site's languages. A site with languages gets one index file per language
 * behind each index name; tokens, gaffer_search() and gaffer_search_archive() use the current
 * language's file.
 *
 * Built in: NoLanguages (the default) and WpmlLanguages (when WPML is active). A site with
 * another language plugin returns its own implementation from the `gaffer_search_language_adapter`
 * filter.
 *
 * @internal Not part of the public API (the engine contract, docs/contract.md, is): may change in any version.
 */
interface LanguageAdapter
{
    /** @return list<string> The active language codes; [] when the site has no languages. */
    public function languages(): array;

    /** The language of the current request, null on a site without languages. */
    public function current(): ?string;

    /**
     * Make $language the current language for what follows in this request (permalinks, term
     * queries), as a build of that language's index needs.
     */
    public function switch(string $language): void;

    /**
     * SQL that keeps only posts in $language, for a query with the posts table aliased as
     * $alias: a JOIN and a WHERE condition. Posts of a type the plugin doesn't translate pass
     * in every language.
     *
     * @return array{0: string, 1: string} [join, where]
     */
    public function postSql(string $alias, string $language): array;

    /**
     * These posts and every translation of them. An update of a post updates its translations
     * too: a translation plugin syncs fields between them (WCML: stock, prices), often with
     * direct queries that fire no hook.
     *
     * @param  list<int> $postIds
     * @return list<int>
     */
    public function translations(array $postIds): array;
}
