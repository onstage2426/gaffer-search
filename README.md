# Gaffer Search for WordPress

Gaffer's search engine: fast search and faceted archives for WordPress and WooCommerce themes built on Gaffer, from an index the plugin builds
itself with [Fuzor](https://github.com/onstage2426/fuzor) (SQLite, BM25 ranking, typo tolerance,
facets). Searches are answered by small endpoints that don't boot WordPress, authorized by signed
tokens, so a searchbar or a filtered shop page stays fast on large catalogs.

- Indexes for posts, pages, custom post types and WooCommerce products: taxonomies, typed meta,
  variations in stock, customer groups.
- Updates per product a few seconds after a change; full rebuilds on a schedule or with WP-CLI.
- Multisite (one config and index per site) and WPML (one index per language).
- Server-rendered archives that the browser enhances: the same form works without JavaScript.
- Several web servers: each keeps its own index files on local disk.

## Requirements

PHP 8.5 (64-bit, with `mbstring`) with `pdo_sqlite` linked against SQLite 3.46 or newer (`php -r 'echo (new PDO("sqlite::memory:"))->query("select sqlite_version()")->fetchColumn();'`; check the PHP that serves the site and the one a system crontab runs), WordPress 6.0 or later. The dashboard says when the server falls short, and the plugin doesn't activate. WooCommerce and WPML are optional.

## How a site uses it

Only through [Gaffer](https://github.com/onstage2426/gaffer): the theme defines its indexes and
its shop archive in `config/search.php` and renders with `Gaffer\Search\Search` (`archive()`,
`cards()`, `searchbar()`) and Gaffer's search JavaScript. Gaffer renders the cards into the
index, keeps it current when the card template changes, and adds the archive's redirects,
canonical and robots. Gaffer's `gaffer-search` skill (in every Gaffer theme with this plugin)
has the details.

1. Install and activate the plugin (it checks the server's SQLite first).
2. Add `config/search.php` to the theme (see the skill) and deploy it.
3. Build the index: **Settings → Gaffer Search**, or `wp gaffer-search rebuild`.
4. `php gaffer doctor --wp` in the theme checks the definitions, the index and a rendered card.

## Documentation

- [docs/contract.md](docs/contract.md) — the engine contract Gaffer uses: the plugin's public API.
- [docs/hosting.md](docs/hosting.md) — the endpoint, caching, languages, multisite, cron, several servers, WP-CLI.
- [docs/migration.md](docs/migration.md) — what changes for a site per version.
- [API.md](API.md) — what is public and how it may change.

**On nginx**, block the index and config folders (`wp-content/uploads/gaffer-search`, `wp-content/gaffer-search`)
or keep indexes outside the web root with `GAFFER_SEARCH_INDEX_DIR`: an index file holds every stored
document. See "Endpoint URLs and config" in `docs/hosting.md`.

## License

MIT
