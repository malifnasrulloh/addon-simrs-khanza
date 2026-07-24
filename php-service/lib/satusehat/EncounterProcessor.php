<?php

/**
 * EncounterProcessor - Orchestrator for Satu Sehat Encounter sync.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class SatuSehatEncounterProcessor
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

    public function run(?array $arrivedRecords = null, ?array $inProgressRecords = null, ?array $finishedRecords = null): array
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
        $this->log->info("[SYNC] Phase 1: POST 'arrived' Encounters");
        $this->processArrived($dateFrom, $dateTo, $arrivedRecords);

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 2: PUT 'in-progress' Encounters");
        $this->processInProgress($dateFrom, $dateTo, $inProgressRecords);

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 3: PUT 'finished' Encounters");
        $this->processFinished($dateFrom, $dateTo, $finishedRecords);

        return [
            'success' => $this->successCount,
            'fail'    => $this->failCount,
            'skip'    => $this->skipCount,
        ];
    }

    private function processArrived(string $dateFrom, string $dateTo, ?array $patients = null): void
    {
        if ($patients === null) {
            $patients = $this->db->fetchPendingArrived($dateFrom, $dateTo);
        }

        if (empty($patients)) {
            $this->log->info("[PHASE 1] No pending 'arrived' encounters.");
            return;
        }

        $this->log->info("[PHASE 1] Found " . count($patients) . " patient(s) to arrive.");

        foreach ($patients as $p) {
            $noRawat = $p['no_rawat'];
            $nik = $p['no_ktp'];
            $nikDokter = $p['ktpdokter'];

            $idPasien = $this->db->getIhsPatient($nik);
            $idDokter = $this->db->getIhsPractitioner($nikDokter);

            if (!$idPasien || !$idDokter) {
                $this->log->warning("[PHASE 1] {$noRawat}: Missing IHS ID for Patient or Doctor. Skipped.");
                $this->skipCount++;
                continue;
            }

            // Determine target status:
            // - Ranap: POST directly at 'in-progress' (admission time)
            // - Ralan/IGD: POST at 'arrived' (registration time)
            $isRanap = ($p['status_lanjut'] ?? '') === 'Ranap';
            $targetStatus = $isRanap ? 'in-progress' : 'arrived';

            $payload = SatuSehatPayloadBuilder::encounter(
                $this->config->orgId,
                $p,
                $idPasien,
                $idDokter,
                $targetStatus
            );

            $this->log->info("[PHASE 1] {$noRawat}: POST /Encounter ({$targetStatus})");
            $result = $this->api->post('/Encounter', $payload);

            if ($result['success'] && isset($result['data']['id'])) {
                $idEncounter = $result['data']['id'];
                $this->db->saveEncounter($noRawat, $idEncounter);
                $this->db->updateLocalState($noRawat, $targetStatus);
                $this->log->info("[PHASE 1] {$noRawat}: ✓ Created Encounter {$idEncounter} ({$targetStatus})");
                $this->successCount++;
            } else {
                $errorMessage = $result['data']['issue'][0]['diagnostics'] ?? $result['message'];

                // Duplicate Handling Fallback
                if (stripos($errorMessage, 'duplicate') !== false || $result['code'] === 409) {
                    $this->log->warning("[PHASE 1] {$noRawat}: Duplicated Encounter detected. Searching existing records...");
                    $idEncounter = $this->resolveDuplicateEncounter($noRawat);

                    if ($idEncounter) {
                        $this->db->saveEncounter($noRawat, $idEncounter);
                        $this->db->updateLocalState($noRawat, $targetStatus);
                        $this->log->info("[PHASE 1] {$noRawat}: ✓ Recovered Encounter {$idEncounter} from Satu Sehat API");
                        $this->successCount++;
                    } else {
                        $this->log->error("[PHASE 1] {$noRawat}: ✗ Failed to recover duplicate Encounter.");
                        $this->failCount++;
                    }
                } else {
                    $this->log->warning("[PHASE 1] {$noRawat}: ✗ Failed -> " . $errorMessage);
                    $this->failCount++;
                }
            }
        }
    }

    private function processInProgress(string $dateFrom, string $dateTo, ?array $patients = null): void
    {
        if ($patients === null) {
            $patients = $this->db->fetchPendingInProgress($dateFrom, $dateTo);
        }

        if (empty($patients)) {
            $this->log->info("[PHASE 2] No pending 'in-progress' encounters.");
            return;
        }

        $this->log->info("[PHASE 2] Found " . count($patients) . " patient(s) to set in-progress.");

        foreach ($patients as $p) {
            $noRawat = $p['no_rawat'];
            $localState = $this->db->getLocalState($noRawat);

            if ($localState === 'in-progress' || $localState === 'finished') {
                $this->skipCount++;
                continue;
            }

            $nik = $p['no_ktp'];
            $nikDokter = $p['ktpdokter'];

            $idPasien = $this->db->getIhsPatient($nik);
            $idDokter = $this->db->getIhsPractitioner($nikDokter);

            if (!$idPasien || !$idDokter) {
                $this->log->warning("[PHASE 2] {$noRawat}: Missing IHS ID. Skipped.");
                $this->skipCount++;
                continue;
            }

            $idEncounter = $p['id_encounter'];

            // Build PATCH operations for in-progress transition
            $startWaktu = SatuSehatPayloadBuilder::sanitizeDateTime(
                $p['tgl_registrasi'] ?? null, $p['jam_reg'] ?? null, $p
            );
            $inProgressWaktu = !empty($p['waktu_perawatan'])
                ? SatuSehatPayloadBuilder::sanitizeDateTime($p['waktu_perawatan'], null, $p)
                : $startWaktu;

            $ops = [
                [
                    'op' => 'replace',
                    'path' => '/status',
                    'value' => 'in-progress'
                ],
                [
                    'op' => 'replace',
                    'path' => '/period/end',
                    'value' => $inProgressWaktu
                ],
                [
                    'op' => 'replace',
                    'path' => '/statusHistory',
                    'value' => [
                        [
                            'status' => 'arrived',
                            'period' => [
                                'start' => $startWaktu,
                                'end'   => $inProgressWaktu
                            ]
                        ],
                        [
                            'status' => 'in-progress',
                            'period' => [
                                'start' => $inProgressWaktu
                            ]
                        ]
                    ]
                ]
            ];

            $this->log->info("[PHASE 2] {$noRawat}: PATCH /Encounter/{$idEncounter} (in-progress)");
            $result = $this->api->patch("/Encounter/{$idEncounter}", $ops);

            if ($result['success']) {
                $this->db->updateLocalState($noRawat, 'in-progress');
                $this->log->info("[PHASE 2] {$noRawat}: ✓ Updated to in-progress via PATCH");
                $this->successCount++;
            } else {
                $this->log->warning("[PHASE 2] {$noRawat}: ✗ Failed -> " . ($result['data']['issue'][0]['diagnostics'] ?? $result['message']));
                $this->failCount++;
            }
        }
    }

    private function processFinished(string $dateFrom, string $dateTo, ?array $patients = null): void
    {
        if ($patients === null) {
            $patients = $this->db->fetchPendingFinished($dateFrom, $dateTo);
        }

        if (empty($patients)) {
            $this->log->info("[PHASE 3] No pending 'finished' encounters.");
            return;
        }

        $this->log->info("[PHASE 3] Found " . count($patients) . " patient(s) to set finished.");

        foreach ($patients as $p) {
            $noRawat = $p['no_rawat'];
            $localState = $this->db->getLocalState($noRawat);

            if ($localState === 'finished') {
                $this->skipCount++;
                continue;
            }

            $diagnoses = $this->db->fetchDiagnoses($noRawat);
            if (empty($diagnoses)) {
                $this->log->debug("[PHASE 3] {$noRawat}: No diagnoses found yet. Must have diagnosis to finish. Skipped.");
                $this->skipCount++;
                continue;
            }

            $nik = $p['no_ktp'];
            $nikDokter = $p['ktpdokter'];

            $idPasien = $this->db->getIhsPatient($nik);
            $idDokter = $this->db->getIhsPractitioner($nikDokter);

            if (!$idPasien || !$idDokter) {
                $this->log->warning("[PHASE 3] {$noRawat}: Missing IHS ID. Skipped.");
                $this->skipCount++;
                continue;
            }

            $idEncounter = $p['id_encounter'];

            // Build PATCH operations dynamically for finished transition
            $startWaktu = SatuSehatPayloadBuilder::sanitizeDateTime(
                $p['tgl_registrasi'] ?? null, $p['jam_reg'] ?? null, $p
            );
            $finishedWaktu = !empty($p['waktu_pulang'])
                ? SatuSehatPayloadBuilder::sanitizeDateTime($p['waktu_pulang'], null, $p)
                : null;

            // Compute statusHistory based on encounter type
            $isRanap = ($p['status_lanjut'] ?? '') === 'Ranap';
            if ($isRanap) {
                // Ranap: in-progress -> finished
                $statusHistory = [
                    [
                        'status' => 'in-progress',
                        'period' => [
                            'start' => $startWaktu,
                            'end'   => $finishedWaktu
                        ]
                    ]
                ];
                if ($finishedWaktu) {
                    $statusHistory[] = [
                        'status' => 'finished',
                        'period' => [
                            'start' => $finishedWaktu,
                            'end'   => $finishedWaktu
                        ]
                    ];
                }
            } else {
                // Ralan/IGD: arrived -> in-progress -> finished
                $inProgressWaktu = SatuSehatPayloadBuilder::sanitizeDateTime(
                    $p['waktu_perawatan'] ?? null, null, $p
                );
                if (!$inProgressWaktu) {
                    $inProgressWaktu = $startWaktu;
                }
                $statusHistory = [
                    [
                        'status' => 'arrived',
                        'period' => [
                            'start' => $startWaktu,
                            'end'   => $inProgressWaktu
                        ]
                    ],
                    [
                        'status' => 'in-progress',
                        'period' => [
                            'start' => $inProgressWaktu,
                            'end'   => $finishedWaktu
                        ]
                    ]
                ];
                if ($finishedWaktu) {
                    $statusHistory[] = [
                        'status' => 'finished',
                        'period' => [
                            'start' => $finishedWaktu,
                            'end'   => $finishedWaktu
                        ]
                    ];
                }
            }

            $ops = [
                [
                    'op' => 'replace',
                    'path' => '/status',
                    'value' => 'finished'
                ],
            ];

            // Only set period/end if discharge time is available (SATUSEHAT requires valid datetime)
            if ($finishedWaktu !== null) {
                $ops[] = [
                    'op' => 'replace',
                    'path' => '/period/end',
                    'value' => $finishedWaktu
                ];
            }

            // statusHistory: only set entries with non-null end times
            // Skip entries where end would be null (Rule Number 10122)
            $validHistory = [];
            foreach ($statusHistory as $entry) {
                if ($entry['period']['start'] !== null && $entry['period']['end'] !== null) {
                    $validHistory[] = $entry;
                }
            }
            if (!empty($validHistory)) {
                $ops[] = [
                    'op' => 'replace',
                    'path' => '/statusHistory',
                    'value' => $validHistory
                ];
            }

            // Add diagnosis if available
            if (!empty($diagnoses)) {
                $diagnosisPayload = [];
                $rank = 1;
                foreach ($diagnoses as $diag) {
                    $diagnosisPayload[] = [
                        'condition' => [
                            'reference' => 'Condition/' . $diag['id_condition'],
                            'display'   => $diag['nm_penyakit']
                        ],
                        'use' => [
                            'coding' => [
                                [
                                    'system'  => 'http://terminology.hl7.org/CodeSystem/diagnosis-role',
                                    'code'    => 'DD',
                                    'display' => 'Discharge diagnosis'
                                ]
                            ]
                        ],
                        'rank' => $rank
                    ];
                    $rank++;
                }
                $ops[] = [
                    'op' => 'replace',
                    'path' => '/diagnosis',
                    'value' => $diagnosisPayload
                ];
            }

            // Add hospitalization with discharge disposition
            $dischargeDisposition = $this->buildDischargeDisposition($p);
            if ($dischargeDisposition !== null) {
                $ops[] = [
                    'op' => 'replace',
                    'path' => '/hospitalization',
                    'value' => [
                        'dischargeDisposition' => [
                            'coding' => [
                                $dischargeDisposition
                            ]
                        ]
                    ]
                ];
            }

            // Add length (duration)
            if ($finishedWaktu) {
                $durationSeconds = strtotime($finishedWaktu) - strtotime($startWaktu);
                if ($durationSeconds > 0) {
                    $unit = $isRanap ? 'd' : 'min';
                    $durationValue = $isRanap ? round($durationSeconds / 86400, 1) : round($durationSeconds / 60);
                    if ($durationValue < 1) {
                        $durationValue = 1;
                        $unit = 'min';
                    }
                    $ops[] = [
                        'op' => 'replace',
                        'path' => '/length',
                        'value' => [
                            'value'  => $durationValue,
                            'unit'   => $unit,
                            'system' => 'http://unitsofmeasure.org',
                            'code'   => $unit
                        ]
                    ];
                }
            }

            $this->log->info("[PHASE 3] {$noRawat}: PATCH /Encounter/{$idEncounter} (finished, " . count($ops) . " ops)");
            $result = $this->api->patch("/Encounter/{$idEncounter}", $ops);

            if ($result['success']) {
                $this->db->updateLocalState($noRawat, 'finished');
                $this->log->info("[PHASE 3] {$noRawat}: ✓ Updated to finished via PATCH");
                $this->successCount++;
            } else {
                $this->log->warning("[PHASE 3] {$noRawat}: ✗ Failed -> " . ($result['data']['issue'][0]['diagnostics'] ?? $result['message']));
                $this->failCount++;
            }
        }
    }

    /**
     * Resolves a duplicate Encounter by searching the Satu Sehat API by its identifier.
     */
    private function resolveDuplicateEncounter(string $noRawat): ?string
    {
        $endpoint = "/Encounter?identifier=http://sys-ids.kemkes.go.id/encounter/{$this->config->orgId}|" . urlencode($noRawat);
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

    /**
     * Build discharge disposition coding array from patient data.
     * Mirrors the logic from PayloadBuilder::encounter().
     */
    private function buildDischargeDisposition(array $p): ?array
    {
        $isRalan = ($p['status_lanjut'] ?? '') === 'Ralan';

        if ($isRalan) {
            $stts = $p['stts'] ?? '';
            if ($stts === 'Dirujuk') {
                return [
                    'system' => 'http://terminology.hl7.org/CodeSystem/discharge-disposition',
                    'code' => 'other-hcf',
                    'display' => 'Other healthcare facility'
                ];
            } elseif ($stts === 'Meninggal') {
                return [
                    'system' => 'http://terminology.hl7.org/CodeSystem/discharge-disposition',
                    'code' => 'oth',
                    'display' => 'Other'
                ];
            } elseif ($stts === 'Pulang Paksa') {
                return [
                    'system' => 'http://terminology.hl7.org/CodeSystem/discharge-disposition',
                    'code' => 'aadvice',
                    'display' => 'Left against advice'
                ];
            } else {
                return [
                    'system' => 'http://terminology.hl7.org/CodeSystem/discharge-disposition',
                    'code' => 'home',
                    'display' => 'Home'
                ];
            }
        } else {
            // Inpatient (Ranap)
            $sttsPulang = $p['stts_pulang'] ?? '';
            $lama = intval($p['lama'] ?? 0);
            if (in_array($sttsPulang, ['Sehat', 'Sembuh', 'Membaik', 'Atas Persetujuan Dokter'])) {
                return [
                    'system' => 'http://terminology.hl7.org/CodeSystem/discharge-disposition',
                    'code' => 'home',
                    'display' => 'Home'
                ];
            } elseif (in_array($sttsPulang, ['Atas Permintaan Sendiri', 'APS', 'Isoman'])) {
                return [
                    'system' => 'http://terminology.hl7.org/CodeSystem/discharge-disposition',
                    'code' => 'aadvice',
                    'display' => 'Left against advice'
                ];
            } elseif ($sttsPulang === 'Pulang Paksa') {
                return [
                    'system' => 'http://terminology.hl7.org/CodeSystem/discharge-disposition',
                    'code' => 'aadvice',
                    'display' => 'Left against advice'
                ];
            } elseif ($sttsPulang === 'Rujuk') {
                return [
                    'system' => 'http://terminology.hl7.org/CodeSystem/discharge-disposition',
                    'code' => 'other-hcf',
                    'display' => 'Other healthcare facility'
                ];
            } elseif (in_array($sttsPulang, ['+', 'Meninggal'])) {
                if ($lama <= 2) {
                    return [
                        'system' => 'http://terminology.kemkes.go.id/CodeSystem/discharge-disposition',
                        'code' => 'exp-lt48h',
                        'display' => 'Meninggal < 48 jam'
                    ];
                } else {
                    return [
                        'system' => 'http://terminology.kemkes.go.id/CodeSystem/discharge-disposition',
                        'code' => 'exp-gt48h',
                        'display' => 'Meninggal > 48 jam'
                    ];
                }
            } else {
                return [
                    'system' => 'http://terminology.hl7.org/CodeSystem/discharge-disposition',
                    'code' => 'home',
                    'display' => 'Home'
                ];
            }
        }
    }
}
