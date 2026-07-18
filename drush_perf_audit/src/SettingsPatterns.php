<?php

declare(strict_types=1);

namespace Drupal\drush_perf_audit;

/**
 * Regex catalogue used by the perf:settings-audit command.
 *
 * The patterns are hand-written greps against the raw text of a settings.php
 * file. They live in this dependency-free value object (rather than inline in
 * PerfAuditCommands) so they can be asserted by unit tests without
 * bootstrapping Drupal or Drush. Keep the expressions byte-identical to the
 * ones documented for the command — a silent change here changes what the
 * command reports.
 *
 * Two catalogues with opposite polarity are exposed:
 * - PATTERNS: a match is a finding (an antipattern is present).
 * - REQUIRED: the ABSENCE of a match is a finding (a mandatory hardening line
 *   is missing).
 */
final class SettingsPatterns {

  /**
   * Antipatterns whose PRESENCE in settings.php is a finding.
   *
   * Map of human-readable label => PCRE pattern. Each pattern is applied with
   * preg_match_all() against the raw contents of the scanned settings file.
   *
   * @var array<string, string>
   */
  public const PATTERNS = [
    'rebuild_access enabled' => '/\$settings\s*\[\s*[\'"]rebuild_access[\'"]\s*\]\s*=\s*TRUE\b/i',
    'verbose error_level' => '/\$config\s*\[\s*[\'"]system\.logging[\'"]\s*\]\s*\[\s*[\'"]error_level[\'"]\s*\]\s*=\s*[\'"](?:verbose|all)[\'"]/',
    'hard-coded hash_salt' => '/\$settings\s*\[\s*[\'"]hash_salt[\'"]\s*\]\s*=\s*[\'"][^\'"]+[\'"]/',
  ];

  /**
   * Settings that MUST be present; their ABSENCE is a finding.
   *
   * Map of human-readable label => PCRE pattern that detects the required
   * assignment. If preg_match() returns 0 for one of these against a settings
   * file, the command reports the label as a missing hardening line.
   *
   * @var array<string, string>
   */
  public const REQUIRED = [
    'missing trusted_host_patterns' => '/\$settings\s*\[\s*[\'"]trusted_host_patterns[\'"]\s*\]\s*=/',
  ];

}
