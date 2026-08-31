<?php

/**
 * NutritionOrderProcessor - Orchestrator for Satu Sehat NutritionOrder sync.
 *
 * @author malifnasrulloh
 */

declare(strict_types=1);

class SatuSehatNutritionOrderProcessor
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
        $this->log->info("[SYNC] Phase 1: POST New NutritionOrder");
        $this->processActive($dateFrom, $dateTo, $activeRecords);

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 2: PUT/PATCH Update NutritionOrder");
        $this->processUpdate($dateFrom, $dateTo, $updateRecords);

        return [
            'success' => $this->successCount,
            'fail'    => $this->failCount,
            'skip'    => $this->skipCount,
        ];
    }

    private function processActive(string $dateFrom, string $dateTo, ?array $orders = null): void
    {
        if ($orders === null) {
            $orders = $this->db->fetchPendingNutritionOrderActive($dateFrom, $dateTo);
        }

        if (empty($orders)) {
            $this->log->info("[PHASE 1] No pending NutritionOrders to POST.");
            return;
        }

        $this->log->info("[PHASE 1] Found " . count($orders) . " NutritionOrder record(s) to POST.");

        foreach ($orders as $p) {
            $noRawat = $p['no_rawat'];
            $tanggalAdime = $p['tanggal_adime'];
            $idEncounter = $p['id_encounter'];

            $nikPasien = $p['no_ktp'];
            $nikPraktisi = $p['ktppraktisi'];

            $idPasien = $this->db->getIhsPatient($nikPasien);
            if (!$idPasien) {
                $this->log->warning("[PHASE 1] {$noRawat}: Missing IHS ID for Patient. Skipped.");
                $this->skipCount++;
                continue;
            }

            $idPraktisi = $this->db->getIhsPractitioner($nikPraktisi);
            if (!$idPraktisi && !empty($p['ktpdokter_dpjp'])) {
                $idPraktisi = $this->db->getIhsPractitioner($p['ktpdokter_dpjp']);
            }
            if (!$idPraktisi) {
                $this->log->warning("[PHASE 1] {$noRawat}: Missing IHS ID for Practitioner/DPJP. Skipped.");
                $this->skipCount++;
                continue;
            }

            // Duplicate Prevention lookup
            $idNutritionOrder = $this->resolveDuplicateNutritionOrder($idPasien, $idEncounter);
            if ($idNutritionOrder) {
                $this->db->saveNutritionOrder($noRawat, $tanggalAdime, $idNutritionOrder, 'active');
                $this->log->info("[PHASE 1] {$noRawat}: ✓ Recovered existing NutritionOrder {$idNutritionOrder} from Satu Sehat");
                $this->successCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::nutritionOrder(
                $this->config->orgId,
                $p,
                $idPasien,
                $idPraktisi
            );

            $this->log->info("[PHASE 1] {$noRawat}: POST /NutritionOrder");
            $result = $this->api->post('/NutritionOrder', $payload);

            if ($result['success'] && isset($result['data']['id'])) {
                $idNutritionOrder = $result['data']['id'];
                $this->db->saveNutritionOrder($noRawat, $tanggalAdime, $idNutritionOrder, 'active');
                $this->log->info("[PHASE 1] {$noRawat}: ✓ Created NutritionOrder {$idNutritionOrder}");
                $this->successCount++;
            } else {
                $errorMessage = \SatuSehatClient::extractErrorMsg($result);

                // Fallback check on conflict or potential duplicate error
                if (stripos($errorMessage, 'duplicate') !== false || $result['code'] === 409) {
                    $this->log->warning("[PHASE 1] {$noRawat}: Conflict or duplicate detected. Searching remote records...");
                    $idNutritionOrder = $this->resolveDuplicateNutritionOrder($idPasien, $idEncounter);

                    if ($idNutritionOrder) {
                        $this->db->saveNutritionOrder($noRawat, $tanggalAdime, $idNutritionOrder, 'active');
                        $this->log->info("[PHASE 1] {$noRawat}: ✓ Recovered existing NutritionOrder {$idNutritionOrder} from Satu Sehat");
                        $this->successCount++;
                    } else {
                        $this->log->error("[PHASE 1] {$noRawat}: ✗ Failed to recover duplicate NutritionOrder.");
                        $this->failCount++;
                    }
                } else {
                    $this->log->warning("[PHASE 1] {$noRawat}: ✗ Failed -> " . $errorMessage);
                    $this->failCount++;
                }
            }
        }
    }

    private function processUpdate(string $dateFrom, string $dateTo, ?array $orders = null): void
    {
        if ($orders === null) {
            $orders = $this->db->fetchPendingNutritionOrderUpdate($dateFrom, $dateTo);
        }

        if (empty($orders)) {
            $this->log->info("[PHASE 2] No pending NutritionOrders to UPDATE.");
            return;
        }

        $this->log->info("[PHASE 2] Found " . count($orders) . " NutritionOrder record(s) to UPDATE.");

        foreach ($orders as $p) {
            $noRawat = $p['no_rawat'];
            $tanggalAdime = $p['tanggal_adime'];
            $idNutritionOrder = $p['id_nutritionorder'];

            $nikPasien = $p['no_ktp'];
            $nikPraktisi = $p['ktppraktisi'];

            $idPasien = $this->db->getIhsPatient($nikPasien);
            if (!$idPasien) {
                $this->log->warning("[PHASE 2] {$noRawat}: Missing IHS ID for Patient. Skipped.");
                $this->skipCount++;
                continue;
            }

            $idPraktisi = $this->db->getIhsPractitioner($nikPraktisi);
            if (!$idPraktisi && !empty($p['ktpdokter_dpjp'])) {
                $idPraktisi = $this->db->getIhsPractitioner($p['ktpdokter_dpjp']);
            }
            if (!$idPraktisi) {
                $this->log->warning("[PHASE 2] {$noRawat}: Missing IHS ID for Practitioner/DPJP. Skipped.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::nutritionOrder(
                $this->config->orgId,
                $p,
                $idPasien,
                $idPraktisi,
                $idNutritionOrder
            );
            $ops = SatuSehatPayloadBuilder::payloadToPatchOps($payload);

            $this->log->info("[PHASE 2] {$noRawat}: PATCH /NutritionOrder/{$idNutritionOrder} (" . count($ops) . " ops)");
            $result = $this->api->patch("/NutritionOrder/{$idNutritionOrder}", $ops, $payload);

            if ($result['success']) {
                $this->db->saveNutritionOrder($noRawat, $tanggalAdime, $idNutritionOrder, 'updated');
                $this->log->info("[PHASE 2] {$noRawat}: ✓ Updated NutritionOrder {$idNutritionOrder} via PATCH");
                $this->successCount++;
            } else {
                $errorMessage = \SatuSehatClient::extractErrorMsg($result);
                $this->log->warning("[PHASE 2] {$noRawat}: ✗ Failed -> " . $errorMessage);
                $this->failCount++;
            }
        }
    }

    private function resolveDuplicateNutritionOrder(string $idPasien, string $idEncounter): ?string
    {
        $endpoint = "/NutritionOrder?patient={$idPasien}&encounter={$idEncounter}";
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
