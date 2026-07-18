# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `perf:settings-audit` Drush command (with `--file`) that greps a settings.php
  file for production-hardening antipatterns, backed by a dependency-free
  `SettingsPatterns` value object and positive/negative fixture unit tests.
- `perf:twig-audit` Drush command (with `--path`) that scans `*.html.twig`
  templates for `|raw` filters and inline `<script>`/`<style>` blocks, backed
  by a dependency-free `TwigPatterns` value object and fixture unit tests.
- `config-examples/settings.local.php` and `config-examples/services.dev.yml`
  annotated development config examples (verbose errors, devel, disabled
  render/page cache, Twig debug), linked from the README and yamllint in CI.

## [0.4.0] - 2026-06-22

### Added
- CI workflow: yamllint, PHPCS against Drupal/DrupalPractice standards, shellcheck.
- `CONTRIBUTING.md` with guidance for new checklist items and Drush checks.
- `composer phpcs` script.

### Changed
- Drush commands converted from legacy docblock annotations to the modern
  `Drush\Attributes` (PHP attribute) style for Drush 12/13.

### Fixed
- Removed the dangling `configure: drush_perf_audit.settings` info.yml key
  that produced a broken "Configure" link on the Extend page.

## [0.3.0] - 2026-05-09

### Added
- `config-examples/settings.production.php` annotated production settings snippet.
- `config-examples/nginx.conf.snippet` and `varnish.vcl.snippet` reference configs.
- `config-examples/services.production.yml` for production Twig / cacheability headers.
- README sections: settings.php production checklist, web server config, profiling tools.

## [0.2.0] - 2026-04-25

### Added
- `drush_perf_audit` module providing the `perf:audit` aggregate command.
- `perf:cache-status`, `perf:render-deprecated`, `perf:db-slow` subcommands.
- Service registration via `drush_perf_audit.services.yml`.
- `drush/drush ^12 || ^13` requirement in composer.json.

## [0.1.0] - 2026-03-09

### Added
- Initial README with sections on caching layers, BigPipe, render arrays.
- Expanded checklist with database, image styles, JS/CSS aggregation, HTTP caching sections.
- MIT license, baseline `.gitignore`, `composer.json` scaffolding.

[Unreleased]: https://github.com/mykolapodpriatov/drupal-performance-checklist/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/mykolapodpriatov/drupal-performance-checklist/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/mykolapodpriatov/drupal-performance-checklist/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/mykolapodpriatov/drupal-performance-checklist/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/mykolapodpriatov/drupal-performance-checklist/releases/tag/v0.1.0
