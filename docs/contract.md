# The engine contract

How Gaffer talks to this plugin. It is the plugin's whole theme-facing surface: themes talk to
Gaffer (`Gaffer\Search`), Gaffer talks to the engine through this contract, and nothing else of
the plugin is public. See `design.md` for why.

The engine indexes and searches; it knows nothing of pages. Archive URLs (the canonical, page
links, redirects of old URLs) and whether search engines index a page are Gaffer's: they're
policy that changes per site, and a frozen contract is the wrong place for it.

**Contract 1.** This document is the specification; `tests/contract/` holds the fixtures that
enforce it. The rules below hold from the plugin's first release (its first tag) on;
before it, contract 1 was reset once (2026-11-03: archive URLs and SEO moved to Gaffer).

## The rules

1. **It only grows.** Nothing in this document is ever changed or removed. New things are
   added: an optional key, a new method, a new response field, a new error code. When a meaning
   has to change, a new key is added next to the old one, and the old one keeps working.
2. **New things are detected, not versions compared.** Everything added after the first release
   is a *feature* with a name (see [Features](#features)); Gaffer calls
   `supports('name')` before using it. An older engine just lacks the feature.
3. **Tolerant readers.** Both sides ignore keys they don't know. A response may carry more than
   this document lists.
4. **Data only.** Arguments and results are arrays of scalars (and the closures in
   `define()`). No plugin class, no library class (`Fuzor\…`) crosses the boundary.
5. **The number is an emergency hatch.** If a change can't be made additively, contract 2 is
   written next to this one and the engine keeps serving contract 1 for as long as a supported
   Gaffer asks for it.

Gaffer and the plugin keep their own version numbers and release independently. Any Gaffer works
with any plugin that speaks contract 1 and has the features that Gaffer checks for.

## Finding the engine

```php
$engine = apply_filters('gaffer_search_engine', null, 1);
```

Returns an object with the methods below, or `null` (the plugin is inactive, or doesn't speak
contract 1). Available from `plugins_loaded` on. Gaffer asks once per request and keeps the object.

## Shared shapes

### Filter

A map from field to condition, the same form a token stores:

| Condition | Meaning |
|---|---|
| `'field' => 'value'` | equals |
| `'field' => ['a', 'b']` | any of |
| `'field' => ['not' => 'a']`, `['not' => ['a', 'b']]` | none of |
| `'field' => ['min' => 10, 'max' => 50]` (either may be missing) | a numeric range, inclusive |
| `'field' => ['all' => ['a', 'b']]` | feature `filter_groups`: every one of (a field with several values per document) |

The conditions of a map all hold (AND). With feature `filter_groups`, three keys hold groups
instead of a field's condition, each made of filters of this same shape, nested up to 4 levels
with up to 20 entries per group:

| Group | Meaning |
|---|---|
| `'any' => [Filter, Filter, …]` | at least one entry holds |
| `'all' => [Filter, Filter, …]` | every entry holds (two conditions on one field) |
| `'not' => Filter` | the entry doesn't hold (all of its conditions together); a document without a field passes `not` of a condition on it |

So `any`, `all` and `not` are never field names (`problems()` names one). In a visitor's filter
(the browser's `filter[]`, `search()`'s `filter`) an empty group, a deeper one or the entries past
20 are left out, like other invalid input, and a filter of more than 64 conditions loses its
groups (with a warning). A locked filter (`token()`'s `filter`) with a group past these limits
finds nothing: a lock is never cut short. Facet counts: a field
with one condition of its own in the map (outside groups; `any` of values of one field counts as
that field's list) is counted without it, as before; a field inside a group, or with `all`, is
counted over the filtered result.

```php
'filter' => [
    'stock_status' => 'instock',
    'tags'         => ['all' => ['sale', 'new']],
    'any'          => [['on_sale' => '1'], ['price' => ['max' => 20]]],
    'not'          => ['category' => 'outlet', 'color' => 'rood'],
],
```

Values of taxonomy fields are term slugs, never names.

### Sort

A list of `'field:asc'` / `'field:desc'`, applied in order. Text fields sort by their
`{field}_sort` key (`title_sort:asc`); only fields the index declares sortable work.

### Request

What a search is made of. `search()` and `token()` take the keys that apply to them.

| Key | Type | Meaning |
|---|---|---|
| `query` | string | the search text; `''` searches everything (`search()` only; the browser sends `q`). Each word is searched once, at most 32 different words, with a warning when words are left out; text that isn't valid UTF-8 searches as empty |
| `limit` | int | hits per page, 1–100; default 10 |
| `offset` | int | `search()` only; 0–10000 |
| `sort` | Sort | `search()` only; the visitor's sort in the browser |
| `default_sort` | Sort | used while there is no query and no sort: ranking without words |
| `filter` | Filter | `token()`: locked, the browser can't change these fields. `search()`: the visitor's filter, as the browser's `filter[]` (`visibility` and `term` win; a field the index doesn't filter on is left out with a warning) |
| `visibility` | `'search'` \| `'catalog'` | products: hide what WooCommerce's catalog visibility hides from that use (`catalog` also hides out-of-stock products when WooCommerce is set to) |
| `term` | int | a term ID: locks the field the index stores that term's taxonomy in to that term (a category archive). A term that doesn't exist, or whose taxonomy the index doesn't store, finds nothing: a lock is never dropped |
| `facets` | list of fields | count these fields' values (`facets`, `facetStats`, `labels` in the response) |
| `facet_stats` | list of fields | feature `facet_stats`: only `facetStats` (min/max) for these numeric fields, the same as `facets` gives, without counting their values (a price slider's ends); a field also in `facets` gets it from there |
| `attributes` | list of fields | the stored fields each hit carries; default: every field except long text |
| `ttl` | int, seconds | `token()`: the token stops working after this (a page that mustn't be cached for others) |
| `limits` | list of int | the page sizes the browser may choose with `limit` (each clamped like `limit`); any other keeps `limit` |
| `facet_values` | map field → int | how many values `facets` lists for that field, most frequent first; default 100, at most 1000 |
| `max_docs` | int | how many of the best-matching documents a search with words ranks by relevance first; `total`, `facets`, `sort` and paging cover every match (past these, relevance may differ from an exhaustive ranking); default 500, at most 5000. More gives a large catalog the same first page as an exhaustive search, at some speed |

An unknown key is ignored; `problems()` doesn't see requests, so the fixtures cover them.

### SearchResponse

| Field | Type | Meaning |
|---|---|---|
| `query` | string | |
| `total` | int | matching documents, exact up to 10,000 |
| `offset`, `limit` | int | as used, after clamping |
| `results` | list of Hit | this page |
| `facets` | map field → map value → count | for the requested facets; always a map |
| `facetStats` | map field → `{min, max}` | numeric facets and `facet_stats` fields: bounds over the results (a field's own filter left out, as for its facet) |
| `labels` | map field → map slug → name | display names for every slug in `facets` and in the request's filter |
| `exhaustive` | bool | false when the page's ranking is approximate: a broad search with words ranked its best candidates (`max_docs`) instead of every match; `total` is still exact. Always true without words |
| `approximateFacets` | list of fields | facets whose counts (and `facetStats`) were computed over a capped set of the matches, so they are approximate |
| `truncatedFacets` | list of fields | facets that have more values than they list (`facet_values`) |
| `warnings` | list of strings | ignored options, undeclared fields: a misconfiguration, not an error |
| `code` | string | feature `search_codes`, `search()` only: the search couldn't run, and the response is empty for that reason, not because nothing matched: `index_not_built` or `search_failed` (the endpoint's codes, below). Missing when it searched |

**Hit:** `id` (int) and the stored fields `attributes` asks for: text as plain text, numbers as numbers (a whole float may arrive as an int, as JSON has one number type), taxonomy fields as lists of slugs; `labels` (the hit's
fields that have names (taxonomy fields, `labels`), as names, same shape); `formatted.title` and `formatted.snippet` (safe HTML with the
matched words in `<mark>`). Fields added by `define()`'s `document` callable (rendered cards) are
returned as stored.

A search on an index that isn't built returns this shape with no results, never an error (with
feature `search_codes`, and its `code`).

## Methods

### `supports(string $feature): bool`

Whether the engine has a feature added after contract 1's first release.

### `define(array $indexes): void`

The site's indexes, for this request. Call it before `init` (Gaffer calls it while the theme
boots); the engine reads definitions from `init` on, for searches, builds and WP-CLI.
Every request that may search or build calls it; a build that runs in a request without
definitions keeps the old index (see `status()`).

`$indexes` maps an index name (`[a-z0-9_]+`) to a definition:

| Key | Type | Meaning |
|---|---|---|
| `type` | `'products'` \| `'posts'` | required. `products`: WooCommerce products with prices, stock, visibility and attributes; `posts`: any post types |
| `label` | string | a display name; default: the name |
| `post_types` | list | `posts`: which; default `['post']` |
| `language` | string | ignored: text is indexed as written, without stemming or stop words (stemming went with Fuzor 2's stemmers). `problems()` names it, and the index is defined without it. Word forms: the last word is a prefix and typos find many; `synonyms` for the rest |
| `searchable` | list of fields | the fields the words are searched in; default: every text field |
| `facets` | list of fields | fields to filter and count on besides the ones the type makes filterable itself |
| `sortable` | list of fields | text fields that get a `{field}_sort` key |
| `meta` | map meta key → field \| `[field, 'int'\|'float'\|'string']` | post meta stored as fields |
| `taxonomies` | map taxonomy → field | taxonomies stored as fields (slugs; ancestors included for hierarchical ones) |
| `direct_terms` | list of taxonomies | hierarchical taxonomies of `taxonomies` stored with only the assigned terms, without ancestors: a `term` lock then finds the term's own posts, not its children's (WooCommerce's archives with `include_children: false`) |
| `fields` | map field → `'string'`\|`'int'`\|`'float'` | fields the `document` closure adds besides cards (a price per unit for a filter), stored as that type; `searchable`, `facets` and `sortable` may name them. A document without the field (or with null, or a value that isn't a number for `int`/`float`) doesn't have it |
| `synonyms` | `{equivalences?: list<list<string>>, one_way?: map<string, list<string>>}` | whole words that match each other, or a word that also finds others |
| `thumbnail_size` | string | `products`: the image size of the `thumbnail` field; default the original |
| `variation_price` | `'min'` \| `'default'` | `products`: which variation a variable product's `price`, `regular_price` and `sale_price` are: its cheapest, or its default one's |
| `rebuild` | `'hourly'` \| `'twicedaily'` \| `'daily'` \| `''` | a full rebuild on this schedule besides the updates per change; default `''` |
| `version` | string | anything that changes when the documents would: when it differs from the version the index was built from, the engine rebuilds in the background and keeps serving the old index until the new one is ready |
| `document` | `?Closure(array $document, string $index): ?array` | adds fields to each document as it is built (the rendered cards); returns the document, or null to leave that post out of the index. Runs during builds: in WP-cron and WP-CLI, with the theme loaded. An exception fails the build: the old index stays, `status()` has the error |
| `labels` | `?Closure(string $index): array` | the display names of values of fields without terms (`meta`, `fields`, the built-in `stock_status`): returns field → map value → name. Runs whenever the engine writes an index's names (builds, and updates that meet a value without a name), in the same context as `document` (the site and language of the file being written); a field the index stores terms in keeps its term names. Names then go out like term names (`labels` in responses, `hit.labels`) |

Documents have `id`, `title`, `url`, `thumbnail`, `date`, the fields `meta` and `taxonomies` map
and a `{field}_sort` key per `sortable` field. `posts` also have `content`, `excerpt` and
`post_type`; `products` have `description`, `short_description`, `visibility`, `menu_order`,
`total_sales` (sortable: `total_sales:desc` is popularity), and from WooCommerce's product
objects (never post meta) the standard fields, each one filterable and sortable, and missing
when the product has no value:

| Field | Type | Meaning |
|---|---|---|
| `price` | float | the current price; a variable product's is one shown variation's: its cheapest, or with `variation_price: 'default'` the one its default attributes select |
| `regular_price`, `sale_price` | float | the same product's or variation's; `sale_price` missing without one |
| `on_sale` | `'yes'` \| `'no'` | WooCommerce's "Sale!" badge (`is_on_sale()`) |
| `stock_status` | string | `instock`, `outofstock`, `onbackorder` |
| `sku` | string | |
| `rating` | float | the average review rating; missing without reviews |

A `meta` line for one of these names, or for the post meta WooCommerce keeps them in (`_price`,
`_regular_price`, `_sale_price`, `_stock_status`, `_sku`, `_wc_average_rating`), is a problem,
but `define()` still defines the index, without that line: the built-in field wins, so a plugin
update never undefines the index of a theme written before.

### `problems(array $indexes): list<string>`

What is wrong with these definitions, in words, e.g. `products: facet "colour" is not a field of
the index (did you mean "color"?)`. Empty when nothing is. For `doctor`: the engine knows its
fields, so Gaffer keeps no list of them.

### `status(): array`

Per defined index:

| Key | Type | Meaning |
|---|---|---|
| `built` | bool | a usable index exists |
| `documents` | int | |
| `version` | ?string | the `version` it was built from |
| `building` | bool | a build is running |
| `error` | ?string | the last build's error, or why a build was skipped (no definitions in that request) |
| `pending` | int | feature `pending_updates`: content changes logged but not yet applied to this server's index (WP-cron or `wp gaffer-search update` applies them within seconds) |
| `pending_since` | ?int | feature `pending_updates`: when the oldest of them was logged (Unix time), null when none: an old one means the updates don't run here |
| `drift` | ?int | feature `health_checks`: how many documents of the last daily sample (about 60, compared with the database) differed: a write that fires no hook (direct SQL, an import past WordPress). They were put in the update log, so the next update fixes them; more than 0 means such writes happen. Null before the first check |
| `drift_checked` | ?int | feature `health_checks`: when that sample was taken (Unix time), null before the first one |
| `served` | ?list<string> | feature `health_checks`: the plugin's folders (relative to `wp-content`) that served a probe file to a request like a visitor's in the last daily check: an index file there can be downloaded, hidden documents too (nginx ignores the deny `.htaccess`). Empty: none did; null: not checked yet, or no folder inside the web root |

### `endpoint(): string`

The URL the browser searches (see [The browser side](#the-browser-side)).

### `search(string $index, array $request): array`

A search from PHP (a server-rendered page, a slider): the same code and results as the endpoint.
Returns a SearchResponse. For the first page of an archive, `filter` holds the visitor's filter
from the URL; `visibility` and `term` still win over it, as a token's lock does in the browser.

### `token(string $index, array $request): string`

A signed token for the browser, locking `limit`, `default_sort`, `filter`, `visibility`, `term`,
`facets`, `facet_stats` and `attributes` (and `ttl`). The visitor can change only `q`, `offset`, `sort[]` and
`filter[]` for fields the token doesn't lock.

## The browser side

The browser searches `endpoint()` with GET:

| Parameter | Meaning |
|---|---|
| `token` | required |
| `q` | the search text |
| `offset` | |
| `sort[]` | Sort, repeatable |
| `filter[field]`, `filter[field][]`, `filter[field][min]`, `filter[field][max]` | the visitor's filter; locked fields can't be changed |
| `filter[field][all][]`, `filter[any][0][field]…`, `filter[all][0][field]…`, `filter[not][field]…` | feature `filter_groups`: the same Filter as a query string. The visitor's groups apply on top of the token's filter (they only narrow it); a group naming a field the index doesn't filter on is left out with a warning |
| `limit` | one of the token's `limits`; any other is ignored |

Answers a SearchResponse as JSON (plus `time`), or an error: `{"error": "<message>",
"code": "<code>"}` with this status:

| `code` | Status | Meaning |
|---|---|---|
| `invalid_token` | 400 | the signature doesn't verify (the secret was rotated): the page is stale |
| `token_expired` | 400 | a `ttl` token ran out: the page is stale |
| `token_required` | 400 | |
| `index_missing` | 400 | the token names no index |
| `language_unknown` | 400 | the index has no file for the token's language (the site gained or lost languages since the page was made): the page is stale |
| `index_unknown` | 503 | the token names an index the site doesn't define |
| `index_not_built` | 503 | |
| `search_failed` | 503 | details in the PHP error log |
| `config_missing` | 503 | the endpoint can't find its config |

Unknown codes are possible later: treat any other 4xx as a stale page and any other 5xx as a
temporary failure. Every token the engine signs carries `c: 1`; the endpoint answers each token
in the shape of its contract, so pages cached before a plugin update keep working.

## Features

Added after contract 1's first release, newest last. Each: the name `supports()` takes and what it
adds; from the fourth on, the plugin version that added it. Every release has the first three.

- `facet_stats`: the request key `facet_stats`, a numeric field's `facetStats` without its value counts. On a whole catalog's price, ~6× cheaper than the facet (Fuzor `352927d`); a narrow match costs the same.
- `filter_groups`: in a Filter, `'field' => ['all' => [...]]` and the groups `any`, `all` and `not` (Fuzor's `Filter`), from PHP, in tokens and in the browser's `filter[]`. A Filter without them means and costs what it did.
- `pending_updates`: `status()`'s `pending` and `pending_since`: the content changes an index hasn't taken in yet. The update job runs from WP-cron, which a password-protected or offline site never runs: the index then misses every change until a rebuild.
- `search_codes` (0.2.0): `search()`'s `code`: an empty response because the index isn't built or the search failed, so a page built from it (an archive, a slider) can stay out of page caches and search engines instead of being kept as an empty page.
- `health_checks` (0.2.0): `status()`'s `drift`, `drift_checked` and `served`, from a daily job per site: what nothing else notices (content changed without a hook, index files served over HTTP).

## Fixtures

`tests/contract/` holds one JSON file per case: a call (a method with its arguments, or an
endpoint request) against the test index, and what the answer must contain.

```json
{
    "call": "search",
    "args": ["products", {"query": "dress", "limit": 2, "attributes": ["id", "title"]}],
    "expect": {"total": 3, "results": [{"id": 12, "title": "Red dress"}]}
}
```

- `expect` is a subset: the answer may have more keys (rule 3), never less, never different
  values. Lists match by position.
- Most cases run against one fixture index. A case with `setup.build` (a definition and posts)
  defines its own index through `define()` and builds it with the real builders first: for what
  the contract promises about builds (`direct_terms`, `fields`).
- `tests/ContractTest.php` runs every file. A new case is recorded from the real answer with
  `RECORD_CONTRACT=1 composer test`, which only writes files that don't exist yet. **A fixture
  is never edited or deleted**: a failing one means the change breaks contract 1.
- Gaffer copies the files into its own tests and runs its code against a fake engine that
  answers from them, so both sides are held to the same cases.

## Changing the contract

1. Write the addition here, under its method or shape, and under [Features](#features) with its
   name.
2. Implement it, with `supports()` returning true for it.
3. Record fixtures for it.
4. In Gaffer: check `supports()` before using it, and let `doctor` say which plugin version
   brings it when it's missing.
