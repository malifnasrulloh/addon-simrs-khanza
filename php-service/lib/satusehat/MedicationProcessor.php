<?php

/**
 * MedicationProcessor - Orchestrator for Satu Sehat Medication sync.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class SatuSehatMedicationProcessor
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

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 1: POST New Medication Master");
        $this->processActive($activeRecords);

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 2: PUT Update Medication Master");
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
            $records = $this->db->fetchPendingMedicationActive();
        }
        
        if (empty($records)) {
            $this->log->info("[PHASE 1] No pending Medication to POST.");
            return;
        }

        $this->log->info("[PHASE 1] Found " . count($records) . " medication record(s) to POST.");

        foreach ($records as $med) {
            $kodeBrng = $med['kode_brng'];

            $payload = SatuSehatPayloadBuilder::medication(
                $this->config->orgId,
                $med
            );

            $this->log->info("[PHASE 1] {$kodeBrng}: POST /Medication (KFA Code: {$med['obat_code']}, Display: {$med['obat_display']})");
            $result = $this->api->post('/Medication', $payload);

            if ($result['success'] && isset($result['data']['id'])) {
                $idMedication = $result['data']['id'];
                $this->db->saveMedication($kodeBrng, $idMedication);
                $this->db->updateMedicationLocalState($kodeBrng, 'active');
                $this->log->info("[PHASE 1] {$kodeBrng}: ✓ Created Medication {$idMedication}");
                $this->successCount++;
            } else {
                $errorMessage = $result['data']['issue'][0]['diagnostics'] ?? $result['message'];
                
                // Duplicate Handling Fallback
                if (stripos($errorMessage, 'duplicate') !== false || $result['code'] === 409 || $result['code'] === 400) {
                    $this->log->warning("[PHASE 1] {$kodeBrng}: Duplicated Medication detected. Searching existing records...");
                    $idMedication = $this->resolveDuplicateMedication($kodeBrng);

                    if ($idMedication) {
                        $this->db->saveMedication($kodeBrng, $idMedication);
                        $this->db->updateMedicationLocalState($kodeBrng, 'active');
                        $this->log->info("[PHASE 1] {$kodeBrng}: ✓ Recovered Medication {$idMedication} from Satu Sehat API");
                        $this->successCount++;
                    } else {
                        $this->log->error("[PHASE 1] {$kodeBrng}: ✗ Failed to recover duplicate Medication.");
                        $this->failCount++;
                    }
                } else {
                    $this->log->warning("[PHASE 1] {$kodeBrng}: ✗ Failed -> " . $errorMessage);
                    $this->failCount++;
                }
            }
        }
    }

    private function processUpdate(?array $records = null): void
    {
        if ($records === null) {
            $records = $this->db->fetchPendingMedicationUpdate();
        }

        if (empty($records)) {
            $this->log->info("[PHASE 2] No pending Medication to PUT.");
            return;
        }

        $this->log->info("[PHASE 2] Found " . count($records) . " medication record(s) to PUT.");

        foreach ($records as $med) {
            $kodeBrng = $med['kode_brng'];
            $idMedication = $med['id_medication'];

            $localState = $this->db->getMedicationLocalState($kodeBrng);

            if ($localState === 'updated') {
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::medication(
                $this->config->orgId,
                $med,
                $idMedication
            );

            $this->log->info("[PHASE 2] {$kodeBrng}: PUT /Medication/{$idMedication} (KFA Code: {$med['obat_code']})");
            $result = $this->api->put("/Medication/{$idMedication}", $payload);

            if ($result['success']) {
                $this->db->updateMedicationLocalState($kodeBrng, 'updated');
                $this->log->info("[PHASE 2] {$kodeBrng}: ✓ Updated Medication {$idMedication}");
                $this->successCount++;
            } else {
                $this->log->warning("[PHASE 2] {$kodeBrng}: ✗ Failed -> " . ($result['data']['issue'][0]['diagnostics'] ?? $result['message']));
                $this->failCount++;
            }
        }
    }

    /**
     * Resolves a duplicate Medication by searching the Satu Sehat API.
     */
    private function resolveDuplicateMedication(string $kodeBrng): ?string
    {
        $endpoint = "/Medication?identifier=http://sys-ids.kemkes.go.id/medication/{$this->config->orgId}|" . urlencode($kodeBrng);
        $result = $this->api->get($endpoint);

        if (!$result['success'] || empty($result['data']['entry'])) {
            return null;
        }

        foreach ($result['data']['entry'] as $entry) {
            $res = $entry['resource'] ?? [];
            if (isset($res['id'])) {
                return $res['id']; // Match found
            }
        }

        return null;
    }
}
