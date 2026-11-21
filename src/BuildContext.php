<?php

declare(strict_types=1);

namespace GafferSearch;

use GafferSearch\Support\Language\Languages;

/**
 * What one build of an index is for: the site and the language. Builders receive it in every
 * call that reads content, so one registered index can be built per site (multisite) and per
 * language (WPML) without a builder per combination.
 *
 * `language` is the language plugin's code ('en-us'), null on a site without one: index
 * everything.
 *
 * @internal Not part of the public API (the engine contract, docs/contract.md, is): may change in any version.
 */
final readonly class BuildContext
{
    public function __construct(
        public int $site,
        public ?string $language = null,
    ) {}

    /** The current site in its current language. */
    public static function current(): self
    {
        return new self(get_current_blog_id(), Languages::adapter()->current());
    }
}
