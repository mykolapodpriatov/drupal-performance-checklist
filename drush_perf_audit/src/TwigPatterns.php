<?php

declare(strict_types=1);

namespace Drupal\drush_perf_audit;

/**
 * Regex catalogue used by the perf:twig-audit command.
 *
 * The patterns are hand-written greps for a small set of Twig perf/security
 * smells. They live in this dependency-free value object (rather than inline
 * in PerfAuditCommands) so they can be asserted by unit tests without
 * bootstrapping Drupal or Drush. Keep the expressions byte-identical to the
 * ones documented for the command — a silent change here changes what the
 * command reports.
 */
final class TwigPatterns {

  /**
   * Map of human-readable label => PCRE pattern.
   *
   * Each pattern is applied with preg_match_all() against the raw contents of
   * scanned *.html.twig files.
   *
   * @var array<string, string>
   */
  public const PATTERNS = [
    // The |raw filter disables Twig autoescaping — an XSS vector unless the
    // value is already known-safe markup.
    '|raw filter' => '/\|\s*raw\b/',
    // Inline <script> blocks (no src attribute) ship render-blocking,
    // unaggregated, un-CSP-friendly JS straight from a template.
    'inline <script> block' => '/<script(?![^>]*\bsrc\s*=)[^>]*>/i',
    // Inline <style> blocks bypass CSS aggregation and add render-blocking CSS.
    'inline <style> block' => '/<style[^>]*>/i',
  ];

}
