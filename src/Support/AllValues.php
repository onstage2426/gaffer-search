<?php

declare(strict_types=1);

namespace GafferSearch\Support;

/**
 * A field's condition `['all' => [...]]`: the document holds every one of these values (a
 * multi-valued field: tagged both "sale" and "new"). FilterQuery parses it, Fuzor gets it as
 * Filter::all() of one condition per value.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final readonly class AllValues
{
    /** @param non-empty-list<string> $values */
    public function __construct(public array $values)
    {
    }
}
