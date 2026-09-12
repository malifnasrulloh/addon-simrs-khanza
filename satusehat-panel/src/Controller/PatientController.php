<?php

namespace SatusehatPanel\Controller;

defined('PANEL_BASE') || exit('Direct script access denied.');

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
            // Direct column comparison (sargable) — DATE() wrapping would
            // defeat the tgl_registrasi index.
            $where .= " AND rp.tgl_registrasi >= ?";
            $params[] = $since;
        }
        if ($until !== '') {
            $where .= " AND rp.tgl_registrasi < DATE_ADD(?, INTERVAL 1 DAY)";
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
                COALESCE(
                    (SELECT nj.tanggal FROM nota_jalan nj WHERE nj.no_rawat = rp.no_rawat LIMIT 1),
                    (SELECT ni.tanggal FROM nota_inap ni WHERE ni.no_rawat = rp.no_rawat LIMIT 1)
                ) AS tgl_keluar,
                COALESCE(
                    (SELECT nj.jam FROM nota_jalan nj WHERE nj.no_rawat = rp.no_rawat LIMIT 1),
                    (SELECT ni.jam FROM nota_inap ni WHERE ni.no_rawat = rp.no_rawat LIMIT 1)
                ) AS jam_keluar
            FROM reg_periksa rp
            JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
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

        // Rail stats: same date/search scope as the list, computed server-side
        // over the FULL filtered set (not just this page).
        $stats = self::stats($where, $params);

        return [
            'success' => true,
            'data' => $patients,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'pages' => $pages,
            ],
            'stats' => $stats,
        ];
    }

    /**
     * Aggregate totals over the filtered registration set:
     * total / paid / unpaid from billing status, ready = patients having at
     * least one resource type with source data that is not fully sent.
     * Mirrors the availability/coverage rules of buildResourceManifest().
     */
    private static function stats(string $where, array $params): array
    {
        $db = Database::getMysql();
        $base = "FROM reg_periksa rp JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis {$where}";

        $total = 0;
        $paid = 0;
        try {
            $stmt = $db->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(rp.status_bayar LIKE '%Sudah%'), 0) AS paid {$base}");
            $stmt->execute($params);
            $row = $stmt->fetch();
            $total = (int) ($row['total'] ?? 0);
            $paid = (int) ($row['paid'] ?? 0);
        } catch (\Throwable $e) {
            // Stats are advisory — never fail the list over them.
        }

        $ready = 0;
        try {
            $predicates = array_values(self::pendingResourcePredicates());
            if ($predicates !== []) {
                $sql = "SELECT COUNT(DISTINCT rp.no_rawat) AS ready {$base} AND (" . implode(' OR ', $predicates) . ')';
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $ready = (int) ($stmt->fetch()['ready'] ?? 0);
            }
        } catch (\Throwable $e) {
            $ready = 0;
        }

        return [
            'total' => $total,
            'paid' => $paid,
            'unpaid' => $total - $paid,
            'ready' => $ready,
        ];
    }

    /**
     * One SQL predicate per resource type: "this visit has source data but
     * not every instance carries a real SATUSEHAT id". Used by the stats
     * "ready" count; keep in exact parity with buildResourceManifest().
     * Every expression references the outer alias `rp` (reg_periksa).
     *
     * @internal — exposed for structural tests (predicate coverage).
     * @return array<string, string>
     */
    public static function pendingResourcePredicates(): array
    {
        $src = fn(string $body) => "(SELECT COUNT(*) FROM {$body})";
        $mapReal = fn(string $table, string $idCol) =>
            "(SELECT COUNT(*) FROM {$table} WHERE no_rawat = rp.no_rawat AND {$idCol} NOT IN ('', '-'))";
        // MedicationRequest/MedicationStatement are keyed (no_resep, kode_brng)
        // — they have NO no_rawat column. Resolve the visit via resep_obat,
        // mirroring the CLI (Database.php): JOIN resep_obat ON no_resep.
        $mapMed = fn(string $table, string $idCol) =>
            "(SELECT COUNT(*) FROM {$table} ssm INNER JOIN resep_obat ro ON ro.no_resep = ssm.no_resep"
            . " WHERE ro.no_rawat = rp.no_rawat AND ssm.{$idCol} NOT IN ('', '-'))";
        $anyReal = fn(string $table, string $idCol) =>
            "NOT EXISTS (SELECT 1 FROM {$table} WHERE no_rawat = rp.no_rawat AND {$idCol} NOT IN ('', '-'))";

        $srcCond = $src('diagnosa_pasien dp WHERE dp.no_rawat = rp.no_rawat')
            . ' + ' . $src("pemeriksaan_ralan pr WHERE pr.no_rawat = rp.no_rawat AND pr.keluhan IS NOT NULL AND pr.keluhan != ''")
            . ' + ' . $src("pemeriksaan_ranap pr WHERE pr.no_rawat = rp.no_rawat AND pr.keluhan IS NOT NULL AND pr.keluhan != ''");
        $srcMed = $src('resep_obat ro INNER JOIN resep_dokter rd ON rd.no_resep = ro.no_resep WHERE ro.no_rawat = rp.no_rawat');
        $srcTTV = $src('pemeriksaan_ralan pr WHERE pr.no_rawat = rp.no_rawat')
            . ' + ' . $src('pemeriksaan_ranap pr WHERE pr.no_rawat = rp.no_rawat');

        // MedicationDispense parity with the manifest's strict allCovered:
        // every administered drug line (detail_pemberian_obat, keyed exactly
        // as the CLI syncs it) must carry a real mapping id.
        $srcDpo = $src('detail_pemberian_obat dpo WHERE dpo.no_rawat = rp.no_rawat');
        $mapDpo = $src('detail_pemberian_obat dpo INNER JOIN satu_sehat_medicationdispense ssmd'
            . ' ON ssmd.no_rawat = dpo.no_rawat AND ssmd.tgl_perawatan = dpo.tgl_perawatan'
            . ' AND ssmd.jam = dpo.jam AND ssmd.kode_brng = dpo.kode_brng'
            . ' AND ssmd.no_batch = dpo.no_batch AND ssmd.no_faktur = dpo.no_faktur'
            . ' WHERE dpo.no_rawat = rp.no_rawat AND ssmd.id_medicationdispanse NOT IN (\'\', \'-\')');

        $predicates = [
            'Encounter' => "NOT EXISTS (SELECT 1 FROM satu_sehat_encounter e WHERE e.no_rawat = rp.no_rawat)",
            'Condition' => "({$srcCond} > 0) AND ({$srcCond} > {$mapReal('satu_sehat_condition', 'id_condition')})",
            'Procedure' => "(SELECT COUNT(*) FROM prosedur_pasien pp WHERE pp.no_rawat = rp.no_rawat) > 0"
                . " AND (SELECT COUNT(*) FROM prosedur_pasien pp WHERE pp.no_rawat = rp.no_rawat) > {$mapReal('satu_sehat_procedure', 'id_procedure')}",
            'AllergyIntolerance' => "((SELECT COUNT(*) FROM pemeriksaan_ralan pr WHERE pr.no_rawat = rp.no_rawat AND pr.alergi IS NOT NULL AND pr.alergi != '' AND pr.alergi != '-')"
                . " + (SELECT COUNT(*) FROM pemeriksaan_ranap pr WHERE pr.no_rawat = rp.no_rawat AND pr.alergi IS NOT NULL AND pr.alergi != '' AND pr.alergi != '-')) > 0"
                . " AND {$anyReal('satu_sehat_allergy_intolerance', 'id_allergy_intolerance')}",
            'MedicationRequest' => "({$srcMed} > 0) AND ({$srcMed} > {$mapMed('satu_sehat_medicationrequest', 'id_medicationrequest')})",
            'MedicationDispense' => "({$srcMed} > 0) AND ({$srcDpo} > {$mapDpo})",
            'MedicationStatement' => "({$srcMed} > 0) AND ({$srcMed} > {$mapMed('satu_sehat_medicationstatement', 'id_medicationstatement')})",
            'Medication' => "({$srcMed} > 0) AND NOT EXISTS (SELECT 1 FROM resep_obat ro"
                . " INNER JOIN resep_dokter rd ON rd.no_resep = ro.no_resep"
                . " INNER JOIN satu_sehat_medication ssm ON ssm.kode_brng = rd.kode_brng"
                . " WHERE ro.no_rawat = rp.no_rawat AND ssm.id_medication NOT IN ('', '-'))",
            'CarePlan' => "((SELECT COUNT(*) FROM pemeriksaan_ralan pr WHERE pr.no_rawat = rp.no_rawat AND pr.rtl IS NOT NULL AND pr.rtl != '')"
                . " + (SELECT COUNT(*) FROM pemeriksaan_ranap pr WHERE pr.no_rawat = rp.no_rawat AND pr.rtl IS NOT NULL AND pr.rtl != '')) > 0"
                . " AND {$anyReal('satu_sehat_careplan', 'id_careplan')}",
            'ClinicalImpression' => "((SELECT COUNT(*) FROM pemeriksaan_ralan pr WHERE pr.no_rawat = rp.no_rawat AND pr.penilaian <> '')"
                . " + (SELECT COUNT(*) FROM pemeriksaan_ranap pr WHERE pr.no_rawat = rp.no_rawat AND pr.penilaian <> '')) > 0"
                . " AND {$anyReal('satu_sehat_clinicalimpression', 'id_clinicalimpression')}",
            'Immunization' => "((SELECT COUNT(*) FROM detail_pemberian_obat dpo JOIN satu_sehat_mapping_vaksin smv ON smv.kode_brng = dpo.kode_brng"
                . " WHERE dpo.no_rawat = rp.no_rawat AND dpo.no_batch <> '')) > 0"
                . " AND {$anyReal('satu_sehat_immunization', 'id_immunization')}",
            'Composition' => "((SELECT COUNT(*) FROM nota_jalan nj WHERE nj.no_rawat = rp.no_rawat)"
                . " + (SELECT COUNT(*) FROM nota_inap ni WHERE ni.no_rawat = rp.no_rawat)) > 0"
                . " AND NOT EXISTS (SELECT 1 FROM satu_sehat_composition sc WHERE sc.no_rawat = rp.no_rawat)",
            'QuestionnaireResponse' => "((SELECT COUNT(*) FROM resep_obat ro INNER JOIN telaah_farmasi tf ON tf.no_resep = ro.no_resep"
                . " WHERE ro.no_rawat = rp.no_rawat)) > 0"
                . " AND NOT EXISTS (SELECT 1 FROM resep_obat ro INNER JOIN satu_sehat_questionresponse_telaah_farmasi ssqr ON ssqr.no_resep = ro.no_resep"
                . " WHERE ro.no_rawat = rp.no_rawat AND ssqr.id_questionresponse NOT IN ('', '-'))",
            'EpisodeOfCare' => "(SELECT COUNT(*) FROM diagnosa_pasien dp WHERE dp.no_rawat = rp.no_rawat) > 0"
                . " AND (SELECT COUNT(*) FROM satu_sehat_encounter e WHERE e.no_rawat = rp.no_rawat) > 0"
                . " AND (SELECT COUNT(*) FROM satu_sehat_condition sc WHERE sc.no_rawat = rp.no_rawat) > 0"
                . " AND {$anyReal('satu_sehat_episode_of_care', 'id_episode_of_care')}",
            'ObservationTTV' => "({$srcTTV} > 0) AND NOT EXISTS (SELECT 1 FROM ("
                . implode(' UNION ALL ', array_map(
                    fn(string $t) => "SELECT id_observation FROM {$t} WHERE no_rawat = rp.no_rawat",
                    ['satu_sehat_observationttvsuhu', 'satu_sehat_observationttvrespirasi', 'satu_sehat_observationttvnadi',
                     'satu_sehat_observationttvspo2', 'satu_sehat_observationttvtb', 'satu_sehat_observationttvbb',
                     'satu_sehat_observationttvlp', 'satu_sehat_observationttvtensi', 'satu_sehat_observationttvgcs',
                     'satu_sehat_observationttvkesadaran'],
                ))
                . ") t WHERE t.id_observation NOT IN ('', '-'))",
        ];

        // Lab pipeline — per-type pending when ANY source variant (pk/mb/rad)
        // has detail rows that are not fully covered by real ids.
        $labSrcPk = 'SELECT COUNT(*) FROM permintaan_lab pl INNER JOIN permintaan_detail_permintaan_lab dl ON dl.noorder = pl.noorder WHERE pl.no_rawat = rp.no_rawat';
        $labSrcMb = 'SELECT COUNT(*) FROM permintaan_labmb pl INNER JOIN permintaan_detail_permintaan_labmb dl ON dl.noorder = pl.noorder WHERE pl.no_rawat = rp.no_rawat';
        $labSrcRad = 'SELECT COUNT(*) FROM permintaan_radiologi pr INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder WHERE pr.no_rawat = rp.no_rawat';
        $labTypes = [
            'ServiceRequest' => ['satu_sehat_servicerequest_lab', 'satu_sehat_servicerequest_lab_mb', 'satu_sehat_servicerequest_radiologi', 'id_servicerequest'],
            'Specimen' => ['satu_sehat_specimen_lab', 'satu_sehat_specimen_lab_mb', 'satu_sehat_specimen_radiologi', 'id_specimen'],
            'Observation' => ['satu_sehat_observation_lab', 'satu_sehat_observation_lab_mb', 'satu_sehat_observation_radiologi', 'id_observation'],
            'DiagnosticReport' => ['satu_sehat_diagnosticreport_lab', 'satu_sehat_diagnosticreport_lab_mb', 'satu_sehat_diagnosticreport_radiologi', 'id_diagnosticreport'],
        ];
        foreach ($labTypes as $type => [$tPk, $tMb, $tRad, $idCol]) {
            $predicates[$type] = "(({$labSrcPk}) > 0 AND ({$labSrcPk}) > "
                    . "(SELECT COUNT(*) FROM permintaan_lab pl INNER JOIN permintaan_detail_permintaan_lab dl ON dl.noorder = pl.noorder"
                    . " INNER JOIN {$tPk} ss ON ss.noorder = dl.noorder WHERE pl.no_rawat = rp.no_rawat AND ss.{$idCol} NOT IN ('', '-')))"
                . " OR (({$labSrcMb}) > 0 AND ({$labSrcMb}) > "
                    . "(SELECT COUNT(*) FROM permintaan_labmb pl INNER JOIN permintaan_detail_permintaan_labmb dl ON dl.noorder = pl.noorder"
                    . " INNER JOIN {$tMb} ss ON ss.noorder = dl.noorder WHERE pl.no_rawat = rp.no_rawat AND ss.{$idCol} NOT IN ('', '-')))"
                . " OR (({$labSrcRad}) > 0 AND ({$labSrcRad}) > "
                    . "(SELECT COUNT(*) FROM permintaan_radiologi pr INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder"
                    . " INNER JOIN {$tRad} ss ON ss.noorder = ppr.noorder WHERE pr.no_rawat = rp.no_rawat AND ss.{$idCol} NOT IN ('', '-')))";
        }

        return $predicates;
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
            SELECT rp.*, pj.nm_pasien, pj.no_ktp, pj.no_rkm_medis, pj.tgl_lahir, pj.jk,
                (SELECT nj.tanggal FROM nota_jalan nj WHERE nj.no_rawat = rp.no_rawat LIMIT 1) AS tgl_keluar,
                (SELECT nj.jam FROM nota_jalan nj WHERE nj.no_rawat = rp.no_rawat LIMIT 1) AS jam_keluar_jalan,
                (SELECT ni.tanggal FROM nota_inap ni WHERE ni.no_rawat = rp.no_rawat LIMIT 1) AS tgl_keluar_inap,
                (SELECT ni.jam FROM nota_inap ni WHERE ni.no_rawat = rp.no_rawat LIMIT 1) AS jam_keluar_inap
            FROM reg_periksa rp
            JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            WHERE rp.no_rawat = ?
        ");
        $stmt->execute([$noRawat]);
        $patient = $stmt->fetch();
        if (!$patient) {
            return ['success' => false, 'error' => 'Patient not found'];
        }
        // Merge discharge date from nota_jalan (outpatient) or nota_inap (inpatient).
        if (empty($patient['tgl_keluar']) && !empty($patient['tgl_keluar_inap'])) {
            $patient['tgl_keluar'] = $patient['tgl_keluar_inap'];
            $patient['jam_keluar'] = $patient['jam_keluar_inap'];
        } else {
            $patient['jam_keluar'] = $patient['jam_keluar_jalan'] ?? null;
        }
        unset($patient['jam_keluar_jalan'], $patient['tgl_keluar_inap'], $patient['jam_keluar_inap']);

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
            'Encounter' => "SELECT no_rawat, COUNT(*) as cnt FROM satu_sehat_encounter WHERE no_rawat IN ({$placeholders}) AND id_encounter NOT IN ('', '-') GROUP BY no_rawat",
            'Condition' => "SELECT no_rawat, COUNT(*) as cnt FROM satu_sehat_condition WHERE no_rawat IN ({$placeholders}) AND id_condition NOT IN ('', '-') GROUP BY no_rawat",
            'Procedure' => "SELECT no_rawat, COUNT(*) as cnt FROM satu_sehat_procedure WHERE no_rawat IN ({$placeholders}) AND id_procedure NOT IN ('', '-') GROUP BY no_rawat",
            'MedicationDispense' => "SELECT no_rawat, COUNT(*) as cnt FROM satu_sehat_medicationdispense WHERE no_rawat IN ({$placeholders}) AND id_medicationdispanse NOT IN ('', '-') GROUP BY no_rawat",
            'CarePlan' => "SELECT no_rawat, COUNT(*) as cnt FROM satu_sehat_careplan WHERE no_rawat IN ({$placeholders}) AND id_careplan NOT IN ('', '-') GROUP BY no_rawat",
            'ClinicalImpression' => "SELECT no_rawat, COUNT(*) as cnt FROM satu_sehat_clinicalimpression WHERE no_rawat IN ({$placeholders}) AND id_clinicalimpression NOT IN ('', '-') GROUP BY no_rawat",
            'Composition' => "SELECT no_rawat, COUNT(*) as cnt FROM satu_sehat_composition WHERE no_rawat IN ({$placeholders}) AND id_composition NOT IN ('', '-') GROUP BY no_rawat",
            'AllergyIntolerance' => "SELECT no_rawat, COUNT(*) as cnt FROM satu_sehat_allergy_intolerance WHERE no_rawat IN ({$placeholders}) AND id_allergy_intolerance NOT IN ('', '-') GROUP BY no_rawat",
            'Immunization' => "SELECT no_rawat, COUNT(*) as cnt FROM satu_sehat_immunization WHERE no_rawat IN ({$placeholders}) AND id_immunization NOT IN ('', '-') GROUP BY no_rawat",
            'MedicationRequest' => "SELECT ro.no_rawat, COUNT(*) as cnt FROM resep_obat ro
                INNER JOIN resep_dokter rd ON rd.no_resep = ro.no_resep
                INNER JOIN satu_sehat_medicationrequest ssmr ON ssmr.no_resep = rd.no_resep AND ssmr.kode_brng = rd.kode_brng
                WHERE ro.no_rawat IN ({$placeholders}) AND ssmr.id_medicationrequest NOT IN ('', '-') GROUP BY ro.no_rawat",
            'MedicationStatement' => "SELECT ro.no_rawat, COUNT(*) as cnt FROM resep_obat ro
                INNER JOIN resep_dokter rd ON rd.no_resep = ro.no_resep
                INNER JOIN satu_sehat_medicationstatement ssms ON ssms.no_resep = rd.no_resep AND ssms.kode_brng = rd.kode_brng
                WHERE ro.no_rawat IN ({$placeholders}) AND ssms.id_medicationstatement NOT IN ('', '-') GROUP BY ro.no_rawat",
            'Medication' => "SELECT ro.no_rawat, COUNT(DISTINCT ssm.kode_brng) as cnt FROM resep_obat ro
                INNER JOIN resep_dokter rd ON rd.no_resep = ro.no_resep
                INNER JOIN satu_sehat_medication ssm ON ssm.kode_brng = rd.kode_brng
                WHERE ro.no_rawat IN ({$placeholders}) AND ssm.id_medication NOT IN ('', '-') GROUP BY ro.no_rawat",
            'EpisodeOfCare' => "SELECT no_rawat, COUNT(*) as cnt FROM satu_sehat_episode_of_care WHERE no_rawat IN ({$placeholders}) AND id_episode_of_care NOT IN ('', '-') GROUP BY no_rawat",
            'QuestionnaireResponse' => "SELECT ro.no_rawat, COUNT(*) as cnt FROM resep_obat ro
                INNER JOIN satu_sehat_questionresponse_telaah_farmasi ssqr ON ssqr.no_resep = ro.no_resep
                WHERE ro.no_rawat IN ({$placeholders}) AND ssqr.id_questionresponse NOT IN ('', '-') GROUP BY ro.no_rawat",
        ];

        // Lab pipeline counts: per-type UNION over the pk/mb/rad variants,
        // each joined to its order header so counts stay visit-scoped.
        $labTables = [
            'ServiceRequest' => ['satu_sehat_servicerequest_lab', 'satu_sehat_servicerequest_lab_mb', 'satu_sehat_servicerequest_radiologi', 'id_servicerequest'],
            'Specimen' => ['satu_sehat_specimen_lab', 'satu_sehat_specimen_lab_mb', 'satu_sehat_specimen_radiologi', 'id_specimen'],
            'Observation' => ['satu_sehat_observation_lab', 'satu_sehat_observation_lab_mb', 'satu_sehat_observation_radiologi', 'id_observation'],
            'DiagnosticReport' => ['satu_sehat_diagnosticreport_lab', 'satu_sehat_diagnosticreport_lab_mb', 'satu_sehat_diagnosticreport_radiologi', 'id_diagnosticreport'],
        ];
        foreach ($labTables as $resType => [$tLab, $tMb, $tRad, $idCol]) {
            // Each UNION member embeds its own placeholder set; the bind list
            // must therefore carry the visit list once PER member (3×). The
            // outer alias maps nr back to no_rawat for the fetch loop.
            $p1 = implode(',', array_fill(0, count($noRawatList), '?'));
            $p2 = implode(',', array_fill(0, count($noRawatList), '?'));
            $p3 = implode(',', array_fill(0, count($noRawatList), '?'));
            $unions = [
                "SELECT pl.no_rawat AS nr FROM permintaan_lab pl INNER JOIN {$tLab} ss ON ss.noorder = pl.noorder WHERE pl.no_rawat IN ({$p1}) AND ss.{$idCol} NOT IN ('', '-')",
                "SELECT pl.no_rawat AS nr FROM permintaan_labmb pl INNER JOIN {$tMb} ss ON ss.noorder = pl.noorder WHERE pl.no_rawat IN ({$p2}) AND ss.{$idCol} NOT IN ('', '-')",
                "SELECT pr.no_rawat AS nr FROM permintaan_radiologi pr INNER JOIN {$tRad} ss ON ss.noorder = pr.noorder WHERE pr.no_rawat IN ({$p3}) AND ss.{$idCol} NOT IN ('', '-')",
            ];
            $tables[$resType] = "SELECT nr AS no_rawat, COUNT(*) as cnt FROM (" . implode(' UNION ALL ', $unions) . ") t GROUP BY nr";
        }

        // ObservationTTV: 10 vital-sign mapping tables, each keyed by no_rawat.
        $ttvTables = [
            'satu_sehat_observationttvsuhu', 'satu_sehat_observationttvrespirasi', 'satu_sehat_observationttvnadi',
            'satu_sehat_observationttvspo2', 'satu_sehat_observationttvtb', 'satu_sehat_observationttvbb',
            'satu_sehat_observationttvlp', 'satu_sehat_observationttvtensi', 'satu_sehat_observationttvgcs',
            'satu_sehat_observationttvkesadaran',
        ];
        $ttvUnions = [];
        foreach ($ttvTables as $t) {
            $ph = implode(',', array_fill(0, count($noRawatList), '?'));
            $ttvUnions[] = "SELECT no_rawat AS nr FROM {$t} WHERE no_rawat IN ({$ph}) AND id_observation NOT IN ('', '-')";
        }
        $tables['ObservationTTV'] = "SELECT nr AS no_rawat, COUNT(*) as cnt FROM (" . implode(' UNION ALL ', $ttvUnions) . ") t GROUP BY nr";

        foreach ($tables as $resType => $sql) {
            try {
                $stmt = $db->prepare($sql);
                // Lab UNION queries embed 3 placeholder sets; ObservationTTV
                // embeds 10; regular queries embed 1.
                if (in_array($resType, array_keys($labTables), true)) {
                    $params = array_merge($noRawatList, $noRawatList, $noRawatList);
                } elseif ($resType === 'ObservationTTV') {
                    $params = array_merge(...array_fill(0, 10, $noRawatList));
                } else {
                    $params = $noRawatList;
                }
                $stmt->execute($params);
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

        // Coverage check: a multi-instance type is only "sent" when EVERY
        // source instance has a real SATUSEHAT id. Any rejected entry (the
        // A1 bug) leaves the type partially sent and therefore re-sendable.
        $allCovered = function (string $sourceSql, array $sourceParams, string $mappedSql, array $mappedParams) use ($db): bool {
            try {
                $src = $db->prepare($sourceSql);
                $src->execute($sourceParams);
                $nSrc = (int) $src->fetchColumn();
                if ($nSrc <= 0) {
                    return false;
                }
                $map = $db->prepare($mappedSql);
                $map->execute($mappedParams);
                return ((int) $map->fetchColumn()) >= $nSrc;
            } catch (\Throwable $e) {
                return false;
            }
        };

        // 1. Encounter — always exists for a registration
        $manifest[] = [
            'type' => 'Encounter',
            'available' => true,
            'sent' => $hasData("SELECT COUNT(*) FROM satu_sehat_encounter WHERE no_rawat = ? AND id_encounter NOT IN ('', '-')", [$noRawat]),
        ];

        // 2. Condition — diagnoses + chief complaints (keluhan utama). Sent only
        //    when ALL instances of the visit carry a real id.
        $manifest[] = [
            'type' => 'Condition',
            'available' => $hasData("SELECT COUNT(*) FROM diagnosa_pasien WHERE no_rawat = ?", [$noRawat])
                || $hasData("SELECT COUNT(*) FROM pemeriksaan_ralan WHERE no_rawat = ? AND keluhan IS NOT NULL AND keluhan != ''", [$noRawat])
                || $hasData("SELECT COUNT(*) FROM pemeriksaan_ranap WHERE no_rawat = ? AND keluhan IS NOT NULL AND keluhan != ''", [$noRawat]),
            'sent' => $allCovered(
                "SELECT (SELECT COUNT(*) FROM diagnosa_pasien WHERE no_rawat = ?)
                     + (SELECT COUNT(*) FROM pemeriksaan_ralan WHERE no_rawat = ? AND keluhan IS NOT NULL AND keluhan != '')
                     + (SELECT COUNT(*) FROM pemeriksaan_ranap WHERE no_rawat = ? AND keluhan IS NOT NULL AND keluhan != '')",
                [$noRawat, $noRawat, $noRawat],
                "SELECT COUNT(*) FROM satu_sehat_condition WHERE no_rawat = ? AND id_condition NOT IN ('', '-')",
                [$noRawat]
            ),
        ];

        // 3. Procedure — from prosedur_pasien + icd9 (CLI source). All-or-nothing.
        $manifest[] = [
            'type' => 'Procedure',
            'available' => $hasData("SELECT COUNT(*) FROM prosedur_pasien WHERE no_rawat = ?", [$noRawat]),
            'sent' => $allCovered(
                "SELECT COUNT(*) FROM prosedur_pasien WHERE no_rawat = ?",
                [$noRawat],
                "SELECT COUNT(*) FROM satu_sehat_procedure WHERE no_rawat = ? AND id_procedure NOT IN ('', '-')",
                [$noRawat]
            ),
        ];

        // 4. AllergyIntolerance — from pemeriksaan_ralan/ranap.alergi (CLI source,
        //    NOT riwayat_alergi which does not exist on this hospital).
        //    Exclude the '-' sentinel (means "no allergy recorded").
        $hasAllergy = $hasData("SELECT COUNT(*) FROM pemeriksaan_ralan WHERE no_rawat = ? AND alergi IS NOT NULL AND alergi != '' AND alergi != '-'", [$noRawat])
            || $hasData("SELECT COUNT(*) FROM pemeriksaan_ranap WHERE no_rawat = ? AND alergi IS NOT NULL AND alergi != '' AND alergi != '-'", [$noRawat]);
        $sentAllergy = $hasData("SELECT COUNT(*) FROM satu_sehat_allergy_intolerance WHERE no_rawat = ? AND id_allergy_intolerance NOT IN ('', '-')", [$noRawat]);
        $manifest[] = [
            'type' => 'AllergyIntolerance',
            'available' => $hasAllergy || $sentAllergy,
            'sent' => $sentAllergy,
        ];

        // 5. MedicationRequest / Dispense / Statement — from resep_obat & detail_pemberian_obat.
        $hasPrescription = $hasData("SELECT COUNT(*) FROM resep_obat WHERE no_rawat = ?", [$noRawat]);
        $sentMedRequest = $allCovered(
            "SELECT COUNT(*) FROM resep_obat ro INNER JOIN resep_dokter rd ON rd.no_resep = ro.no_resep WHERE ro.no_rawat = ?",
            [$noRawat],
            "SELECT COUNT(*) FROM resep_obat ro
             INNER JOIN resep_dokter rd ON rd.no_resep = ro.no_resep
             INNER JOIN satu_sehat_medicationrequest ssmr
                 ON ssmr.no_resep = rd.no_resep AND ssmr.kode_brng = rd.kode_brng
             WHERE ro.no_rawat = ? AND ssmr.id_medicationrequest NOT IN ('', '-')",
            [$noRawat]
        );
        $manifest[] = [
            'type' => 'MedicationRequest',
            'available' => $hasPrescription || $sentMedRequest,
            'sent' => $sentMedRequest,
        ];

        // MedicationDispense is sourced from detail_pemberian_obat
        $hasDispense = $hasData("SELECT COUNT(*) FROM detail_pemberian_obat WHERE no_rawat = ? AND status IN ('Ralan', 'Ranap')", [$noRawat]);
        $sentDispense = $allCovered(
            "SELECT COUNT(*) FROM detail_pemberian_obat dpo WHERE dpo.no_rawat = ? AND dpo.status IN ('Ralan', 'Ranap')",
            [$noRawat],
            "SELECT COUNT(*) FROM detail_pemberian_obat dpo
             INNER JOIN satu_sehat_medicationdispense ssmd
                 ON ssmd.no_rawat = dpo.no_rawat AND ssmd.tgl_perawatan = dpo.tgl_perawatan
                 AND ssmd.jam = dpo.jam AND ssmd.kode_brng = dpo.kode_brng
                 AND ssmd.no_batch = dpo.no_batch AND ssmd.no_faktur = dpo.no_faktur
             WHERE dpo.no_rawat = ? AND ssmd.id_medicationdispanse NOT IN ('', '-')",
            [$noRawat]
        );
        $manifest[] = [
            'type' => 'MedicationDispense',
            'available' => $hasDispense || $sentDispense,
            'sent' => $sentDispense,
        ];

        $sentMedStatement = $allCovered(
            "SELECT COUNT(*) FROM resep_obat ro INNER JOIN resep_dokter rd ON rd.no_resep = ro.no_resep WHERE ro.no_rawat = ?",
            [$noRawat],
            "SELECT COUNT(*) FROM resep_obat ro
             INNER JOIN resep_dokter rd ON rd.no_resep = ro.no_resep
             INNER JOIN satu_sehat_medicationstatement ssms
                 ON ssms.no_resep = rd.no_resep AND ssms.kode_brng = rd.kode_brng
             WHERE ro.no_rawat = ? AND ssms.id_medicationstatement NOT IN ('', '-')",
            [$noRawat]
        );
        $manifest[] = [
            'type' => 'MedicationStatement',
            'available' => $hasPrescription || $sentMedStatement,
            'sent' => $sentMedStatement,
        ];

        // 6. CarePlan — from pemeriksaan_ralan/ranap.rtl
        $hasCarePlan = $hasData("SELECT COUNT(*) FROM pemeriksaan_ralan WHERE no_rawat = ? AND rtl IS NOT NULL AND rtl != '' AND rtl != '-'", [$noRawat])
            || $hasData("SELECT COUNT(*) FROM pemeriksaan_ranap WHERE no_rawat = ? AND rtl IS NOT NULL AND rtl != '' AND rtl != '-'", [$noRawat]);
        $sentCarePlan = $hasData("SELECT COUNT(*) FROM satu_sehat_careplan WHERE no_rawat = ? AND id_careplan NOT IN ('', '-')", [$noRawat]);
        $manifest[] = [
            'type' => 'CarePlan',
            'available' => $hasCarePlan || $sentCarePlan,
            'sent' => $sentCarePlan,
        ];

        // 7. ClinicalImpression — from pemeriksaan_ralan/ranap with penilaian
        $hasClinical = $hasData("
            SELECT 1 FROM pemeriksaan_ralan pem
            WHERE pem.no_rawat = ? AND pem.penilaian IS NOT NULL AND pem.penilaian <> '' AND pem.penilaian <> '-'
            UNION ALL
            SELECT 1 FROM pemeriksaan_ranap pem
            WHERE pem.no_rawat = ? AND pem.penilaian IS NOT NULL AND pem.penilaian <> '' AND pem.penilaian <> '-'
        ", [$noRawat, $noRawat]);
        $sentClinical = $hasData("SELECT COUNT(*) FROM satu_sehat_clinicalimpression WHERE no_rawat = ? AND id_clinicalimpression NOT IN ('', '-')", [$noRawat]);
        $manifest[] = [
            'type' => 'ClinicalImpression',
            'available' => $hasClinical || $sentClinical,
            'sent' => $sentClinical,
        ];

        // 8. Lab pipeline — ServiceRequest, Specimen, Observation, DiagnosticReport.
        // Available = request tables exist (permintaan_lab / permintaan_labmb /
        // permintaan_radiologi — the CLI's Lab pipeline sources, NOT periksa_lab/
        // laboratorium). Sent = mapping tables keyed by noorder joined back to
        // the visit via the permintaan_* header, non-empty id.
        $hasLabPk = $hasData("SELECT COUNT(*) FROM permintaan_lab WHERE no_rawat = ?", [$noRawat]);
        $hasLabMb = $hasData("SELECT COUNT(*) FROM permintaan_labmb WHERE no_rawat = ?", [$noRawat]);
        $hasRad  = $hasData("SELECT COUNT(*) FROM permintaan_radiologi WHERE no_rawat = ?", [$noRawat]);

        // Lab coverage per variant: source request-detail pairs vs mapped
        // rows with real ids. All variants with data must be fully covered.
        // The three source counts are identical for every lab resource type —
        // compute them ONCE here instead of per type (4×3 queries -> 3).
        $labSource = [
            'pk'  => "SELECT COUNT(*) FROM permintaan_lab pl INNER JOIN permintaan_detail_permintaan_lab dl ON dl.noorder = pl.noorder WHERE pl.no_rawat = ?",
            'mb'  => "SELECT COUNT(*) FROM permintaan_labmb pl INNER JOIN permintaan_detail_permintaan_labmb dl ON dl.noorder = pl.noorder WHERE pl.no_rawat = ?",
            'rad' => "SELECT COUNT(*) FROM permintaan_radiologi pr INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder WHERE pr.no_rawat = ?",
        ];
        $labSrcCounts = [];
        foreach ($labSource as $variant => $sql) {
            try {
                $stmt = $db->prepare($sql);
                $stmt->execute([$noRawat]);
                $labSrcCounts[$variant] = (int) $stmt->fetchColumn();
            } catch (\Throwable $e) {
                $labSrcCounts[$variant] = 0;
            }
        }
        $labMapped = [
            'pk'  => "SELECT COUNT(*) FROM permintaan_lab pl INNER JOIN permintaan_detail_permintaan_lab dl ON dl.noorder = pl.noorder INNER JOIN {table} ss ON ss.noorder = dl.noorder WHERE pl.no_rawat = ? AND ss.{idCol} NOT IN ('', '-')",
            'mb'  => "SELECT COUNT(*) FROM permintaan_labmb pl INNER JOIN permintaan_detail_permintaan_labmb dl ON dl.noorder = pl.noorder INNER JOIN {table} ss ON ss.noorder = dl.noorder WHERE pl.no_rawat = ? AND ss.{idCol} NOT IN ('', '-')",
            'rad' => "SELECT COUNT(*) FROM permintaan_radiologi pr INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder INNER JOIN {table} ss ON ss.noorder = ppr.noorder WHERE pr.no_rawat = ? AND ss.{idCol} NOT IN ('', '-')",
        ];
        $allLabCovered = function (array $tables, string $idCol) use ($db, $labMapped, $labSrcCounts, $noRawat): bool {
            try {
                $totalSrc = array_sum($labSrcCounts);
                if ($totalSrc <= 0) {
                    return false;
                }
                foreach ($labSrcCounts as $variant => $nSrc) {
                    if ($nSrc <= 0) {
                        continue;
                    }
                    $mapSql = str_replace(['{table}', '{idCol}'], [$tables[$variant], $idCol], $labMapped[$variant]);
                    $map = $db->prepare($mapSql);
                    $map->execute([$noRawat]);
                    if (((int) $map->fetchColumn()) < $nSrc) {
                        return false;
                    }
                }
                return true;
            } catch (\Throwable $e) {
                return false;
            }
        };

        $hasLabMapped = $hasData("
            SELECT 1 FROM (
                SELECT 1 FROM permintaan_lab pl
                INNER JOIN permintaan_detail_permintaan_lab dl ON dl.noorder = pl.noorder
                INNER JOIN template_laboratorium tl ON tl.id_template = dl.id_template
                INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
                WHERE pl.no_rawat = ? AND sml.code IS NOT NULL
                UNION ALL
                SELECT 1 FROM permintaan_labmb pl
                INNER JOIN permintaan_detail_permintaan_labmb dl ON dl.noorder = pl.noorder
                INNER JOIN template_laboratorium tl ON tl.id_template = dl.id_template
                INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
                WHERE pl.no_rawat = ? AND sml.code IS NOT NULL
                UNION ALL
                SELECT 1 FROM permintaan_radiologi pr
                INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
                INNER JOIN satu_sehat_mapping_radiologi smr ON smr.kd_jenis_prw = ppr.kd_jenis_prw
                WHERE pr.no_rawat = ? AND smr.code IS NOT NULL
            ) t LIMIT 1
        ", [$noRawat, $noRawat, $noRawat]);

        $sentSR = $allLabCovered([
            'pk' => 'satu_sehat_servicerequest_lab',
            'mb' => 'satu_sehat_servicerequest_lab_mb',
            'rad' => 'satu_sehat_servicerequest_radiologi',
        ], 'id_servicerequest');
        $manifest[] = [
            'type' => 'ServiceRequest',
            'available' => $hasLabMapped || $sentSR,
            'sent' => $sentSR,
        ];

        $sentSP = $allLabCovered([
            'pk' => 'satu_sehat_specimen_lab',
            'mb' => 'satu_sehat_specimen_lab_mb',
            'rad' => 'satu_sehat_specimen_radiologi',
        ], 'id_specimen');
        $manifest[] = [
            'type' => 'Specimen',
            'available' => $hasLabMapped || $sentSP,
            'sent' => $sentSP,
        ];

        $sentOB = $allLabCovered([
            'pk' => 'satu_sehat_observation_lab',
            'mb' => 'satu_sehat_observation_lab_mb',
            'rad' => 'satu_sehat_observation_radiologi',
        ], 'id_observation');
        $manifest[] = [
            'type' => 'Observation',
            'available' => $hasLabMapped || $sentOB,
            'sent' => $sentOB,
        ];

        $sentDR = $allLabCovered([
            'pk' => 'satu_sehat_diagnosticreport_lab',
            'mb' => 'satu_sehat_diagnosticreport_lab_mb',
            'rad' => 'satu_sehat_diagnosticreport_radiologi',
        ], 'id_diagnosticreport');
        $manifest[] = [
            'type' => 'DiagnosticReport',
            'available' => $hasLabMapped || $sentDR,
            'sent' => $sentDR,
        ];

        // 9. Medication — keyed by kode_brng (KFA lookup).
        $hasMedicationRows = $hasData("
            SELECT 1 FROM resep_obat ro
            INNER JOIN resep_dokter rd ON rd.no_resep = ro.no_resep
            INNER JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = rd.kode_brng
            WHERE ro.no_rawat = ?
            UNION ALL
            SELECT 1 FROM detail_pemberian_obat dpo
            INNER JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = dpo.kode_brng
            WHERE dpo.no_rawat = ?
        ", [$noRawat, $noRawat]);
        $sentMedication = $hasData("
            SELECT 1 FROM resep_obat ro
            INNER JOIN resep_dokter rd ON rd.no_resep = ro.no_resep
            INNER JOIN satu_sehat_medication ssm ON ssm.kode_brng = rd.kode_brng
            WHERE ro.no_rawat = ? AND ssm.id_medication NOT IN ('', '-')", [$noRawat]);
        $manifest[] = [
            'type' => 'Medication',
            'available' => $hasMedicationRows || $sentMedication,
            'sent' => $sentMedication,
        ];

        // 10. Immunization — from detail_pemberian_obat + mapping_vaksin
        $hasImmunization = $hasData("
            SELECT COUNT(*) FROM detail_pemberian_obat dpo
            JOIN satu_sehat_mapping_vaksin smv ON smv.kode_brng = dpo.kode_brng
            WHERE dpo.no_rawat = ? AND dpo.no_batch <> ''", [$noRawat]);
        $sentImmunization = $hasData("SELECT COUNT(*) FROM satu_sehat_immunization WHERE no_rawat = ? AND id_immunization NOT IN ('', '-')", [$noRawat]);
        $manifest[] = [
            'type' => 'Immunization',
            'available' => $hasImmunization || $sentImmunization,
            'sent' => $sentImmunization,
        ];

        // 11. Composition — available after discharge (nota exists)
        $hasComposition = $hasData("SELECT 1 FROM nota_jalan WHERE no_rawat = ?", [$noRawat])
            || $hasData("SELECT 1 FROM nota_inap WHERE no_rawat = ?", [$noRawat]);
        $sentComposition = $hasData("SELECT COUNT(*) FROM satu_sehat_composition WHERE no_rawat = ? AND id_composition NOT IN ('', '-')", [$noRawat]);
        $manifest[] = [
            'type' => 'Composition',
            'available' => $hasComposition || $sentComposition,
            'sent' => $sentComposition,
        ];

        // 12. QuestionnaireResponse — from telaah_farmasi joined via resep_obat
        $hasQR = $hasData("
            SELECT 1 FROM resep_obat ro
            INNER JOIN telaah_farmasi tf ON tf.no_resep = ro.no_resep
            WHERE ro.no_rawat = ?", [$noRawat]);
        $sentQR = $hasData("
            SELECT 1 FROM resep_obat ro
            INNER JOIN satu_sehat_questionresponse_telaah_farmasi ssqr ON ssqr.no_resep = ro.no_resep
            WHERE ro.no_rawat = ? AND ssqr.id_questionresponse NOT IN ('', '-')", [$noRawat]);
        $manifest[] = [
            'type' => 'QuestionnaireResponse',
            'available' => $hasQR || $sentQR,
            'sent' => $sentQR,
        ];

        // 13. EpisodeOfCare — requires recognized government health program diagnosis (TB, HIV, ANC, Stunting, etc.)
        $hasEocData = $hasData("
            SELECT COUNT(*) FROM diagnosa_pasien dp
            WHERE dp.no_rawat = ?
              AND (dp.kd_penyakit LIKE 'A15%' OR dp.kd_penyakit LIKE 'A16%' OR dp.kd_penyakit LIKE 'A17%'
                OR dp.kd_penyakit LIKE 'A18%' OR dp.kd_penyakit LIKE 'A19%' OR dp.kd_penyakit LIKE 'B20%'
                OR dp.kd_penyakit LIKE 'B21%' OR dp.kd_penyakit LIKE 'B22%' OR dp.kd_penyakit LIKE 'B23%'
                OR dp.kd_penyakit LIKE 'B24%' OR dp.kd_penyakit LIKE 'O%' OR dp.kd_penyakit LIKE 'E40%'
                OR dp.kd_penyakit LIKE 'E41%' OR dp.kd_penyakit LIKE 'E42%' OR dp.kd_penyakit LIKE 'E43%'
                OR dp.kd_penyakit LIKE 'E44%' OR dp.kd_penyakit LIKE 'E45%' OR dp.kd_penyakit LIKE 'E46%')
        ", [$noRawat]);
        $sentEoc = $hasData("SELECT COUNT(*) FROM satu_sehat_episode_of_care WHERE no_rawat = ? AND id_episode_of_care NOT IN ('', '-')", [$noRawat]);
        $manifest[] = [
            'type' => 'EpisodeOfCare',
            'available' => $hasEocData || $sentEoc,
            'sent' => $sentEoc,
        ];

        // 14. ObservationTTV — only available if at least one vital sign value is entered
        $hasTtvData = $hasData("
            SELECT 1 FROM (
                SELECT 1 FROM pemeriksaan_ralan WHERE no_rawat = ? AND (
                    (suhu_tubuh IS NOT NULL AND suhu_tubuh <> '' AND suhu_tubuh <> '-') OR
                    (tensi IS NOT NULL AND tensi <> '' AND tensi <> '-') OR
                    (nadi IS NOT NULL AND nadi <> '' AND nadi <> '-') OR
                    (respirasi IS NOT NULL AND respirasi <> '' AND respirasi <> '-') OR
                    (tinggi IS NOT NULL AND tinggi <> '' AND tinggi <> '-') OR
                    (berat IS NOT NULL AND berat <> '' AND berat <> '-') OR
                    (spo2 IS NOT NULL AND spo2 <> '' AND spo2 <> '-') OR
                    (gcs IS NOT NULL AND gcs <> '' AND gcs <> '-')
                )
                UNION ALL
                SELECT 1 FROM pemeriksaan_ranap WHERE no_rawat = ? AND (
                    (suhu_tubuh IS NOT NULL AND suhu_tubuh <> '' AND suhu_tubuh <> '-') OR
                    (tensi IS NOT NULL AND tensi <> '' AND tensi <> '-') OR
                    (nadi IS NOT NULL AND nadi <> '' AND nadi <> '-') OR
                    (respirasi IS NOT NULL AND respirasi <> '' AND respirasi <> '-') OR
                    (tinggi IS NOT NULL AND tinggi <> '' AND tinggi <> '-') OR
                    (berat IS NOT NULL AND berat <> '' AND berat <> '-') OR
                    (spo2 IS NOT NULL AND spo2 <> '' AND spo2 <> '-') OR
                    (gcs IS NOT NULL AND gcs <> '' AND gcs <> '-')
                )
            ) t LIMIT 1
        ", [$noRawat, $noRawat]);

        $sentTtv = $hasData("
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
            ) t WHERE id_observation NOT IN ('', '-') LIMIT 1", [$noRawat, $noRawat, $noRawat, $noRawat, $noRawat, $noRawat, $noRawat, $noRawat, $noRawat, $noRawat]);

        $manifest[] = [
            'type' => 'ObservationTTV',
            'available' => $hasTtvData || $sentTtv,
            'sent' => $sentTtv,
        ];

        // Attach dependency declarations
        $prereqs = [
            'Condition'             => ['Encounter'],
            'Procedure'             => ['Encounter'],
            'AllergyIntolerance'    => ['Encounter'],
            'Observation'           => ['Encounter'],
            'ObservationTTV'        => ['Encounter'],
            'MedicationRequest'     => ['Encounter'],
            'MedicationDispense'    => ['Encounter', 'MedicationRequest'],
            'MedicationStatement'   => ['Encounter'],
            'ServiceRequest'        => ['Encounter'],
            'Specimen'              => ['Encounter', 'ServiceRequest'],
            'DiagnosticReport'      => ['Encounter', 'ServiceRequest'],
            'Immunization'          => ['Encounter'],
            'CarePlan'              => ['Encounter'],
            'ClinicalImpression'    => ['Encounter'],
            'QuestionnaireResponse' => ['Encounter'],
            'ImagingStudy'          => ['Encounter', 'ServiceRequest'],
            'Composition'           => ['Encounter'],
            'EpisodeOfCare'         => ['Encounter'],
        ];

        foreach ($manifest as &$item) {
            $item['depends_on'] = $prereqs[$item['type']] ?? [];
        }
        unset($item);

        // Sort: available first, then by canonical FHIR dependency hierarchy
        $dependencyOrder = [
            'EpisodeOfCare',
            'Encounter',
            'Medication',
            'ServiceRequest',
            'Specimen',
            'Condition',
            'Observation',
            'ObservationTTV',
            'Procedure',
            'AllergyIntolerance',
            'MedicationRequest',
            'Immunization',
            'MedicationDispense',
            'MedicationStatement',
            'DiagnosticReport',
            'QuestionnaireResponse',
            'ClinicalImpression',
            'CarePlan',
            'ImagingStudy',
            'Composition',
        ];

        usort($manifest, function ($a, $b) use ($dependencyOrder) {
            if ($a['available'] !== $b['available']) {
                return $a['available'] ? -1 : 1;
            }
            $posA = array_search($a['type'], $dependencyOrder, true);
            $posB = array_search($b['type'], $dependencyOrder, true);
            $idxA = $posA !== false ? $posA : 999;
            $idxB = $posB !== false ? $posB : 999;
            return $idxA <=> $idxB;
        });

        return $manifest;
    }
}
