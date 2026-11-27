# Gaffer Search — public API

The plugin is Gaffer's search engine. Themes never call it: they configure `config/search.php`
and use `Gaffer\Search`, and Gaffer talks to the plugin through the engine contract.

## What is public

| Area | Items | Documented in |
|---|---|---|
| The engine contract | the object `apply_filters('gaffer_search_engine', null, 1)` returns: its methods, the definitions and requests they take, the responses they give; the endpoint's parameters, response and error codes (the browser side) | `docs/contract.md`, held by `tests/contract/` |
| Hosting | the WP-CLI commands a server runs from its crontab: `wp gaffer-search update`, `rebuild` and `status` (their names, arguments and exit codes), and the `wp-config.php` constants / environment `GAFFER_SEARCH_INDEX_DIR`, `GAFFER_SEARCH_CONFIG_DIR` | `docs/hosting.md` |

The other WP-CLI commands (`verify`, `describe`, `search`, `clean`, `rotate-secret`), their
options and output, and `status --format=json`'s shape are tooling: they may change in a minor
release, with an entry in `docs/migration.md`.

Everything else is internal and may change in any version: every class (`@internal`, checked by
`tests/ApiTest.php`), every `gaffer_search_*()` function, the hooks the plugin uses itself, the
the endpoint files, the index files' format.

`api.txt` lists the public items; `tests/ApiTest.php` compares it with the code, so a change
fails the tests until `api.txt` is updated on purpose (`UPDATE_API=1 composer test`).

## How it changes

- **The contract only grows** (`docs/contract.md`, "The rules"): nothing in it is changed or
  removed; additions are features Gaffer detects with `supports()`. Recorded fixtures are never
  edited or deleted (`tests/contract/MANIFEST`). This holds before 1.0 too: live sites update the
  plugin automatically, while their Gaffer only changes when the theme is deployed.
- **Hosting** (the crontab commands, constants): before 1.0 breaking changes are allowed, each with an entry in
  `docs/migration.md`; from 1.0 only after a deprecation.
