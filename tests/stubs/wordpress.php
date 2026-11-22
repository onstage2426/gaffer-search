<?php

declare(strict_types=1);

/**
 * The few WordPress functions the public PHP API touches, backed by in-memory state that
 * TestCase resets before every test. Only what the tests need: anything else is a fatal
 * "undefined function", which is the point (a test that reaches further needs a real site).
 */

final class WP_Term
{
    public int $term_id = 0;
    public string $taxonomy = '';
    public string $name = '';
    public string $slug = '';
    public int $parent = 0;

    public function __construct(int $term_id, string $taxonomy, string $name, string $slug = '')
    {
        $this->term_id  = $term_id;
        $this->taxonomy = $taxonomy;
        $this->name     = $name;
        $this->slug     = $slug;
    }
}

/** WooCommerce is active: `products` definitions are checked, not refused. */
final class WooCommerce
{
}

final class GafferSearchTestWordPress
{
    /** @var array<string, mixed> */
    public static array $options = [];
    /** @var array<string, list<array{callable, int}>> */
    public static array $filters = [];
    /** @var array<string, int> */
    public static array $actions = [];
    /** @var list<string> The actions running now, innermost last. */
    public static array $running = [];
    public static mixed $queried = null;
    /** @var array<int, WP_Term> get_term()'s terms by ID */
    public static array $terms = [];
    /** @var list<string> */
    public static array $doingItWrong = [];
    public static string $uploads = '/tmp';
    public static int $site = 1;
    /** @var list<string> Cron hooks cleared or unscheduled, in order. */
    public static array $unscheduled = [];
    /** @var array<int, WP_Post> The posts WP_Query finds (a contract case's `setup.build`). */
    public static array $posts = [];
    /** @var array<int, array<string, mixed>> post ID => meta key => value */
    public static array $meta = [];
    /** @var array<int, array<string, list<int>>> post ID => taxonomy => term IDs */
    public static array $postTerms = [];
    /** @var array<int, int> term ID => parent term ID */
    public static array $parents = [];
    /** @var list<string> */
    public static array $hierarchical = [];
    /** @var array<string, array{time: int, interval: string}> Recurring events by hook. */
    public static array $events = [];
    /** @var array<string, bool> Options written with an explicit autoload. */
    public static array $autoload = [];
    /** @var list<array{time: int, hook: string, args: list<mixed>}> Single events, in the order scheduled. */
    public static array $single = [];

    public static function reset(string $uploads): void
    {
        self::$options      = [];
        self::$filters      = [];
        self::$actions      = [];
        self::$running      = [];
        self::$queried      = null;
        self::$terms        = [];
        self::$doingItWrong = [];
        self::$uploads      = $uploads;
        self::$site         = 1;
        self::$unscheduled  = [];
        self::$posts        = [];
        self::$meta         = [];
        self::$postTerms    = [];
        self::$parents      = [];
        self::$hierarchical = [];
        self::$autoload     = [];
        self::$events       = [];
        self::$single       = [];
        \GafferSearch\Services\IndexRegistry::reset();
    }
}

function wp_mkdir_p(string $dir): bool
{
    return is_dir($dir) || mkdir($dir, 0777, true);
}

function get_option(string $name, mixed $default = false): mixed
{
    return GafferSearchTestWordPress::$options[$name] ?? $default;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    GafferSearchTestWordPress::$options[$name] = $value;
    if ($autoload !== null) {
        GafferSearchTestWordPress::$autoload[$name] = (bool) $autoload;
    }
    return true;
}

function add_option(string $name, mixed $value = '', string $deprecated = '', mixed $autoload = null): bool
{
    if (array_key_exists($name, GafferSearchTestWordPress::$options)) {
        return false;
    }
    return update_option($name, $value, $autoload);
}

function get_site_option(string $name, mixed $default = false): mixed
{
    return get_option($name, $default);
}

function update_site_option(string $name, mixed $value): bool
{
    return update_option($name, $value, false);
}

function delete_site_option(string $name): bool
{
    return delete_option($name);
}

function delete_option(string $name): bool
{
    unset(GafferSearchTestWordPress::$options[$name]);
    return true;
}

const MINUTE_IN_SECONDS = 60;
const HOUR_IN_SECONDS   = 3600;
const DAY_IN_SECONDS    = 86400;
const WEEK_IN_SECONDS   = 604800;
const MONTH_IN_SECONDS  = 2592000;

function wp_cache_delete(int|string $key, string $group = ''): bool
{
    return true;
}

function wp_delete_file(string $file): void
{
    if (is_file($file)) {
        unlink($file);
    }
}

function untrailingslashit(string $value): string
{
    return rtrim($value, '/\\');
}

function get_transient(string $name): mixed
{
    return GafferSearchTestWordPress::$options["_transient_{$name}"] ?? false;
}

function set_transient(string $name, mixed $value, int $expiration = 0): bool
{
    GafferSearchTestWordPress::$options["_transient_{$name}"] = $value;
    return true;
}

function delete_transient(string $name): bool
{
    unset(GafferSearchTestWordPress::$options["_transient_{$name}"]);
    return true;
}

function wp_raise_memory_limit(string $context = 'admin'): string|false
{
    return false;
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    GafferSearchTestWordPress::$filters[$hook][] = [$callback, $args];
    return true;
}

function remove_filter(string $hook, callable $callback, int $priority = 10): bool
{
    foreach (GafferSearchTestWordPress::$filters[$hook] ?? [] as $i => [$registered]) {
        if ($registered === $callback) {
            unset(GafferSearchTestWordPress::$filters[$hook][$i]);
            return true;
        }
    }
    return false;
}

function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $callback, $priority, $args);
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    foreach (GafferSearchTestWordPress::$filters[$hook] ?? [] as [$callback, $count]) {
        $value = $callback(...array_slice([$value, ...$args], 0, $count));
    }
    return $value;
}

function do_action(string $hook, mixed ...$args): void
{
    GafferSearchTestWordPress::$actions[$hook] = (GafferSearchTestWordPress::$actions[$hook] ?? 0) + 1;
    GafferSearchTestWordPress::$running[]      = $hook;
    try {
        foreach (GafferSearchTestWordPress::$filters[$hook] ?? [] as [$callback, $count]) {
            $callback(...array_slice($args, 0, $count));
        }
    } finally {
        array_pop(GafferSearchTestWordPress::$running);
    }
}

function doing_action(?string $hook = null): bool
{
    return $hook === null ? GafferSearchTestWordPress::$running !== [] : in_array($hook, GafferSearchTestWordPress::$running, true);
}

function did_action(string $hook): int
{
    return GafferSearchTestWordPress::$actions[$hook] ?? 0;
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function _doing_it_wrong(string $function, string $message, string $version): void
{
    GafferSearchTestWordPress::$doingItWrong[] = "{$function}: {$message}";
}

/** @return array{basedir: string, baseurl: string} */
function wp_upload_dir(mixed $time = null, bool $create = true): array
{
    return ['basedir' => GafferSearchTestWordPress::$uploads, 'baseurl' => 'https://example.test/wp-content/uploads'];
}

function plugins_url(string $path = '', string $plugin = ''): string
{
    return 'https://example.test/wp-content/plugins/gaffer-search/' . ltrim($path, '/');
}

function get_current_blog_id(): int
{
    return GafferSearchTestWordPress::$site;
}

// A single site: switch_to_blog() and get_sites() are left undefined, as WordPress does.
function is_multisite(): bool
{
    return false;
}

function wp_clear_scheduled_hook(string $hook, array $args = []): int
{
    GafferSearchTestWordPress::$unscheduled[] = $hook;
    unset(GafferSearchTestWordPress::$events[$hook]);
    return 0;
}

function wp_unschedule_hook(string $hook): int
{
    GafferSearchTestWordPress::$unscheduled[] = $hook;
    return 0;
}

function get_queried_object(): mixed
{
    return GafferSearchTestWordPress::$queried;
}

function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false
{
    return json_encode($value, $flags, $depth);
}

function maybe_unserialize(mixed $data): mixed
{
    return is_string($data) && preg_match('/^(a|s|i|b|d|N):/', $data) === 1 ? unserialize($data) : $data;
}

/** @return list<WP_Term> The known terms of $args['taxonomy']. */
function get_terms(array $args = []): array
{
    return array_values(array_filter(GafferSearchTestWordPress::$terms, static fn(WP_Term $term): bool => $term->taxonomy === ($args['taxonomy'] ?? null)));
}

/** @return list<int> Nearest first. */
function get_ancestors(int $id, string $object_type = '', string $resource_type = ''): array
{
    $ancestors = [];
    while (($id = GafferSearchTestWordPress::$parents[$id] ?? 0) > 0) {
        $ancestors[] = $id;
    }
    return $ancestors;
}

function is_taxonomy_hierarchical(string $taxonomy): bool
{
    return in_array($taxonomy, GafferSearchTestWordPress::$hierarchical, true);
}

final class WP_Post
{
    public int $ID = 0;
    public string $post_title = '';
    public string $post_content = '';
    public string $post_excerpt = '';
    public string $post_type = 'post';
    public string $post_date = '2026-01-01 12:00:00';
    public string $post_date_gmt = '2026-01-01 11:00:00';
}

/** Finds GafferSearchTestWordPress::$posts by `post__in` and `post_type`, in ID order, one page. */
final class WP_Query
{
    /** @var list<WP_Post> */
    public array $posts = [];
    public int $max_num_pages = 1;

    /** @param array<string, mixed> $args */
    public function __construct(array $args)
    {
        $ids   = (array) ($args['post__in'] ?? []);
        // ContentIndexBuilder's keyset (its posts_where filter, which needs a database).
        $after = (int) ($args['gaffer_search_after'] ?? 0);
        $posts = GafferSearchTestWordPress::$posts;
        ksort($posts);
        foreach ($posts as $post) {
            if ($post->ID > $after && ($ids === [] || in_array($post->ID, $ids, true)) && in_array($post->post_type, (array) ($args['post_type'] ?? ['post']), true)) {
                $this->posts[] = $post;
            }
        }
        $this->posts = array_slice($this->posts, 0, (int) ($args['posts_per_page'] ?? 10));
        $this->args  = $args;
    }

    /** @var array<string, mixed> */
    private array $args = [];

    public function get(string $key, mixed $default = ''): mixed
    {
        return $this->args[$key] ?? $default;
    }
}

function get_permalink(WP_Post|int $post): string
{
    return 'https://example.test/?p=' . ($post instanceof WP_Post ? $post->ID : $post);
}

function get_the_post_thumbnail_url(WP_Post|int $post, string $size = 'post-thumbnail'): string|false
{
    return false;
}

function get_post_meta(int $id, string $key = '', bool $single = false): mixed
{
    return GafferSearchTestWordPress::$meta[$id][$key] ?? '';
}

/** @return list<WP_Term>|false */
function get_the_terms(WP_Post|int $post, string $taxonomy): array|false
{
    $ids   = GafferSearchTestWordPress::$postTerms[$post instanceof WP_Post ? $post->ID : $post][$taxonomy] ?? [];
    $terms = array_values(array_filter(array_map(static fn(int $id): ?WP_Term => GafferSearchTestWordPress::$terms[$id] ?? null, $ids)));
    return $terms !== [] ? $terms : false;
}

function strip_shortcodes(string $content): string
{
    return $content;
}

function get_term(int $term): ?WP_Term
{
    return GafferSearchTestWordPress::$terms[$term] ?? null;
}

/** @return list<int> */
function get_term_children(int $term, string $taxonomy): array
{
    $children = [];
    foreach (GafferSearchTestWordPress::$parents as $child => $parent) {
        if ($parent === $term) {
            $children = [...$children, $child, ...get_term_children($child, $taxonomy)];
        }
    }
    return $children;
}

/**
 * @param  int|list<int> $terms
 * @return list<int>
 */
function get_objects_in_term(int|array $terms, string|array $taxonomies): array
{
    $objects = [];
    foreach (GafferSearchTestWordPress::$postTerms as $post => $byTaxonomy) {
        foreach ($byTaxonomy as $taxonomy => $ids) {
            if (in_array($taxonomy, (array) $taxonomies, true) && array_intersect((array) $terms, $ids) !== []) {
                $objects[] = $post;
            }
        }
    }
    return $objects;
}

/** @param list<mixed> $args */
function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool
{
    GafferSearchTestWordPress::$single[] = ['time' => $timestamp, 'hook' => $hook, 'args' => $args];
    return true;
}

function wp_next_scheduled(string $hook, array $args = []): int|false
{
    foreach (GafferSearchTestWordPress::$single as $event) {
        if ($event['hook'] === $hook && $event['args'] === $args) {
            return $event['time'];
        }
    }
    return GafferSearchTestWordPress::$events[$hook]['time'] ?? false;
}

function wp_get_schedule(string $hook, array $args = []): string|false
{
    return GafferSearchTestWordPress::$events[$hook]['interval'] ?? false;
}

/** @return array<string, array{interval: int, display: string}> */
function wp_get_schedules(): array
{
    return ['hourly' => ['interval' => 3600, 'display' => 'Hourly'], 'daily' => ['interval' => 86400, 'display' => 'Daily']];
}

function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
{
    GafferSearchTestWordPress::$events[$hook] = ['time' => $timestamp, 'interval' => $recurrence];
    return true;
}
