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
    private Logger $log;
    private string $tokenCacheFile;

    private const CONNECT_TIMEOUT = 10;
    private const REQUEST_TIMEOUT = 30;

    public function __construct(SatuSehatConfig $config, Logger $log)
    {
        $this->clientId     = $config->clientId;
        $this->secretKey    = $config->secretKey;
        $this->authUrl      = $config->authUrl;
        $this->baseUrl      = $config->baseUrl;
        $this->tokenTimeout = $config->tokenTimeout;
        $this->delayMs      = $config->delayMs;
        $this->log          = $log;

        $this->tokenCacheFile = $config->logDir . '/satusehat_token.json';
    }

    /**
     * Retrieve or refresh OAuth2 Access Token.
     */
    public function getToken(): ?string
    {
        // 1. Check file cache
        if (file_exists($this->tokenCacheFile)) {
            $raw = file_get_contents($this->tokenCacheFile);
            $cache = json_decode($raw, true);
            if ($cache && isset($cache['token']) && isset($cache['expires_at'])) {
                // Buffer of 60 seconds to avoid edge-case expiry
                if (time() < ($cache['expires_at'] - 60)) {
                    return $cache['token'];
                }
            }
        }

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
     * Core HTTP request method.
     */
    private function request(string $method, string $endpoint, ?array $payload): array
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
            'Content-Type: application/json',
            'Accept: application/json'
        ];

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
                $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                if ($attempt === 1) {
                    $this->log->debug("[API] {$method} {$url} | Body: " . substr($body, 0, 500));
                }
            } else {
                if ($attempt === 1) {
                    $this->log->debug("[API] {$method} {$url}");
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
}
