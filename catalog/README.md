# WOT product catalog build

Source of `../products_enriched.json` — the product knowledge base the consultant chatbot
(`api.php`) answers from.

Nothing here touches the website. Every query is a `SELECT` executed through
`drush sqlq` over SSH; no cache clears, no config changes, no writes on the host.

## Source

| | |
|---|---|
| Site | worldoftest.com (Drupal 11.4.10, Qualitest International Inc.) |
| Access | `ssh -i ~/.ssh/wot_new d8157@190.92.168.251` → `cd ~/public_html && ./vendor/bin/drush sqlq "<SQL>"` |
| Scope | `node_field_data.type = 'product'`, `status = 1` → **1822 published products** |
| Generated | see `generated` in the JSON (UTC, ISO8601) |
| `source` field | `db` |

## Rebuild

```bash
cd wot-ai-chat

# 1. pull the raw tables (read-only) into catalog/raw/*.tsv
./catalog/scripts/export_drush_sql.sh

# 2. transform -> products_enriched.json + catalog/stats.json
python3 catalog/scripts/build_catalog.py

# 3. sanity checks
python3 -c "import json;d=json.load(open('products_enriched.json'));print(d['count'],len(d['products']))"
```

`catalog/raw/` is the only network-touching step; step 2 is offline and deterministic
(same dumps → byte-identical product records apart from `generated`).

### What each dump is

| file | query source | rows |
|---|---|---|
| `base.tsv` | `node_field_data` ⋈ `path_alias` (nid, title, alias) | 1822 |
| `terms.tsv` | `node__field_test_application` → `taxonomy_term_field_data` (category terms, delta) | 951 |
| `parent.tsv` | `node__field_parent_product` (child → parent product) | 2565 |
| `standards.tsv` | `node__field_standards` → `taxonomy_term_field_data` (vid `standards`) | 3038 |
| `spec.tsv` | `node__field_technical_specifications` | 1574 |
| `apps.tsv` | `node__field_applications` | 1683 |
| `theory.tsv` | `node__field_theory_method` (standards regex text) | 677 |
| `features.tsv` | `node__field_features` (standards regex text) | 1331 |
| `images.tsv` | `node__field_product_images` → `file_managed.uri` | 2371 |
| `tt.tsv` | `node__field_test_property` → `taxonomy_term_field_data` (test type) | 199 |

Text/long columns are exported as `TO_BASE64()` with the 76-char line wrapping stripped
(`REPLACE(...,CHAR(10),'')`) and parsed by splitting on MySQL batch-mode `\t` escapes.
`node__body` is empty for every product — product prose lives in the `field_*` longtext
fields above, not in a body field.

## Field mapping

| JSON key | Rule |
|---|---|
| `nid` | `node_field_data.nid` |
| `name` | node title, verbatim |
| `url` | `path_alias.alias` + trailing slash, absolute `https://www.worldoftest.com/…` (1822/1822 aliased) |
| `category` | deepest primary term of `field_test_application`; primary = lowest `delta` |
| `category_path` | `["Products", <term>]` — all 46 category terms are root-level in `taxonomy_term__parent`, so taxonomy depth is 1 |
| `standards` | curated `field_standards` names **plus** regex hits over spec/applications/theory/features/test-type text; deduped, capped at 20 |
| `application` | first sentence of `field_applications` prose (HTML stripped, repeated `<h2>` title removed), ≤320 chars |
| `spec_summary` | `field_technical_specifications` HTML stripped to flat text, leading “<product> Technical Specifications” heading removed, ≤300 chars, `…` when cut |
| `image` | first `field_product_images` (`delta` 0) → `file_managed.uri`, `public://` mapped to `https://www.worldoftest.com/sites/default/files/…` (original file, not an image-style derivative) |

Missing values are `null` / `[]`. Nothing is inferred from the product name.

### Category resolution

633 products carry a `field_test_application` term themselves. 1050 more are variant
products whose parent product carries the term — resolution walks `field_parent_product`
up to 8 levels and takes the nearest ancestor term. That is the same structure the site
uses: category pages list parent products, children render as variants of them.

| category source | products |
|---|---|
| direct term | 633 |
| inherited from ancestor product | 1050 |
| no term anywhere in the chain | 139 |

### Standards extraction

Families parsed (per catalog contract): `ASTM [A-Z]?\d+`, `ISO \d+`, `EN \d+`, `BS \d+`,
`DIN \d+`, `JIS [A-Z]?\d+`, with `-part` suffixes and `:year` kept. Names are deduped on a
punctuation-free key, editorial suffixes (`… Clause B.1.5`, `… Procedure A`, `(6.13)`)
stripped, en/em dashes normalised to `-`, and a letterless `ASTM 2863` is dropped when
`ASTM D2863` is present for the same product.

**Deliberate exclusion:** the site's `standards` vocabulary holds 1521 unique designations;
369 of them are outside those six families (IEC 76, AASHTO 24, TAPPI 21, SAE 19, UL 15,
SATRA 10, MIL-STD 7, …). They are not emitted because the contract fixes the family list —
so 51 products that only ever cite e.g. IEC/UL now have `standards: []`. The full set is
still in `raw/standards.tsv` if the contract is widened.

## Counts per category

| products | category |
|---:|---|
| 202 | Metal Testing Equipment |
| 143 | Rubber & Elastomer Testing Equipment |
| 114 | Portable Testers |
| 95 | Flexible Packaging & Film Testing Equipment |
| 94 | Plastic Testing Equipment |
| 88 | Environmental Chambers |
| 84 | Color, Paint & Coating Testing Equipment |
| 74 | Petroleum Testing Equipment |
| 71 | Spectroscopy & Analytical Instruments |
| 69 | Cement Testing Equipment |
| 57 | Geosynthetics and Geotextile Testing Equipment |
| 56 | Metrology Equipment |
| 52 | Cable & Wire Testing Equipment |
| 46 | Water Quality & Moisture Analysis |
| 42 | Metallography Equipment |
| 36 | Asphalt Testing Equipment |
| 34 | Battery Testing Equipment |
| 32 | Textile Testing Equipment |
| 29 | Coal Testing Equipment |
| 28 | Concrete Testing Equipment |
| 28 | Freeze Dryers |
| 27 | Paper & Cardboard Testing Equipment |
| 26 | Semiconductor and Electronics Reliability Testing Equipment |
| 25 | Pharmaceutical Testing Equipment |
| 23 | Soil Testing Equipment |
| 20 | Plastics PVC / Pipe Testing Equipment |
| 17 | CNC Milling & Lathe Machines |
| 15 | Automotive Testing Equipment |
| 11 | Adhesive Tape Testing Equipment |
| 10 | Fire and Flammability Testing Equipment |
| 7 | Microscopes |
| 7 | Medical Testing Equipment |
| 5 | Face Mask Testing Equipment |
| 5 | Analytical Balances |
| 4 | Gold Tester and Precious Metal Analyzers |
| 3 | Rock and Aggregates Testing Equipment |
| 2 | PPE Testing Equipment |
| 2 | XRF Analyzers |
| 139 | *(no category)* |

The `test_application` vocabulary has 46 terms. Two are never referenced by a product
(Aerospace Testing, Surveying Instruments). Six are referenced only as a secondary term,
so they never become a product's primary category: Accelerated Weathering Testers &
Chambers, Analytical Instruments, Cannabis Testing Equipment, Food Testing Equipment,
Lab and Industrial Furnaces, Testing Products on Sale. Result: 38 distinct categories
carry the 1683 categorised products.

## Top 30 standards (products citing them)

| # | standard | products | # | standard | products |
|---:|---|---:|---:|---|---:|
| 1 | ISO 9001 | 31 | 16 | ASTM D882 | 13 |
| 2 | ISO 6508 | 26 | 17 | ISO 148-1 | 12 |
| 3 | ASTM E18 | 25 | 18 | ISO 17025 | 12 |
| 4 | ISO 6506 | 22 | 19 | DIN 5122 | 12 |
| 5 | ASTM E110 | 20 | 20 | ISO 2813 | 12 |
| 6 | ASTM D638 | 18 | 21 | ASTM D790 | 11 |
| 7 | ISO 6507 | 18 | 22 | ASTM A956 | 11 |
| 8 | ASTM E23 | 17 | 23 | ASTM E384 | 11 |
| 9 | ISO 7500-1 | 17 | 24 | ASTM D1894 | 11 |
| 10 | ASTM E10 | 16 | 25 | ASTM D76 | 11 |
| 11 | EN 196 | 16 | 26 | ISO 10360-7 | 11 |
| 12 | ASTM D412 | 15 | 27 | ASTM A1038 | 10 |
| 13 | ISO 148 | 14 | 28 | ISO 20427 | 10 |
| 14 | ASTM E4 | 14 | 29 | ISO 178 | 9 |
| 15 | ASTM D3330 | 13 | 30 | ASTM E8 | 9 |

1508 distinct standards appear across the catalog.

## Coverage

| field | products | % | gap |
|---|---:|---:|---|
| `category` | 1683 | 92.4% | 139 variant products with no term on any ancestor |
| `standards` | 902 | 49.5% | 920 products cite no ASTM/ISO/EN/BS/DIN/JIS standard |
| `application` | 1588 | 87.2% | 234 products have no applications prose |
| `spec_summary` | 1488 | 81.7% | 334 products have no technical-specifications table |
| `image` | 1815 | 99.6% | 7 products have no `field_product_images` row |
| `url` | 1822 | 100% | — |

## Verification

- `json.load` passes; `count` equals `len(products)` (1822 > 1000); every record has exactly
  the 9 contracted keys.
- 0 URLs fail the `https://www.worldoftest.com/` + trailing-slash test; 0 duplicate nids.
- Live checks (2026-11-17): nids 6283, 5921, 6444, 6992, 5702 → **200 200 200 200 200**.
  Three sampled image URLs → 200.
- 0 `spec_summary` values exceed 300 chars, 0 `standards` arrays exceed 20 entries.

`catalog/stats.json` holds the same numbers in machine-readable form; regenerate it with the
build script rather than hand-editing this file.
