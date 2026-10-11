<?php

declare(strict_types=1);

namespace Drupal\Tests\drush_perf_audit\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The shipped VCL must not store a response Drupal marked uncacheable.
 */
final class VarnishSnippetTest extends TestCase {

  /**
   * no-cache, no-store, private, and max-age=0 keep a zero TTL.
   */
  public function testZeroTtlIsNotRaisedWhenCacheControlForbidsStorage(): void {
    $path = dirname(__DIR__, 3) . '/config-examples/varnish.vcl.snippet';
    $vcl = file_get_contents($path);
    self::assertIsString($vcl);
    self::assertStringContainsString('set beresp.uncacheable = true;', $vcl);
    self::assertStringContainsString('else if (beresp.ttl <= 0s)', $vcl);
    self::assertDoesNotMatchRegularExpression(
      '/^    if \(beresp\.ttl <= 0s\) \{$/m',
      $vcl,
    );
  }

}
