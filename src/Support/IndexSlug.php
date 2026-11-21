<?php

declare(strict_types=1);

namespace GafferSearch\Support;

/**
 * Computes the on-disk filename slug for an index: `{name}_{12-hex-char HMAC suffix}`, or
 * `{name}-{language}_{suffix}` for one language's file of an index on a site with languages.
 *
 * The single source of truth for this formula — IndexService (build/admin path) and
 * gaffer_search() (direct SSR path) must resolve to the exact same slug for a given
 * name/language/secret, or the two paths silently diverge onto different index files.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class IndexSlug
{
    public static function compute(string $name, string $secret, ?string $language = null): string
    {
        if ($language === null) {
            return $name . '_' . substr(hash_hmac('sha256', $name, $secret), 0, 12);
        }
        $readable = (string) preg_replace('/[^a-z0-9]/i', '', $language);
        return "{$name}-{$readable}_" . substr(hash_hmac('sha256', "{$name}@{$language}", $secret), 0, 12);
    }
}
