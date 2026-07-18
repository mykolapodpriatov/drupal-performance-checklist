<?php

declare(strict_types=1);

namespace Drupal\Tests\drush_perf_audit\Unit;

use Drupal\drush_perf_audit\TwigPatterns;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regex-level tests for the perf:twig-audit pattern catalogue.
 *
 * These run without Drupal or Drush: they exercise TwigPatterns::PATTERNS
 * against text fixtures under tests/fixtures/twig/. Each pattern has at least
 * one fixture that MUST match (a genuine antipattern) and one that MUST NOT
 * match (well-behaved markup). A silently broken or overly greedy regex
 * therefore fails the build instead of quietly changing what the command
 * reports.
 */
final class TwigPatternsTest extends TestCase {

  /**
   * Absolute path to the twig fixtures directory.
   */
  private const FIXTURES = __DIR__ . '/../../fixtures/twig';

  /**
   * Maps each pattern label to its positive and negative fixture files.
   *
   * @return array<string, array{0: string, 1: string, 2: string}>
   *   Rows of [label, positive fixture, negative fixture].
   */
  public static function patternFixtures(): array {
    return [
      '|raw filter' => [
        '|raw filter',
        'positive/raw_filter.twig.txt',
        'negative/raw_filter.twig.txt',
      ],
      'inline <script> block' => [
        'inline <script> block',
        'positive/inline_script.twig.txt',
        'negative/inline_script.twig.txt',
      ],
      'inline <style> block' => [
        'inline <style> block',
        'positive/inline_style.twig.txt',
        'negative/inline_style.twig.txt',
      ],
    ];
  }

  /**
   * The catalogue must not silently shrink to nothing.
   */
  public function testCatalogueIsNotEmpty(): void {
    self::assertNotEmpty(TwigPatterns::PATTERNS, 'The pattern catalogue is empty.');
  }

  /**
   * Every registered pattern must have a positive and a negative fixture.
   */
  public function testEveryPatternHasFixtures(): void {
    $patternLabels = array_keys(TwigPatterns::PATTERNS);
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
    $regex = TwigPatterns::PATTERNS[$label];
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
    $regex = TwigPatterns::PATTERNS[$label];
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
    $regex = TwigPatterns::PATTERNS[$label];
    $contents = $this->readFixture($negative);
    self::assertSame(
      0,
      preg_match($regex, $contents),
      sprintf('Pattern "%s" produced a false positive on clean fixture %s.', $label, $negative)
    );
  }

  /**
   * Reads a fixture file relative to the twig fixtures directory.
   */
  private function readFixture(string $relative): string {
    $path = self::FIXTURES . '/' . $relative;
    self::assertFileExists($path, sprintf('Missing fixture: %s', $relative));
    $contents = file_get_contents($path);
    self::assertIsString($contents, sprintf('Could not read fixture: %s', $relative));
    return $contents;
  }

}
