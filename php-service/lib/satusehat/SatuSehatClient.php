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
    private string $orgId;
    private string $authUrl;
    private string $baseUrl;
    private int    $tokenTimeout;
    private int    $delayMs;
    private bool   $verbosePayload;
    private bool   $verifyTls;
    private Logger $log;
    private string $tokenCacheFile;
    private string $permissionCacheFile;
    /**
     * Source timezone used when a payload string has no explicit offset.
     * Configurable via SatuSehatConfig::$timezone (defaults to Asia/Jakarta).
     */
    private \DateTimeZone $sourceTimezone;

    /**
     * Optional transport override for tests/proxies: callable receiving
     * (string $url, string $method, array $headers, ?string $body) and
     * returning [string $body, int $httpCode, string $error].
     * When set, cURL is bypassed entirely.
     */
    public ?\Closure $transport = null;

    private const CONNECT_TIMEOUT = 10;
    private const REQUEST_TIMEOUT = 30;

    public function __construct(SatuSehatConfig $config, Logger $log)
    {
        $this->clientId        = $config->clientId;
        $this->secretKey       = $config->secretKey;
        $this->orgId           = $config->orgId;
        $this->authUrl         = $config->authUrl;
        $this->baseUrl         = $config->baseUrl;
        $this->tokenTimeout    = $config->tokenTimeout;
        $this->delayMs         = $config->delayMs;
        $this->verbosePayload  = $config->verbosePayload;
        $this->verifyTls       = $config->verifyTls;
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
            if ($this->transport !== null) {
                [$response, $httpCode, $error] = ($this->transport)($url, 'POST', ['Content-Type: application/x-www-form-urlencoded'], $payload);
            } else {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
                    CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                    CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
                    CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/x-www-form-urlencoded'
                    ]
                ]);

                $response = curl_exec($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error    = curl_error($ch);
                curl_close($ch);
            }

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

            // Save to cache (0600 — the file holds a live OAuth token).
            $cacheData = [
                'token'      => $token,
                'expires_at' => time() + $this->tokenTimeout,
            ];
            file_put_contents($this->tokenCacheFile, json_encode($cacheData));
            @chmod($this->tokenCacheFile, 0600);

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
     * Two-mode update flow:
     *
     * Mode A — PUT (full resource): Used when $putPayload is provided (the
     *           3-arg call from payloadToPatchOps processors). Only PUT is
     *           attempted — PATCH is NEVER used as fallback because it
     *           triggers permanent resource locks on SATUSEHAT by trying
     *           to replace org-scoped immutable fields (identifier, etc.).
     *
     * Mode B — PATCH (targeted ops): Used when $putPayload is null (the
     *           2-arg call from hand-crafted ops like Encounter, Condition).
     *           These only touch status/period fields, never org-scoped
     *           data, so PATCH is safe here.
     *
     * @param array  $operations JSON Patch operations
     * @param array|null $putPayload Full FHIR resource for PUT, or null for
     *                               hand-crafted PATCH-only mode
     */
    public function patch(string $endpoint, array $operations, ?array $putPayload = null): array
    {
        // ── Mode B: Hand-crafted PATCH (no PUT payload) ──────────────
        if ($putPayload === null) {
            return $this->request('PATCH', $endpoint, $operations, 'application/json-patch+json');
        }

        // ── Mode A: PUT-only (no PATCH fallback) ──────────────────────
        // Permission cache — skip if previously denied
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

        // Ownership pre-check: a resource created by ANOTHER fasyankes
        // (referral, external pharmacy/lab) cannot be edited — the server
        // answers "You don't have permission to edit resource". Detect that
        // locally first so the operator gets a clear Indonesian message
        // instead of a misleading generic 403 + permanent-denial cache.
        $ownership = $this->checkEditOwnership($endpoint);
        if ($ownership !== null) {
            $this->log->warning("[UPDATE] {$endpoint}: {$ownership['message']}");
            return [
                'success'          => true,
                'code'             => 200,
                'message'          => $ownership['message'],
                'data'             => [],
                'ownership_skip'   => true,
                'owner_org'        => $ownership['owner_org'],
            ];
        }

        // PUT: full resource replacement
        $result = $this->request('PUT', $endpoint, $putPayload);
        if ($result['success']) {
            $this->log->info("[UPDATE] {$endpoint}: PUT succeeded");
            return $result;
        }

        // Permission denied → cache permanently (never retry)
        if (self::isPermissionMessage(self::extractErrorMsg($result))) {
            $this->log->warning("[UPDATE] {$endpoint}: PUT permission denied — caching as permanent");
            $this->markPermissionDenied($endpoint);
            return [
                'success'          => true,
                'code'             => 200,
                'message'          => 'Permission denied (cached): "You don\'t have permission to edit resource". Resource kemungkinan dimiliki fasyankes lain (rujukan/apotek luar/lab luar) atau Organization ID di konfigurasi berbeda dengan pembuat resource. Resource milik fasyankes lain tidak dapat diedit — kirim resource baru di bawah Encounter sendiri.',
                'data'             => [],
                'permission_skip'  => true,
            ];
        }

        return $result;
    }

    /**
     * Ownership fields that define WHICH Organization created a resource,
     * grounded in the official SATUSEHAT examples. Medication.manufacturer
     * is deliberately EXCLUDED — it is the drug company, always foreign.
     */
    private const OWNERSHIP_FIELDS = [
        'Encounter'         => ['serviceProvider'],
        'Composition'       => ['custodian'],
        'DiagnosticReport'  => ['performer'],
        'ServiceRequest'    => ['performer'],
        'MedicationRequest' => [],
        'MedicationDispense'=> [],
        'Medication'        => [],
        'Specimen'          => [],
        'Observation'       => [],
        'Immunization'      => [],
        'Condition'         => [],
        'CarePlan'          => [],
        'EpisodeOfCare'     => [],
        'AllergyIntolerance'=> [],
        'ClinicalImpression'=> [],
        'QuestionnaireResponse' => [],
    ];

    /**
     * Pre-update ownership check.
     *
     * GETs the existing resource and compares the org-scoped references
     * against this client's Organization ID. Returns an array describing the
     * skip when the resource is demonstrably owned by ANOTHER organization;
     * returns null when the resource is ours, has no org refs, or ownership
     * cannot be determined (missing read scope etc. — the server decides).
     */
    private function checkEditOwnership(string $endpoint): ?array
    {
        $parts = explode('/', trim($endpoint, '/'));
        $resourceType = $parts[0] ?? '';
        $resourceId = $parts[1] ?? '';
        if ($resourceType === '' || $resourceId === '') {
            return null;
        }
        $fields = self::OWNERSHIP_FIELDS[$resourceType] ?? [];
        if (empty($fields)) {
            return null; // no reliable ownership field for this type
        }

        $result = $this->request('GET', '/' . $resourceType . '/' . $resourceId, null);
        if (!$result['success']) {
            if (($result['code'] ?? 0) === 404) {
                return [
                    'message'   => "SKIP: {$resourceType}/{$resourceId} tidak ditemukan di SATUSEHAT (404) — tidak ada yang bisa di-update.",
                    'owner_org' => null,
                ];
            }
            // read scope missing / transient — cannot verify, let the server decide
            $this->log->warning("[UPDATE] {$endpoint}: ownership pre-check GET gagal ({$result['message']}) — lanjut tanpa pemeriksaan.");
            return null;
        }

        $resource = $result['data'] ?? [];
        if (!is_array($resource) || empty($resource)) {
            return null;
        }

        $orgRefs = self::collectOrgRefs($resource);
        if (empty($orgRefs)) {
            return null;
        }

        $mine = trim((string) $this->orgId);
        $mineFound = false;
        $foreign = [];
        foreach ($orgRefs as $ref) {
            $refId = preg_replace('#^Organization/#', '', trim((string) $ref));
            if ($refId === $mine) {
                $mineFound = true;
            } elseif ($refId !== '') {
                $foreign[] = $refId;
            }
        }
        // Our org appears anywhere in the ownership refs → the resource is
        // ours (multi-org performers, e.g. hospital + external lab).
        if ($mineFound) {
            return null;
        }
        if (empty($foreign)) {
            return null; // nothing to compare — the server decides
        }
        // Every org ref is foreign → owned by another fasyankes.
        return [
            'message'   => "SKIP: {$resourceType}/{$resourceId} dimiliki Organization {$foreign[0]} (bukan {$mine}). Resource milik fasyankes lain (rujukan / apotek luar / lab luar) tidak dapat diedit — kirim resource baru di bawah Encounter sendiri, atau periksa Organization ID di konfigurasi.",
            'owner_org' => $foreign[0],
        ];
    }

    /**
     * Deep-collect "Organization/..." reference values (handles both
     * {"reference": "Organization/x"} and nested {"actor": {...}} forms).
     */
    private static function collectOrgRefs(array $node, array &$refs = []): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                self::collectOrgRefs($value, $refs);
            } elseif ($key === 'reference' && is_string($value) && str_starts_with($value, 'Organization/')) {
                $refs[] = $value;
            }
        }
        return $refs;
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
     * Check if an endpoint is permission-denied (within the cache window).
     *
     * Denials are cached with a finite TTL: a configuration fix (wrong
     * org/client/environment) must not permanently lock resources out —
     * after the window the endpoint is retried once.
     */
    private const PERMISSION_CACHE_TTL_SECONDS = 7 * 86400;

    private function isPermissionDenied(string $endpoint): bool
    {
        $cache = $this->getPermissionCache();
        if (!isset($cache[$endpoint])) {
            return false;
        }
        $deniedAt = (int) $cache[$endpoint];
        if ($deniedAt > 0 && time() - $deniedAt >= self::PERMISSION_CACHE_TTL_SECONDS) {
            unset($cache[$endpoint]);
            $this->savePermissionCache($cache);
            $this->log->warning("[PERMISSION] {$endpoint}: cache expired (denied " . date('Y-m-d H:i:s', $deniedAt) . ") — will retry once; if still foreign/unauthorized it will be re-cached.");
            return false;
        }
        return true;
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

        // Serialize the request body once (used by both transport modes).
        $body = null;
        if ($payload !== null) {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($this->verbosePayload) {
                $this->log->info("[API] {$method} {$url}");
                $this->log->info("[API] Request body:");
                foreach (explode("\n", $body) as $line) {
                    $this->log->info("  " . $line);
                }
            } else {
                $this->log->debug("[API] {$method} {$url} | Body: " . substr($body, 0, 500));
            }
        } else {
            $this->log->debug("[API] {$method} {$url}");
        }

        $maxAttempts = 3;
        $baseDelaySeconds = 1.5;
        $tokenRefreshed = false;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($this->transport !== null) {
                [$response, $httpCode, $error] = ($this->transport)($url, $method, $headers, $body ?? null);
            } else {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_CUSTOMREQUEST  => $method,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
                    CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                    CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
                    CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
                    CURLOPT_HTTPHEADER     => $headers,
                ]);

                if ($body !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                }

                $response = curl_exec($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error    = curl_error($ch);
                curl_close($ch);
            }

            // ── Retry policy ──────────────────────────────────────────
            // Only HTTP 429 is retried: the server definitively did NOT
            // process the request. Timeouts, empty responses and 5xx are
            // treated as UNCERTAIN — the server may have committed the
            // request, so re-POSTing the identical bundle risks duplicates
            // (rule 20002). Callers handle uncertain outcomes via
            // idempotency/reconciliation instead.
            $isTransientError = false;
            $errorReason = '';

            if ($httpCode === 429) {
                $isTransientError = true;
                $errorReason = "HTTP 429 Too Many Requests (Rate Limited)";
            } elseif ($httpCode === 0 && $error !== '') {
                $errorReason = "cURL error: {$error}";
            } elseif ($httpCode === 0) {
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

            // ── 401 → refresh the cached token once and retry ─────────
            // A token can be revoked between cache-write and use; without
            // this the first request after revocation fails permanently.
            if ($httpCode === 401 && !$tokenRefreshed) {
                $tokenRefreshed = true;
                @unlink($this->tokenCacheFile);
                $freshToken = $this->getToken();
                if ($freshToken !== null) {
                    $this->log->warning("[API] HTTP 401 — token refreshed, retrying request once.");
                    $headers = [
                        'Authorization: Bearer ' . $freshToken,
                        'Content-Type: ' . ($contentType ?? 'application/json'),
                        'Accept: application/json'
                    ];
                    continue;
                }
            }

            if ($httpCode === 0) {
                $this->log->error("[API] {$errorReason} after {$attempt} attempts");
                return ['success' => false, 'code' => 0, 'message' => $errorReason === 'Empty response' ? 'Empty or invalid response from API' : $errorReason, 'data' => []];
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
