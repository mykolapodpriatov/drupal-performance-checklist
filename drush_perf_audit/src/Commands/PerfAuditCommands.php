<?php

declare(strict_types=1);

namespace Drupal\drush_perf_audit\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Site\Settings;
use Drupal\drush_perf_audit\RenderPatterns;
use Drupal\drush_perf_audit\SettingsPatterns;
use Drush\Attributes as CLI;
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
   * The default database connection.
   */
  protected Connection $database;

  /**
   * Constructs a PerfAuditCommands object.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ModuleHandlerInterface $module_handler,
    Connection $database,
  ) {
    parent::__construct();
    $this->configFactory = $config_factory;
    $this->moduleHandler = $module_handler;
    $this->database = $database;
  }

  /**
   * Run the full performance audit.
   *
   * Aggregates checks from cache-status and inspects a handful of generic
   * production settings. Prints a single table of findings.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Findings as a table.
   */
  #[CLI\Command(name: 'perf:audit', aliases: ['pa'])]
  #[CLI\FieldLabels(labels: [
    'check' => 'Check',
    'status' => 'Status',
    'detail' => 'Detail',
  ])]
  #[CLI\DefaultTableFields(fields: ['check', 'status', 'detail'])]
  #[CLI\FilterDefaultField(field: 'check')]
  #[CLI\Usage(name: 'drush perf:audit', description: 'Run all checks.')]
  public function audit(): RowsOfFields {
    $rows = [];

    foreach ($this->collectCacheChecks() as $row) {
      $rows[] = $row;
    }

    $rows[] = $this->checkTwigDebug();
    $rows[] = $this->checkErrorLevel();
    $rows[] = $this->checkCssAggregation();
    $rows[] = $this->checkJsAggregation();
    $rows[] = $this->checkPageMaxAge();
    $rows[] = $this->checkReverseProxy();
    $rows[] = $this->checkBigPipe();

    return new RowsOfFields($rows);
  }

  /**
   * Report the status of Drupal's page-caching modules and max-age.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Findings as a table.
   */
  #[CLI\Command(name: 'perf:cache-status', aliases: ['pcs'])]
  #[CLI\FieldLabels(labels: [
    'check' => 'Check',
    'status' => 'Status',
    'detail' => 'Detail',
  ])]
  #[CLI\DefaultTableFields(fields: ['check', 'status', 'detail'])]
  public function cacheStatus(): RowsOfFields {
    return new RowsOfFields($this->collectCacheChecks());
  }

  /**
   * Best-effort scan for deprecated render-API usage in custom code.
   *
   * Walks the custom-module directory and grep-matches a small set of known
   * antipatterns. False positives are expected on copy-pasted vendor code;
   * the report is intended as a starting point, not a verdict.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Matches as a table.
   */
  #[CLI\Command(name: 'perf:render-deprecated', aliases: ['prd'])]
  #[CLI\Option(name: 'path', description: 'Override the directory scanned. Defaults to modules/custom.')]
  #[CLI\FieldLabels(labels: [
    'file' => 'File',
    'line' => 'Line',
    'pattern' => 'Pattern',
    'excerpt' => 'Excerpt',
  ])]
  #[CLI\DefaultTableFields(fields: ['file', 'line', 'pattern', 'excerpt'])]
  public function renderDeprecated(array $options = ['path' => NULL]): RowsOfFields {
    $base = DRUPAL_ROOT;
    $relative = $options['path'] ?? 'modules/custom';
    $root = rtrim($base, '/') . '/' . ltrim($relative, '/');

    $rows = [];
    if (!is_dir($root)) {
      $this->logger()->warning(dt('Scan path @p not found, skipping.', ['@p' => $root]));
      return new RowsOfFields($rows);
    }

    $patterns = RenderPatterns::PATTERNS;

    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
      /** @var \SplFileInfo $file */
      if (!$file->isFile()) {
        continue;
      }
      $ext = strtolower($file->getExtension());
      if (!in_array($ext, ['php', 'module', 'inc', 'theme'], TRUE)) {
        continue;
      }

      $contents = @file_get_contents($file->getPathname());
      if ($contents === FALSE || $contents === '') {
        continue;
      }

      foreach ($patterns as $label => $regex) {
        if (!preg_match_all($regex, $contents, $matches, PREG_OFFSET_CAPTURE)) {
          continue;
        }
        foreach ($matches[0] as $match) {
          [$text, $offset] = $match;
          $line = substr_count(substr($contents, 0, $offset), "\n") + 1;
          $rows[] = [
            'file' => str_replace($base . '/', '', $file->getPathname()),
            'line' => $line,
            'pattern' => $label,
            'excerpt' => trim(substr($text, 0, 80)),
          ];
        }
      }
    }

    return new RowsOfFields($rows);
  }

  /**
   * Grep a settings.php file for production-hardening antipatterns.
   *
   * Reads a single settings file as text (never executing it) and applies the
   * SettingsPatterns catalogue: flags whose PRESENCE is a problem
   * (rebuild_access, verbose error_level, a hard-coded hash_salt) and lines
   * whose ABSENCE is a problem (trusted_host_patterns). It is a static lint,
   * not a runtime inspection, so it works against any checked-out settings
   * file without bootstrapping the site it configures.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Findings as a table.
   */
  #[CLI\Command(name: 'perf:settings-audit', aliases: ['psa'])]
  #[CLI\Option(name: 'file', description: 'Override the settings file scanned. Defaults to sites/default/settings.php.')]
  #[CLI\FieldLabels(labels: [
    'check' => 'Check',
    'line' => 'Line',
    'excerpt' => 'Excerpt',
  ])]
  #[CLI\DefaultTableFields(fields: ['check', 'line', 'excerpt'])]
  #[CLI\Usage(name: 'drush perf:settings-audit --file=sites/default/settings.php', description: 'Lint a settings file.')]
  public function settingsAudit(array $options = ['file' => NULL]): RowsOfFields {
    $relative = $options['file'] ?? 'sites/default/settings.php';
    $file = $relative;
    if (!is_file($file) && defined('DRUPAL_ROOT')) {
      $file = rtrim(DRUPAL_ROOT, '/') . '/' . ltrim($relative, '/');
    }

    $rows = [];
    if (!is_file($file)) {
      $this->logger()->warning(dt('Settings file @f not found, skipping.', ['@f' => $file]));
      return new RowsOfFields($rows);
    }

    $contents = @file_get_contents($file);
    if ($contents === FALSE || $contents === '') {
      $this->logger()->warning(dt('Settings file @f is empty or unreadable.', ['@f' => $file]));
      return new RowsOfFields($rows);
    }

    // Antipatterns: a match is a finding, reported with its line number.
    foreach (SettingsPatterns::PATTERNS as $label => $regex) {
      if (!preg_match_all($regex, $contents, $matches, PREG_OFFSET_CAPTURE)) {
        continue;
      }
      foreach ($matches[0] as $match) {
        [$text, $offset] = $match;
        $line = substr_count(substr($contents, 0, $offset), "\n") + 1;
        $rows[] = [
          'check' => $label,
          'line' => $line,
          'excerpt' => trim(substr($text, 0, 80)),
        ];
      }
    }

    // Required lines: the ABSENCE of a match is a finding.
    foreach (SettingsPatterns::REQUIRED as $label => $regex) {
      if (preg_match($regex, $contents) === 1) {
        continue;
      }
      $rows[] = [
        'check' => $label,
        'line' => '-',
        'excerpt' => 'Required hardening line not found.',
      ];
    }

    if (empty($rows)) {
      $this->logger()->notice(dt('No settings antipatterns matched in @f.', ['@f' => $file]));
    }

    return new RowsOfFields($rows);
  }

  /**
   * Surface the slowest queries logged to watchdog.
   *
   * Reads `watchdog` rows with type = 'system' or 'php' and looks for slow
   * query entries. Sites using dedicated slow-log handlers (e.g. New Relic)
   * will see nothing here.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Slow query rows.
   */
  #[CLI\Command(name: 'perf:db-slow', aliases: ['pds'])]
  #[CLI\Option(name: 'limit', description: 'Maximum rows to return. Defaults to 10.')]
  #[CLI\FieldLabels(labels: [
    'timestamp' => 'When',
    'type' => 'Type',
    'message' => 'Message',
  ])]
  #[CLI\DefaultTableFields(fields: ['timestamp', 'type', 'message'])]
  public function dbSlow(array $options = ['limit' => 10]): RowsOfFields {
    $rows = [];
    if (!$this->moduleHandler->moduleExists('dblog')) {
      $this->logger()->warning(dt('dblog is not enabled; cannot read recent watchdog. Consider enabling slow-log at the database level instead.'));
      return new RowsOfFields($rows);
    }

    if (!$this->database->schema()->tableExists('watchdog')) {
      $this->logger()->warning(dt('watchdog table missing.'));
      return new RowsOfFields($rows);
    }

    $limit = max(1, (int) $options['limit']);
    $query = $this->database->select('watchdog', 'w')
      ->fields('w', ['timestamp', 'type', 'message', 'variables'])
      ->condition('severity', 5, '<=')
      ->orderBy('timestamp', 'DESC')
      ->range(0, $limit * 5);

    $candidates = $query->execute()->fetchAll();
    foreach ($candidates as $row) {
      $msg = $row->message ?? '';
      if (!preg_match('/(slow|timeout|query|sql|long.running)/i', $msg)) {
        continue;
      }
      $rows[] = [
        'timestamp' => date('Y-m-d H:i', (int) $row->timestamp),
        'type' => $row->type,
        'message' => mb_substr(strip_tags($msg), 0, 120),
      ];
      if (count($rows) >= $limit) {
        break;
      }
    }

    if (empty($rows)) {
      $this->logger()->notice(dt('No slow-query patterns matched in recent watchdog entries.'));
    }

    return new RowsOfFields($rows);
  }

  /**
   * Build the rows used by perf:cache-status and perf:audit.
   *
   * @return array<int, array{check: string, status: string, detail: string}>
   *   Findings.
   */
  protected function collectCacheChecks(): array {
    $perf = $this->configFactory->get('system.performance');
    $rows = [];

    $rows[] = [
      'check' => 'Module: page_cache',
      'status' => $this->boolStatus($this->moduleHandler->moduleExists('page_cache')),
      'detail' => 'Anonymous internal page cache module.',
    ];
    $rows[] = [
      'check' => 'Module: dynamic_page_cache',
      'status' => $this->boolStatus($this->moduleHandler->moduleExists('dynamic_page_cache')),
      'detail' => 'Caches authenticated requests minus auto-placeholders.',
    ];
    $rows[] = [
      'check' => 'Module: big_pipe',
      'status' => $this->boolStatus($this->moduleHandler->moduleExists('big_pipe')),
      'detail' => 'Streams personalised placeholders after the cacheable shell.',
    ];

    $page_max = (int) $perf->get('cache.page.max_age');
    $rows[] = [
      'check' => 'Page max-age',
      'status' => $page_max > 0 ? 'OK' : 'WARN',
      'detail' => $page_max > 0
        ? sprintf('%d seconds', $page_max)
        : 'Anonymous page cache effectively disabled (max_age = 0).',
    ];

    return $rows;
  }

  /**
   * Check that Twig debug is off in production.
   */
  protected function checkTwigDebug(): array {
    // Twig debug lives in services.yml (not config). We can introspect via
    // the compiled twig service.
    try {
      /** @var \Twig\Environment $twig */
      // The compiled twig service is runtime-only and not portably injectable
      // into a standalone Drush command; the call is guarded by try/catch.
      // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
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
   * Check BigPipe status more verbosely.
   */
  protected function checkBigPipe(): array {
    $on = $this->moduleHandler->moduleExists('big_pipe');
    return [
      'check' => 'BigPipe enabled',
      'status' => $on ? 'OK' : 'WARN',
      'detail' => $on
        ? 'Authenticated traffic will stream placeholders.'
        : 'Consider enabling big_pipe for personalised UI.',
    ];
  }

  /**
   * Render a boolean as OK / FAIL.
   */
  protected function boolStatus(bool $value): string {
    return $value ? 'OK' : 'FAIL';
  }

}
