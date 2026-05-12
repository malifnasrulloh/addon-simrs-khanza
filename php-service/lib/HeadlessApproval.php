<?php

/**
 * HeadlessApproval - Simulate the iCare browser approval flow via cURL.
 *
 * Flow:
 *  1. GET  decrypted URL          → establish session, extract tokens
 *  2. POST /IHS/cekVerifikasi3    → initial verification
 *  3. POST /IHS/approvalIC        → submit approval
 *  4. POST /IHS/cekVerifikasi3    → confirm approval (expect code 200)
 *
 * Uses a cookie jar file to maintain session state across requests.
 *
 * @author  malifnasrulloh (by Antigravity)
 */

declare(strict_types=1);

class HeadlessApproval
{
    private Logger $log;
    private string $cookieJar;
    private string $baseIhsUrl = 'https://mobile-faskes.bpjs-kesehatan.go.id';

    /** @var string|null Last HTML body for debugging */
    private ?string $lastBody = null;

    public function __construct(Logger $log, string $tempDir)
    {
        $this->log = $log;

        if (!str_starts_with($tempDir, '/')) {
            $tempDir = BASE_DIR . '/' . $tempDir;
        }
        if (!is_dir($tempDir) && !mkdir($tempDir, 0755, true)) {
            throw new \RuntimeException("Cannot create temp dir: {$tempDir}");
        }

        // Unique cookie jar per process to avoid collision
        $this->cookieJar = $tempDir . '/icare_cookies_' . getmypid() . '.txt';
    }

    /**
     * Run the full headless approval flow.
     *
     * @param string $icareUrl The decrypted iCare URL containing the session token
     * @return array ['success' => bool, 'message' => string]
     */
    public function approve(string $icareUrl): array
    {
        // Clean any previous cookies
        if (file_exists($this->cookieJar)) {
            @unlink($this->cookieJar);
        }

        try {
            // ── Step 1: GET the iCare URL to establish session ──────────
            $this->log->info('  [BROWSER] Step 1: GET iCare URL to establish session...');
            $response = $this->httpGet($icareUrl);

            if ($response['error']) {
                return ['success' => false, 'message' => 'GET failed: ' . $response['error']];
            }

            $this->log->debug('  [BROWSER] Step 1 HTTP ' . $response['http_code']);
            $this->lastBody = $response['body'];

            // Extract token from URL for subsequent requests
            $token = $this->extractTokenFromUrl($icareUrl);
            if (!$token) {
                return ['success' => false, 'message' => 'Could not extract token from URL'];
            }
            $this->log->debug('  [BROWSER] Token: ' . $token . '...');

            // Extract any hidden form fields or CSRF tokens from HTML
            $formData = $this->extractFormData($response['body']);
            $this->log->debug('  [BROWSER] Extracted form fields: ' . json_encode(array_keys($formData)));

            // Anti-bruteforce delay
            sleep(rand(1, 2));

            // ── Step 2: POST cekVerifikasi3 (initial verification) ──────
            // $this->log->info('  [BROWSER] Step 2: POST cekVerifikasi3 (initial check)...');
            // $verifyUrl = $this->baseIhsUrl . '/IHS/cekVerifikasi3';
            // $verifyPayload = array_merge($formData, ['token' => $token]);

            // $response = $this->httpPost($verifyUrl, $verifyPayload);
            // if ($response['error']) {
            //     return ['success' => false, 'message' => 'cekVerifikasi3 failed: ' . $response['error']];
            // }

            // $this->log->debug('  [BROWSER] Step 2 HTTP ' . $response['http_code'] . ' | Body: ' . $response['body']);
            // $this->lastBody = $response['body'];

            // // Parse response to determine if we need approval
            // $verifyResult = json_decode($response['body'], true);
            // if ($verifyResult === null && json_last_error() !== JSON_ERROR_NONE) {
            //     // Might be HTML, try to extract additional data
            //     $formData = array_merge($formData, $this->extractFormData($response['body']));
            //     $this->log->debug('  [BROWSER] Response is HTML, extracted additional form data');
            // }

            // Anti-bruteforce delay
            // sleep(rand(1, 3));

            // ── Step 3: POST approvalIC (approve) ──────────────────────
            $this->log->info('  [BROWSER] Step 3: POST approvalIC (submit approval)...');
            $approveUrl = $this->baseIhsUrl . '/IHS/approvalIC';
            $approvePayload = array_merge($formData, ['token' => $token]);

            $response = $this->httpPost($approveUrl, $approvePayload);
            if ($response['error']) {
                return ['success' => false, 'message' => 'approvalIC failed: ' . $response['error']];
            }

            $this->log->debug('  [BROWSER] Step 3 HTTP ' . $response['http_code'] . ' | Body: ' . $response['body']);
            $this->lastBody = $response['body'];

            $response_json = json_decode($response['body'], true);
            $inner_data = isset($response_json['response']) ? json_decode($response_json['response'], true) : null;
            $response_metadata = $inner_data['metaData'] ?? null;

            if (isset($response_metadata) && $response_metadata['code'] == 200 && $response_metadata['message'] == "OK") {
                return [
                    'success' => true,
                    'message' => 'Approval flow completed, Last response: ' . $response['body'],
                ];
            }



            // Anti-bruteforce delay
            sleep(rand(1, 2));

            // ── Step 4: POST cekVerifikasi3 (confirm approval) ─────────
            // $this->log->info('  [BROWSER] Step 4: POST cekVerifikasi3 (confirmation)...');
            // $response = $this->httpPost($verifyUrl, $verifyPayload);
            // if ($response['error']) {
            //     return ['success' => false, 'message' => 'Final cekVerifikasi3 failed: ' . $response['error']];
            // }

            // $this->log->debug('  [BROWSER] Step 4 HTTP ' . $response['http_code'] . ' | Body: ' . $response['body']);
            // $this->lastBody = $response['body'];

            // // Check for success response
            // $finalResult = json_decode($response['body'], true);
            // if ($this->isSuccessResponse($finalResult)) {
            //     return ['success' => true, 'message' => 'Approval confirmed (code 200)'];
            // }

            // // Also check step 3 response for success
            // $step3Result = json_decode($this->lastBody ?? '', true);
            // if ($this->isSuccessResponse($step3Result)) {
            //     return ['success' => true, 'message' => 'Approval confirmed at step 3'];
            // }

            return [
                'success' => false,
                'message' => 'Approval flow completed but success not confirmed. Last response: ' . $response['body'],
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Exception: ' . $e->getMessage()];
        } finally {
            // Cleanup cookie jar
            if (file_exists($this->cookieJar)) {
                @unlink($this->cookieJar);
            }
        }
    }

    /**
     * Extract token from iCare URL query string.
     * URL format: https://mobile-faskes.bpjs-kesehatan.go.id/IHS/historyfaskes?token=xxx-xxx
     */
    private function extractTokenFromUrl(string $url): ?string
    {
        $parsed = parse_url($url);
        if (!isset($parsed['query'])) return null;

        parse_str($parsed['query'], $params);
        return $params['token'] ?? null;
    }

    /**
     * Extract hidden form fields from HTML response.
     */
    private function extractFormData(string $html): array
    {
        $data = [];
        if (empty($html)) return $data;

        // Suppress warnings for malformed HTML
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $inputs = $dom->getElementsByTagName('input');
        foreach ($inputs as $input) {
            $name  = $input->getAttribute('name');
            $value = $input->getAttribute('value');
            $type  = strtolower($input->getAttribute('type'));

            if (!empty($name) && $type === 'hidden') {
                $data[$name] = $value;
            }
        }

        return $data;
    }

    /**
     * Check if a JSON response indicates success (code 200).
     */
    private function isSuccessResponse(?array $result): bool
    {
        if ($result === null) return false;

        // Direct check
        if (isset($result['metaData']['code']) && (int)$result['metaData']['code'] === 200) {
            return true;
        }

        // Nested response string (the expected final format)
        if (isset($result['response'])) {
            $inner = is_string($result['response']) ? json_decode($result['response'], true) : $result['response'];
            if (isset($inner['metaData']['code']) && (int)$inner['metaData']['code'] === 200) {
                return true;
            }
        }

        return false;
    }

    /**
     * HTTP GET with cookie jar session.
     */
    private function httpGet(string $url): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: id-ID,id;q=0.9,en;q=0.8',
            ],
        ]);

        $body     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch) ?: null;
        // curl_close($ch);

        return ['body' => $body ?: '', 'http_code' => $httpCode, 'error' => $error];
    }

    /**
     * HTTP POST with cookie jar session (form-urlencoded).
     */
    private function httpPost(string $url, array $data): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json, text/html, */*',
                'Accept-Language: id-ID,id;q=0.9,en;q=0.8',
                'X-Requested-With: XMLHttpRequest',
            ],
        ]);

        $body     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch) ?: null;
        // curl_close($ch);

        return ['body' => $body ?: '', 'http_code' => $httpCode, 'error' => $error];
    }

    /**
     * Get the last response body (for debugging).
     */
    public function getLastBody(): ?string
    {
        return $this->lastBody;
    }
}
