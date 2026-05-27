<?php

namespace Drupal\advanced_image_style_warmer\Commands;

use Drupal\advanced_image_style_warmer\Registry;
use Drupal\advanced_image_style_warmer\Warmer;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;
use Drush\Drush;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Parallelised CLI backfill engine.
 *
 * Master command computes integer FID ranges from MIN(fid)/MAX(fid) and spawns
 * isolated `drush ...:worker` subprocesses for each chunk. Each worker exits
 * when its chunk is done, returning all its RAM to the OS — this is the only
 * reliable way to backfill millions of images without memory bloat.
 */
class BackfillCommands extends DrushCommands {

  /**
   * Machine-readable stats line prefix written by workers (parsed by master).
   */
  public const WORKER_STATS_PREFIX = 'AISW_WORKER_STATS';

  public function __construct(
    protected Connection $database,
    protected Warmer $warmer,
    protected Registry $registry,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Master backfill orchestrator. Spawns parallel workers.
   *
   * @command advanced-image-style-warmer:backfill
   * @aliases aisw:backfill
   * @option styles Comma-separated list of style machine names. Defaults to all configured Immediate + Queue styles.
   * @option concurrency Number of parallel worker processes.
   * @option chunk-size Number of FIDs handled per worker.
   * @option min-fid Lower bound (inclusive) — overrides auto-discovery.
   * @option max-fid Upper bound (inclusive) — overrides auto-discovery.
   * @option progress-interval Seconds between heartbeat messages while workers run (0 = off).
   * @usage drush aisw:backfill --concurrency=8 --chunk-size=5000
   */
  public function backfill(array $options = [
    'styles' => NULL,
    'concurrency' => 4,
    'chunk-size' => 5000,
    'min-fid' => NULL,
    'max-fid' => NULL,
    'progress-interval' => 15,
  ]): int {
    $styles = $this->resolveStyles($options['styles']);
    if (!$styles) {
      $this->logger()->error('No styles configured or specified. Pass --styles=name1,name2 or configure styles in the admin UI.');
      return self::EXIT_FAILURE;
    }

    [$min, $max] = $this->resolveBounds($options['min-fid'], $options['max-fid']);
    if ($min === NULL) {
      $this->logger()->notice('No image files found in {file_managed}.');
      return self::EXIT_SUCCESS;
    }

    $chunkSize = max(1, (int) $options['chunk-size']);
    $concurrency = max(1, (int) $options['concurrency']);
    $progressInterval = max(0, (int) $options['progress-interval']);
    $stylesArg = implode(',', $styles);
    $totalChunks = (int) ceil(($max - $min + 1) / $chunkSize);
    $masterStartedAt = microtime(TRUE);

    $sampleCmd = $this->buildWorkerCommand((string) $min, (string) min($min + $chunkSize - 1, $max), $stylesArg);
    $this->logBackfill(sprintf(
      'Backfilling styles [%s] over FIDs %d-%d (%d chunks × ~%d FIDs, concurrency=%d).',
      $stylesArg, $min, $max, $totalChunks, $chunkSize, $concurrency,
    ));
    $this->logBackfill('Worker command: ' . $this->formatArgv($sampleCmd));
    if ($progressInterval > 0) {
      $this->logBackfill(sprintf('Heartbeat every %d seconds (disable with --progress-interval=0).', $progressInterval));
    }

    $cursor = $min;
    $running = [];
    $completed = 0;
    $failed = 0;
    $lastHeartbeat = microtime(TRUE);
    $nextChunk = 1;
    $totals = $this->emptyBackfillTotals();

    $spawn = function (int $start, int $end) use ($stylesArg): array {
      $cmd = $this->buildWorkerCommand((string) $start, (string) $end, $stylesArg);
      $proc = new Process($cmd);
      $proc->setTimeout(NULL);
      if (defined('DRUPAL_ROOT')) {
        $proc->setWorkingDirectory(DRUPAL_ROOT);
      }
      $proc->start();
      return ['proc' => $proc, 'range' => [$start, $end], 'started' => microtime(TRUE), 'index' => 0];
    };

    while ($cursor <= $max || $running) {
      while (count($running) < $concurrency && $cursor <= $max) {
        $end = min($cursor + $chunkSize - 1, $max);
        $slot = $spawn($cursor, $end);
        $slot['index'] = $nextChunk++;
        $running[] = $slot;
        $this->logBackfill(sprintf(
          '[spawn %d/%d] Worker for FIDs %d-%d started.',
          $slot['index'], $totalChunks, $cursor, $end,
        ));
        $cursor = $end + 1;
      }
      // Non-blocking poll.
      foreach ($running as $i => $slot) {
        /** @var Process $proc */
        $proc = $slot['proc'];
        if (!$proc->isRunning()) {
          [$s, $e] = $slot['range'];
          $elapsed = microtime(TRUE) - $slot['started'];
          if ($proc->isSuccessful()) {
            $completed++;
            $output = trim($proc->getOutput());
            $chunkStats = $this->parseWorkerStatsFromOutput($output);
            if ($chunkStats) {
              $this->accumulateBackfillTotals($totals, $chunkStats);
            }
            $this->logBackfill(sprintf(
              '[done %d/%d] FIDs %d-%d in %s — %s',
              $completed + $failed, $totalChunks, $s, $e, $this->formatDuration((int) round($elapsed)),
              $chunkStats ? $this->formatChunkStatsLine($chunkStats) : ($output !== '' ? $output : 'no stats'),
            ));
            $this->logBackfill('[running total] ' . $this->formatCumulativeTotalsLine($totals));
          }
          else {
            $failed++;
            $this->logger()->error(sprintf(
              '[fail %d/%d] FIDs %d-%d after %s (exit %s): %s',
              $completed + $failed, $totalChunks, $s, $e, $this->formatDuration((int) round($elapsed)),
              (string) $proc->getExitCode(),
              trim($proc->getErrorOutput() ?: $proc->getOutput()),
            ));
          }
          unset($running[$i]);
          $lastHeartbeat = microtime(TRUE);
        }
      }
      $running = array_values($running);
      if ($running) {
        $now = microtime(TRUE);
        if ($progressInterval > 0 && ($now - $lastHeartbeat) >= $progressInterval) {
          $parts = [];
          foreach ($running as $slot) {
            [$s, $e] = $slot['range'];
            $parts[] = sprintf('FIDs %d-%d (%s)', $s, $e, $this->formatDuration((int) round($now - $slot['started'])));
          }
          $this->logBackfill(sprintf(
            '[heartbeat] %d/%d chunks done, %d failed; %d worker(s) active: %s. Elapsed %s. %s',
            $completed, $totalChunks, $failed, count($running), implode('; ', $parts),
            $this->formatDuration((int) round($now - $masterStartedAt)),
            $this->formatCumulativeTotalsLine($totals),
          ));
          $lastHeartbeat = $now;
        }
        usleep(100_000);
      }
    }

    $this->logBackfill(sprintf(
      'Backfill finished in %s: %d chunks ok, %d failed (of %d).',
      $this->formatDuration((int) round(microtime(TRUE) - $masterStartedAt)), $completed, $failed, $totalChunks,
    ));
    $this->logBackfill('[final total] ' . $this->formatCumulativeTotalsLine($totals));
    return $failed === 0 ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
  }

  /**
   * Isolated worker — processes one FID range and exits.
   *
   * @command advanced-image-style-warmer:worker
   * @aliases aisw:worker
   * @hidden
   * @param int $startFid Inclusive lower FID bound.
   * @param int $endFid Inclusive upper FID bound.
   * @param string $styles Comma-separated style machine names.
   */
  public function worker(int $startFid, int $endFid, string $styles): int {
    $styleNames = array_values(array_filter(array_map('trim', explode(',', $styles))));
    if (!$styleNames) {
      $this->logger()->error('No styles passed to worker.');
      return self::EXIT_FAILURE;
    }

    $base = $this->database->select('file_managed', 'f')
      ->condition('fid', $startFid, '>=')
      ->condition('fid', $endFid, '<=')
      ->condition('status', 1)
      ->condition('filemime', 'image/%', 'LIKE');
    $totalInRange = (int) $base->countQuery()->execute()->fetchField();

    $this->logBackfill(sprintf(
      'Worker %d-%d: %d image file(s) in range; warming style(s) [%s].',
      $startFid, $endFid, $totalInRange, $styles,
    ));

    $rows = $this->database->select('file_managed', 'f')
      ->fields('f', ['fid', 'uri', 'filemime'])
      ->condition('fid', $startFid, '>=')
      ->condition('fid', $endFid, '<=')
      ->condition('status', 1)
      ->condition('filemime', 'image/%', 'LIKE')
      ->execute();
    $warmedFiles = 0;
    $attempted = 0;
    $failedFiles = 0;
    $derivatives = 0;
    $scanned = 0;
    $skippedWarm = 0;
    $workerStartedAt = microtime(TRUE);
    $logEvery = max(50, (int) ceil(max(1, $totalInRange) / 20));

    foreach ($rows as $row) {
      $scanned++;
      $fid = (int) $row->fid;
      $pending = $this->registry->pendingStylesForFile($fid, $styleNames);
      if (!$pending) {
        $skippedWarm++;
        if ($totalInRange > 0 && $scanned % $logEvery === 0) {
          $this->emitWorkerProgress($startFid, $endFid, $scanned, $totalInRange, $skippedWarm, $attempted, $warmedFiles, $failedFiles, $derivatives, $workerStartedAt);
        }
        continue;
      }
      $attempted++;
      $created = $this->warmer->generateDerivatives($row->uri, $pending, $fid, TRUE);
      $derivatives += $created;
      if ($created > 0) {
        $warmedFiles++;
      }
      else {
        $failedFiles++;
      }
      if ($attempted % $logEvery === 0 || $scanned % $logEvery === 0) {
        $this->emitWorkerProgress($startFid, $endFid, $scanned, $totalInRange, $skippedWarm, $attempted, $warmedFiles, $failedFiles, $derivatives, $workerStartedAt);
      }
    }

    $stats = [
      'scanned' => $scanned,
      'skipped_warm' => $skippedWarm,
      'attempted' => $attempted,
      'warmed_files' => $warmedFiles,
      'failed_files' => $failedFiles,
      'derivatives' => $derivatives,
      'files_in_range' => $totalInRange,
    ];
    $human = sprintf(
      'Worker %d-%d: %s',
      $startFid,
      $endFid,
      $this->formatChunkStatsLine($stats),
    );
    $machine = $this->formatWorkerStatsLine($stats);
    $this->logBackfill($human);
    // Parent backfill parses AISW_WORKER_STATS from subprocess stdout.
    $this->output()->writeln($machine);
    $this->output()->writeln($human);
    return self::EXIT_SUCCESS;
  }

  /**
   * Reports registry status counts.
   *
   * @command advanced-image-style-warmer:status
   * @aliases aisw:status
   * @field-labels
   *   style: Style
   *   warmed: Warmed (registry)
   *   pending: Pending (registry)
   *   queued: In queue flag (registry)
   * @default-fields style,warmed,pending,queued
   */
  public function status(): \Consolidation\OutputFormatters\StructuredData\RowsOfFields {
    $query = $this->database->select(Registry::TABLE, 'r')
      ->fields('r', ['style_name']);
    $query->addExpression('SUM(CASE WHEN r.warmed = 1 THEN 1 ELSE 0 END)', 'warmed');
    $query->addExpression('SUM(CASE WHEN r.warmed = 0 THEN 1 ELSE 0 END)', 'pending');
    $query->addExpression('SUM(CASE WHEN r.warmed = 0 AND r.in_queue = 1 THEN 1 ELSE 0 END)', 'queued');
    $query->groupBy('style_name');
    $query->orderBy('style_name');
    $out = [];
    foreach ($query->execute() as $r) {
      $out[] = [
        'style' => $r->style_name,
        'warmed' => (int) $r->warmed,
        'pending' => (int) $r->pending,
        'queued' => (int) $r->queued,
      ];
    }
    return new \Consolidation\OutputFormatters\StructuredData\RowsOfFields($out);
  }

  /**
   * Lists the most recently registry-marked warm files for one image style.
   *
   * @command advanced-image-style-warmer:latest
   * @aliases aisw:latest
   * @option style Image style machine name (required).
   * @option limit Maximum number of rows (default 10).
   * @field-labels
   *   fid: FID
   *   warmed_at: Warmed at
   *   source_uri: Source URI
   *   derivative_uri: Derivative URI
   * @default-fields fid,warmed_at,source_uri,derivative_uri
   * @usage drush aisw:latest --style=large_row_article_thumbnail --limit=20
   */
  public function latest(array $options = ['style' => NULL, 'limit' => 10]): ?\Consolidation\OutputFormatters\StructuredData\RowsOfFields {
    $styleName = is_string($options['style'] ?? NULL) ? trim($options['style']) : '';
    if ($styleName === '') {
      $this->logger()->error('Pass --style=machine_name (e.g. --style=thumbnail).');
      return NULL;
    }
    $limit = max(1, min(500, (int) $options['limit']));

    /** @var \Drupal\image\ImageStyleInterface|null $imageStyle */
    $imageStyle = $this->entityTypeManager->getStorage('image_style')->load($styleName);
    if (!$imageStyle) {
      $this->logger()->error('Image style @style does not exist.', ['@style' => $styleName]);
      return NULL;
    }

    $query = $this->database->select(Registry::TABLE, 'r');
    $query->innerJoin('file_managed', 'f', 'f.fid = r.fid');
    $query->fields('r', ['fid', 'timestamp'])
      ->fields('f', ['uri'])
      ->condition('r.style_name', $styleName)
      ->condition('r.warmed', 1)
      ->condition('f.status', 1)
      ->orderBy('r.timestamp', 'DESC')
      ->range(0, $limit);

    $out = [];
    foreach ($query->execute() as $row) {
      $uri = $row->uri;
      $out[] = [
        'fid' => (int) $row->fid,
        'warmed_at' => $this->formatTimestamp((int) $row->timestamp),
        'source_uri' => $uri,
        'derivative_uri' => $imageStyle->buildUri($uri),
      ];
    }

    if (!$out) {
      $this->logger()->warning('No warmed registry rows for style @style.', ['@style' => $styleName]);
    }

    return new \Consolidation\OutputFormatters\StructuredData\RowsOfFields($out);
  }

  /**
   * Formats a Unix timestamp for CLI tables.
   */
  protected function formatTimestamp(int $timestamp): string {
    if ($timestamp <= 0) {
      return '';
    }
    return date('Y-m-d H:i:s', $timestamp);
  }

  /**
   * Resolves which styles to operate on.
   */
  protected function resolveStyles(?string $optStyles): array {
    if ($optStyles) {
      return array_values(array_filter(array_map('trim', explode(',', $optStyles))));
    }
    $buckets = $this->warmer->getConfiguredStyles();
    return array_values(array_unique(array_merge($buckets['immediate'], $buckets['queue'])));
  }

  /**
   * Resolves [min, max] FID bounds, honouring overrides.
   *
   * @return array{0: ?int, 1: ?int}
   */
  protected function resolveBounds($minOpt, $maxOpt): array {
    $q = $this->database->select('file_managed', 'f');
    $q->addExpression('MIN(fid)', 'min_fid');
    $q->addExpression('MAX(fid)', 'max_fid');
    $q->condition('status', 1);
    $q->condition('filemime', 'image/%', 'LIKE');
    $row = $q->execute()->fetchAssoc();
    $min = $row['min_fid'] !== NULL ? (int) $row['min_fid'] : NULL;
    $max = $row['max_fid'] !== NULL ? (int) $row['max_fid'] : NULL;
    if ($minOpt !== NULL && $minOpt !== '') {
      $min = max((int) $minOpt, $min ?? (int) $minOpt);
    }
    if ($maxOpt !== NULL && $maxOpt !== '') {
      $max = min((int) $maxOpt, $max ?? (int) $maxOpt);
    }
    return [$min, $max];
  }

  /**
   * Builds the argv array for a worker subprocess (Drush launcher + command).
   *
   * @return string[]
   */
  protected function buildWorkerCommand(string $startFid, string $endFid, string $stylesArg): array {
    $command = array_merge(
      $this->resolveDrushArgvPrefix(),
      [
        'advanced-image-style-warmer:worker',
        $startFid,
        $endFid,
        $stylesArg,
      ],
      $this->drushGlobalOptionsForSubprocess(),
    );
    return $command;
  }

  /**
   * Drush launcher prefix: either [php, drush.php] or [path/to/drush wrapper].
   *
   * @return string[]
   */
  protected function resolveDrushArgvPrefix(): array {
    $php = (new PhpExecutableFinder())->find() ?: 'php';
    foreach ($this->drushLauncherCandidates() as $path) {
      if (!is_file($path) || !is_readable($path)) {
        continue;
      }
      if ($this->pathIsPhpScript($path)) {
        return [$php, $path];
      }
      if (is_executable($path)) {
        return [$path];
      }
    }
    return ['drush'];
  }

  /**
   * Candidate paths for the same Drush used by the master process.
   *
   * @return string[]
   */
  protected function drushLauncherCandidates(): array {
    $candidates = [];
    $env = getenv('DRUSH_COMMAND');
    if (is_string($env) && $env !== '') {
      $candidates[] = $env;
    }
    if (defined('DRUSH_COMMAND') && DRUSH_COMMAND !== '') {
      $candidates[] = DRUSH_COMMAND;
    }
    if (class_exists(Drush::class)) {
      try {
        $script = Drush::config()->get('runtime.drush-script');
        if (is_string($script) && $script !== '') {
          $candidates[] = $script;
        }
      }
      catch (\Throwable) {
        // Drush not fully bootstrapped.
      }
    }
    if (defined('DRUPAL_ROOT')) {
      $candidates[] = DRUPAL_ROOT . '/vendor/bin/drush';
      $candidates[] = dirname(DRUPAL_ROOT) . '/vendor/bin/drush';
    }
    $cwd = getcwd();
    if ($cwd) {
      $candidates[] = $cwd . '/vendor/bin/drush';
    }
    return array_values(array_unique(array_filter($candidates)));
  }

  /**
   * Whether a launcher file is a PHP script (must be run via php, not as binary).
   */
  protected function pathIsPhpScript(string $path): bool {
    if (str_ends_with(strtolower($path), '.php')) {
      return TRUE;
    }
    $head = @file_get_contents($path, FALSE, NULL, 0, 256);
    return is_string($head) && str_contains($head, '<?php');
  }

  /**
   * Passes site URI (and similar globals) from the master Drush process to workers.
   *
   * @return string[]
   */
  /**
   * Progress messages visible at default Drush verbosity (not hidden as "info").
   */
  protected function logBackfill(string $message): void {
    $this->logger()->notice($message);
  }

  /**
   * @return array{scanned: int, skipped_warm: int, warmed_files: int, derivatives: int, files_in_range: int}
   */
  protected function emptyBackfillTotals(): array {
    return [
      'scanned' => 0,
      'skipped_warm' => 0,
      'attempted' => 0,
      'warmed_files' => 0,
      'failed_files' => 0,
      'derivatives' => 0,
      'files_in_range' => 0,
    ];
  }

  /**
   * @param array{scanned: int, skipped_warm: int, warmed_files: int, derivatives: int, files_in_range: int} $totals
   * @param array{scanned: int, skipped_warm: int, warmed_files: int, derivatives: int, files_in_range: int} $chunk
   */
  protected function accumulateBackfillTotals(array &$totals, array $chunk): void {
    foreach ($totals as $key => $_) {
      $totals[$key] += (int) ($chunk[$key] ?? 0);
    }
  }

  /**
   * @return array{scanned: int, skipped_warm: int, warmed_files: int, derivatives: int, files_in_range: int}|null
   */
  protected function parseWorkerStatsFromOutput(string $output): ?array {
    if (preg_match(
      '/' . self::WORKER_STATS_PREFIX . ' scanned=(\d+) skipped_warm=(\d+) attempted=(\d+) warmed_files=(\d+) failed_files=(\d+) derivatives=(\d+) files_in_range=(\d+)/',
      $output,
      $m,
    )) {
      return [
        'scanned' => (int) $m[1],
        'skipped_warm' => (int) $m[2],
        'attempted' => (int) $m[3],
        'warmed_files' => (int) $m[4],
        'failed_files' => (int) $m[5],
        'derivatives' => (int) $m[6],
        'files_in_range' => (int) $m[7],
      ];
    }
    // Legacy stats line (before attempted/failed split).
    if (preg_match(
      '/' . self::WORKER_STATS_PREFIX . ' scanned=(\d+) skipped_warm=(\d+) warmed_files=(\d+) derivatives=(\d+) files_in_range=(\d+)/',
      $output,
      $m,
    )) {
      $attempted = (int) $m[3];
      $derivatives = (int) $m[4];
      return [
        'scanned' => (int) $m[1],
        'skipped_warm' => (int) $m[2],
        'attempted' => $attempted,
        'warmed_files' => $attempted,
        'failed_files' => 0,
        'derivatives' => $derivatives,
        'files_in_range' => (int) $m[5],
      ];
    }
    return NULL;
  }

  /**
   * @param array{scanned: int, skipped_warm: int, warmed_files: int, derivatives: int, files_in_range: int} $stats
   */
  protected function formatWorkerStatsLine(array $stats): string {
    return sprintf(
      '%s scanned=%d skipped_warm=%d attempted=%d warmed_files=%d failed_files=%d derivatives=%d files_in_range=%d',
      self::WORKER_STATS_PREFIX,
      $stats['scanned'],
      $stats['skipped_warm'],
      $stats['attempted'],
      $stats['warmed_files'],
      $stats['failed_files'],
      $stats['derivatives'],
      $stats['files_in_range'],
    );
  }

  /**
   * @param array{scanned: int, skipped_warm: int, warmed_files: int, derivatives: int, files_in_range: int} $stats
   */
  protected function formatChunkStatsLine(array $stats): string {
    return sprintf(
      '%d image(s) in range, %d scanned — %d skipped (registry warm), %d attempted (pending), %d succeeded, %d failed (still pending), %d derivative(s) in registry',
      $stats['files_in_range'],
      $stats['scanned'],
      $stats['skipped_warm'],
      $stats['attempted'] ?? $stats['warmed_files'],
      $stats['warmed_files'],
      $stats['failed_files'] ?? 0,
      $stats['derivatives'],
    );
  }

  /**
   * @param array{scanned: int, skipped_warm: int, warmed_files: int, derivatives: int, files_in_range: int} $totals
   */
  protected function formatCumulativeTotalsLine(array $totals): string {
    return sprintf(
      'Totals: %d image(s) in ranges, %d scanned — %d skipped (registry warm), %d attempted, %d succeeded, %d failed, %d registry marks (derivatives)',
      $totals['files_in_range'],
      $totals['scanned'],
      $totals['skipped_warm'],
      $totals['attempted'] ?? 0,
      $totals['warmed_files'],
      $totals['failed_files'] ?? 0,
      $totals['derivatives'],
    );
  }

  protected function emitWorkerProgress(
    int $startFid,
    int $endFid,
    int $scanned,
    int $totalInRange,
    int $skippedWarm,
    int $attempted,
    int $warmedFiles,
    int $failedFiles,
    int $derivatives,
    float $workerStartedAt,
  ): void {
    $line = sprintf(
      'Worker %d-%d: progress %d/%d — %d skipped, %d attempted, %d ok, %d failed, %d derivatives (%s)',
      $startFid,
      $endFid,
      $scanned,
      $totalInRange,
      $skippedWarm,
      $attempted,
      $warmedFiles,
      $failedFiles,
      $derivatives,
      $this->formatDuration((int) round(microtime(TRUE) - $workerStartedAt)),
    );
    $this->logBackfill($line);
    $this->output()->writeln($line);
  }

  protected function formatDuration(int $seconds): string {
    if ($seconds < 60) {
      return $seconds . 's';
    }
    $minutes = intdiv($seconds, 60);
    $secs = $seconds % 60;
    if ($minutes < 60) {
      return sprintf('%dm %ds', $minutes, $secs);
    }
    $hours = intdiv($minutes, 60);
    $minutes = $minutes % 60;
    return sprintf('%dh %dm', $hours, $minutes);
  }

  protected function formatArgv(array $argv): string {
    return implode(' ', array_map(static function (string $part): string {
      return str_contains($part, ' ') ? escapeshellarg($part) : $part;
    }, $argv));
  }

  protected function drushGlobalOptionsForSubprocess(): array {
    $flags = [];
    if ($this->input() && $this->input()->hasOption('uri')) {
      $uri = $this->input()->getOption('uri');
      if (is_string($uri) && $uri !== '') {
        $flags[] = '--uri=' . $uri;
      }
    }
    return $flags;
  }

}
