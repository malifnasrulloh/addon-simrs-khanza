<?php

/**
 * ConditionProcessor - Orchestrator for Satu Sehat Condition sync.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class SatuSehatConditionProcessor
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

    public function run(): array
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
        $this->log->info("[SYNC] Phase 1: POST New Condition");
        $this->processActive($dateFrom, $dateTo);

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 2: PUT Update Condition");
        $this->processUpdate($dateFrom, $dateTo);

        return [
            'success' => $this->successCount,
            'fail'    => $this->failCount,
            'skip'    => $this->skipCount,
        ];
    }

    private function processActive(string $dateFrom, string $dateTo): void
    {
        $patients = $this->db->fetchPendingConditionActive($dateFrom, $dateTo);
        
        if (empty($patients)) {
            $this->log->info("[PHASE 1] No pending conditions to POST.");
            return;
        }

        $this->log->info("[PHASE 1] Found " . count($patients) . " diagnosis record(s) to POST.");

        foreach ($patients as $p) {
            $noRawat = $p['no_rawat'];
            $kdPenyakit = $p['kd_penyakit'];
            $statusRawat = $p['status'];

            $nik = $p['no_ktp'];

            $idPasien = $this->db->getIhsPatient($nik);

            if (!$idPasien) {
                $this->log->warning("[PHASE 1] {$noRawat}: Missing IHS ID for Patient. Skipped.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::condition(
                $p,
                $idPasien
            );

            $this->log->info("[PHASE 1] {$noRawat}: POST /Condition (ICD: {$kdPenyakit})");
            $result = $this->api->post('/Condition', $payload);

            if ($result['success'] && isset($result['data']['id'])) {
                $idCondition = $result['data']['id'];
                $this->db->saveCondition($noRawat, $kdPenyakit, $statusRawat, $idCondition);
                $this->db->updateConditionLocalState($noRawat, $kdPenyakit, 'active');
                $this->log->info("[PHASE 1] {$noRawat}: ✓ Created Condition {$idCondition}");
                $this->successCount++;
            } else {
                $errorMessage = $result['data']['issue'][0]['diagnostics'] ?? $result['message'];
                
                // Duplicate Handling Fallback
                if (stripos($errorMessage, 'duplicate') !== false || $result['code'] === 409 || $result['code'] === 400) {
                    $this->log->warning("[PHASE 1] {$noRawat}: Duplicated Condition detected. Searching existing records...");
                    $idCondition = $this->resolveDuplicateCondition($idPasien, $p['id_encounter'], $kdPenyakit);

                    if ($idCondition) {
                        $this->db->saveCondition($noRawat, $kdPenyakit, $statusRawat, $idCondition);
                        $this->db->updateConditionLocalState($noRawat, $kdPenyakit, 'active');
                        $this->log->info("[PHASE 1] {$noRawat}: ✓ Recovered Condition {$idCondition} from BPJS");
                        $this->successCount++;
                    } else {
                        $this->log->error("[PHASE 1] {$noRawat}: ✗ Failed to recover duplicate Condition.");
                        $this->failCount++;
                    }
                } else {
                    $this->log->warning("[PHASE 1] {$noRawat}: ✗ Failed -> " . $errorMessage);
                    $this->failCount++;
                }
            }
        }
    }

    private function processUpdate(string $dateFrom, string $dateTo): void
    {
        $patients = $this->db->fetchPendingConditionUpdate($dateFrom, $dateTo);

        if (empty($patients)) {
            $this->log->info("[PHASE 2] No pending conditions to PUT.");
            return;
        }

        $this->log->info("[PHASE 2] Found " . count($patients) . " diagnosis record(s) to PUT.");

        foreach ($patients as $p) {
            $noRawat = $p['no_rawat'];
            $kdPenyakit = $p['kd_penyakit'];
            $statusRawat = $p['status'];
            $localState = $this->db->getConditionLocalState($noRawat, $kdPenyakit);

            if ($localState === 'updated') {
                $this->skipCount++;
                continue;
            }

            $nik = $p['no_ktp'];

            $idPasien = $this->db->getIhsPatient($nik);

            if (!$idPasien) {
                $this->log->warning("[PHASE 2] {$noRawat}: Missing IHS ID. Skipped.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::condition(
                $p,
                $idPasien,
                $p['id_condition']
            );

            $this->log->info("[PHASE 2] {$noRawat}: PUT /Condition/{$p['id_condition']} (ICD: {$kdPenyakit})");
            $result = $this->api->put("/Condition/{$p['id_condition']}", $payload);

            if ($result['success']) {
                $this->db->updateConditionLocalState($noRawat, $kdPenyakit, 'updated');
                $this->log->info("[PHASE 2] {$noRawat}: ✓ Updated Condition {$p['id_condition']}");
                $this->successCount++;
            } else {
                $this->log->warning("[PHASE 2] {$noRawat}: ✗ Failed -> " . ($result['data']['issue'][0]['diagnostics'] ?? $result['message']));
                $this->failCount++;
            }
        }
    }

    /**
     * Resolves a duplicate Condition by searching the Satu Sehat API.
     */
    private function resolveDuplicateCondition(string $idPasien, string $idEncounter, string $kdPenyakit): ?string
    {
        // Note: For Condition we query by patient and encounter.
        $endpoint = "/Condition?patient={$idPasien}&encounter={$idEncounter}";
        $result = $this->api->get($endpoint);

        if (!$result['success'] || empty($result['data']['entry'])) {
            return null;
        }

        foreach ($result['data']['entry'] as $entry) {
            $res = $entry['resource'] ?? [];
            
            // Look for matching ICD-10 code
            $resCode = $res['code']['coding'][0]['code'] ?? '';
            
            if ($resCode === $kdPenyakit) {
                return $res['id']; // Found the matching duplicate!
            }
        }

        return null;
    }
}