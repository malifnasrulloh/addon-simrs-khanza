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

    public function run(): array
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
        $this->processActive($dateFrom, $dateTo);

        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[SYNC] Phase 2: PUT 'finished' Episode Of Care");
        $this->processFinished($dateFrom, $dateTo);

        return [
            'success' => $this->successCount,
            'fail'    => $this->failCount,
            'skip'    => $this->skipCount,
        ];
    }

    private function processActive(string $dateFrom, string $dateTo): void
    {
        $patients = $this->db->fetchPendingEocActive($dateFrom, $dateTo);
        
        if (empty($patients)) {
            $this->log->info("[PHASE 1] No pending 'active' episodes.");
            return;
        }

        $this->log->info("[PHASE 1] Found " . count($patients) . " potential diagnosis record(s) to check.");

        foreach ($patients as $p) {
            $noRawat = $p['no_rawat'];
            $kdPenyakit = $p['kd_penyakit'];

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
                $this->db->saveEpisodeOfCare($noRawat, $kdPenyakit, 'active', $idEpisode);
                $this->db->updateEocLocalState($noRawat, 'active');
                $this->log->info("[PHASE 1] {$noRawat}: ✓ Created EpisodeOfCare {$idEpisode}");
                $this->successCount++;
            } else {
                $errorMessage = $result['data']['issue'][0]['diagnostics'] ?? $result['message'];
                
                // Duplicate Handling Fallback
                if (stripos($errorMessage, 'found duplicated') !== false || $result['code'] === 409 || $result['code'] === 400) {
                    $this->log->warning("[PHASE 1] {$noRawat}: Duplicated EpisodeOfCare detected. Searching existing records...");
                    $idEpisode = $this->resolveDuplicateEpisode($idPasien, $type->code, $noRawat);

                    if ($idEpisode) {
                        $this->db->saveEpisodeOfCare($noRawat, $kdPenyakit, 'active', $idEpisode);
                        $this->db->updateEocLocalState($noRawat, 'active');
                        $this->log->info("[PHASE 1] {$noRawat}: ✓ Recovered EpisodeOfCare {$idEpisode} from BPJS");
                        $this->successCount++;
                    } else {
                        $this->log->error("[PHASE 1] {$noRawat}: ✗ Failed to recover duplicate EpisodeOfCare.");
                        $this->failCount++;
                    }
                } else {
                    $this->log->warning("[PHASE 1] {$noRawat}: ✗ Failed -> " . $errorMessage);
                    $this->failCount++;
                }
            }
        }
    }

    private function processFinished(string $dateFrom, string $dateTo): void
    {
        $patients = $this->db->fetchPendingEocFinished($dateFrom, $dateTo);

        if (empty($patients)) {
            $this->log->info("[PHASE 2] No pending 'finished' episodes.");
            return;
        }

        $this->log->info("[PHASE 2] Found " . count($patients) . " patient(s) to set finished.");

        foreach ($patients as $p) {
            $noRawat = $p['no_rawat'];
            $kdPenyakit = $p['kd_penyakit'];
            $localState = $this->db->getEocLocalState($noRawat);

            if ($localState === 'finished') {
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

            $payload = SatuSehatPayloadBuilder::episodeOfCare(
                $this->config->orgId,
                $p,
                $idPasien,
                $idDokter,
                'finished',
                $type,
                $p['id_episode_of_care']
            );

            $this->log->info("[PHASE 2] {$noRawat}: PUT /EpisodeOfCare/{$p['id_episode_of_care']} (finished)");
            $result = $this->api->put("/EpisodeOfCare/{$p['id_episode_of_care']}", $payload);

            if ($result['success']) {
                $this->db->saveEpisodeOfCare($noRawat, $kdPenyakit, 'finished', $p['id_episode_of_care']);
                $this->db->updateEocLocalState($noRawat, 'finished');
                $this->log->info("[PHASE 2] {$noRawat}: ✓ Updated to finished");
                $this->successCount++;
            } else {
                $this->log->warning("[PHASE 2] {$noRawat}: ✗ Failed -> " . ($result['data']['issue'][0]['diagnostics'] ?? $result['message']));
                $this->failCount++;
            }
        }
    }

    /**
     * Resolves a duplicate EpisodeOfCare by searching the Satu Sehat API.
     * Uses the multi-tiered filtering and sorting algorithm.
     */
    private function resolveDuplicateEpisode(string $idPasien, string $targetTypeCode, string $noRawat): ?string
    {
        // Search API for active episodes for this patient
        $endpoint = "/EpisodeOfCare?patient={$idPasien}&organization={$this->config->orgId}&status=active";
        $result = $this->api->get($endpoint);

        if (!$result['success'] || empty($result['data']['entry'])) {
            return null;
        }

        $activeCandidates = [];

        // Tier 1: Filter & Exact Match
        foreach ($result['data']['entry'] as $entry) {
            $res = $entry['resource'] ?? [];
            
            // Check status and type code
            $resStatus = $res['status'] ?? '';
            $resTypeCode = $res['type'][0]['coding'][0]['code'] ?? '';
            
            if ($resStatus === 'active' && $resTypeCode === $targetTypeCode) {
                // Tier 1 Check: Does the identifier match exactly?
                if (isset($res['identifier']) && is_array($res['identifier'])) {
                    foreach ($res['identifier'] as $idBlock) {
                        if (isset($idBlock['value']) && $idBlock['value'] === $noRawat) {
                            return $res['id']; // 100% exact match!
                        }
                    }
                }
                
                // Add to candidates for Tier 2 fallback
                $activeCandidates[] = $res;
            }
        }

        // Tier 2: Closest Date Match (Newest active episode)
        if (!empty($activeCandidates)) {
            usort($activeCandidates, function($a, $b) {
                $timeA = isset($a['period']['start']) ? strtotime($a['period']['start']) : 0;
                $timeB = isset($b['period']['start']) ? strtotime($b['period']['start']) : 0;
                return $timeB <=> $timeA; // Descending (newest first)
            });
            
            return $activeCandidates[0]['id'];
        }

        return null;
    }
}
