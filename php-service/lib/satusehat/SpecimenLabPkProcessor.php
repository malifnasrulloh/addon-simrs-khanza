<?php

/**
 * SpecimenLabPkProcessor - Orchestrator for Satu Sehat Specimen (Lab PK) sync.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class SatuSehatSpecimenLabPkProcessor
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
        $this->log->info("[SYNC] Phase 1: POST New Specimen (Lab PK)");
        $this->processActive($dateFrom, $dateTo, $activeRecords);

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 2: PUT Update Specimen (Lab PK)");
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
            $records = $this->db->fetchPendingSpecimenLabPKActive($dateFrom, $dateTo);
        }
        
        if (empty($records)) {
            $this->log->info("[PHASE 1] No pending Specimens to POST.");
            return;
        }

        $this->log->info("[PHASE 1] Found " . count($records) . " record(s) to POST.");

        foreach ($records as $p) {
            $noorder = $p['noorder'];
            $idTemplate = (int)$p['id_template'];
            $kdJenisPrw = $p['kd_jenis_prw'];
            $pemeriksaan = $p['Pemeriksaan'];

            $idPasien = $this->db->getIhsPatient($p['nik_pasien']);

            if (!$idPasien) {
                $this->log->warning("[PHASE 1] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: Missing IHS ID for Patient. Skipped.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::specimenLab(
                $p,
                $idPasien,
                $this->config->organizationId
            );

            $this->log->info("[PHASE 1] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: POST /Specimen ({$pemeriksaan})");
            $result = $this->api->post('/Specimen', $payload);

            if ($result['success'] && isset($result['data']['id'])) {
                $idSpecimen = $result['data']['id'];
                $this->db->saveSpecimenLabPK($noorder, $kdJenisPrw, $idTemplate, $idSpecimen);
                $this->log->info("[PHASE 1] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: ✓ Created Specimen {$idSpecimen}");
                $this->successCount++;
            } else {
                $errorMessage = $result['data']['issue'][0]['diagnostics'] ?? $result['message'];
                
                // Duplicate Handling Fallback using identifier
                if (stripos($errorMessage, 'duplicate') !== false || $result['code'] === 409 || $result['code'] === 400) {
                    $this->log->warning("[PHASE 1] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: Duplicated Specimen detected. Searching existing records...");
                    $idSpecimen = $this->resolveDuplicateSpecimen($noorder, $idTemplate);

                    if ($idSpecimen) {
                        $this->db->saveSpecimenLabPK($noorder, $kdJenisPrw, $idTemplate, $idSpecimen);
                        $this->log->info("[PHASE 1] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: ✓ Recovered Specimen {$idSpecimen} from Satu Sehat");
                        $this->successCount++;
                    } else {
                        $this->log->error("[PHASE 1] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: ✗ Failed to recover duplicate Specimen.");
                        $this->failCount++;
                    }
                } else {
                    $this->log->warning("[PHASE 1] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: ✗ Failed -> " . $errorMessage);
                    $this->failCount++;
                }
            }
        }
    }

    private function processUpdate(string $dateFrom, string $dateTo, ?array $records = null): void
    {
        if ($records === null) {
            $records = $this->db->fetchPendingSpecimenLabPKUpdate($dateFrom, $dateTo);
        }

        if (empty($records)) {
            $this->log->info("[PHASE 2] No pending Specimens to PUT.");
            return;
        }

        $this->log->info("[PHASE 2] Found " . count($records) . " record(s) to PUT.");

        foreach ($records as $p) {
            $noorder = $p['noorder'];
            $idTemplate = (int)$p['id_template'];
            $kdJenisPrw = $p['kd_jenis_prw'];
            $pemeriksaan = $p['Pemeriksaan'];
            $idSpecimen = $p['id_specimen'];

            $idPasien = $this->db->getIhsPatient($p['nik_pasien']);

            if (!$idPasien) {
                $this->log->warning("[PHASE 2] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: Missing IHS ID for Patient. Skipped.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::specimenLab(
                $p,
                $idPasien,
                $this->config->organizationId,
                $idSpecimen
            );

            $this->log->info("[PHASE 2] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: PUT /Specimen/{$idSpecimen} ({$pemeriksaan})");
            $result = $this->api->put("/Specimen/{$idSpecimen}", $payload);

            if ($result['success']) {
                $this->log->info("[PHASE 2] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: ✓ Updated Specimen {$idSpecimen}");
                $this->successCount++;
            } else {
                $this->log->warning("[PHASE 2] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: ✗ Failed -> " . ($result['data']['issue'][0]['diagnostics'] ?? $result['message']));
                $this->failCount++;
            }
        }
    }

    private function resolveDuplicateSpecimen(string $noorder, int $idTemplate): ?string
    {
        $orgId = $this->config->organizationId;
        $identifier = "{$noorder}.{$idTemplate}";
        $endpoint = "/Specimen?identifier=http://sys-ids.kemkes.go.id/specimen/{$orgId}|{$identifier}";
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
