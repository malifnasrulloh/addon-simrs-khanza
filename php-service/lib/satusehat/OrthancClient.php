<?php

/**
 * OrthancClient - Native PHP client for Orthanc PACS and go-dcm Converter integration.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class OrthancClient
{
    private SatuSehatConfig $config;
    private Logger $log;

    public function __construct(SatuSehatConfig $config, Logger $log)
    {
        $this->config = $config;
        $this->log = $log;
    }

    /**
     * Performs a cURL request to Orthanc PACS.
     */
    private function orthancRequest(string $method, string $path, ?array $payload = null): array
    {
        $url = rtrim($this->config->orthancUrl, '/') . ':' . $this->config->orthancPort . '/' . ltrim($path, '/');
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $user = $this->config->orthancUser;
        $pass = $this->config->orthancPass;
        curl_setopt($ch, CURLOPT_USERPWD, "{$user}:{$pass}");

        $headers = ['User-Agent: SIMRS-Khanza-OrthancClient/1.0'];

        if ($payload !== null) {
            $jsonData = json_encode($payload);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($jsonData);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->log->error("[ORTHANC] cURL Error calling {$url}: {$error}");
            return ['success' => false, 'code' => $httpCode, 'message' => $error, 'data' => null];
        }

        $decoded = json_decode($response, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            $this->log->error("[ORTHANC] API Error calling {$url} (HTTP {$httpCode}): {$response}");
            return ['success' => false, 'code' => $httpCode, 'message' => $response, 'data' => $decoded];
        }

        return ['success' => true, 'code' => $httpCode, 'message' => 'Success', 'data' => $decoded];
    }

    /**
     * Performs a cURL request to go-dcm Converter.
     */
    private function converterRequest(string $method, string $path, ?array $payload = null): array
    {
        $url = rtrim($this->config->dicomConverterUrl, '/') . ':' . $this->config->dicomConverterPort . '/' . ltrim($path, '/');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $headers = ['User-Agent: SIMRS-Khanza-OrthancClient/1.0'];

        if ($payload !== null) {
            $jsonData = json_encode($payload);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($jsonData);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->log->error("[CONVERTER] cURL Error calling {$url}: {$error}");
            return ['success' => false, 'code' => $httpCode, 'message' => $error, 'data' => null];
        }

        $decoded = json_decode($response, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            $this->log->error("[CONVERTER] API Error calling {$url} (HTTP {$httpCode}): {$response}");
            return ['success' => false, 'code' => $httpCode, 'message' => $response, 'data' => $decoded];
        }

        return ['success' => true, 'code' => $httpCode, 'message' => 'Success', 'data' => $decoded];
    }

    /**
     * Finds the Orthanc internal study ID by searching for an exact AccessionNumber.
     */
    public function findStudyByAccession(string $acsn): ?string
    {
        $this->log->debug("[ORTHANC] Searching study by AccessionNumber '{$acsn}'...");
        
        $payload = [
            'Level' => 'Study',
            'Expand' => true,
            'Query' => [
                'AccessionNumber' => $acsn
            ]
        ];

        $res = $this->orthancRequest('POST', '/tools/find', $payload);
        if ($res['success'] && is_array($res['data']) && count($res['data']) > 0) {
            $studyId = $res['data'][0]['ID'] ?? null;
            $this->log->info("[ORTHANC] Found Study ID '{$studyId}' matching AccessionNumber '{$acsn}'");
            return $studyId;
        }

        return null;
    }

    /**
     * Queries Orthanc for studies matching PatientID, date, and ModalitiesInStudy.
     */
    public function findStudyByModality(string $patientId, string $date, string $modality): array
    {
        $this->log->debug("[ORTHANC] Querying series by modality: Patient='{$patientId}', Date='{$date}', Modality='{$modality}'...");

        $payload = [
            'Level' => 'Study',
            'Expand' => true,
            'Query' => [
                'StudyDate' => "{$date}-{$date}",
                'PatientID' => $patientId,
                'ModalitiesInStudy' => $modality
            ]
        ];

        $res = $this->orthancRequest('POST', '/tools/find', $payload);
        if ($res['success'] && is_array($res['data'])) {
            return $res['data'];
        }

        return [];
    }

    /**
     * Modifies study tags in Orthanc (specifically AccessionNumber).
     * Returns the new Orthanc Study ID on success.
     */
    public function modifyStudyAccession(string $studyId, string $acsn): ?string
    {
        $this->log->info("[ORTHANC] Modifying Study '{$studyId}' AccessionNumber to '{$acsn}'...");

        // First attempt via the Gateway Mode (Go Converter API)
        $converterBase = trim($this->config->dicomConverterUrl);
        if (!empty($converterBase)) {
            $this->log->debug("[ORTHANC] Gateway Mode: routing modifyStudyAccession through Go Converter API...");
            $payload = [
                'Replace' => [
                    'AccessionNumber' => $acsn
                ],
                'KeepSource' => false
            ];
            $res = $this->converterRequest('POST', "/api/v1/studies/{$studyId}/modify", $payload);
            if ($res['success']) {
                $newStudyId = $res['data']['ID'] ?? null;
                if ($newStudyId) {
                    $this->log->info("[ORTHANC] Gateway modify success. New Study ID: '{$newStudyId}'");
                    return $newStudyId;
                }
            }
            $this->log->warning("[ORTHANC] Gateway modify failed, falling back to direct Orthanc API call...");
        }

        // Direct call to Orthanc API
        $payload = [
            'Replace' => [
                'AccessionNumber' => $acsn
            ],
            'KeepSource' => false
        ];

        $res = $this->orthancRequest('POST', "/studies/{$studyId}/modify", $payload);
        if ($res['success']) {
            $newStudyId = $res['data']['ID'] ?? null;
            $this->log->info("[ORTHANC] Direct modify success. New Study ID: '{$newStudyId}'");
            return $newStudyId;
        }

        return null;
    }

    /**
     * Commands Orthanc to C-STORE/transfer the study to the Kemenkes DICOM Router modality.
     */
    public function sendToModality(string $studyId, string $modality): bool
    {
        $this->log->info("[ORTHANC] Triggering C-STORE route for Study '{$studyId}' to modality '{$modality}'...");

        $res = $this->orthancRequest('POST', "/modalities/{$modality}/store", [$studyId]);
        if ($res['success']) {
            $this->log->info("[ORTHANC] C-STORE request successfully sent for Study '{$studyId}'");
            return true;
        }

        $this->log->error("[ORTHANC] C-STORE request failed for Study '{$studyId}' to Modality '{$modality}'");
        return false;
    }

    /**
     * Sends a list of remote webapps image URLs to go-dcm for conversion & PACS upload.
     * Polls the job status until completion. Returns the resolved Orthanc Study ID on success.
     */
    public function sendToDicomConverterFromUrls(array $urls, array $parameters, array $modify): ?string
    {
        $this->log->info("[CONVERTER] Sending " . count($urls) . " URLs to go-dcm converter...");

        $payload = [
            'filetype' => 'img',
            'urls' => $urls,
            'parameters' => $parameters,
            'orthanc_modify' => $modify
        ];

        $res = $this->converterRequest('POST', '/api/v1/send-to-orthanc-from-urls', $payload);
        if (!$res['success']) {
            $this->log->error("[CONVERTER] Initial send-to-orthanc-from-urls call failed.");
            return null;
        }

        $jobId = $res['data']['job_id'] ?? null;
        if (!$jobId) {
            $this->log->error("[CONVERTER] No Job ID returned from converter API.");
            return null;
        }

        return $this->pollJobStatus($jobId);
    }

    /**
     * Polls the go-dcm job status endpoint.
     */
    private function pollJobStatus(string $jobId): ?string
    {
        $this->log->info("[CONVERTER] Polling status for Job ID '{$jobId}'...");

        $maxAttempts = 150; // 5 minutes max (150 * 2 seconds)
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            sleep(2);

            $res = $this->converterRequest('GET', "/api/v1/jobs/{$jobId}");
            if (!$res['success']) {
                $this->log->warning("[CONVERTER] Failed to poll job status for '{$jobId}' (attempt {$attempt})");
                continue;
            }

            $jobData = $res['data'] ?? [];
            $status = $jobData['status'] ?? 'PENDING';
            $this->log->debug("[CONVERTER] Job '{$jobId}' status (attempt {$attempt}): {$status}");

            if ($status === 'COMPLETED') {
                $resultNode = $jobData['result'] ?? [];
                // Typically contains {"ID": "new_orthanc_study_id", ...}
                $studyId = $resultNode['ID'] ?? null;
                if (!$studyId) {
                    // Try case-insensitive lookup
                    $studyId = $resultNode['id'] ?? null;
                }
                $this->log->info("[CONVERTER] Job completed successfully! Study ID resolved: '{$studyId}'");
                return $studyId;
            }

            if ($status === 'FAILED') {
                $errorMsg = $jobData['error'] ?? 'Unknown converter worker error';
                $this->log->error("[CONVERTER] Job '{$jobId}' failed: {$errorMsg}");
                return null;
            }
        }

        $this->log->error("[CONVERTER] Job '{$jobId}' timed out after 5 minutes.");
        return null;
    }
}
