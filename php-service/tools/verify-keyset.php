#!/usr/bin/env php
<?php
/**
 * verify-keyset.php — parity harness for keyset pagination.
 *
 * For every keyset-capable fetchPending* method it collects the FULL row
 * multiset (keyed by the method's keyset tuple columns) twice on the same
 * window: once with keyset pagination OFF (legacy OFFSET) and once ON.
 * Any difference means the keyset cursor skips or duplicates rows — FAIL.
 *
 * The TTV single-pass method (fetchPendingObservationsAll) is verified as its
 * own parity pair (keyset ON vs OFF), which proves the keyset cursor does not
 * drop rows for the rewritten query.
 *
 * Usage:
 *   php tools/verify-keyset.php
 *   php tools/verify-keyset.php --from 2023-01-01 --to 2026-12-31 --batch 137
 *   php tools/verify-keyset.php --service encounter
 *
 * Options:
 *   --from / --to   YYYY-MM-DD window (default: 24 months ending yesterday)
 *   --batch <n>     Batch size (odd sizes exercise more page boundaries)
 *   --service <s>   Substring filter on method name (default: all)
 *   --json          Machine-readable output
 *   --help          This message
 */

declare(strict_types=1);

define('BASE_DIR', dirname(__DIR__));

$options = getopt('', ['help', 'from:', 'to:', 'batch:', 'service:', 'json']);

if (isset($options['help'])) {
    echo <<<HELP
    Usage:
      php tools/verify-keyset.php [--from YYYY-MM-DD] [--to YYYY-MM-DD] [--batch N] [--service <name>] [--json]

    Compares legacy-OFFSET vs keyset-paginated row sets for every keyset-capable
    fetchPending method. Exit code 0 = parity OK.
    HELP;
    exit(0);
}

require_once BASE_DIR . '/lib/Logger.php';
require_once BASE_DIR . '/lib/satusehat/Config.php';
require_once BASE_DIR . '/lib/satusehat/SatuSehatClient.php';
require_once BASE_DIR . '/lib/satusehat/Database.php';
require_once BASE_DIR . '/lib/satusehat/BatchCursor.php';
require_once BASE_DIR . '/lib/satusehat/Supervisor.php';

$batch   = max(1, (int) ($options['batch'] ?? 137));
$service = $options['service'] ?? '';
$jsonOut = isset($options['json']);
$from    = $options['from'] ?? date('Y-m-d', strtotime('-730 days'));
$to      = $options['to'] ?? date('Y-m-d', strtotime('-1 day'));

/**
 * Collect the full multiset of keyset tuples a method returns in a given mode.
 *
 * @return array tupleString => count
 */
function collectRows(string $method, bool $keysetOn, array $rowKeys, string $from, string $to, int $batch): array
{
    $overrides = [
        'DB_NAME'                  => 'sik_temps',
        'SATUSEHAT_ORG_ID'         => 'bench-org',
        'SATUSEHAT_CLIENT_ID'      => 'bench-client',
        'SATUSEHAT_SECRET_KEY'     => 'bench-secret',
        'SATUSEHAT_DATE_FROM'      => $from,
        'SATUSEHAT_DATE_TO'        => $to,
        'SATUSEHAT_BATCH_SIZE'     => (string) $batch,
        'SATUSEHAT_DELAY_MS'       => '0',
        'SATUSEHAT_KEYSET_PAGINATION' => $keysetOn ? 'true' : 'false',
        'LOG_LEVEL'                => 'ERROR',
    ];

    $config = new SatuSehatConfig(BASE_DIR . '/.env', $overrides);
    $log = new Logger($config->logDir, 'satusehat_verify', 'ERROR');
    $db = new SatuSehatDatabase($config, $log, new SatuSehatClient($config, $log));

    $ref = new \ReflectionMethod($db, $method);
    $required = $ref->getNumberOfRequiredParameters();
    $params = $required >= 2 ? [$from, $to] : [];

    $rows = [];
    $cursor = new SatuSehatBatchCursor($db, $method, $params, $batch, null, $method);
    foreach ($cursor->batches() as $page) {
        foreach ($page as $row) {
            $parts = [];
            foreach ($rowKeys as $k) {
                $parts[] = (string) ($row[$k] ?? "\x00NULL");
            }
            $key = implode('|', $parts);
            $rows[$key] = ($rows[$key] ?? 0) + 1;
        }
        $cursor->tick();
    }
    $db->close();
    return $rows;
}

$probeConfig = new SatuSehatConfig(BASE_DIR . '/.env', [
    'SATUSEHAT_KEYSET_PAGINATION' => 'true',
    'SATUSEHAT_ORG_ID'            => 'bench-org',
    'SATUSEHAT_CLIENT_ID'         => 'bench-client',
    'SATUSEHAT_SECRET_KEY'        => 'bench-secret',
]);
$probeLog = new Logger($probeConfig->logDir, 'satusehat_verify', 'ERROR');
$probeDb = new SatuSehatDatabase($probeConfig, $probeLog, new SatuSehatClient($probeConfig, $probeLog));

$results = [];
$failed = 0;
$tested = 0;

foreach (get_class_methods($probeDb) as $method) {
    if (!str_starts_with($method, 'fetchPending')) {
        continue;
    }
    if (!$probeDb->usesKeyset($method)) {
        continue;
    }
    if ($service !== '' && stripos($method, $service) === false) {
        continue;
    }

    $rowKeys = $probeDb->keysetRowKeys($method);
    $tested++;

    $legacy = collectRows($method, false, $rowKeys, $from, $to, $batch);
    $keyset = collectRows($method, true, $rowKeys, $from, $to, $batch);

    $missing = array_diff_key($legacy, $keyset);
    $extra = array_diff_key($keyset, $legacy);
    $countMismatch = [];
    foreach ($legacy as $k => $c1) {
        if (($keyset[$k] ?? 0) !== $c1) {
            $countMismatch[$k] = [$c1, $keyset[$k] ?? 0];
        }
    }

    $ok = count($missing) === 0 && count($extra) === 0 && count($countMismatch) === 0;
    if (!$ok) {
        $failed++;
    }

    $results[$method] = [
        'ok' => $ok,
        'legacyRows' => array_sum($legacy),
        'keysetRows' => array_sum($keyset),
        'missing' => count($missing),
        'extra' => count($extra),
        'countMismatch' => count($countMismatch),
        'samples' => [
            'missing' => array_slice(array_map(fn($k) => trim($k, '"'), array_keys($missing)), 0, 3),
            'extra' => array_slice(array_map(fn($k) => trim($k, '"'), array_keys($extra)), 0, 3),
        ],
    ];
}

$probeDb->close();

if ($jsonOut) {
    echo json_encode(['tested' => $tested, 'failed' => $failed, 'methods' => $results], JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    printf("%-58s %10s %10s %7s %7s %s\n", 'METHOD', 'LEGACY', 'KEYSET', 'MISS', 'EXTRA', 'RESULT');
    printf("%s\n", str_repeat('-', 108));
    foreach ($results as $m => $r) {
        printf("%-58s %10d %10d %7d %7d %s\n", $m, $r['legacyRows'], $r['keysetRows'], $r['missing'], $r['extra'], $r['ok'] ? 'PASS' : 'FAIL');
    }
    printf("%s\n", str_repeat('-', 108));
    printf("Tested: %d | Failed: %d (window %s..%s, batch %d)\n", $tested, $failed, $from, $to, $batch);
}

exit($failed > 0 ? 1 : 0);