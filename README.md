# Drupal Performance Pre-Release Checklist

A practical, opinionated checklist for taking a Drupal 10 / 11 site from "it works on my laptop" to "it survives Black Friday on a shared CDN". Ships with a small Drush module that automates the boring parts of the audit.

Curated and maintained against Drupal 11.x (with notes for 10.3+). PHP 8.3+ assumed.

> This is the checklist I actually run before every prod release. Not a marketing list of buzzwords.

---

## Table of Contents

1. [How to use this](#how-to-use-this)
2. [Caching Layers](#caching-layers)
3. [BigPipe and Placeholders](#bigpipe-and-placeholders)
4. [Render Array Audit](#render-array-audit)
5. [Database](#database)
6. [Image Styles and Media](#image-styles-and-media)
7. [JS / CSS Aggregation](#js--css-aggregation)
8. [HTTP Caching](#http-caching)
9. [Search and Indexing](#search-and-indexing)
10. [Cron and Queues](#cron-and-queues)
11. [Settings.php Production Checklist](#settingsphp-production-checklist)
12. [Web Server Config](#web-server-config)
13. [Profiling Tools](#profiling-tools)
14. [References](#references)

---

## How to use this

Three ways:

1. **Read top to bottom** before your first major prod release. Most teams ship with 3-5 items from this list misconfigured.
2. **Skim by section** when chasing a specific symptom (TTFB high, cache hit ratio low, image bandwidth blowing the budget).
3. **Run the bundled Drush command** to automate the checks that *can* be automated:

   ```bash
   ddev drush perf:audit
   ```

   See [`drush_perf_audit/`](./drush_perf_audit) for the module and its subcommands.

Markers used below:
- ✔ = should be true in production
- ✘ = should be false / off in production
- ⚠ = check carefully, depends on site shape

---

## Caching Layers

Drupal has *six* meaningful caching layers (CDN, reverse proxy, Page Cache, Dynamic Page Cache, render cache, entity cache). Each has its own invalidation contract. Most "weird caching bugs" are actually a mismatch between two layers.

### Cache tags strategy

- ✔ Every renderable that depends on an entity carries that entity's cache tag (`node:123`, `user:5`, `taxonomy_term:42`).
- ✔ Custom tags use a stable, prefixed naming convention: `mymodule:report:weekly`, not `report` or `weekly_report`.
- ✔ When you write a custom block / controller that lists entities, use the list tag (`node_list`, `node_list:article`).
- ⚠ Don't invent a tag if the entity already provides one. `Cache::PERMANENT` + the right tag beats `max-age = 60` every time.
- ✔ On config save, invalidate the tag(s) the config produces. `Drupal\Core\Cache\Cache::invalidateTags(['mymodule:settings'])`.

### Cache contexts

- ✔ Anything that varies per user role uses `user.permissions` (not `user`).
- ✔ Anything that depends on URL path / query / route uses the *narrowest* context: `route` before `url`, `url.path` before `url`.
- ✘ Avoid `user` as a cache context unless the output is genuinely per-user. It explodes cache cardinality.
- ⚠ `languages:language_interface` is added automatically for translated UI; don't add it again manually.

### Cache max-age

- ✔ `Cache::PERMANENT` (`-1`) for anything invalidated by tags. This is the default and the right answer ~90% of the time.
- ⚠ `0` only for content that genuinely cannot be cached (e.g. live dashboard tile). Better: lazy-build via BigPipe.
- ⚠ A finite max-age (e.g. `3600`) is almost always a code smell — it usually means you didn't model the cache tag.

### Page Cache vs Dynamic Page Cache vs Internal Page Cache

| Layer | Audience | Where | Use when |
|---|---|---|---|
| Internal Page Cache | Anonymous only | bin: `page` | Site is mostly anonymous. Default ON. |
| Dynamic Page Cache | Anonymous + authenticated | bin: `dynamic_page_cache` | Always. Caches everything except `auto_placeholder`-marked elements. |
| External (Varnish/CDN) | Anonymous | reverse proxy | Always for non-trivial traffic. |

- ✔ Both `page_cache` and `dynamic_page_cache` modules enabled.
- ✔ `BigPipe` enabled for authenticated UI.
- ✔ Reverse-proxy purging configured via `purge` + `varnish_purger` (or CDN-specific module).

### Render array #cache discipline

Every non-trivial render array should look like:

```php
$build = [
  '#theme' => 'my_widget',
  '#data' => $data,
  '#cache' => [
    'tags' => Cache::mergeTags(['mymodule:widget'], $entity->getCacheTags()),
    'contexts' => ['user.permissions', 'url.path'],
    'max-age' => Cache::PERMANENT,
  ],
];
```

Run `perf:render-deprecated` for a best-effort grep for deprecated render-API antipatterns.

---

## BigPipe and Placeholders

BigPipe streams the slow bits *after* the cacheable shell, so TTFB stays low even with personalised content.

- ✔ `big_pipe` module enabled.
- ✔ Personalised blocks (user menu, cart count, "logged in as") use `#lazy_builder`, not direct render.
- ✘ Don't lazy-build cheap things. Lazy build has overhead — only worth it for genuinely expensive or per-user content.

### Lazy builder pattern

```php
$build['cart_count'] = [
  '#lazy_builder' => ['my_module.lazy_builders:cartCount', []],
  '#create_placeholder' => TRUE,
];
```

Service:

```yaml
my_module.lazy_builders:
  class: Drupal\my_module\LazyBuilders
  arguments: ['@current_user']
```

The lazy builder must be a service (or `'classname:method'`) and must return a render array with its own `#cache` keys.

### Placeholder strategy in settings.php

Tune the auto-placeholder threshold. The defaults are conservative.

```php
// services.yml
parameters:
  renderer.config:
    required_cache_contexts: ['languages:language_interface', 'theme', 'user.permissions']
    auto_placeholder_conditions:
      max-age: 0
      contexts: ['session', 'user']
      tags: []
```

- ⚠ Adding `'url.query_args'` to `auto_placeholder_conditions.contexts` is often a big win on sites with filtered listings.

---

## Render Array Audit

The single most common reason a Drupal site is slow in production: render arrays that look cached but aren't.

### Antipatterns

- ✘ Calling `\Drupal::service('renderer')->render($build)` inside `hook_preprocess_*`. Renders during build = bubbled cacheability lost.
- ✘ Returning a raw HTML string from a controller. Wrap in `['#markup' => Markup::create($html), '#cache' => [...]]`.
- ✘ `$build['#prefix'] = $node->title->value;` — that's an XSS hole *and* skips render caching of the title.
- ✘ Forgetting `#cache` on a custom block plugin. Custom blocks default to `max-age: 0` if you don't set them.
- ⚠ `hook_preprocess_node` adding heavy data — use a lazy builder or a render-cached sub-build.

### Twig

- ✔ `twig.config.debug: false` in `services.yml` for prod.
- ✔ `twig.config.auto_reload: false` for prod.
- ✔ `twig.config.cache: true` for prod.
- ✘ Conditional `{% if user.isAuthenticated %}` in a globally-rendered template without `user.roles` (or narrower) in `#cache.contexts`.

### Block render caching

- ✔ Custom block plugins set `getCacheTags()`, `getCacheContexts()`, `getCacheMaxAge()`.
- ✔ Block visibility conditions (path, role) bubble their own contexts automatically — don't override.

---

## Database

The DB is the easiest layer to mismeasure. "Slow query" reports without context lie. Use slow log + a profiler.

### Query inspection

- ✔ `dblog` enabled in pre-prod (off or rate-limited in prod for write-heavy sites).
- ✔ `webprofiler` (in `devel` contrib) on staging — shows query count per request, duplicates, N+1 patterns.
- ✔ MySQL `slow_query_log` on with `long_query_time = 0.5` during load tests.
- ✔ Run `EXPLAIN` on anything in the top 10 slowest list. Look for `Using filesort`, `Using temporary`, full scans on >10k row tables.

### Indexed fields and EntityQuery

- ✔ Custom base fields that are queried get `'indexes'` declared in `baseFieldDefinitions()`.
- ✔ Use `EntityQuery` for entity lookups. Drop to `Drupal::database()->select()` only when you need a JOIN across non-entity tables.
- ⚠ Configurable field storage adds a JOIN per field. If you query the same field 5 ways, consider making it a base field.

### Pager performance

- ✘ Don't paginate over `entityQuery()` with a `range()` of more than a few thousand offset — MySQL counts every row up to the offset.
- ✔ For deep listings, use seek pagination (`WHERE id > :last_seen ORDER BY id LIMIT N`).
- ⚠ Views with exposed filters + large pagers: enable Views caching (Tag-based), tune to a sensible max-age.

### Replica connection

```php
// settings.php
$databases['default']['replica'][] = [
  'database' => 'drupal',
  'username' => 'drupal_ro',
  'password' => '...',
  'host' => 'db-replica.internal',
  'port' => '3306',
  'driver' => 'mysql',
  'prefix' => '',
];
```

Drupal will route SELECTs to the replica when `db_replica` target is requested. Most core code does this for you. Custom code: `Database::getConnection('replica')`.

- ⚠ Watch for replication lag on writes-then-reads in the same request. Force primary with `Database::getConnection('default', 'default')` when needed.

---

## Image Styles and Media

Image bytes dominate page weight for content sites. This is the single highest-leverage section for most teams.

- ✔ All `<img>` tags emit `loading="lazy"` (Drupal 9.1+ does this by default for image fields). Verify in source.
- ✔ Responsive image module configured with a `srcset` covering 320 / 768 / 1280 / 1920 widths.
- ✔ Image styles set `quality` to `82` for JPEG (not the default `75`, not `90+`).
- ✔ WebP derivatives generated via core's `image_effects` or the `webp` contrib module. AVIF where supported.
- ✔ Image style derivative URLs are protected by the token (`image.settings: suppress_itok_output: false`).
- ⚠ Avoid generating derivatives on the first request in production — pre-warm critical styles via `drush image:flush` + crawl.
- ✘ Never serve unstyled `field--type-image` images on listing pages.

### Responsive image config example

```yaml
# config/sync/responsive_image.styles.hero.yml
breakpoint_group: my_theme
image_style_mappings:
  - breakpoint_id: my_theme.mobile
    multiplier: 1x
    image_mapping_type: image_style
    image_mapping: hero_640
  - breakpoint_id: my_theme.desktop
    multiplier: 1x
    image_mapping_type: image_style
    image_mapping: hero_1280
fallback_image_style: hero_640
```

---

## JS / CSS Aggregation

- ✔ `system.performance.css.preprocess: true`
- ✔ `system.performance.js.preprocess: true`
- ✔ Gzip / Brotli at the web server (NGINX `gzip on` + `brotli on` if module compiled).
- ⚠ `advagg` (contrib) gives finer control: bundling strategy, defer, async, critical CSS extraction. Worth it on D10/11 sites with many libraries.
- ✔ Heavy third-party JS loaded via `attached.html_head` with `defer` or `async` — not as a global library.

### Critical CSS

- Extract above-the-fold CSS to `critical.css`, inline in `<head>` via `hook_page_attachments`, defer the rest.
- Tools: `critical` (npm), `penthouse`. Run per template, not per page.

### HTTP/2 / HTTP/3

- ✔ Web server speaks HTTP/2 minimum. HTTP/3 (QUIC) where available.
- ✘ Don't bother with HTTP/2 Server Push — deprecated in Chrome. Use `<link rel="preload">` instead.

```php
// hook_page_attachments
$attachments['#attached']['html_head_link'][] = [
  [
    'rel' => 'preload',
    'href' => '/themes/custom/mytheme/fonts/inter.woff2',
    'as' => 'font',
    'type' => 'font/woff2',
    'crossorigin' => 'anonymous',
  ],
];
```

---

## HTTP Caching

The cheapest cache is the one the browser already has.

### Cache-Control headers

- ✔ Drupal sets `Cache-Control: max-age=N, public` for anonymous cacheable responses based on `page_cache_maximum_age`.
- ✔ `system.performance.cache.page.max_age` set to something like `21600` (6h) for editorial sites.
- ✔ Static assets (`/sites/default/files/css/...`, `/themes/...`) served with `expires max` / `Cache-Control: public, max-age=31536000, immutable`.

### Reverse proxy

- ✔ Varnish or a CDN in front for anonymous traffic.
- ✔ `BAN` / `PURGE` requests wired up via the `purge` + `varnish_purger` modules.
- ✔ Cache invalidation by tag (Drupal sends `Surrogate-Key` or `Cache-Tags` header; reverse proxy bans by tag).
- ⚠ If using a CDN with no tag support (CloudFront classic), fall back to short max-age + soft purge on deploy.

See [`config-examples/varnish.vcl.snippet`](./config-examples/varnish.vcl.snippet) for a working VCL.

### Tag-based purging

```php
// On entity save, the storage handler already invalidates the entity tag.
// For custom invalidations:
\Drupal::service('cache_tags.invalidator')
  ->invalidateTags(['mymodule:report:weekly']);
```

The `purge` queue picks these up and sends them to the configured purger (Varnish, Fastly, Cloudflare, Akamai).

---

## Search and Indexing

- ✔ Built-in core search OFF for any site bigger than ~5k nodes. It uses LIKE queries on `search_index`.
- ✔ Search API + Solr / Elasticsearch / OpenSearch for real search.
- ✔ Index processors enabled: HTML filter, tokenizer, stemmer, stopwords for the site language.
- ✔ Indexing runs from cron or a dedicated worker, never on the web request.
- ⚠ Boost: title fields and tags weighted higher than body.
- ⚠ Faceted search uses `facets` module with hard-cap on facet item count to avoid blowing up the result page.

---

## Cron and Queues

The web request is for rendering. Everything else goes elsewhere.

- ✘ Don't run cron via Drupal's default URL-based trigger in production. It blocks a PHP-FPM worker.
- ✔ Cron triggered via `drush cron` from system cron / k8s CronJob, every 5-15 min.
- ✔ Heavy work modeled as Queue Workers (`@QueueWorker` plugin).
- ✔ Queues processed by `drush queue:run <queue_name>` workers, not by `drush cron` alone.
- ⚠ For long-running queues, use the `advancedqueue` contrib or push to an external broker (Redis Streams, RabbitMQ).

```bash
# systemd / supervisord example
ExecStart=/var/www/html/vendor/bin/drush --root=/var/www/html/web queue:run my_module_email_queue --time-limit=55
```

---

## Settings.php Production Checklist

See [`config-examples/settings.production.php`](./config-examples/settings.production.php) for the full annotated example.

- ✔ `$settings['hash_salt']` set from env / secret, **never** committed.
- ✔ `$settings['trusted_host_patterns']` set to your real host(s) only.
- ✔ `$settings['reverse_proxy'] = TRUE;` if behind Varnish/CDN.
- ✔ `$settings['reverse_proxy_addresses']` lists trusted proxy IPs.
- ✔ `$settings['file_temp_path'] = '/tmp/drupal-tmp';` on a non-shared, writable mount.
- ✔ `$settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.production.yml';`
- ✔ Redis or Memcache for cache backends, configured at the very bottom of settings.php so all bins use it.
- ✔ `config_split` activated for `prod` — settings.php sets `$config['config_split.config_split.prod']['status'] = TRUE;`.
- ✘ `$settings['rebuild_access'] = TRUE;` must be OFF in prod.
- ✘ `$config['system.logging']['error_level'] = 'hide';` in prod. (Use `verbose` in dev only.)

### settings.local.php for dev

- Loaded conditionally at the bottom of `settings.php`.
- Enables `devel`, sets verbose error reporting, points to `services.dev.yml` with twig debug ON.
- Never present on production filesystems.

---

## Web Server Config

See [`config-examples/nginx.conf.snippet`](./config-examples/nginx.conf.snippet). Key bullets:

- ✔ `client_max_body_size` matches the largest expected upload.
- ✔ `fastcgi_buffers` tuned for HTML response size (default is fine for most).
- ✔ Static asset location blocks set `expires max; access_log off;`.
- ✔ `/sites/default/files/` allowed; PHP execution under it explicitly denied.
- ✔ `try_files $uri /index.php?$query_string;` as the catch-all.
- ✘ No `autoindex on` anywhere.

---

## Profiling Tools

In order of "how much should you use it":

1. **Blackfire.io** — Best for finding the actual hot path. DDEV has a one-command setup. Free tier covers most needs.
2. **webprofiler** (in `devel`) — In-page profiler. Per-request query counts, render time, cache hit/miss. Always on in staging.
3. **Xdebug profile mode** — Works in DDEV (`ddev xdebug on` then trigger profile). Reads with KCacheGrind / qcachegrind.
4. **Tideways** — Production-grade APM if you can afford it.
5. **`perf:audit`** (this repo) — sanity-check config, not a profiler.

### Blackfire in DDEV

```bash
ddev get ddev/ddev-blackfire
ddev restart
ddev blackfire <url>
```

### Xdebug profile in DDEV

```bash
ddev xdebug on
XDEBUG_TRIGGER=PROFILE curl https://my-site.ddev.site/
# .cachegrind file appears in /tmp inside the web container
```

---

## References

### Core docs

- [Cacheability of render arrays](https://www.drupal.org/docs/drupal-apis/render-api/cacheability-of-render-arrays)
- [Cache API overview](https://www.drupal.org/docs/drupal-apis/cache-api/cache-api)
- [Cache contexts](https://www.drupal.org/docs/drupal-apis/cache-api/cache-contexts)
- [Cache tags](https://www.drupal.org/docs/drupal-apis/cache-api/cache-tags)
- [BigPipe technical concept](https://www.drupal.org/docs/8/core/modules/big-pipe/big-pipe-technical-concept)
- [Setting up reverse proxy](https://www.drupal.org/docs/installing-drupal/trusted-host-settings)

### Contrib modules worth knowing

- `purge` + `varnish_purger` / `cloudflarepurger`
- `advagg` (advanced JS/CSS aggregation)
- `search_api` + `search_api_solr`
- `redis` (cache backend)
- `memcache` (cache backend)
- `image_optimize` + binary toolkits
- `responsive_image` (core)
- `webprofiler` (in `devel`)

### Community reading

- "High Performance Drupal" — O'Reilly. Old but the principles are unchanged.
- Acquia's "Drupal Performance" knowledge base.
- Lullabot's "Caching in Drupal 8/9/10" series.

---

## License

[MIT](./LICENSE). Use, fork, copy into your wiki, cite freely.
