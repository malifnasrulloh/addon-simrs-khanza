<?php

/**
 * SIMRS Khanza - Satu Sehat MedicationStatement Processor
 *
 * Orchestrates dual-phase MedicationStatement synchronization (POST/PUT) with SQLite state cache
 * and defensive duplicate resolution.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 * @version 1.0.0
 */

declare(strict_types=1);

class SatuSehatMedicationStatementProcessor
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

        $this->log->info("  Date Range: {$this->config->dateFrom} to {$this->config->dateTo} (Configured)");

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 1: POST New MedicationStatement");
        $this->processActive($activeRecords);

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 2: PUT Update MedicationStatement");
        $this->processUpdate($updateRecords);

        return [
            'success' => $this->successCount,
            'fail'    => $this->failCount,
            'skip'    => $this->skipCount,
        ];
    }

    private function processActive(?array $records = null): void
    {
        if ($records === null) {
            $records = $this->db->fetchPendingMedicationStatementActive($this->config->dateFrom, $this->config->dateTo);
        }

        if (empty($records)) {
            $this->log->info("[PHASE 1] No pending MedicationStatement to POST.");
            return;
        }

        $this->log->info("[PHASE 1] Found " . count($records) . " MedicationStatement record(s) to POST.");

        foreach ($records as $p) {
            $noResep = $p['no_resep'];
            $kodeBrng = $p['kode_brng'];
            $noRacik = $p['no_racik'];
            $isRacikan = (bool)$p['is_racikan'];

            $localState = $this->db->getMedicationStatementLocalState($noResep, $kodeBrng, $noRacik);
            if (in_array($localState, ['active', 'updated', 'privacy_error', 'failed_rule', 'invalid_code'], true)) {
                $this->skipCount++;
                continue;
            }

            // Look up Patient ID
            $idPasien = $this->db->getIhsPatient($p['no_ktp']);
            if (!$idPasien) {
                $this->log->warning("[PHASE 1] [SKIPPED] Patient No.RM: {$p['no_rkm_medis']} (No.KTP: {$p['no_ktp']}) has no valid IHS ID.");
                $this->failCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::medicationStatement(
                $this->config->orgId,
                $p,
                $idPasien
            );

            $label = $isRacikan ? "Racikan #{$noRacik}" : "Non-Racikan";
            $this->log->info("[PHASE 1] [{$label}]: POST /MedicationStatement (Resep: {$noResep}, Kode Barang: {$kodeBrng})");
            $result = $this->api->post('/MedicationStatement', $payload);

            if ($result['success'] && isset($result['data']['id'])) {
                $idStatement = $result['data']['id'];
                $this->db->saveMedicationStatement($noResep, $kodeBrng, $noRacik, $idStatement, $isRacikan);
                $this->db->updateMedicationStatementLocalState($noResep, $kodeBrng, $noRacik, 'active');
                $this->log->info("[PHASE 1] Resep: {$noResep}: ✓ Created MedicationStatement {$idStatement}");
                $this->successCount++;
            } else {
                $errorMessage = $result['data']['issue'][0]['diagnostics'] ?? $result['message'];

                if (stripos($errorMessage, 'duplicate') !== false || $result['code'] === 409) {
                    $this->log->warning("[PHASE 1] Resep: {$noResep}: Duplicated MedicationStatement detected. Searching existing records...");
                    $idStatement = $this->resolveDuplicateMedicationStatement($noResep, $kodeBrng, $noRacik, $isRacikan);

                    if ($idStatement) {
                        $this->db->saveMedicationStatement($noResep, $kodeBrng, $noRacik, $idStatement, $isRacikan);
                        $this->db->updateMedicationStatementLocalState($noResep, $kodeBrng, $noRacik, 'active');
                        $this->log->info("[PHASE 1] Resep: {$noResep}: ✓ Recovered MedicationStatement {$idStatement} from Satu Sehat API");
                        $this->successCount++;
                    } else {
                        $this->log->error("[PHASE 1] Resep: {$noResep}: ✗ Failed to recover duplicate MedicationStatement.");
                        $this->failCount++;
                    }
                } else {
                    $isPrivacy = (stripos($errorMessage, 'consent') !== false || stripos($errorMessage, 'privacy') !== false);
                    $isRule = (stripos($errorMessage, 'rule') !== false || stripos($errorMessage, 'RuleNumber') !== false);
                    $isCode = (stripos($errorMessage, 'code') !== false || stripos($errorMessage, 'system') !== false || stripos($errorMessage, 'terminology') !== false);

                    if ($isPrivacy) {
                        $this->db->updateMedicationStatementLocalState($noResep, $kodeBrng, $noRacik, 'privacy_error');
                        $this->log->warning("[PHASE 1] Resep: {$noResep}: ✗ Permanent Privacy Error -> {$errorMessage}");
                    } elseif ($isRule) {
                        $this->db->updateMedicationStatementLocalState($noResep, $kodeBrng, $noRacik, 'failed_rule');
                        $this->log->warning("[PHASE 1] Resep: {$noResep}: ✗ Permanent Rule Error -> {$errorMessage}");
                    } elseif ($isCode) {
                        $this->db->updateMedicationStatementLocalState($noResep, $kodeBrng, $noRacik, 'invalid_code');
                        $this->log->warning("[PHASE 1] Resep: {$noResep}: ✗ Permanent Code Error -> {$errorMessage}");
                    } else {
                        $this->log->warning("[PHASE 1] Resep: {$noResep}: ✗ Failed -> " . $errorMessage);
                    }
                    $this->failCount++;
                }
            }
        }
    }

    private function processUpdate(?array $records = null): void
    {
        if ($records === null) {
            $records = $this->db->fetchPendingMedicationStatementUpdate($this->config->dateFrom, $this->config->dateTo);
        }

        if (empty($records)) {
            $this->log->info("[PHASE 2] No pending MedicationStatement to PATCH.");
            return;
        }

        $this->log->info("[PHASE 2] Found " . count($records) . " MedicationStatement record(s) to PATCH.");

        foreach ($records as $p) {
            $noResep = $p['no_resep'];
            $kodeBrng = $p['kode_brng'];
            $noRacik = $p['no_racik'];
            $isRacikan = (bool)$p['is_racikan'];
            $idStatement = $p['id_medicationstatement'];

            $localState = $this->db->getMedicationStatementLocalState($noResep, $kodeBrng, $noRacik);
            if (in_array($localState, ['updated', 'privacy_error', 'failed_rule', 'invalid_code'], true)) {
                $this->skipCount++;
                continue;
            }

            // Build PATCH operations — confirm completed status
            $payload = SatuSehatPayloadBuilder::medicationStatement(
                $this->config->orgId,
                $p,
                $idPasien,
                $idStatement
            );
            $ops = SatuSehatPayloadBuilder::payloadToPatchOps($payload);

            $label = $isRacikan ? "Racikan #{$noRacik}" : "Non-Racikan";
            $this->log->info("[PHASE 2] [{$label}]: PATCH /MedicationStatement/{$idStatement} (" . count($ops) . " ops)");
            $result = $this->api->patch("/MedicationStatement/{$idStatement}", $ops);

            if ($result['success']) {
                $this->db->updateMedicationStatementLocalState($noResep, $kodeBrng, $noRacik, 'updated');
                $this->log->info("[PHASE 2] Resep: {$noResep}: ✓ Updated MedicationStatement {$idStatement}");
                $this->successCount++;
            } else {
                $errorMessage = $result['data']['issue'][0]['diagnostics'] ?? $result['message'];
                $isPrivacy = (stripos($errorMessage, 'consent') !== false || stripos($errorMessage, 'privacy') !== false);
                $isRule = (stripos($errorMessage, 'rule') !== false || stripos($errorMessage, 'RuleNumber') !== false);
                $isCode = (stripos($errorMessage, 'code') !== false || stripos($errorMessage, 'system') !== false || stripos($errorMessage, 'terminology') !== false);

                if ($isPrivacy) {
                    $this->db->updateMedicationStatementLocalState($noResep, $kodeBrng, $noRacik, 'privacy_error');
                    $this->log->warning("[PHASE 2] Resep: {$noResep}: ✗ Permanent Privacy Error -> {$errorMessage}");
                } elseif ($isRule) {
                    $this->db->updateMedicationStatementLocalState($noResep, $kodeBrng, $noRacik, 'failed_rule');
                    $this->log->warning("[PHASE 2] Resep: {$noResep}: ✗ Permanent Rule Error -> {$errorMessage}");
                } elseif ($isCode) {
                    $this->db->updateMedicationStatementLocalState($noResep, $kodeBrng, $noRacik, 'invalid_code');
                    $this->log->warning("[PHASE 2] Resep: {$noResep}: ✗ Permanent Code Error -> {$errorMessage}");
                } else {
                    $this->log->warning("[PHASE 2] Resep: {$noResep}: ✗ Failed -> " . $errorMessage);
                }
                $this->failCount++;
            }
        }
    }

    /**
     * Resolves a duplicate MedicationStatement using Satu Sehat identifier checks.
     */
    private function resolveDuplicateMedicationStatement(
        string $noResep, 
        string $kodeBrng, 
        string $noRacik, 
        bool $isRacikan
    ): ?string {
        $valIdentifier = $noResep . '-' . $kodeBrng;
        if ($isRacikan && $noRacik !== '') {
            $valIdentifier .= '-' . $noRacik;
        }

        $endpoint = "/MedicationStatement?identifier=http://sys-ids.kemkes.go.id/medicationstatement/{$this->config->orgId}|" . urlencode($valIdentifier);
        $result = $this->api->get($endpoint);

        if ($result['success'] && !empty($result['data']['entry'])) {
            foreach ($result['data']['entry'] as $entry) {
                $res = $entry['resource'] ?? [];
                if (isset($res['id'])) {
                    return $res['id'];
                }
            }
        }

        return null;
    }
}
