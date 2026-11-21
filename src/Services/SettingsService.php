<?php

declare(strict_types=1);

namespace GafferSearch\Services;

/** @internal Not part of the public API (see API.md): may change in any version. */
class SettingsService
{
    private const string OPTION_KEY = 'gaffer_search_options';

    /** @var array<string, mixed>|null */
    private ?array $cache        = null;
    private ?string $searchSecret = null;
    private ?string $tokenSecret  = null;

    /**
     * The index folder defaults to {uploads}/gaffer-search, resolved through wp_upload_dir() so the
     * UPLOADS constant and the upload_path option are respected.
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'index_dir'   => wp_upload_dir(null, false)['basedir'] . '/gaffer-search',
        ];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->setMany([$key => $value]);
    }

    /** @param array<string, mixed> $pairs */
    public function setMany(array $pairs): void
    {
        $raw = get_option(self::OPTION_KEY, []);
        foreach ($pairs as $key => $value) {
            $raw[$key] = $value;
        }
        update_option(self::OPTION_KEY, $raw);
        $this->cache = null;
    }

    /** @param list<string> $keys */
    public function removeMany(array $keys): void
    {
        if ($keys === []) {
            return;
        }
        $raw = get_option(self::OPTION_KEY, []);
        foreach ($keys as $key) {
            unset($raw[$key]);
        }
        update_option(self::OPTION_KEY, $raw);
        $this->cache = null;
    }

    /**
     * The settings, with `index_dir` from GAFFER_SEARCH_INDEX_DIR when that's defined: a folder on the
     * server's local disk (several web servers each keep their own copy of the indexes; SQLite
     * must never be read over a network file system), one subfolder per site.
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = array_merge($this->defaults(), get_option(self::OPTION_KEY, []));
            if (defined('GAFFER_SEARCH_INDEX_DIR') && is_string(GAFFER_SEARCH_INDEX_DIR) && GAFFER_SEARCH_INDEX_DIR !== '') {
                $this->cache['index_dir'] = untrailingslashit(GAFFER_SEARCH_INDEX_DIR) . '/' . get_current_blog_id();
            }
        }
        return $this->cache;
    }

    public function searchSecret(): string
    {
        if ($this->searchSecret !== null) {
            return $this->searchSecret;
        }
        $stored = get_option('gaffer_search_secret');
        if (!$stored || !is_string($stored)) {
            $stored = bin2hex(random_bytes(16));
            update_option('gaffer_search_secret', $stored, false);
        }
        $this->searchSecret = $stored;
        return $this->searchSecret;
    }

    public function tokenSecret(): string
    {
        if ($this->tokenSecret !== null) {
            return $this->tokenSecret;
        }
        $stored = get_option('gaffer_search_token_secret');
        if (!$stored || !is_string($stored)) {
            $stored = bin2hex(random_bytes(16));
            update_option('gaffer_search_token_secret', $stored, false);
        }
        $this->tokenSecret = $stored;
        return $this->tokenSecret;
    }

    public function rotateSearchSecret(): void
    {
        delete_option('gaffer_search_secret');
        $this->searchSecret = null;
    }

    public function rotateTokenSecret(): void
    {
        delete_option('gaffer_search_token_secret');
        $this->tokenSecret = null;
    }
}
