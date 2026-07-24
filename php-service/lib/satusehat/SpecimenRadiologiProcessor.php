<?php

/**
 * SpecimenRadiologiProcessor - Orchestrator for Satu Sehat Specimen (Radiologi) sync.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class SatuSehatSpecimenRadiologiProcessor
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
        $this->log->info("[SYNC] Phase 1: POST New Specimen (Radiologi)");
        $this->processActive($dateFrom, $dateTo, $activeRecords);

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 2: PUT Update Specimen (Radiologi)");
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
            $records = $this->db->fetchPendingSpecimenRadiologiActive($dateFrom, $dateTo);
        }
        
        if (empty($records)) {
            $this->log->info("[PHASE 1] No pending Specimens to POST.");
            return;
        }

        $this->log->info("[PHASE 1] Found " . count($records) . " record(s) to POST.");

        foreach ($records as $p) {
            $noorder = $p['noorder'];
            $kdJenisPrw = $p['kd_jenis_prw'];
            $nmPerawatan = $p['nm_perawatan'];

            // Local state check to prevent resubmitting terminal failures
            $localState = $this->db->getSpecimenRadiologiLocalState($noorder, $kdJenisPrw);
            if ($localState === 'active' || $localState === 'updated' || in_array($localState, ['privacy_error', 'failed_rule', 'invalid_code'], true)) {
                $this->skipCount++;
                continue;
            }

            // Dependency check: ServiceRequest must be synced first
            if (empty($p['id_servicerequest']) || $p['id_servicerequest'] === '-') {
                $this->log->warning("[PHASE 1] {$noorder} [{$kdJenisPrw}]: Missing ServiceRequest ID. Skipped.");
                $this->db->updateSpecimenRadiologiLocalState($noorder, $kdJenisPrw, 'skipped_missing_servicerequest');
                $this->skipCount++;
                continue;
            }

            $idPasien = $this->db->getIhsPatient($p['nik_pasien']);

            if (!$idPasien) {
                $this->log->warning("[PHASE 1] {$noorder} [{$kdJenisPrw}]: Missing IHS ID for Patient. Skipped.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::specimenRadiologi(
                $p,
                $idPasien,
                $this->config->orgId
            );

            $this->log->info("[PHASE 1] {$noorder} [{$kdJenisPrw}]: POST /Specimen ({$nmPerawatan})");
            $result = $this->api->post('/Specimen', $payload);

            if ($result['success'] && isset($result['data']['id'])) {
                $idSpecimen = $result['data']['id'];
                $this->db->saveSpecimenRadiologi($noorder, $kdJenisPrw, $idSpecimen);
                $this->db->updateSpecimenRadiologiLocalState($noorder, $kdJenisPrw, 'active');
                $this->log->info("[PHASE 1] {$noorder} [{$kdJenisPrw}]: ✓ Created Specimen {$idSpecimen}");
                $this->successCount++;
            } else {
                $errorMessage = $result['data']['issue'][0]['details']['text'] ?? $result['data']['issue'][0]['diagnostics'] ?? $result['message'];
                
                // Duplicate Handling Fallback using identifier
                $isDuplicate = false;
                if (stripos($errorMessage, 'duplicate') !== false || $result['code'] === 409) {
                    $isDuplicate = true;
                } elseif ($result['code'] === 400 && isset($result['data']['issue'])) {
                    foreach ($result['data']['issue'] as $issue) {
                        $issueText = $issue['details']['text'] ?? $issue['diagnostics'] ?? '';
                        if (stripos($issueText, 'duplicate') !== false || stripos($issueText, 'RuleNumber: 20002') !== false) {
                            $isDuplicate = true;
                            break;
                        }
                    }
                }

                if ($isDuplicate) {
                    $this->log->warning("[PHASE 1] {$noorder} [{$kdJenisPrw}]: Duplicated Specimen detected. Searching existing records...");
                    $idSpecimen = $this->resolveDuplicateSpecimen($noorder, $kdJenisPrw);

                    if ($idSpecimen) {
                        $this->db->saveSpecimenRadiologi($noorder, $kdJenisPrw, $idSpecimen);
                        $this->db->updateSpecimenRadiologiLocalState($noorder, $kdJenisPrw, 'active');
                        $this->log->info("[PHASE 1] {$noorder} [{$kdJenisPrw}]: ✓ Recovered Specimen {$idSpecimen} from Satu Sehat");
                        $this->successCount++;
                    } else {
                        $this->log->error("[PHASE 1] {$noorder} [{$kdJenisPrw}]: ✗ Failed to recover duplicate Specimen.");
                        $this->failCount++;
                    }
                } else {
                    $this->log->warning("[PHASE 1] {$noorder} [{$kdJenisPrw}]: ✗ Failed -> " . $errorMessage);
                    
                    // Categorize and cache permanent/terminal failures
                    $state = 'fail';
                    $isTransient = ($result['code'] === 429 || ($result['code'] >= 500 && $result['code'] <= 599) || $result['code'] === 0);
                    if (!$isTransient) {
                        if (stripos($errorMessage, 'consent') !== false || stripos($errorMessage, 'privacy') !== false) {
                            $state = 'privacy_error';
                        } elseif (stripos($errorMessage, 'rule') !== false || stripos($errorMessage, 'RuleNumber') !== false || stripos($errorMessage, 'date') !== false) {
                            $state = 'failed_rule';
                        } elseif (stripos($errorMessage, 'code') !== false || stripos($errorMessage, 'system') !== false || stripos($errorMessage, 'terminology') !== false) {
                            $state = 'invalid_code';
                        }

                        if ($state !== 'fail') {
                            $this->db->updateSpecimenRadiologiLocalState($noorder, $kdJenisPrw, $state);
                        }
                    }
                    $this->failCount++;
                }
            }
        }
    }

    private function processUpdate(string $dateFrom, string $dateTo, ?array $records = null): void
    {
        if ($records === null) {
            $records = $this->db->fetchPendingSpecimenRadiologiUpdate($dateFrom, $dateTo);
        }

        if (empty($records)) {
            $this->log->info("[PHASE 2] No pending to PATCH.");
            return;
        }

        $this->log->info("[PHASE 2] Found " . count($records) . " record(s) to PATCH.");

        foreach ($records as $p) {
            $noorder = $p['noorder'];
            $kdJenisPrw = $p['kd_jenis_prw'];
            $nmPerawatan = $p['nm_perawatan'];
            $idSpecimen = $p['id_specimen'];

            // Local state check to prevent resubmitting terminal failures
            $localState = $this->db->getSpecimenRadiologiLocalState($noorder, $kdJenisPrw);
            if ($localState === 'updated' || in_array($localState, ['privacy_error', 'failed_rule', 'invalid_code'], true)) {
                $this->skipCount++;
                continue;
            }

            // Dependency check: ServiceRequest must be synced first
            if (empty($p['id_servicerequest']) || $p['id_servicerequest'] === '-') {
                $this->log->warning("[PHASE 2] {$noorder} [{$kdJenisPrw}]: Missing ServiceRequest ID. Skipped.");
                $this->db->updateSpecimenRadiologiLocalState($noorder, $kdJenisPrw, 'skipped_missing_servicerequest');
                $this->skipCount++;
                continue;
            }

            $idPasien = $this->db->getIhsPatient($p['nik_pasien']);

            if (!$idPasien) {
                $this->log->warning("[PHASE 2] {$noorder} [{$kdJenisPrw}]: Missing IHS ID for Patient. Skipped.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::specimenRadiologi(
                $p,
                $idPasien,
                $this->config->orgId
            );
            $ops = SatuSehatPayloadBuilder::payloadToPatchOps($payload);

            $this->log->info("[PHASE 2] {$noorder} [{$kdJenisPrw}]: PATCH /Specimen/{$idSpecimen} ({$nmPerawatan})");
            $result = $this->api->patch("/Specimen/{$idSpecimen}", $ops, $payload);

            if ($result['success']) {
                $this->db->updateSpecimenRadiologiLocalState($noorder, $kdJenisPrw, 'updated');
                $this->log->info("[PHASE 2] {$noorder} [{$kdJenisPrw}]: ✓ Updated Specimen {$idSpecimen}");
                $this->successCount++;
            } else {
                $errorMessage = $result['data']['issue'][0]['details']['text'] ?? $result['data']['issue'][0]['diagnostics'] ?? $result['message'];
                $this->log->warning("[PHASE 2] {$noorder} [{$kdJenisPrw}]: ✗ Failed -> " . $errorMessage);
                
                // Categorize and cache permanent/terminal failures
                $state = 'fail';
                $isTransient = ($result['code'] === 429 || ($result['code'] >= 500 && $result['code'] <= 599) || $result['code'] === 0);
                if (!$isTransient) {
                    if (stripos($errorMessage, 'consent') !== false || stripos($errorMessage, 'privacy') !== false) {
                        $state = 'privacy_error';
                    } elseif (stripos($errorMessage, 'rule') !== false || stripos($errorMessage, 'RuleNumber') !== false || stripos($errorMessage, 'date') !== false) {
                        $state = 'failed_rule';
                    } elseif (stripos($errorMessage, 'code') !== false || stripos($errorMessage, 'system') !== false || stripos($errorMessage, 'terminology') !== false) {
                        $state = 'invalid_code';
                    }

                    if ($state !== 'fail') {
                        $this->db->updateSpecimenRadiologiLocalState($noorder, $kdJenisPrw, $state);
                    }
                }
                $this->failCount++;
            }
        }
    }

    private function resolveDuplicateSpecimen(string $noorder, string $kdJenisPrw): ?string
    {
        $orgId = $this->config->orgId;
        $identifier = "{$noorder}.{$kdJenisPrw}";
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
