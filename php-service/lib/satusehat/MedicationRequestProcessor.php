<?php

/**
 * MedicationRequestProcessor - Orchestrator for Satu Sehat MedicationRequest sync.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class SatuSehatMedicationRequestProcessor
{
    private SatuSehatDatabase $db;
    private SatuSehatClient $api;
    private SatuSehatConfig $config;
    private Logger $log;

    private int $successCount = 0;
    private int $failCount    = 0;
    private int $skipCount    = 0;

    public function __construct(
        SatuSehatDatabase $db, 
        SatuSehatClient $api, 
        SatuSehatConfig $config, 
        Logger $log
    ) {
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
        $this->log->info("[SYNC] Phase 1: POST New MedicationRequest");
        $this->processActive($dateFrom, $dateTo, $activeRecords);

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 2: PUT Update MedicationRequest");
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
            $records = $this->db->fetchPendingMedicationRequestActive($dateFrom, $dateTo);
        }
        
        if (empty($records)) {
            $this->log->info("[PHASE 1] No pending MedicationRequest to POST.");
            return;
        }

        $this->log->info("[PHASE 1] Found " . count($records) . " medication request record(s) to POST.");

        foreach ($records as $medreq) {
            $noResep = $medreq['no_resep'];
            $kodeBrng = $medreq['kode_brng'];
            $noRacik = $medreq['no_racik'];
            $isRacikan = (bool)$medreq['is_racikan'];

            $nikPasien = $medreq['no_ktp'];
            $nikPraktisi = $medreq['ktppraktisi'];

            $idPasien = $this->db->getIhsPatient($nikPasien);
            if (!$idPasien) {
                $this->log->warning("[PHASE 1] {$noResep}: Missing IHS ID for Patient (NIK: {$nikPasien}). Skipped.");
                $this->skipCount++;
                continue;
            }

            $idDokter = $this->db->getIhsPractitioner($nikPraktisi);
            if (!$idDokter) {
                $this->log->warning("[PHASE 1] {$noResep}: Missing IHS ID for Practitioner (NIK: {$nikPraktisi}). Skipped.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::medicationRequest(
                $this->config->orgId,
                $medreq,
                $idPasien,
                $idDokter
            );

            $label = $isRacikan ? "{$noResep}-{$noRacik}" : "{$noResep}";
            $this->log->info("[PHASE 1] {$label}: POST /MedicationRequest (Drug: {$medreq['obat_display']})");
            $result = $this->api->post('/MedicationRequest', $payload);

            if ($result['success'] && isset($result['data']['id'])) {
                $idMedicationRequest = $result['data']['id'];
                $this->db->saveMedicationRequest($noResep, $kodeBrng, $noRacik, $idMedicationRequest, $isRacikan);
                $this->db->updateMedicationRequestLocalState($noResep, $kodeBrng, $noRacik, 'active');
                $this->log->info("[PHASE 1] {$label}: ✓ Created MedicationRequest {$idMedicationRequest}");
                $this->successCount++;
            } else {
                $errorMessage = $result['data']['issue'][0]['diagnostics'] ?? $result['message'];
                
                // Duplicate Handling Fallback
                if (stripos($errorMessage, 'duplicate') !== false || $result['code'] === 409 || $result['code'] === 400) {
                    $this->log->warning("[PHASE 1] {$label}: Duplicated MedicationRequest detected. Searching existing records...");
                    $idMedicationRequest = $this->resolveDuplicateMedicationRequest($noResep, $noRacik, $isRacikan);

                    if ($idMedicationRequest) {
                        $this->db->saveMedicationRequest($noResep, $kodeBrng, $noRacik, $idMedicationRequest, $isRacikan);
                        $this->db->updateMedicationRequestLocalState($noResep, $kodeBrng, $noRacik, 'active');
                        $this->log->info("[PHASE 1] {$label}: ✓ Recovered MedicationRequest {$idMedicationRequest} from Satu Sehat API");
                        $this->successCount++;
                    } else {
                        $this->log->error("[PHASE 1] {$label}: ✗ Failed to recover duplicate MedicationRequest.");
                        $this->failCount++;
                    }
                } else {
                    $this->log->warning("[PHASE 1] {$label}: ✗ Failed -> " . $errorMessage);
                    $this->failCount++;
                }
            }
        }
    }

    private function processUpdate(string $dateFrom, string $dateTo, ?array $records = null): void
    {
        if ($records === null) {
            $records = $this->db->fetchPendingMedicationRequestUpdate($dateFrom, $dateTo);
        }

        if (empty($records)) {
            $this->log->info("[PHASE 2] No pending MedicationRequest to PUT.");
            return;
        }

        $this->log->info("[PHASE 2] Found " . count($records) . " medication request record(s) to PUT.");

        foreach ($records as $medreq) {
            $noResep = $medreq['no_resep'];
            $kodeBrng = $medreq['kode_brng'];
            $noRacik = $medreq['no_racik'];
            $isRacikan = (bool)$medreq['is_racikan'];
            $idMedicationRequest = $medreq['id_medicationrequest'];

            $localState = $this->db->getMedicationRequestLocalState($noResep, $kodeBrng, $noRacik);

            if ($localState === 'updated') {
                $this->skipCount++;
                continue;
            }

            $nikPasien = $medreq['no_ktp'];
            $nikPraktisi = $medreq['ktppraktisi'];

            $idPasien = $this->db->getIhsPatient($nikPasien);
            if (!$idPasien) {
                $this->log->warning("[PHASE 2] {$noResep}: Missing IHS ID for Patient (NIK: {$nikPasien}). Skipped.");
                $this->skipCount++;
                continue;
            }

            $idDokter = $this->db->getIhsPractitioner($nikPraktisi);
            if (!$idDokter) {
                $this->log->warning("[PHASE 2] {$noResep}: Missing IHS ID for Practitioner (NIK: {$nikPraktisi}). Skipped.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::medicationRequest(
                $this->config->orgId,
                $medreq,
                $idPasien,
                $idDokter,
                $idMedicationRequest
            );

            $label = $isRacikan ? "{$noResep}-{$noRacik}" : "{$noResep}";
            $this->log->info("[PHASE 2] {$label}: PUT /MedicationRequest/{$idMedicationRequest} (Drug: {$medreq['obat_display']})");
            $result = $this->api->put("/MedicationRequest/{$idMedicationRequest}", $payload);

            if ($result['success']) {
                $this->db->updateMedicationRequestLocalState($noResep, $kodeBrng, $noRacik, 'updated');
                $this->log->info("[PHASE 2] {$label}: ✓ Updated MedicationRequest {$idMedicationRequest}");
                $this->successCount++;
            } else {
                $this->log->warning("[PHASE 2] {$label}: ✗ Failed -> " . ($result['data']['issue'][0]['diagnostics'] ?? $result['message']));
                $this->failCount++;
            }
        }
    }

    /**
     * Resolves a duplicate MedicationRequest by searching the Satu Sehat API.
     */
    private function resolveDuplicateMedicationRequest(string $noResep, string $noRacik, bool $isRacikan): ?string
    {
        $prescVal = $noResep;
        if ($isRacikan && $noRacik !== '') {
            $prescVal = $noResep . '-' . $noRacik;
        }

        $endpoint = "/MedicationRequest?identifier=http://sys-ids.kemkes.go.id/prescription/{$this->config->orgId}|" . urlencode($prescVal);
        $result = $this->api->get($endpoint);

        if (!$result['success'] || empty($result['data']['entry'])) {
            return null;
        }

        foreach ($result['data']['entry'] as $entry) {
            $res = $entry['resource'] ?? [];
            if (isset($res['id'])) {
                return $res['id']; // Match found
            }
        }

        return null;
    }
}
