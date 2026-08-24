<?php

/**
 * ObservationTTVProcessor - Orchestrator for Satu Sehat Observation TTV sync.
 *
 * Single-pass model: the sync script streams one keyset-paginated query that
 * returns every vital-sign column per pemeriksaan row (Ralan + Ranap). Each
 * row may carry up to 10 pending observations; runRow() fans the row out to
 * the matching vital-sign types, sharing the IHS lookups across all of them.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

require_once __DIR__ . '/ObservationTTVDictionary.php';

class SatuSehatObservationTTVProcessor
{
    private SatuSehatDatabase $db;
    private SatuSehatClient $api;
    private SatuSehatConfig $config;
    private Logger $log;

    public function __construct(SatuSehatDatabase $db, SatuSehatClient $api, SatuSehatConfig $config, Logger $log)
    {
        $this->db     = $db;
        $this->api    = $api;
        $this->config = $config;
        $this->log    = $log;
    }

    /**
     * Process ONE pemeriksaan row: for every vital-sign type that carries a
     * pending (unsynced, non-empty) value, POST the Observation.
     *
     * Returns per-run deltas: ['success' => int, 'fail' => int, 'skip' => int].
     */
    public function runRow(array $row): array
    {
        $delta = ['success' => 0, 'fail' => 0, 'skip' => 0];

        $noRawat = $row['no_rawat'];
        $tglObs  = $row['tgl_observasi'];
        $jamObs  = $row['jam_observasi'];

        // IHS lookups are shared by all 10 types of this row (memoized in DB).
        $idPasien = $this->db->getIhsPatient($row['no_ktp'] ?? '');
        $idDokter = $this->db->getIhsPractitioner($row['ktpdokter'] ?? '');
        // If nurse/paramedis has no IHS ID, fall back to the attending doctor (DPJP)
        if (!$idDokter && !empty($row['ktpdokter_dpjp'])) {
            $idDokter = $this->db->getIhsPractitioner($row['ktpdokter_dpjp']);
        }
        $missingIhs = (!$idPasien || !$idDokter);

        foreach (ObservationTTVDictionary::getDefinitions() as $ttvType => $def) {
            $dbCol = $def['db_column'];
            $value = $row[$dbCol] ?? '';
            if ($value === null || trim((string) $value) === '' || $value === '-') {
                continue; // this row carries no value for this type
            }

            if (!empty($row[$ttvType . '_synced'])) {
                continue; // already sent in a previous run
            }

            $localState = $this->db->getObservationLocalState($ttvType, $noRawat, $tglObs, $jamObs, (string) ($row['status'] ?? ''));
            if ($localState === 'sent' || in_array($localState, ['privacy_error', 'failed_rule', 'invalid_code'], true)) {
                $delta['skip']++;
                continue;
            }

            if ($missingIhs) {
                $this->log->warning("  [SKIP] {$noRawat} / {$ttvType}: Missing IHS ID for Patient or Doctor.");
                $delta['skip']++;
                continue;
            }

            $payload = SatuSehatPayloadBuilder::observationTTV(
                $row + ['value' => $value],
                $idPasien,
                $idDokter,
                $def
            );

            if (empty($payload)) {
                $this->log->warning("  [SKIP] {$noRawat} / {$ttvType}: Invalid numeric reading '{$value}'");
                $delta['skip']++;
                continue;
            }

            $this->log->info("  [POST] {$noRawat} / {$ttvType} = {$value}");
            $result = $this->api->post('/Observation', $payload);

            if ($result['success'] && isset($result['data']['id'])) {
                $idObservation = $result['data']['id'];

                $this->db->saveObservationTTV(
                    $def['state_table'],
                    $def['state_id_col'] ?? 'id_observation',
                    $noRawat,
                    $tglObs,
                    $jamObs,
                    $row['status'],
                    $idObservation
                );
                $this->db->updateObservationLocalState($ttvType, $noRawat, $tglObs, $jamObs, 'sent', (string) ($row['status'] ?? ''));
                $this->log->info("    ✓ Created {$idObservation}");
                $delta['success']++;
            } else {
                $errorMessage = \SatuSehatClient::extractErrorMsg($result);

                // Duplicate handling for Observation
                if (stripos($errorMessage, 'duplicate') !== false || $result['code'] === 409) {
                    $this->log->warning("    ! Duplicated. Attempting to recover...");
                    $idObservation = $this->resolveDuplicateObservation($idPasien, $row['id_encounter'] ?? '', $def['code']);

                    if ($idObservation) {
                        $this->db->saveObservationTTV(
                            $def['state_table'],
                            $def['state_id_col'] ?? 'id_observation',
                            $noRawat,
                            $tglObs,
                            $jamObs,
                            $row['status'],
                            $idObservation
                        );
                        $this->db->updateObservationLocalState($ttvType, $noRawat, $tglObs, $jamObs, 'sent', (string) ($row['status'] ?? ''));
                        $this->log->info("    ✓ Recovered {$idObservation} from Server");
                        $delta['success']++;
                    } else {
                        $this->log->error("    ✗ Failed to recover duplicate.");
                        $delta['fail']++;
                    }
                } else {
                    $this->log->warning("    ✗ Failed -> " . $errorMessage);

                    // Categorize and cache permanent/terminal failures
                    $state = 'fail';
                    if (stripos($errorMessage, 'consent') !== false || stripos($errorMessage, 'privacy') !== false) {
                        $state = 'privacy_error';
                    } elseif (stripos($errorMessage, 'rule') !== false || stripos($errorMessage, 'RuleNumber') !== false) {
                        $state = 'failed_rule';
                    } elseif (stripos($errorMessage, 'code') !== false || stripos($errorMessage, 'system') !== false || stripos($errorMessage, 'terminology') !== false) {
                        $state = 'invalid_code';
                    }

                    $this->db->updateObservationLocalState($ttvType, $noRawat, $tglObs, $jamObs, $state, (string) ($row['status'] ?? ''));
                    $delta['fail']++;
                }
            }
        }

        return $delta;
    }

    /**
     * Resolves a duplicate Observation by searching the Satu Sehat API.
     */
    private function resolveDuplicateObservation(string $idPasien, string $idEncounter, string $loincCode): ?string
    {
        $endpoint = "/Observation?patient={$idPasien}&encounter={$idEncounter}&code={$loincCode}";
        $result = $this->api->get($endpoint);

        if (!$result['success'] || empty($result['data']['entry'])) {
            return null;
        }

        foreach ($result['data']['entry'] as $entry) {
            $res = $entry['resource'] ?? [];
            return $res['id'] ?? null; // Returns the first matching observation
        }

        return null;
    }
}