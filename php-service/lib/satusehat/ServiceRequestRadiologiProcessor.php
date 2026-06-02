<?php

/**
 * ServiceRequestRadiologiProcessor - Orchestrator for Satu Sehat ServiceRequest (Radiologi) sync.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class SatuSehatServiceRequestRadiologiProcessor
{
    private SatuSehatDatabase $db;
    private SatuSehatClient $api;
    private SatuSehatConfig $config;
    private Logger $log;

    private int $successCount = 0;
    private int $failCount    = 0;
    private int $skipCount    = 0;

    public function __construct(SatuSehatDatabase $db, SatuSehatClient $api, SatuSehatConfig $config, Logger $log)
    {
        $this->db     = $db;
        $this->api    = $api;
        $this->config = $config;
        $this->log    = $log;
    }

    public function run(?array $activeRecords = null, ?array $updateRecords = null): array
    {
        $this->successCount = 0;
        $this->failCount    = 0;
        $this->skipCount    = 0;

        if ($this->config->lookbackDays > 0) {
            $dateTo = date('Y-m-d', strtotime('-1 day'));
            $dateFrom = date('Y-m-d', strtotime('-' . $this->config->lookbackDays . ' days', strtotime(date('Y-m-d'))));
            $this->log->info("  Date Range: {$dateFrom} to {$dateTo} (Lookback: {$this->config->lookbackDays} days)");
        } else {
            $dateFrom = $this->config->dateFrom;
            $dateTo = $this->config->dateTo;
            $this->log->info("  Date Range: {$dateFrom} to {$dateTo} (Configured)");
        }

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 1: POST New ServiceRequest (Radiologi)");
        $this->processActive($dateFrom, $dateTo, $activeRecords);

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 2: PUT Update ServiceRequest (Radiologi)");
        $this->processUpdate($dateFrom, $dateTo, $updateRecords);

        return [
            'success' => $this->successCount,
            'fail'    => $this->failCount,
            'skip'    => $this->skipCount,
        ];
    }

    private function processActive(string $dateFrom, string $dateTo, ?array $records = null): void
    {
        if ($records === null) {
            $records = $this->db->fetchPendingServiceRequestRadiologiActive($dateFrom, $dateTo);
        }
        
        if (empty($records)) {
            $this->log->info("[PHASE 1] No pending ServiceRequests to POST.");
            return;
        }

        $this->log->info("[PHASE 1] Found " . count($records) . " record(s) to POST.");

        foreach ($records as $p) {
            $noorder = $p['noorder'];
            $kdJenisPrw = $p['kd_jenis_prw'];
            $nmPerawatan = $p['nm_perawatan'];

            $idPasien = $this->db->getIhsPatient($p['nik_pasien']);
            $idDokter = $this->db->getIhsPractitioner($p['nik_praktisi']);

            if (!$idPasien || !$idDokter) {
                $this->log->warning("[PHASE 1] {$noorder} [{$kdJenisPrw}]: Missing IHS ID for Patient or Practitioner. Skipped.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::serviceRequestRadiologi(
                $p,
                $idPasien,
                $idDokter,
                $this->config->organizationId
            );

            $this->log->info("[PHASE 1] {$noorder} [{$kdJenisPrw}]: POST /ServiceRequest ({$nmPerawatan})");
            $result = $this->api->post('/ServiceRequest', $payload);

            if ($result['success'] && isset($result['data']['id'])) {
                $idServiceRequest = $result['data']['id'];
                $this->db->saveServiceRequestRadiologi($noorder, $kdJenisPrw, $idServiceRequest);
                $this->log->info("[PHASE 1] {$noorder} [{$kdJenisPrw}]: ✓ Created ServiceRequest {$idServiceRequest}");
                $this->successCount++;
            } else {
                $errorMessage = $result['data']['issue'][0]['diagnostics'] ?? $result['message'];
                
                // Duplicate Handling Fallback using deterministic ACSN
                if (stripos($errorMessage, 'duplicate') !== false || $result['code'] === 409 || $result['code'] === 400) {
                    $acsn = SatuSehatPayloadBuilder::buildAcsn($noorder, $kdJenisPrw);
                    $this->log->warning("[PHASE 1] {$noorder} [{$kdJenisPrw}]: Duplicated ServiceRequest detected. Searching existing records for ACSN: {$acsn}...");
                    $idServiceRequest = $this->resolveDuplicateServiceRequest($acsn);

                    if ($idServiceRequest) {
                        $this->db->saveServiceRequestRadiologi($noorder, $kdJenisPrw, $idServiceRequest);
                        $this->log->info("[PHASE 1] {$noorder} [{$kdJenisPrw}]: ✓ Recovered ServiceRequest {$idServiceRequest} from Satu Sehat");
                        $this->successCount++;
                    } else {
                        $this->log->error("[PHASE 1] {$noorder} [{$kdJenisPrw}]: ✗ Failed to recover duplicate ServiceRequest.");
                        $this->failCount++;
                    }
                } else {
                    $this->log->warning("[PHASE 1] {$noorder} [{$kdJenisPrw}]: ✗ Failed -> " . $errorMessage);
                    $this->failCount++;
                }
            }
        }
    }

    private function processUpdate(string $dateFrom, string $dateTo, ?array $records = null): void
    {
        if ($records === null) {
            $records = $this->db->fetchPendingServiceRequestRadiologiUpdate($dateFrom, $dateTo);
        }

        if (empty($records)) {
            $this->log->info("[PHASE 2] No pending ServiceRequests to PUT.");
            return;
        }

        $this->log->info("[PHASE 2] Found " . count($records) . " record(s) to PUT.");

        foreach ($records as $p) {
            $noorder = $p['noorder'];
            $kdJenisPrw = $p['kd_jenis_prw'];
            $nmPerawatan = $p['nm_perawatan'];
            $idServiceRequest = $p['id_servicerequest'];

            $idPasien = $this->db->getIhsPatient($p['nik_pasien']);
            $idDokter = $this->db->getIhsPractitioner($p['nik_praktisi']);

            if (!$idPasien || !$idDokter) {
                $this->log->warning("[PHASE 2] {$noorder} [{$kdJenisPrw}]: Missing IHS ID. Skipped.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::serviceRequestRadiologi(
                $p,
                $idPasien,
                $idDokter,
                $this->config->organizationId,
                $idServiceRequest
            );

            $this->log->info("[PHASE 2] {$noorder} [{$kdJenisPrw}]: PUT /ServiceRequest/{$idServiceRequest} ({$nmPerawatan})");
            $result = $this->api->put("/ServiceRequest/{$idServiceRequest}", $payload);

            if ($result['success']) {
                $this->log->info("[PHASE 2] {$noorder} [{$kdJenisPrw}]: ✓ Updated ServiceRequest {$idServiceRequest}");
                $this->successCount++;
            } else {
                $this->log->warning("[PHASE 2] {$noorder} [{$kdJenisPrw}]: ✗ Failed -> " . ($result['data']['issue'][0]['diagnostics'] ?? $result['message']));
                $this->failCount++;
            }
        }
    }

    private function resolveDuplicateServiceRequest(string $acsn): ?string
    {
        $orgId = $this->config->organizationId;
        $endpoint = "/ServiceRequest?identifier=http://sys-ids.kemkes.go.id/acsn/{$orgId}|{$acsn}";
        $result = $this->api->get($endpoint);

        if (!$result['success'] || empty($result['data']['entry'])) {
            return null;
        }

        foreach ($result['data']['entry'] as $entry) {
            $res = $entry['resource'] ?? [];
            if (!empty($res['id'])) {
                return $res['id'];
            }
        }

        return null;
    }
}
