<?php

declare(strict_types=1);

namespace GafferSearch\Services;

use Fuzor\FacetExclude;

/**
 * The locked filters a request's `term` and `visibility` add (Engine): they replace the visitor's
 * filter for their field, in tokens and PHP searches alike.
 *
 * @internal
 */
final class Locks
{
    /** A lock no document passes (ids start at 1): what a lock that can't be applied becomes. */
    public const array NOTHING = ['id' => '0'];

    /**
     * The lock for a term archive: [field => term slug] when $index stores the term's taxonomy.
     * Otherwise (the index isn't defined in this request, or doesn't store the taxonomy) NOTHING:
     * leaving the lock out would show every document on the term's archive.
     *
     * @return array<string, string>
     */
    public static function term(string $index, \WP_Term $term): array
    {
        $field = (new IndexRegistry())->builder($index)?->fieldForTaxonomy($term->taxonomy);
        if ($field === null) {
            error_log("Gaffer Search: index \"{$index}\" can't lock to {$term->taxonomy} term {$term->term_id} (not defined in this request, or it doesn't store that taxonomy): the search finds nothing."); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return self::NOTHING;
        }
        return [$field => rawurldecode($term->slug)];
    }

    /**
     * Hides the products WooCommerce's catalog visibility hides from a use: 'search' (a
     * searchbar) or 'catalog' (the shop, which also hides out-of-stock products when WooCommerce
     * is set to).
     *
     * @param  'search'|'catalog' $use
     * @return array{visibility: FacetExclude}
     */
    public static function visibility(string $use): array
    {
        $hidden = $use === 'search'
            ? ['exclude-from-search']
            : (get_option('woocommerce_hide_out_of_stock_items') === 'yes' ? ['exclude-from-catalog', 'outofstock'] : ['exclude-from-catalog']);

        return ['visibility' => new FacetExclude($hidden)];
    }
}
