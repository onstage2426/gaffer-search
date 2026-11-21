# Hosting and running Gaffer Search

How the plugin runs on a server: where its files and config live, the endpoint, caching,
languages, multisite, updates after content changes, cron, several web servers, verifying an
index and rotating its secrets. Themes don't talk to the plugin: Gaffer does, through the engine
contract (`contract.md`); a theme defines its indexes in its `config/search.php`.

## Rotating secrets

Both are buttons on the dashboard's **Settings** tab.

- **Token signing secret:** every token signed before stops verifying (the endpoint answers
  `400` with `invalid_token`), and new tokens are signed with the new secret. Every page cached
  with an old token fails its searches until that cache is cleared, so rotate right before a
  full page-cache clear. Visitors with a page already open get `invalid_token` on their next
  search; Gaffer's search JavaScript reports it so the page can say "refresh".
- **Search secret:** the index file names are derived from it. Every existing index file becomes
  an orphan (listed on the dashboard, safe to delete), a rebuild of every index is scheduled
  right away, and searches return nothing until it finishes.

---

## Endpoint URLs and config

The endpoint's URL is the engine's `endpoint()` (Gaffer gives it to templates):
`{plugin URL}/endpoints/search.php`.

The endpoints run **without booting WordPress**. They read a config file WordPress writes per
site, `{WP_CONTENT_DIR}/gaffer-search/sites/{site ID}.php` (token secret, index folder, index file
names) — outside the plugin folder, so replacing the plugin can't delete it. A
single site is site 1. Every token carries the site that signed it (it can't be set), and the endpoint loads that site's file and verifies the token with that
site's secret. To find the folder without WordPress constants, an endpoint takes the folder that
contains the plugins folder. That holds for WordPress's default layout, Bedrock, and any custom
`WP_CONTENT_DIR` with the plugins folder directly inside it.

For any other layout (a `WP_PLUGIN_DIR` outside `WP_CONTENT_DIR`, or a symlinked plugin folder
served through nginx's `$realpath_root`), set the config **folder** explicitly with an environment
variable for PHP — both WordPress and the endpoints then use it:

```
GAFFER_SEARCH_CONFIG_DIR=/var/www/wp-content/gaffer-search
```

The dashboard warns when the layout needs it, and calls the endpoint on every load to confirm it
finds its config. Index files default to `{uploads}/gaffer-search/` (via `wp_upload_dir()`, so the
`UPLOADS` constant and the `upload_path` option are respected — on a network, each site's own
uploads folder).

**Disk space: twice the index.** A rebuild publishes the new file next to the old one
(`{index}.db.v-…`, `{index}.db` a symlink to the current one) and keeps the previous version, also
on a single server: searches that opened it while the rebuild finished still read it. It's deleted
by the next rebuild. (The snapshot generations of several servers, below, are separate.)

**The index folder must not be served.** An index file holds every stored document, also the
ones a token hides (customer groups, hidden products). The plugin puts a deny `.htaccess` and an
`index.php` in every folder it writes (index files, snapshots, endpoint configs), which covers
Apache and LiteSpeed. **nginx ignores `.htaccess`**: block the folders in the server config, or
keep the indexes outside the web root with `GAFFER_SEARCH_INDEX_DIR`:

```nginx
location ~ ^/wp-content/(uploads/(sites/\d+/)?gaffer-search|gaffer-search)/ { deny all; }
```

### Caching

Responses carry an `ETag` (the index file's state plus the request's parameters, in any order)
and `Last-Modified`, and answer `304` to a matching conditional request. Tokens without `ttl` get
`Cache-Control: public, max-age=60, s-maxage=300, stale-while-revalidate=60`: a CDN may serve a
response up to 5 minutes after the index changed.

- **Cloudflare doesn't cache `.php` URLs by default** (`cf-cache-status: DYNAMIC`). Add a cache
  rule: *URI Path* contains `/endpoints/search.php` → *Eligible
  for cache*, *Edge TTL: use cache-control header if present*, cache key with the full query
  string. Without it every request reaches PHP (still correct, just uncached).
- **A second `Cache-Control` header** (`max-age=0`, say) comes from the server, not the plugin:
  an `.htaccess` `Header set`/`ExpiresByType` rule, or a caching plugin's rules for PHP files.
  Exclude the endpoints from it, or the browser and CDN see two policies.
- Tokens with `ttl` are `private`: never in a shared cache.

### Languages (WPML)

On a site with languages, every index is **one file per language** behind one name: a build of
`products` builds the `nl`, `en-us` and `de` files, each with the site switched to that language.
Nothing changes in a theme:

- **Each file holds that language's posts** (and the posts of types the language plugin doesn't
  translate, in every language), with that language's URLs, terms and labels.
- **Every token carries its language** — the current language when it's signed, like the site; it
  can't be set — and the endpoint searches that language's file. Searches from PHP use the current language too.
- **WPML** is detected automatically; it's the only language plugin supported.
- Changing a post updates it **and its translations** in every language's file: a translation
  plugin syncs fields between translations (WCML: stock, prices) with queries that fire no hook.
  Verify and the dashboard cover every language.

### Multisite

Every site of a network has its own indexes, secrets, settings, index folder and endpoint config,
exactly as if it were a separate install; nothing is shared. A site's indexes are the ones its
theme defines (`config/search.php`, through Gaffer), so different sites can index different things.

- **Activation:** network-wide or per site. Each site sets itself up on its first request (endpoint
  config, cron schedules); no admin visit is needed. That happens on a site without definitions
  too (a non-Gaffer theme, or the theme before its switch): it indexes and serves nothing, but
  creates the queue table (`{prefix}gaffer_search_queue`), the options
  `gaffer_search_queue_version` and `gaffer_search_token_secret`, and
  `wp-content/gaffer-search/sites/`. Uninstalling removes them.
- **A request without definitions changes nothing:** while the live theme isn't on
  `config/search.php` yet (a staged copy is built and checked first), its requests leave the
  endpoint's indexes, the schedules and the index files as the staged copy's processes made them,
  so searches from the stage keep working. A theme that drops its indexes for good leaves them
  until the plugin is deactivated or the index is deleted on the dashboard. Network-wide deactivation clears every site's
  cron jobs; deleting a site deletes its endpoint config (WordPress removes its uploads, and so its
  index files).
- **Changes from another site:** code that edits site B while running on site A
  (`switch_to_blog()`) schedules the update on site B — for all of site B's indexes, because site
  A can't see which of them the change affects.
- **Network Admin → Settings → Gaffer Search** lists every site with its indexes, document counts
  and last update, and links to each site's own dashboard. Building happens per site.
- **WP-CLI** runs per site: `wp gaffer-search verify --url=https://example.com/shop/`. For every site:
  `wp site list --field=url | xargs -I{} wp gaffer-search verify --url={}`. (A `--network` flag can't
  exist: one process only loads one site's theme, and so only that site's indexes.)

---

## Automatic updates after content changes

A change to anything an index depends on updates the documents it touched, a few seconds later
— no configuration needed for the built-in builders:

- **What counts as a change:** saving, publishing, unpublishing, scheduling, trashing,
  restoring, or deleting a post of a watched post type; a change to a watched meta key or
  taxonomy on such a post; renaming, re-slugging or deleting a term of a watched taxonomy; a
  WooCommerce stock change (including stock reduced by an order). The built-in builders watch
  their post types (and, for products, `product_variation`, which updates the parent product),
  their `meta` keys and `_thumbnail_id`, and their taxonomies (plus `product_visibility` for
  products). Opening the editor (`_edit_lock`), autosaves, and revisions don't count.
- **How:** the hooks only record *which* posts changed, per index, in an update log per site
  (the table `{prefix}gaffer_search_queue`). At the end of the request one update job is scheduled
  (WP-cron) 5 seconds later; a burst of edits costs one job. The job reads the posts logged
  since its last position again (`documentsFor()`, in every language's file), replaces their
  documents and removes the ones that no longer belong (deleted, unpublished), up to 1,000 rows
  per run (more: the next run starts right away). The position is kept next to the index files
  (`{index}.state.json`), so each web server has its own ([Hosting](#hosting)); log rows are
  pruned after a day. The update re-reads from the database, so it doesn't matter how a post was saved
  — the editor, quick edit, the REST API, WooCommerce, ACF, or an importer.
- **Terms:** a renamed term only rewrites the labels (documents hold slugs). A new slug or a
  deleted term in a flat taxonomy (attributes, tags) updates the posts that have it. In a
  hierarchical taxonomy (categories) every descendant's posts store the term as an ancestor, so a
  new slug, a new parent, or a deletion there schedules a **full rebuild** a minute later.
- **Safety:** nothing heavy runs while a post is being saved, and a failure never reaches the
  editor — it's logged and shown on the dashboard, and the position only moves after the
  files are written, so the next run tries again.
  A full rebuild and an update never write at the same time, and a second rebuild (another
  tab, WP-CLI, cron) is refused before it builds anything: each takes the `flock()` of every
  language's file of the index first (it also works without a persistent object cache). An
  update that finds a build running tries again 30 seconds later, a scheduled rebuild 60 seconds
  later.
- **Delay:** about 5 seconds, plus however long WP-cron takes to run (it only runs when the site
  gets traffic, and one run blocks the next for up to a minute), plus up to 5 minutes of CDN
  caching of endpoint responses (`s-maxage=300`). In production, use a system cron
  ([Hosting](#hosting)).
- **Not covered:** writes that bypass WordPress entirely (direct SQL). The index's scheduled
  `rebuildInterval` catches those.
- **A changed definition:** each build records a hash of the index's definition and its
  `version` (Gaffer's: the definition and the card's templates). When it changes (a new facet, a
  changed card template), the next request schedules a full rebuild; until it's done, the old
  index is served (a visitor's filter or sort on a new field is ignored, a locked one fails).
- **A `rebuild` schedule** in the definition runs a full rebuild besides the updates per change
  (`rebuildInterval` above).

## Hosting

### A system cron instead of WP-cron

WP-cron only runs when the site gets traffic, and one run can hold up the next for a minute.
In production, set `define('DISABLE_WP_CRON', true);` and run WordPress's cron from the system
— or, for Gaffer Search only, its own commands:

```
* * * * *  wp gaffer-search update  --url=https://shop.example --path=/var/www/html
0 3 * * *  wp gaffer-search rebuild --url=https://shop.example --path=/var/www/html
```

- `wp gaffer-search update [<index>]` — apply every logged change since this server's position (and,
  with snapshots, copy in a newer full rebuild first). Quiet when there's nothing to do; exits
  non-zero when an update fails.
- `wp gaffer-search rebuild [<index>]` — a full rebuild of every language's file. Every index is
  attempted; exits **0** when every index was built, **1** when a build failed (the old index
  stays; the error is in the output and `status()`), **2** when one was skipped (a build already
  running, here or on another server) or no index is defined in that request (the theme isn't
  active). A failure wins over a skip.
- `wp gaffer-search status [--format=json]` — every defined index for monitoring: built,
  documents, a build running, the last build's error, changes waiting. Exits **1** when an index
  isn't built, has an error, or has changes waiting for more than 15 minutes (the updates don't
  run on this server), or when no index is defined; **0** otherwise.
- `wp gaffer-search verify [<index>] [--fix]` — compare the index with the database, `--fix` to update what differs (see below).

Without either, nothing applies the logged changes: WP-cron needs traffic and a loopback request
to `wp-cron.php`, which a password-protected (HTTP auth) or firewalled site refuses, and WP-CLI
never runs WP-cron. The index then misses every change (a product trashed from WP-CLI stays
findable) until a rebuild. The dashboard, `wp gaffer-search verify` and `status()` (`pending`,
`pending_since`; Gaffer's `doctor --wp`) show the changes waiting.

On a network, run each per site (`wp site list --field=url | xargs -I{} wp gaffer-search update --url={}`).
Run them as the web server's user, and on the same paths the web server sees: the endpoint
config holds absolute paths. PHP's command line usually runs without OPcache, so WordPress with
WooCommerce and a few big plugins can need more than 128 MB before Gaffer Search even starts: give the
CLI `memory_limit=512M` (`php -d memory_limit=512M wp-cli.phar …`, or in the CLI's php.ini).

### Several web servers

Each server keeps its own copy of every index on **local disk** — SQLite must never be read or
written over a network file system (NFS, EFS, SMB): its locking doesn't work there, and that
corrupts files. In `wp-config.php` on every server:

```php
define('GAFFER_SEARCH_INDEX_DIR', '/var/lib/gaffer-search');      // local disk, the same path on every server
define('GAFFER_SEARCH_SNAPSHOT_DIR', '/mnt/shared/gaffer-search'); // shared storage (NFS is fine here: files are only copied)
```

- **Changes:** every server applies the update log itself, from its own position: run
  `wp gaffer-search update` every minute **on every server** (WP-cron events live in the shared
  database, so only one server would get them).
- **Full rebuilds** are built once: the server that builds (the scheduled `rebuildInterval`, a
  rebuild after a changed builder, `wp gaffer-search rebuild`) publishes the result to
  `GAFFER_SEARCH_SNAPSHOT_DIR` as a new generation, with the log position it was built at. Every other
  server copies it on its next `wp gaffer-search update` and applies the log from there. The previous
  generation is kept for a server that is still copying it; older ones are deleted.
- **One full rebuild at a time across servers:** a rebuild takes a lock in the shared database
  (MySQL's `GET_LOCK()`, released when the process ends, also when it's killed), so a
  `wp gaffer-search rebuild` in every server's crontab is fine: one server builds, the others skip it
  with a warning (exit code 2) and copy its result. The dashboard says "already being rebuilt on
  another server". A database proxy that sends queries to different servers (a Galera cluster
  without a single writer) doesn't share `GET_LOCK()`: run the rebuild on one server there.
- **A new server** gets its indexes the same way, on its first `wp gaffer-search update`.
- **A server that was down** for longer than the log keeps rows (a day) copies the latest
  published generation, or rebuilds when there's none newer.
- `GAFFER_SEARCH_INDEX_DIR` and `GAFFER_SEARCH_SNAPSHOT_DIR` get a subfolder per site (`/var/lib/gaffer-search/1`).
  Without `GAFFER_SEARCH_SNAPSHOT_DIR` every server rebuilds by itself.
- Each server writes its own endpoint config (`wp-content/gaffer-search/`, see "Endpoint URLs
  and config"); it's rewritten on the next request whenever its content differs from the
  database, so a settings change or a rotated secret reaches every server.

### Verifying an index

`wp gaffer-search verify [<index>]` (and the **Verify** button on the dashboard) compares the index with
what its builder produces right now and lists documents that are missing, extra, or outdated.
It exits non-zero when anything differs, so it can run in a cron job or deploy check.
`--fix` updates the documents that differ through the update log (other servers apply
them with their next update) and checks those documents again: for an index built before a switch, while the
plugin was inactive and content changes weren't tracked. If they still differ, rebuild.
`wp gaffer-search describe [<index>]` shows the schema, document count, and file revision.
