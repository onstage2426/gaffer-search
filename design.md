# Gaffer Search: the integration design

Status: agreed 2026-11-01 (decisions at the end), built by 2026-11-04. Kept for the why.

## What we want

- **Stable when only one side is updated.** A plugin update must not break a theme whose Gaffer
  is older, and a theme deploying a newer Gaffer must not break on an older plugin.
- **Focused.** The plugin it replaced was a general plugin for any theme. gaffer-search serves
  Gaffer themes only, so it can drop what was there for generality and hide what themes no longer need to see.
- **Automatic.** What every site wrote by hand (registering the index, rendering cards into it,
  the cards' version, SEO and redirect hooks, the archive's JavaScript) is done for the theme.
- **Gaffer's principles**: one way to do each thing, PHP prepares and Twig presents, config over
  code, checked by `doctor`, no hidden magic.

## Who updates when (the reason for the whole shape)

| Piece | How it updates | Consequence |
|---|---|---|
| Theme code + Gaffer | Together: Gaffer is locked in the theme's `composer.lock` and changes only when a developer runs `composer update` and deploys the theme. | Anything the theme calls is safest **in Gaffer**: it can only change in the same deploy as the theme code that uses it. |
| The plugin | On its own, any time, through WordPress's updater (auto-updates are on). On a network, one plugin serves sites whose themes may run different Gaffer versions. | The plugin must never break what an older Gaffer asks of it. |

So the boundary that has to be stable is **Gaffer ↔ plugin**, not theme ↔ plugin. Today it is
the other way round: themes call 25 `gaffer_search_*` functions, 13 hooks, builder classes and
`window.GafferSearch`, all of which an automatic plugin update can change underneath a live site.

## The shape: three layers

```
theme      config/search.php, the card template, the searchbar and archive markup,
           small Alpine components for UI state only
             │  Gaffer's public API (API.md, composer-locked)
Gaffer     Gaffer\Search\*: reads the config, renders cards, gives templates their data,
           SEO and redirects, the JS components, doctor checks, guideline, MCP tool
             │  the engine contract (versioned, data only, documented in docs/contract.md)
plugin     gaffer-search: indexes (builders, change tracking, languages, multisite,
           several servers), the endpoints, WP-CLI
```

- **Themes talk only to Gaffer.** No theme code calls the plugin. Its API is composer-locked, so a
  plugin update can't change it.
- **Gaffer talks to the plugin only through the contract**, never through plugin classes or
  functions. The plugin knows nothing about Gaffer either: Gaffer hands it callables and data.
- This is the model Gaffer already uses for Gravity Forms (`Gaffer\Forms`): the integration lives
  in Gaffer, the engine is the plugin.

Decided: the theme-facing part is in Gaffer. In the plugin, every automatic plugin update could
break theme code, and Gaffer's `doctor` and guidelines would describe an API whose version Gaffer
doesn't know.

## The engine contract (Gaffer ↔ plugin)

### Finding it

```php
$engine = apply_filters('gaffer_search_engine', null, 1); // contract 1
```

A filter, not a function or class: without the plugin it returns `null` and nothing fatals.

### A contract that only grows

The contract's number is not a package version. Gaffer and gaffer-search keep their own semver
and release independently; any Gaffer and any gaffer-search work together as long as both speak
contract 1, and the aim is that the contract never needs a 2:

- **Never change or remove, only add.** A new feature is a new optional key or a new method. When
  a meaning has to change, a new key is added next to the old one, and the old one keeps working.
- **Features are detected, not versions compared.** `$engine->supports('feature')`: Gaffer checks
  before using anything added after the first release. An older plugin turns off only that
  feature, and `doctor` says "update gaffer-search for …".
- **Tolerant readers.** Both sides ignore keys they don't know.
- **Small.** Only data crosses (arrays, scalars, closures). Builders, the URL format and indexing
  stay inside the plugin, where they change freely.
- **Enforced by tests.** The plugin keeps frozen request/response fixtures (`tests/contract/`, like
  `api.txt`): a new fixture passes, a changed or removed one fails CI. Gaffer runs its code against
  a fake engine built from the same fixtures.
- **The browser side the same way:** tokens and endpoint responses follow the same additive rule,
  so pages cached with an old token keep working after a plugin update.
- **The number is an emergency hatch.** Should a change ever be unavoidable, contract 2 ships next
  to 1 as an adapter, and the plugin keeps answering 1 for as long as a supported Gaffer asks for it.

So the plugin's public surface (the contract, WP-CLI, the `wp-config.php` constants) only grows,
and gaffer-search never needs a breaking major release for themes. Auto-updates stay on: that is
how fixes reach live sites.

### What contract 1 holds: data in, data out

Only arrays, scalars and closures cross the boundary, so either side can change internally.

| Method | What it does |
|---|---|
| `define(array $indexes)` | The site's indexes for this request (from `config/search.php`), each with its type, fields, facets, synonyms, rebuild interval, a `version` string and a `document` callable that adds fields to a document (the rendered cards). Called by Gaffer on `init`. |
| `problems(array $indexes): list<string>` | What's wrong with definitions, asked of the engine itself (unknown field, a facet that isn't filterable): `doctor` shows these, and Gaffer keeps no lists of the engine's fields. |
| `search(string $index, array $request): array` | A server-side search (the first page, sliders). The response shape is the same as the endpoint's. |
| `token(string $index, array $options): string` | A signed token for the browser. |
| `status(): array` | Per index: built, document count, the version it was built from, the last error. For `doctor`, the admin bar and the MCP tool. |

Archive URLs and SEO (the canonical, `noindex`, `/page/N/` and WooCommerce's params as 301s) were
first in the contract too (`archive()`, `url()`, `redirect()`). Two rounds of moving a large shop
showed they're per-site policy (later pages indexable, a sale page, a `?show=all` page, a filter
plugin's old URLs): each need became a plugin feature and a release. On 2026-11-03, before the
first release, they moved to Gaffer (`Gaffer\Search\ArchiveUrl`) and the contract was reset.

The `document` callable is how cards get into the index without the plugin knowing Gaffer: the
plugin calls it during builds (WP-cron, WP-CLI, the dashboard), where the theme and Gaffer are
loaded. When a build runs without the definitions (a request where the theme didn't load, e.g.
`wp --skip-themes`), the plugin **keeps the old index** and reports it in `status()` instead of
building one without cards.

## What the theme writes

### `config/search.php`

```php
return [
    'indexes' => [
        'products' => [
            'type' => 'products', // or 'posts'
            'searchable' => ['title', 'short_description', 'origin'],
            'facets' => ['price', 'category', 'on_sale'],
            'meta' => ['herkomst' => 'origin'], // price, stock_status, sku… are built in
            'taxonomies' => ['product_cat' => 'category'],
            'sortable' => ['title'],
            'synonyms' => [['garnalen', 'garnaal', 'gamba', 'scampi']],
            'rebuild' => 'twicedaily',
            'card' => 'product/card.twig', // rendered into each document
        ],
    ],
    // WooCommerce's shop, product categories and search results from an index
    'archive' => [
        'index' => 'products',
        'per_page' => 24,
        'sorts' => ['' => 'Populair', 'price:asc' => 'Prijs oplopend', 'price:desc' => 'Prijs aflopend'],
        'default_sort' => ['total_sales:desc', 'title_sort:asc'],
    ],
];
```

A shop theme's `inc/fuzor.php` (63 lines) becomes this file. Keys get stubs in Gaffer's
`config/search.php`, so `config:show` and `doctor` know them.

### Templates get their data from Gaffer

| Gaffer | Replaces (in a shop theme) |
|---|---|
| `Search::archive(): ?Archive` — the current shop/category/search page per `archive` config, null elsewhere. `Archive` has `token`, `endpoint`, `query`, `total`, `cards` (trusted `Markup`, no `|raw` needed), `initial` (the JSON for the component), `sorts`, `sort`. | `Theme\Search::archive()` and the template's token/offset/sort plumbing |
| `Search::cards(string $index, int $limit, string $sort, ?Term $term = null, ?Post $except = null): list<Markup>` | `Theme\Search::best_sellers()` |
| `Search::searchbar(string $index, int $limit = 8, array $facets = []): Searchbar` (`token`, `endpoint`, `results_url`) | `Theme\Search::form()` |
| automatic with `archive` config: WordPress's own search (`?s=`) redirects to the shop's results; canonical and robots (Yoast, or WordPress's own); `/page/N/` and WooCommerce params redirect | `search.php`, three hooks in `inc/fuzor.php` |
| the card's version: a hash of the card template and everything it includes (Gaffer's template parse tree, as `twig:lint` reads it); a deploy that changes any of them rebuilds the index | `Theme\Search::cards_version()` with its hand-written list |
| cards rendered as a visitor on the site's own scheme (also from WP-CLI without `--url`, which today stores `http://` theme URLs in cards on an HTTPS site) | `Theme\Search::with_cards()` |

Form markup stays in templates; Gaffer's objects give values and attributes
(`<form {{ search.form_attrs() }}>`), following Gaffer's "helpers output attributes, not tags".

### Card variants (audiences) stay the theme's

A shop theme renders each card twice (incl. and excl. VAT) and shows business customers the second.
That's the theme's business, so Gaffer offers only the hook point, in config:

```php
'card' => 'product/card.twig',
'card_variants' => [
    'business' => [Theme\Business::class, 'render_excl_vat'], // fn(Closure $render): string
],
'card_variant' => [Theme\Business::class, 'variant'],   // fn(): ?string, null = the default card
```

Gaffer stores one field per variant and picks the visitor's. Tokens for a non-default variant get
a `ttl` automatically (as that theme's `private_token()` did).

### JavaScript from Gaffer, composer-locked

Gaffer ships `resources/js/search.js` (Alpine factories: archive, searchbar, facet options, the
price slider, pages), which the theme imports through Vite from `vendor/onstage2426/gaffer`. It
renders hits' cards into a list and runs `htmx.process()` on them, so a theme's `archive.js`
disappears and `searchbar.js` keeps only its UI state. The plugin no longer puts a script on every
page.

Decided: JS from the Gaffer package, locked with the theme like the PHP.

## What the plugin drops or hides

Public now: 25 functions, 13 hooks, 7 classes, 14 JS members, token keys, response shapes. After:
**the contract, WP-CLI, the `wp-config.php` constants.** Specifically:

- **Internal:** every `gaffer_search_*` function, the builder classes, `IndexDefinition`, `Watch`,
  `BuildContext`, `LanguageAdapter`, the theme hooks (`register_indexes`, `index_document`,
  `index_version`, the `archive_*` filters), `window.GafferSearch`.
- **Gone** (were there for generality):
  - the dashboard's settings form (searchable fields, post types): definitions are code now; the
    dashboard keeps status, rebuild and verify;
  - the dashboard's token generator;
  - CORS (`cors_origin`): the endpoints serve their own site;
  - the facet-search endpoint and `createFacetSearch()` (no site uses them);
  - custom builders (`IndexBuilderInterface` for themes): `products` and `posts` cover the sites;
    a third type is added to the plugin when a site needs one;
  - `Audience` (customer-group visibility): decided against, deleted 2026-11-04.

## Failures, and what each looks like

| Situation | Result |
|---|---|
| Plugin missing or inactive | `Search::*` give an unavailable state: no cards, `available` false; templates show "search unavailable". Nothing fatals. `doctor` error, admin bar warning dot. |
| Plugin lacks a feature this Gaffer uses | Only that feature is off; `doctor` warns "update gaffer-search for …". |
| Plugin doesn't speak contract 1 at all (only after an emergency contract 2) | Same as missing, with the fix in the message. |
| Index not built yet, or building | Server side: empty results, and `status()` says why. Browser: Gaffer's JS handles `index_not_built` like any error. `doctor` error. |
| Card template changed | The version changes, the plugin rebuilds in the background and keeps serving the old index until the new one is ready (as today). |
| Build without the theme loaded | Old index kept, `status()` says why (see the contract). |
| Pages cached with old tokens after a plugin update | Still answered in the token's version. |

## Checks: the AI checks its own work

- `doctor`: the engine is there and speaks the contract; `problems()` for the config; every index
  built and from the current version; the card template renders for a sample document with strict
  variables (the same render `doctor --wp` uses); templates use `Search::*` (no plugin calls, no
  `|raw` for cards).
- An MCP tool `gaffer-search` (read-only): search an index as the browser would and see the hits,
  facets and timing, plus `status()`. The agent can check a synonym or a facet without a browser.
- The guideline and skill live in Gaffer next to the API they describe, so they always match the
  installed version (today they sit in Gaffer but describe the plugin's API).

## A shop theme, before and after

| Before | After |
|---|---|
| `inc/fuzor.php` 63 lines | `config/search.php` ~30 lines |
| `app/Search.php` 188 lines | gone; ~10 lines in `Theme\Business` for the excl.-VAT variant |
| `assets/js/components/archive.js` 40 lines | gone |
| `searchbar.js` 64 lines | UI state only, ~30 lines |
| `search.php` | gone |
| templates call `search.cards` with `|raw` and trust comments | `search.cards` are `Markup` |

## Order of work

1. The contract: `docs/contract.md` and its fixtures (in the plugin).
2. The plugin: the contract-1 engine over today's services (`src/Engine.php`, done
   2026-11-01), facet search and CORS dropped. The old theme API (functions, hooks,
   `window.GafferSearch`) keeps working next to it until the sites have switched.
3. Gaffer: `Gaffer\Search` (config stub, `Search`, `Archive`, `Searchbar`, card rendering and
   version, SEO and redirects), `resources/js/search.js`, `doctor` checks, guideline, skill, MCP
   tool, CHANGELOG.
4. The first shop site switches (done 2026-11-02: same products, order and card HTML as before on
   every archive, business variant and sliders checked; its search code went from 291 to 142
   lines; render diff, screenshots, ajax); then the starter (it gets the archive too) and a
   multisite + WPML test network.
5. Cleanup once no site uses the old theme API: remove the `gaffer_search_*` functions, the
   theme hooks, `window.GafferSearch` and the frontend script; rewrite the dashboard as a small
   status page (indexes, rebuild, verify, test search, secrets: the index and token builders go);
   `API.md` lists the contract, WP-CLI and the constants; `docs/php.md` keeps hosting only.
6. The plugin it replaced stays frozen; sites remove it once they've switched.

## Decisions (2026-11-01)

1. The theme-facing part is in Gaffer.
2. Versioning: a contract that only grows, with feature detection and frozen fixtures; the
   packages keep independent semver; plugin auto-updates stay on.
3. Search JavaScript comes from the Gaffer package.
4. The drop list above, as proposed.
