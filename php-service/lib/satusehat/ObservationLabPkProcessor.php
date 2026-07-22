<?php

/**
 * ObservationLabPkProcessor - Orchestrator for Satu Sehat Observation (Lab PK) sync.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class SatuSehatObservationLabPkProcessor
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
        $this->log->info("[SYNC] Phase 1: POST New Observation (Lab PK)");
        $this->processActive($dateFrom, $dateTo, $activeRecords);

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 2: PUT Update Observation (Lab PK)");
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
            $records = $this->db->fetchPendingObservationLabPKActive($dateFrom, $dateTo);
        }
        
        if (empty($records)) {
            $this->log->info("[PHASE 1] No pending Observations to POST.");
            return;
        }

        $this->log->info("[PHASE 1] Found " . count($records) . " record(s) to POST.");

        foreach ($records as $p) {
            $noorder = $p['noorder'];
            $idTemplate = (int)$p['id_template'];
            $kdJenisPrw = $p['kd_jenis_prw'];
            $pemeriksaan = $p['Pemeriksaan'];

            // SQLite Local State Check
            $localState = $this->db->getObservationLabPKLocalState($noorder, $kdJenisPrw, $idTemplate);
            if ($localState === 'sent' || in_array($localState, ['privacy_error', 'failed_rule', 'invalid_code'], true)) {
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

            $payload = SatuSehatPayloadBuilder::observationLab(
                $p,
                $idPasien,
                $idDokter,
                $this->config->orgId
            );

            $this->log->info("[PHASE 1] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: POST /Observation ({$pemeriksaan})");
            $result = $this->api->post('/Observation', $payload);

            if ($result['success'] && isset($result['data']['id'])) {
                $idObservation = $result['data']['id'];
                $this->db->saveObservationLabPK($noorder, $kdJenisPrw, $idTemplate, $idObservation);
                $this->db->updateObservationLabPKLocalState($noorder, $kdJenisPrw, $idTemplate, 'sent');
                $this->log->info("[PHASE 1] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: ✓ Created Observation {$idObservation}");
                $this->successCount++;
            } else {
                $errorMessage = $result['data']['issue'][0]['diagnostics'] ?? $result['message'];
                
                // Duplicate Handling Fallback using identifier
                if (stripos($errorMessage, 'duplicate') !== false || $result['code'] === 409) {
                    $this->log->warning("[PHASE 1] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: Duplicated Observation detected. Searching existing records...");
                    $idObservation = $this->resolveDuplicateObservation($noorder, $idTemplate);

                    if ($idObservation) {
                        $this->db->saveObservationLabPK($noorder, $kdJenisPrw, $idTemplate, $idObservation);
                        $this->db->updateObservationLabPKLocalState($noorder, $kdJenisPrw, $idTemplate, 'sent');
                        $this->log->info("[PHASE 1] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: ✓ Recovered Observation {$idObservation} from Satu Sehat");
                        $this->successCount++;
                    } else {
                        $this->log->error("[PHASE 1] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: ✗ Failed to recover duplicate Observation.");
                        $this->failCount++;
                    }
                } else {
                    $this->log->warning("[PHASE 1] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: ✗ Failed -> " . $errorMessage);
                    
                    // Categorize and cache permanent/terminal failures
                    $state = 'fail';
                    if (stripos($errorMessage, 'consent') !== false || stripos($errorMessage, 'privacy') !== false) {
                        $state = 'privacy_error';
                    } elseif (stripos($errorMessage, 'rule') !== false || stripos($errorMessage, 'RuleNumber') !== false) {
                        $state = 'failed_rule';
                    } elseif (stripos($errorMessage, 'code') !== false || stripos($errorMessage, 'system') !== false || stripos($errorMessage, 'terminology') !== false) {
                        $state = 'invalid_code';
                    }
                    
                    $this->db->updateObservationLabPKLocalState($noorder, $kdJenisPrw, $idTemplate, $state);
                    $this->failCount++;
                }
            }
        }
    }

    private function processUpdate(string $dateFrom, string $dateTo, ?array $records = null): void
    {
        if ($records === null) {
            $records = $this->db->fetchPendingObservationLabPKUpdate($dateFrom, $dateTo);
        }

        if (empty($records)) {
            $this->log->info("[PHASE 2] No pending to PATCH.");
            return;
        }

        $this->log->info("[PHASE 2] Found " . count($records) . " record(s) to PATCH.");

        foreach ($records as $p) {
            $noorder = $p['noorder'];
            $idTemplate = (int)$p['id_template'];
            $kdJenisPrw = $p['kd_jenis_prw'];
            $pemeriksaan = $p['Pemeriksaan'];
            $idObservation = $p['id_observation'];

            // SQLite Local State Check
            $localState = $this->db->getObservationLabPKLocalState($noorder, $kdJenisPrw, $idTemplate);
            if ($localState === 'sent' || in_array($localState, ['privacy_error', 'failed_rule', 'invalid_code'], true)) {
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

            $ops = [
                [
                    'op' => 'replace',
                    'path' => '/status',
                    'value' => 'final'
                ]
            ];

            $this->log->info("[PHASE 2] {$noorder} [{$kdJenisPrw}]: PATCH /Observation/{$idObservation} ({$nmPerawatan})");
            $result = $this->api->patch("/Observation/{$idObservation}", $ops);

            if ($result['success']) {
                $this->db->updateObservationLabPKLocalState($noorder, $kdJenisPrw, $idTemplate, 'sent');
                $this->log->info("[PHASE 2] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: ✓ Updated Observation {$idObservation}");
                $this->successCount++;
            } else {
                $errorMessage = $result['data']['issue'][0]['diagnostics'] ?? $result['message'];
                $this->log->warning("[PHASE 2] {$noorder} [{$idTemplate}/{$kdJenisPrw}]: ✗ Failed -> " . $errorMessage);
                
                // Categorize and cache permanent/terminal failures
                $state = 'fail';
                if (stripos($errorMessage, 'consent') !== false || stripos($errorMessage, 'privacy') !== false) {
                    $state = 'privacy_error';
                } elseif (stripos($errorMessage, 'rule') !== false || stripos($errorMessage, 'RuleNumber') !== false) {
                    $state = 'failed_rule';
                } elseif (stripos($errorMessage, 'code') !== false || stripos($errorMessage, 'system') !== false || stripos($errorMessage, 'terminology') !== false) {
                    $state = 'invalid_code';
                }
                
                $this->db->updateObservationLabPKLocalState($noorder, $kdJenisPrw, $idTemplate, $state);
                $this->failCount++;
            }
        }
    }

    private function resolveDuplicateObservation(string $noorder, int $idTemplate): ?string
    {
        $orgId = $this->config->orgId;
        $identifier = "{$noorder}.{$idTemplate}";
        $endpoint = "/Observation?identifier=http://sys-ids.kemkes.go.id/observation/{$orgId}|{$identifier}";
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
