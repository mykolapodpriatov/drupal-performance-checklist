<?php

declare(strict_types=1);

namespace Drupal\drush_perf_audit;

/**
 * Turns a set of audit findings into a pass/fail decision.
 *
 * The pattern catalogues live in dependency-free value objects so they can be
 * unit-tested without bootstrapping Drupal or Drush, and the gate's decision
 * belongs in the same place for the same reason: this repository has no Drupal
 * bootstrap in its test suite, so anything left inside the Drush command is
 * effectively untested.
 *
 * The status vocabulary is closed and ordered. A status the audit does not
 * define must never rank as "fine": a typo in a check would then silently
 * disable the gate, which is the one failure mode a release gate cannot have.
 */
final class AuditVerdict {

  /**
   * Statuses in increasing order of severity.
   *
   * OK   the check passed.
   * SKIP the check could not run (a setting unreachable from this context).
   * INFO a finding worth reading that is not a problem by itself.
   * WARN a real finding that a team may knowingly accept.
   * FAIL a misconfiguration that should stop a release.
   *
   * @var list<string>
   */
  public const SEVERITY = ['OK', 'SKIP', 'INFO', 'WARN', 'FAIL'];

  /**
   * Thresholds accepted by the gate, mapped to the status that trips them.
   *
   * INFO and below are deliberately not offered. A gate that can fail on an
   * informational row invites exclusion lists, and a gate with an exclusion
   * list means nothing.
   *
   * @var array<string, string>
   */
  public const THRESHOLDS = [
    'fail' => 'FAIL',
    'warn' => 'WARN',
  ];

  /**
   * Rank of a status, higher being worse.
   *
   * An unrecognised status ranks as the worst known severity rather than the
   * best, so a check that emits something unexpected fails loudly.
   */
  public static function rank(string $status): int {
    $index = array_search(strtoupper(trim($status)), self::SEVERITY, TRUE);

    return $index === FALSE ? count(self::SEVERITY) - 1 : $index;
  }

  /**
   * The worst status across the given rows, or NULL when there are none.
   *
   * NULL is not a pass: no rows means the checks did not run, which is a
   * different thing from everything being green. Callers must treat it as a
   * failure and say so.
   *
   * @param iterable<array<string, mixed>> $rows
   *   Rows as the perf:audit checks produce them, each carrying a 'status'.
   */
  public static function worst(iterable $rows): ?string {
    $worst = NULL;
    foreach ($rows as $row) {
      $status = isset($row['status']) ? (string) $row['status'] : '';
      if ($worst === NULL || self::rank($status) > self::rank($worst)) {
        $worst = strtoupper(trim($status));
      }
    }

    return $worst;
  }

  /**
   * Count of rows per status, in severity order, including empty buckets.
   *
   * Every bucket is present so a summary reads the same shape every run and a
   * consumer can diff two runs without reconciling missing keys.
   *
   * @param iterable<array<string, mixed>> $rows
   *   Rows as the perf:audit checks produce them.
   *
   * @return array<string, int>
   *   Status => count.
   */
  public static function tally(iterable $rows): array {
    $counts = array_fill_keys(self::SEVERITY, 0);
    foreach ($rows as $row) {
      $status = isset($row['status']) ? strtoupper(trim((string) $row['status'])) : '';
      $known = self::SEVERITY[self::rank($status)];
      $counts[$known]++;
    }

    return $counts;
  }

  /**
   * Names of the checks at or above the threshold, in the order given.
   *
   * @param iterable<array<string, mixed>> $rows
   *   Rows as the perf:audit checks produce them.
   * @param string $threshold
   *   A key of self::THRESHOLDS.
   *
   * @return list<string>
   *   Check names, or the raw status when a row has no name.
   */
  public static function offenders(iterable $rows, string $threshold): array {
    $floor = self::rank(self::THRESHOLDS[self::normalizeThreshold($threshold)]);
    $names = [];
    foreach ($rows as $row) {
      $status = isset($row['status']) ? (string) $row['status'] : '';
      if (self::rank($status) >= $floor) {
        $names[] = isset($row['check']) ? (string) $row['check'] : strtoupper(trim($status));
      }
    }

    return $names;
  }

  /**
   * Whether the given rows should fail a release at this threshold.
   *
   * @param iterable<array<string, mixed>> $rows
   *   Rows as the perf:audit checks produce them.
   * @param string $threshold
   *   A key of self::THRESHOLDS.
   */
  public static function shouldFail(iterable $rows, string $threshold): bool {
    $worst = self::worst($rows);
    if ($worst === NULL) {
      // The checks did not run. That is not a clean site.
      return TRUE;
    }

    return self::rank($worst) >= self::rank(self::THRESHOLDS[self::normalizeThreshold($threshold)]);
  }

  /**
   * Validate and normalise a --fail-on value.
   *
   * @throws \InvalidArgumentException
   *   When the value is not a known threshold. An unknown value is a usage
   *   error rather than a silent fallback to the permissive one: a typo must
   *   not quietly turn the gate off.
   */
  public static function normalizeThreshold(string $threshold): string {
    $key = strtolower(trim($threshold));
    if (!isset(self::THRESHOLDS[$key])) {
      throw new \InvalidArgumentException(sprintf(
        'Unknown --fail-on value %s; expected one of: %s.',
        var_export($threshold, TRUE),
        implode(', ', array_keys(self::THRESHOLDS)),
      ));
    }

    return $key;
  }

}
