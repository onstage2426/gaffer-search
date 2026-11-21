<?php

declare(strict_types=1);

namespace GafferSearch\Services;

use GafferSearch\Support\FilterQuery;
use GafferSearch\Support\Language\Languages;
use GafferSearch\Support\Token;

/**
 * Signs the browser's tokens (Engine::token()): the locked options (limit, facets, attributes,
 * filter, default_sort, …; `ttl` makes it expire). The visitor can change only q, offset, sort[]
 * and filter[] for fields the token doesn't lock.
 *
 * @internal
 */
final class Tokens
{
    /** @param array<string, mixed> $params */
    public static function sign(array $params): string
    {
        return Token::sign(self::payload($params), (new SettingsService())->tokenSecret());
    }

    /**
     * What a token signs: the locked filter serialized, the expiry from `ttl`, the site and
     * language that signed it, and the contract (`c`).
     *
     * @param  array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function payload(array $params): array
    {
        $filter = is_array($params['filter'] ?? null) ? $params['filter'] : [];
        unset($params['filter']);
        if ($filter !== []) {
            $params['filter'] = FilterQuery::serialize($filter);
        }

        // The expiry is a time the endpoint compares with its clock; `ttl` is how callers ask for it.
        $ttl = is_numeric($params['ttl'] ?? null) ? (int) $params['ttl'] : 0;
        unset($params['ttl'], $params['expires']);
        if ($ttl > 0) {
            $params['expires'] = time() + $ttl;
        }

        // The site whose config (and secret) the endpoint verifies the token with, and on a site
        // with languages the language whose file it searches: set here, never by the caller, so a
        // token always belongs to the site and language that signed it.
        unset($params['language']);
        $params['site'] = get_current_blog_id();
        // The engine contract the token was signed under (docs/contract.md): the endpoint answers
        // each token in its contract's shape, so cached pages outlive plugin updates.
        $params['c'] = 1;
        $language    = Languages::current();
        if ($language !== null) {
            $params['language'] = $language;
        }
        return $params;
    }
}
