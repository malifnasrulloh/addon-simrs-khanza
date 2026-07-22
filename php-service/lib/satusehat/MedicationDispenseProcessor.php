<?php

/**
 * SIMRS Khanza - Satu Sehat MedicationDispense Processor
 *
 * Orchestrates dual-phase MedicationDispense synchronization (POST/PUT) with SQLite state cache
 * and defensive duplicate resolution.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 * @version 1.0.0
 */

declare(strict_types=1);

class SatuSehatMedicationDispenseProcessor
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

        $this->log->info("  Date Range: {$this->config->dateFrom} to {$this->config->dateTo} (Configured)");

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 1: POST New MedicationDispense");
        $this->processActive($activeRecords);

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 2: PUT Update MedicationDispense");
        $this->processUpdate($updateRecords);

        return [
            'success' => $this->successCount,
            'fail'    => $this->failCount,
            'skip'    => $this->skipCount,
        ];
    }

    private function processActive(?array $records = null): void
    {
        if ($records === null) {
            $records = $this->db->fetchPendingMedicationDispenseActive($this->config->dateFrom, $this->config->dateTo);
        }

        if (empty($records)) {
            $this->log->info("[PHASE 1] No pending MedicationDispense to POST.");
            return;
        }

        $this->log->info("[PHASE 1] Found " . count($records) . " MedicationDispense record(s) to POST.");

        foreach ($records as $p) {
            $noRawat = $p['no_rawat'];
            $tglPerawatan = $p['tgl_perawatan'];
            $jam = $p['jam'];
            $kodeBrng = $p['kode_brng'];
            $noBatch = $p['no_batch'];
            $noFaktur = $p['no_faktur'];
            $noResep = $p['no_resep'];
            $statusPemberian = $p['status_pemberian'];

            $localState = $this->db->getMedicationDispenseLocalState($noRawat, $tglPerawatan, $jam, $kodeBrng, $noBatch, $noFaktur);
            if (in_array($localState, ['active', 'updated', 'privacy_error', 'failed_rule', 'invalid_code'], true)) {
                $this->skipCount++;
                continue;
            }

            // Look up Patient ID
            $idPasien = $this->db->getIhsPatient($p['no_ktp']);
            if (!$idPasien) {
                $this->log->warning("[PHASE 1] [SKIPPED] Patient No.RM: {$p['no_rkm_medis']} (No.KTP: {$p['no_ktp']}) has no valid IHS ID.");
                $this->failCount++;
                continue;
            }

            // Look up Practitioner ID
            $idDokter = $this->db->getIhsPractitioner($p['ktppraktisi']);
            if (!$idDokter) {
                $this->log->warning("[PHASE 1] [SKIPPED] Practitioner Name: {$p['nama']} (No.KTP: {$p['ktppraktisi']}) has no valid IHS ID.");
                $this->failCount++;
                continue;
            }

            // Authorizing prescription lookup (MedicationRequest)
            $idMedicationRequest = $this->db->getMedicationRequestId($noResep, $kodeBrng);
            if (empty($idMedicationRequest)) {
                $reqState = $this->db->getMedicationRequestLocalState($noResep, $kodeBrng, '');
                if (in_array($reqState, ['privacy_error', 'failed_rule', 'invalid_code'], true)) {
                    $this->log->warning("[PHASE 1] [SKIPPED] Authorizing MedicationRequest has terminal failure state '{$reqState}'. Marking MedicationDispense as failed_rule.");
                    $this->db->updateMedicationDispenseLocalState($noRawat, $tglPerawatan, $jam, $kodeBrng, $noBatch, $noFaktur, 'failed_rule');
                    $this->skipCount++;
                    continue;
                }
                $this->log->warning("[PHASE 1] [SKIPPED] Authorizing MedicationRequest not found or not yet synced for Resep: {$noResep}, Kode Barang: {$kodeBrng}. MedicationDispense must wait.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::medicationDispense(
                $this->config->orgId,
                $p,
                $idPasien,
                $idDokter,
                $idMedicationRequest
            );

            $this->log->info("[PHASE 1] {$noRawat} [{$statusPemberian}]: POST /MedicationDispense (Resep: {$noResep}, Kode Barang: {$kodeBrng})");
            $result = $this->api->post('/MedicationDispense', $payload);

            if ($result['success'] && isset($result['data']['id'])) {
                $idDispense = $result['data']['id'];
                $this->db->saveMedicationDispense($noRawat, $tglPerawatan, $jam, $kodeBrng, $noBatch, $noFaktur, $idDispense);
                $this->db->updateMedicationDispenseLocalState($noRawat, $tglPerawatan, $jam, $kodeBrng, $noBatch, $noFaktur, 'active');
                $this->log->info("[PHASE 1] {$noRawat}: ✓ Created MedicationDispense {$idDispense}");
                $this->successCount++;
            } else {
                $errorMessage = $result['data']['issue'][0]['diagnostics'] ?? $result['message'];

                if (stripos($errorMessage, 'duplicate') !== false || $result['code'] === 409) {
                    $this->log->warning("[PHASE 1] {$noRawat}: Duplicated MedicationDispense detected. Searching existing records...");
                    $idDispense = $this->resolveDuplicateMedicationDispense($noResep, $kodeBrng);

                    if ($idDispense) {
                        $this->db->saveMedicationDispense($noRawat, $tglPerawatan, $jam, $kodeBrng, $noBatch, $noFaktur, $idDispense);
                        $this->db->updateMedicationDispenseLocalState($noRawat, $tglPerawatan, $jam, $kodeBrng, $noBatch, $noFaktur, 'active');
                        $this->log->info("[PHASE 1] {$noRawat}: ✓ Recovered MedicationDispense {$idDispense} from Satu Sehat API");
                        $this->successCount++;
                    } else {
                        $this->log->error("[PHASE 1] {$noRawat}: ✗ Failed to recover duplicate MedicationDispense.");
                        $this->failCount++;
                    }
                } else {
                    $isPrivacy = (stripos($errorMessage, 'consent') !== false || stripos($errorMessage, 'privacy') !== false);
                    $isRule = (stripos($errorMessage, 'rule') !== false || stripos($errorMessage, 'RuleNumber') !== false);
                    $isCode = (stripos($errorMessage, 'code') !== false || stripos($errorMessage, 'system') !== false || stripos($errorMessage, 'terminology') !== false);

                    if ($isPrivacy) {
                        $this->db->updateMedicationDispenseLocalState($noRawat, $tglPerawatan, $jam, $kodeBrng, $noBatch, $noFaktur, 'privacy_error');
                        $this->log->warning("[PHASE 1] {$noRawat}: ✗ Permanent Privacy Error -> {$errorMessage}");
                    } elseif ($isRule) {
                        $this->db->updateMedicationDispenseLocalState($noRawat, $tglPerawatan, $jam, $kodeBrng, $noBatch, $noFaktur, 'failed_rule');
                        $this->log->warning("[PHASE 1] {$noRawat}: ✗ Permanent Rule Error -> {$errorMessage}");
                    } elseif ($isCode) {
                        $this->db->updateMedicationDispenseLocalState($noRawat, $tglPerawatan, $jam, $kodeBrng, $noBatch, $noFaktur, 'invalid_code');
                        $this->log->warning("[PHASE 1] {$noRawat}: ✗ Permanent Code Error -> {$errorMessage}");
                    } else {
                        $this->log->warning("[PHASE 1] {$noRawat}: ✗ Failed -> " . $errorMessage);
                    }
                    $this->failCount++;
                }
            }
        }
    }

    private function processUpdate(?array $records = null): void
    {
        if ($records === null) {
            $records = $this->db->fetchPendingMedicationDispenseUpdate($this->config->dateFrom, $this->config->dateTo);
        }

        if (empty($records)) {
            $this->log->info("[PHASE 2] No pending MedicationDispense to PATCH.");
            return;
        }

        $this->log->info("[PHASE 2] Found " . count($records) . " MedicationDispense record(s) to PATCH.");

        foreach ($records as $p) {
            $noRawat = $p['no_rawat'];
            $tglPerawatan = $p['tgl_perawatan'];
            $jam = $p['jam'];
            $kodeBrng = $p['kode_brng'];
            $noBatch = $p['no_batch'];
            $noFaktur = $p['no_faktur'];
            $noResep = $p['no_resep'];
            $idDispense = $p['id_medicationdispanse'];
            $statusPemberian = $p['status_pemberian'];

            $localState = $this->db->getMedicationDispenseLocalState($noRawat, $tglPerawatan, $jam, $kodeBrng, $noBatch, $noFaktur);
            if (in_array($localState, ['updated', 'privacy_error', 'failed_rule', 'invalid_code'], true)) {
                $this->skipCount++;
                continue;
            }

            // Build PATCH operations — confirm completed status
            $ops = [
                [
                    'op' => 'replace',
                    'path' => '/status',
                    'value' => 'completed'
                ]
            ];

            $this->log->info("[PHASE 2] {$noRawat} [{$statusPemberian}]: PATCH /MedicationDispense/{$idDispense} (" . count($ops) . " ops)");
            $result = $this->api->patch("/MedicationDispense/{$idDispense}", $ops);

            if ($result['success']) {
                $this->db->updateMedicationDispenseLocalState($noRawat, $tglPerawatan, $jam, $kodeBrng, $noBatch, $noFaktur, 'updated');
                $this->log->info("[PHASE 2] {$noRawat}: ✓ Updated MedicationDispense {$idDispense}");
                $this->successCount++;
            } else {
                $errorMessage = $result['data']['issue'][0]['diagnostics'] ?? $result['message'];
                $isPrivacy = (stripos($errorMessage, 'consent') !== false || stripos($errorMessage, 'privacy') !== false);
                $isRule = (stripos($errorMessage, 'rule') !== false || stripos($errorMessage, 'RuleNumber') !== false);
                $isCode = (stripos($errorMessage, 'code') !== false || stripos($errorMessage, 'system') !== false || stripos($errorMessage, 'terminology') !== false);

                if ($isPrivacy) {
                    $this->db->updateMedicationDispenseLocalState($noRawat, $tglPerawatan, $jam, $kodeBrng, $noBatch, $noFaktur, 'privacy_error');
                    $this->log->warning("[PHASE 2] {$noRawat}: ✗ Permanent Privacy Error -> {$errorMessage}");
                } elseif ($isRule) {
                    $this->db->updateMedicationDispenseLocalState($noRawat, $tglPerawatan, $jam, $kodeBrng, $noBatch, $noFaktur, 'failed_rule');
                    $this->log->warning("[PHASE 2] {$noRawat}: ✗ Permanent Rule Error -> {$errorMessage}");
                } elseif ($isCode) {
                    $this->db->updateMedicationDispenseLocalState($noRawat, $tglPerawatan, $jam, $kodeBrng, $noBatch, $noFaktur, 'invalid_code');
                    $this->log->warning("[PHASE 2] {$noRawat}: ✗ Permanent Code Error -> {$errorMessage}");
                } else {
                    $this->log->warning("[PHASE 2] {$noRawat}: ✗ Failed -> " . $errorMessage);
                }
                $this->failCount++;
            }
        }
    }

    /**
     * Resolves a duplicate MedicationDispense using Satu Sehat identifier checks.
     */
    private function resolveDuplicateMedicationDispense(string $noResep, string $kodeBrng): ?string
    {
        $endpoint = "/MedicationDispense?identifier=http://sys-ids.kemkes.go.id/prescription/{$this->config->orgId}|" . urlencode($noResep);
        $result = $this->api->get($endpoint);

        if (!$result['success'] || empty($result['data']['entry'])) {
            $endpoint = "/MedicationDispense?identifier=http://sys-ids.kemkes.go.id/medicationdispense/{$this->config->orgId}|" . urlencode($noResep);
            $result = $this->api->get($endpoint);
            if (!$result['success'] || empty($result['data']['entry'])) {
                return null;
            }
        }

        foreach ($result['data']['entry'] as $entry) {
            $res = $entry['resource'] ?? [];
            
            $matchResep = false;
            $matchItem = false;
            
            if (!empty($res['identifier'])) {
                foreach ($res['identifier'] as $ident) {
                    $system = $ident['system'] ?? '';
                    $val = $ident['value'] ?? '';
                    
                    if (strpos($system, 'prescription') !== false || strpos($system, 'medicationdispense') !== false) {
                        if ($val === $noResep) {
                            $matchResep = true;
                        }
                    }
                    if (strpos($system, 'prescription-item') !== false || strpos($system, 'medicationdispense-item') !== false) {
                        if ($val === $kodeBrng) {
                            $matchItem = true;
                        }
                    }
                }
            }
            
            if ($matchResep && $matchItem && isset($res['id'])) {
                return $res['id'];
            }
        }

        return null;
    }
}
