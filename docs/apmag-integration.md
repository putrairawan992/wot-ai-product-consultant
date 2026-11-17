# APSmag integration: WOT AI product consultant block

How the standalone wot-ai-chat prototype (api.php + index.html + products.json) gets
embedded in a Drupal 11 site as a block, how to stand the local test bed up, and what
differs when the same module is deployed on APSmag.

The prototype stays the single source of truth. The module never copies `index.html`;
it mounts it in an iframe at a configurable base URL.

- Repository: `/Users/fikrinurhakim/dev/projects/freelance/Qualitest/wot-ai-chat`
- Drupal root: `web/`
- Module: `web/modules/custom/wot_product_consultant`
- Block plugin id: `wot_product_consultant`
- Placed block config entity: `olivero_wot_product_consultant` (theme `olivero`, region `footer_bottom`)

## 1. Requirements

| Piece | Local value | Notes |
|---|---|---|
| Drupal | 11.4.8 (`core_version_requirement: ^11`) | installed by `drush site:install standard` |
| PHP | 8.4.24 at `/opt/homebrew/opt/php@8.4/bin/php` | vendor/ requires >= 8.3; the default `php` on this Mac is 8.2 and drush dies on `platform_check.php` |
| Drush | 13.8.0.0 (`vendor/bin/drush`) | runs through the PHP above |
| Database | SQLite at `web/sites/default/files/.ht.sqlite` | local only; APSmag uses its own DB |
| Widget origin | `http://127.0.0.1:8088` (`php -S 127.0.0.1:8088` from the repo root) | serves `index.html` + `api.php` |

Put PHP 8.4 in front of every command:

```bash
cd /Users/fikrinurhakim/dev/projects/freelance/Qualitest/wot-ai-chat
export PATH=/opt/homebrew/opt/php@8.4/bin:$PATH
```

## 2. Local install, from an empty Drupal tree

1. `web/sites/default/settings.php` gets a hash salt and a SQLite block at the end of the file:

```php
$settings['hash_salt'] = 'apmag-local-dev-salt-20261117-Xq7vT2mWpLkR9bYz';

$databases['default']['default'] = [
  // Equivalent to sqlite://sites/default/files/.ht.sqlite, but anchored to the
  // Drupal root so it resolves no matter which directory drush or the PHP
  // built-in server is started from.
  'database' => $app_root . '/sites/default/files/.ht.sqlite',
  'prefix' => '',
  'driver' => 'sqlite',
  'namespace' => 'Drupal\\sqlite\\Driver\\Database\\sqlite',
  'autoload' => 'core/modules/sqlite/src/Driver/Database/sqlite/',
];
```

   `$app_root` instead of the literal `sites/default/files/.ht.sqlite` is deliberate: the
   SQLite driver hands the path straight to PDO, so a relative path is resolved against the
   caller's working directory. Drush and `php -S` run from different directories and one of
   them will fail with `SQLSTATE[HY000] [14] unable to open database file`.

2. Install:

```bash
./vendor/bin/drush site:install standard \
  --db-url=sqlite://sites/default/files/.ht.sqlite \
  --account-name=admin --account-pass=admin -y
```

3. The installer appends its own `$databases['default']['default'] = array(...)` block **after**
   the hand-written one and it wins, with a mangled path (`default/files/.ht.sqlite`, no
   `sites/`). Delete the installer's duplicate array and keep the `$app_root` version, or the
   next drush command cannot reach the database. The installer also replaces the hash salt
   with a generated one — that is fine, keep it.

4. Dev performance settings (they matter, see §7):

```bash
./vendor/bin/drush config:set system.performance css.preprocess 0 -y
./vendor/bin/drush config:set system.performance js.preprocess 0 -y
./vendor/bin/drush pm:uninstall page_cache -y
```

## 3. Enable the module and place the block

```bash
./vendor/bin/drush en wot_product_consultant -y
./vendor/bin/drush cr
```

Block placement is a config entity, so no clicking is required:

```bash
./vendor/bin/drush ev '
$id = "olivero_wot_product_consultant";
if ($b = \Drupal\block\Entity\Block::load($id)) { $b->delete(); }
\Drupal\block\Entity\Block::create([
  "id" => $id, "theme" => "olivero", "region" => "footer_bottom",
  "plugin" => "wot_product_consultant", "weight" => 10, "visibility" => [],
  "settings" => [
    "id" => "wot_product_consultant",
    "label" => "WOT Product Consultant",
    "label_display" => "0",
    "provider" => "wot_product_consultant",
    "enabled" => TRUE,
    "base_url" => "http://127.0.0.1:8088",
    "pages" => "",
    "launcher_label" => "Product consultant",
  ],
])->save();'
```

In the APSmag UI the same thing is `/admin/structure/block` → *Place block* → **WOT Product
Consultant** → choose a region → *Configure*.

## 4. Serve it

```bash
cd /Users/fikrinurhakim/dev/projects/freelance/Qualitest/wot-ai-chat
export PATH=/opt/homebrew/opt/php@8.4/bin:$PATH
php -S 127.0.0.1:8090 -t web
```

Clean URLs (`/admin`, `/node/1`) work without a router script because the built-in server
falls back to `index.php`. The widget itself keeps running on its own port:

```bash
php -S 127.0.0.1:8088        # from the repo root, serves index.html + api.php
```

## 5. Verify

```bash
export PATH=/opt/homebrew/opt/php@8.4/bin:$PATH
./vendor/bin/drush status | grep -E "Drupal version|DB driver"   # 11.4.8 / sqlite
./vendor/bin/drush pm:list --filter=wot_product_consultant        # Enabled
curl -s -o /tmp/f.html -w "%{http_code}\n" http://127.0.0.1:8090/  # 200
grep -o '<title>[^<]*</title>' /tmp/f.html                        # APSmag site name
grep -c 'id="wot-product-consultant"' /tmp/f.html                 # 1
grep -o 'data-wot-base-url="[^"]*"' /tmp/f.html                   # the configured base URL
php -l web/modules/custom/wot_product_consultant/src/Plugin/Block/ProductConsultantBlock.php

# /admin as an authenticated user (one-shot: login link + request in one shell)
LOGIN=$(./vendor/bin/drush user:login --uri=http://127.0.0.1:8090 | tail -1)
curl -s -c /tmp/jar -b /tmp/jar -L -o /dev/null "$LOGIN"
curl -s -c /tmp/jar -b /tmp/jar -o /dev/null -w "/admin %{http_code}\n" http://127.0.0.1:8090/admin
```

Anonymous `curl http://127.0.0.1:8090/admin` returns **403 Access denied** — that is the
correct Drupal answer for an anonymous request, not a routing failure. 200 needs the session
from the command above.

Rendered markup (anonymous front page):

```html
<div id="wot-product-consultant" class="wot-consultant" data-wot-consultant="1"
     data-wot-base-url="http://127.0.0.1:8088" data-wot-label="Product consultant">
  <button type="button" class="wot-consultant__launcher" aria-expanded="false"
          aria-controls="wot-consultant-panel">Product consultant</button>
  <div id="wot-consultant-panel" class="wot-consultant__panel" hidden="hidden"
       role="dialog" aria-label="Product consultant">
    <div class="wot-consultant__loading">Loading product consultant…</div>
  </div>
</div>
```

Measured at 390 x 844 (headless Edge over CDP, `Emulation.setDeviceMetricsOverride`):
`document.documentElement.scrollWidth === clientWidth === 390`, `horizontalOverflow: false`
both closed and open; launcher 197x45 at (185, 791); open panel 374x512 at (8, 324);
`iframe src` = `http://127.0.0.1:8088/index.html`.

## 6. Configuration keys

Stored on the block config entity `block.block.olivero_wot_product_consultant`, under
`settings`, defined by `config/schema/wot_product_consultant.schema.yml`
(`block.settings.wot_product_consultant`).

| Key | Type | Default | Meaning |
|---|---|---|---|
| `enabled` | boolean | `TRUE` | Unchecked → `build()` returns `[]`, no markup at all |
| `base_url` | string (URL) | `http://127.0.0.1:8088` | Origin serving `index.html` + `api.php`. Trailing slashes are stripped. Iframe target is `<base_url>/index.html` |
| `pages` | string (textarea) | `` (empty = all pages) | One Drupal internal path pattern per line, e.g. `/products/*`, `<front>`, `/node/1`. Matched with `PathMatcher::matchPath($current_path, $patterns)` |
| `launcher_label` | string | `Product consultant` | Accessible name of the button and the dialog |

Set them from the CLI without touching the UI:

```bash
./vendor/bin/drush config:set block.block.olivero_wot_product_consultant settings.base_url https://staging-api.example.com -y
./vendor/bin/drush config:set block.block.olivero_wot_product_consultant settings.pages "/products/*
<front>" -y
./vendor/bin/drush config:set block.block.olivero_wot_product_consultant settings.enabled 0 -y
./vendor/bin/drush cr
```

The block ships `#cache => ['max-age' => 0]` and `getCacheMaxAge() === 0`, so nothing about it is
cached per user and a config change shows up on the next request. It is visible to anonymous
users — there is no access callback beyond the default `TRUE`.

Pattern semantics come straight from `PathMatcher`: `*` is a wildcard, `<front>` expands to the
front page's *internal* path (so on a fresh install `<front>` is `/node` and also matches
`/node`). Verified: `pages = "/user*"` hides the block on `/` and shows it on `/user/login`;
`pages = ""` shows it everywhere. Note that anonymous `/user` answers 302 to `/user/login`, so
test the login path, not `/user`.

## 7. Pointing the block at a staging API URL

1. Set `settings.base_url` to the staging origin that serves the widget files, e.g.
   `https://consultant-staging.example.com`. It must serve **both** `index.html` and `api.php`
   from that origin — the iframe calls the API relative to its own document.
2. `drush cr`.
3. The staging response must allow framing by the Drupal host:

   - no `X-Frame-Options: DENY|SAMEORIGIN` on `index.html` (Drupal sends
     `X-Frame-Options: SAMEORIGIN` on its own pages — that is unrelated, the iframe is the
     child, not the parent), or use `Content-Security-Policy: frame-ancestors https://apmag.example.com`
   - CORS is not needed for the iframe document itself, only if the widget's JS fetches the API
     across origins; `api.php` answers same-origin here, so keep both files on one origin
   - HTTPS page + HTTP base_url = blocked mixed content; both sides must share the scheme
4. If the page is served behind a CSP `frame-src`/`child-src` directive, add the widget origin
   there as well. The module injects no CSP of its own.
5. The widget JS is loaded on every page (the library is attached in `build()`), but the iframe
   is only created on the first click, so an unopened panel never contacts the staging host.

## 8. APSmag vs this local install

| Concern | Local (this repo) | APSmag production |
|---|---|---|
| Paths | repo `.../Qualitest/wot-ai-chat`, Drupal root `web/`, docroot `web/` | module goes to `<docroot>/modules/custom/wot_product_consultant`; composer-managed installs put it under `web/modules/custom` and require a `composer.json` path repository or a commit into the site repo |
| Database | SQLite `web/sites/default/files/.ht.sqlite` | MySQL/MariaDB (or Postgres) with credentials in `settings.php`/`settings.local.php`; **never** ship the SQLite file, and keep `settings.php` at 444 outside version control |
| Hash salt | generated string in `settings.php` | per-environment secret from the environment (`$settings['hash_salt'] = getenv('DRUPAL_HASH_SALT')`), never committed |
| CSS/JS pipeline | aggregation **off** (`css.preprocess`/`js.preprocess` = 0) | aggregation **on**; needs a real web server so `sites/default/files/css`/`js` are rewritten by `.htaccess`. Verify `/sites/default/files/css/*.css` returns 200 right after a deploy |
| Page cache | `page_cache` uninstalled | `page_cache` + `dynamic_page_cache` enabled; block `max-age 0` still bypasses both for its own markup, but the surrounding page caches normally, so a `base_url` change requires a cache rebuild or a cache-tag purge |
| Cache bins | database-backed (`cache_*` tables in SQLite) | often Redis/Memcached — verify the block still renders after `drush cr` because an unreachable cache backend hides the block until the container rebuilds |
| External cache | none | Varnish/CDN in front: purge `/` after changing `settings.base_url`, otherwise anonymous visitors keep the old iframe URL for the full TTL |
| Files permissions | `sites/default/files` 0775, dirs `drwxrwxr-x` | `files` writable by the web user (0775/2775 with setgid), `settings.php` 444, `settings.local.php` 444, private files outside the docroot |
| CSP | none | `frame-src`/`child-src` must include the widget origin; `X-Frame-Options` on the widget host must permit the APSmag host |
| Twig/debug | Twig cache on (default), no `settings.local.php` | `$settings['twig_debug']`/`twig_cache` per env; production keeps the Twig cache on |
| Serving | `php -S 127.0.0.1:8090 -t web` | Apache/Nginx + PHP-FPM; make sure `/modules/custom/...` static assets are served and not blocked by a WAF rule on `data-wot-*` attributes |

Local quirks worth knowing before blaming the module:

- With aggregation **on** under `php -S`, the page emitted
  `/sites/default/files/css/css_<hash>.css` URLs while the aggregate files were never dumped
  into `web/sites/default/files/css/` — every stylesheet 404'd and the site rendered unstyled.
  Writes to `public://css` work from both drush and the web SAPI (verified with a throwaway
  probe), so the failure is in the aggregation pipeline of the built-in server, not in file
  permissions. Aggregation off is the deterministic local setting; APSmag keeps it on.
- `page_cache` was uninstalled locally for the same reason: a cached anonymous HTML response
  kept replaying aggregate URLs after the aggregate files were gone, and it made block
  changes look like they had not applied.
- `drush user:login` links must be followed and used in the same shell with a cookie jar
  (`curl -c jar -b jar -L`), otherwise the session id rotates and `/admin` answers 403.

## 9. Files

```
web/modules/custom/wot_product_consultant/
├── wot_product_consultant.info.yml
├── wot_product_consultant.libraries.yml
├── config/schema/wot_product_consultant.schema.yml
├── css/wot-product-consultant.css
├── js/wot-product-consultant.js
└── src/Plugin/Block/ProductConsultantBlock.php
```

`ProductConsultantBlock` is an attribute-discovered `Block` plugin
(`#[Block(id: 'wot_product_consultant', ...)]`), implements
`ContainerFactoryPluginInterface` for `path.matcher`, and reads its settings out of the block
config entity, so `drush config:export` captures them.
