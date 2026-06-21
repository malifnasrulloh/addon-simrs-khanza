<?php

/**
 * EpisodeOfCareProcessor - Orchestrator for Satu Sehat Episode of Care sync.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

require_once __DIR__ . '/EpisodeOfCareType.php';

class SatuSehatEpisodeOfCareProcessor
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

    public function run(?array $activeRecords = null, ?array $finishedRecords = null): array
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
        $this->log->info("[SYNC] Phase 1: POST 'active' Episode Of Care");
        $this->processActive($dateFrom, $dateTo, $activeRecords);

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 2: PUT 'finished' Episode Of Care");
        $this->processFinished($dateFrom, $dateTo, $finishedRecords);

        return [
            'success' => $this->successCount,
            'fail'    => $this->failCount,
            'skip'    => $this->skipCount,
        ];
    }

    private function processActive(string $dateFrom, string $dateTo, ?array $patients = null): void
    {
        if ($patients === null) {
            $patients = $this->db->fetchPendingEocActive($dateFrom, $dateTo);
        }
        
        if (empty($patients)) {
            $this->log->info("[PHASE 1] No pending 'active' episodes.");
            return;
        }

        $this->log->info("[PHASE 1] Found " . count($patients) . " potential diagnosis record(s) to check.");

        $processedNoRawat = [];

        foreach ($patients as $p) {
            $noRawat = $p['no_rawat'];
            $kdPenyakit = $p['kd_penyakit'];

            // Skip if already processed in this batch or exists in database
            if (isset($processedNoRawat[$noRawat])) {
                continue;
            }
            $existingId = $this->db->getSavedEpisodeOfCareId($noRawat);
            if ($existingId) {
                $processedNoRawat[$noRawat] = $existingId;
                continue;
            }

            // Local State check: skip if previously failed due to privacy rules or business constraints
            $localState = $this->db->getEocLocalState($noRawat);
            if ($localState === 'privacy_error' || $localState === 'failed_rule' || $localState === 'invalid_code') {
                $this->skipCount++;
                continue;
            }

            // 1. Detect Episode Type
            $type = EpisodeOfCareType::fromIcdCode($kdPenyakit);
            if (!$type) {
                // $this->log->debug("[PHASE 1] {$noRawat}: ICD {$kdPenyakit} doesn't map to an Episode of Care. Skip.");
                continue;
            }

            $nik = $p['no_ktp'];
            $nikDokter = $p['ktpdokter'];

            $idPasien = $this->db->getIhsPatient($nik);
            $idDokter = $this->db->getIhsPractitioner($nikDokter);

            if (!$idPasien || !$idDokter) {
                $this->log->warning("[PHASE 1] {$noRawat}: Missing IHS ID for Patient or Doctor. Skipped.");
                $this->skipCount++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::episodeOfCare(
                $this->config->orgId,
                $p,
                $idPasien,
                $idDokter,
                'active',
                $type
            );

            $this->log->info("[PHASE 1] {$noRawat}: POST /EpisodeOfCare (active, type={$type->code})");
            $result = $this->api->post('/EpisodeOfCare', $payload);

            if ($result['success'] && isset($result['data']['id'])) {
                $idEpisode = $result['data']['id'];
                $this->db->saveEpisodeOfCare($noRawat, $kdPenyakit, $p['status_lanjut'], $idEpisode);
                $this->db->updateEocLocalState($noRawat, 'active');
                $this->log->info("[PHASE 1] {$noRawat}: ✓ Created EpisodeOfCare {$idEpisode}");
                $this->successCount++;
                $processedNoRawat[$noRawat] = $idEpisode;
            } else {
                $errorMessage = $result['data']['issue'][0]['details']['text'] 
                    ?? $result['data']['issue'][0]['diagnostics'] 
                    ?? $result['message'];
                
                // Duplicate Handling Fallback
                if (stripos($errorMessage, 'found duplicated') !== false || stripos($errorMessage, 'duplicate') !== false || $result['code'] === 409) {
                    $this->log->warning("[PHASE 1] {$noRawat}: Duplicated EpisodeOfCare detected (Rule 10110/20002). Resolving...");
                    $recoveryResult = $this->resolveDuplicateEpisode($idPasien, $type->code, $noRawat, $p['stts'] ?? '', $payload);

                    if ($recoveryResult) {
                        $this->db->saveEpisodeOfCare($noRawat, $kdPenyakit, $p['status_lanjut'], $recoveryResult);
                        $this->db->updateEocLocalState($noRawat, 'active');
                        $this->log->info("[PHASE 1] {$noRawat}: ✓ Recovered EpisodeOfCare {$recoveryResult}");
                        $this->successCount++;
                        $processedNoRawat[$noRawat] = $recoveryResult;
                    } else {
                        $this->log->error("[PHASE 1] {$noRawat}: ✗ Failed to recover duplicate EpisodeOfCare.");
                        $this->db->updateEocLocalState($noRawat, 'failed_rule');
                        $this->skipCount++;
                    }
                } elseif (stripos($errorMessage, 'consent') !== false || stripos($errorMessage, 'privacy') !== false) {
                    $this->db->updateEocLocalState($noRawat, 'privacy_error');
                    $this->log->warning("[PHASE 1] {$noRawat}: Skip future retries due to privacy/consent settings.");
                    $this->skipCount++;
                } elseif (stripos($errorMessage, 'Rule Number: 10110') !== false || stripos($errorMessage, 'found another EpisodeOfCare') !== false) {
                    $this->db->updateEocLocalState($noRawat, 'failed_rule');
                    $this->log->warning("[PHASE 1] {$noRawat}: Skip future retries due to active EpisodeOfCare rule conflict.");
                    $this->skipCount++;
                } else {
                    $this->log->warning("[PHASE 1] {$noRawat}: ✗ Failed -> " . $errorMessage);
                    $this->failCount++;
                }
            }
        }
    }

    private function processFinished(string $dateFrom, string $dateTo, ?array $patients = null): void
    {
        if ($patients === null) {
            $patients = $this->db->fetchPendingEocFinished($dateFrom, $dateTo);
        }

        if (empty($patients)) {
            $this->log->info("[PHASE 2] No pending 'finished' episodes.");
            return;
        }

        $this->log->info("[PHASE 2] Found " . count($patients) . " patient(s) to set finished.");

        $processedNoRawat = [];

        foreach ($patients as $p) {
            $noRawat = $p['no_rawat'];
            $kdPenyakit = $p['kd_penyakit'];

            if (isset($processedNoRawat[$noRawat])) {
                continue;
            }
            $processedNoRawat[$noRawat] = true;

            $localState = $this->db->getEocLocalState($noRawat);

            if ($localState === 'finished' || $localState === 'privacy_error' || $localState === 'failed_rule' || $localState === 'invalid_code' || $localState === 'merge_failed') {
                $this->skipCount++;
                continue;
            }

            $type = EpisodeOfCareType::fromIcdCode($kdPenyakit);
            if (!$type) {
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

            $eocId = $p['id_episode_of_care'];

            // ── GET the existing EpisodeOfCare from SATUSEHAT ──────────────
            $this->log->info("[PHASE 2] {$noRawat}: GET /EpisodeOfCare/{$eocId}");
            $getResult = $this->api->get("/EpisodeOfCare/{$eocId}");

            if (!$getResult['success'] || empty($getResult['data']['id'])) {
                $this->log->warning("[PHASE 2] {$noRawat}: ✗ Failed to GET EpisodeOfCare/{$eocId}. Skipped.");
                $this->failCount++;
                continue;
            }

            $resource = $getResult['data'];

            // Already finished on server? Just update local state
            if (($resource['status'] ?? '') === 'finished') {
                $this->db->saveEpisodeOfCare($noRawat, $kdPenyakit, $p['status_lanjut'], $eocId);
                $this->db->updateEocLocalState($noRawat, 'finished');
                $this->log->info("[PHASE 2] {$noRawat}: ✓ Already finished on SATUSEHAT → synced locally");
                $this->successCount++;
                continue;
            }

            // ── Compute period.end ─────────────────────────────────────────
            $finishedWaktu = $p['waktu_pulang'] ?? null;
            $periodStart = $resource['period']['start'] ?? null;

            if (!$finishedWaktu && $periodStart) {
                // Fallback: period.start + 1 day (UTC)
                try {
                    $startDt = new \DateTime($periodStart);
                    $endDt = (clone $startDt)->modify('+1 day');
                    $finishedWaktu = $endDt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
                    $this->log->info("[PHASE 2] {$noRawat}: No discharge time → fallback to start+1day ({$finishedWaktu})");
                } catch (\Throwable $e) {
                    $finishedWaktu = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
                    $this->log->warning("[PHASE 2] {$noRawat}: Cannot parse period.start → fallback to NOW ({$finishedWaktu})");
                }
            } elseif (!$finishedWaktu) {
                $finishedWaktu = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
                $this->log->warning("[PHASE 2] {$noRawat}: No discharge time and no period.start → fallback to NOW ({$finishedWaktu})");
            }

            // ── Mutate the resource ────────────────────────────────────────
            $resource['status'] = 'finished';
            $resource['period']['end'] = $finishedWaktu;

            // Add statusHistory entry for the finished transition
            if (!isset($resource['statusHistory'])) {
                $resource['statusHistory'] = [];
            }
            // Close the active period in the last statusHistory entry
            $lastIdx = count($resource['statusHistory']) - 1;
            if ($lastIdx >= 0 && ($resource['statusHistory'][$lastIdx]['status'] ?? '') === 'active') {
                $resource['statusHistory'][$lastIdx]['period']['end'] = $finishedWaktu;
            }
            $resource['statusHistory'][] = [
                'status' => 'finished',
                'period' => [
                    'start' => $finishedWaktu,
                    'end'   => $finishedWaktu
                ]
            ];

            // Remove server-managed fields that can't be sent in PUT
            unset($resource['meta']);

            // ── PUT the modified resource back ─────────────────────────────
            $this->log->info("[PHASE 2] {$noRawat}: PUT /EpisodeOfCare/{$eocId} (finished)");
            $result = $this->api->put("/EpisodeOfCare/{$eocId}", $resource);

            if ($result['success']) {
                $this->db->saveEpisodeOfCare($noRawat, $kdPenyakit, $p['status_lanjut'], $eocId);
                $this->db->updateEocLocalState($noRawat, 'finished');
                $this->log->info("[PHASE 2] {$noRawat}: ✓ Updated to finished");
                $this->successCount++;
            } else {
                $errorMessage = $result['data']['issue'][0]['details']['text'] 
                    ?? $result['data']['issue'][0]['diagnostics'] 
                    ?? $result['message'];

                if (stripos($errorMessage, 'consent') !== false || stripos($errorMessage, 'privacy') !== false) {
                    $this->db->updateEocLocalState($noRawat, 'privacy_error');
                    $this->log->warning("[PHASE 2] {$noRawat}: Skip future retries due to privacy/consent settings.");
                    $this->skipCount++;
                } elseif (stripos($errorMessage, 'Rule Number: 10110') !== false || stripos($errorMessage, 'found another EpisodeOfCare') !== false) {
                    $this->db->updateEocLocalState($noRawat, 'failed_rule');
                    $this->log->warning("[PHASE 2] {$noRawat}: Skip future retries due to active EpisodeOfCare rule conflict.");
                    $this->skipCount++;
                } elseif (stripos($errorMessage, 'merge_failed') !== false || stripos($errorMessage, 'FHIRPath constraint') !== false) {
                    $this->db->updateEocLocalState($noRawat, 'merge_failed');
                    $this->log->warning("[PHASE 2] {$noRawat}: ✗ merge_failed → marked to skip future retries.");
                    $this->skipCount++;
                } else {
                    $this->log->warning("[PHASE 2] {$noRawat}: ✗ Failed -> " . $errorMessage);
                    $this->failCount++;
                }
            }
        }
    }

    /**
     * Resolves a duplicate EpisodeOfCare using a 3-tier strategy:
     *
     * Tier 0: Search our organization by identifier (no_rawat).
     *   - Found                      → Reuse existing ID, PATCH to target status if needed.
     *
     * Tier 1: Search OUR organization for active EoC.
     *   - Found + stts="Batal"       → PATCH cancelled, re-POST
     *   - Found + stts="Sudah"       → PATCH finished, re-POST
     *   - Found + period ≤ 1 day     → Reuse existing EoC ID
     *   - Found + period > 1 day     → PATCH finished, re-POST (stale)
     *
     * Tier 2: Search WITHOUT org filter (cross-organization).
     *   - Found in another org       → PATCH finished, re-POST
     *
     * @return string|null The EpisodeOfCare ID on success, null on failure
     */
    private function resolveDuplicateEpisode(string $idPasien, string $targetTypeCode, string $noRawat, string $stts, array $payload): ?string
    {
        // ── Tier 0: Search by identifier (no_rawat) ──────────────────────────
        $this->log->info("[RECOVERY] {$noRawat}: Tier 0 - Searching by identifier...");
        $identifierSystem = "http://sys-ids.kemkes.go.id/episode-of-care/" . $this->config->orgId;
        $endpoint = "/EpisodeOfCare?identifier=" . urlencode($identifierSystem . "|" . $noRawat);
        $result = $this->api->get($endpoint);

        if ($result['success'] && !empty($result['data']['entry'])) {
            $entry = $result['data']['entry'][0]['resource'] ?? [];
            $eocId = $entry['id'] ?? null;
            if ($eocId) {
                $currentStatus = $entry['status'] ?? '';
                $this->log->info("[RECOVERY] {$noRawat}: Found existing EoC {$eocId} with status '{$currentStatus}' via identifier search.");

                // Determine target status based on stts
                $targetStatus = 'active';
                if ($stts === 'Batal') {
                    $targetStatus = 'cancelled';
                } elseif ($stts === 'Sudah') {
                    $targetStatus = 'finished';
                }

                if ($currentStatus !== $targetStatus) {
                    $this->log->info("[RECOVERY] {$noRawat}: Status mismatch (current: {$currentStatus}, target: {$targetStatus}) → PATCHing...");
                    $operations = [
                        ['op' => 'replace', 'path' => '/status', 'value' => $targetStatus],
                    ];
                    if ($targetStatus === 'finished') {
                        $periodStart = $entry['period']['start'] ?? null;
                        if ($periodStart) {
                            try {
                                $startDt = new \DateTime($periodStart);
                                $now = new \DateTime('now', new \DateTimeZone('UTC'));
                                $diff = $now->diff($startDt);
                                if ($diff->days > 1 || $diff->invert === 1) {
                                    $endDt = (clone $startDt)->modify('+1 day');
                                } else {
                                    $endDt = $now;
                                }
                                $periodEnd = $endDt->format('Y-m-d\TH:i:s+00:00');
                                $operations[] = ['op' => 'replace', 'path' => '/period/end', 'value' => $periodEnd];
                            } catch (\Throwable $e) {
                                $this->log->warning("[RECOVERY] {$noRawat}: Could not calculate period.end: " . $e->getMessage());
                            }
                        }
                    }
                    $patchResult = $this->api->patch("/EpisodeOfCare/{$eocId}", $operations);
                    if ($patchResult['success']) {
                        $this->log->info("[RECOVERY] {$noRawat}: ✓ PATCH {$eocId} → {$targetStatus}");
                    } else {
                        $patchError = $patchResult['data']['issue'][0]['details']['text']
                            ?? $patchResult['data']['issue'][0]['diagnostics']
                            ?? $patchResult['message'];
                        $this->log->error("[RECOVERY] {$noRawat}: PATCH {$eocId} failed → {$patchError}");
                    }
                }
                return $eocId;
            }
        }

        // ── Tier 1: Search OUR organization ──────────────────────────────────
        $this->log->info("[RECOVERY] {$noRawat}: Tier 1 - Searching our organization...");
        $endpoint = "/EpisodeOfCare?patient={$idPasien}&organization={$this->config->orgId}&status=active";
        $result = $this->api->get($endpoint);

        if ($result['success'] && !empty($result['data']['entry'])) {
            foreach ($result['data']['entry'] as $entry) {
                $res = $entry['resource'] ?? [];
                $resTypeCode = $res['type'][0]['coding'][0]['code'] ?? '';

                if (($res['status'] ?? '') === 'active' && $resTypeCode === $targetTypeCode) {
                    $eocId = $res['id'];
                    $periodStart = $res['period']['start'] ?? null;

                    if ($stts === 'Batal') {
                        // Patient cancelled → PATCH to cancelled, then re-POST
                        $this->log->info("[RECOVERY] {$noRawat}: Found our EoC {$eocId}, stts=Batal → PATCH cancelled");
                        return $this->patchAndRepost($eocId, 'cancelled', $periodStart, $payload, $noRawat);
                    } elseif ($stts === 'Sudah') {
                        // Patient finished → PATCH to finished, then re-POST
                        $this->log->info("[RECOVERY] {$noRawat}: Found our EoC {$eocId}, stts=Sudah → PATCH finished");
                        return $this->patchAndRepost($eocId, 'finished', $periodStart, $payload, $noRawat);
                    } else {
                        // Visit still ongoing - check if stale
                        $isStale = $this->isPeriodStale($periodStart);
                        if ($isStale) {
                            $this->log->info("[RECOVERY] {$noRawat}: Found our stale EoC {$eocId} (period > 1 day) → PATCH finished");
                            return $this->patchAndRepost($eocId, 'finished', $periodStart, $payload, $noRawat);
                        } else {
                            // Legitimately active (same day or recent) → reuse
                            $this->log->info("[RECOVERY] {$noRawat}: Found our active EoC {$eocId} (recent, stts={$stts}) → Reusing");
                            return $eocId;
                        }
                    }
                }
            }
        }

        // ── Tier 2: Cross-organization search ────────────────────────────────
        $this->log->info("[RECOVERY] {$noRawat}: Tier 2 - Searching cross-organization...");
        $endpoint = "/EpisodeOfCare?patient={$idPasien}&status=active";
        $result = $this->api->get($endpoint);

        if ($result['success'] && !empty($result['data']['entry'])) {
            foreach ($result['data']['entry'] as $entry) {
                $res = $entry['resource'] ?? [];
                $resTypeCode = $res['type'][0]['coding'][0]['code'] ?? '';

                if (($res['status'] ?? '') === 'active' && $resTypeCode === $targetTypeCode) {
                    $eocId = $res['id'];
                    $remoteOrg = $res['managingOrganization']['reference'] ?? 'unknown';
                    $periodStart = $res['period']['start'] ?? null;

                    $this->log->info("[RECOVERY] {$noRawat}: Found cross-org EoC {$eocId} (org: {$remoteOrg}) → PATCH finished");
                    return $this->patchAndRepost($eocId, 'finished', $periodStart, $payload, $noRawat);
                }
            }
        }

        $this->log->error("[RECOVERY] {$noRawat}: No active EpisodeOfCare found in any organization.");
        return null;
    }

    /**
     * PATCH an existing EpisodeOfCare to a new status, then re-POST the original payload.
     *
     * @return string|null The new EpisodeOfCare ID on success, null on failure
     */
    private function patchAndRepost(string $eocId, string $newStatus, ?string $periodStart, array $payload, string $noRawat): ?string
    {
        // Build PATCH operations
        $operations = [
            ['op' => 'replace', 'path' => '/status', 'value' => $newStatus],
        ];

        // Add period.end for "finished" status with a reasonable value
        if ($newStatus === 'finished' && $periodStart) {
            // Set period.end to either:
            // - The original period.start + 1 day (if stale/cross-org)
            // - Or "now" if the start is recent enough
            try {
                $startDt = new \DateTime($periodStart);
                $now = new \DateTime('now', new \DateTimeZone('UTC'));
                
                // If start is more than 1 day ago, set end to start + 1 day
                // Otherwise set end to now
                $diff = $now->diff($startDt);
                if ($diff->days > 1 || $diff->invert === 1) {
                    $endDt = (clone $startDt)->modify('+1 day');
                } else {
                    $endDt = $now;
                }
                $periodEnd = $endDt->format('Y-m-d\TH:i:s+00:00');
                $operations[] = ['op' => 'replace', 'path' => '/period/end', 'value' => $periodEnd];
            } catch (\Throwable $e) {
                $this->log->warning("[RECOVERY] {$noRawat}: Could not calculate period.end: " . $e->getMessage());
            }
        }

        // Execute PATCH
        $patchResult = $this->api->patch("/EpisodeOfCare/{$eocId}", $operations);

        if (!$patchResult['success']) {
            $patchError = $patchResult['data']['issue'][0]['details']['text']
                ?? $patchResult['data']['issue'][0]['diagnostics']
                ?? $patchResult['message'];
            $this->log->error("[RECOVERY] {$noRawat}: PATCH {$eocId} to '{$newStatus}' failed → {$patchError}");
            return null;
        }

        $this->log->info("[RECOVERY] {$noRawat}: ✓ PATCH {$eocId} → {$newStatus}");

        // Re-POST the original payload
        $this->log->info("[RECOVERY] {$noRawat}: Re-POST /EpisodeOfCare after clearing conflict...");
        $postResult = $this->api->post('/EpisodeOfCare', $payload);

        if ($postResult['success'] && isset($postResult['data']['id'])) {
            $newId = $postResult['data']['id'];
            $this->log->info("[RECOVERY] {$noRawat}: ✓ Re-POST successful → new EpisodeOfCare {$newId}");
            return $newId;
        }

        $postError = $postResult['data']['issue'][0]['details']['text']
            ?? $postResult['data']['issue'][0]['diagnostics']
            ?? $postResult['message'];
        $this->log->error("[RECOVERY] {$noRawat}: Re-POST failed → {$postError}");
        return null;
    }

    /**
     * Check if a period.start is older than 1 day (stale).
     */
    private function isPeriodStale(?string $periodStart): bool
    {
        if (!$periodStart) {
            return true; // No period.start → treat as stale
        }

        try {
            $startDt = new \DateTime($periodStart);
            $now = new \DateTime('now', new \DateTimeZone('UTC'));
            $diffHours = ($now->getTimestamp() - $startDt->getTimestamp()) / 3600;
            return $diffHours > 24;
        } catch (\Throwable $e) {
            return true; // Can't parse → treat as stale
        }
    }
}

