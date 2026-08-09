<?php

declare(strict_types=1);

/**
 * sync-lib.php — keep the panel's adopted SATUSEHAT library in lockstep with
 * the source of truth (php-service/lib/satusehat + php-service/lib/Logger.php).
 *
 * The panel deliberately FORKS no library code: every file listed in the
 * manifest below must be byte-identical to its upstream sibling, or the
 * pipeline fails loudly. Panel-local code (PayloadAdapter, CredentialLocator,
 * controllers, JS) lives only in the panel and is NOT part of the manifest.
 *
 * Usage:
 *   php scripts/sync-lib.php --verify    # fail (exit 1) on any drift
 *   php scripts/sync-lib.php --dry-run   # report what would change
 *   php scripts/sync-lib.php --apply     # copy upstream files, update manifest
 *
 * Options:
 *   --source=<path>   upstream root (default: repo root's php-service)
 *
 * Drift state is recorded in storage/lib-manifest.json (gitignored).
 */

$root = dirname(__DIR__);
$defaultSource = dirname(__DIR__, 2) . '/php-service';

$sourceRoot = $defaultSource;
$mode = 'verify';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--source=')) {
        $sourceRoot = rtrim(substr($arg, strlen('--source=')), '/');
    } elseif ($arg === '--verify' || $arg === '--dry-run' || $arg === '--apply') {
        $mode = ltrim($arg, '-');
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(2);
    }
}

$manifestFile = $root . '/storage/lib-manifest.json';

/**
 * [source, target] pairs — relative to each project root.
 */
$manifest = [
    ['lib/satusehat/PayloadBuilder.php',       'src/Util/PayloadBuilder.php'],
    ['lib/satusehat/AllergyDictionary.php',    'src/Util/AllergyDictionary.php'],
    ['lib/satusehat/ObservationTTVDictionary.php', 'src/Util/ObservationTTVDictionary.php'],
    ['lib/satusehat/EpisodeOfCareType.php',    'src/Util/EpisodeOfCareType.php'],
    ['lib/satusehat/SatuSehatClient.php',      'src/Util/SatuSehatClient.php'],
    ['lib/satusehat/Config.php',               'src/Util/SatuSehatConfig.php'],
    ['lib/satusehat/DateTimeUtil.php',         'src/Util/DateTimeUtil.php'],
    ['lib/satusehat/NumberUtil.php',          'src/Util/NumberUtil.php'],
    ['lib/Logger.php',                         'src/Util/Logger.php'],
];

function shaOf(string $path): string
{
    if (!is_file($path)) {
        return '<missing>';
    }
    return hash_file('sha256', $path);
}

$drift = [];
$changes = [];
$ok = true;

foreach ($manifest as [$sourceRel, $targetRel]) {
    $src = $sourceRoot . '/' . $sourceRel;
    $dst = $root . '/' . $targetRel;

    $srcSha = shaOf($src);
    $dstSha = shaOf($dst);

    if ($srcSha === '<missing>') {
        fwrite(STDERR, "[ERROR] upstream missing: {$src}\n");
        $ok = false;
        continue;
    }

    if ($srcSha !== $dstSha) {
        $drift[] = ['source' => $sourceRel, 'target' => $targetRel, 'src_sha' => $srcSha, 'dst_sha' => $dstSha];
    }
}

$hasMissing = false;
foreach ($drift as $d) {
    if ($d['dst_sha'] === '<missing>') {
        $hasMissing = true;
        break;
    }
}

if ($mode === 'verify' && !empty($drift)) {
    fwrite(STDERR, "DRIFT DETECTED — panel library is out of sync with php-service:\n");
    foreach ($drift as $d) {
        $suffix = $d['dst_sha'] === '<missing>' ? '  (TARGET MISSING — run --apply)' : '';
        fwrite(STDERR, sprintf("  %-45s  %s%s\n", $d['target'], $d['source'], $suffix));
    }
    fwrite(STDERR, "Fix with: php scripts/sync-lib.php --apply\n");
    exit(1);
}

if (empty($drift)) {
    fwrite(STDOUT, "OK — all " . count($manifest) . " shared library files are in sync.\n");
    if ($mode === 'verify') {
        exit(0);
    }
}

if ($mode === 'dry-run') {
    foreach ($drift as $d) {
        fwrite(STDOUT, sprintf("would update  %s  <-  %s\n", $d['target'], $d['source']));
    }
    exit(0);
}

if ($mode === 'apply' && !empty($drift)) {
    $state = [];
    foreach ($drift as $d) {
        $src = $sourceRoot . '/' . $d['source'];
        $dst = $root . '/' . $d['target'];
        if (!copy($src, $dst)) {
            fwrite(STDERR, "[ERROR] copy failed: {$src} -> {$dst}\n");
            $ok = false;
            continue;
        }
        fwrite(STDOUT, sprintf("updated     %s  <-  %s\n", $d['target'], $d['source']));
    }

    // Recompute full manifest state (all files, not just drifted ones).
    $state = [];
    foreach ($manifest as [$sourceRel, $targetRel]) {
        $state[$targetRel] = [
            'source'   => $sourceRel,
            'src_sha'  => shaOf($sourceRoot . '/' . $sourceRel),
            'dst_sha'  => shaOf($root . '/' . $targetRel),
        ];
    }
    $state['_synced_at'] = date('c');
    $state['_source_root'] = $sourceRoot;

    if (!is_dir(dirname($manifestFile))) {
        mkdir(dirname($manifestFile), 0755, true);
    }
    if (file_put_contents($manifestFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        fwrite(STDERR, "[ERROR] could not write {$manifestFile}\n");
        $ok = false;
    }

    if (!$ok) {
        exit(1);
    }
    fwrite(STDOUT, "Manifest updated: {$manifestFile}\n");
    exit(0);
}

exit($ok ? 0 : 1);
