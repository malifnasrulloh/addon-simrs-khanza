<?php

/**
 * EncounterProcessor - Orchestrator for Satu Sehat Encounter sync.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class SatuSehatEncounterProcessor
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

    public function run(?array $arrivedRecords = null, ?array $inProgressRecords = null, ?array $finishedRecords = null): array
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
        $this->log->info("[SYNC] Phase 1: POST 'arrived' Encounters");
        $this->processArrived($dateFrom, $dateTo, $arrivedRecords);

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 2: PUT 'in-progress' Encounters");
        $this->processInProgress($dateFrom, $dateTo, $inProgressRecords);

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 3: PUT 'finished' Encounters");
        $this->processFinished($dateFrom, $dateTo, $finishedRecords);

        return [
            'success' => $this->successCount,
            'fail'    => $this->failCount,
            'skip'    => $this->skipCount,
        ];
    }

    private function processArrived(string $dateFrom, string $dateTo, ?array $patients = null): void
    {
        if ($patients === null) {
            $patients = $this->db->fetchPendingArrived($dateFrom, $dateTo);
        }
        
        if (empty($patients)) {
            $this->log->info("[PHASE 1] No pending 'arrived' encounters.");
            return;
        }

        $this->log->info("[PHASE 1] Found " . count($patients) . " patient(s) to arrive.");

        foreach ($patients as $p) {
            $noRawat = $p['no_rawat'];
            $nik = $p['no_ktp'];
            $nikDokter = $p['ktpdokter'];

            $idPasien = $this->db->getIhsPatient($nik);
            $idDokter = $this->db->getIhsPractitioner($nikDokter);

            if (!$idPasien || !$idDokter) {
                $this->log->warning("[PHASE 1] {$noRawat}: Missing IHS ID for Patient or Doctor. Skipped.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::encounter(
                $this->config->orgId,
                $p,
                $idPasien,
                $idDokter,
                'arrived'
            );

            $this->log->info("[PHASE 1] {$noRawat}: POST /Encounter (arrived)");
            $result = $this->api->post('/Encounter', $payload);

            if ($result['success'] && isset($result['data']['id'])) {
                $idEncounter = $result['data']['id'];
                $this->db->saveEncounter($noRawat, $idEncounter);
                $this->db->updateLocalState($noRawat, 'arrived');
                $this->log->info("[PHASE 1] {$noRawat}: ✓ Created Encounter {$idEncounter}");
                $this->successCount++;
            } else {
                $errorMessage = $result['data']['issue'][0]['diagnostics'] ?? $result['message'];

                // Duplicate Handling Fallback
                if (stripos($errorMessage, 'duplicate') !== false || $result['code'] === 409) {
                    $this->log->warning("[PHASE 1] {$noRawat}: Duplicated Encounter detected. Searching existing records...");
                    $idEncounter = $this->resolveDuplicateEncounter($noRawat);

                    if ($idEncounter) {
                        $this->db->saveEncounter($noRawat, $idEncounter);
                        $this->db->updateLocalState($noRawat, 'arrived');
                        $this->log->info("[PHASE 1] {$noRawat}: ✓ Recovered Encounter {$idEncounter} from Satu Sehat API");
                        $this->successCount++;
                    } else {
                        $this->log->error("[PHASE 1] {$noRawat}: ✗ Failed to recover duplicate Encounter.");
                        $this->failCount++;
                    }
                } else {
                    $this->log->warning("[PHASE 1] {$noRawat}: ✗ Failed -> " . $errorMessage);
                    $this->failCount++;
                }
            }
        }
    }

    private function processInProgress(string $dateFrom, string $dateTo, ?array $patients = null): void
    {
        if ($patients === null) {
            $patients = $this->db->fetchPendingInProgress($dateFrom, $dateTo);
        }

        if (empty($patients)) {
            $this->log->info("[PHASE 2] No pending 'in-progress' encounters.");
            return;
        }

        $this->log->info("[PHASE 2] Found " . count($patients) . " patient(s) to set in-progress.");

        foreach ($patients as $p) {
            $noRawat = $p['no_rawat'];
            $localState = $this->db->getLocalState($noRawat);

            if ($localState === 'in-progress' || $localState === 'finished') {
                $this->skipCount++;
                continue;
            }

            $nik = $p['no_ktp'];
            $nikDokter = $p['ktpdokter'];

            $idPasien = $this->db->getIhsPatient($nik);
            $idDokter = $this->db->getIhsPractitioner($nikDokter);

            if (!$idPasien || !$idDokter) {
                $this->log->warning("[PHASE 2] {$noRawat}: Missing IHS ID. Skipped.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::encounter(
                $this->config->orgId,
                $p,
                $idPasien,
                $idDokter,
                'in-progress',
                [],
                $p['id_encounter']
            );

            $this->log->info("[PHASE 2] {$noRawat}: PUT /Encounter/{$p['id_encounter']} (in-progress)");
            $result = $this->api->put("/Encounter/{$p['id_encounter']}", $payload);

            if ($result['success']) {
                $this->db->updateLocalState($noRawat, 'in-progress');
                $this->log->info("[PHASE 2] {$noRawat}: ✓ Updated to in-progress");
                $this->successCount++;
            } else {
                $this->log->warning("[PHASE 2] {$noRawat}: ✗ Failed -> " . ($result['data']['issue'][0]['diagnostics'] ?? $result['message']));
                $this->failCount++;
            }
        }
    }

    private function processFinished(string $dateFrom, string $dateTo, ?array $patients = null): void
    {
        if ($patients === null) {
            $patients = $this->db->fetchPendingFinished($dateFrom, $dateTo);
        }

        if (empty($patients)) {
            $this->log->info("[PHASE 3] No pending 'finished' encounters.");
            return;
        }

        $this->log->info("[PHASE 3] Found " . count($patients) . " patient(s) to set finished.");

        foreach ($patients as $p) {
            $noRawat = $p['no_rawat'];
            $localState = $this->db->getLocalState($noRawat);

            if ($localState === 'finished') {
                $this->skipCount++;
                continue;
            }

            $diagnoses = $this->db->fetchDiagnoses($noRawat);
            if (empty($diagnoses)) {
                $this->log->debug("[PHASE 3] {$noRawat}: No diagnoses found yet. Must have diagnosis to finish. Skipped.");
                $this->skipCount++;
                continue;
            }

            $nik = $p['no_ktp'];
            $nikDokter = $p['ktpdokter'];

            $idPasien = $this->db->getIhsPatient($nik);
            $idDokter = $this->db->getIhsPractitioner($nikDokter);

            if (!$idPasien || !$idDokter) {
                $this->log->warning("[PHASE 3] {$noRawat}: Missing IHS ID. Skipped.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::encounter(
                $this->config->orgId,
                $p,
                $idPasien,
                $idDokter,
                'finished',
                $diagnoses,
                $p['id_encounter']
            );

            $this->log->info("[PHASE 3] {$noRawat}: PUT /Encounter/{$p['id_encounter']} (finished)");
            $result = $this->api->put("/Encounter/{$p['id_encounter']}", $payload);

            if ($result['success']) {
                $this->db->updateLocalState($noRawat, 'finished');
                $this->log->info("[PHASE 3] {$noRawat}: ✓ Updated to finished");
                $this->successCount++;
            } else {
                $this->log->warning("[PHASE 3] {$noRawat}: ✗ Failed -> " . ($result['data']['issue'][0]['diagnostics'] ?? $result['message']));
                $this->failCount++;
            }
        }
    }

    /**
     * Resolves a duplicate Encounter by searching the Satu Sehat API by its identifier.
     */
    private function resolveDuplicateEncounter(string $noRawat): ?string
    {
        $endpoint = "/Encounter?identifier=http://sys-ids.kemkes.go.id/encounter/{$this->config->orgId}|" . urlencode($noRawat);
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
