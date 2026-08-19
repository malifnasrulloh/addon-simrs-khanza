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
    private string $delayMode;
    private int    $consecutive429 = 0;
    private int    $successStreak  = 0;
    private float  $nextAllowedAt  = 0.0;
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

    // ── Acceptance-rate instrumentation (Phase C) ──────────────────────────
    /** Rejection taxonomy counters accumulated per process (400 responses). */
    private static array $rejectedByRule = [];
    private static int $rejectedOther400 = 0;
    private static int $rejectedPermission = 0;
    /** Pre-flight payload-shape issue count (see PayloadBuilder::validatePayload). */
    private static int $validationIssueCount = 0;

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
        $this->delayMode       = $config->delayMode;
        $this->verbosePayload  = $config->verbosePayload;
        $this->verifyTls       = $config->verifyTls;
        $this->log             = $log;

        $this->tokenCacheFile     = $config->logDir . '/satusehat_token.json';
        $this->permissionCacheFile = $config->logDir . '/satusehat_permission_denied.json';
        $this->sourceTimezone     = new \DateTimeZone($config->timezone ?: 'Asia/Jakarta');

        // Per-run acceptance summary: logged once at process shutdown so every
        // sync service reports rejected-by-rule / 429 / permission counts.
        register_shutdown_function(function () {
            $parts = [];
            foreach (self::$rejectedByRule as $rule => $count) {
                $parts[] = "rule {$rule}: {$count}";
            }
            if (self::$rejectedPermission > 0) {
                $parts[] = 'permission: ' . self::$rejectedPermission;
            }
            if (self::$rejectedOther400 > 0) {
                $parts[] = 'other-400: ' . self::$rejectedOther400;
            }
            if (self::$validationIssueCount > 0) {
                $parts[] = 'preflight-issues: ' . self::$validationIssueCount;
            }
            if ($parts !== []) {
                $this->log->warning('[REJECT-STATS] ' . implode(' | ', $parts));
            }
        });
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
        // ── Denial cap: stop hammering after N denials this run ───────
        if (self::$denialCount >= self::PERMISSION_DENIAL_CAP) {
            $this->log->warning("[UPDATE] {$endpoint}: Skipped — permission-denial cap (" . self::PERMISSION_DENIAL_CAP . ") reached this run. Periksa konfigurasi Organization ID / client, lalu reset state yang terkena.");
            return [
                'success'          => true,
                'code'             => 200,
                'message'          => 'Permission-denial cap reached this run',
                'data'             => [],
                'permission_cap'   => true,
            ];
        }

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
     * SATUSEHAT puts the message in issue[0].details.text (NOT diagnostics —
     * processors reading only diagnostics silently got "API Error").
     */
    public static function extractErrorMsg(array $result): string
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
     * Classify an API failure into a terminal state, RuleNumber-first.
     *
     * Replaces the keyword-hack (stripos 'code'/'system'/'consent') that
     * misclassified errors and, worse, saw "API Error" when details.text was
     * not read. Priority:
     *   duplicate  → 'duplicate'          (409 / "Found duplicate")
     *   RuleNumber 10xxx → 'invalid_code' (payload/terminology rules)
     *   RuleNumber 20xxx → 'failed_rule'  (permission/org/conflict rules)
     *   privacy/consent/forbidden keywords → 'privacy_error'
     *   otherwise  → 'generic'            (retryable — no terminal state)
     */
    public static function classifyError(array $result): string
    {
        $msg = self::extractErrorMsg($result);
        if (stripos($msg, 'duplicate') !== false || ($result['code'] ?? 0) === 409) {
            return 'duplicate';
        }
        if (preg_match('/RuleNumber\s*:?\s*(\d{3,6})/i', $msg, $m)) {
            $rule = (int) $m[1];
            if ($rule >= 10000 && $rule < 20000) {
                return 'invalid_code';
            }
            return 'failed_rule';
        }
        // NOTE: 'consent' alone is deliberately NOT a privacy signal —
        // clinical text like "Inform consent" appears in payloads; real
        // SATUSEHAT privacy denials carry 'privacy'/'forbidden' wording.
        if (preg_match('/privacy|forbidden|not authorized|permission/i', $msg)) {
            return 'privacy_error';
        }
        return 'generic';
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

    /**
     * Cap on permission-denied PUT/PATCH attempts per process run — a whole
     * pipeline must never hammer the API thousands of times (observed:
     * 10,923 denied specimen updates in one run before caching kicked in).
     * After the cap, remaining updates short-circuit with a summary.
     */
    private const PERMISSION_DENIAL_CAP = 50;

    private static int $denialCount = 0;

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
        self::$denialCount++;
        $cache = $this->getPermissionCache();
        $cache[$endpoint] = time();
        $this->savePermissionCache($cache);
        $remaining = self::PERMISSION_DENIAL_CAP - self::$denialCount;
        $this->log->warning("[PERMISSION] Cached {$endpoint} — will not retry on future runs (denials this run: " . self::$denialCount . ", cap " . self::PERMISSION_DENIAL_CAP . ", {$remaining} remaining before short-circuit).");
    }

    /**
     * Pre-request pacing. In 'fixed' mode sleeps a constant SATUSEHAT_DELAY_MS
     * (legacy behavior). In 'adaptive' mode (default) there is no fixed sleep —
     * requests run back-to-back until HTTP 429 triggers an exponential
     * cooldown that ramps 2s→60s with jitter, then decays on success.
     */
    private function applyRateLimit(): void
    {
        if ($this->delayMode === 'fixed') {
            if ($this->delayMs > 0) {
                usleep($this->delayMs * 1000);
            }
            return;
        }

        // Adaptive: only an active 429 cooldown holds the line.
        if ($this->nextAllowedAt > 0) {
            $waitUs = (int) (($this->nextAllowedAt - microtime(true)) * 1000000);
            if ($waitUs > 0) {
                usleep($waitUs);
            }
            $this->nextAllowedAt = 0;
        }
    }

    /**
     * Tally HTTP 400 rejection reasons for the run-end acceptance summary.
     * Permission-denied ("don't have permission") and per-rule (RuleNumber:)
     * responses are split so the acceptance rate can exclude permission noise.
     */
    private function tallyRejection(int $httpCode, $response): void
    {
        if ($httpCode !== 400 || !is_string($response) || $response === '') {
            return;
        }
        if (stripos($response, 'permission') !== false) {
            self::$rejectedPermission++;
            return;
        }
        if (preg_match_all('/RuleNumber:\s*(\d+)/', $response, $m)) {
            foreach ($m[1] as $rn) {
                self::$rejectedByRule[$rn] = (self::$rejectedByRule[$rn] ?? 0) + 1;
            }
            return;
        }
        self::$rejectedOther400++;
    }

    /**
     * Record per-entry rule rejections from a transaction Bundle response
     * (entry-level OperationOutcome inside an HTTP-200 response). The
     * HTTP-level tally above never sees these, so the panel calls this
     * after classifying every entry.
     */
    public static function tallyRuleRejection(int $rule, int $count = 1): void
    {
        self::$rejectedByRule[$rule] = (self::$rejectedByRule[$rule] ?? 0) + $count;
    }

    /**
     * Record per-entry non-rule rejections (e.g. privacy/permission entries
     * without a RuleNumber) into the run-end acceptance summary.
     */
    public static function tallyOtherRejection(int $count = 1): void
    {
        self::$rejectedOther400 += $count;
    }

    /**
     * Feed rate-limit state from each HTTP result.
     */
    private function noteHttpResult(int $httpCode): void
    {
        if ($httpCode === 429) {
            $this->consecutive429++;
            $this->successStreak = 0;
            $backoff = min(60.0, pow(2, $this->consecutive429));
            $backoff *= 0.75 + (mt_rand(0, 500) / 1000); // jitter 0.75x–1.25x
            $this->nextAllowedAt = microtime(true) + $backoff;
            return;
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            $this->successStreak++;
            $this->consecutive429 = max(0, $this->consecutive429 - 1);
            if ($this->successStreak >= 10) {
                $this->successStreak = 0;
                $this->consecutive429 = 0;
            }
            return;
        }

        $this->successStreak = 0;
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

        // Rate limit pacing: fixed sleep (legacy) or adaptive with 429 backoff
        $this->applyRateLimit();

        $url = $this->baseUrl . $endpoint;
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: ' . ($contentType ?? 'application/json'),
            'Accept: application/json'
        ];

        if ($payload !== null) {
            $payload = $this->convertPayloadDatesToUtc($payload);
        }

        // Pre-flight payload shape validation (best-practice guard): flags
        // empty system/code/reference and malformed coding arrays that the
        // server rejects as 400s. Logs only — never blocks the send.
        if ($payload !== null && class_exists(\SatuSehatPayloadBuilder::class)) {
            $issues = \SatuSehatPayloadBuilder::validatePayload($payload, $endpoint);
            if ($issues !== []) {
                self::$validationIssueCount += count($issues);
                static $validateLogged = 0;
                if ($validateLogged < 10) {
                    foreach ($issues as $issue) {
                        $this->log->warning("[VALIDATE] {$issue}");
                    }
                    $validateLogged++;
                }
            }
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
            $this->noteHttpResult($httpCode);
            $this->tallyRejection($httpCode, $response);
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

            // Curl can return false / an empty body while a non-zero HTTP code
            // was recorded (e.g. "transfer closed with outstanding read data
            // remaining") — treat as an uncertain network failure, never feed
            // it to json_decode (was a FATAL TypeError on the live server).
            if ($response === false || $response === '') {
                $this->log->error("[API] Empty or invalid response from Satu Sehat (HTTP {$httpCode}) after {$attempt} attempts");
                return ['success' => false, 'code' => 0, 'message' => 'Empty or invalid response from API', 'data' => []];
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
