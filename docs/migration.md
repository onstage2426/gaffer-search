# Migration

What a theme changes when it updates the plugin. Newest first. Each entry: what changed, how
to find the affected code, what to do.

## Unreleased

### A search that couldn't run says so (nothing to change in a theme)

- `search()` from PHP adds `code` (`index_not_built`, `search_failed`) to the empty response it
  gives when the index isn't built or the search failed (feature `search_codes`,
  `docs/contract.md`). With a Gaffer that reads it, such a page stays out of page caches and
  search engines instead of being kept as an empty shop.
