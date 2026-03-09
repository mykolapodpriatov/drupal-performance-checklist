# Drupal Performance Pre-Release Checklist

A practical, opinionated checklist for taking a Drupal 10 / 11 site from "it works on my laptop" to "it survives Black Friday on a shared CDN".

Curated and maintained against Drupal 11.x (with notes for 10.3+). PHP 8.3+ assumed.

> This is the checklist I actually run before every prod release. Not a marketing list of buzzwords.

---

## Table of Contents

1. [How to use this](#how-to-use-this)
2. [Caching Layers](#caching-layers)
3. [BigPipe and Placeholders](#bigpipe-and-placeholders)
4. [Render Array Audit](#render-array-audit)
5. [References](#references)

More sections (database, images, HTTP caching, server config, profiling) coming in follow-up commits.

---

## How to use this

Three ways:

1. **Read top to bottom** before your first major prod release. Most teams ship with 3-5 items from this list misconfigured.
2. **Skim by section** when chasing a specific symptom (TTFB high, cache hit ratio low, image bandwidth blowing the budget).
3. The bundled Drush command (in a follow-up commit) automates the checks that *can* be automated.

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

## References

- [Cacheability of render arrays](https://www.drupal.org/docs/drupal-apis/render-api/cacheability-of-render-arrays)
- [Cache API overview](https://www.drupal.org/docs/drupal-apis/cache-api/cache-api)
- [Cache contexts](https://www.drupal.org/docs/drupal-apis/cache-api/cache-contexts)
- [Cache tags](https://www.drupal.org/docs/drupal-apis/cache-api/cache-tags)
- [BigPipe technical concept](https://www.drupal.org/docs/8/core/modules/big-pipe/big-pipe-technical-concept)

---

## License

[MIT](./LICENSE). Use, fork, copy into your wiki, cite freely.
