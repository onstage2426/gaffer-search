<?php

declare(strict_types=1);

namespace GafferSearch\Support;

/**
 * Parses the plugin's sort[] query-param contract into the shape Fuzor\Index::search()/
 * searchBoolean() expect — exactly 'field:asc' or 'field:desc' per entry (direction required,
 * matching the vendor library's own parseSortSpec()). Anything else is dropped rather than
 * throwing, the same "invalid input is silently ignored" contract FilterQuery::parse() uses.
 *
 * Shared between search.php and searches from PHP so the two never drift.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class SortQuery
{
    /**
     * @param  array<mixed> $raw
     * @return list<string>
     */
    public static function parse(array $raw): array
    {
        $sort = [];

        foreach ($raw as $entry) {
            $entry = (string) $entry;
            if (preg_match('/^[a-zA-Z0-9_]+:(asc|desc)$/i', $entry)) {
                $sort[] = strtolower($entry);
            }
        }

        return $sort;
    }
}
