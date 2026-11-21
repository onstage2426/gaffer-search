<?php

declare(strict_types=1);

namespace GafferSearch\Services;

use GafferSearch\Watch;

/**
 * Keeps indexes fresh after content changes: "note which documents changed, update them a few
 * seconds later".
 *
 * The hooks only answer "which posts of which index might be affected", never "what changed" —
 * the update re-reads those posts from the database (IndexService::applyUpdates()), so there is
 * no per-event logic to get wrong and noting a post too often is harmless. They are WordPress's
 * low-level points that every save path goes through (the editor, quick edit, REST,
 * WooCommerce's CRUD, ACF, imports that use the API), filtered against each builder's watches().
 * Writes that bypass WordPress entirely (direct SQL) are caught by the scheduled full rebuild,
 * which stays the safety net.
 *
 * What gets noted:
 *  - a post, its meta or its terms: the post's ID; for a Watch::$parentPostTypes post (a
 *    variation), its parent's;
 *  - a term renamed: the labels (UpdateQueue::LABELS) — documents hold slugs — and, for an
 *    index with a `document` callable (a theme's rendered cards may show the name), the posts
 *    that have the term (in a hierarchical taxonomy, or one of its descendants);
 *  - a term's slug changed, or a term deleted: the posts that have it — but in a hierarchical
 *    taxonomy every descendant's posts store the term's slug as an ancestor, and a deleted term's
 *    children move up, so there it's a full rebuild; as it is for a changed parent;
 *  - a change on another site than this request's (code that switch_to_blog()s): a full rebuild
 *    of that site's indexes, whose builders this request doesn't know.
 *
 * A callback never touches an index file and never throws: it records in memory. At `shutdown`,
 * the IDs go into the site's update log (UpdateQueue) and one update job is scheduled a few seconds later
 * (unless one is pending), so a burst of edits costs one job. A broken index therefore can't
 * break the post editor — the worst case is a stale search result and an error on the dashboard.
 * If the queue can't be written, the index gets a full rebuild instead.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class ChangeTracker
{
    public const string REBUILD_HOOK = 'gaffer_search_scheduled_rebuild';
    public const string UPDATE_HOOK  = 'gaffer_search_apply_updates';
    /** A build a request found missing (an unbuilt index, a changed builder): asked again before it runs. */
    public const string ENSURE_HOOK  = 'gaffer_search_ensure_built';
    /** When the last full rebuild was requested (a microtime), per index; not autoloaded. */
    public const string REQUESTED_OPTION = 'gaffer_search_rebuild_requested_';
    public const int DELAY_SECONDS   = 60;
    public const int UPDATE_DELAY    = 5;

    /**
     * Indexes to rebuild in full, per site: [site ID => [index name => true]]. For another site
     * than the one this request runs for, the index names aren't known here — that site's theme
     * registers them — so it holds ['*' => true]: all of its indexes.
     *
     * @var array<int, array<string, true>>
     */
    private array $rebuild = [];

    /** @var array<string, array<int, true>> index name → post IDs to update (this site) */
    private array $ids = [];

    /** @var array<int, array{slug: string, parent: int}> term ID → its values before wp_update_term() */
    private array $termsBefore = [];

    /** The site this request runs for, whose indexes the registry holds. */
    private readonly int $home;

    /** @var array<string, Watch|null> */
    private array $watches = [];

    public function __construct(private readonly IndexRegistry $registry, private readonly UpdateQueue $queue = new UpdateQueue())
    {
        $this->home = get_current_blog_id();
    }

    public function register(): void
    {
        add_action('clean_post_cache', fn($postId, $post = null) => $this->guard(fn() => $this->onPost($post instanceof \WP_Post ? $post : get_post( (int) $postId))), 10, 2);
        add_action('transition_post_status', fn($new, $old, $post) => $this->guard(fn() => $this->onPost($post instanceof \WP_Post ? $post : null)), 10, 3);
        add_action('deleted_post', fn($postId, $post = null) => $this->guard(fn() => $this->onPost($post instanceof \WP_Post ? $post : null)), 10, 2);

        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) {
            add_action($hook, fn($metaId, $objectId, $metaKey) => $this->guard(fn() => $this->onMeta( (int) $objectId, (string) $metaKey)), 10, 3);
        }

        add_action('set_object_terms', fn($objectId, $terms, $ttIds, $taxonomy) => $this->guard(fn() => $this->onObjectTerms( (int) $objectId, (string) $taxonomy)), 10, 4);
        add_action('edit_terms', fn($termId, $taxonomy) => $this->guard(fn() => $this->beforeTermEdit( (int) $termId, (string) $taxonomy)), 10, 2);
        add_action('edited_term', fn($termId, $ttId, $taxonomy) => $this->guard(fn() => $this->onTermEdited( (int) $termId, (string) $taxonomy)), 10, 3);
        add_action('delete_term', fn($term, $ttId, $taxonomy, $deleted = null, $objectIds = []) => $this->guard(fn() => $this->onTermDeleted( (string) $taxonomy, array_values(array_map(intval(...), (array) $objectIds)))), 10, 5);

        // WooCommerce writes stock and sales counts (total_sales) on order placement with a
        // direct SQL query, which fires no post or meta hook — only its own.
        foreach (['woocommerce_product_set_stock', 'woocommerce_variation_set_stock'] as $hook) {
            add_action($hook, fn($product) => $this->guard(fn() => $this->onPost(is_object($product) && method_exists($product, 'get_id') ? get_post( (int) $product->get_id()) : null)));
        }
        foreach (['woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'woocommerce_updated_product_sales'] as $hook) {
            add_action($hook, fn($productId) => $this->guard(fn() => $this->onPost(get_post( (int) $productId))));
        }

        add_action('shutdown', fn() => $this->guard(fn() => $this->flush()));
    }

    /**
     * Schedule a full rebuild of $name in $delay seconds, unless one is already pending, and
     * note when it was asked for: a build that starts later does it, so the job skips it then
     * (Bootstrap; IndexService::satisfiedBy()). $retry: the job trying again after it found a
     * build running, which keeps the time of the request it retries.
     */
    public static function schedule(string $name, int $delay = self::DELAY_SECONDS, bool $retry = false): void
    {
        if (!$retry) {
            update_option(self::REQUESTED_OPTION . $name, sprintf('%.6F', microtime(true)), false);
        }
        if (wp_next_scheduled(self::REBUILD_HOOK, [$name]) === false) {
            wp_schedule_single_event(time() + max(0, $delay), self::REBUILD_HOOK, [$name]);
        }
    }

    /** When the last full rebuild of $name was asked for; null: never (or before this was noted). */
    public static function requested(string $name): ?float
    {
        $time = get_option(self::REQUESTED_OPTION . $name);
        return is_numeric($time) ? (float) $time : null;
    }

    /**
     * Schedule a check-and-build of $name in $delay seconds, unless one is pending: for what a
     * request finds (IndexService::needsBuild()), which every request finds until the build
     * is done; the job asks again before it builds, so a build that did it in between counts.
     */
    public static function ensure(string $name, int $delay = 0): void
    {
        if (wp_next_scheduled(self::ENSURE_HOOK, [$name]) === false) {
            wp_schedule_single_event(time() + max(0, $delay), self::ENSURE_HOOK, [$name]);
        }
    }

    /** Schedule the current site's update job in $delay seconds, unless one is already pending. */
    public static function scheduleUpdate(int $delay = self::UPDATE_DELAY): void
    {
        if (wp_next_scheduled(self::UPDATE_HOOK) === false) {
            wp_schedule_single_event(time() + max(0, $delay), self::UPDATE_HOOK);
        }
    }

    private function onPost(?\WP_Post $post): void
    {
        // An auto-draft is WordPress's placeholder for "Add new" — never in an index. Its later
        // save as a draft or published post fires these hooks again with the real status.
        if ($post === null || $post->post_status === 'auto-draft' || $this->elsewhere()) {
            return;
        }
        foreach ($this->registry->names() as $name) {
            $watch = $this->watch($name);
            if ($watch !== null && $watch->watchesPostType($post->post_type)) {
                $this->note($name, $watch, $post);
            }
        }
    }

    private function onMeta(int $objectId, string $metaKey): void
    {
        $post = get_post($objectId);
        if (!$post instanceof \WP_Post || $this->elsewhere()) {
            return;
        }
        foreach ($this->registry->names() as $name) {
            $watch = $this->watch($name);
            if ($watch !== null && $watch->watchesPostType($post->post_type) && $watch->watchesMetaKey($metaKey)) {
                $this->note($name, $watch, $post);
            }
        }
    }

    private function onObjectTerms(int $objectId, string $taxonomy): void
    {
        $post = get_post($objectId);
        if (!$post instanceof \WP_Post || $this->elsewhere()) {
            return;
        }
        foreach ($this->registry->names() as $name) {
            $watch = $this->watch($name);
            if ($watch !== null && $watch->watchesPostType($post->post_type) && $watch->watchesTaxonomy($taxonomy)) {
                $this->note($name, $watch, $post);
            }
        }
    }

    /** Before wp_update_term() writes: what onTermEdited() compares with. */
    private function beforeTermEdit(int $termId, string $taxonomy): void
    {
        $term = get_term($termId, $taxonomy);
        if ($term instanceof \WP_Term) {
            $this->termsBefore[$termId] = ['slug' => $term->slug, 'parent' => $term->parent];
        }
    }

    private function onTermEdited(int $termId, string $taxonomy): void
    {
        if ($this->elsewhere()) {
            return;
        }
        $before = $this->termsBefore[$termId] ?? null;
        $term   = get_term($termId, $taxonomy);
        unset($this->termsBefore[$termId]);

        $renamedOnly = $before !== null && $term instanceof \WP_Term && $term->slug === $before['slug'] && $term->parent === $before['parent'];
        $flat        = !is_taxonomy_hierarchical($taxonomy);
        $objects     = null;

        foreach ($this->registry->names() as $name) {
            if (!$this->watch($name)?->watchesTaxonomy($taxonomy)) {
                continue;
            }
            if ($renamedOnly && $this->registry->get($name)?->document === null) {
                $this->ids[$name][UpdateQueue::LABELS] = true;
            } elseif ($renamedOnly) {
                // The theme's fields (rendered cards) may show the name, a descendant's card its
                // ancestors' names: re-render the posts that have it.
                $objects ??= $this->objectsInTermTree($termId, $taxonomy);
                $this->noteIds($name, $objects);
            } elseif ($flat && $before !== null) {
                // A new slug: the posts that have the term store the old one.
                $objects ??= array_values(array_map(intval(...), (array) get_objects_in_term($termId, $taxonomy)));
                $this->noteIds($name, $objects);
            } else {
                $this->rebuild[$this->home][$name] = true;
            }
        }
    }

    /**
     * The posts that have the term or, in a hierarchical taxonomy, one of its descendants.
     *
     * @return list<int>
     */
    private function objectsInTermTree(int $termId, string $taxonomy): array
    {
        $terms = [$termId];
        if (is_taxonomy_hierarchical($taxonomy)) {
            $children = get_term_children($termId, $taxonomy);
            $terms    = [...$terms, ...(is_array($children) ? array_map(intval(...), $children) : [])];
        }
        $objects = get_objects_in_term($terms, $taxonomy);
        return is_array($objects) ? array_values(array_unique(array_map(intval(...), $objects))) : [];
    }

    /** @param list<int> $objectIds The posts that had the term (WordPress passes them). */
    private function onTermDeleted(string $taxonomy, array $objectIds): void
    {
        if ($this->elsewhere()) {
            return;
        }
        foreach ($this->registry->names() as $name) {
            if (!$this->watch($name)?->watchesTaxonomy($taxonomy)) {
                continue;
            }
            if (is_taxonomy_hierarchical($taxonomy)) {
                $this->rebuild[$this->home][$name] = true;
            } else {
                $this->noteIds($name, $objectIds);
            }
        }
    }

    /** The post's own document, or its parent's for a Watch::$parentPostTypes post. */
    private function note(string $name, Watch $watch, \WP_Post $post): void
    {
        $id = $watch->updatesParent($post->post_type) ? (int) $post->post_parent : $post->ID;
        if ($id > 0) {
            $this->ids[$name][$id] = true;
        }
    }

    /** @param list<int> $ids */
    private function noteIds(string $name, array $ids): void
    {
        foreach ($ids as $id) {
            $this->ids[$name][$id] = true;
        }
        $this->ids[$name][UpdateQueue::LABELS] = true;
    }

    /**
     * A change on another site than this request's (switch_to_blog()): note that site, whose
     * indexes this request can't see, and report true so the caller stops there.
     */
    private function elsewhere(): bool
    {
        $site = get_current_blog_id();
        if ($site === $this->home) {
            return false;
        }
        $this->rebuild[$site]['*'] = true;
        return true;
    }

    private function flush(): void
    {
        $queued = false;
        foreach ($this->ids as $name => $ids) {
            if (isset($this->rebuild[$this->home][$name])) {
                continue;
            }
            if ($this->queue->add($name, ...array_keys($ids))) {
                $queued = true;
            } else {
                $this->rebuild[$this->home][$name] = true;
            }
        }
        if ($queued) {
            self::scheduleUpdate();
        }

        foreach ($this->rebuild as $site => $names) {
            if ($site !== $this->home) {
                // Another site: schedule on that site, for every index it last registered
                // (Bootstrap keeps gaffer_search_known_indexes per site); its own request runs them.
                switch_to_blog($site);
                $names = array_fill_keys(array_map(strval(...), (array) get_option('gaffer_search_known_indexes', [])), true);
            }
            foreach (array_keys($names) as $name) {
                self::schedule($name);
            }
            if ($site !== $this->home) {
                restore_current_blog();
            }
        }
        $this->rebuild = [];
        $this->ids     = [];
    }

    private function watch(string $name): ?Watch
    {
        if (!array_key_exists($name, $this->watches)) {
            $this->watches[$name] = $this->registry->builder($name)?->watches();
        }
        return $this->watches[$name];
    }

    /** Run a hook callback without ever letting an error escape into the request that fired it. */
    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            error_log('Gaffer Search: change tracking failed: ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }
}
