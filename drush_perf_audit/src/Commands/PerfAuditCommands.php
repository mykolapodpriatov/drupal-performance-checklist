<?php

declare(strict_types=1);

namespace Drupal\drush_perf_audit\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Site\Settings;
use Drush\Commands\DrushCommands;

/**
 * Provides the perf:* family of Drush commands.
 *
 * Each command inspects the running Drupal site (config, enabled modules,
 * settings.php constants where reachable) and reports findings. The intent is
 * a fast pre-release sanity check, not a profiler.
 */
final class PerfAuditCommands extends DrushCommands {

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The module handler.
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * Constructs a PerfAuditCommands object.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ModuleHandlerInterface $module_handler
  ) {
    parent::__construct();
    $this->configFactory = $config_factory;
    $this->moduleHandler = $module_handler;
  }

  /**
   * Run the full performance audit.
   *
   * Inspects production-relevant configuration values, enabled cache modules,
   * and a handful of settings.php constants. Prints a single table of
   * findings.
   *
   * @command perf:audit
   * @aliases pa
   * @field-labels
   *   check: Check
   *   status: Status
   *   detail: Detail
   * @default-fields check,status,detail
   *
   * @usage drush perf:audit
   *   Run all checks.
   *
   * @filter-default-field check
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Findings as a table.
   */
  public function audit(): RowsOfFields {
    $rows = [];

    $rows[] = $this->checkModule('page_cache', 'Anonymous internal page cache module.');
    $rows[] = $this->checkModule('dynamic_page_cache', 'Caches authenticated requests minus auto-placeholders.');
    $rows[] = $this->checkModule('big_pipe', 'Streams personalised placeholders after the cacheable shell.');

    $rows[] = $this->checkTwigDebug();
    $rows[] = $this->checkErrorLevel();
    $rows[] = $this->checkCssAggregation();
    $rows[] = $this->checkJsAggregation();
    $rows[] = $this->checkPageMaxAge();
    $rows[] = $this->checkReverseProxy();

    return new RowsOfFields($rows);
  }

  /**
   * Check that Twig debug is off in production.
   */
  protected function checkTwigDebug(): array {
    try {
      /** @var \Twig\Environment $twig */
      $twig = \Drupal::service('twig');
      $debug = $twig->isDebug();
    }
    catch (\Throwable $e) {
      $debug = NULL;
    }

    return [
      'check' => 'Twig debug',
      'status' => $debug === FALSE ? 'OK' : ($debug === TRUE ? 'FAIL' : 'SKIP'),
      'detail' => $debug === TRUE
        ? 'twig.config.debug is ON. Disable in production services.yml.'
        : ($debug === FALSE ? 'twig.config.debug is off.' : 'Could not introspect twig service.'),
    ];
  }

  /**
   * Check that error reporting is hidden in production.
   */
  protected function checkErrorLevel(): array {
    $level = (string) $this->configFactory->get('system.logging')->get('error_level');
    $ok = $level === 'hide';
    return [
      'check' => 'system.logging.error_level',
      'status' => $ok ? 'OK' : 'WARN',
      'detail' => sprintf('Current: %s (production should be "hide").', $level ?: 'unset'),
    ];
  }

  /**
   * Check CSS aggregation.
   */
  protected function checkCssAggregation(): array {
    $on = (bool) $this->configFactory->get('system.performance')->get('css.preprocess');
    return [
      'check' => 'CSS aggregation',
      'status' => $on ? 'OK' : 'FAIL',
      'detail' => $on ? 'Enabled.' : 'Disabled. Enable system.performance.css.preprocess in prod.',
    ];
  }

  /**
   * Check JS aggregation.
   */
  protected function checkJsAggregation(): array {
    $on = (bool) $this->configFactory->get('system.performance')->get('js.preprocess');
    return [
      'check' => 'JS aggregation',
      'status' => $on ? 'OK' : 'FAIL',
      'detail' => $on ? 'Enabled.' : 'Disabled. Enable system.performance.js.preprocess in prod.',
    ];
  }

  /**
   * Check page max-age.
   */
  protected function checkPageMaxAge(): array {
    $age = (int) $this->configFactory->get('system.performance')->get('cache.page.max_age');
    return [
      'check' => 'Anonymous page max-age',
      'status' => $age >= 60 ? 'OK' : 'WARN',
      'detail' => $age > 0
        ? sprintf('%d seconds.', $age)
        : 'Set to 0 — anonymous page cache effectively disabled.',
    ];
  }

  /**
   * Check reverse_proxy setting from $settings.
   */
  protected function checkReverseProxy(): array {
    $rp = (bool) Settings::get('reverse_proxy', FALSE);
    $addrs = Settings::get('reverse_proxy_addresses', []);
    return [
      'check' => 'reverse_proxy in settings.php',
      'status' => $rp ? 'OK' : 'INFO',
      'detail' => $rp
        ? sprintf('Enabled, %d trusted address(es).', is_array($addrs) ? count($addrs) : 0)
        : 'Off. Set if running behind Varnish / CDN / load balancer.',
    ];
  }

  /**
   * Check that a module is enabled.
   */
  protected function checkModule(string $name, string $detail): array {
    $on = $this->moduleHandler->moduleExists($name);
    return [
      'check' => sprintf('Module: %s', $name),
      'status' => $on ? 'OK' : 'FAIL',
      'detail' => $detail,
    ];
  }

}
