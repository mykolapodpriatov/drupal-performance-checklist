<?php

/**
 * @file
 * Production settings.php snippet for Drupal 10.3+ / 11.x.
 *
 * Drop the relevant blocks into the bottom of your sites/default/settings.php
 * (or include this file from there). Comments explain the *why* — the values
 * still need to be tuned for your environment.
 */

// -----------------------------------------------------------------------------
// Hash salt — REQUIRED.
// Read from environment / secrets store, never hardcode in version control.
// -----------------------------------------------------------------------------
$settings['hash_salt'] = getenv('DRUPAL_HASH_SALT') ?: '';

// -----------------------------------------------------------------------------
// Trusted hosts — REQUIRED.
// Drupal rejects requests whose Host header does not match.
// Patterns are regex. Anchor with ^...$.
// -----------------------------------------------------------------------------
$settings['trusted_host_patterns'] = [
  '^www\.example\.com$',
  '^example\.com$',
];

// -----------------------------------------------------------------------------
// Reverse proxy — enable only if Drupal is behind Varnish / a load balancer
// / a CDN that injects X-Forwarded-* headers.
// `reverse_proxy_addresses` must list ONLY trusted proxies, otherwise clients
// can spoof their IP via X-Forwarded-For.
// -----------------------------------------------------------------------------
$settings['reverse_proxy'] = TRUE;
$settings['reverse_proxy_addresses'] = [
  '10.0.0.10',
  '10.0.0.11',
];
$settings['reverse_proxy_trusted_headers'] =
  \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_FOR
  | \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_HOST
  | \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PORT
  | \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PROTO;

// -----------------------------------------------------------------------------
// File system locations.
// `file_temp_path` must be a writable, non-shared mount. /tmp on a container
// will be wiped on restart, which is fine for Drupal's tmp usage.
// -----------------------------------------------------------------------------
$settings['file_public_path'] = 'sites/default/files';
$settings['file_private_path'] = '/var/drupal-private';
$settings['file_temp_path'] = '/tmp';

// -----------------------------------------------------------------------------
// Container YAML overrides.
// services.production.yml in this repo turns off twig debug, cacheability
// debug headers, etc.
// -----------------------------------------------------------------------------
$settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.production.yml';

// -----------------------------------------------------------------------------
// Logging — hide PHP errors from end users.
// -----------------------------------------------------------------------------
$config['system.logging']['error_level'] = 'hide';

// -----------------------------------------------------------------------------
// Config split — activate the prod split, ignore the dev one.
// -----------------------------------------------------------------------------
$config['config_split.config_split.prod']['status'] = TRUE;
$config['config_split.config_split.dev']['status']  = FALSE;

// -----------------------------------------------------------------------------
// Performance config defaults — these can also live in exported config.
// Listed here as a belt-and-braces guarantee.
// -----------------------------------------------------------------------------
$config['system.performance']['css']['preprocess'] = TRUE;
$config['system.performance']['js']['preprocess']  = TRUE;
$config['system.performance']['cache']['page']['max_age'] = 21600;

// -----------------------------------------------------------------------------
// Redis (optional) — uncomment if redis contrib + phpredis are installed.
// -----------------------------------------------------------------------------
// $settings['redis.connection']['interface'] = 'PhpRedis';
// $settings['redis.connection']['host'] = 'redis.internal';
// $settings['redis.connection']['port'] = 6379;
// $settings['cache']['default'] = 'cache.backend.redis';
// $settings['container_yamls'][] = 'modules/contrib/redis/example.services.yml';
// $settings['bootstrap_container_definition'] = [
//   'parameters' => [],
//   'services' => [
//     'redis.factory' => [
//       'class' => 'Drupal\redis\ClientFactory',
//     ],
//     'cache.backend.redis' => [
//       'class' => 'Drupal\redis\Cache\CacheBackendFactory',
//       'arguments' => ['@redis.factory', '@cache_tags_provider.container', '@serialization.phpserialize'],
//     ],
//     'cache.container' => [
//       'class' => '\Drupal\redis\Cache\PhpRedis',
//       'factory' => ['@cache.backend.redis', 'get'],
//       'arguments' => ['container'],
//     ],
//     'cache_tags_provider.container' => [
//       'class' => 'Drupal\redis\Cache\RedisCacheTagsChecksum',
//       'arguments' => ['@redis.factory'],
//     ],
//     'serialization.phpserialize' => [
//       'class' => 'Drupal\Component\Serialization\PhpSerialize',
//     ],
//   ],
// ];

// -----------------------------------------------------------------------------
// rebuild_access — MUST be off in production.
// -----------------------------------------------------------------------------
$settings['rebuild_access'] = FALSE;

// -----------------------------------------------------------------------------
// settings.local.php — optional, only loaded if present (dev only).
// Production hosts should never have this file on disk.
// -----------------------------------------------------------------------------
if (file_exists($app_root . '/' . $site_path . '/settings.local.php')) {
  include $app_root . '/' . $site_path . '/settings.local.php';
}
