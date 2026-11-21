<?php

declare(strict_types=1);

namespace GafferSearch\Support;

/**
 * The display names of an index's slug values: `{field: {slug: name}}`, written by a build next
 * to the index file (`{slug}.labels.json`) and read by the endpoints and gaffer_search() to add
 * names to a response. Term names are public, and the file name carries the same secret suffix
 * as the index, so it lives in the index folder like the index itself.
 *
 * No WordPress functions here: the endpoints use this.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class Labels
{
    /** @var array<string, array{0: int, 1: array<string, array<string, string>>}> path → [mtime, labels] */
    private static array $cache = [];

    public static function path(string $indexPath): string
    {
        return (string) preg_replace('/\.db$/', '', $indexPath) . '.labels.json';
    }

    /** @return array<string, array<string, string>> Empty when the index has no labels file. */
    public static function read(string $indexPath): array
    {
        $path = self::path($indexPath);
        clearstatcache(true, $path);
        $mtime = @filemtime($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ($mtime === false) {
            return [];
        }
        if ((self::$cache[$path][0] ?? null) !== $mtime) {
            /** @var array<string, array<string, string>>|null $data */
            $data               = json_decode( (string) file_get_contents($path), true); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            self::$cache[$path] = [$mtime, is_array($data) ? $data : []];
        }
        return self::$cache[$path][1];
    }

    /**
     * Replace the labels file atomically (a reader sees the old or the new file, never half).
     *
     * @param array<string, array<string, string>> $labels
     */
    public static function write(string $indexPath, array $labels): bool
    {
        $path = self::path($indexPath);
        $tmp  = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, (string) json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.WP.AlternativeFunctions.json_encode_json_encode
            return false;
        }
        if (!rename($tmp, $path)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic replace
            @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return false;
        }
        unset(self::$cache[$path]);
        return true;
    }

    /**
     * Whether any of these documents has a value in a labelled field that $labels has no name
     * for (a term created after the labels were written).
     *
     * @param array<string, array<string, string>> $labels
     * @param list<array<string, mixed>>           $docs
     */
    public static function lacksNames(array $labels, array $docs): bool
    {
        foreach ($docs as $doc) {
            foreach ($labels as $field => $names) {
                foreach ( (array) ($doc[$field] ?? []) as $value) {
                    if (is_scalar($value) && !isset($names[ (string) $value])) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    public static function delete(string $indexPath): void
    {
        @unlink(self::path($indexPath)); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    }

    /**
     * The names for these values of one field, in the same shape: a slug or a list of slugs.
     * A value without a label (a non-taxonomy field, a label file older than the index) is
     * its own name.
     *
     * @param  array<string, string> $fieldLabels
     * @param  string|array<mixed>   $value
     * @return string|list<string>
     */
    public static function name(array $fieldLabels, string|array $value): string|array
    {
        return is_array($value)
            ? array_values(array_map(static fn(mixed $v): string => $fieldLabels[ (string) $v] ?? (string) $v, $value))
            : $fieldLabels[$value] ?? $value;
    }
}
