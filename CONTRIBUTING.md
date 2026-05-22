# Contributing

Thanks for considering a contribution. This is a small, opinionated checklist — but real-world counter-evidence is genuinely welcome. If your site does the opposite of what's recommended here and it works, open an issue or PR.

## Ground rules

- Be specific. "Cache more" is not an improvement. "Set `system.performance.cache.page.max_age` to N because X" is.
- Cite the Drupal version. A claim that holds on 11.x may not hold on 10.x.
- Prefer measurements over folklore. Numbers from a real Blackfire / k6 / `ab` run beat anecdotes.
- Keep examples copy-pasteable.

## Adding a new checklist item

Open a PR that:

1. Picks the right section in `README.md` (or proposes a new one if nothing fits).
2. States the recommendation in a single line, prefixed with one of:
   - ✔ should be true in production
   - ✘ should be false / off in production
   - ⚠ depends on site shape
3. Explains *why* in 1-3 lines underneath. Link to Drupal core docs / change records / community posts where they exist.
4. If there's a concrete config snippet (settings.php, services.yml, NGINX), put it in `config-examples/` and reference it from the README.

## Adding a new check to the Drush module

Each `perf:*` subcommand lives in `drush_perf_audit/src/Commands/PerfAuditCommands.php`. To add a check:

1. Write a `protected function checkSomething(): array` that returns

   ```php
   ['check' => '...', 'status' => 'OK|WARN|FAIL|INFO|SKIP', 'detail' => '...']
   ```

2. Wire it into `audit()` (and into `cacheStatus()` if it's cache-shaped).
3. Use real Drupal APIs (`\Drupal::config()`, `ModuleHandlerInterface`, `Settings::get()`) — no shelling out, no parsing `phpinfo()`.
4. Be defensive. The command may run on a half-installed site. Wrap any introspection that can fail in `try { ... } catch (\Throwable $e) { ... }` and degrade to `SKIP`.

## Coding standards

- PHP follows Drupal coding standards (`composer phpcs` or `phpcs --standard=Drupal,DrupalPractice`).
- YAML is checked with `yamllint`.
- Shell scripts (if any) are checked with `shellcheck`.

CI runs all three on every PR. Fix the report; don't suppress it without a comment explaining why.

## Reporting a bug

Open an issue with:
- Drupal core version
- PHP version
- The exact command output (with `--verbose` if relevant)
- Steps to reproduce on a fresh `composer create-project drupal/recommended-project`

## License

By contributing you agree your contribution is licensed under MIT, the same as the project.
