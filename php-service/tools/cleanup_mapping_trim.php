#!/usr/bin/env php
<?php
/**
 * cleanup_mapping_trim.php — idempotent TRIM cleanup for SATUSEHAT mapping
 * tables. Trailing/leading whitespace in mapped codes (e.g. LOINC '20570-8 ')
 * made the platform reject payloads (rule 10010/10012).
 *
 * Discovers string columns per table from information_schema and runs
 * UPDATE t SET col = TRIM(col) WHERE col <> TRIM(col) for each — safe to
 * re-run, preview by default.
 *
 * Usage:
 *   php tools/cleanup_mapping_trim.php              # preview (counts only)
 *   php tools/cleanup_mapping_trim.php --apply      # apply
 *   php tools/cleanup_mapping_trim.php --table satu_sehat_mapping_lab --apply
 *
 * Options:
 *   --apply        Actually UPDATE (default: preview)
 *   --table <name> Restrict to one table (default: all mapping tables)
 *   --db-name <n>  Database name override (default: from .env)
 *   --help         This message
 */

declare(strict_types=1);

define('BASE_DIR', dirname(__DIR__));

$options = getopt('', ['help', 'apply', 'table:', 'db-name:']);

if (isset($options['help'])) {
    echo <<<HELP
    Usage:
      php tools/cleanup_mapping_trim.php [--apply] [--table satu_sehat_mapping_lab] [--db-name sik]

    Trims whitespace off all string columns of the SATUSEHAT mapping tables.
    Default is a preview (only reports affected row counts).
    HELP;
    exit(0);
}

require_once BASE_DIR . '/lib/Logger.php';
require_once BASE_DIR . '/lib/satusehat/Config.php';

$apply = isset($options['apply']);
$tableFilter = $options['table'] ?? '';
$overrides = [
    'SATUSEHAT_ORG_ID'     => 'cleanup-tool',
    'SATUSEHAT_CLIENT_ID'  => 'cleanup-tool',
    'SATUSEHAT_SECRET_KEY' => 'cleanup-tool',
];
if (isset($options['db-name'])) {
    $overrides['DB_NAME'] = $options['db-name'];
}

try {
    $config = new SatuSehatConfig(BASE_DIR . '/.env', $overrides);
} catch (\RuntimeException $e) {
    fwrite(STDERR, "[FATAL] Configuration error: {$e->getMessage()}\n");
    exit(1);
}

$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config->dbHost, $config->dbPort, $config->dbName);
$pdo = new PDO($dsn, $config->dbUser, $config->dbPass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tables = [
    'satu_sehat_mapping_lab',
    'satu_sehat_mapping_obat',
    'satu_sehat_mapping_radiologi',
    'satu_sehat_mapping_vaksin',
    'satu_sehat_mapping_lokasi_ralan',
    'satu_sehat_mapping_lokasi_ranap',
];

$totalAffected = 0;
foreach ($tables as $table) {
    if ($tableFilter !== '' && $table !== $tableFilter) {
        continue;
    }

    // Discover string columns (skip the integer/template PKs).
    $stmt = $pdo->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t
           AND DATA_TYPE IN ('varchar', 'char', 'text')
         ORDER BY ORDINAL_POSITION"
    );
    $stmt->execute(['db' => $config->dbName, 't' => $table]);
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if ($columns === []) {
        echo "{$table}: (no string columns or table missing)\n";
        continue;
    }

    $affected = 0;
    foreach ($columns as $col) {
        $preview = $pdo->query(
            "SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` <> TRIM(`{$col}`) AND `{$col}` IS NOT NULL"
        )->fetchColumn();
        if ((int) $preview > 0) {
            $affected += (int) $preview;
            if ($apply) {
                $pdo->exec("UPDATE `{$table}` SET `{$col}` = TRIM(`{$col}`) WHERE `{$col}` <> TRIM(`{$col}`) AND `{$col}` IS NOT NULL");
            }
            echo sprintf("  %-38s %-32s affected: %s%s\n", $table, $col, $preview, $apply ? ' (applied)' : '');
        }
    }
    $totalAffected += $affected;
}

echo "─────────────────────────────────────────────────\n";
echo "TOTAL rows needing trim: {$totalAffected}" . ($apply ? ' (all applied)' : ' (run with --apply to fix)') . "\n";
exit(0);