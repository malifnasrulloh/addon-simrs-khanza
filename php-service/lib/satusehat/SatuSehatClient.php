<?php

/**
 * SatuSehatClient - BPJS Satu Sehat API client.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class SatuSehatClient
{
    private string $clientId;
    private string $secretKey;
    private string $authUrl;
    private string $baseUrl;
    private int    $tokenTimeout;
    private int    $delayMs;
    private bool   $verbosePayload;
    private Logger $log;
    private string $tokenCacheFile;
    private string $permissionCacheFile;
    /**
     * Source timezone used when a payload string has no explicit offset.
     * Configurable via SatuSehatConfig::$timezone (defaults to Asia/Jakarta).
     */
    private \DateTimeZone $sourceTimezone;

    private const CONNECT_TIMEOUT = 10;
    private const REQUEST_TIMEOUT = 30;

    public function __construct(SatuSehatConfig $config, Logger $log)
    {
        $this->clientId        = $config->clientId;
        $this->secretKey       = $config->secretKey;
        $this->authUrl         = $config->authUrl;
        $this->baseUrl         = $config->baseUrl;
        $this->tokenTimeout    = $config->tokenTimeout;
        $this->delayMs         = $config->delayMs;
        $this->verbosePayload  = $config->verbosePayload;
        $this->log             = $log;

        $this->tokenCacheFile     = $config->logDir . '/satusehat_token.json';
        $this->permissionCacheFile = $config->logDir . '/satusehat_permission_denied.json';
        $this->sourceTimezone     = new \DateTimeZone($config->timezone ?: 'Asia/Jakarta');
    }

    /**
     * Retrieve or refresh OAuth2 Access Token.
     */
    public function getToken(): ?string
    {
        // 1. First check: read-only check without lock for high performance
        if (file_exists($this->tokenCacheFile)) {
            $raw = @file_get_contents($this->tokenCacheFile);
            if ($raw) {
                $cache = json_decode($raw, true);
                if ($cache && isset($cache['token']) && isset($cache['expires_at'])) {
                    // Buffer of 60 seconds to avoid edge-case expiry
                    if (time() < ($cache['expires_at'] - 60)) {
                        return $cache['token'];
                    }
                }
            }
        }

        // 2. Lock file to prevent concurrent token requests
        $lockFile = $this->tokenCacheFile . '.lock';
        $lockFp = @fopen($lockFile, 'c');
        if (!$lockFp) {
            // Fallback to non-locking behavior if lock file cannot be opened
            return $this->requestNewToken();
        }

        // Block until lock is acquired
        flock($lockFp, LOCK_EX);

        try {
            // 3. Double-check cache inside the lock
            if (file_exists($this->tokenCacheFile)) {
                $raw = @file_get_contents($this->tokenCacheFile);
                if ($raw) {
                    $cache = json_decode($raw, true);
                    if ($cache && isset($cache['token']) && isset($cache['expires_at'])) {
                        if (time() < ($cache['expires_at'] - 60)) {
                            return $cache['token'];
                        }
                    }
                }
            }

            // 4. Request new token under the lock
            return $this->requestNewToken();
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    /**
     * Request a new OAuth token from Satu Sehat server.
     */
    private function requestNewToken(): ?string
    {
        $this->log->info("[AUTH] Token expired or not found. Requesting new token...");
        $url = $this->authUrl . '/accesstoken?grant_type=client_credentials';
        $payload = http_build_query([
            'client_id'     => $this->clientId,
            'client_secret' => $this->secretKey
        ]);

        $maxAttempts = 3;
        $baseDelaySeconds = 1.5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/x-www-form-urlencoded'
                ]
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            curl_close($ch);

            // Determine if token request failed due to transient issues
            $isTransientError = false;
            $errorReason = '';

            if ($error) {
                $isTransientError = true;
                $errorReason = "cURL error: {$error}";
            } elseif ($httpCode === 429) {
                $isTransientError = true;
                $errorReason = "HTTP 429 Too Many Requests (Rate Limited)";
            } elseif ($httpCode >= 500 && $httpCode <= 599) {
                $isTransientError = true;
                $errorReason = "HTTP {$httpCode} Server Error";
            } elseif ($response === false || $response === '') {
                $isTransientError = true;
                $errorReason = "Empty response";
            }

            if ($isTransientError && $attempt < $maxAttempts) {
                $jitter = rand(100, 1000) / 1000;
                $delaySeconds = (pow(2, $attempt - 1) * $baseDelaySeconds) + $jitter;
                
                $this->log->warning("[AUTH] Attempt {$attempt} to fetch token failed ({$errorReason}). Retrying in " . round($delaySeconds, 2) . "s...");
                usleep((int)($delaySeconds * 1000000));
                continue;
            }

            if ($error) {
                $this->log->error("[AUTH] cURL error fetching token: {$error} after {$attempt} attempts");
                return null;
            }

            $data = json_decode($response, true);
            if ($httpCode !== 200 || empty($data['access_token'])) {
                $this->log->error("[AUTH] Failed to get token. HTTP {$httpCode}: " . substr($response, 0, 300));
                return null;
            }

            $token = $data['access_token'];
            $this->log->info("[AUTH] Token retrieved successfully (Attempts: {$attempt}).");

            // Save to cache
            $cacheData = [
                'token'      => $token,
                'expires_at' => time() + $this->tokenTimeout
            ];
            file_put_contents($this->tokenCacheFile, json_encode($cacheData));

            return $token;
        }

        return null;
    }

    /**
     * Send GET request.
     */
    public function get(string $endpoint): array
    {
        return $this->request('GET', $endpoint, null);
    }

    /**
     * Send POST request.
     */
    public function post(string $endpoint, array $payload): array
    {
        return $this->request('POST', $endpoint, $payload);
    }

    /**
     * Send PUT request.
     */
    public function put(string $endpoint, array $payload): array
    {
        return $this->request('PUT', $endpoint, $payload);
    }

    /**
     * 3-layer update flow: PUT → PATCH(batch) → PATCH(per-op), with
     * permanent permission-denied caching.
     *
     * Layer 0 — Permission cache: if this resource was previously rejected
     *           with "You don't have permission to edit resource", skip it.
     * Layer 1 — PUT: full resource replacement (most permissive).
     * Layer 2 — PATCH batch: all operations in one call.
     * Layer 3 — PATCH per-op: each operation individually.
     *
     * If $payload is provided, Layer 1 (PUT) is attempted first. This is useful
     * when the FHIR server rejects PATCH on certain resources but accepts PUT.
     *
     * When ALL layers fail with a permission error, the resource endpoint is
     * cached to disk so it is never retried on future runs.
     *
     * @param array  $operations JSON Patch operations, e.g. [['op'=>'replace','path'=>'/status','value'=>'finished']]
     * @param array|null $putPayload Full FHIR resource for PUT fallback, or null to skip PUT layer
     */
    public function patch(string $endpoint, array $operations, ?array $putPayload = null): array
    {
        // ── Layer 0: Permission cache — skip if previously denied ─────
        if ($this->isPermissionDenied($endpoint)) {
            $this->log->info("[UPDATE] {$endpoint}: Skipped (cached permission denied)");
            return [
                'success'          => true,
                'code'             => 200,
                'message'          => 'Permission denied (cached)',
                'data'             => [],
                'permission_skip'  => true,
            ];
        }

        // ── Layer 1: PUT (full resource replacement) ───────────────────
        if ($putPayload !== null) {
            $putResult = $this->request('PUT', $endpoint, $putPayload);
            if ($putResult['success']) {
                $this->log->info("[UPDATE] {$endpoint}: PUT succeeded (Layer 1/3)");
                return $putResult;
            }
            // Permission denied on PUT → cache immediately, no point trying PATCH
            if (self::isPermissionMessage(self::extractErrorMsg($putResult))) {
                $this->log->warning("[UPDATE] {$endpoint}: PUT permission denied — caching as permanent");
                $this->markPermissionDenied($endpoint);
                return [
                    'success'          => true,
                    'code'             => 200,
                    'message'          => 'Permission denied (cached)',
                    'data'             => [],
                    'permission_skip'  => true,
                ];
            }
            $this->log->warning(
                "[UPDATE] {$endpoint}: PUT failed (HTTP {$putResult['code']}), " .
                "falling back to PATCH (Layer 2/3)"
            );
        }

        // ── Layer 2: PATCH batch (all ops at once) ─────────────────────
        $result = $this->request('PATCH', $endpoint, $operations, 'application/json-patch+json');

        if ($result['success']) {
            return $result;
        }

        // Permission denied on batch PATCH → cache immediately
        if (self::isPermissionMessage(self::extractErrorMsg($result))) {
            $this->log->warning("[UPDATE] {$endpoint}: Batch PATCH permission denied — caching as permanent");
            $this->markPermissionDenied($endpoint);
            return [
                'success'          => true,
                'code'             => 200,
                'message'          => 'Permission denied (cached)',
                'data'             => [],
                'permission_skip'  => true,
            ];
        }

        // Single-op PATCH failed too → no point decomposing further
        if (count($operations) <= 1) {
            return $result;
        }

        // ── Layer 3: PATCH per-op (one at a time) ──────────────────────
        $opCount = count($operations);
        $this->log->warning(
            "[UPDATE] {$endpoint}: Batch PATCH failed (HTTP {$result['code']}), " .
            "falling back to per-op PATCH ({$opCount} ops — Layer 3/3)"
        );

        $successCount = 0;
        $failCount    = 0;
        $failedOps    = [];
        $lastResult   = $result;

        foreach ($operations as $i => $op) {
            $opPath = $op['path'] ?? '?';
            $opDesc = "op=" . ($op['op'] ?? '?') . " path={$opPath}";

            $this->log->info("[UPDATE] Per-op " . ($i + 1) . "/{$opCount}: {$opDesc}");
            $opResult = $this->request('PATCH', $endpoint, [$op], 'application/json-patch+json');
            $lastResult = $opResult;

            if ($opResult['success']) {
                $successCount++;
                $this->log->info("[UPDATE]  ✓ Op {$i}: {$opDesc}");
            } else {
                $errMsg = self::extractErrorMsg($opResult);
                $failCount++;
                $failedOps[] = [
                    'index' => $i,
                    'op'    => $op,
                    'error' => $errMsg,
                ];
                $this->log->warning("[UPDATE]  ✗ Op {$i}: {$opDesc} failed → {$errMsg}");
            }
        }

        // ── Compose the final result ────────────────────────────────────
        if ($failCount === 0) {
            return [
                'success'  => true,
                'code'     => 200,
                'message'  => "Per-op PATCH: all {$successCount} ops succeeded",
                'data'     => $lastResult['data'] ?? [],
                'response' => $lastResult['response'] ?? '',
            ];
        }

        $errorMsg = "Per-op PATCH: {$successCount} ok, {$failCount} failed";
        $this->log->error("[UPDATE] {$errorMsg}");

        // ── Permission cache: if ALL failures are permission-denied, cache it ──
        $allPermission = ($failCount > 0);
        foreach ($failedOps as $fo) {
            $this->log->error("[UPDATE]   Failed op #{$fo['index']}: {$fo['error']}");
            if (!self::isPermissionMessage($fo['error'])) {
                $allPermission = false;
            }
        }

        if ($allPermission) {
            $this->log->warning("[UPDATE] {$endpoint}: All ops failed with 'permission denied' — caching as permanent");
            $this->markPermissionDenied($endpoint);
        }

        return [
            'success'  => false,
            'code'     => 0,
            'message'  => $errorMsg,
            'data'     => [
                'issue'              => [['diagnostics' => $errorMsg]],
                'individual_results' => $failedOps,
            ],
        ];
    }

    /**
     * Extract the best human-readable error message from an API response.
     * Prefers details.text (used by SATUSEHAT), then diagnostics, then message.
     */
    private static function extractErrorMsg(array $result): string
    {
        // SATUSEHAT typically wraps the message in issue[0].details.text
        if (isset($result['data']['issue'][0]['details']['text'])) {
            return $result['data']['issue'][0]['details']['text'];
        }
        // FHIR standard diagnostics fallback
        if (isset($result['data']['issue'][0]['diagnostics'])) {
            return $result['data']['issue'][0]['diagnostics'];
        }
        // Generic message
        return $result['message'] ?? 'Unknown error';
    }

    /**
     * Check if an error message indicates a permanent permission denial.
     */
    private static function isPermissionMessage(string $msg): bool
    {
        $needles = ['permission', "don't have permission", 'do not have permission', 'forbidden', 'not authorized'];
        foreach ($needles as $needle) {
            if (stripos($msg, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Load the permission-denied cache (persistent across runs).
     * @return array<string, int> endpoint => timestamp
     */
    private function getPermissionCache(): array
    {
        if (!file_exists($this->permissionCacheFile)) {
            return [];
        }
        $raw = @file_get_contents($this->permissionCacheFile);
        if (!$raw) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Save the permission-denied cache to disk.
     */
    private function savePermissionCache(array $cache): void
    {
        file_put_contents($this->permissionCacheFile, json_encode($cache));
    }

    /**
     * Check if an endpoint is permanently permission-denied.
     */
    private function isPermissionDenied(string $endpoint): bool
    {
        $cache = $this->getPermissionCache();
        return isset($cache[$endpoint]);
    }

    /**
     * Mark an endpoint as permanently permission-denied (persisted to disk).
     */
    private function markPermissionDenied(string $endpoint): void
    {
        $cache = $this->getPermissionCache();
        $cache[$endpoint] = time();
        $this->savePermissionCache($cache);
        $this->log->warning("[PERMISSION] Cached {$endpoint} — will not retry on future runs");
    }

    /**
     * Core HTTP request method.
     */
    private function request(string $method, string $endpoint, ?array $payload, ?string $contentType = null): array
    {
        $token = $this->getToken();
        if (!$token) {
            return ['success' => false, 'code' => 401, 'message' => 'Failed to obtain access token', 'data' => []];
        }

        // Rate limit delay
        if ($this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }

        $url = $this->baseUrl . $endpoint;
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: ' . ($contentType ?? 'application/json'),
            'Accept: application/json'
        ];

        if ($payload !== null) {
            $payload = $this->convertPayloadDatesToUtc($payload);
        }

        $maxAttempts = 3;
        $baseDelaySeconds = 1.5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_HTTPHEADER     => $headers,
            ]);

            if ($payload !== null) {
                $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                if ($attempt === 1) {
                    if ($this->verbosePayload) {
                        $this->log->info("[API] {$method} {$url}");
                        $this->log->info("[API] Request body:");
                        foreach (explode("\n", $body) as $line) {
                            $this->log->info("  " . $line);
                        }
                    } else {
                        $this->log->debug("[API] {$method} {$url} | Body: " . substr($body, 0, 500));
                    }
                }
            } else {
                if ($attempt === 1) {
                    $msg = "[API] {$method} {$url}";
                    if ($this->verbosePayload) {
                        $this->log->info($msg);
                    } else {
                        $this->log->debug($msg);
                    }
                }
            }

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            curl_close($ch);

            // Determine if request failed due to transient issues
            $isTransientError = false;
            $errorReason = '';

            if ($error) {
                $isTransientError = true;
                $errorReason = "cURL error: {$error}";
            } elseif ($httpCode === 429) {
                $isTransientError = true;
                $errorReason = "HTTP 429 Too Many Requests (Rate Limited)";
            } elseif ($httpCode >= 500 && $httpCode <= 599) {
                $isTransientError = true;
                $errorReason = "HTTP {$httpCode} Server Error";
            } elseif ($response === false || $response === '') {
                $isTransientError = true;
                $errorReason = "Empty response";
            }

            if ($isTransientError && $attempt < $maxAttempts) {
                // Calculate backoff time with jitter
                $jitter = rand(100, 1000) / 1000; // 0.1 to 1.0s jitter
                $delaySeconds = (pow(2, $attempt - 1) * $baseDelaySeconds) + $jitter;
                
                $this->log->warning("[API] Attempt {$attempt} failed ({$errorReason}). Retrying in " . round($delaySeconds, 2) . "s...");
                usleep((int)($delaySeconds * 1000000));
                continue;
            }

            // Standard response processing
            if ($error) {
                $this->log->error("[API] cURL error: {$error} after {$attempt} attempts");
                return ['success' => false, 'code' => 0, 'message' => "cURL error: {$error}", 'data' => []];
            }

            if ($response === false || $response === '') {
                $this->log->error("[API] Empty or invalid response from Satu Sehat (HTTP {$httpCode}) after {$attempt} attempts");
                return ['success' => false, 'code' => $httpCode, 'message' => 'Empty or invalid response from API', 'data' => []];
            }

            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log->error("[API] Invalid JSON response (HTTP {$httpCode}) after {$attempt} attempts: " . substr($response, 0, 300));
                return ['success' => false, 'code' => $httpCode, 'message' => 'Invalid JSON response', 'data' => []];
            }

            // HTTP 2xx or 201 Created/200 OK
            $isSuccess = ($httpCode >= 200 && $httpCode < 300);
            
            if ($isSuccess) {
                $this->log->info("[API] {$method} {$endpoint} -> HTTP {$httpCode} OK (Attempts: {$attempt})");
            } else {
                $this->log->warning("[API] {$method} {$endpoint} -> HTTP {$httpCode} FAILED (Attempts: {$attempt}): " . substr($response, 0, 500));
            }

            return [
                'success'  => $isSuccess,
                'code'     => $httpCode,
                'message'  => $isSuccess ? 'Success' : 'API Error',
                'data'     => $data,
                'response' => $response
            ];
        }

        return ['success' => false, 'code' => 500, 'message' => 'API execution exhausted all attempts', 'data' => []];
    }

    /**
     * Recursively traverse the request payload and normalize date-time strings to UTC.
     *
     * Accepted inputs (any Indonesian or other timezone, e.g. Asia/Jakarta,
     * Asia/Makassar, Asia/Jayapura):
     *   - "2026-06-03 20:10:07"          (no offset → assumed source timezone)
     *   - "2026-06-03T20:10:07"          (no offset → assumed source timezone)
     *   - "2026-06-03T20:10:07+07:00"    (with offset)
     *   - "2026-06-03T20:10:07+0700"     (basic-offset form)
     *
     * Already-UTC values ("...Z" or "...+00:00") are returned untouched.
     * Strings that don't look like date-times are left alone.
     */
    private function convertPayloadDatesToUtc(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->convertPayloadDatesToUtc($value);
            } elseif (is_string($value)) {
                $converted = $this->normalizeDateTimeToUtc($value);
                if ($converted !== null) {
                    $payload[$key] = $converted;
                }
            }
        }
        return $payload;
    }

    /**
     * Convert one date-time string to UTC ISO 8601, or return null if the
     * string does not look like a date-time.
     */
    private function normalizeDateTimeToUtc(string $value): ?string
    {
        // Must contain at least YYYY-MM-DD plus HH:MM:SS or HH:MM to be a candidate.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?/', $value)) {
            return null;
        }

        // Normalize space separator to 'T' so DateTimeImmutable parses consistently.
        $candidate = str_replace(' ', 'T', $value);

        try {
            // If the string carries an explicit offset (e.g. +07:00, +0700, Z),
            // DateTimeImmutable::ATOM falls back to that offset.
            $dt = new \DateTimeImmutable($candidate);
        } catch (\Exception $e) {
            return null;
        }

        // If no offset was specified, $candidate was parsed using the default
        // timezone (whatever PHP's date.timezone is). Re-anchor to the configured
        // source timezone so a server in UTC doesn't mis-interpret a local time.
        if (preg_match('/[Zz]$|[+\-]\d{2}:?\d{2}$/', $value) === 0) {
            try {
                $dt = new \DateTimeImmutable($candidate, $this->sourceTimezone);
            } catch (\Exception $e) {
                return null;
            }
        }

        // Already UTC? Return as-is in canonical form.
        $offsetSeconds = $dt->getOffset();
        if ($offsetSeconds === 0) {
            return $dt->format('Y-m-d\TH:i:s\Z');
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
