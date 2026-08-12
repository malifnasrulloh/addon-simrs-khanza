#!/usr/bin/env php
<?php
/**
 * satusehat_reset_states.php — inspect and reset terminal sync states.
 *
 * Terminal states (invalid_code / failed_rule / privacy_error / merge_failed)
 * are permanent skips: records in those states are never retried. After a
 * root-cause fix (payload, mapping, config) they must be reset so the
 * pipeline re-attempts them.
 *
 * Usage:
 *   php satusehat_reset_states.php                          # summary of all states
 *   php satusehat_reset_states.php --table=medicationdispense_state --status=invalid_code --dry-run
 *   php satusehat_reset_states.php --table=medicationdispense_state --status=invalid_code --i-am-sure
 *
 * Order matters: reset ONLY AFTER the corresponding root-cause fix is live,
 * or you recreate thousands of doomed attempts against the API.
 *
 * @author malifnasrulloh
 */

declare(strict_types=1);

define('BASE_DIR', __DIR__);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$options = getopt('', ['table::', 'status::', 'dry-run', 'i-am-sure', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
Usage: php satusehat_reset_states.php [--table=NAME] [--status=STATUS] [--dry-run|--i-am-sure]

  (no args)          print a per-table status summary
  --table=NAME       target one state table (e.g. medicationdispense_state)
  --status=STATUS    only rows with this status (invalid_code|failed_rule|privacy_error|merge_failed|...)
  --dry-run          (default) show what would be deleted, change nothing
  --i-am-sure        actually delete the matching rows

HELP;
    exit(0);
}

$dbPath = BASE_DIR . '/logs/satusehat_state.sqlite';
if (!is_file($dbPath)) {
    fwrite(STDERR, "state DB not found at {$dbPath}\n");
    exit(1);
}
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$table = $options['table'] ?? null;
$status = $options['status'] ?? null;
$sure = isset($options['i-am-sure']);

$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '%_state' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

if ($table === null) {
    // Summary mode
    echo str_pad('table', 42) . str_pad('total', 10) . "by status\n";
    foreach ($tables as $t) {
        $total = (int) $db->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        $parts = [];
        foreach ($db->query("SELECT status, COUNT(*) c FROM {$t} GROUP BY status ORDER BY c DESC")->fetchAll() as $r) {
            $parts[] = "{$r['status']}:{$r['c']}";
        }
        echo str_pad($t, 42) . str_pad((string) $total, 10) . implode(' ', $parts) . "\n";
    }
    echo "\nReset with: php satusehat_reset_states.php --table=NAME --status=STATUS --i-am-sure\n";
    exit(0);
}

if (!in_array($table, $tables, true)) {
    fwrite(STDERR, "unknown state table: {$table}\n");
    exit(1);
}

$where = $status !== null ? ' WHERE status = :s' : '';
$sql = "SELECT COUNT(*) FROM {$table}{$where}";
$stmt = $db->prepare($sql);
if ($status !== null) {
    $stmt->execute(['s' => $status]);
} else {
    $stmt->execute();
}
$count = (int) $stmt->fetchColumn();
echo "{$table}" . ($status !== null ? " status='{$status}'" : ' (all rows)') . ": {$count} row(s)\n";

if ($count === 0) {
    exit(0);
}
if (!$sure) {
    echo "DRY-RUN — nothing changed. Re-run with --i-am-sure to delete.\n";
    exit(0);
}

$del = $db->prepare("DELETE FROM {$table}{$where}");
if ($status !== null) {
    $del->execute(['s' => $status]);
} else {
    $del->execute();
}
echo "Deleted {$del->rowCount()} row(s). Next sync run will re-attempt them.\n";
exit(0);
