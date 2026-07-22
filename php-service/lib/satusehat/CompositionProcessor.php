<?php

/**
 * CompositionProcessor - Orchestrator for Satu Sehat Composition sync.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class SatuSehatCompositionProcessor
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
        $this->log->info("[SYNC] Phase 1: POST New Composition");
        $this->processActive($dateFrom, $dateTo, $activeRecords);

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 2: PUT Update Composition");
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
            $records = $this->db->fetchPendingCompositionActive($dateFrom, $dateTo);
        }

        if (empty($records)) {
            $this->log->info("[PHASE 1] No pending compositions to POST.");
            return;
        }

        $this->log->info("[PHASE 1] Found " . count($records) . " records to POST.");

        foreach ($records as $r) {
            $noRawat = $r['no_rawat'];
            $localState = $this->db->getCompositionLocalState($noRawat);
            if ($localState === 'active' || $localState === 'updated' || $localState === 'skipped') {
                $this->skipCount++;
                continue;
            }

            $nik = $r['no_ktp'];
            $idPasien = $this->db->getIhsPatient($nik);
            if (!$idPasien) {
                $this->log->warning("[PHASE 1] {$noRawat}: Missing Patient IHS. Skipped.");
                $this->skipCount++;
                continue;
            }

            $nikDokter = $r['ktpdokter'];
            $idDokter = $this->db->getIhsPractitioner($nikDokter);
            if (!$idDokter) {
                $this->log->warning("[PHASE 1] {$noRawat}: Missing Practitioner IHS. Skipped.");
                $this->skipCount++;
                continue;
            }

            $refs = $this->db->fetchSyncedResourceReferences($noRawat);
            $idEncounter = $refs['Encounter'] ?? null;
            if (!$idEncounter) {
                $this->log->warning("[PHASE 1] {$noRawat}: Missing Encounter ID. Skipped.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::composition(
                $this->config->orgId,
                $r,
                $idPasien,
                $idDokter,
                $idEncounter,
                $refs
            );

            $this->log->info("[PHASE 1] {$noRawat}: POST /Composition");
            $response = $this->api->post('/Composition', $payload);

            if ($response && ($response['success'] ?? false) && isset($response['data']['id'])) {
                $idComposition = $response['data']['id'];
                $this->db->saveComposition($noRawat, $idComposition);
                $this->db->updateCompositionLocalState($noRawat, 'active');
                $this->log->info("[PHASE 1] {$noRawat}: ✓ Created Composition {$idComposition}");
                $this->successCount++;
            } else {
                $issueText = $response['data']['issue'][0]['details']['text'] ?? $response['message'] ?? '';

                // Duplicate handling: Composition was already posted (e.g. from a previous crash before local save)
                if (stripos($issueText, 'duplicate') !== false || stripos($issueText, '20002') !== false) {
                    $this->log->warning("[PHASE 1] {$noRawat}: Duplicate Composition detected. Recovering from Satu Sehat...");
                    $idComposition = $this->resolveDuplicateComposition($idEncounter);

                    if ($idComposition) {
                        $this->db->saveComposition($noRawat, $idComposition);
                        $this->db->updateCompositionLocalState($noRawat, 'active');
                        $this->log->info("[PHASE 1] {$noRawat}: ✓ Recovered Composition {$idComposition} from Satu Sehat");
                        $this->successCount++;
                    } else {
                        $this->log->error("[PHASE 1] {$noRawat}: ✗ Failed to recover duplicate Composition.");
                        $this->failCount++;
                    }
                } else {
                    $this->log->warning("[PHASE 1] {$noRawat}: ✗ Failed -> " . $issueText);
                    $this->failCount++;
                }
            }
        }
    }

    private function processUpdate(string $dateFrom, string $dateTo, ?array $records = null): void
    {
        if ($records === null) {
            $records = $this->db->fetchPendingCompositionUpdate($dateFrom, $dateTo);
        }

        if (empty($records)) {
            $this->log->info("[PHASE 2] No composition updates to process.");
            return;
        }

        $this->log->info("[PHASE 2] Found " . count($records) . " records to update.");

        foreach ($records as $r) {
            $noRawat = $r['no_rawat'];
            $idComposition = $r['id_composition'];

            $localState = $this->db->getCompositionLocalState($noRawat);
            if ($localState === 'updated') {
                $this->skipCount++;
                continue;
            }

            // Build PATCH operations — confirm status final
            $ops = [
                [
                    'op' => 'replace',
                    'path' => '/status',
                    'value' => 'final'
                ]
            ];

            $this->log->info("[PHASE 2] {$noRawat}: PATCH /Composition/{$idComposition}");
            $response = $this->api->patch('/Composition/' . $idComposition, $ops);

            if ($response && ($response['success'] ?? false)) {
                $this->db->updateCompositionLocalState($noRawat, 'updated');
                $this->log->info("[PHASE 2] {$noRawat}: ✓ Updated Composition {$idComposition} via PATCH");
                $this->successCount++;
            } else {
                $this->log->warning("[PHASE 2] {$noRawat}: ✗ Update failed -> " . json_encode($response['data']['issue'][0]['details']['text'] ?? $response['message'] ?? ''));
                $this->failCount++;
            }
        }
    }

    /**
     * Resolves a duplicate Composition by searching the Satu Sehat API by encounter reference.
     * This handles cases where the Composition was created on SATUSEHAT but the local save failed.
     */
    private function resolveDuplicateComposition(string $idEncounter): ?string
    {
        $endpoint = "/Composition?encounter={$idEncounter}";
        $result = $this->api->get($endpoint);

        if (!($result['success'] ?? false) || empty($result['data']['entry'])) {
            return null;
        }

        foreach ($result['data']['entry'] as $entry) {
            $res = $entry['resource'] ?? [];
            if (isset($res['id'])) {
                return $res['id'];
            }
        }

        return null;
    }
}
