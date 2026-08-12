#!/usr/bin/env php
<?php
/**
 * satusehat_healthcheck.php — cron-able health gate for the sync services.
 *
 * Scans today's logs (and the last N days) for the failure signatures seen
 * in production:
 *   - [FATAL] / Unhandled exception          → crashes
 *   - "Found N ... record(s) to POST/PATCH"  appearing repeatedly with no
 *     state change in between                 → infinite retry loops
 *   - "permission-denial cap reached"        → systemic permission problem
 *
 * Exit code 0 = healthy, 1 = problems found (cron can alert on it).
 *
 * Usage:
 *   php satusehat_healthcheck.php                     # today only
 *   php satusehat_healthcheck.php --days=3            # last 3 days
 *   php satusehat_healthcheck.php --verbose           # print findings
 *
 * @author malifnasrulloh
 */

declare(strict_types=1);

define('BASE_DIR', __DIR__);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$options = getopt('', ['days::', 'verbose', 'help']);
if (isset($options['help'])) {
    echo "Usage: php satusehat_healthcheck.php [--days=N] [--verbose]\n";
    exit(0);
}
$days = (int) ($options['days'] ?? 1);
$verbose = isset($options['verbose']);

$logRoot = BASE_DIR . '/logs';
if (!is_dir($logRoot)) {
    fwrite(STDERR, "log dir not found: {$logRoot}\n");
    exit(2);
}

$issues = [];
$fatalCount = 0;
$loopCount = 0;

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($logRoot, FilesystemIterator::SKIP_DOTS));
$today = date('Y-m-d');
$window = [];
for ($i = 0; $i < $days; $i++) {
    $window[] = date('Y-m-d', strtotime("-{$i} days"));
}

// Loop detection: per service+day, track consecutive runs that found the
// same record count to POST without any success in between.
$loopTracker = []; // service|day => [count, runsWithoutProgress]

foreach ($it as $f) {
    if (!$f->isFile() || !str_ends_with($f->getFilename(), '.log')) {
        continue;
    }
    $file = $f->getPathname();
    $base = basename($file);
    // Only consider the window days.
    $inWindow = false;
    foreach ($window as $d) {
        if (str_contains($base, $d)) {
            $inWindow = true;
            break;
        }
    }
    if (!$inWindow) {
        continue;
    }
    $service = basename(dirname($file));

    $lastFound = null;
    $h = fopen($file, 'r');
    $foundSinceLastProgress = null;
    $progressAfterFound = false;
    $lineNo = 0;
    while (($line = fgets($h)) !== false) {
        $lineNo++;
        if (preg_match('/\[FATAL\]|Unhandled exception/', $line)) {
            $fatalCount++;
            if ($verbose) {
                $issues[] = "FATAL {$service}/{$base}: " . trim(substr($line, 0, 160));
            }
        }
        if (preg_match('/Found (\d+) .*? record\(s\) to (POST|PATCH)/', $line, $m)) {
            $count = (int) $m[1];
            $foundSinceLastProgress = $count;
            $progressAfterFound = false;
            $lastFound = "{$service}:{$count}";
        }
        if ($foundSinceLastProgress !== null && preg_match('/✓ (Created|Updated|Recovered)/', $line)) {
            $progressAfterFound = true;
        }
        if ($foundSinceLastProgress !== null && preg_match('/No pending/', $line)) {
            $foundSinceLastProgress = null;
        }
    }
    fclose($h);

    // If the LAST "Found N" of the file had no success after it and the same
    // count appears in earlier runs of the same day, flag a possible loop.
    if ($foundSinceLastProgress !== null && !$progressAfterFound) {
        $key = $service . '|' . date('Y-m-d');
        $loopTracker[$key] = ($loopTracker[$key] ?? 0) + 1;
        if ($loopTracker[$key] >= 2) {
            $loopCount++;
            if ($verbose) {
                $issues[] = "LOOP {$service}: 'Found {$foundSinceLastProgress}' without progress across multiple runs";
            }
        }
    }
    if (preg_match('/permission-denial cap reached/', file_get_contents($file))) {
        $issues[] = "CAP {$service}/{$base}: permission-denial cap reached (systemic permission problem)";
    }
}

$problems = $fatalCount > 0 || $loopCount > 0 || count($issues) > 0;
echo "Health check (" . ($days === 1 ? 'today' : "last {$days} days") . "): "
    . "fatal={$fatalCount} loops={$loopCount}"
    . ($problems ? " → PROBLEMS FOUND" : " → OK")
    . "\n";
foreach ($issues as $i) {
    echo "  - {$i}\n";
}
exit($problems ? 1 : 0);
