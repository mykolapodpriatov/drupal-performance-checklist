<?php

declare(strict_types=1);

namespace Drupal\Tests\drush_perf_audit\Unit;

use Drupal\drush_perf_audit\SettingsPatterns;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regex-level tests for the perf:settings-audit pattern catalogues.
 *
 * These run without Drupal or Drush: they exercise SettingsPatterns against
 * text fixtures under tests/fixtures/settings/. Two polarities are covered:
 * - PATTERNS: a match means the antipattern is present (a finding).
 * - REQUIRED: no match means a mandatory line is missing (a finding).
 *
 * Each pattern has a positive fixture that MUST produce a finding and a
 * negative fixture that MUST NOT, so a silently broken or overly greedy regex
 * fails the build instead of quietly changing what the command reports.
 */
final class SettingsPatternsTest extends TestCase {

  /**
   * Absolute path to the settings fixtures directory.
   */
  private const FIXTURES = __DIR__ . '/../../fixtures/settings';

  /**
   * Maps each pattern label to its fixtures and polarity.
   *
   * @return array<string, array{0: string, 1: string, 2: string, 3: bool}>
   *   Rows of [label, positive fixture, negative fixture, is-required].
   */
  public static function patternFixtures(): array {
    return [
      'rebuild_access enabled' => [
        'rebuild_access enabled',
        'positive/rebuild_access.php.txt',
        'negative/rebuild_access.php.txt',
        FALSE,
      ],
      'verbose error_level' => [
        'verbose error_level',
        'positive/error_level.php.txt',
        'negative/error_level.php.txt',
        FALSE,
      ],
      'hard-coded hash_salt' => [
        'hard-coded hash_salt',
        'positive/hash_salt.php.txt',
        'negative/hash_salt.php.txt',
        FALSE,
      ],
      'missing trusted_host_patterns' => [
        'missing trusted_host_patterns',
        'positive/trusted_host.php.txt',
        'negative/trusted_host.php.txt',
        TRUE,
      ],
    ];
  }

  /**
   * Neither catalogue may silently shrink to nothing.
   */
  public function testCataloguesAreNotEmpty(): void {
    self::assertNotEmpty(SettingsPatterns::PATTERNS, 'The PATTERNS catalogue is empty.');
    self::assertNotEmpty(SettingsPatterns::REQUIRED, 'The REQUIRED catalogue is empty.');
  }

  /**
   * Every registered pattern must have a positive and a negative fixture.
   */
  public function testEveryPatternHasFixtures(): void {
    $patternLabels = array_merge(
      array_keys(SettingsPatterns::PATTERNS),
      array_keys(SettingsPatterns::REQUIRED)
    );
    $fixtureLabels = array_keys(self::patternFixtures());
    sort($patternLabels);
    sort($fixtureLabels);
    self::assertSame(
      $patternLabels,
      $fixtureLabels,
      'Each pattern needs a positive and negative fixture (add fixtures when adding a pattern).'
    );
  }

  /**
   * Each pattern must be a valid PCRE expression.
   */
  #[DataProvider('patternFixtures')]
  public function testPatternCompiles(string $label, string $positive, string $negative, bool $required): void {
    $regex = $this->regexFor($label, $required);
    self::assertNotFalse(
      @preg_match($regex, ''),
      sprintf('Pattern "%s" (%s) is not a valid regular expression.', $label, $regex)
    );
  }

  /**
   * The positive fixture for each pattern must produce a finding.
   */
  #[DataProvider('patternFixtures')]
  public function testPositiveFixtureIsFlagged(string $label, string $positive, string $negative, bool $required): void {
    self::assertTrue(
      $this->producesFinding($label, $required, $this->readFixture($positive)),
      sprintf('Pattern "%s" should have flagged fixture %s but did not.', $label, $positive)
    );
  }

  /**
   * The negative fixture for each pattern must NOT produce a finding.
   */
  #[DataProvider('patternFixtures')]
  public function testNegativeFixtureIsNotFlagged(string $label, string $positive, string $negative, bool $required): void {
    self::assertFalse(
      $this->producesFinding($label, $required, $this->readFixture($negative)),
      sprintf('Pattern "%s" produced a false positive on clean fixture %s.', $label, $negative)
    );
  }

  /**
   * Resolves a label to its regex from the correct catalogue.
   */
  private function regexFor(string $label, bool $required): string {
    return $required
      ? SettingsPatterns::REQUIRED[$label]
      : SettingsPatterns::PATTERNS[$label];
  }

  /**
   * Applies a label's polarity to decide whether contents are a finding.
   *
   * Presence patterns flag on a match; required patterns flag on no match.
   */
  private function producesFinding(string $label, bool $required, string $contents): bool {
    $matched = preg_match($this->regexFor($label, $required), $contents) === 1;
    return $required ? !$matched : $matched;
  }

  /**
   * Reads a fixture file relative to the settings fixtures directory.
   */
  private function readFixture(string $relative): string {
    $path = self::FIXTURES . '/' . $relative;
    self::assertFileExists($path, sprintf('Missing fixture: %s', $relative));
    $contents = file_get_contents($path);
    self::assertIsString($contents, sprintf('Could not read fixture: %s', $relative));
    return $contents;
  }

}
