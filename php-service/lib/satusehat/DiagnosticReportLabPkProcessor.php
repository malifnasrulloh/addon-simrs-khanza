<?php

/**
 * DiagnosticReportLabPkProcessor - Orchestrator for Satu Sehat Diagnostic Report (Lab PK) sync.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class SatuSehatDiagnosticReportLabPkProcessor
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
        $this->log->info("[SYNC] Phase 1: POST New Diagnostic Report (Lab PK)");
        $this->processActive($dateFrom, $dateTo, $activeRecords);

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 2: PUT Update Diagnostic Report (Lab PK)");
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
            $records = $this->db->fetchPendingDiagnosticReportLabPKActive($dateFrom, $dateTo);
        }
        
        if (empty($records)) {
            $this->log->info("[PHASE 1] No pending Diagnostic Reports to POST.");
            return;
        }

        $this->log->info("[PHASE 1] Found " . count($records) . " record(s) to POST.");

        foreach ($records as $p) {
            $noorder = $p['noorder'];
            $idTemplate = (int)$p['id_template'];
            $kdJenisPrw = $p['kd_jenis_prw'];
            $pemeriksaan = $p['Pemeriksaan'];
            $code = $p['code'] ?? '';

            // Check local SQLite state
            $localState = $this->db->getDiagnosticReportLabPkLocalState($noorder, $idTemplate, $code);
            if (in_array($localState, ['skipped', 'invalid_code', 'active', 'updated'], true)) {
                $this->skipCount++;
                continue;
            }

            $idPasien = $this->db->getIhsPatient($p['nik_pasien']);
            $idDokter = $this->db->getIhsPractitioner($p['nik_dokter']);

            if (!$idPasien) {
                $this->log->warning("[PHASE 1] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: Missing IHS ID for Patient. Skipped.");
                $this->skipCount++;
                continue;
            }

            if (!$idDokter) {
                $this->log->warning("[PHASE 1] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: Missing IHS ID for Practitioner. Skipped.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::diagnosticReportLab(
                $p,
                $idPasien,
                $idDokter,
                $this->config->orgId
            );

            $this->log->info("[PHASE 1] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: POST /DiagnosticReport ({$pemeriksaan})");
            $result = $this->api->post('/DiagnosticReport', $payload);

            if ($result['success'] && isset($result['data']['id'])) {
                $idDiagnosticReport = $result['data']['id'];
                $this->db->saveDiagnosticReportLabPK($noorder, $kdJenisPrw, $idTemplate, $idDiagnosticReport);
                $this->db->updateDiagnosticReportLabPkLocalState($noorder, $idTemplate, $code, 'active');
                $this->log->info("[PHASE 1] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: ✓ Created Diagnostic Report {$idDiagnosticReport}");
                $this->successCount++;
            } else {
                $errorMessage = $result['data']['issue'][0]['diagnostics'] ?? $result['message'];
                
                $isDuplicate = (
                    stripos($errorMessage, 'duplicate') !== false || 
                    $result['code'] === 409 || 
                    ($result['code'] === 400 && stripos($errorMessage, 'already exists') !== false)
                );

                $isTerminologyError = (
                    $result['code'] === 400 && (
                        stripos($errorMessage, 'Code not found') !== false ||
                        stripos($errorMessage, 'not found in value set') !== false ||
                        stripos($errorMessage, 'invalid code') !== false ||
                        stripos($errorMessage, 'terminology') !== false
                    )
                );

                if ($isDuplicate) {
                    $this->log->warning("[PHASE 1] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: Duplicated Diagnostic Report detected. Searching existing records...");
                    $idDiagnosticReport = $this->resolveDuplicateDiagnosticReport($noorder, $idTemplate);

                    if ($idDiagnosticReport) {
                        $this->db->saveDiagnosticReportLabPK($noorder, $kdJenisPrw, $idTemplate, $idDiagnosticReport);
                        $this->db->updateDiagnosticReportLabPkLocalState($noorder, $idTemplate, $code, 'active');
                        $this->log->info("[PHASE 1] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: ✓ Recovered Diagnostic Report {$idDiagnosticReport} from Satu Sehat");
                        $this->successCount++;
                    } else {
                        $this->log->error("[PHASE 1] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: ✗ Failed to recover duplicate Diagnostic Report.");
                        $this->failCount++;
                    }
                } elseif ($isTerminologyError) {
                    $this->db->updateDiagnosticReportLabPkLocalState($noorder, $idTemplate, $code, 'invalid_code');
                    $this->log->warning("[PHASE 1] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: Skipped -> Validation / Terminology Error: " . $errorMessage);
                    $this->skipCount++;
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
            $records = $this->db->fetchPendingDiagnosticReportLabPKUpdate($dateFrom, $dateTo);
        }

        if (empty($records)) {
            $this->log->info("[PHASE 2] No pending Diagnostic Reports to PATCH.");
            return;
        }

        $this->log->info("[PHASE 2] Found " . count($records) . " record(s) to PATCH.");

        foreach ($records as $p) {
            $noorder = $p['noorder'];
            $idTemplate = (int)$p['id_template'];
            $kdJenisPrw = $p['kd_jenis_prw'];
            $pemeriksaan = $p['Pemeriksaan'];
            $idDiagnosticReport = $p['id_diagnosticreport'];
            $code = $p['code'] ?? '';

            // Check local SQLite state
            $localState = $this->db->getDiagnosticReportLabPkLocalState($noorder, $idTemplate, $code);
            if (in_array($localState, ['skipped', 'invalid_code'], true)) {
                $this->skipCount++;
                continue;
            }

            $idPasien = $this->db->getIhsPatient($p['nik_pasien']);
            $idDokter = $this->db->getIhsPractitioner($p['nik_dokter']);

            if (!$idPasien) {
                $this->log->warning("[PHASE 2] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: Missing IHS ID for Patient. Skipped.");
                $this->skipCount++;
                continue;
            }

            if (!$idDokter) {
                $this->log->warning("[PHASE 2] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: Missing IHS ID for Practitioner. Skipped.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::diagnosticReportLab(
                $p,
                $idPasien,
                $idDokter,
                $this->config->orgId,
                $idDiagnosticReport
            );

            $this->log->info("[PHASE 2] {$noorder} [{$kdJenisPrw}]: PATCH /DiagnosticReport/{$idDiagnosticReport} ({$nmPerawatan})");
            $result = $this->api->patch("/DiagnosticReport/{$idDiagnosticReport}", $ops);

            if ($result['success']) {
                $this->db->updateDiagnosticReportLabPkLocalState($noorder, $idTemplate, $code, 'updated');
                $this->log->info("[PHASE 2] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: ✓ Updated Diagnostic Report {$idDiagnosticReport}");
                $this->successCount++;
            } else {
                $errorMessage = $result['data']['issue'][0]['diagnostics'] ?? $result['message'];
                $isTerminologyError = (
                    $result['code'] === 400 && (
                        stripos($errorMessage, 'Code not found') !== false ||
                        stripos($errorMessage, 'not found in value set') !== false ||
                        stripos($errorMessage, 'invalid code') !== false ||
                        stripos($errorMessage, 'terminology') !== false
                    )
                );

                if ($isTerminologyError) {
                    $this->db->updateDiagnosticReportLabPkLocalState($noorder, $idTemplate, $code, 'invalid_code');
                    $this->log->warning("[PHASE 2] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: Skipped -> Validation / Terminology Error: " . $errorMessage);
                    $this->skipCount++;
                } else {
                    $this->log->warning("[PHASE 2] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: ✗ Failed -> " . $errorMessage);
                    $this->failCount++;
                }
            }
        }
    }

    private function resolveDuplicateDiagnosticReport(string $noorder, int $idTemplate): ?string
    {
        $orgId = $this->config->orgId;
        $identifier = "{$noorder}.{$idTemplate}";
        $endpoint = "/DiagnosticReport?identifier=http://sys-ids.kemkes.go.id/diagnostic/{$orgId}/lab|{$identifier}";
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
