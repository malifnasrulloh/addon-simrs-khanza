<?php

/**
 * BPJSICareApi - BPJS iCare wsihs API client with decryption.
 *
 * Handles:
 *  - HMAC-SHA256 signature generation
 *  - POST /api/RS/validate
 *  - AES-256-CBC decryption + LZString decompression
 *
 * Ported from: src/bridging/ApiICareBPJS.java + ApiBPJSEnc.java
 *
 * @author  malifnasrulloh (ported from Java by Antigravity)
 */

declare(strict_types=1);

require_once __DIR__ . '/LZString.php';

class BPJSICareApi
{
    private string $consId;
    private string $secretKey;
    private string $userKey;
    private string $baseUrl;
    private Logger $log;

    public function __construct(string $consId, string $secretKey, string $userKey, string $baseUrl, Logger $log)
    {
        $this->consId    = $consId;
        $this->secretKey = $secretKey;
        $this->userKey   = $userKey;
        $this->baseUrl   = rtrim($baseUrl, '/');
        $this->log       = $log;
    }

    /**
     * Generate BPJS API headers with HMAC-SHA256 signature.
     * Signature data = consId + "&" + timestamp, key = secretKey
     */
    public function generateHeaders(?int $timestamp = null): array
    {
        $timestamp = $timestamp ?? time();
        $data = $this->consId . '&' . $timestamp;
        $signature = base64_encode(hash_hmac('sha256', $data, $this->secretKey, true));

        return [
            'X-cons-id: '    . $this->consId,
            'X-timestamp: '  . $timestamp,
            'X-signature: '  . $signature,
            'user_key: '     . $this->userKey,
            'Content-Type: application/json',
            'Accept: */*',
        ];
    }

    /**
     * Call POST /api/RS/validate to get the encrypted iCare URL.
     *
     * @param string $param      NIK or No Kartu BPJS
     * @param int $kodeDokter BPJS doctor code
     * @return array ['success' => bool, 'url' => string|null, 'message' => string, 'timestamp' => int]
     */
    public function validate(string $param, int $kodeDokter): array
    {
        $timestamp = time();
        $headers = $this->generateHeaders($timestamp);
        $url = $this->baseUrl . '/api/rs/validate';

        $payload = json_encode([
            'param'      => $param,
            'kodedokter' => $kodeDokter,
        ], JSON_UNESCAPED_UNICODE);

        $this->log->debug("[API] POST {$url}");
        $this->log->debug("[API] Payload: {$payload}");

        $result = $this->curlPost($url, $payload, $headers);

        if ($result['error']) {
            return ['success' => false, 'url' => null, 'message' => 'cURL error: ' . $result['error'], 'timestamp' => $timestamp];
        }

        $this->log->debug("[API] HTTP {$result['http_code']} | Body: " . substr($result['body'], 0, 500));

        $json = json_decode($result['body'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'url' => null, 'message' => 'Invalid JSON response', 'timestamp' => $timestamp];
        }

        $code = $json['metaData']['code'] ?? '';
        $message = $json['metaData']['message'] ?? 'Unknown';

        if ((string)$code !== '200') {
            return ['success' => false, 'url' => null, 'message' => "API {$code}: {$message}", 'timestamp' => $timestamp];
        }

        // Decrypt response to get URL
        $encrypted = $json['response'] ?? '';
        if (empty($encrypted)) {
            return ['success' => false, 'url' => null, 'message' => 'Empty response field', 'timestamp' => $timestamp];
        }

        try {
            $decrypted = $this->decrypt($encrypted, $timestamp);
            $this->log->debug("[API] Decrypted: " . substr($decrypted, 0, 300));

            $parsed = json_decode($decrypted, true);
            $icareUrl = $parsed['url'] ?? null;

            if (empty($icareUrl)) {
                return ['success' => false, 'url' => null, 'message' => 'No URL in decrypted response', 'timestamp' => $timestamp];
            }

            return ['success' => true, 'url' => $icareUrl, 'message' => 'OK', 'timestamp' => $timestamp];
        } catch (\Throwable $e) {
            return ['success' => false, 'url' => null, 'message' => 'Decryption failed: ' . $e->getMessage(), 'timestamp' => $timestamp];
        }
    }

    /**
     * Decrypt BPJS API response using AES-256-CBC + LZString.
     *
     * Key derivation (from ApiBPJSEnc.java):
     *   hashKey = SHA-256(consId + secretKey + timestamp)  → 32 bytes (AES-256 key)
     *   hashIv  = first 16 bytes of hashKey                → IV
     *
     * @param string $cipherText Base64-encoded encrypted data
     * @param int    $timestamp  The timestamp used in the API request
     * @return string Decompressed plaintext
     */
    public function decrypt(string $cipherText, int $timestamp): string
    {
        // 1. Generate key material: SHA-256(ConsId + SecretKey + timestamp)
        $keySource = $this->consId . $this->secretKey . $timestamp;
        $hashKey = hash('sha256', $keySource, true); // 32 bytes
        $hashIv  = substr($hashKey, 0, 16);           // first 16 bytes

        // 2. AES-256-CBC decrypt
        $decoded = base64_decode($cipherText, true);
        if ($decoded === false) {
            throw new \RuntimeException('Invalid base64 in cipher text');
        }

        $decrypted = openssl_decrypt(
            $decoded,
            'aes-256-cbc',
            $hashKey,
            OPENSSL_RAW_DATA,
            $hashIv
        );

        if ($decrypted === false) {
            throw new \RuntimeException('AES decryption failed: ' . openssl_error_string());
        }

        // 3. LZString decompress
        $decompressed = LZString::decompressFromEncodedURIComponent($decrypted);
        if ($decompressed === null) {
            throw new \RuntimeException('LZString decompression returned null');
        }

        return $decompressed;
    }

    /**
     * Send HTTP POST via cURL.
     */
    private function curlPost(string $url, string $body, array $headers): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'SIMRS-Khanza/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch) ?: null;
        curl_close($ch);

        return ['body' => $response ?: '', 'http_code' => $httpCode, 'error' => $error];
    }
}
