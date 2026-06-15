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
                $this->config->organizationId,
                $r,
                $idPasien,
                $idDokter,
                $idEncounter,
                $refs
            );

            $this->log->info("[PHASE 1] {$noRawat}: POST /Composition");
            $response = $this->api->post('/Composition', $payload);

            if ($response && isset($response['id'])) {
                $idComposition = $response['id'];
                $this->db->saveComposition($noRawat, $idComposition);
                $this->db->updateCompositionLocalState($noRawat, 'active');
                $this->log->success("[PHASE 1] {$noRawat}: Sync success. ID: {$idComposition}");
                $this->successCount++;
            } else {
                $this->log->error("[PHASE 1] {$noRawat}: Sync failed. Error details: " . json_encode($response));
                $this->failCount++;
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

            $nik = $r['no_ktp'];
            $idPasien = $this->db->getIhsPatient($nik);
            if (!$idPasien) {
                $this->log->warning("[PHASE 2] {$noRawat}: Missing Patient IHS. Skipped.");
                $this->skipCount++;
                continue;
            }

            $nikDokter = $r['ktpdokter'];
            $idDokter = $this->db->getIhsPractitioner($nikDokter);
            if (!$idDokter) {
                $this->log->warning("[PHASE 2] {$noRawat}: Missing Practitioner IHS. Skipped.");
                $this->skipCount++;
                continue;
            }

            $refs = $this->db->fetchSyncedResourceReferences($noRawat);
            $idEncounter = $refs['Encounter'] ?? null;
            if (!$idEncounter) {
                $this->log->warning("[PHASE 2] {$noRawat}: Missing Encounter ID. Skipped.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::composition(
                $this->config->organizationId,
                $r,
                $idPasien,
                $idDokter,
                $idEncounter,
                $refs,
                $idComposition
            );

            $this->log->info("[PHASE 2] {$noRawat}: PUT /Composition/{$idComposition}");
            $response = $this->api->put('/Composition/' . $idComposition, $payload);

            if ($response && isset($response['id'])) {
                $this->db->updateCompositionLocalState($noRawat, 'updated');
                $this->log->success("[PHASE 2] {$noRawat}: Update success. ID: {$idComposition}");
                $this->successCount++;
            } else {
                $this->log->error("[PHASE 2] {$noRawat}: Update failed. Error details: " . json_encode($response));
                $this->failCount++;
            }
        }
    }
}
