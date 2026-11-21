<?php

declare(strict_types=1);

namespace GafferSearch\Builders;

use GafferSearch\BuildContext;
use GafferSearch\Support\TaxonomyAncestors;
use WP_Query;

/**
 * Builder for post and page indexes (any post type, including ACF/custom ones).
 *
 * Uses WP_Query internally, which bulk-warms the meta and term caches per batch — making
 * get_post_meta() and get_the_terms() cache hits rather than extra queries. URLs come from
 * get_permalink(), so every permalink structure is handled, including hierarchical pages.
 *
 * Usage:
 *   add_action('gaffer_search_register_indexes', function (): void {
 *       gaffer_search_register_index(
 *           new IndexDefinition(name: 'posts', label: 'Posts', description: 'Blog posts.'),
 *           new ContentIndexBuilder(
 *               postTypes:    ['post'],
 *               postStatuses: ['publish'],
 *               searchable:   ['title', 'content', 'excerpt'],
 *               taxonomy:     ['category', 'post_tag' => 'tags'], // 'category' indexed as-is; 'post_tag' renamed to 'tags'
 *               meta:         ['_reading_time' => ['reading_time', 'int']],
 *               sortable:     ['title'],
 *           ),
 *       );
 *   });
 *
 * Documents: id, title, content, excerpt (all plain text), url, post_type, thumbnail, date,
 * plus one field per meta/taxonomy mapping (taxonomy fields hold term slugs) and a {field}_sort
 * key per sortable field. See
 * PostIndexBuilder for the rules they follow.
 *
 * @internal Not part of the public API (the engine contract, docs/contract.md, is): may change in any version.
 */
class ContentIndexBuilder extends PostIndexBuilder
{
    public function documents(BuildContext $context): iterable
    {
        return $this->posts([]);
    }

    public function documentsFor(BuildContext $context, int ...$ids): iterable
    {
        return $ids === [] ? [] : $this->posts(array_values($ids));
    }

    public function config(): array
    {
        return ['type' => 'content'] + parent::config();
    }

    /**
     * @param  list<int> $ids [] = every post of the index's types and statuses.
     * @return \Generator<array<string, mixed>>
     */
    private function posts(array $ids): \Generator
    {
        $lastId = 0;
        // Keyset paging: each batch starts after the last ID of the one before (WP_Query has no
        // argument for it: a WHERE on this builder's queries only). An OFFSET reads and skips
        // every earlier row again, and a post deleted mid-build shifted it.
        $after = static function (string $where, \WP_Query $query): string {
            global $wpdb;
            $id = $query->get('gaffer_search_after', null);
            return is_int($id) ? $where . $wpdb->prepare(" AND {$wpdb->posts}.ID > %d", $id) : $where;
        };
        add_filter('posts_where', $after, 10, 2);

        try {
            do {
                $query = new WP_Query([
                    'post_type'              => $this->postTypes,
                    'post_status'            => $this->postStatuses,
                    'post__in'               => $ids,
                    'posts_per_page'         => $this->chunkSize(),
                    'gaffer_search_after'    => $lastId,
                    // A stable order, the keyset's.
                    'orderby'                => 'ID',
                    'order'                  => 'ASC',
                    'ignore_sticky_posts'    => true,
                    'no_found_rows'          => true,
                    'suppress_filters'       => false,
                    'update_post_meta_cache' => true,
                    'update_post_term_cache' => true,
                ]);
                $posts = array_values(array_filter($query->posts ?? [], static fn(mixed $post): bool => $post instanceof \WP_Post));
                foreach ($posts as $post) {
                    yield $this->buildDoc($post);
                }
                $this->releaseBatch();
                $found  = count($posts);
                $lastId = $found === 0 ? $lastId : $posts[$found - 1]->ID;
            } while ($found === $this->chunkSize());
        } finally {
            remove_filter('posts_where', $after, 10);
        }
    }

    /** @return array<string, mixed> */
    private function buildDoc(\WP_Post $post): array
    {
        $doc = [
            'id'        => $post->ID,
            'title'     => $this->text($post->post_title),
            'content'   => $this->text($post->post_content),
            'excerpt'   => $this->text($post->post_excerpt),
            'url'       => (string) get_permalink($post),
            'post_type' => $post->post_type,
            'thumbnail' => (string) (get_the_post_thumbnail_url($post, 'full') ?: ''),
            'date'      => $this->timestamp($post->post_date_gmt, $post->post_date),
        ];

        foreach ($this->metaMap() as $metaKey => ['field' => $field, 'type' => $type]) {
            $doc[$field] = $this->castMeta(get_post_meta($post->ID, $metaKey, true), $type);
        }

        foreach ($this->taxonomyMap() as $taxonomy => $field) {
            $terms = get_the_terms($post, $taxonomy);
            if (!is_array($terms)) {
                $doc[$field] = [];
            } elseif ($this->withAncestors($taxonomy)) {
                $slugs = [];
                foreach ($terms as $term) {
                    $slugs = [...$slugs, ...TaxonomyAncestors::expand($term->term_id, $taxonomy)];
                }
                $doc[$field] = array_values(array_unique($slugs));
            } else {
                $doc[$field] = array_map(fn(\WP_Term $term): string => $this->termSlug($term->slug), $terms);
            }
        }

        return $this->finalize($doc);
    }
}
