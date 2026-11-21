# Benchmark

```
php -d memory_limit=2G bench/benchmark.php     # 100,000 products, 15 runs per request
php -d memory_limit=2G bench/benchmark.php 50000 --runs=5 --json=bench/results/x.json
```

Generates a deterministic catalog shaped like `ProductIndexBuilder`'s documents, builds it the
way `IndexService` does (`Index::rebuild()`, chunks of 2,500), then times archive and search
requests through `SearchRunner` (what the endpoint and `gaffer_search()` run, without HTTP or
CDN caching), a facet search, and `upsert()` of existing products. Numbers depend on the
machine: compare runs on the same one. Results go to `results/` with `--json`.

## Baseline (2026-10-05, 100k products, Fuzor library 1.7, PHP 8.5, `results/baseline-100k.json`)

Build: 152 s, 518 MB on disk, 138 MB peak memory.

| Request | Median | p95 |
|---|---|---|
| archive page 1, no facets | 3 ms | 6 ms |
| archive page 1, 5 facets (brand, category, color, size, price) | 315 ms | 326 ms |
| archive in a category, 5 facets | 83 ms | 89 ms |
| archive, category + 2 filters, 5 facets | 65 ms | 66 ms |
| archive sorted by price, 5 facets | 321 ms | 326 ms |
| archive sorted by name, no facets | 3 ms | 7 ms |
| archive page at offset 9,000, 5 facets | 330 ms | 340 ms |
| search "jurk", 3 facets | 5 ms | 9 ms |
| search as you type "ju" | 3 ms | 3 ms |
| search "rode lange jurk" | 7 ms | 7 ms |
| search typo "jruk" | 0.5 ms (0 hits) | |
| search rare word | 2 ms | 2 ms |
| search SKU | 2 ms | 2 ms |
| facet search brand "st" | 36 ms | 36 ms |
| upsert 1 / 100 / 1,000 products | 9 / 104 / 936 ms | |

## Fuzor 2.0 (2026-10-08, 100k products, `results/fuzor2-100k.json`)

Same machine and catalog; the plugin's defaults now weigh titles 3× (`boosts`), allow no typos
in words with a digit, and match several words with `MatchingStrategy::Last`.

Build: 166 s, 568 MB on disk, 120 MB peak memory (generator passed straight to `insert()`).

| Request | 1.7 median | 2.0 median |
|---|---|---|
| archive page 1, no facets | 3 ms | 3 ms |
| archive page 1, 5 facets | 315 ms | 163 ms |
| archive in a category, 5 facets | 83 ms | 60 ms |
| archive, category + 2 filters, 5 facets | 65 ms | 62 ms |
| archive sorted by price, 5 facets | 321 ms | 169 ms |
| archive sorted by name, no facets | 3 ms | 3 ms |
| archive page at offset 9,000, 5 facets | 330 ms | 175 ms |
| search "jurk", 3 facets | 5 ms | 8 ms |
| search as you type "ju" | 3 ms | 13 ms |
| search "rode lange jurk" | 7 ms | 7 ms |
| search typo "jruk" | 0.5 ms (0 hits) | 0.5 ms (0 hits: 4 letters, typos start at 5) |
| search rare word | 2 ms | 2 ms |
| search SKU | 2 ms | 13 ms |
| facet search brand "st" | 36 ms | 27 ms |
| upsert 1 / 100 / 1,000 products | 9 / 104 / 936 ms | 9 / 124 / 1,004 ms |

- Facet pages take half the time; the whole-catalog facet counts are still the slow part.
- **The title boost is the cost of the slower searches.** At 30k products: "ju" 6.6 ms with
  `boosts` vs 2.8 ms with `boosts: []`, SKU 6.2 vs 3.5 ms, "jurk" 5.8 vs 4.7 ms (the library's
  `fieldBoosts` path reads per-field hit counts). "jurk" puts a title match first either way here.
  Library `3a5844a` (2026-10-08) reads only the term/document pairs it scores: at 30k "ju" 3.9 ms
  (2.7 without boosts), SKU 4.5 (3.6), "jurk" 5.8 (4.5) — about 1 ms for the boost now.
- SKU lookups: `MatchingStrategy::Last` still returns every SKU (the first word, `fz`, is in all
  of them); `matching_strategy: 'all'` returns the one product (1 hit, 2.6 ms).

## Library `4a0aa38` and `28cc0d2` (2026-10-18, 100k products, `results/fuzor2-2026-10-18-100k.json`, `results/fuzor2-28cc0d2-100k.json`)

Build: 156 s, 570 MB on disk, 130 MB peak memory.

| Request | 2.0 (Oct 8) | `4a0aa38` | `28cc0d2` |
|---|---|---|---|
| archive page 1, no facets | 3 ms | 3 ms | 3 ms |
| archive page 1, 5 facets | 163 ms | 162 ms | 129 ms |
| archive in a category, 5 facets | 60 ms | 84 ms | 36 ms |
| archive, category + 2 filters, 5 facets | 62 ms | 134 ms | 62 ms |
| archive sorted by price, 5 facets | 169 ms | 168 ms | 135 ms |
| archive page at offset 9,000, 5 facets | 175 ms | 173 ms | 140 ms |
| search "jurk", 3 facets | 8 ms | 49 ms | 7 ms |
| search as you type "ju" | 13 ms | 5 ms | 5 ms |
| search "rode lange jurk" | 7 ms | 25 ms | 14 ms |
| search SKU | 13 ms | 3 ms | 3 ms |
| facet search brand "st" | 27 ms | 27 ms | 27 ms |
| upsert 1 / 100 / 1,000 products | 9 / 124 / 1,004 ms | 12 / 137 / 1,118 ms | 11 / 135 / 1,128 ms |

`4a0aa38`'s slower facet pages came from `c877352` (bisected at 30k): counting read every
`facet_value_ids` row of the requested keys, and `price` has a value per product. `28cc0d2`
looks up only the counted values. "rode lange jurk" stays slower than on Oct 8: multi-word
searches read the documents with all words exactly (library changelog).

## Library `781d65c` (2026-11-12, 100k products, `results/fuzor2-781d65c-100k.json`)

Against `e5d62c4` (`results/fuzor2-e5d62c4-2026-11-12-100k.json`), four passes in the order old,
new, new, old; the table has the mean of each version's two medians. Build (154 s, 576 MB, 130 MB
peak memory) and upserts (10 / 134 / 1,117 ms) are unchanged. **Needs a rebuild**: older files lack
`facet_keys.row_count` and the `facet_counts_number` index (a price facet and every upsert throw on
them; `IndexService::FILE_FORMAT` 3).

| Request | `e5d62c4` | `781d65c` |
|---|---|---|
| archive: page 1, no facets | 2.7 ms | 2.8 ms |
| archive: page 1, 5 facets | 71.5 ms | 71.8 ms |
| archive: category, 5 facets | 26.3 ms | 26.4 ms |
| archive: category + 2 filters, 5 facets | 63.4 ms | **53.4 ms** |
| archive: sorted by price | 73.1 ms | 73.0 ms |
| archive: sorted by name | 2.9 ms | 2.9 ms |
| archive: deep page (offset 9,000) | 77.7 ms | 77.4 ms |
| search: "jurk", 3 facets | 172 ms | 170 ms |
| search: as you type "ju" | 36.8 ms | 36.7 ms |
| search: "rode lange jurk" | 22.1 ms | 21.8 ms |
| search: rare word | 2.4 ms | 2.5 ms |
| search: SKU | 18.7 ms | 19.0 ms |
| facet search: brand "st" | 26.2 ms | 26.5 ms |

Paths the table doesn't hit, one pass each on the same 100k files (medians of 21; `visibility`
exclusion as on a shop, `price` 50–300):

| Request | `e5d62c4` | `781d65c` |
|---|---|---|
| price range alone, no facets | 5.6 ms | 2.2 ms |
| exclusion + price range | 16.9 ms | 11.9 ms |
| exclusion + category + price range | 20.6 ms | 14.4 ms |
| exclusion + category + narrow price (82 hits) | 11.3 ms | 3.5 ms |
| category + price, 5 facets | 155 ms | 103 ms |
| category + 2 colors + price, no facets | 19.4 ms | 12.4 ms |
| shop + 2 colors + price, 5 facets | 237 ms | 180 ms |
| shop, price facet only | 46.7 ms | 38.8 ms |
| category + price, sorted by price | 20.2 ms | 10.0 ms |
| "jurk" in a category | 41.1 ms | 17.4 ms |
| "jurk" in a category, 3 facets | 44.0 ms | 20.6 ms |
| "jurk" + one brand (184 hits) | 39.6 ms | 3.9 ms |
| "ju" in a category | 81.3 ms | 30.8 ms |

- The wide-range cost `c3bdccf` added (+4.4 ms, see below) is gone and then some.
- Same answers on archives, ranges and plain searches (hashed ids, facets and stats). A one-word
  search with a selective filter differs, as the library's changelog announces: its first page now
  equals the uncapped ranking ("jurk" + brand, "rok" + brand: 24/24, were 15 and 23), "ju" in a
  category 19/24 (was 2). Pages past the ranked candidates are filled newest first.
- Five real site indexes (one of them in two languages; 1–19 documents),
  rebuilt from their stored documents with each version: 18,174 requests byte-identical. They are
  too small to reach the capped paths that changed.

## Library `e5d62c4` (2026-11-09, 100k products, `results/fuzor2-e5d62c4-100k.json`)

Against `352927d` (`results/fuzor2-352927d-2026-11-09-100k.json`), four passes in the order old,
new, new, old; the table has the mean of each version's two medians. Build (154–159 s, 570 MB,
130 MB peak memory) and upserts (10 / 133 / 1,110 ms) are unchanged. **Needs a rebuild**: older
files lack `facet_keys.has_strings` (`IndexService::FILE_FORMAT` 2).

| Request | `352927d` | `e5d62c4` |
|---|---|---|
| archive: page 1, no facets | 2.7 ms | 2.7 ms |
| archive: page 1, 5 facets | 70.7 ms | 72.0 ms |
| archive: category, 5 facets | 26.0 ms | 26.4 ms |
| archive: category + 2 filters, 5 facets | 63.4 ms | 63.6 ms |
| archive: sorted by price | 72.1 ms | 73.1 ms |
| archive: sorted by name | 2.9 ms | 2.9 ms |
| archive: deep page (offset 9,000) | 76.8 ms | 78.8 ms |
| search: "jurk", 3 facets | 168 ms | 171 ms |
| search: as you type "ju" | 36.9 ms | 36.8 ms |
| search: "rode lange jurk" | 21.5 ms | 21.8 ms |
| search: rare word | 2.4 ms | 2.5 ms |
| search: SKU | 18.6 ms | 18.7 ms |
| facet search: brand "st" | 26.5 ms | 26.8 ms |

- Within noise (the second new pass ran 1–3% above the first); same totals and top hits.
- Same answers: five real site indexes
  rebuilt from their stored documents with each version, 17,848 requests (plain, `facet_stats`,
  a FacetExclude lock with a FacetRange): full responses byte-identical. Only queries with an
  unclosed quote differ (now a phrase, as the library's changelog announces).
- The wide-range cost of `c3bdccf` is unchanged (`visibility` exclusion + `price` 16.7 ms, the
  range alone 5.2 ms).
- `Filter::all()`/`any()`/`not()` (not in the contract): equivalent shapes give the same answers
  at the same speed as a map; an OR across two fields 2.6–7.5 ms, a negated group 3.9 ms (11.6 ms
  with 4 facets).

## Library `c3bdccf` (2026-11-07, 100k products, `results/fuzor2-c3bdccf-100k.json`)

Against `5480389` measured again the same day (`results/fuzor2-5480389-2026-11-07-100k.json`),
four passes in the order old, new, new, old; the table has the mean of each version's two medians.
Build (154 s, 570 MB, 130 MB peak memory) and upserts (10 / 133 / 1,100 ms) are unchanged. No
index rebuild needed. `maxDocs`, `maxFacetCountDocs` and `maxTotalHits` moved into `Fuzor\Limits`
(`SearchRunner::run()` passes one); the plugin's options keep their names.

| Request | `5480389` | `c3bdccf` |
|---|---|---|
| archive: page 1, no facets | 2.7 ms | 2.6 ms |
| archive: page 1, 5 facets | 72 ms | 72 ms |
| archive: category, 5 facets | 34 ms | 26 ms |
| archive: category + 2 filters, 5 facets | 60 ms | 63 ms |
| archive: sorted by price | 73 ms | 72 ms |
| archive: sorted by name | 2.9 ms | 2.9 ms |
| archive: deep page (offset 9,000) | 77 ms | 77 ms |
| search: "jurk", 3 facets | 170 ms | 170 ms |
| search: as you type "ju" | 36 ms | 37 ms |
| search: "rode lange jurk" | 24 ms | 22 ms |
| search: typo "jruk" | 0.5 ms | 0.5 ms |
| search: rare word | 2.4 ms | 2.5 ms |
| search: SKU | 19 ms | 19 ms |
| facet search: brand "st" | 26 ms | 26 ms |

- A category page counts faster (34 → 26 ms), and so do term filters together: category + color
  57 → 31 ms, category + one color 36 → 21 ms (measured separately on the same index).
- **A wide numeric range next to any other filter costs ~4.4 ms more**, with or without facets:
  `visibility` exclusion + `price` 50–300 12.1 → 16.6 ms, + category 15.5 → 19.9 ms; a range alone
  (5.3 ms) and a narrow range (82 hits, 11.5 ms) are unchanged. Reported to Fuzor. That is the
  "category + 2 filters" row.
- Same answers on the generated catalog (every variant above hashed equal) and on two real sites (7,080
  and 2,210 requests): byte-identical. A third (1,874): 80 differ, all in
  `formatted`: words matched through a typo (`variable` → "Variabel") are marked now, as the
  library's changelog announces; ids, order, totals, facets and snippet text are the same.

## Library `5480389` (2026-11-06, 100k products, `results/fuzor2-5480389-100k.json`)

Against `59c4413` (`results/fuzor2-59c4413-100k.json`), same machine, four passes in the order
old, new, new, old; the table has the mean of each version's two medians. Build (157 s, 570 MB, 130 MB
peak memory) and upserts (10 / 135 / 1,118 ms) are unchanged. No index rebuild needed (schema
revision 4).

| Request | `59c4413` | `5480389` |
|---|---|---|
| archive page 1, no facets | 3.0 ms | 2.8 ms |
| archive page 1, 5 facets | 129 ms | 73 ms |
| archive in a category, 5 facets | 37 ms | 35 ms |
| archive, category + 2 filters, 5 facets | 62 ms | 62 ms |
| archive sorted by price, 5 facets | 135 ms | 75 ms |
| archive sorted by name, no facets | 3.4 ms | 2.9 ms |
| archive page at offset 9,000, 5 facets | 140 ms | 79 ms |
| search "jurk", 3 facets | 176 ms | 172 ms |
| search as you type "ju" | 38 ms | 37 ms |
| search "rode lange jurk" | 25 ms | 24 ms |
| search SKU | 20 ms | 19 ms |
| facet search brand "st" | 27 ms | 27 ms |

- The gain is the facet counts under the constant `visibility` exclusion over a whole catalog.
  Filtered archives count few documents and barely move; searches here are dominated by counting
  every match.
- Same answers: 4,032 requests on a generated 8k catalog and 352 each on two real sites' indexes, full responses byte-identical between the two versions.
- Two follow-ups measured at 8k products (`page` = 6 facets without `price`) and not built:
  folding `bounds` into an unfiltered page saves 1.7 ms, not the whole request (page 2.8 + bounds
  7.9 ms against 9.0 ms: `price` still has to be counted, now in the page); turning off highlight
  and crop saves 0.3 ms on an archive page and 1.3 ms on a search of 15.6 ms.

## Library `ad1ef59` (2026-11-02, 100k products, `results/fuzor2-ad1ef59-100k.json`)

Against `6584c09` below, same machine. Build: 155 s, 570 MB on disk, 130 MB peak memory (153 s
before). Within noise for every request (archives 3 / 128 / 36 / 62 / 133 / 3 / 139 ms, searches
176 / 37 / 25 / 3 / 20 ms, facet search 27 ms); upserts 9 / 134 / 1,105 ms (11 / 130 / 1,091).
The library's own speedups (browse totals from facet counts, mixed filters as a difference, packed
facet rows) are paths these requests don't hit: they are dominated by counting five facets over
the whole archive and by scoring common words. `Index::exists()` opens read-only now, so a check
racing a swap can no longer create an empty version file.

## Library `6584c09` (2026-10-27, 100k products, `results/fuzor2-6584c09-100k.json`)

`28cc0d2` measured again the same day (`results/fuzor2-28cc0d2-2026-10-27-100k.json`) with the
plugin before the update. Build: 153 s, 570 MB on disk, 130 MB peak memory for both.

| Request | `28cc0d2` | `6584c09` |
|---|---|---|
| archive page 1, no facets | 3 ms | 3 ms |
| archive page 1, 5 facets | 126 ms | 126 ms |
| archive in a category, 5 facets | 35 ms | 36 ms |
| archive, category + 2 filters, 5 facets | 61 ms | 61 ms |
| archive sorted by price, 5 facets | 131 ms | 131 ms |
| archive sorted by name, no facets | 3 ms | 3 ms |
| archive page at offset 9,000, 5 facets | 137 ms | 136 ms |
| search "jurk", 3 facets | 7 ms | 172 ms |
| search as you type "ju" | 5 ms | 37 ms |
| search "rode lange jurk" | 14 ms | 24 ms |
| search rare word | 2 ms | 3 ms |
| search SKU | 3 ms | 20 ms |
| facet search brand "st" | 27 ms | 27 ms |
| upsert 1 / 100 / 1,000 products | 11 / 140 / 1,076 ms | 11 / 130 / 1,091 ms |

- Archives are unchanged; their `total` stops at 10,100 (`maxTotalHits`, was 93,160).
- **Searches with common words count every match now**: `total` for "jurk" is 10,100+ (was 490,
  the scored candidates), and facets are counted over every match up to `maxFacetCountDocs`
  (50,000). The cap costs little ("jurk" without facets 13 / 18 / 36 ms at `maxTotalHits`
  1,000 / 10,100 / 100,000; "ju" 26 / 33 / 94 ms); the facet counts are the rest ("jurk" with
  3 facets 130 ms vs 13 ms without). `maxFacetCountDocs` 2,000 / 5,000 / 10,000 / 50,000:
  23 / 30 / 42 / 123 ms, approximate facets below 50,000.

## Findings (1.7)

- **Facet counts over the whole catalog are the slow part.** Searching and browsing are a few
  milliseconds; the unfiltered shop page with five facets is ~315 ms. Per facet (whole catalog,
  with / without the visibility exclusion): brand 28 / 15 ms, category 45 / 28, color 45 / 27,
  size 67 / 43, price stats 157 / 78. Counting is linear in the matching documents, and the
  exclusion roughly doubles it. The fix belongs in the library (e.g. counts and stats cached per
  index revision for browse requests, which are the same for every visitor until the index
  changes).
- **Relevance:** "jurk" ranks products with the word only in the description above products
  with it in the title; no field weighting. "rode lange jurk" puts "Rode armband" first.
- **Typos:** "jruk" (a transposition) finds nothing.
- **SKU search** "FZ-04217" ranks the right product first, but matches every SKU (`FZ` is a
  token of all of them), so `total` is meaningless for SKU lookups.

## `facet_stats` (Fuzor `352927d`, 2026-11-08, 30k generated products)

`SearchRunner` medians, a new read-only Index per request. Same `min`/`max` in every pair.

| Request | `facets: [price]` | `facet_stats: [price]` |
|---|---|---|
| shop bounds (limit 1, whole catalog) | 26.4 ms | 2.8 ms |
| one category's bounds (1,427 hits) | 5.9 ms | 3.3 ms |
| searchbar "jurk", + `brand` facet (brand alone 23.1 ms) | 54.7 ms | 25.6 ms |
| searchbar "r", + `brand` facet (brand alone 98.6 ms) | 157.6 ms | 100.8 ms |

The generated catalog has ~49k distinct prices; a real shop has far fewer, so its price facet
costs less to begin with (Fuzor's 7.7k-product shop copy: bounds 4.4 → 0.7 ms, searchbar ~2 ms).
