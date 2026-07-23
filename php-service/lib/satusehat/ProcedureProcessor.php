<?php

/**
 * ProcedureProcessor - Orchestrator for Satu Sehat Procedure sync.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class SatuSehatProcedureProcessor
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
        $this->log->info("[SYNC] Phase 1: POST New Procedure");
        $this->processActive($dateFrom, $dateTo, $activeRecords);

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 2: PUT Update Procedure");
        $this->processUpdate($dateFrom, $dateTo, $updateRecords);

        return [
            'success' => $this->successCount,
            'fail'    => $this->failCount,
            'skip'    => $this->skipCount,
        ];
    }

    private function processActive(string $dateFrom, string $dateTo, ?array $patients = null): void
    {
        if ($patients === null) {
            $patients = $this->db->fetchPendingProcedureActive($dateFrom, $dateTo);
        }

        if (empty($patients)) {
            $this->log->info("[PHASE 1] No pending procedures to POST.");
            return;
        }

        $this->log->info("[PHASE 1] Found " . count($patients) . " procedure record(s) to POST.");

        foreach ($patients as $p) {
            $noRawat = $p['no_rawat'];
            $kode = $p['kode'];
            $statusRawat = $p['status'];
            $idEncounter = $p['id_encounter'];

            $nik = $p['no_ktp'];

            $idPasien = $this->db->getIhsPatient($nik);

            if (!$idPasien) {
                $this->log->warning("[PHASE 1] {$noRawat}: Missing IHS ID for Patient. Skipped.");
                $this->skipCount++;
                continue;
            }

            // Look up practitioner for performer field (optional — not required)
            $idDokter = null;
            $namaDokter = null;
            if (!empty($p['ktp_dokter'])) {
                $idDokter = $this->db->getIhsPractitioner($p['ktp_dokter']);
                $namaDokter = $p['nama_dokter'] ?? '';
            }

            // Preemptive Duplicate Check: Search if there is already a remote Procedure for this patient, encounter and code to avoid duplicate POSTs
            $idProcedure = $this->resolveDuplicateProcedure($idPasien, $idEncounter, $kode);
            if ($idProcedure) {
                $this->db->saveProcedure($noRawat, $kode, $statusRawat, $idProcedure);
                $this->db->updateProcedureLocalState($noRawat, $kode, 'active');
                $this->log->info("[PHASE 1] {$noRawat}: ✓ Recovered existing Procedure {$idProcedure} from Satu Sehat (ICD-9: {$kode})");
                $this->successCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::procedure(
                $p,
                $idPasien,
                '',
                $idDokter,
                $namaDokter
            );

            $this->log->info("[PHASE 1] {$noRawat}: POST /Procedure (ICD-9: {$kode})");
            $result = $this->api->post('/Procedure', $payload);

            if ($result['success'] && isset($result['data']['id'])) {
                $idProcedure = $result['data']['id'];
                $this->db->saveProcedure($noRawat, $kode, $statusRawat, $idProcedure);
                $this->db->updateProcedureLocalState($noRawat, $kode, 'active');
                $this->log->info("[PHASE 1] {$noRawat}: ✓ Created Procedure {$idProcedure}");
                $this->successCount++;
            } else {
                $errorMessage = $result['data']['issue'][0]['diagnostics'] ?? $result['message'];

                // Cache permanent API failures
                $isPrivacy = (stripos($errorMessage, 'consent') !== false || stripos($errorMessage, 'privacy') !== false);
                $isRule = (stripos($errorMessage, 'rule') !== false || stripos($errorMessage, 'RuleNumber') !== false);
                $isCode = (stripos($errorMessage, 'code') !== false || stripos($errorMessage, 'system') !== false || stripos($errorMessage, 'terminology') !== false);

                if ($isPrivacy) {
                    $this->db->updateProcedureLocalState($noRawat, $kode, 'privacy_error');
                    $this->log->warning("[PHASE 1] {$noRawat}: ✗ Permanent Privacy Error -> {$errorMessage}");
                } elseif ($isRule) {
                    $this->db->updateProcedureLocalState($noRawat, $kode, 'failed_rule');
                    $this->log->warning("[PHASE 1] {$noRawat}: ✗ Permanent Rule Error -> {$errorMessage}");
                } elseif ($isCode) {
                    $this->db->updateProcedureLocalState($noRawat, $kode, 'invalid_code');
                    $this->log->warning("[PHASE 1] {$noRawat}: ✗ Permanent Code Error -> {$errorMessage}");
                } else {
                    $this->log->warning("[PHASE 1] {$noRawat}: ✗ Failed -> " . $errorMessage);
                }
                $this->failCount++;
            }
        }
    }

    private function processUpdate(string $dateFrom, string $dateTo, ?array $patients = null): void
    {
        if ($patients === null) {
            $patients = $this->db->fetchPendingProcedureUpdate($dateFrom, $dateTo);
        }

        if (empty($patients)) {
            $this->log->info("[PHASE 2] No pending procedures to PATCH.");
            return;
        }

        $this->log->info("[PHASE 2] Found " . count($patients) . " procedure record(s) to PATCH.");

        foreach ($patients as $p) {
            $noRawat = $p['no_rawat'];
            $kode = $p['kode'];
            $idProcedure = $p['id_procedure'];
            $localState = $this->db->getProcedureLocalState($noRawat, $kode);

            if ($localState === 'updated') {
                $this->skipCount++;
                continue;
            }

            $nik = $p['no_ktp'];
            $idPasien = $this->db->getIhsPatient($nik);
            if (!$idPasien) {
                $this->log->warning("[PHASE 2] {$noRawat}: Missing IHS ID for Patient. Skipped.");
                $this->skipCount++;
                continue;
            }

            $idDokter = null;
            if (!empty($p['ktp_dokter'])) {
                $idDokter = $this->db->getIhsPractitioner($p['ktp_dokter']);
            }

            $payload = SatuSehatPayloadBuilder::procedure(
                $p,
                $idPasien,
                $idProcedure,
                $idDokter,
                $p['nama_dokter'] ?? ''
            );
            $ops = SatuSehatPayloadBuilder::payloadToPatchOps($payload);

            $this->log->info("[PHASE 2] {$noRawat}: PATCH /Procedure/{$idProcedure} (" . count($ops) . " ops)");
            $result = $this->api->patch("/Procedure/{$idProcedure}", $ops);

            if ($result['success']) {
                $this->db->updateProcedureLocalState($noRawat, $kode, 'updated');
                $this->log->info("[PHASE 2] {$noRawat}: ✓ Updated Procedure {$idProcedure} via PATCH");
                $this->successCount++;
            } else {
                $this->log->warning("[PHASE 2] {$noRawat}: ✗ Failed -> " . ($result['data']['issue'][0]['diagnostics'] ?? $result['message']));
                $this->failCount++;
            }
        }
    }

    /**
     * Resolves a duplicate Procedure by searching the Satu Sehat API.
     */
    private function resolveDuplicateProcedure(string $idPasien, string $idEncounter, string $kode): ?string
    {
        // Query by patient and encounter.
        $endpoint = "/Procedure?patient={$idPasien}&encounter={$idEncounter}";
        $result = $this->api->get($endpoint);

        if (!$result['success'] || empty($result['data']['entry'])) {
            return null;
        }

        foreach ($result['data']['entry'] as $entry) {
            $res = $entry['resource'] ?? [];
            
            // Look for matching ICD-9 code
            $resCode = $res['code']['coding'][0]['code'] ?? '';
            
            if ($resCode === $kode) {
                return $res['id']; // Found the matching duplicate!
            }
        }

        return null;
    }
}