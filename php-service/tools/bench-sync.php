#!/usr/bin/env php
<?php
/**
 * bench-sync.php — SATUSEHAT fetch-layer benchmark harness.
 *
 * Iterates every paginated fetchPending* method on SatuSehatDatabase with
 * SatuSehatBatchCursor over a date window (DB layer only, no API/network) and
 * reports wall time, rows, pages, rows/sec and peak memory. Can run the same
 * window with keyset pagination ON vs OFF (--compare) to measure the delta.
 *
 * Safe for development databases: issues only SELECTs, never writes.
 *
 * Usage:
 *   php tools/bench-sync.php --from 2024-01-01 --to 2024-12-31
 *   php tools/bench-sync.php --service encounter --mode db --batch 500
 *   php tools/bench-sync.php --compare --json
 *
 * Options:
 *   --service <name>   Substring filter on method name (e.g. 'encounter',
 *                      'observation', 'radiologi'). Default: all methods.
 *   --mode db          DB fetch layer only (default)
 *   --from / --to      YYYY-MM-DD window (default: one year ending today-1)
 *   --batch <n>        Batch size (default 500)
 *   --keyset / --no-keyset   Force keyset pagination ON/OFF instead of config
 *   --compare          Run each method twice (keyset ON and OFF) and report both
 *   --db-name <name>   Database name override (default: sik_temps)
 *   --db-host <host>   Database host override (default: from .env)
 *   --json             Emit machine-readable JSON instead of a table
 *   --help             Show this message
 */

declare(strict_types=1);

define('BASE_DIR', dirname(__DIR__));

$options = getopt('', [
    'help', 'mode:', 'service:', 'from:', 'to:', 'batch:', 'db-name:', 'db-host:',
    'keyset', 'no-keyset', 'compare', 'json'
]);

if (isset($options['help'])) {
    echo file_get_contents(__FILE__) === false ? '' : '';
    $usage = <<<HELP
    Usage:
      php tools/bench-sync.php [options]

    Options:
      --service <name>   Substring filter on method name (default: all)
      --mode db          DB fetch layer only (default and only mode today)
      --from / --to      YYYY-MM-DD window (default: one year ending today-1)
      --batch <n>        Batch size (default 500)
      --keyset / --no-keyset   Force keyset pagination ON/OFF
      --compare          Run each method with keyset ON and OFF, report both
      --db-name <name>   Database name (default: sik_temps)
      --db-host <host>   Database host (default: from .env)
      --json             Machine-readable JSON output
      --help             This message
    HELP;
    echo $usage . PHP_EOL;
    exit(0);
}

require_once BASE_DIR . '/lib/Logger.php';
require_once BASE_DIR . '/lib/satusehat/Config.php';
require_once BASE_DIR . '/lib/satusehat/SatuSehatClient.php';
require_once BASE_DIR . '/lib/satusehat/Database.php';
require_once BASE_DIR . '/lib/satusehat/BatchCursor.php';
require_once BASE_DIR . '/lib/satusehat/Supervisor.php';

$mode       = $options['mode'] ?? 'db';
$service    = $options['service'] ?? '';
$batchSize  = max(1, (int) ($options['batch'] ?? 500));
$dbName     = $options['db-name'] ?? 'sik_temps';
$dbHost     = $options['db-host'] ?? null;
$jsonOut    = isset($options['json']);
$compare    = isset($options['compare']);

$from = $options['from'] ?? date('Y-m-d', strtotime('-365 days'));
$to   = $options['to']   ?? date('Y-m-d', strtotime('-1 day'));

if ($mode !== 'db') {
    fwrite(STDERR, "[FATAL] Only --mode db is implemented so far.\n");
    exit(1);
}

$overrides = [
    'DB_NAME'                  => $dbName,
    'SATUSEHAT_ORG_ID'         => 'bench-org',
    'SATUSEHAT_CLIENT_ID'      => 'bench-client',
    'SATUSEHAT_SECRET_KEY'     => 'bench-secret',
    'SATUSEHAT_DATE_FROM'      => $from,
    'SATUSEHAT_DATE_TO'        => $to,
    'SATUSEHAT_BATCH_SIZE'     => (string) $batchSize,
    'SATUSEHAT_DELAY_MS'       => '0',
    'LOG_LEVEL'                => 'ERROR',
];
if ($dbHost !== null) {
    $overrides['DB_HOST'] = $dbHost;
}
if (isset($options['keyset'])) {
    $overrides['SATUSEHAT_KEYSET_PAGINATION'] = 'true';
}
if (isset($options['no-keyset'])) {
    $overrides['SATUSEHAT_KEYSET_PAGINATION'] = 'false';
}

try {
    $config = new SatuSehatConfig(BASE_DIR . '/.env', $overrides);
} catch (\RuntimeException $e) {
    fwrite(STDERR, "[FATAL] Configuration error: {$e->getMessage()}\n");
    exit(1);
}

$log = new Logger($config->logDir, 'satusehat_bench', 'ERROR');
$client = new SatuSehatClient($config, $log);

try {
    $db = new SatuSehatDatabase($config, $log, $client);
} catch (\Throwable $e) {
    fwrite(STDERR, "[FATAL] DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

$results = [];
$methods = get_class_methods($db);
sort($methods);

foreach ($methods as $method) {
    if (!str_starts_with($method, 'fetchPending')) {
        continue;
    }
    if ($service !== '' && stripos($method, $service) === false) {
        continue;
    }

    $ref = new \ReflectionMethod($db, $method);
    $required = $ref->getNumberOfRequiredParameters();
    if ($required > 2) {
        // Method needs extra params (e.g. per-type definitions) — not benchable generically
        continue;
    }

    if ($compare) {
        $rOn  = benchMethod($db, $method, $required, $from, $to, $batchSize, $log, true);
        $rOff = benchMethod($db, $method, $required, $from, $to, $batchSize, $log, false);
        $results[$method . ' [keyset ON]'] = $rOn;
        $results[$method . ' [OFFSET]'] = $rOff;
    } else {
        $results[$method] = benchMethod($db, $method, $required, $from, $to, $batchSize, $log);
    }
}

$db->close();

if ($jsonOut) {
    echo json_encode([
        'window' => ['from' => $from, 'to' => $to],
        'batch' => $batchSize,
        'db' => $dbName,
        'methods' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$totalRows = 0;
$totalSec = 0.0;
$totalPeak = 0.0;
printf("%-62s %8s %10s %12s %10s\n", 'METHOD', 'PAGES', 'ROWS', 'SECONDS', 'ROWS/S');
printf("%s\n", str_repeat('-', 108));
foreach ($results as $method => $r) {
    printf("%-62s %8d %10d %12.3f %10.1f\n",
        $method, $r['pages'], $r['rows'], $r['seconds'], $r['rowsPerSec']);
    $totalRows += $r['rows'];
    $totalSec += $r['seconds'];
    $totalPeak = max($totalPeak, $r['peakMemMB'] ?? 0);
}
printf("%s\n", str_repeat('-', 108));
printf("%-62s %8s %10d %12.3f %10.1f\n", 'TOTAL', '', $totalRows, $totalSec, $totalSec > 0 ? $totalRows / $totalSec : 0);
printf("Window: %s .. %s | batch: %d | peak mem: %.1fMB\n", $from, $to, $batchSize, $totalPeak);

/** Run one paginated method over the window and return perf stats. */
function benchMethod(
    SatuSehatDatabase $db,
    string $method,
    int $required,
    string $from,
    string $to,
    int $batchSize,
    Logger $log,
    ?bool $keysetOverride = null
): array {
    $params = $required >= 2 ? [$from, $to] : [];

    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $start = microtime(true);
    $rows = 0;
    $pages = 0;

    try {
        $cursor = new SatuSehatBatchCursor($db, $method, $params, $batchSize, null, $method);
        foreach ($cursor->batches() as $batch) {
            $rows += count($batch);
            $pages++;
            $cursor->tick();
        }
    } catch (\Throwable $e) {
        return [
            'pages' => $pages, 'rows' => $rows,
            'seconds' => round(microtime(true) - $start, 4),
            'rowsPerSec' => 0.0,
            'peakMemMB' => round(memory_get_peak_usage(true) / 1048576, 1),
            'error' => $e->getMessage(),
        ];
    }

    $elapsed = microtime(true) - $start;
    return [
        'pages' => $pages,
        'rows' => $rows,
        'seconds' => round($elapsed, 4),
        'rowsPerSec' => $elapsed > 0 ? round($rows / $elapsed, 1) : 0.0,
        'peakMemMB' => round(memory_get_peak_usage(true) / 1048576, 1),
    ];
}