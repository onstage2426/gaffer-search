<?php

declare(strict_types=1);

namespace GafferSearch\Support\Language;

use GafferSearch\Language\LanguageAdapter;

/**
 * WPML, through its public filters and actions (wpml_active_languages, wpml_current_language,
 * wpml_switch_language) and its translations table, which every translated post has a row in.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class WpmlLanguages implements LanguageAdapter
{
    /** @var array<string, array<string, mixed>>|null code → WPML's language record */
    private ?array $active = null;

    public function languages(): array
    {
        return array_map(strval(...), array_keys($this->active()));
    }

    public function current(): ?string
    {
        $current = apply_filters('wpml_current_language', null);
        return is_string($current) && $current !== '' && $current !== 'all' ? $current : null;
    }

    public function switch(string $language): void
    {
        do_action('wpml_switch_language', $language);
    }

    public function postSql(string $alias, string $language): array
    {
        global $wpdb;

        $join  = "LEFT JOIN {$wpdb->prefix}icl_translations gaffer_search_lang ON gaffer_search_lang.element_id = {$alias}.ID"
            . " AND gaffer_search_lang.element_type = CONCAT('post_', {$alias}.post_type)";
        $where = $wpdb->prepare('(gaffer_search_lang.language_code = %s OR gaffer_search_lang.translation_id IS NULL)', $language);
        return [$join, (string) $where];
    }

    public function translations(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }
        global $wpdb;
        $ids = implode(',', array_map(intval(...), $postIds));
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- IDs are ints
        $found = $wpdb->get_col(
            "SELECT t.element_id FROM {$wpdb->prefix}icl_translations t
             INNER JOIN {$wpdb->prefix}icl_translations o ON o.trid = t.trid
             WHERE o.element_id IN ({$ids}) AND o.element_type LIKE 'post\\_%' AND t.element_type = o.element_type",
        );
        // phpcs:enable
        return array_values(array_unique([...$postIds, ...array_map(intval(...), (array) $found)]));
    }

    /** @return array<string, array<string, mixed>> */
    private function active(): array
    {
        if ($this->active === null) {
            $languages    = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
            $this->active = is_array($languages) ? $languages : [];
        }
        return $this->active;
    }
}
