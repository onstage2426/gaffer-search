# Gaffer Search — docs

- **[contract.md](contract.md)** — the engine contract: everything Gaffer (and nothing else) uses,
  how it only grows, and its fixtures. This is the plugin's public API for code.
- **[hosting.md](hosting.md)** — running it: the endpoint and its config, caching, languages,
  multisite, updates after content changes, WP-CLI and system cron, several web servers,
  verifying an index, rotating secrets.
- **[migration.md](migration.md)** — what changed per version for sites.

Themes don't call the plugin: they configure `config/search.php` and use `Gaffer\Search` (see
Gaffer's `gaffer-search` skill). What exactly is public is in `API.md`; `api.txt` is its
machine-checked list.
