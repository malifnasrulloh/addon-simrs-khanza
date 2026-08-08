<?php

namespace SatusehatPanel\Controller;

use SatusehatPanel\Core\Database;

class PatientController
{
    /**
     * List all patients with billing closure status.
     *
     * Shows every registration (reg_periksa) color-coded by status_bayar.
     * Includes counts of available vs already-sent resources per patient.
     */
    public static function list(): array
    {
        $db = Database::getMysql();

        // Optional date-range filter.
        $since = self::validDate($_GET['since'] ?? '');
        $until = self::validDate($_GET['until'] ?? '');
        $search = trim($_GET['search'] ?? '');
        $page   = max((int) ($_GET['page'] ?? 1), 1);
        $perPage = min(max((int) ($_GET['per_page'] ?? 50), 1), 200);

        // Base WHERE clause
        $where = "WHERE 1=1";
        $params = [];

        if ($since !== '') {
            $where .= " AND DATE(rp.tgl_registrasi) >= ?";
            $params[] = $since;
        }
        if ($until !== '') {
            $where .= " AND DATE(rp.tgl_registrasi) <= ?";
            $params[] = $until;
        }
        if ($search !== '') {
            $where .= " AND (rp.no_rawat LIKE ? OR pj.nm_pasien LIKE ? OR pj.no_rkm_medis LIKE ?)";
            $searchWild = '%' . $search . '%';
            $params[] = $searchWild;
            $params[] = $searchWild;
            $params[] = $searchWild;
        }

        // Count total matching rows for pagination metadata
        $countSql = "
            SELECT COUNT(*) FROM reg_periksa rp
            JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            {$where}";
        try {
            $countStmt = $db->prepare($countSql);
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();
        } catch (\Throwable $e) {
            $total = 0;
        }

        // Calculate offset
        $pages = max((int) ceil($total / $perPage), 1);
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        // Query registrations with billing status + patient info
        $sql = "
            SELECT
                rp.no_rawat,
                rp.tgl_registrasi,
                rp.jam_reg,
                rp.status_lanjut,
                rp.kd_poli,
                rp.status_bayar,
                rp.stts,
                pj.no_rkm_medis,
                pj.nm_pasien,
                pj.no_ktp,
                pj.tgl_lahir,
                pj.jk,
                COALESCE(nj.tanggal, ni.tanggal) AS tgl_keluar,
                COALESCE(nj.jam, ni.jam) AS jam_keluar
            FROM reg_periksa rp
            JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            LEFT JOIN nota_jalan nj ON nj.no_rawat = rp.no_rawat
            LEFT JOIN nota_inap ni ON ni.no_rawat = rp.no_rawat
            {$where}
            ORDER BY rp.tgl_registrasi DESC, rp.jam_reg DESC
            LIMIT {$perPage} OFFSET {$offset}
        ";

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $patients = $stmt->fetchAll() ?: [];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Gagal membaca data pasien: ' . $e->getMessage()];
        }

        // Batch enrich resource counts for all returned patients
        $noRawatList = array_column($patients, 'no_rawat');
        $batchCounts = self::batchCountAvailableResources($noRawatList);

        foreach ($patients as &$p) {
            $p['resource_counts'] = $batchCounts[$p['no_rawat']] ?? [];
        }

        return [
            'success' => true,
            'data' => $patients,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'pages' => $pages,
            ],
        ];
    }

    /**
     * Detail for one patient: all resources that exist in SIMRS for this visit.
     * Each entry marks availability (data exists locally, unsent to SATUSEHAT).
     */
    public static function detail(string $noRawat): array
    {
        $db = Database::getMysql();

        // Patient row
        $stmt = $db->prepare("
            SELECT rp.*, pj.nm_pasien, pj.no_ktp, pj.no_rkm_medis, pj.tgl_lahir, pj.jk
            FROM reg_periksa rp
            JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            WHERE rp.no_rawat = ?
        ");
        $stmt->execute([$noRawat]);
        $patient = $stmt->fetch();
        if (!$patient) {
            return ['success' => false, 'error' => 'Patient not found'];
        }

        $resources = self::buildResourceManifest($noRawat, $patient);

        return [
            'success' => true,
            'data' => [
                'patient' => $patient,
                'resources' => $resources,
            ],
        ];
    }

    /**
     * Count how many of each resource type exist for this visit.
     *
     * Each mapping table is keyed by its OWN PK — never assume no_rawat:
     *  - satu_sehat_medicationrequest / _statement: (no_resep, kode_brng)
     *  - satu_sehat_medication: kode_brng
     *  - satu_sehat_servicerequest/specimen/observation/diagnosticreport_*: noorder
     * Counts filter out rows whose id is empty or '-' (the CLI's not-synced
     * sentinels) so "sent" counts only reflect resources that really synced.
     */
    /**
     * Validate an optional Y-m-d query parameter.
     * Returns '' for empty/invalid input (caller treats as "no constraint").
     */
    private static function validDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $d = \DateTime::createFromFormat('Y-m-d', $value);
        if ($d === false || $d->format('Y-m-d') !== $value) {
            return '';
        }
        return $value;
    }

    private static function batchCountAvailableResources(array $noRawatList): array
    {
        if (empty($noRawatList)) {
            return [];
        }
        $db = Database::getMysql();
        $results = [];
        foreach ($noRawatList as $nr) {
            $results[$nr] = [];
        }

        $placeholders = implode(',', array_fill(0, count($noRawatList), '?'));

        $tables = [
            'Encounter' => "SELECT no_rawat, COUNT(*) as cnt FROM satu_sehat_encounter WHERE no_rawat IN ({$placeholders}) GROUP BY no_rawat",
            'Condition' => "SELECT no_rawat, COUNT(*) as cnt FROM satu_sehat_condition WHERE no_rawat IN ({$placeholders}) GROUP BY no_rawat",
            'Procedure' => "SELECT no_rawat, COUNT(*) as cnt FROM satu_sehat_procedure WHERE no_rawat IN ({$placeholders}) GROUP BY no_rawat",
            'MedicationDispense' => "SELECT no_rawat, COUNT(*) as cnt FROM satu_sehat_medicationdispense WHERE no_rawat IN ({$placeholders}) AND id_medicationdispanse NOT IN ('', '-') GROUP BY no_rawat",
            'CarePlan' => "SELECT no_rawat, COUNT(*) as cnt FROM satu_sehat_careplan WHERE no_rawat IN ({$placeholders}) GROUP BY no_rawat",
            'ClinicalImpression' => "SELECT no_rawat, COUNT(*) as cnt FROM satu_sehat_clinicalimpression WHERE no_rawat IN ({$placeholders}) GROUP BY no_rawat",
            'Composition' => "SELECT no_rawat, COUNT(*) as cnt FROM satu_sehat_composition WHERE no_rawat IN ({$placeholders}) GROUP BY no_rawat",
            'AllergyIntolerance' => "SELECT no_rawat, COUNT(*) as cnt FROM satu_sehat_allergy_intolerance WHERE no_rawat IN ({$placeholders}) GROUP BY no_rawat",
            'Immunization' => "SELECT no_rawat, COUNT(*) as cnt FROM satu_sehat_immunization WHERE no_rawat IN ({$placeholders}) GROUP BY no_rawat",
        ];

        foreach ($tables as $resType => $sql) {
            try {
                $stmt = $db->prepare($sql);
                $stmt->execute($noRawatList);
                while ($row = $stmt->fetch()) {
                    $results[$row['no_rawat']][$resType] = (int) $row['cnt'];
                }
            } catch (\Throwable $e) {
                // Ignore missing tables gracefully
            }
        }

        return $results;
    }

    /**
     * Build the dynamic resource manifest for a patient's visit.
     *
     * Checks the SIMRS tables for actual clinical data. For each resource
     * type that has data, marks it 'available' (can be sent) or 'sent'
     * (already has an id in the satu_sehat_* mapping table).
     */
    private static function buildResourceManifest(string $noRawat, array $patient): array
    {
        $db = Database::getMysql();
        $manifest = [];

        // Helper to check raw SIMRS data existence
        $hasData = function (string $sql, array $params = []) use ($db): bool {
            try {
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                return ((int) $stmt->fetchColumn()) > 0;
            } catch (\Throwable $e) {
                return false;
            }
        };

        // 1. Encounter — always exists for a registration
        $manifest[] = [
            'type' => 'Encounter',
            'available' => true,
            'sent' => $hasData("SELECT COUNT(*) FROM satu_sehat_encounter WHERE no_rawat = ?", [$noRawat]),
        ];

        // 2. Condition — from diagnosa_pasien
        $manifest[] = [
            'type' => 'Condition',
            'available' => $hasData("SELECT COUNT(*) FROM diagnosa_pasien WHERE no_rawat = ?", [$noRawat]),
            'sent' => $hasData("SELECT COUNT(*) FROM satu_sehat_condition WHERE no_rawat = ?", [$noRawat]),
        ];

        // 3. Procedure — from prosedur_pasien + icd9 (CLI source)
        $manifest[] = [
            'type' => 'Procedure',
            'available' => $hasData("SELECT COUNT(*) FROM prosedur_pasien WHERE no_rawat = ?", [$noRawat]),
            'sent' => $hasData("SELECT COUNT(*) FROM satu_sehat_procedure WHERE no_rawat = ?", [$noRawat]),
        ];

        // 4. AllergyIntolerance — from pemeriksaan_ralan/ranap.alergi (CLI source,
        //    NOT riwayat_alergi which does not exist on this hospital).
        //    Exclude the '-' sentinel (means "no allergy recorded").
        $hasAllergy = $hasData("SELECT COUNT(*) FROM pemeriksaan_ralan WHERE no_rawat = ? AND alergi IS NOT NULL AND alergi != '' AND alergi != '-'", [$noRawat])
            || $hasData("SELECT COUNT(*) FROM pemeriksaan_ranap WHERE no_rawat = ? AND alergi IS NOT NULL AND alergi != '' AND alergi != '-'", [$noRawat]);
        $manifest[] = [
            'type' => 'AllergyIntolerance',
            'available' => $hasAllergy,
            'sent' => $hasData("SELECT COUNT(*) FROM satu_sehat_allergy_intolerance WHERE no_rawat = ? AND id_allergy_intolerance NOT IN ('', '-')", [$noRawat]),
        ];

        // 5. MedicationRequest / Dispense / Statement — from resep_obat.
        // sent requires the exact (no_resep, kode_brng) pairs of THIS visit —
        // a single no_resep match is a false positive (one prescription holds
        // many drugs). Empty/'-' ids are not-synced sentinels.
        $hasMeds = $hasData("SELECT COUNT(*) FROM resep_obat WHERE no_rawat = ?", [$noRawat]);
        $manifest[] = [
            'type' => 'MedicationRequest',
            'available' => $hasMeds,
            'sent' => $hasData("
                SELECT COUNT(*) FROM resep_obat ro
                INNER JOIN resep_dokter rd ON rd.no_resep = ro.no_resep
                INNER JOIN satu_sehat_medicationrequest ssmr
                    ON ssmr.no_resep = rd.no_resep AND ssmr.kode_brng = rd.kode_brng
                WHERE ro.no_rawat = ? AND ssmr.id_medicationrequest NOT IN ('', '-')", [$noRawat]),
        ];
        $manifest[] = [
            'type' => 'MedicationDispense',
            'available' => $hasMeds,
            'sent' => $hasData("SELECT COUNT(*) FROM satu_sehat_medicationdispense WHERE no_rawat = ? AND id_medicationdispanse NOT IN ('', '-')", [$noRawat]),
        ];
        $manifest[] = [
            'type' => 'MedicationStatement',
            'available' => $hasMeds,
            'sent' => $hasData("
                SELECT COUNT(*) FROM resep_obat ro
                INNER JOIN resep_dokter rd ON rd.no_resep = ro.no_resep
                INNER JOIN satu_sehat_medicationstatement ssms
                    ON ssms.no_resep = rd.no_resep AND ssms.kode_brng = rd.kode_brng
                WHERE ro.no_rawat = ? AND ssms.id_medicationstatement NOT IN ('', '-')", [$noRawat]),
        ];

        // 6. CarePlan — from pemeriksaan_ralan/ranap.rtl (CLI source; nota_jalan
        //    has no rtl column on this hospital)
        $manifest[] = [
            'type' => 'CarePlan',
            'available' => $hasData("SELECT COUNT(*) FROM pemeriksaan_ralan WHERE no_rawat = ? AND rtl IS NOT NULL AND rtl != ''", [$noRawat])
                || $hasData("SELECT COUNT(*) FROM pemeriksaan_ranap WHERE no_rawat = ? AND rtl IS NOT NULL AND rtl != ''", [$noRawat]),
            'sent' => $hasData("SELECT COUNT(*) FROM satu_sehat_careplan WHERE no_rawat = ? AND id_careplan NOT IN ('', '-')", [$noRawat]),
        ];

        // 7. ClinicalImpression — from pemeriksaan_ralan/ranap with penilaian (matching CLI)
        $manifest[] = [
            'type' => 'ClinicalImpression',
            'available' => $hasData("SELECT COUNT(*) FROM pemeriksaan_ralan WHERE no_rawat = ? AND penilaian <> ''", [$noRawat])
                || $hasData("SELECT COUNT(*) FROM pemeriksaan_ranap WHERE no_rawat = ? AND penilaian <> ''", [$noRawat]),
            'sent' => $hasData("SELECT COUNT(*) FROM satu_sehat_clinicalimpression WHERE no_rawat = ?", [$noRawat]),
        ];

        // 8. Lab pipeline — ServiceRequest, Specimen, Observation, DiagnosticReport.
        // Available = request tables exist (permintaan_lab / permintaan_labmb /
        // permintaan_radiologi — the CLI's Lab pipeline sources, NOT periksa_lab/
        // laboratorium). Sent = mapping tables keyed by noorder joined back to
        // the visit via the permintaan_* header, non-empty id.
        $hasLabPk = $hasData("SELECT COUNT(*) FROM permintaan_lab WHERE no_rawat = ?", [$noRawat]);
        $hasLabMb = $hasData("SELECT COUNT(*) FROM permintaan_labmb WHERE no_rawat = ?", [$noRawat]);
        $hasRad  = $hasData("SELECT COUNT(*) FROM permintaan_radiologi WHERE no_rawat = ?", [$noRawat]);

        $manifest[] = [
            'type' => 'ServiceRequest',
            'available' => $hasLabPk || $hasLabMb || $hasRad,
            'sent' => $hasData("
                SELECT 1 FROM permintaan_lab pl
                INNER JOIN satu_sehat_servicerequest_lab sssr ON sssr.noorder = pl.noorder
                WHERE pl.no_rawat = ? AND sssr.id_servicerequest NOT IN ('', '-')", [$noRawat])
                || $hasData("
                SELECT 1 FROM permintaan_labmb pl
                INNER JOIN satu_sehat_servicerequest_lab_mb sssr ON sssr.noorder = pl.noorder
                WHERE pl.no_rawat = ? AND sssr.id_servicerequest NOT IN ('', '-')", [$noRawat])
                || $hasData("
                SELECT 1 FROM permintaan_radiologi pl
                INNER JOIN satu_sehat_servicerequest_radiologi sssr ON sssr.noorder = pl.noorder
                WHERE pl.no_rawat = ? AND sssr.id_servicerequest NOT IN ('', '-')", [$noRawat]),
        ];
        $manifest[] = [
            'type' => 'Specimen',
            'available' => $hasLabPk || $hasLabMb || $hasRad,
            'sent' => $hasData("
                SELECT 1 FROM permintaan_lab pl
                INNER JOIN satu_sehat_specimen_lab sssp ON sssp.noorder = pl.noorder
                WHERE pl.no_rawat = ? AND sssp.id_specimen NOT IN ('', '-')", [$noRawat])
                || $hasData("
                SELECT 1 FROM permintaan_labmb pl
                INNER JOIN satu_sehat_specimen_lab_mb sssp ON sssp.noorder = pl.noorder
                WHERE pl.no_rawat = ? AND sssp.id_specimen NOT IN ('', '-')", [$noRawat])
                || $hasData("
                SELECT 1 FROM permintaan_radiologi pl
                INNER JOIN satu_sehat_specimen_radiologi sssp ON sssp.noorder = pl.noorder
                WHERE pl.no_rawat = ? AND sssp.id_specimen NOT IN ('', '-')", [$noRawat]),
        ];
        $manifest[] = [
            'type' => 'Observation',
            'available' => $hasLabPk || $hasLabMb || $hasRad,
            'sent' => $hasData("
                SELECT 1 FROM permintaan_lab pl
                INNER JOIN satu_sehat_observation_lab sso ON sso.noorder = pl.noorder
                WHERE pl.no_rawat = ? AND sso.id_observation NOT IN ('', '-')", [$noRawat])
                || $hasData("
                SELECT 1 FROM permintaan_labmb pl
                INNER JOIN satu_sehat_observation_lab_mb sso ON sso.noorder = pl.noorder
                WHERE pl.no_rawat = ? AND sso.id_observation NOT IN ('', '-')", [$noRawat])
                || $hasData("
                SELECT 1 FROM permintaan_radiologi pl
                INNER JOIN satu_sehat_observation_radiologi sso ON sso.noorder = pl.noorder
                WHERE pl.no_rawat = ? AND sso.id_observation NOT IN ('', '-')", [$noRawat]),
        ];
        $manifest[] = [
            'type' => 'DiagnosticReport',
            'available' => $hasLabPk || $hasLabMb || $hasRad,
            'sent' => $hasData("
                SELECT 1 FROM permintaan_lab pl
                INNER JOIN satu_sehat_diagnosticreport_lab ssdr ON ssdr.noorder = pl.noorder
                WHERE pl.no_rawat = ? AND ssdr.id_diagnosticreport NOT IN ('', '-')", [$noRawat])
                || $hasData("
                SELECT 1 FROM permintaan_labmb pl
                INNER JOIN satu_sehat_diagnosticreport_lab_mb ssdr ON ssdr.noorder = pl.noorder
                WHERE pl.no_rawat = ? AND ssdr.id_diagnosticreport NOT IN ('', '-')", [$noRawat])
                || $hasData("
                SELECT 1 FROM permintaan_radiologi pl
                INNER JOIN satu_sehat_diagnosticreport_radiologi ssdr ON ssdr.noorder = pl.noorder
                WHERE pl.no_rawat = ? AND ssdr.id_diagnosticreport NOT IN ('', '-')", [$noRawat]),
        ];

        // 9. Medication — keyed by kode_brng (KFA lookup). sent iff a drug on
        //    this visit's prescriptions has a real id.
        $manifest[] = [
            'type' => 'Medication',
            'available' => $hasMeds,
            'sent' => $hasData("
                SELECT 1 FROM resep_obat ro
                INNER JOIN resep_dokter rd ON rd.no_resep = ro.no_resep
                INNER JOIN satu_sehat_medication ssm ON ssm.kode_brng = rd.kode_brng
                WHERE ro.no_rawat = ? AND ssm.id_medication NOT IN ('', '-')", [$noRawat]),
        ];

        // 10. Immunization — from detail_pemberian_obat + mapping_vaksin (CLI source)
        $manifest[] = [
            'type' => 'Immunization',
            'available' => $hasData("
                SELECT COUNT(*) FROM detail_pemberian_obat dpo
                JOIN satu_sehat_mapping_vaksin smv ON smv.kode_brng = dpo.kode_brng
                WHERE dpo.no_rawat = ? AND dpo.no_batch <> ''", [$noRawat]),
            'sent' => $hasData("SELECT COUNT(*) FROM satu_sehat_immunization WHERE no_rawat = ? AND id_immunization NOT IN ('', '-')", [$noRawat]),
        ];

        // 11. Composition — available after discharge (nota exists)
        $manifest[] = [
            'type' => 'Composition',
            'available' => $hasData("SELECT COUNT(*) FROM nota_jalan WHERE no_rawat = ?", [$noRawat])
                || $hasData("SELECT COUNT(*) FROM nota_inap WHERE no_rawat = ?", [$noRawat]),
            'sent' => $hasData("SELECT COUNT(*) FROM satu_sehat_composition WHERE no_rawat = ?", [$noRawat]),
        ];

        // 12. QuestionnaireResponse — from telaah_farmasi joined via resep_obat
        $manifest[] = [
            'type' => 'QuestionnaireResponse',
            'available' => $hasData("
                SELECT 1 FROM resep_obat ro
                INNER JOIN telaah_farmasi tf ON tf.no_resep = ro.no_resep
                WHERE ro.no_rawat = ?", [$noRawat]),
            'sent' => $hasData("
                SELECT 1 FROM resep_obat ro
                INNER JOIN satu_sehat_questionresponse_telaah_farmasi ssqr ON ssqr.no_resep = ro.no_resep
                WHERE ro.no_rawat = ? AND ssqr.id_questionresponse NOT IN ('', '-')", [$noRawat]),
        ];

        // 13. Patient — available if pasien has valid NIK and IHS not mapped yet
        $manifest[] = [
            'type' => 'Patient',
            'available' => $hasData("SELECT COUNT(*) FROM pasien WHERE no_rkm_medis = ? AND no_ktp REGEXP '^[0-9]{16}$'", [$patient['no_rkm_medis']]),
            'sent' => $hasData("SELECT COUNT(*) FROM satu_sehat_ihs_patient WHERE nikpasien = ? AND ihspasien NOT IN ('', '-')", [$patient['no_ktp']]),
        ];

        // 14. EpisodeOfCare — requires diagnosis + Encounter + Condition.
        // sent = a row exists with a real id (the CLI stores raw status
        // 'Ralan'/'Ranap' here; its own gating is "any row in the table").
        $manifest[] = [
            'type' => 'EpisodeOfCare',
            'available' => $hasData("SELECT COUNT(*) FROM diagnosa_pasien dp WHERE dp.no_rawat = ?", [$noRawat])
                && $hasData("SELECT COUNT(*) FROM satu_sehat_encounter WHERE no_rawat = ?", [$noRawat])
                && $hasData("SELECT COUNT(*) FROM satu_sehat_condition WHERE no_rawat = ?", [$noRawat]),
            'sent' => $hasData("SELECT COUNT(*) FROM satu_sehat_episode_of_care WHERE no_rawat = ? AND id_episode_of_care NOT IN ('', '-')", [$noRawat]),
        ];

        // 15. ObservationTTV — from pemeriksaan_ralan/ranap (any of 10 vital signs)
        $manifest[] = [
            'type' => 'ObservationTTV',
            'available' => $hasData("SELECT COUNT(*) FROM pemeriksaan_ralan WHERE no_rawat = ?", [$noRawat])
                || $hasData("SELECT COUNT(*) FROM pemeriksaan_ranap WHERE no_rawat = ?", [$noRawat]),
            'sent' => $hasData("
                SELECT 1 FROM (
                    SELECT id_observation FROM satu_sehat_observationttvsuhu WHERE no_rawat = ?
                    UNION ALL SELECT id_observation FROM satu_sehat_observationttvrespirasi WHERE no_rawat = ?
                    UNION ALL SELECT id_observation FROM satu_sehat_observationttvnadi WHERE no_rawat = ?
                    UNION ALL SELECT id_observation FROM satu_sehat_observationttvspo2 WHERE no_rawat = ?
                    UNION ALL SELECT id_observation FROM satu_sehat_observationttvtb WHERE no_rawat = ?
                    UNION ALL SELECT id_observation FROM satu_sehat_observationttvbb WHERE no_rawat = ?
                    UNION ALL SELECT id_observation FROM satu_sehat_observationttvlp WHERE no_rawat = ?
                    UNION ALL SELECT id_observation FROM satu_sehat_observationttvtensi WHERE no_rawat = ?
                    UNION ALL SELECT id_observation FROM satu_sehat_observationttvgcs WHERE no_rawat = ?
                    UNION ALL SELECT id_observation FROM satu_sehat_observationttvkesadaran WHERE no_rawat = ?
                ) t WHERE id_observation NOT IN ('', '-')", [$noRawat, $noRawat, $noRawat, $noRawat, $noRawat, $noRawat, $noRawat, $noRawat, $noRawat, $noRawat]),
        ];

        // Sort: available first, then by type name
        usort($manifest, function ($a, $b) {
            if ($a['available'] !== $b['available']) {
                return $a['available'] ? -1 : 1;
            }
            return strcmp($a['type'], $b['type']);
        });

        return $manifest;
    }
}
