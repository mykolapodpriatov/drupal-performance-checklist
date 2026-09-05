<?php

declare(strict_types=1);

namespace Drupal\Tests\drush_perf_audit\Unit;

use Drupal\drush_perf_audit\AuditVerdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the perf:gate pass/fail decision.
 *
 * These run without Drupal or Drush. The decision lives in AuditVerdict rather
 * than in the Drush command precisely so it can be asserted here: this
 * repository has no Drupal bootstrap in its suite, so logic left in the command
 * would be untested, and a release gate that is untested is a release gate that
 * silently stops working.
 */
final class AuditVerdictTest extends TestCase {

  /**
   * Builds a findings row in the shape perf:audit produces.
   *
   * @param string $check
   *   The check name.
   * @param string $status
   *   The reported status.
   *
   * @return array<string, string>
   *   One findings row.
   */
  private static function row(string $check, string $status): array {
    return ['check' => $check, 'status' => $status, 'detail' => ''];
  }

  /**
   * Severity is ordered from least to most severe.
   */
  public function testSeverityIsOrdered(): void {
    $this->assertLessThan(AuditVerdict::rank('WARN'), AuditVerdict::rank('OK'));
    $this->assertLessThan(AuditVerdict::rank('WARN'), AuditVerdict::rank('INFO'));
    $this->assertLessThan(AuditVerdict::rank('FAIL'), AuditVerdict::rank('WARN'));
  }

  /**
   * An unknown status ranks as the worst, so a typo fails loudly.
   */
  public function testUnknownStatusRanksAsWorst(): void {
    $this->assertSame(AuditVerdict::rank('FAIL'), AuditVerdict::rank('OKAY'));
    $this->assertSame(AuditVerdict::rank('FAIL'), AuditVerdict::rank(''));
  }

  /**
   * Status matching ignores case and surrounding space.
   */
  public function testStatusMatchingIsForgivingOfCase(): void {
    $this->assertSame(AuditVerdict::rank('OK'), AuditVerdict::rank(' ok '));
  }

  /**
   * The worst status across a set of rows is the most severe one.
   */
  public function testWorstPicksTheMostSevereRow(): void {
    $rows = [
      self::row('cache', 'OK'),
      self::row('twig', 'FAIL'),
      self::row('proxy', 'WARN'),
    ];

    $this->assertSame('FAIL', AuditVerdict::worst($rows));
  }

  /**
   * No rows yields NULL, which callers must not read as a pass.
   */
  public function testWorstIsNullWithoutRows(): void {
    $this->assertNull(AuditVerdict::worst([]));
  }

  /**
   * Every bucket is present in a tally, including the empty ones.
   */
  public function testTallyCountsEveryBucket(): void {
    $counts = AuditVerdict::tally([
      self::row('a', 'OK'),
      self::row('b', 'OK'),
      self::row('c', 'WARN'),
    ]);

    $this->assertSame(
      ['OK' => 2, 'SKIP' => 0, 'INFO' => 0, 'WARN' => 1, 'FAIL' => 0],
      $counts,
    );
  }

  /**
   * An unknown status is tallied into the worst bucket.
   */
  public function testTallyFoldsUnknownIntoWorst(): void {
    $counts = AuditVerdict::tally([self::row('a', 'PROBABLY-FINE')]);

    $this->assertSame(1, $counts['FAIL']);
  }

  /**
   * A site with nothing worse than INFO passes at either threshold.
   */
  public function testCleanSitePasses(): void {
    $rows = [self::row('a', 'OK'), self::row('b', 'SKIP'), self::row('c', 'INFO')];

    $this->assertFalse(AuditVerdict::shouldFail($rows, 'fail'));
    $this->assertFalse(AuditVerdict::shouldFail($rows, 'warn'));
  }

  /**
   * A FAIL stops the release at either threshold.
   */
  public function testFailStopsTheRelease(): void {
    $rows = [self::row('a', 'OK'), self::row('twig debug', 'FAIL')];

    $this->assertTrue(AuditVerdict::shouldFail($rows, 'fail'));
    $this->assertTrue(AuditVerdict::shouldFail($rows, 'warn'));
  }

  /**
   * A WARN stops the release only when the caller asked for that.
   */
  public function testWarningStopsTheReleaseOnlyWhenAsked(): void {
    $rows = [self::row('a', 'OK'), self::row('page max-age', 'WARN')];

    $this->assertFalse(AuditVerdict::shouldFail($rows, 'fail'));
    $this->assertTrue(AuditVerdict::shouldFail($rows, 'warn'));
  }

  /**
   * An informational row never stops a release.
   *
   * Otherwise people add exclusions until the gate means nothing.
   */
  public function testInformationalRowsNeverFail(): void {
    $rows = [self::row('reverse proxy', 'INFO'), self::row('a', 'SKIP')];

    $this->assertFalse(AuditVerdict::shouldFail($rows, 'fail'));
    $this->assertFalse(AuditVerdict::shouldFail($rows, 'warn'));
  }

  /**
   * No rows is a failure: the checks did not run.
   */
  public function testNoRowsFailsTheGate(): void {
    $this->assertTrue(AuditVerdict::shouldFail([], 'fail'));
    $this->assertTrue(AuditVerdict::shouldFail([], 'warn'));
  }

  /**
   * Offenders lists only the rows at or above the threshold.
   */
  public function testOffendersRespectTheThreshold(): void {
    $rows = [
      self::row('cache', 'OK'),
      self::row('twig debug', 'FAIL'),
      self::row('page max-age', 'WARN'),
      self::row('reverse proxy', 'INFO'),
    ];

    $this->assertSame(['twig debug'], AuditVerdict::offenders($rows, 'fail'));
    $this->assertSame(['twig debug', 'page max-age'], AuditVerdict::offenders($rows, 'warn'));
  }

  /**
   * Offenders come back in the order the checks ran.
   */
  public function testOffendersPreserveOrder(): void {
    $rows = [
      self::row('second', 'FAIL'),
      self::row('first', 'FAIL'),
    ];

    $this->assertSame(['second', 'first'], AuditVerdict::offenders($rows, 'fail'));
  }

  /**
   * A row with no check name falls back to its status.
   */
  public function testOffendersFallBackToTheStatus(): void {
    $this->assertSame(['FAIL'], AuditVerdict::offenders([['status' => 'FAIL']], 'fail'));
  }

  /**
   * Provides threshold spellings and the value each normalises to.
   *
   * @return list<array{string, string}>
   *   Pairs of raw input and expected normalised threshold.
   */
  public static function thresholdProvider(): array {
    return [
      ['fail', 'fail'],
      ['warn', 'warn'],
      ['FAIL', 'fail'],
      [' Warn ', 'warn'],
    ];
  }

  /**
   * Threshold matching ignores case and surrounding space.
   *
   * @param string $given
   *   The raw --fail-on value.
   * @param string $expected
   *   The normalised threshold.
   */
  #[DataProvider('thresholdProvider')]
  public function testThresholdMatchingIsForgivingOfCase(string $given, string $expected): void {
    $this->assertSame($expected, AuditVerdict::normalizeThreshold($given));
  }

  /**
   * Provides values that are not valid thresholds.
   *
   * @return list<array{string}>
   *   Rejected --fail-on values.
   */
  public static function badThresholdProvider(): array {
    return [['info'], ['ok'], ['never'], [''], ['0']];
  }

  /**
   * An unknown threshold is an error, never a silent permissive fallback.
   *
   * @param string $given
   *   The rejected --fail-on value.
   */
  #[DataProvider('badThresholdProvider')]
  public function testUnknownThresholdIsAnError(string $given): void {
    $this->expectException(\InvalidArgumentException::class);
    AuditVerdict::normalizeThreshold($given);
  }

}
