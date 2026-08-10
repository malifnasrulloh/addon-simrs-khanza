#!/usr/bin/env php
<?php
/**
 * Diagnose "You don't have permission to edit resource" denials.
 *
 * Reads satusehat_permission_denied.json, GETs one denied resource per type
 * and prints the org-scoped references side by side with the configured
 * Organization ID, so the org-mismatch / wrong-environment / foreign-resource
 * causes are distinguishable at a glance.
 *
 * Usage:
 *   php satusehat_check_permission_denied.php [--type=DiagnosticReport]
 *
 * @author malifnasrulloh
 */

declare(strict_types=1);

define('BASE_DIR', __DIR__);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$options = getopt('', ['type::']);
$onlyType = $options['type'] ?? null;

require_once BASE_DIR . '/lib/Logger.php';
require_once BASE_DIR . '/lib/satusehat/Config.php';
require_once BASE_DIR . '/lib/satusehat/SatuSehatClient.php';

try {
    $config = new SatuSehatConfig(BASE_DIR . '/.env');
} catch (\Throwable $e) {
    fwrite(STDERR, "[FATAL] Config error: {$e->getMessage()}\n");
    exit(1);
}

$log = new Logger($config->logDir, 'permission_check', 'INFO', true);
$client = new SatuSehatClient($config, $log);

$denied = json_decode((string) @file_get_contents(BASE_DIR . '/satusehat_permission_denied.json'), true);
if (!is_array($denied) || empty($denied)) {
    echo "No denied endpoints in satusehat_permission_denied.json — nothing to diagnose.\n";
    exit(0);
}

echo "Configured Organization ID : {$config->orgId}\n";
echo "Environment / API base     : {$config->baseUrl}\n";
echo "Denied endpoints in cache  : " . count($denied) . "\n\n";

$checked = [];
foreach ($denied as $endpoint => $ts) {
    $parts = explode('/', trim((string) $endpoint, '/'));
    $type = $parts[0] ?? '?';
    if ($onlyType !== null && $type !== $onlyType) {
        continue;
    }
    if (isset($checked[$type])) {
        continue;
    }
    $checked[$type] = true;
    $path = '/' . $parts[0] . '/' . $parts[1];

    echo "== {$path}  (denied at " . date('Y-m-d H:i:s', (int) $ts) . ") ==\n";
    $result = $client->get($path);
    if (!$result['success']) {
        echo "   GET failed: {$result['message']} — resource mungkin bukan milik environment/config ini.\n\n";
        continue;
    }
    $res = $result['data'];
    if (!is_array($res) || empty($res)) {
        echo "   (empty resource)\n\n";
        continue;
    }
    foreach (['serviceProvider', 'custodian', 'manufacturer', 'performer', 'author', 'organization', 'requester'] as $key) {
        if (isset($res[$key])) {
            echo "   {$key}: " . json_encode($res[$key], JSON_UNESCAPED_SLASHES) . "\n";
        }
    }
    if (isset($res['meta'])) {
        echo "   meta: " . json_encode($res['meta'], JSON_UNESCAPED_SLASHES) . "\n";
    }
    $owner = json_encode($res, JSON_UNESCAPED_SLASHES);
    if (str_contains($owner, 'Organization/' . $config->orgId)) {
        echo "   => MENGGANDUNG Organization/{$config->orgId}: resource terlihat milik org config — cek client/scope.\n";
    } else {
        echo "   => TIDAK ada ref Organization/{$config->orgId}: resource kemungkinan milik org lain / environment lain.\n";
    }
    echo "\n";
}