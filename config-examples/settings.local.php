<?php

/**
 * @file
 * Development settings.local.php snippet for Drupal 10.3+ / 11.x.
 *
 * This is the dev counterpart of settings.production.php. It is included
 * conditionally from the bottom of settings.php (see the guard shipped in
 * settings.production.php) and must NEVER exist on a production filesystem —
 * it turns off caching and exposes verbose errors on purpose.
 *
 * Drupal core ships a similar example at
 * sites/example.settings.local.php; this trimmed version keeps the toggles
 * that matter for the performance workflow documented in the README.
 */

// -----------------------------------------------------------------------------
// Container YAML overrides — load the dev services file (twig debug on,
// auto_reload on, cache off, cacheability debug headers on).
// -----------------------------------------------------------------------------
$settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.dev.yml';

// -----------------------------------------------------------------------------
// Verbose errors — show every notice / warning and the full backtrace.
// The opposite of production, where error_level is 'hide'.
// -----------------------------------------------------------------------------
$config['system.logging']['error_level'] = 'verbose';
error_reporting(E_ALL);
ini_set('display_errors', 'TRUE');
ini_set('display_startup_errors', 'TRUE');

// -----------------------------------------------------------------------------
// Devel — enable the devel module and route errors through its backtrace
// handler. Install it first: `composer require --dev drupal/devel`.
// Value 1 is Drupal's default error handler, 2 is devel's backtrace handler.
// -----------------------------------------------------------------------------
$config['devel.settings']['error_handlers'] = [2 => 2];

// -----------------------------------------------------------------------------
// Disable render / page / dynamic page caching so template and code changes
// are visible immediately (route them to the null backend).
// -----------------------------------------------------------------------------
$settings['cache']['bins']['render'] = 'cache.backend.null';
$settings['cache']['bins']['page'] = 'cache.backend.null';
$settings['cache']['bins']['dynamic_page_cache'] = 'cache.backend.null';

// -----------------------------------------------------------------------------
// Disable CSS / JS aggregation so assets are served unminified and un-bundled.
// -----------------------------------------------------------------------------
$config['system.performance']['css']['preprocess'] = FALSE;
$config['system.performance']['js']['preprocess'] = FALSE;

// -----------------------------------------------------------------------------
// Development conveniences.
// - rebuild_access: allow rebuild.php without a token (dev only — production
//   MUST keep this off).
// - skip permissions hardening so the settings.php stays writable while you
//   iterate on config.
// -----------------------------------------------------------------------------
$settings['rebuild_access'] = TRUE;
$settings['skip_permissions_hardening'] = TRUE;

// -----------------------------------------------------------------------------
// Config split — activate the dev split, ignore the prod one.
// -----------------------------------------------------------------------------
$config['config_split.config_split.dev']['status'] = TRUE;
$config['config_split.config_split.prod']['status'] = FALSE;
