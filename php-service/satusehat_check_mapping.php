#!/usr/bin/env php
<?php
/**
 * satusehat_check_mapping.php — data-quality audit of the SATUSEHAT mapping
 * tables (read-only). Catches the classes of mapping bugs seen in production
 * logs: empty code/system pairs (RuleNumber 10480), non-UCUM unit codes
 * ('ml' → 10050/10357), route values that are plain words in a coded system
 * ('Topical' in ATC → 10056).
 *
 * Usage:
 *   php satusehat_check_mapping.php                 # summary + top offenders
 *   php satusehat_check_mapping.php --table=obat    # only one mapping table
 *   php satusehat_check_mapping.php --limit=50      # max rows per section
 *
 * @author malifnasrulloh
 */

declare(strict_types=1);

define('BASE_DIR', __DIR__);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$options = getopt('', ['table::', 'limit::', 'help']);
if (isset($options['help'])) {
    echo "Usage: php satusehat_check_mapping.php [--table=obat|lab|radiologi|vaksin] [--limit=N]\n";
    exit(0);
}
$onlyTable = $options['table'] ?? null;
$limit = (int) ($options['limit'] ?? 30);

require_once BASE_DIR . '/lib/Logger.php';
require_once BASE_DIR . '/lib/satusehat/Config.php';

try {
    $config = new SatuSehatConfig(BASE_DIR . '/.env');
} catch (\Throwable $e) {
    fwrite(STDERR, "[FATAL] Config error: {$e->getMessage()}\n");
    exit(1);
}

$pdo = new PDO(
    "mysql:host={$config->dbHost};port={$config->dbPort};dbname={$config->dbName};charset=utf8mb4",
    $config->dbUser,
    $config->dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Common SIMRS unit spellings that are NOT valid UCUM (case matters).
$badUnits = ['ml', 'dl', 'l', 'ul', 'mg', 'g', 'mmhg', 'mm/hg', '%', 'IU', 'u/l', 'iu/ml', 'sel/ul'];
$badUnitsMap = [
    'ml' => 'mL', 'dl' => 'dL', 'l' => 'L', 'ul' => 'uL',
    'mg' => 'mg (ok in UCUM — verify)', 'g' => 'g (ok in UCUM — verify)',
    'mmhg' => 'mm[Hg]', 'mm/hg' => 'mm[Hg]', '%' => '% (ok in UCUM — verify)',
    'iu' => 'IU (ok in UCUM — verify)',
];

$sections = [];

$tables = [
    'obat' => 'satu_sehat_mapping_obat',
    'lab' => 'satu_sehat_mapping_lab',
    'radiologi' => 'satu_sehat_mapping_radiologi',
    'vaksin' => 'satu_sehat_mapping_vaksin',
];

foreach ($tables as $key => $table) {
    if ($onlyTable !== null && $key !== $onlyTable) {
        continue;
    }
    try {
        $exists = $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    } catch (\Throwable $e) {
        echo "== {$table}: TABLE MISSING/SKIPPED ({$e->getMessage()})\n";
        continue;
    }
    echo "== {$table}: {$exists} rows\n";

    $checks = [
        "empty code or system" => "SELECT * FROM {$table} WHERE code IS NULL OR code = '' OR system IS NULL OR system = '' LIMIT {$limit}",
        "unit-like values in code (plain words / non-UCUM)" => "SELECT * FROM {$table} WHERE code REGEXP '^[a-zA-Z ]+$' LIMIT {$limit}",
    ];
    foreach ($checks as $label => $sql) {
        try {
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            echo "  [skip] {$label}: {$e->getMessage()}\n";
            continue;
        }
        if (empty($rows)) {
            continue;
        }
        echo "  -- {$label}: " . count($rows) . " row(s) shown\n";
        foreach ($rows as $r) {
            $id = implode('|', array_filter([
                $r['kode_brng'] ?? $r['id_template'] ?? $r['kd_jenis_prw'] ?? null,
                $r['code'] ?? '',
                $r['system'] ?? '',
                $r['display'] ?? '',
            ]));
            echo "     {$id}\n";
        }
    }

    // Unit spellings that are valid strings but wrong UCUM case (route/unit columns).
    foreach (['route_code', 'denominator_code', 'sampel_code'] as $col) {
        try {
            $rows = $pdo->query("SELECT * FROM {$table} WHERE {$col} IN ('" . implode("','", array_map('strtolower', $badUnits)) . "') LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            continue; // column not present
        }
        if (!empty($rows)) {
            echo "  -- suspect {$col} values (lowercase units): " . count($rows) . " row(s)\n";
            foreach ($rows as $r) {
                $v = $r[$col];
                $sugg = $badUnitsMap[strtolower((string) $v)] ?? '?';
                echo "     {$col}='{$v}' (suggest: {$sugg}) kode_brng=" . ($r['kode_brng'] ?? $r['id_template'] ?? '?') . "\n";
            }
        }
    }
}

echo "\nDone. Fix the listed rows in the mapping tables, then reset affected states:\n";
echo "  php satusehat_reset_states.php --table=medicationdispense_state --status=invalid_code --i-am-sure\n";
