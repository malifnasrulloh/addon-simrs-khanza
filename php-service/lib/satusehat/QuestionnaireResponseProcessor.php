<?php

/**
 * QuestionnaireResponseProcessor - Orchestrator for Satu Sehat QuestionnaireResponse (Telaah Farmasi) sync.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class SatuSehatQuestionnaireResponseProcessor
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
        $this->log->info("[SYNC] Phase 1: POST New QuestionnaireResponse");
        $this->processActive($dateFrom, $dateTo, $activeRecords);

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 2: PUT Update QuestionnaireResponse");
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
            $records = $this->db->fetchPendingQuestionnaireResponseActive($dateFrom, $dateTo);
        }
        
        if (empty($records)) {
            $this->log->info("[PHASE 1] No pending QuestionnaireResponse to POST.");
            return;
        }

        $this->log->info("[PHASE 1] Found " . count($records) . " QuestionnaireResponse record(s) to POST.");

        foreach ($records as $qr) {
            $noResep = $qr['no_resep'];
            $nikPasien = $qr['no_ktp'];
            $nikPraktisi = $qr['ktppraktisi'];
            $idEncounter = $qr['id_encounter'];

            $idPasien = $this->db->getIhsPatient($nikPasien);
            if (!$idPasien) {
                $this->log->warning("[PHASE 1] {$noResep}: Missing IHS ID for Patient (NIK: {$nikPasien}). Skipped.");
                $this->skipCount++;
                continue;
            }

            $idPraktisi = $this->db->getIhsPractitioner($nikPraktisi);
            if (!$idPraktisi) {
                $this->log->warning("[PHASE 1] {$noResep}: Missing IHS ID for Practitioner (NIK: {$nikPraktisi}). Skipped.");
                $this->skipCount++;
                continue;
            }

            // Check if there is already a remote QuestionnaireResponse for this encounter/practitioner and prescription to avoid duplicate POSTs
            $idQR = $this->resolveDuplicateQuestionnaireResponse($idEncounter, $idPraktisi, $noResep);
            if ($idQR) {
                $this->db->saveQuestionnaireResponse($noResep, $idQR);
                $this->db->updateQuestionnaireResponseLocalState($noResep, 'active');
                $this->log->info("[PHASE 1] {$noResep}: ✓ Recovered existing QuestionnaireResponse {$idQR} from Satu Sehat");
                $this->successCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::questionnaireResponse(
                $qr,
                $idPasien,
                $idPraktisi
            );

            $this->log->info("[PHASE 1] {$noResep}: POST /QuestionnaireResponse");
            $result = $this->api->post('/QuestionnaireResponse', $payload);

            if ($result['success'] && isset($result['data']['id'])) {
                $newId = $result['data']['id'];
                $this->db->saveQuestionnaireResponse($noResep, $newId);
                $this->db->updateQuestionnaireResponseLocalState($noResep, 'active');
                $this->log->info("[PHASE 1] {$noResep}: ✓ Created QuestionnaireResponse {$newId}");
                $this->successCount++;
            } else {
                $errorMessage = $result['data']['issue'][0]['diagnostics'] ?? $result['message'];
                $this->log->warning("[PHASE 1] {$noResep}: ✗ Failed -> " . $errorMessage);
                $this->failCount++;
            }
        }
    }

    private function processUpdate(string $dateFrom, string $dateTo, ?array $records = null): void
    {
        if ($records === null) {
            $records = $this->db->fetchPendingQuestionnaireResponseUpdate($dateFrom, $dateTo);
        }

        if (empty($records)) {
            $this->log->info("[PHASE 2] No pending QuestionnaireResponse to PUT.");
            return;
        }

        $this->log->info("[PHASE 2] Found " . count($records) . " QuestionnaireResponse record(s) to PUT.");

        foreach ($records as $qr) {
            $noResep = $qr['no_resep'];
            $idQR = $qr['id_questionresponse'];
            $localState = $this->db->getQuestionnaireResponseLocalState($noResep);

            if ($localState === 'updated') {
                $this->skipCount++;
                continue;
            }

            $nikPasien = $qr['no_ktp'];
            $nikPraktisi = $qr['ktppraktisi'];

            $idPasien = $this->db->getIhsPatient($nikPasien);
            if (!$idPasien) {
                $this->log->warning("[PHASE 2] {$noResep}: Missing IHS ID for Patient (NIK: {$nikPasien}). Skipped.");
                $this->skipCount++;
                continue;
            }

            $idPraktisi = $this->db->getIhsPractitioner($nikPraktisi);
            if (!$idPraktisi) {
                $this->log->warning("[PHASE 2] {$noResep}: Missing IHS ID for Practitioner (NIK: {$nikPraktisi}). Skipped.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::questionnaireResponse(
                $qr,
                $idPasien,
                $idPraktisi,
                $idQR
            );

            $this->log->info("[PHASE 2] {$noResep}: PUT /QuestionnaireResponse/{$idQR}");
            $result = $this->api->put("/QuestionnaireResponse/{$idQR}", $payload);

            if ($result['success']) {
                $this->db->updateQuestionnaireResponseLocalState($noResep, 'updated');
                $this->log->info("[PHASE 2] {$noResep}: ✓ Updated QuestionnaireResponse {$idQR}");
                $this->successCount++;
            } else {
                $this->log->warning("[PHASE 2] {$noResep}: ✗ Failed -> " . ($result['data']['issue'][0]['diagnostics'] ?? $result['message']));
                $this->failCount++;
            }
        }
    }

    /**
     * Resolves an existing QuestionnaireResponse in Satu Sehat by encounter, author, and prescription ID.
     */
    private function resolveDuplicateQuestionnaireResponse(string $idEncounter, string $idPraktisi, string $noResep): ?string
    {
        $endpoint = "/QuestionnaireResponse?encounter={$idEncounter}&author={$idPraktisi}";
        $result = $this->api->get($endpoint);

        if (!$result['success'] || empty($result['data']['entry'])) {
            return null;
        }

        foreach ($result['data']['entry'] as $entry) {
            $res = $entry['resource'] ?? [];
            if (empty($res['item'])) {
                continue;
            }

            // Find no-resep inside items
            $foundResep = null;
            foreach ($res['item'] as $item) {
                if ($item['linkId'] === 'identitas' && !empty($item['item'])) {
                    foreach ($item['item'] as $subItem) {
                        if ($subItem['linkId'] === 'no-resep' && !empty($subItem['answer'][0]['valueString'])) {
                            $foundResep = $subItem['answer'][0]['valueString'];
                            break 2;
                        }
                    }
                }
            }

            if ($foundResep === $noResep && isset($res['id'])) {
                return $res['id']; // Match found
            }
        }

        return null;
    }
}
