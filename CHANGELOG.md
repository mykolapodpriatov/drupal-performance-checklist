# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `perf:cache-status` subcommand reporting page-cache, dynamic-page-cache, BigPipe state and max-age.
- `perf:render-deprecated` subcommand scanning custom modules for stale render-API patterns.
- `perf:db-slow` subcommand surfacing slow query entries from `dblog`.

## [0.2.0] - 2026-04-11

### Added
- `drush_perf_audit` module providing the `perf:audit` aggregate command.
- Service registration via `drush_perf_audit.services.yml`.
- `drush/drush ^12 || ^13` requirement in composer.json.

## [0.1.0] - 2026-03-09

### Added
- Initial README with sections on caching layers, BigPipe, render arrays.
- Expanded checklist with database, image styles, JS/CSS aggregation, HTTP caching sections.
- MIT license, baseline `.gitignore`, `composer.json` scaffolding.

[Unreleased]: https://example.com/changelog/HEAD
[0.2.0]: https://example.com/changelog/v0.2.0
[0.1.0]: https://example.com/changelog/v0.1.0
