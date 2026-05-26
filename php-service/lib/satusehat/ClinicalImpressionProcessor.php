<?php

/**
 * ClinicalImpressionProcessor - Orchestrator for Satu Sehat ClinicalImpression sync.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class SatuSehatClinicalImpressionProcessor
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
        $this->log->info("[SYNC] Phase 1: POST New ClinicalImpression");
        $this->processActive($dateFrom, $dateTo, $activeRecords);

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 2: PUT Update ClinicalImpression");
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
            $records = $this->db->fetchPendingClinicalImpressionActive($dateFrom, $dateTo);
        }
        
        if (empty($records)) {
            $this->log->info("[PHASE 1] No pending clinical impressions to POST.");
            return;
        }

        $this->log->info("[PHASE 1] Found " . count($records) . " record(s) to POST.");

        foreach ($records as $p) {
            $noRawat = $p['no_rawat'];
            $tglPerawatan = $p['tgl_perawatan'];
            $jamRawat = $p['jam_rawat'];
            $status = $p['status_lanjut'];
            $kdPenyakit = $p['kd_penyakit'];

            $idPasien = $this->db->getIhsPatient($p['nik_pasien']);
            $idDokter = $this->db->getIhsPractitioner($p['nik_praktisi']);

            if (!$idPasien || !$idDokter) {
                $this->log->warning("[PHASE 1] {$noRawat} [{$tglPerawatan} {$jamRawat}]: Missing IHS ID for Patient or Practitioner. Skipped.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::clinicalImpression(
                $p,
                $idPasien,
                $idDokter
            );

            $this->log->info("[PHASE 1] {$noRawat} [{$tglPerawatan} {$jamRawat}]: POST /ClinicalImpression (ICD-10: {$kdPenyakit})");
            $result = $this->api->post('/ClinicalImpression', $payload);

            if ($result['success'] && isset($result['data']['id'])) {
                $idClinImp = $result['data']['id'];
                $this->db->saveClinicalImpression($noRawat, $tglPerawatan, $jamRawat, $status, $idClinImp);
                $this->db->updateClinicalImpressionLocalState($noRawat, $tglPerawatan, $jamRawat, $status, 'active');
                $this->log->info("[PHASE 1] {$noRawat} [{$tglPerawatan} {$jamRawat}]: ✓ Created ClinicalImpression {$idClinImp}");
                $this->successCount++;
            } else {
                $errorMessage = $result['data']['issue'][0]['diagnostics'] ?? $result['message'];
                
                // Duplicate Handling Fallback
                if (stripos($errorMessage, 'duplicate') !== false || $result['code'] === 409 || $result['code'] === 400) {
                    $this->log->warning("[PHASE 1] {$noRawat} [{$tglPerawatan} {$jamRawat}]: Duplicated ClinicalImpression detected. Searching existing records...");
                    $idClinImp = $this->resolveDuplicateClinicalImpression($idPasien, $p['id_encounter'], $kdPenyakit);

                    if ($idClinImp) {
                        $this->db->saveClinicalImpression($noRawat, $tglPerawatan, $jamRawat, $status, $idClinImp);
                        $this->db->updateClinicalImpressionLocalState($noRawat, $tglPerawatan, $jamRawat, $status, 'active');
                        $this->log->info("[PHASE 1] {$noRawat} [{$tglPerawatan} {$jamRawat}]: ✓ Recovered ClinicalImpression {$idClinImp} from Satu Sehat");
                        $this->successCount++;
                    } else {
                        $this->log->error("[PHASE 1] {$noRawat} [{$tglPerawatan} {$jamRawat}]: ✗ Failed to recover duplicate ClinicalImpression.");
                        $this->failCount++;
                    }
                } else {
                    $this->log->warning("[PHASE 1] {$noRawat} [{$tglPerawatan} {$jamRawat}]: ✗ Failed -> " . $errorMessage);
                    $this->failCount++;
                }
            }
        }
    }

    private function processUpdate(string $dateFrom, string $dateTo, ?array $records = null): void
    {
        if ($records === null) {
            $records = $this->db->fetchPendingClinicalImpressionUpdate($dateFrom, $dateTo);
        }

        if (empty($records)) {
            $this->log->info("[PHASE 2] No pending clinical impressions to PUT.");
            return;
        }

        $this->log->info("[PHASE 2] Found " . count($records) . " record(s) to PUT.");

        foreach ($records as $p) {
            $noRawat = $p['no_rawat'];
            $tglPerawatan = $p['tgl_perawatan'];
            $jamRawat = $p['jam_rawat'];
            $status = $p['status_lanjut'];
            $kdPenyakit = $p['kd_penyakit'];

            $localState = $this->db->getClinicalImpressionLocalState($noRawat, $tglPerawatan, $jamRawat, $status);

            if ($localState === 'updated') {
                $this->skipCount++;
                continue;
            }

            $idPasien = $this->db->getIhsPatient($p['nik_pasien']);
            $idDokter = $this->db->getIhsPractitioner($p['nik_praktisi']);

            if (!$idPasien || !$idDokter) {
                $this->log->warning("[PHASE 2] {$noRawat} [{$tglPerawatan} {$jamRawat}]: Missing IHS ID. Skipped.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::clinicalImpression(
                $p,
                $idPasien,
                $idDokter,
                $p['id_clinicalimpression']
            );

            $this->log->info("[PHASE 2] {$noRawat} [{$tglPerawatan} {$jamRawat}]: PUT /ClinicalImpression/{$p['id_clinicalimpression']} (ICD-10: {$kdPenyakit})");
            $result = $this->api->put("/ClinicalImpression/{$p['id_clinicalimpression']}", $payload);

            if ($result['success']) {
                $this->db->updateClinicalImpressionLocalState($noRawat, $tglPerawatan, $jamRawat, $status, 'updated');
                $this->log->info("[PHASE 2] {$noRawat} [{$tglPerawatan} {$jamRawat}]: ✓ Updated ClinicalImpression {$p['id_clinicalimpression']}");
                $this->successCount++;
            } else {
                $this->log->warning("[PHASE 2] {$noRawat} [{$tglPerawatan} {$jamRawat}]: ✗ Failed -> " . ($result['data']['issue'][0]['diagnostics'] ?? $result['message']));
                $this->failCount++;
            }
        }
    }

    private function resolveDuplicateClinicalImpression(string $idPasien, string $idEncounter, string $kdPenyakit): ?string
    {
        $endpoint = "/ClinicalImpression?patient={$idPasien}&encounter={$idEncounter}";
        $result = $this->api->get($endpoint);

        if (!$result['success'] || empty($result['data']['entry'])) {
            return null;
        }

        foreach ($result['data']['entry'] as $entry) {
            $res = $entry['resource'] ?? [];
            
            // Check if finding itemReference code matches
            $resCode = $res['finding'][0]['itemCodeableConcept']['coding'][0]['code'] ?? '';
            
            if ($resCode === $kdPenyakit) {
                return $res['id'];
            }
        }

        return null;
    }
}
