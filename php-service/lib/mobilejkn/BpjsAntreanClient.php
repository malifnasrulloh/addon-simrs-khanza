<?php

/**
 * BpjsAntreanClient - BPJS Mobile JKN Antrean API client.
 *
 * Features:
 *  - HMAC-SHA256 signature generation (same pattern as Aplicare/iCare)
 *  - Single request methods (add, batal, updatewaktu, farmasi/add)
 *  - Parallel batch execution via curl_multi_* for I/O-bound throughput
 *
 * @author  malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class BpjsAntreanClient
{
    private string $consId;
    private string $secretKey;
    private string $userKey;
    private string $baseUrl;
    private Logger $log;
    private int    $batchSize;
    private bool   $dryRun;

    // ─── cURL defaults ─────────────────────────────────────────────────────
    private const CONNECT_TIMEOUT = 10;
    private const REQUEST_TIMEOUT = 30;
    private const USER_AGENT      = 'SIMRS-Khanza-MobileJKN/2.0';

    public function __construct(
        string $consId,
        string $secretKey,
        string $userKey,
        string $baseUrl,
        int    $batchSize,
        Logger $log,
        bool   $dryRun = false
    ) {
        $this->consId    = $consId;
        $this->secretKey = $secretKey;
        $this->userKey   = $userKey;
        $this->baseUrl   = rtrim($baseUrl, '/');
        $this->batchSize = $batchSize;
        $this->log       = $log;
        $this->dryRun    = $dryRun;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // High-level API methods (single request)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * POST /antrean/add — Add a new queue entry.
     *
     * @return array{success: bool, code: string, message: string}
     */
    public function addAntrean(array $payload): array
    {
        return $this->post('/antrean/add', $payload);
    }

    /**
     * POST /antrean/batal — Cancel a queue entry.
     */
    public function batalAntrean(string $kodebooking, string $keterangan): array
    {
        return $this->post('/antrean/batal', [
            'kodebooking' => $kodebooking,
            'keterangan'  => $keterangan,
        ]);
    }

    /**
     * POST /antrean/updatewaktu — Update task timestamp.
     *
     * @param int $waktu Epoch milliseconds
     */
    public function updateWaktu(string $kodebooking, string $taskId, int $waktu): array
    {
        return $this->post('/antrean/updatewaktu', [
            'kodebooking' => $kodebooking,
            'taskid'      => $taskId,
            'waktu'       => $waktu,
        ]);
    }

    /**
     * POST /antrean/farmasi/add — Add pharmacy queue entry.
     */
    public function addFarmasiAntrean(array $payload): array
    {
        return $this->post('/antrean/farmasi/add', $payload);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Batch execution via curl_multi_*
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Execute multiple API requests in parallel using curl_multi.
     *
     * @param array $requests Array of ['id' => string, 'endpoint' => string, 'payload' => array]
     * @return array Keyed by request 'id' => ['success' => bool, 'code' => string, 'message' => string]
     */
    public function executeBatch(array $requests): array
    {
        if (empty($requests)) return [];

        $results = [];
        $chunks  = array_chunk($requests, $this->batchSize, true);
        $totalChunks = count($chunks);

        foreach ($chunks as $chunkIdx => $chunk) {
            $chunkNum = $chunkIdx + 1;
            $this->log->debug("[BATCH] Processing chunk {$chunkNum}/{$totalChunks} (" . count($chunk) . " requests)");

            if ($this->dryRun) {
                foreach ($chunk as $req) {
                    $results[$req['id']] = ['success' => true, 'code' => 'DRY', 'message' => 'Dry-run skipped'];
                }
                continue;
            }

            $chunkResults = $this->executeMultiCurl($chunk);
            $results = array_merge($results, $chunkResults);
        }

        return $results;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Internal: curl_multi execution
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Execute a chunk of requests in parallel via curl_multi_*.
     *
     * @param array $chunk Array of ['id' => string, 'endpoint' => string, 'payload' => array]
     * @return array Keyed by 'id'
     */
    private function executeMultiCurl(array $chunk): array
    {
        $mh      = curl_multi_init();
        $handles = [];
        $results = [];

        // Build and add handles
        foreach ($chunk as $req) {
            $id       = $req['id'];
            $url      = $this->baseUrl . $req['endpoint'];
            $body     = json_encode($req['payload'], JSON_UNESCAPED_UNICODE);
            $headers  = $this->generateHeaders();

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT      => self::USER_AGENT,
            ]);

            $handles[$id] = $ch;
            curl_multi_add_handle($mh, $ch);

            $this->log->debug("[HTTP] Queued POST {$url} | Body: " . substr($body, 0, 300));
        }

        // Execute all handles
        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($status > CURLM_OK) {
                $this->log->error("[HTTP] curl_multi_exec error: " . curl_multi_strerror($status));
                break;
            }
            // Wait for activity (avoids busy-looping)
            if ($running > 0) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running > 0);

        // Collect results
        foreach ($handles as $id => $ch) {
            $response = curl_multi_getcontent($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);

            if ($error) {
                $this->log->error("[HTTP] {$id}: cURL error: {$error}");
                $results[$id] = ['success' => false, 'code' => '0', 'message' => "cURL error: {$error}"];
            } else {
                $results[$id] = $this->parseApiResponse($id, $response, $httpCode);
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);
        return $results;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Internal: Single POST (for one-off calls like batal)
    // ═══════════════════════════════════════════════════════════════════════

    private function post(string $endpoint, array $payload): array
    {
        $url     = $this->baseUrl . $endpoint;
        $body    = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $headers = $this->generateHeaders();

        $this->log->debug("[HTTP] POST {$url}");
        $this->log->debug("[HTTP] Body: {$body}");

        if ($this->dryRun) {
            $this->log->info("[DRY-RUN] Skipped POST {$endpoint}");
            return ['success' => true, 'code' => 'DRY', 'message' => 'Dry-run skipped'];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => self::USER_AGENT,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->log->error("[HTTP] cURL error: {$error}");
            return ['success' => false, 'code' => '0', 'message' => "cURL error: {$error}"];
        }

        return $this->parseApiResponse($endpoint, $response, $httpCode);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Generate BPJS API headers with HMAC-SHA256 signature.
     */
    private function generateHeaders(): array
    {
        $timestamp = time();
        $data      = $this->consId . '&' . $timestamp;
        $signature = base64_encode(hash_hmac('sha256', $data, $this->secretKey, true));

        return [
            'X-cons-id: '   . $this->consId,
            'X-timestamp: ' . $timestamp,
            'X-signature: ' . $signature,
            'user_key: '    . $this->userKey,
            'Content-Type: application/json',
            'Accept: */*',
        ];
    }

    /**
     * Parse BPJS API JSON response into a standardized result.
     */
    private function parseApiResponse(string $label, string $body, int $httpCode): array
    {
        $this->log->debug("[HTTP] {$label}: HTTP {$httpCode} | Body: " . substr($body, 0, 500));

        $json = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log->error("[HTTP] {$label}: Invalid JSON: " . substr($body, 0, 300));
            return ['success' => false, 'code' => (string) $httpCode, 'message' => 'Invalid JSON response'];
        }

        $code    = (string) ($json['metadata']['code'] ?? $json['metaData']['code'] ?? '');
        $message = (string) ($json['metadata']['message'] ?? $json['metaData']['message'] ?? 'Unknown');

        $isSuccess = in_array($code, ['200', '208'], true) || $message === 'Ok';

        if ($isSuccess) {
            $this->log->info("[BPJS] {$label}: {$code} — {$message}");
        } else {
            $this->log->warning("[BPJS] {$label}: {$code} — {$message}");
        }

        return ['success' => $isSuccess, 'code' => $code, 'message' => $message];
    }
}
