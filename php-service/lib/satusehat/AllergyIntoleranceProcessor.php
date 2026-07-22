<?php

/**
 * AllergyIntoleranceProcessor - Orchestrator for Satu Sehat Allergy Intolerance sync.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class SatuSehatAllergyIntoleranceProcessor
{
    private SatuSehatDatabase $db;
    private SatuSehatClient $api;
    private SatuSehatConfig $config;
    private Logger $log;
    private SatuSehatAllergyDictionary $dictionary;

    private int $successCount = 0;
    private int $failCount    = 0;
    private int $skipCount    = 0;

    public function __construct(
        SatuSehatDatabase $db, 
        SatuSehatClient $api, 
        SatuSehatConfig $config, 
        Logger $log,
        SatuSehatAllergyDictionary $dictionary
    ) {
        $this->db         = $db;
        $this->api        = $api;
        $this->config     = $config;
        $this->log        = $log;
        $this->dictionary = $dictionary;
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
        $this->log->info("[SYNC] Phase 1: POST New AllergyIntolerance");
        $this->processActive($dateFrom, $dateTo, $activeRecords);

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 2: PUT Update AllergyIntolerance");
        $this->processUpdate($dateFrom, $dateTo, $updateRecords);

        return [
            'success' => $this->successCount,
            'fail'    => $this->failCount,
            'skip'    => $this->skipCount,
        ];
    }

    private function processActive(string $dateFrom, string $dateTo, ?array $patients = null): void
    {
        if ($patients === null) {
            $patients = $this->db->fetchPendingAllergyActive($dateFrom, $dateTo);
        }
        
        if (empty($patients)) {
            $this->log->info("[PHASE 1] No pending AllergyIntolerance to POST.");
            return;
        }

        $this->log->info("[PHASE 1] Found " . count($patients) . " allergy record(s) to POST.");

        foreach ($patients as $a) {
            $noRawat = $a['no_rawat'];
            $alergi = $a['alergi'];
            $statusRawat = $a['status_rawat'];
            $tglPerawatan = $a['tgl_perawatan'];
            $jamRawat = $a['jam_rawat'];

            $nikPasien = $a['no_ktp'];
            $nikPraktisi = $a['ktppraktisi'];

            $idPasien = $this->db->getIhsPatient($nikPasien);
            if (!$idPasien) {
                $this->log->warning("[PHASE 1] {$noRawat}: Missing IHS ID for Patient. Skipped.");
                $this->skipCount++;
                continue;
            }

            $idPraktisi = $this->db->getIhsPractitioner($nikPraktisi);
            if (!$idPraktisi) {
                $this->log->warning("[PHASE 1] {$noRawat}: Missing IHS ID for Practitioner. Skipped.");
                $this->skipCount++;
                continue;
            }

            // Look up mapping in dictionary
            $allergyData = $this->dictionary->lookup($alergi);

            if ($allergyData['coding_code'] === 'unknown') {
                $this->log->warning("[PHASE 1] {$noRawat}: Allergy keyword '{$alergi}' is unmapped. Skipped until mapped in cache/alergisatusehat.iyem.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::allergyIntolerance(
                $a,
                $allergyData,
                $idPasien,
                $idPraktisi,
                $this->config->orgId
            );

            $this->log->info("[PHASE 1] {$noRawat}: POST /AllergyIntolerance (Code: {$allergyData['coding_code']})");
            $result = $this->api->post('/AllergyIntolerance', $payload);

            if ($result['success'] && isset($result['data']['id'])) {
                $idAllergy = $result['data']['id'];
                $this->db->saveAllergyIntolerance($noRawat, $tglPerawatan, $jamRawat, $statusRawat, $idAllergy);
                $this->db->updateAllergyLocalState($noRawat, $tglPerawatan, $jamRawat, $alergi, 'active');
                $this->log->info("[PHASE 1] {$noRawat}: ✓ Created AllergyIntolerance {$idAllergy}");
                $this->successCount++;
            } else {
                $errorMessage = $result['data']['issue'][0]['diagnostics'] ?? $result['message'];
                
                // Duplicate Handling Fallback
                if (stripos($errorMessage, 'duplicate') !== false || $result['code'] === 409) {
                    $this->log->warning("[PHASE 1] {$noRawat}: Duplicated Allergy detected. Searching existing records...");
                    $idAllergy = $this->resolveDuplicateAllergy($idPasien, $a['id_encounter'], $allergyData['coding_code']);

                    if ($idAllergy) {
                        $this->db->saveAllergyIntolerance($noRawat, $tglPerawatan, $jamRawat, $statusRawat, $idAllergy);
                        $this->db->updateAllergyLocalState($noRawat, $tglPerawatan, $jamRawat, $alergi, 'active');
                        $this->log->info("[PHASE 1] {$noRawat}: ✓ Recovered AllergyIntolerance {$idAllergy} from Satu Sehat");
                        $this->successCount++;
                    } else {
                        $this->log->error("[PHASE 1] {$noRawat}: ✗ Failed to recover duplicate AllergyIntolerance.");
                        $this->failCount++;
                    }
                } else {
                    // Cache permanent API failures to avoid retries
                    $isPrivacy = (stripos($errorMessage, 'consent') !== false || stripos($errorMessage, 'privacy') !== false);
                    $isRule = (stripos($errorMessage, 'Rule Number') !== false || stripos($errorMessage, 'rule violation') !== false);
                    $isInvalidCode = (stripos($errorMessage, 'not found in value set') !== false || stripos($errorMessage, 'invalid code') !== false || stripos($errorMessage, 'incorrect') !== false || stripos($errorMessage, 'Code not found') !== false);

                    if ($isPrivacy) {
                        $this->db->updateAllergyLocalState($noRawat, $tglPerawatan, $jamRawat, $alergi, 'privacy_error');
                        $this->log->warning("[PHASE 1] {$noRawat}: ✗ Skipped permanently due to consent/privacy restrictions.");
                    } elseif ($isRule) {
                        $this->db->updateAllergyLocalState($noRawat, $tglPerawatan, $jamRawat, $alergi, 'failed_rule');
                        $this->log->warning("[PHASE 1] {$noRawat}: ✗ Skipped permanently due to Satu Sehat business rules.");
                    } elseif ($isInvalidCode) {
                        $this->db->updateAllergyLocalState($noRawat, $tglPerawatan, $jamRawat, $alergi, 'invalid_code');
                        $this->log->warning("[PHASE 1] {$noRawat}: ✗ Skipped permanently due to invalid allergy code mapping.");
                    } else {
                        $this->log->warning("[PHASE 1] {$noRawat}: ✗ Failed -> " . $errorMessage);
                    }
                    $this->failCount++;
                }
            }
        }
    }

    private function processUpdate(string $dateFrom, string $dateTo, ?array $patients = null): void
    {
        if ($patients === null) {
            $patients = $this->db->fetchPendingAllergyUpdate($dateFrom, $dateTo);
        }

        if (empty($patients)) {
            $this->log->info("[PHASE 2] No pending AllergyIntolerance to PATCH.");
            return;
        }

        $this->log->info("[PHASE 2] Found " . count($patients) . " allergy record(s) to PATCH.");

        foreach ($patients as $a) {
            $noRawat = $a['no_rawat'];
            $alergi = $a['alergi'];
            $statusRawat = $a['status_rawat'];
            $tglPerawatan = $a['tgl_perawatan'];
            $jamRawat = $a['jam_rawat'];
            $idAllergy = $a['id_allergy_intolerance'];

            $localState = $this->db->getAllergyLocalState($noRawat, $tglPerawatan, $jamRawat, $alergi);

            if ($localState === 'updated') {
                $this->skipCount++;
                continue;
            }

            // Build PATCH operations to confirm clinical + verification status
            $ops = [
                [
                    'op' => 'replace',
                    'path' => '/clinicalStatus',
                    'value' => [
                        'coding' => [
                            [
                                'system'  => 'http://terminology.hl7.org/CodeSystem/allergyintolerance-clinical',
                                'code'    => 'active',
                                'display' => 'Active'
                            ]
                        ]
                    ]
                ],
                [
                    'op' => 'replace',
                    'path' => '/verificationStatus',
                    'value' => [
                        'coding' => [
                            [
                                'system'  => 'http://terminology.hl7.org/CodeSystem/allergyintolerance-verification',
                                'code'    => 'confirmed',
                                'display' => 'Confirmed'
                            ]
                        ]
                    ]
                ]
            ];

            $this->log->info("[PHASE 2] {$noRawat}: PATCH /AllergyIntolerance/{$idAllergy} (" . count($ops) . " ops)");
            $result = $this->api->patch("/AllergyIntolerance/{$idAllergy}", $ops);

            if ($result['success']) {
                $this->db->updateAllergyLocalState($noRawat, $tglPerawatan, $jamRawat, $alergi, 'updated');
                $this->log->info("[PHASE 2] {$noRawat}: ✓ Updated AllergyIntolerance {$idAllergy} via PATCH");
                $this->successCount++;
            } else {
                $this->log->warning("[PHASE 2] {$noRawat}: ✗ Failed -> " . ($result['data']['issue'][0]['diagnostics'] ?? $result['message']));
                $this->failCount++;
            }
        }
    }

    /**
     * Resolves a duplicate AllergyIntolerance by searching the Satu Sehat API.
     */
    private function resolveDuplicateAllergy(string $idPasien, string $idEncounter, string $snomedCode): ?string
    {
        // Query by patient and encounter.
        $endpoint = "/AllergyIntolerance?patient={$idPasien}&encounter={$idEncounter}";
        $result = $this->api->get($endpoint);

        if (!$result['success'] || empty($result['data']['entry'])) {
            return null;
        }

        foreach ($result['data']['entry'] as $entry) {
            $res = $entry['resource'] ?? [];
            
            // Look for matching SNOMED code
            $resCode = $res['code']['coding'][0]['code'] ?? '';
            
            if ($resCode === $snomedCode) {
                return $res['id']; // Found the matching duplicate!
            }
        }

        return null;
    }
}
