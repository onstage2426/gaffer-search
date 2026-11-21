<?php

declare(strict_types=1);

namespace GafferSearch\Support;

use WP_Term;

/**
 * A hierarchical term's slug plus every ancestor's slug (URL-decoded, like the builders store
 * them), so a parent term's filter matches posts assigned only to a child term. Cached per
 * term for the length of the request: a build asks for the same terms over and over.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class TaxonomyAncestors
{
    /** @var array<string, list<string>> */
    private static array $cache = [];

    /** @return list<string> The term's slug first, then its ancestors' (nearest first). */
    public static function expand(int $termId, string $taxonomy): array
    {
        $key = "{$taxonomy}:{$termId}";
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $term = get_term($termId, $taxonomy);
        if (!$term instanceof WP_Term) {
            self::$cache[$key] = [];
            return self::$cache[$key];
        }

        $slugs = [rawurldecode($term->slug)];
        foreach (get_ancestors($termId, $taxonomy, 'taxonomy') as $ancestorId) {
            $ancestor = get_term($ancestorId, $taxonomy);
            if ($ancestor instanceof WP_Term) {
                $slugs[] = rawurldecode($ancestor->slug);
            }
        }

        self::$cache[$key] = $slugs;
        return self::$cache[$key];
    }
}
