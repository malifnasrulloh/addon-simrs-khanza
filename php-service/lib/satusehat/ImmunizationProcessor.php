<?php

/**
 * ImmunizationProcessor - Orchestrator for Satu Sehat Immunization sync.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class SatuSehatImmunizationProcessor
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
        $this->log->info("[SYNC] Phase 1: POST New Immunization");
        $this->processActive($dateFrom, $dateTo, $activeRecords);

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 2: PUT Update Immunization");
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
            $records = $this->db->fetchPendingImmunizationActive($dateFrom, $dateTo);
        }
        
        if (empty($records)) {
            $this->log->info("[PHASE 1] No pending Immunization to POST.");
            return;
        }

        $this->log->info("[PHASE 1] Found " . count($records) . " immunization record(s) to POST.");

        foreach ($records as $imm) {
            $noRawat = $imm['no_rawat'];
            $tglPerawatan = $imm['tgl_perawatan'];
            $jam = $imm['jam'];
            $kodeBrng = $imm['kode_brng'];
            $noBatch = $imm['no_batch'];
            $noFaktur = $imm['no_faktur'];

            // Skip records already successfully processed or permanently failed
            $localState = $this->db->getImmunizationLocalState($noRawat, $tglPerawatan, $jam, $kodeBrng, $noBatch, $noFaktur);
            if (in_array($localState, ['active', 'updated', 'privacy_error', 'failed_rule', 'invalid_code'], true)) {
                $this->skipCount++;
                continue;
            }

            $nikPasien = $imm['no_ktp'];
            $nikPraktisi = $imm['ktppraktisi'];

            $idPasien = $this->db->getIhsPatient($nikPasien);
            if (!$idPasien) {
                $this->log->warning("[PHASE 1] {$noRawat}: Missing IHS ID for Patient (NIK: {$nikPasien}). Skipped.");
                $this->skipCount++;
                continue;
            }

            $idDokter = $this->db->getIhsPractitioner($nikPraktisi);
            if (!$idDokter) {
                $this->log->warning("[PHASE 1] {$noRawat}: Missing IHS ID for Practitioner (NIK: {$nikPraktisi}). Skipped.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::immunization(
                $imm,
                $idPasien,
                $idDokter
            );

            $this->log->info("[PHASE 1] {$noRawat}: POST /Immunization (Vaccine Code: {$imm['vaksin_code']}, Batch: {$noBatch})");
            $result = $this->api->post('/Immunization', $payload);

            if ($result['success'] && isset($result['data']['id'])) {
                $idImmunization = $result['data']['id'];
                $this->db->saveImmunization($noRawat, $tglPerawatan, $jam, $kodeBrng, $noBatch, $noFaktur, $idImmunization);
                $this->db->updateImmunizationLocalState($noRawat, $tglPerawatan, $jam, $kodeBrng, $noBatch, $noFaktur, 'active');
                $this->log->info("[PHASE 1] {$noRawat}: ✓ Created Immunization {$idImmunization}");
                $this->successCount++;
            } else {
                $errorMessage = $result['data']['issue'][0]['diagnostics'] ?? $result['message'];
                
                // Duplicate Handling Fallback
                if (stripos($errorMessage, 'duplicate') !== false || $result['code'] === 409) {
                    $this->log->warning("[PHASE 1] {$noRawat}: Duplicated Immunization detected. Searching existing records...");
                    $idImmunization = $this->resolveDuplicateImmunization($idPasien, $imm['id_encounter'], $imm['vaksin_code'], $noBatch);

                    if ($idImmunization) {
                        $this->db->saveImmunization($noRawat, $tglPerawatan, $jam, $kodeBrng, $noBatch, $noFaktur, $idImmunization);
                        $this->db->updateImmunizationLocalState($noRawat, $tglPerawatan, $jam, $kodeBrng, $noBatch, $noFaktur, 'active');
                        $this->log->info("[PHASE 1] {$noRawat}: ✓ Recovered Immunization {$idImmunization} from Satu Sehat API");
                        $this->successCount++;
                    } else {
                        $this->log->error("[PHASE 1] {$noRawat}: ✗ Failed to recover duplicate Immunization.");
                        $this->failCount++;
                    }
                } else {
                    // Cache permanent API failures
                    $isPrivacy = (stripos($errorMessage, 'consent') !== false || stripos($errorMessage, 'privacy') !== false);
                    $isRule = (stripos($errorMessage, 'Rule Number') !== false || stripos($errorMessage, 'rule violation') !== false);
                    $isInvalidCode = (stripos($errorMessage, 'not found in value set') !== false || stripos($errorMessage, 'invalid code') !== false);

                    if ($isPrivacy) {
                        $this->db->updateImmunizationLocalState($noRawat, $tglPerawatan, $jam, $kodeBrng, $noBatch, $noFaktur, 'privacy_error');
                        $this->log->warning("[PHASE 1] {$noRawat}: ✗ Skipped permanently due to consent/privacy restrictions.");
                    } elseif ($isRule) {
                        $this->db->updateImmunizationLocalState($noRawat, $tglPerawatan, $jam, $kodeBrng, $noBatch, $noFaktur, 'failed_rule');
                        $this->log->warning("[PHASE 1] {$noRawat}: ✗ Skipped permanently due to Satu Sehat business rules.");
                    } elseif ($isInvalidCode) {
                        $this->db->updateImmunizationLocalState($noRawat, $tglPerawatan, $jam, $kodeBrng, $noBatch, $noFaktur, 'invalid_code');
                        $this->log->warning("[PHASE 1] {$noRawat}: ✗ Skipped permanently due to invalid vaccine code mapping.");
                    } else {
                        $this->log->warning("[PHASE 1] {$noRawat}: ✗ Failed -> " . $errorMessage);
                    }
                    $this->failCount++;
                }
            }
        }
    }

    private function processUpdate(string $dateFrom, string $dateTo, ?array $records = null): void
    {
        if ($records === null) {
            $records = $this->db->fetchPendingImmunizationUpdate($dateFrom, $dateTo);
        }

        if (empty($records)) {
            $this->log->info("[PHASE 2] No pending Immunization to PUT.");
            return;
        }

        $this->log->info("[PHASE 2] Found " . count($records) . " immunization record(s) to PUT.");

        foreach ($records as $imm) {
            $noRawat = $imm['no_rawat'];
            $tglPerawatan = $imm['tgl_perawatan'];
            $jam = $imm['jam'];
            $kodeBrng = $imm['kode_brng'];
            $noBatch = $imm['no_batch'];
            $noFaktur = $imm['no_faktur'];
            $idImmunization = $imm['id_immunization'];

            $localState = $this->db->getImmunizationLocalState($noRawat, $tglPerawatan, $jam, $kodeBrng, $noBatch, $noFaktur);

            if (in_array($localState, ['updated', 'privacy_error', 'failed_rule', 'invalid_code'], true)) {
                $this->skipCount++;
                continue;
            }

            $nikPasien = $imm['no_ktp'];
            $nikPraktisi = $imm['ktppraktisi'];

            $idPasien = $this->db->getIhsPatient($nikPasien);
            if (!$idPasien) {
                $this->log->warning("[PHASE 2] {$noRawat}: Missing IHS ID for Patient (NIK: {$nikPasien}). Skipped.");
                $this->skipCount++;
                continue;
            }

            $idDokter = $this->db->getIhsPractitioner($nikPraktisi);
            if (!$idDokter) {
                $this->log->warning("[PHASE 2] {$noRawat}: Missing IHS ID for Practitioner (NIK: {$nikPraktisi}). Skipped.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::immunization(
                $imm,
                $idPasien,
                $idDokter,
                $idImmunization
            );

            $this->log->info("[PHASE 2] {$noRawat}: PUT /Immunization/{$idImmunization} (Vaccine Code: {$imm['vaksin_code']})");
            $result = $this->api->put("/Immunization/{$idImmunization}", $payload);

            if ($result['success']) {
                $this->db->updateImmunizationLocalState($noRawat, $tglPerawatan, $jam, $kodeBrng, $noBatch, $noFaktur, 'updated');
                $this->log->info("[PHASE 2] {$noRawat}: ✓ Updated Immunization {$idImmunization}");
                $this->successCount++;
            } else {
                $errorMessage = $result['data']['issue'][0]['diagnostics'] ?? $result['message'];

                // Cache permanent API failures
                $isPrivacy = (stripos($errorMessage, 'consent') !== false || stripos($errorMessage, 'privacy') !== false);
                $isRule = (stripos($errorMessage, 'Rule Number') !== false || stripos($errorMessage, 'rule violation') !== false);
                $isInvalidCode = (stripos($errorMessage, 'not found in value set') !== false || stripos($errorMessage, 'invalid code') !== false);

                if ($isPrivacy) {
                    $this->db->updateImmunizationLocalState($noRawat, $tglPerawatan, $jam, $kodeBrng, $noBatch, $noFaktur, 'privacy_error');
                    $this->log->warning("[PHASE 2] {$noRawat}: ✗ Skipped permanently due to consent/privacy restrictions.");
                } elseif ($isRule) {
                    $this->db->updateImmunizationLocalState($noRawat, $tglPerawatan, $jam, $kodeBrng, $noBatch, $noFaktur, 'failed_rule');
                    $this->log->warning("[PHASE 2] {$noRawat}: ✗ Skipped permanently due to Satu Sehat business rules.");
                } elseif ($isInvalidCode) {
                    $this->db->updateImmunizationLocalState($noRawat, $tglPerawatan, $jam, $kodeBrng, $noBatch, $noFaktur, 'invalid_code');
                    $this->log->warning("[PHASE 2] {$noRawat}: ✗ Skipped permanently due to invalid vaccine code mapping.");
                } else {
                    $this->log->warning("[PHASE 2] {$noRawat}: ✗ Failed -> " . $errorMessage);
                }
                $this->failCount++;
            }
        }
    }

    /**
     * Resolves a duplicate Immunization by searching the Satu Sehat API.
     */
    private function resolveDuplicateImmunization(string $idPasien, string $idEncounter, string $vaccineCode, string $lotNumber): ?string
    {
        $endpoint = "/Immunization?patient={$idPasien}&encounter={$idEncounter}";
        $result = $this->api->get($endpoint);

        if (!$result['success'] || empty($result['data']['entry'])) {
            return null;
        }

        foreach ($result['data']['entry'] as $entry) {
            $res = $entry['resource'] ?? [];
            
            // Match Vaccine code
            $resCode = $res['vaccineCode']['coding'][0]['code'] ?? '';
            $resLot  = $res['lotNumber'] ?? '';
            
            if ($resCode === $vaccineCode && $resLot === $lotNumber) {
                return $res['id']; // Match found
            }
        }

        return null;
    }
}
