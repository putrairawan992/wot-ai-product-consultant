# Qualitest Product Consultant (WOT)

AI product consultant for [World of Test](https://www.worldoftest.com). A visitor describes a sample, a property, or a test standard, and the assistant shortlists matching equipment from the WOT catalog.

Repository: <https://github.com/putrairawan992/wot-ai-product-consultant>

## Stack

- **Frontend:** vanilla HTML/CSS/JS, one file (`public/index.html`), no build step
- **Backend:** plain PHP 8, no framework, no Composer runtime dependency
- **Model:** DeepSeek by default; Gemini or Claude if their keys are set
- **Data:** `products.json` (scraped sitemap, 1439 unique products) plus optional `products_enriched.json` with standards, application, specs, and images

## Run

```bash
export DEEPSEEK_API_KEY=...                                   # never hardcode it in a file
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8088 -t public      # http://127.0.0.1:8088/
```

`PHP_CLI_SERVER_WORKERS` matters only for the PHP dev server, which otherwise answers one request at a time. Production runs on PHP-FPM and is concurrent by default.

`public/` is the only web-served directory and contains exactly `index.html` and `api.php`. The code, catalog, and runtime data stay above the docroot, so a misconfiguration cannot serve them.

## Environment

| Variable | Required | Purpose |
|---|---|---|
| `DEEPSEEK_API_KEY` | one provider key | DeepSeek chat completions |
| `GEMINI_API_KEY` | alternative | Gemini 2.0 Flash |
| `CLAUDE_API_KEY` | alternative | Claude Haiku |
| `CF_TURNSTILE_SECRET` | production | enables Turnstile verification on every request |
| `CF_TURNSTILE_SITEKEY` | production | handed to the widget through `api.php?config=1` |
| `WOT_DATA_DIR` | optional | override for `data/` (query log, rate limits, image cache) |
| `WOT_CHAT_MODEL` | optional | override the DeepSeek model id |
| `WOT_CHAT_EFFORT` | optional | DeepSeek reasoning budget: `low` (default), `high`, `max` |
| `WOT_DEBUG` | optional | `1` adds a `debug` block with retrieval details (JSON body, or the `done` event when streaming) |

No key has a default. With no provider key the endpoint answers `503 not_configured` instead of silently using a bundled credential.

## Layout

```
public/
  index.html            chat widget (escape-first markdown renderer, WOT-only links)
  api.php               single entry point: validate, limit, retrieve, answer, police links
lib/
  security.php          error shaping, CORS allowlist, input typing, rate limit, Turnstile, PII scrub
  catalog.php           index build, standards table, query understanding, two-pass retrieval
  llm.php               provider transport, truncation repair, link policing, image cache
data/                   outside the docroot: queries.db, ratelimit/, image-cache.json
products.json           fallback catalog
products_enriched.json  optional enriched catalog (standards, application, spec_summary, image)
catalog/                raw scrape + build scripts for the enriched catalog
tests/                  scripted end-to-end tests, raw responses in tests/out/
```

Enriched schema the code expects:

```json
{
  "generated": "2026-11-17",
  "source": "worldoftest.com",
  "products": [
    { "nid": 1234, "name": "Brinell Hardness Tester",
      "url": "https://www.worldoftest.com/brinell-hardness-tester/",
      "category": "Metal Testing Equipment", "category_path": ["Metal Testing Equipment"],
      "standards": ["ASTM E10", "ISO 6506"], "application": "Cast iron and steel Brinell hardness",
      "spec_summary": "Loads 62.5 to 3000 kgf, 10 mm ball indenter, digital eyepiece",
      "image": "https://www.worldoftest.com/..." }
  ]
}
```

When the enriched file is missing the loader falls back to `products.json` and the standards table alone, so the chat still works. Base catalog rows are always merged in, so a partial enriched file cannot shrink coverage.

## How an answer is produced

1. **Validate** — `message` must be a string (<=1000 chars), `history` an array of `{role,content}` with roles `user|assistant`. Wrong types are a `400` with a short JSON error; nothing is coerced.
2. **Understand** — standards are extracted with a regex (`ASTM D-2240-15` -> `ASTM D2240`) and resolved through two sources: a curated equipment-family table (ISO 6506 -> Brinell, ASTM B117 -> salt spray, ASTM D1238 -> melt flow index, ~110 entries) and a **standards index built from the catalog itself** — every standard that real products declare, with the categories that cite it. The table supplies the family wording, the index supplies the ground truth and covers standards nobody curated (ISO 9513, DIN 5122, and 1400 others resolve this way). Application wording ("cannabis potency", "melt flow index", "martindale") is mapped by a separate lexicon, plus capacity ("50 kN") and subject words. `WOT_DEBUG=1` reports which source resolved each standard.
3. **Conversation core** — the current message plus the equipment family under discussion. History is never concatenated into one query string. A bare qualification ("yes, 50 kN") inherits the subject from the immediately preceding turn and the products named in the last reply.
4. **Retrieve** — ranked primary pass on name/category/standards/application/capacity; if fewer than six candidates survive, a widened pass on type synonyms and category hints runs; if still thin, hinted categories are browsed. Six to ten candidates with name, URL, category, standards, application, and specs go to the model. "Not in catalog" is only ever said when all passes are empty.
5. **Answer** — American-English consultant prompt: ask qualifying questions, explain fit, never invent pricing, payment terms, lead times, or specs. The transport streams: `POST /api.php` with `Accept: text/event-stream` (or `?stream=1`) returns server-sent events — `ready` and `preview` (the shortlist being evaluated, ~90 ms) before the model starts, then `delta` chunks, an optional `rewrite` with the policed text, and a final `done` carrying the authoritative reply, images, and provider. Without that header the same endpoint answers with one JSON object, which is what the tests and non-streaming clients use.
6. **Police** — the finished text is scanned: markdown links are rewritten to canonical trailing-slash URLs, a link that was not in the candidate set is demoted to plain text, banned internal phrasing is replaced, and a truncated link is dropped instead of being sent half-written. The frontend escapes everything before it converts markdown, so model output can never become live HTML.

## Secrets

Every credential this project needs is read from the process environment — `DEEPSEEK_API_KEY`, `GEMINI_API_KEY`, `CLAUDE_API_KEY`, `CF_TURNSTILE_SECRET`, `CF_TURNSTILE_SITEKEY`, `WOT_ADMIN_KEY`. Nothing is read from a file in the repo, and no key has a default: with no provider key `api.php` answers `503 not_configured` instead of falling back to a bundled credential.

Any API key that appears in an older revision of this repository is revoked. The history was rewritten with `git filter-repo` to replace the DeepSeek key with `REDACTED` before the first public push, so no key material exists in any commit, branch, tag, or blob. Do not resurrect a key from an old clone or bundle — get a fresh one from the DeepSeek console and put it in the hosting panel's environment (cPanel: "Setup PHP Environment Variables"), never in a tracked file.

## Security posture

- Keys come from the environment only; no credential is stored in the repo or the docroot.
- `display_errors` off, all errors (including fatals via a shutdown handler) become `{"error": "...", "code": "..."}` with no paths, traces, or upstream bodies.
- CORS: `Access-Control-Allow-Origin` is echoed only for `worldoftest.com` (any subdomain) and loopback; foreign origins receive no CORS header at all.
- Rate limit: 10 requests/minute and 200/day per IP, file counters under `data/ratelimit/`, `429` with `Retry-After`. Counters fail open so a read-only volume cannot take the chat down.
- Turnstile verification runs whenever `CF_TURNSTILE_SECRET` is set. Without the secret the widget does not render a challenge.
- The query log stores intent text only after email addresses and phone numbers are replaced with `[email]` and `[number]`.
- `queries.php` (log viewer, outside the docroot) still has a default admin key; set `WOT_ADMIN_KEY` before exposing it.

## Tests

```bash
DEEPSEEK_API_KEY=... python3 tests/run_tests.py --spawn   # or --base http://127.0.0.1:8088
python3 tests/timing.py --base http://127.0.0.1:8088      # time to first rendered text
node tests/render_test.js
bash tests/security_surface.sh
```

`run_tests.py` covers eight standard-only first messages (ISO 6506, ASTM E18, ASTM B117, ASTM D412, ASTM D638, ISO 6892-1, ISO 2039-2, ASTM D2240), two standards that only the catalog index knows (ISO 9513, DIN 5122), six application prompts, three products with no standards or application metadata (they must stay retrievable), the steel-wire multi-turn, three prompt injections, the rate limit, malformed payloads, CORS, and key hygiene. Every raw response lands in `tests/out/`, and the pass/fail matrix — including which source resolved each standard — is printed and written to `tests/out/matrix.md`.

```bash
bash tests/security_surface.sh [port]     # Turnstile gating, headers, docroot isolation
php tests/debug_retrieval.php "ISO 6506"  # what the retriever sees, no model call
```

`render_test.js` loads the renderer out of the shipped `public/index.html` and checks that `<img onerror>`, SVG payloads, foreign links, `javascript:` links, and foreign image sources never become markup.

`timing.py` opens real SSE requests and records two numbers per sample: when **text first renders** (the `preview` event, which the widget paints immediately) and when the model's **first token** lands. It fails when first rendered text exceeds 5 s and warns when provider latency pushes the first token past it. `tests/security_surface.sh` covers Turnstile gating, header hygiene, and docroot isolation without spending a model call.

## Deploy notes

Upload `public/` as the docroot and keep `lib/`, `data/`, `products*.json` one level above it. Set `DEEPSEEK_API_KEY`, `CF_TURNSTILE_SECRET`, and `CF_TURNSTILE_SITEKEY` in the hosting panel (cPanel: "Setup PHP Environment Variables"), never in files.

## Still open

- Rotate the DeepSeek key: the old one was committed in the deleted root `api.php`. It has been redacted from this repository's history, but it lived in a clone and is therefore considered compromised — revoke it in the DeepSeek console and set the replacement as a panel env var.
- Product quotes open the recommended product page, or `https://www.worldoftest.com/contact/` when nothing has been recommended yet.
