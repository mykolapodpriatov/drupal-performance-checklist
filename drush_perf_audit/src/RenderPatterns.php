<?php

declare(strict_types=1);

namespace Drupal\drush_perf_audit;

/**
 * Regex catalogue used by the perf:render-deprecated command.
 *
 * The patterns are hand-written greps for a small set of render-API
 * antipatterns. They live in this dependency-free value object (rather than
 * inline in PerfAuditCommands) so they can be asserted by unit tests without
 * bootstrapping Drupal or Drush. Keep the expressions byte-identical to the
 * ones documented for the command — a silent change here changes what the
 * command reports.
 */
final class RenderPatterns {

  /**
   * Map of human-readable label => PCRE pattern.
   *
   * Each pattern is applied with preg_match_all() against the raw contents of
   * scanned *.php, *.module, *.inc and *.theme files.
   *
   * @var array<string, string>
   */
  public const PATTERNS = [
    'drupal_render() call' => '/\bdrupal_render\s*\(/',
    'render() inside preprocess' => '/->\s*render\s*\(\s*\$build\s*\)/',
    'raw #markup string' => '/#markup\'\s*=>\s*\$(?!safe|markup)[A-Za-z_]+(?!.*Markup::create)/',
    'missing #cache (block build)' => '/public function build\(\)\s*\{(?:(?!#cache).){1,400}return\s*\[/s',
  ];

}
