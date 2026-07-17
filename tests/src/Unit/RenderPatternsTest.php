<?php

declare(strict_types=1);

namespace Drupal\Tests\drush_perf_audit\Unit;

use Drupal\drush_perf_audit\RenderPatterns;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regex-level tests for the perf:render-deprecated pattern catalogue.
 *
 * These run without Drupal or Drush: they exercise RenderPatterns::PATTERNS
 * against text fixtures under tests/fixtures/. Each pattern has at least one
 * fixture that MUST match (a genuine antipattern) and one that MUST NOT match
 * (well-behaved code). A silently broken or overly greedy regex therefore
 * fails the build instead of quietly changing what the command reports.
 */
final class RenderPatternsTest extends TestCase {

  /**
   * Absolute path to the fixtures directory.
   */
  private const FIXTURES = __DIR__ . '/../../fixtures';

  /**
   * Maps each pattern label to its positive and negative fixture files.
   *
   * @return array<string, array{0: string, 1: string, 2: string}>
   *   Rows of [label, positive fixture, negative fixture].
   */
  public static function patternFixtures(): array {
    return [
      'drupal_render() call' => [
        'drupal_render() call',
        'positive/drupal_render.php.txt',
        'negative/drupal_render.php.txt',
      ],
      'render() inside preprocess' => [
        'render() inside preprocess',
        'positive/render_build.php.txt',
        'negative/render_build.php.txt',
      ],
      'raw #markup string' => [
        'raw #markup string',
        'positive/raw_markup.php.txt',
        'negative/raw_markup.php.txt',
      ],
      'missing #cache (block build)' => [
        'missing #cache (block build)',
        'positive/missing_cache.php.txt',
        'negative/missing_cache.php.txt',
      ],
    ];
  }

  /**
   * The catalogue must not silently shrink to nothing.
   */
  public function testCatalogueIsNotEmpty(): void {
    self::assertNotEmpty(RenderPatterns::PATTERNS, 'The pattern catalogue is empty.');
  }

  /**
   * Every registered pattern must have a positive and a negative fixture.
   */
  public function testEveryPatternHasFixtures(): void {
    $patternLabels = array_keys(RenderPatterns::PATTERNS);
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
  public function testPatternCompiles(string $label): void {
    $regex = RenderPatterns::PATTERNS[$label];
    self::assertNotFalse(
      @preg_match($regex, ''),
      sprintf('Pattern "%s" (%s) is not a valid regular expression.', $label, $regex)
    );
  }

  /**
   * The positive fixture for each pattern must be flagged.
   */
  #[DataProvider('patternFixtures')]
  public function testPositiveFixtureMatches(string $label, string $positive): void {
    $regex = RenderPatterns::PATTERNS[$label];
    $contents = $this->readFixture($positive);
    self::assertSame(
      1,
      preg_match($regex, $contents),
      sprintf('Pattern "%s" should have matched fixture %s but did not.', $label, $positive)
    );
  }

  /**
   * The negative fixture for each pattern must NOT be flagged.
   */
  #[DataProvider('patternFixtures')]
  public function testNegativeFixtureDoesNotMatch(string $label, string $positive, string $negative): void {
    $regex = RenderPatterns::PATTERNS[$label];
    $contents = $this->readFixture($negative);
    self::assertSame(
      0,
      preg_match($regex, $contents),
      sprintf('Pattern "%s" produced a false positive on clean fixture %s.', $label, $negative)
    );
  }

  /**
   * Reads a fixture file relative to the fixtures directory.
   */
  private function readFixture(string $relative): string {
    $path = self::FIXTURES . '/' . $relative;
    self::assertFileExists($path, sprintf('Missing fixture: %s', $relative));
    $contents = file_get_contents($path);
    self::assertIsString($contents, sprintf('Could not read fixture: %s', $relative));
    return $contents;
  }

}
