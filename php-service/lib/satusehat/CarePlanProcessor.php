<?php

/**
 * CarePlanProcessor - Orchestrator for Satu Sehat CarePlan sync.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class SatuSehatCarePlanProcessor
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
        $this->log->info("[SYNC] Phase 1: POST New CarePlan");
        $this->processActive($dateFrom, $dateTo, $activeRecords);

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 2: PUT Update CarePlan");
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
            $patients = $this->db->fetchPendingCarePlanActive($dateFrom, $dateTo);
        }

        if (empty($patients)) {
            $this->log->info("[PHASE 1] No pending CarePlans to POST.");
            return;
        }

        $this->log->info("[PHASE 1] Found " . count($patients) . " care plan record(s) to POST.");

        foreach ($patients as $p) {
            $noRawat = $p['no_rawat'];
            $tglPerawatan = $p['tgl_perawatan'];
            $jamRawat = $p['jam_rawat'];
            $statusLanjut = $p['status_lanjut']; // 'Ralan' or 'Ranap'
            $idEncounter = $p['id_encounter'];

            $nikPasien = $p['no_ktp'];
            $nikPraktisi = $p['ktppraktisi'];

            $idPasien = $this->db->getIhsPatient($nikPasien);
            if (!$idPasien) {
                $this->log->warning("[PHASE 1] {$noRawat}: Missing IHS ID for Patient. Skipped.");
                $this->skipCount++;
                continue;
            }

            $idDokter = $this->db->getIhsPractitioner($nikPraktisi);
            if (!$idDokter) {
                $this->log->warning("[PHASE 1] {$noRawat}: Missing IHS ID for Practitioner. Skipped.");
                $this->skipCount++;
                continue;
            }

            // Get Category Code for Duplicate Prevention lookup
            if (($p['kd_poli'] ?? '') === 'IGDK') {
                $categoryCode = 'TK000068';
            } else {
                $categoryCode = ($statusLanjut === 'Ralan') ? '736271009' : '736353004';
            }

            // Duplicate Prevention lookup
            $idCarePlan = $this->resolveDuplicateCarePlan($idPasien, $idEncounter, $categoryCode);
            if ($idCarePlan) {
                $this->db->saveCarePlan($noRawat, $tglPerawatan, $jamRawat, $statusLanjut, $idCarePlan);
                $this->db->updateCarePlanLocalState($noRawat, $tglPerawatan, $jamRawat, $statusLanjut, 'active');
                $this->log->info("[PHASE 1] {$noRawat}: ✓ Recovered existing CarePlan {$idCarePlan} from Satu Sehat");
                $this->successCount++;
                continue;
            }

            // Determine title based on context
            $title = 'Instruksi Medik dan Keperawatan Pasien';
            if ($statusLanjut === 'Ranap') {
                $title = 'Rencana Rawat Pasien';
            }

            $payload = SatuSehatPayloadBuilder::carePlan(
                $this->config->orgId,
                $p,
                $idPasien,
                $idDokter,
                '',
                $title
            );

            $this->log->info("[PHASE 1] {$noRawat}: POST /CarePlan");
            $result = $this->api->post('/CarePlan', $payload);

            if ($result['success'] && isset($result['data']['id'])) {
                $idCarePlan = $result['data']['id'];
                $this->db->saveCarePlan($noRawat, $tglPerawatan, $jamRawat, $statusLanjut, $idCarePlan);
                $this->db->updateCarePlanLocalState($noRawat, $tglPerawatan, $jamRawat, $statusLanjut, 'active');
                $this->log->info("[PHASE 1] {$noRawat}: ✓ Created CarePlan {$idCarePlan}");
                $this->successCount++;
            } else {
                $errorMessage = $result['data']['issue'][0]['diagnostics'] ?? $result['message'];

                // Fallback check on conflict or potential duplicate error
                if (stripos($errorMessage, 'duplicate') !== false || $result['code'] === 409) {
                    $this->log->warning("[PHASE 1] {$noRawat}: Conflict or duplicate detected. Searching remote records...");
                    $idCarePlan = $this->resolveDuplicateCarePlan($idPasien, $idEncounter, $categoryCode);

                    if ($idCarePlan) {
                        $this->db->saveCarePlan($noRawat, $tglPerawatan, $jamRawat, $statusLanjut, $idCarePlan);
                        $this->db->updateCarePlanLocalState($noRawat, $tglPerawatan, $jamRawat, $statusLanjut, 'active');
                        $this->log->info("[PHASE 1] {$noRawat}: ✓ Recovered existing CarePlan {$idCarePlan} from Satu Sehat");
                        $this->successCount++;
                    } else {
                        $this->log->error("[PHASE 1] {$noRawat}: ✗ Failed to recover duplicate CarePlan.");
                        $this->failCount++;
                    }
                } else {
                    $this->log->warning("[PHASE 1] {$noRawat}: ✗ Failed -> " . $errorMessage);
                    $this->failCount++;
                }
            }
        }
    }

    private function processUpdate(string $dateFrom, string $dateTo, ?array $patients = null): void
    {
        if ($patients === null) {
            $patients = $this->db->fetchPendingCarePlanUpdate($dateFrom, $dateTo);
        }

        if (empty($patients)) {
            $this->log->info("[PHASE 2] No pending CarePlans to PATCH.");
            return;
        }

        $this->log->info("[PHASE 2] Found " . count($patients) . " care plan record(s) to PATCH.");

        foreach ($patients as $p) {
            $noRawat = $p['no_rawat'];
            $tglPerawatan = $p['tgl_perawatan'];
            $jamRawat = $p['jam_rawat'];
            $statusLanjut = $p['status_lanjut'];
            $idCarePlan = $p['id_careplan'];

            $localState = $this->db->getCarePlanLocalState($noRawat, $tglPerawatan, $jamRawat, $statusLanjut);

            if ($localState === 'updated') {
                $this->skipCount++;
                continue;
            }

            $nikPasien = $p['no_ktp'];
            $nikPraktisi = $p['ktppraktisi'];
            $idPasien = $this->db->getIhsPatient($nikPasien);
            if (!$idPasien) {
                $this->log->warning("[PHASE 2] {$noRawat}: Missing IHS ID for Patient. Skipped.");
                $this->skipCount++;
                continue;
            }
            $idDokter = $this->db->getIhsPractitioner($nikPraktisi);
            if (!$idDokter) {
                $this->log->warning("[PHASE 2] {$noRawat}: Missing IHS ID for Practitioner. Skipped.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::carePlan(
                $this->config->orgId,
                $p,
                $idPasien,
                $idDokter,
                $idCarePlan
            );
            $ops = SatuSehatPayloadBuilder::payloadToPatchOps($payload);

            $this->log->info("[PHASE 2] {$noRawat}: PATCH /CarePlan/{$idCarePlan} (" . count($ops) . " ops)");
            $result = $this->api->patch("/CarePlan/{$idCarePlan}", $ops);

            if ($result['success']) {
                $this->db->updateCarePlanLocalState($noRawat, $tglPerawatan, $jamRawat, $statusLanjut, 'updated');
                $this->log->info("[PHASE 2] {$noRawat}: ✓ Updated CarePlan {$idCarePlan} via PATCH");
                $this->successCount++;
            } else {
                $this->log->warning("[PHASE 2] {$noRawat}: ✗ Failed -> " . ($result['data']['issue'][0]['diagnostics'] ?? $result['message']));
                $this->failCount++;
            }
        }
    }

    private function resolveDuplicateCarePlan(string $idPasien, string $idEncounter, string $categoryCode): ?string
    {
        $endpoint = "/CarePlan?patient={$idPasien}&encounter={$idEncounter}";
        $result = $this->api->get($endpoint);

        if (!$result['success'] || empty($result['data']['entry'])) {
            return null;
        }

        foreach ($result['data']['entry'] as $entry) {
            $res = $entry['resource'] ?? [];
            $resCode = $res['category'][0]['coding'][0]['code'] ?? '';

            if ($resCode === $categoryCode) {
                return $res['id'];
            }
        }

        return null;
    }
}
