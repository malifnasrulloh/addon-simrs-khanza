<?php

namespace SatusehatPanel\Util;

use SatusehatPanel\Core\Database;
use SatusehatPanel\Core\Config;

/**
 * PayloadAdapter - Bridges the panel's DB access to SatuSehatPayloadBuilder.
 */

/**
 * PayloadAdapter - Bridges the panel's DB access to SatuSehatPayloadBuilder.
 *
 * V3 REWRITE - comprehensive audit fixes:
 *  - ObservationTTV: iterates all 10 vital signs individually (was 1 merged payload)
 *  - ClinicalImpression: uses pemeriksaan_ralan/ranap UNION matching CLI pattern
 *  - Encounter status: 3-phase lifecycle (arrived -> in-progress -> finished)
 *  - Procedure JOIN: composite key prevents duplicate rows
 *  - Composition: validates discharge exists before building
 *  - All payloads attach _panel_persist_keys for composite table inserts
 *  - Condition status matched to ssc.status column per CLI pattern
 */
class PayloadAdapter
{
    /**
     * Build FHIR payload(s) for a resource type using PayloadBuilder.
     *
     * @param array $refs optional in-bundle references (type => [uuid, ...])
     *                    forwarded to composition() for section entries.
     *
     * @return array List of FHIR payloads. Empty array if no data exists.
     *               Each item is a complete FHIR resource ready for a Bundle entry.
     */
    public static function build(string $resource, string $noRawat, array $patient, array $refs = []): array
    {
        $db = Database::getMysql();
        $orgId = (string) Config::get('satusehat.org_id', '');

        return match ($resource) {
            'Encounter' => self::wrapSingle(self::buildEncounter($db, $patient)),
            'Condition' => self::buildConditionMulti($db, $patient),
            'Procedure' => self::buildProcedureMulti($db, $patient),
            'Medication' => self::buildMedicationMulti($db, $noRawat, $orgId),
            'MedicationRequest' => self::buildMedicationFamilyMulti($db, $patient, $orgId, 'request'),
            'MedicationDispense' => self::buildMedicationFamilyMulti($db, $patient, $orgId, 'dispense'),
            'MedicationStatement' => self::buildMedicationFamilyMulti($db, $patient, $orgId, 'statement'),
            'CarePlan' => self::wrapSingle(self::buildCarePlan($db, $patient, $orgId)),
            'AllergyIntolerance' => self::wrapSingle(self::buildAllergy($db, $patient)),
            'Immunization' => self::buildImmunizationMulti($db, $patient),
            'ClinicalImpression' => self::buildClinicalImpressionMulti($db, $patient),
            'ServiceRequest' => self::buildLabPipelineMulti($db, $patient, $orgId, 'serviceRequest'),
            'Specimen' => self::buildLabPipelineMulti($db, $patient, $orgId, 'specimen'),
            'Observation' => self::buildLabPipelineMulti($db, $patient, $orgId, 'observation'),
            'DiagnosticReport' => self::buildLabPipelineMulti($db, $patient, $orgId, 'diagnosticReport'),
            'Composition' => self::wrapSingle(self::buildComposition($db, $patient, $orgId, $refs)),
            'QuestionnaireResponse' => self::buildQuestionnaireResponseMulti($db, $patient),
            'EpisodeOfCare' => self::wrapSingle(self::buildEpisodeOfCare($db, $patient, $orgId)),
            'ObservationTTV' => self::buildObservationTTVMulti($db, $patient),
            default => [],
        };
    }

    /** Wrap a single nullable payload into a list for uniform return type. */
    private static function wrapSingle(?array $payload): array
    {
        return $payload !== null ? [$payload] : [];
    }

    // Patient removed from the sendable manifest (T35) — IHS lookup stays.

    // Patient removed from the sendable manifest (T35) — IHS lookup stays.

    /**
     * Resolve patient IHS ID, calling SATUSEHAT API if needed.
     * Only called during actual send, never during preview.
     */
    public static function resolvePatientIhs(\PDO $db, string $nik): string
    {
        if (!preg_match('/^[0-9]{16}$/', $nik)) return '';

        $stmt = $db->prepare("SELECT ihspasien FROM satu_sehat_ihs_patient WHERE nikpasien = ? LIMIT 1");
        $stmt->execute([$nik]);
        $ihs = trim((string) ($stmt->fetchColumn() ?: ''));
        if ($ihs !== '' && $ihs !== '-') return $ihs;

        try {
            $client = self::getClient();
            $result = $client->get('/Patient?identifier=https://fhir.kemkes.go.id/id/nik|' . $nik);
            $entry = $result['data']['entry'][0]['resource'] ?? null;
            if ($result['success'] && $entry && isset($entry['id'])) {
                $ihs = $entry['id'];
                $upd = $db->prepare("INSERT INTO satu_sehat_ihs_patient (nikpasien, ihspasien) VALUES (?, ?) ON DUPLICATE KEY UPDATE ihspasien = VALUES(ihspasien)");
                $upd->execute([$nik, $ihs]);
                return $ihs;
            }
        } catch (\Throwable $e) { /* fall through */ }

        return 'P-PASIEN-IHS-PLACEHOLDER';
    }

    /**
     * Resolve practitioner IHS ID, calling SATUSEHAT API if needed.
     * Only called during actual send, never during preview.
     */
    public static function resolveDokterIhs(\PDO $db, string $nikDokter): string
    {
        if (empty($nikDokter)) return '';

        $stmt = $db->prepare("SELECT ihspegawai FROM satu_sehat_ihs_practitioner WHERE nikpegawai = ? LIMIT 1");
        $stmt->execute([$nikDokter]);
        $ihs = trim((string) ($stmt->fetchColumn() ?: ''));
        if ($ihs !== '' && $ihs !== '-') return $ihs;

        try {
            $client = self::getClient();
            $result = $client->get('/Practitioner?identifier=https://fhir.kemkes.go.id/id/nik|' . $nikDokter);
            $entry = $result['data']['entry'][0]['resource'] ?? null;
            if ($result['success'] && $entry && isset($entry['id'])) {
                $ihs = $entry['id'];
                $upd = $db->prepare("INSERT INTO satu_sehat_ihs_practitioner (nikpegawai, ihspegawai) VALUES (?, ?) ON DUPLICATE KEY UPDATE ihspegawai = VALUES(ihspegawai)");
                $upd->execute([$nikDokter, $ihs]);
                return $ihs;
            }
        } catch (\Throwable $e) { /* fall through */ }

        return 'N-DOKTER-IHS-PLACEHOLDER';
    }

    // EpisodeOfCare (single per visit)

    private static function buildEpisodeOfCare(\PDO $db, array $patient, string $orgId): ?array
    {
        $ihs = self::getIhsIds($patient);
        $stmt = $db->prepare("
            SELECT
                rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis,
                pj.nm_pasien, pj.no_ktp, rp.kd_dokter, pg.nama, pg.no_ktp AS ktpdokter,
                dp.kd_penyakit, py.nm_penyakit, rp.stts, rp.status_lanjut, dp.status,
                pr.tgl_perawatan, pr.jam_rawat, ki.tgl_keluar, ki.jam_keluar,
                sse.id_encounter, ssc.id_condition, IFNULL(sseo.id_episode_of_care, '') AS id_episode_of_care
            FROM reg_periksa rp
            JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            LEFT JOIN pegawai pg ON pg.nik = rp.kd_dokter
            LEFT JOIN diagnosa_pasien dp ON dp.no_rawat = rp.no_rawat
            LEFT JOIN penyakit py ON py.kd_penyakit = dp.kd_penyakit
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            LEFT JOIN pemeriksaan_ralan pr ON pr.no_rawat = rp.no_rawat
            LEFT JOIN kamar_inap ki ON ki.no_rawat = rp.no_rawat
            LEFT JOIN satu_sehat_condition ssc ON ssc.no_rawat = rp.no_rawat AND ssc.kd_penyakit = dp.kd_penyakit
            LEFT JOIN satu_sehat_episode_of_care sseo ON sseo.no_rawat = rp.no_rawat
            WHERE rp.no_rawat = ?
              AND (sseo.id_episode_of_care IS NULL OR sseo.id_episode_of_care IN ('', '-'))
            LIMIT 1
        ");
        $stmt->execute([$patient['no_rawat']]);
        $row = $stmt->fetch();
        if (!$row) return null;

        $type = \EpisodeOfCareType::fromIcdCode($row['kd_penyakit'] ?? '');
        if ($type === null) return null;

        $payload = \SatuSehatPayloadBuilder::episodeOfCare($orgId, $row, $ihs['pasien'], $ihs['dokter'], 'active', $type, $row['id_episode_of_care'] ?? '');
        if ($payload !== null) {
            $payload = self::withPersistKeys($payload, 'satu_sehat_episode_of_care', 'id_episode_of_care', $row, ['no_rawat', 'kd_penyakit', 'status']);
        }
        return $payload;
    }

    // Shared helpers

    /**
     * Attach _panel_persist_keys to a payload for SendController persist +
     * ReferenceRegistry routing. Only keys that actually exist in $row are
     * included, so the composite matches the mapping table's real schema.
     */
    private static function withPersistKeys(array $payload, string $table, string $idCol, array $row, array $wantedKeys): array
    {
        $keys = [];
        foreach ($wantedKeys as $k) {
            if (array_key_exists($k, $row) && $row[$k] !== null) {
                $keys[$k] = (string) $row[$k];
            }
        }
        $payload['_panel_persist_keys'] = [
            'table' => $table,
            'id_col' => $idCol,
            'keys' => $keys,
        ];
        return $payload;
    }

    private static function getClient(): \SatuSehatClient
    {
        $config = \CredentialLocator::buildSatuSehatConfig();
        $log = new \Logger($config->logDir, 'panel_lookup');
        return new \SatuSehatClient($config, $log);
    }

    private static function getIhsIds(array $patient): array
    {
        $db = Database::getMysql();
        $ihsPasien = '';
        if (!empty($patient['no_ktp'])) {
            try {
                $stmt = $db->prepare("SELECT ihspasien FROM satu_sehat_ihs_patient WHERE nikpasien = ? LIMIT 1");
                $stmt->execute([$patient['no_ktp']]);
                $ihsPasien = trim((string) ($stmt->fetchColumn() ?: ''));
                if ($ihsPasien === '-') $ihsPasien = '';
            } catch (\Throwable $e) { $ihsPasien = ''; }
        }
        $ihsDokter = '';
        if (!empty($patient['no_rawat'])) {
            try {
                $stmt2 = $db->prepare("
                    SELECT ihspegawai FROM satu_sehat_ihs_practitioner
                    WHERE nikpegawai = (SELECT pg.nik FROM reg_periksa rp JOIN pegawai pg ON pg.nik = rp.kd_dokter WHERE rp.no_rawat = ? LIMIT 1)
                    LIMIT 1
                ");
                $stmt2->execute([$patient['no_rawat']]);
                $ihsDokter = trim((string) ($stmt2->fetchColumn() ?: ''));
                if ($ihsDokter === '-') $ihsDokter = '';
            } catch (\Throwable $e) { $ihsDokter = ''; }
        }
        if ($ihsPasien === '') $ihsPasien = 'P-PASIEN-IHS-PLACEHOLDER';
        if ($ihsDokter === '') $ihsDokter = 'N-DOKTER-IHS-PLACEHOLDER';
        return ['pasien' => $ihsPasien, 'dokter' => $ihsDokter];
    }

    private static function fetchEncounterRow(array $patient): array
    {
        $db = Database::getMysql();
        $stmt = $db->prepare("
            SELECT
                rp.no_rawat, rp.tgl_registrasi, rp.jam_reg, rp.status_lanjut,
                rp.kd_poli, rp.kd_pj, rp.no_rkm_medis, rp.kd_dokter,
                pj.nm_pasien, pj.no_ktp, pj.jk, pj.tgl_lahir, pj.nm_ibu, pj.alamat,
                pol.nm_poli, pg.nama,
                COALESCE(smlranap.id_lokasi_satusehat, smlr.id_lokasi_satusehat) AS id_lokasi_satusehat,
                ki.tgl_masuk, ki.jam_masuk, ki.stts_pulang, ki.lama, ki.kd_kamar,
                COALESCE(nj.tanggal, ni.tanggal) AS tgl_keluar,
                COALESCE(nj.jam, ni.jam) AS jam_keluar,
                -- Finalization timestamps the builder needs (B4): discharge
                -- datetime + the latest examination time as 'waktu_perawatan'.
                CONCAT(COALESCE(nj.tanggal, ni.tanggal), ' ', COALESCE(nj.jam, ni.jam)) AS waktu_pulang,
                (SELECT CONCAT(pr2.tgl_perawatan, ' ', pr2.jam_rawat)
                 FROM pemeriksaan_ralan pr2
                 WHERE pr2.no_rawat = rp.no_rawat
                 ORDER BY pr2.tgl_perawatan DESC, pr2.jam_rawat DESC LIMIT 1) AS waktu_perawatan,
                IFNULL(sse.id_encounter, '') AS id_encounter
            FROM reg_periksa rp
            JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            LEFT JOIN pegawai pg ON pg.nik = rp.kd_dokter
            LEFT JOIN poliklinik pol ON pol.kd_poli = rp.kd_poli
            LEFT JOIN satu_sehat_mapping_lokasi_ralan smlr ON smlr.kd_poli = rp.kd_poli
            LEFT JOIN (
                SELECT ki2.*
                FROM kamar_inap ki2
                INNER JOIN (
                    SELECT no_rawat, MAX(CONCAT(tgl_masuk, ' ', jam_masuk)) AS latest
                    FROM kamar_inap
                    GROUP BY no_rawat
                ) ki_latest ON ki_latest.no_rawat = ki2.no_rawat
                    AND CONCAT(ki2.tgl_masuk, ' ', ki2.jam_masuk) = ki_latest.latest
            ) ki ON ki.no_rawat = rp.no_rawat
            LEFT JOIN satu_sehat_mapping_lokasi_ranap smlranap ON smlranap.kd_kamar = ki.kd_kamar
            LEFT JOIN nota_jalan nj ON nj.no_rawat = rp.no_rawat
            LEFT JOIN nota_inap ni ON ni.no_rawat = rp.no_rawat
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            WHERE rp.no_rawat = ?
            LIMIT 1
        ");
        $stmt->execute([$patient['no_rawat']]);
        return $stmt->fetch() ?: $patient;
    }

    // Encounter (single per visit) — 3-phase lifecycle matching CLI

    private static function buildEncounter(\PDO $db, array $patient): ?array
    {
        $row = self::fetchEncounterRow($patient);
        $ihs = self::getIhsIds($patient);

        // Status logic matching CLI's 3-phase lifecycle:
        // Phase 1: Ranap always starts at 'in-progress', Ralan at 'arrived'
        // Phase 2: In-progress when examination exists (pemeriksaan_ralan/ranap)
        // Phase 3: Finished when discharge note exists (nota_jalan/nota_inap)
        $isRanap = strtolower($row['status_lanjut'] ?? '') === 'ranap';
        $hasDischarge = !empty($row['tgl_keluar']);

        if ($hasDischarge) {
            $status = 'finished';
        } elseif ($isRanap) {
            $status = 'in-progress';
        } else {
            $status = 'arrived';
        }

        $payload = \SatuSehatPayloadBuilder::encounter(
            (string) Config::get('satusehat.org_id', ''), $row, $ihs['pasien'], $ihs['dokter'], $status, [], $row['id_encounter'] ?? ''
        );
        if ($payload !== null) {
            $payload = self::withPersistKeys($payload, 'satu_sehat_encounter', 'id_encounter', $row, ['no_rawat']);
        }
        return $payload;
    }

    // Condition (MULTI-ROW: ALL diagnoses for this visit) + chief complaints
    // (keluhan utama, official rajal pattern) — merged under one type.

    private static function buildConditionMulti(\PDO $db, array $patient): array
    {
        return array_merge(
            self::buildDiagnosisMulti($db, $patient),
            self::buildChiefComplaintMulti($db, $patient)
        );
    }

    private static function buildDiagnosisMulti(\PDO $db, array $patient): array
    {
        $ihs = self::getIhsIds($patient);
        $stmt = $db->prepare("
            SELECT dp.*, pn.nm_penyakit, sse.id_encounter, pg.nama AS nm_dokter, IFNULL(ssc.id_condition, '') AS id_condition
            FROM diagnosa_pasien dp
            LEFT JOIN penyakit pn ON pn.kd_penyakit = dp.kd_penyakit
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = dp.no_rawat
            LEFT JOIN reg_periksa rp ON rp.no_rawat = dp.no_rawat
            LEFT JOIN pegawai pg ON pg.nik = rp.kd_dokter
            LEFT JOIN satu_sehat_condition ssc ON ssc.no_rawat = dp.no_rawat AND ssc.kd_penyakit = dp.kd_penyakit AND ssc.status = dp.status
            WHERE dp.no_rawat = ?
              AND (ssc.id_condition IS NULL OR ssc.id_condition IN ('', '-'))
        ");
        $stmt->execute([$patient['no_rawat']]);
        $rows = $stmt->fetchAll();
        if (empty($rows)) return [];

        $payloads = [];
        foreach ($rows as $row) {
            $row['tgl_registrasi'] = $patient['tgl_registrasi'];
            $row['jam_reg'] = $patient['jam_reg'];
            $row['nm_pasien'] = $patient['nm_pasien'];
            $row['no_ktp'] = $patient['no_ktp'];
            $p = \SatuSehatPayloadBuilder::condition($row, $ihs['pasien'], $row['id_condition'] ?? '', $ihs['dokter'] ?: null, $row['nm_dokter'] ?? '');
            if ($p !== null) {
                // Onset (T22): the diagnosis is dated by the visit.
                $p['onsetDateTime'] = \SatuSehatPayloadBuilder::sanitizeDateTime(
                    $row['tgl_registrasi'] ?? null, $row['jam_reg'] ?? null, $row
                );
                // Attach composite persist keys for SendController
                $p['_panel_persist_keys'] = [
                    'table' => 'satu_sehat_condition',
                    'id_col' => 'id_condition',
                    'keys' => ['no_rawat' => $patient['no_rawat'], 'kd_penyakit' => $row['kd_penyakit'], 'status' => $row['status'] ?? 'Ralan'],
                ];
                $payloads[] = $p;
            }
        }
        return $payloads;
    }

    // Chief complaint (keluhan utama) — official rajal pattern: category
    // terminology.kemkes.go.id chief-complaint, free-text keluhan as the
    // code text (no SNOMED dictionary), onset = exam time. Persisted in
    // satu_sehat_condition with kd_penyakit='CHIEF-COMPLAINT' so re-sends
    // do not duplicate.

    private static function buildChiefComplaintMulti(\PDO $db, array $patient): array
    {
        $ihs = self::getIhsIds($patient);
        $noRawat = $patient['no_rawat'];
        $payloads = [];

        foreach (['Ralan' => 'pemeriksaan_ralan', 'Ranap' => 'pemeriksaan_ranap'] as $status => $table) {
            $stmt = $db->prepare("
                SELECT pr.no_rawat, pr.keluhan, pr.tgl_perawatan, pr.jam_rawat,
                       sse.id_encounter, pg.nama AS nm_dokter,
                       IFNULL(ssc.id_condition, '') AS id_condition
                FROM {$table} pr
                LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = pr.no_rawat
                LEFT JOIN pegawai pg ON pg.nik = pr.nip
                LEFT JOIN satu_sehat_condition ssc
                    ON ssc.no_rawat = pr.no_rawat AND ssc.kd_penyakit = 'CHIEF-COMPLAINT' AND ssc.status = ?
                WHERE pr.no_rawat = ?
                  AND pr.keluhan IS NOT NULL AND pr.keluhan != ''
                  AND (ssc.id_condition IS NULL OR ssc.id_condition IN ('', '-'))
                ORDER BY pr.tgl_perawatan, pr.jam_rawat
            ");
            $stmt->execute([$status, $noRawat]);
            foreach ($stmt->fetchAll() as $row) {
                $p = [
                    'resourceType' => 'Condition',
                    'clinicalStatus' => [
                        'coding' => [[
                            'system' => 'http://terminology.hl7.org/CodeSystem/condition-clinical',
                            'code' => 'active',
                            'display' => 'Active',
                        ]],
                    ],
                    'category' => [[
                        'coding' => [[
                            'system' => 'http://terminology.kemkes.go.id',
                            'code' => 'chief-complaint',
                            'display' => 'Chief Complaint',
                        ]],
                    ]],
                    'code' => [
                        'text' => trim((string) $row['keluhan']),
                    ],
                    'subject' => [
                        'reference' => 'Patient/' . $ihs['pasien'],
                        'display' => $patient['nm_pasien'],
                    ],
                    'encounter' => [
                        'reference' => 'Encounter/' . ($row['id_encounter'] ?? ''),
                    ],
                    'onsetDateTime' => \SatuSehatPayloadBuilder::sanitizeDateTime(
                        $row['tgl_perawatan'] ?? null, $row['jam_rawat'] ?? null, $row
                    ),
                    'recordedDate' => \SatuSehatPayloadBuilder::sanitizeDateTime(
                        $row['tgl_perawatan'] ?? null, $row['jam_rawat'] ?? null, $row, [], true
                    ),
                    'recorder' => [
                        'reference' => 'Practitioner/' . $ihs['dokter'],
                        'display' => (string) ($row['nm_dokter'] ?? ''),
                    ],
                    'note' => [[
                        'text' => trim((string) $row['keluhan']),
                    ]],
                ];
                if (!empty($row['id_condition'])) {
                    $p['id'] = $row['id_condition'];
                }
                $p['_panel_persist_keys'] = [
                    'table' => 'satu_sehat_condition',
                    'id_col' => 'id_condition',
                    'keys' => [
                        'no_rawat' => $noRawat,
                        'kd_penyakit' => 'CHIEF-COMPLAINT',
                        'status' => $status,
                    ],
                ];
                $payloads[] = $p;
            }
        }
        return $payloads;
    }

    // Procedure (MULTI-ROW: ALL procedures for this visit)

    private static function buildProcedureMulti(\PDO $db, array $patient): array
    {
        $ihs = self::getIhsIds($patient);
        $stmt = $db->prepare("
            SELECT
                rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis,
                pj.nm_pasien, pj.no_ktp, rp.stts, rp.status_lanjut,
                CONCAT(rp.tgl_registrasi, 'T', rp.jam_reg, '+07:00') AS waktu_registrasi,
                sse.id_encounter, pp.kode, py.deskripsi_panjang, pp.status,
                pg.nama AS nama_dokter,
                IFNULL(ssp.id_procedure, '') AS id_procedure
            FROM reg_periksa rp
            JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            JOIN prosedur_pasien pp ON pp.no_rawat = rp.no_rawat
            JOIN icd9 py ON py.kode = pp.kode
            LEFT JOIN pegawai pg ON pg.nik = rp.kd_dokter
            LEFT JOIN satu_sehat_procedure ssp ON ssp.no_rawat = pp.no_rawat AND ssp.kode = pp.kode
            WHERE rp.no_rawat = ?
              AND (ssp.id_procedure IS NULL OR ssp.id_procedure IN ('', '-'))
        ");
        $stmt->execute([$patient['no_rawat']]);
        $rows = $stmt->fetchAll();
        if (empty($rows)) return [];

        $payloads = [];
        foreach ($rows as $row) {
            $row['tgl_registrasi'] = $patient['tgl_registrasi'];
            $row['jam_reg'] = $patient['jam_reg'];
            $row['nm_pasien'] = $patient['nm_pasien'];
            $row['no_ktp'] = $patient['no_ktp'];
            $p = \SatuSehatPayloadBuilder::procedure($row, $ihs['pasien'], $row['id_procedure'] ?? '', $ihs['dokter'] ?: null, $row['nama_dokter'] ?? '');
            if ($p !== null) {
                $p['_panel_persist_keys'] = [
                    'table' => 'satu_sehat_procedure',
                    'id_col' => 'id_procedure',
                    'keys' => ['no_rawat' => $patient['no_rawat'], 'kode' => $row['kode'], 'status' => $row['status'] ?? 'Ralan'],
                ];
                $payloads[] = $p;
            }
        }
        return $payloads;
    }

    // Medication (MULTI-ROW: ALL drugs for this visit)

    private static function buildMedicationMulti(\PDO $db, string $noRawat, string $orgId): array
    {
        $stmt = $db->prepare("
            SELECT
                ssmo.obat_code, ssmo.obat_system, db.status,
                ssmo.kode_brng, ssmo.obat_display, ssmo.form_code,
                ssmo.form_system, ssmo.form_display,
                IFNULL(ssm.id_medication, '') AS id_medication
            FROM resep_obat ro
            JOIN resep_dokter rd ON rd.no_resep = ro.no_resep
            INNER JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = rd.kode_brng
            INNER JOIN databarang db ON db.kode_brng = ssmo.kode_brng
            LEFT JOIN satu_sehat_medication ssm ON ssm.kode_brng = ssmo.kode_brng
            WHERE ro.no_rawat = ?
              AND (ssm.id_medication IS NULL OR ssm.id_medication IN ('', '-'))
            GROUP BY ssmo.kode_brng
        ");
        $stmt->execute([$noRawat]);
        $rows = $stmt->fetchAll();
        $payloads = [];
        foreach ($rows as $row) {
            $med = [
                'kode_brng' => $row['kode_brng'] ?? '', 'obat_code' => $row['obat_code'] ?? '',
                'obat_system' => $row['obat_system'] ?? '', 'obat_display' => $row['obat_display'] ?? '',
                'form_code' => $row['form_code'] ?? '', 'form_system' => $row['form_system'] ?? '',
                'form_display' => $row['form_display'] ?? '', 'status' => $row['status'] ?? 'active',
            ];
            $p = \SatuSehatPayloadBuilder::medication($orgId, $med, $row['id_medication'] ?: null);
            if ($p !== null) {
                $p = self::withPersistKeys($p, 'satu_sehat_medication', 'id_medication', $row, ['kode_brng']);
                $payloads[] = $p;
            }
        }
        return $payloads;
    }

    // MedicationDispense — dpo-based (CLI parity: columns + authorizing request)

    private static function buildDispenseFromDpo(\PDO $db, array $patient, string $orgId, array $ihs): array
    {
        $stmt = $db->prepare("
            SELECT
                rp.no_rawat, rp.no_rkm_medis, rp.tgl_registrasi, rp.jam_reg,
                pj.nm_pasien, pj.no_ktp, rp.status_lanjut,
                peg.nama, sse.id_encounter,
                ssmo.obat_code, ssmo.obat_system, ssmo.obat_display,
                ssmo.form_code, ssmo.form_system, ssmo.form_display,
                ssmo.route_code, ssmo.route_system, ssmo.route_display,
                ssmo.denominator_code, ssmo.denominator_system,
                ssm.id_medication,
                ro.tgl_peresepan, ro.jam_peresepan,
                dpo.kode_brng, dpo.jml, dpo.no_batch, dpo.no_faktur,
                dpo.tgl_perawatan, dpo.jam, dpo.status AS status_pemberian,
                ap.aturan, ro.no_resep,
                IFNULL(ssml.id_lokasi_satusehat, '') AS id_lokasi_satusehat,
                IFNULL(b.nm_bangsal, '') AS nm_bangsal,
                IFNULL(ssmr.id_medicationrequest, '') AS id_medicationrequest,
                IFNULL(ssmd.id_medicationdispanse, '') AS id_medicationdispense
            FROM reg_periksa rp
            JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            INNER JOIN resep_obat ro ON ro.no_rawat = rp.no_rawat
            LEFT JOIN pegawai peg ON peg.nik = ro.kd_dokter
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            INNER JOIN detail_pemberian_obat dpo ON dpo.no_rawat = ro.no_rawat
                AND dpo.tgl_perawatan = ro.tgl_perawatan AND dpo.jam = ro.jam
            LEFT JOIN aturan_pakai ap ON ap.no_rawat = dpo.no_rawat AND ap.tgl_perawatan = dpo.tgl_perawatan AND ap.jam = dpo.jam AND ap.kode_brng = dpo.kode_brng
            INNER JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = dpo.kode_brng
            LEFT JOIN satu_sehat_medication ssm ON ssm.kode_brng = ssmo.kode_brng
            LEFT JOIN bangsal bm ON bm.kd_bangsal = dpo.kd_bangsal
            LEFT JOIN satu_sehat_mapping_lokasi_depo_farmasi ssml ON ssml.kd_bangsal = bm.kd_bangsal
            LEFT JOIN satu_sehat_medicationrequest ssmr ON ssmr.no_resep = ro.no_resep AND ssmr.kode_brng = dpo.kode_brng
            LEFT JOIN satu_sehat_medicationdispense ssmd ON ssmd.no_rawat = dpo.no_rawat
                AND ssmd.tgl_perawatan = dpo.tgl_perawatan AND ssmd.jam = dpo.jam
                AND ssmd.kode_brng = dpo.kode_brng AND ssmd.no_batch = dpo.no_batch AND ssmd.no_faktur = dpo.no_faktur
            WHERE rp.no_rawat = ?
              AND dpo.status IN ('Ralan', 'Ranap')
        ");
        $stmt->execute([$patient['no_rawat']]);
        $rows = $stmt->fetchAll();

        $payloads = [];
        foreach ($rows as $row) {
            if (trim((string) ($row['id_medicationdispense'] ?? '')) !== '') {
                continue; // already synced (real id present)
            }
            // CLI parity: no authorizing MedicationRequest → skip with warning
            // (rule 10393/10394 rejections otherwise).
            if (empty(trim((string) ($row['id_medicationrequest'] ?? '')))) {
                PayloadAdapterWarnings::add("MedicationDispense ({$row['no_resep']}/{$row['kode_brng']}): MedicationRequest belum terkirim — dilewati");
                continue;
            }
            $row['nm_pasien'] = $patient['nm_pasien'];
            $row['no_ktp'] = $patient['no_ktp'];
            $payload = \SatuSehatPayloadBuilder::medicationDispense(
                $orgId, $row, $ihs['pasien'], $ihs['dokter'], $row['id_medicationrequest'] ?? '', $row['id_medicationdispense'] ?? null
            );
            if ($payload !== null) {
                $payload = self::withPersistKeys(
                    $payload, 'satu_sehat_medicationdispense', 'id_medicationdispanse', $row,
                    ['no_rawat', 'tgl_perawatan', 'jam', 'kode_brng', 'no_batch', 'no_faktur']
                );
                $payloads[] = $payload;
            }
        }
        return $payloads;
    }

    // MedicationRequest / Dispense / Statement (MULTI-ROW)

    private static function buildMedicationFamilyMulti(\PDO $db, array $patient, string $orgId, string $kind): array
    {
        $ihs = self::getIhsIds($patient);
        $payloads = [];

        // MedicationDispense is sourced from detail_pemberian_obat (the CLI's
        // dpo-based flow) — NOT from the prescription row set, which lacks
        // tgl_perawatan/jam/no_batch/no_faktur/status_pemberian/location.
        if ($kind === 'dispense') {
            return self::buildDispenseFromDpo($db, $patient, $orgId, $ihs);
        }

        // Regular prescriptions
        $stmt = $db->prepare("
            SELECT rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis,
                pj.nm_pasien, pj.no_ktp, rp.status_lanjut, ro.kd_dokter,
                sse.id_encounter,
                ssmo.obat_code, ssmo.obat_system, ssmo.obat_display,
                ssmo.form_code, ssmo.form_system, ssmo.form_display,
                ssmo.route_code, ssmo.route_system, ssmo.route_display,
                ssmo.denominator_code, ssmo.denominator_system,
                rd.kode_brng, ro.tgl_peresepan, ro.jam_peresepan,
                rd.jml, rd.aturan_pakai, rd.no_resep, '' AS no_racik,
                ssm.id_medication,
                IFNULL(ssmr.id_medicationrequest, '') AS id_medicationrequest,
                '' AS id_medicationdispense,
                IFNULL(ssms.id_medicationstatement, '') AS id_medicationstatement,
                peg.nama AS nama, '0' AS is_racikan
            FROM resep_obat ro
            JOIN reg_periksa rp ON rp.no_rawat = ro.no_rawat
            JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            INNER JOIN resep_dokter rd ON rd.no_resep = ro.no_resep
            LEFT JOIN pegawai peg ON peg.nik = ro.kd_dokter
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = ro.no_rawat
            INNER JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = rd.kode_brng
            LEFT JOIN satu_sehat_medication ssm ON ssm.kode_brng = rd.kode_brng
            LEFT JOIN satu_sehat_medicationrequest ssmr ON ssmr.no_resep = rd.no_resep AND ssmr.kode_brng = rd.kode_brng
            LEFT JOIN satu_sehat_medicationstatement ssms ON ssms.no_resep = rd.no_resep AND ssms.kode_brng = rd.kode_brng
            WHERE ro.no_rawat = ?
        ");
        $stmt->execute([$patient['no_rawat']]);
        $regularRows = $stmt->fetchAll();

        // Racikan prescriptions
        $stmt2 = $db->prepare("
            SELECT rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis,
                pj.nm_pasien, pj.no_ktp, rp.status_lanjut, ro.kd_dokter,
                sse.id_encounter,
                ssmo.obat_code, ssmo.obat_system, ssmo.obat_display,
                ssmo.form_code, ssmo.form_system, ssmo.form_display,
                ssmo.route_code, ssmo.route_system, ssmo.route_display,
                ssmo.denominator_code, ssmo.denominator_system,
                rdrd.kode_brng, ro.tgl_peresepan, ro.jam_peresepan,
                rdrd.jml, rdr.aturan_pakai, rdrd.no_resep, rdrd.no_racik,
                ssm.id_medication,
                IFNULL(ssmrr.id_medicationrequest, '') AS id_medicationrequest,
                '' AS id_medicationdispense,
                IFNULL(ssmsr.id_medicationstatement, '') AS id_medicationstatement,
                peg.nama AS nama, '1' AS is_racikan
            FROM resep_obat ro
            JOIN reg_periksa rp ON rp.no_rawat = ro.no_rawat
            JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            INNER JOIN resep_dokter_racikan rdr ON rdr.no_resep = ro.no_resep
            INNER JOIN resep_dokter_racikan_detail rdrd ON rdrd.no_resep = rdr.no_resep AND rdrd.no_racik = rdr.no_racik
            LEFT JOIN pegawai peg ON peg.nik = ro.kd_dokter
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = ro.no_rawat
            INNER JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = rdrd.kode_brng
            LEFT JOIN satu_sehat_medication ssm ON ssm.kode_brng = rdrd.kode_brng
            LEFT JOIN satu_sehat_medicationrequest_racikan ssmrr ON ssmrr.no_resep = rdrd.no_resep AND ssmrr.kode_brng = rdrd.kode_brng AND ssmrr.no_racik = rdrd.no_racik
            LEFT JOIN satu_sehat_medicationstatement_racikan ssmsr ON ssmsr.no_resep = rdrd.no_resep AND ssmsr.kode_brng = rdrd.kode_brng AND ssmsr.no_racik = rdrd.no_racik
            WHERE ro.no_rawat = ?
        ");
        $stmt2->execute([$patient['no_rawat']]);
        $racikanRows = $stmt2->fetchAll();

        // Per-kind sent filtering: a row is only excluded when ITS OWN mapping
        // table already holds a real id (request vs statement are tracked in
        // different tables, so the shared row set must not be filtered in SQL).
        $isAlreadySent = static function (array $row, string $kind): bool {
            $idCol = match ($kind) {
                'request'   => 'id_medicationrequest',
                'statement' => 'id_medicationstatement',
                'dispense'  => 'id_medicationdispense',
                default     => null,
            };
            if ($idCol === null) {
                return false;
            }
            $id = trim((string) ($row[$idCol] ?? ''));
            return $id !== '' && $id !== '-';
        };

        // Official prescription-item identifiers: {no_resep}-{sequence}
        // (T21) — sequence counts items per prescription across regular and
        // racikan rows in the order they are emitted.
        $itemSeq = [];

        foreach (array_merge($regularRows, $racikanRows) as $row) {
            if ($isAlreadySent($row, $kind)) {
                continue;
            }
            $resepKey = (string) ($row['no_resep'] ?? '');
            $itemSeq[$resepKey] = ($itemSeq[$resepKey] ?? 0) + 1;
            $row['prescription_item_seq'] = $resepKey . '-' . $itemSeq[$resepKey];
            $isRacikan = ((string) ($row['is_racikan'] ?? '0')) === '1';
            $row['is_racikan'] = $isRacikan;
            $idMedReq = ($kind !== 'request') ? (string) ($row['id_medicationrequest'] ?? '') : '';

            $payload = match ($kind) {
                'request' => \SatuSehatPayloadBuilder::medicationRequest($orgId, $row, $ihs['pasien'], $ihs['dokter'], $row['id_medicationrequest'] ?? null),
                'dispense' => \SatuSehatPayloadBuilder::medicationDispense($orgId, $row, $ihs['pasien'], $ihs['dokter'], $idMedReq, $row['id_medicationdispense'] ?? null),
                'statement' => \SatuSehatPayloadBuilder::medicationStatement($orgId, $row, $ihs['pasien'], $row['id_medicationstatement'] ?? null),
                default => null,
            };
            if ($payload !== null) {
                if (in_array($kind, ['request', 'statement'], true)) {
                    $payload['_panel_is_racikan'] = $isRacikan;
                    $payload['_panel_no_racik'] = (string) ($row['no_racik'] ?? '');
                }

                // Persist routing: composite keys match the CLI mapping-table
                // schemas. Dispense keys include tgl_perawatan/jam (selected
                // by T12) so refills stay unique; racikan tables key by
                // no_resep+kode_brng+no_racik.
                $table = match ($kind) {
                    'request'  => $isRacikan ? 'satu_sehat_medicationrequest_racikan' : 'satu_sehat_medicationrequest',
                    'dispense' => 'satu_sehat_medicationdispense',
                    'statement' => $isRacikan ? 'satu_sehat_medicationstatement_racikan' : 'satu_sehat_medicationstatement',
                    default => '',
                };
                $idCol = match ($kind) {
                    'request'   => 'id_medicationrequest',
                    'dispense'  => 'id_medicationdispanse', // CLI schema typo, kept for parity
                    'statement' => 'id_medicationstatement',
                    default => '',
                };
                $wanted = $kind === 'dispense'
                    ? ['no_rawat', 'tgl_perawatan', 'jam', 'kode_brng', 'no_batch', 'no_faktur']
                    : ($isRacikan ? ['no_resep', 'kode_brng', 'no_racik'] : ['no_resep', 'kode_brng']);
                $payload = self::withPersistKeys($payload, $table, $idCol, $row, $wanted);

                $payloads[] = $payload;
            }
        }
        return $payloads;
    }

    // CarePlan (single - uses first pemeriksaan with rtl)

    private static function buildCarePlan(\PDO $db, array $patient, string $orgId): ?array
    {
        $ihs = self::getIhsIds($patient);
        $statusRawat = 'Ralan';
        $stmt = $db->prepare("
            SELECT pr.no_rawat, pr.rtl, pr.tgl_perawatan, pr.jam_rawat,
                   rp.tgl_registrasi, rp.jam_reg, rp.status_lanjut, rp.kd_poli,
                   pg.nama AS nama, sse.id_encounter, IFNULL(ssc.id_careplan, '') AS id_careplan
            FROM pemeriksaan_ralan pr
            JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat
            LEFT JOIN pegawai pg ON pg.nik = pr.nip
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = pr.no_rawat
            LEFT JOIN satu_sehat_careplan ssc ON ssc.no_rawat = pr.no_rawat AND ssc.tgl_perawatan = pr.tgl_perawatan AND ssc.jam_rawat = pr.jam_rawat AND ssc.status = 'Ralan'
            WHERE pr.no_rawat = ? AND pr.rtl IS NOT NULL AND pr.rtl != ''
            LIMIT 1
        ");
        $stmt->execute([$patient['no_rawat']]);
        $row = $stmt->fetch();
        if (!$row) {
            $statusRawat = 'Ranap';
            $stmt = $db->prepare("
                SELECT pr.no_rawat, pr.rtl, pr.tgl_perawatan, pr.jam_rawat,
                       rp.tgl_registrasi, rp.jam_reg, rp.status_lanjut, rp.kd_poli,
                       pg.nama AS nama, sse.id_encounter, IFNULL(ssc.id_careplan, '') AS id_careplan
                FROM pemeriksaan_ranap pr
                JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat
                LEFT JOIN pegawai pg ON pg.nik = pr.nip
                LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = pr.no_rawat
                LEFT JOIN satu_sehat_careplan ssc ON ssc.no_rawat = pr.no_rawat AND ssc.tgl_perawatan = pr.tgl_perawatan AND ssc.jam_rawat = pr.jam_rawat AND ssc.status = 'Ranap'
                WHERE pr.no_rawat = ? AND pr.rtl IS NOT NULL AND pr.rtl != ''
                    AND (ssc.id_careplan IS NULL OR ssc.id_careplan IN ('', '-'))
                LIMIT 1
            ");
            $stmt->execute([$patient['no_rawat']]);
            $row = $stmt->fetch();
        }
        if (!$row) return null;
        $row['nm_pasien'] = $patient['nm_pasien'];
        $row['no_ktp'] = $patient['no_ktp'];
        $p = \SatuSehatPayloadBuilder::carePlan($orgId, $row, $ihs['pasien'], $ihs['dokter'], $row['id_careplan'] ?? '');
        if ($p !== null) {
            $p['_panel_persist_keys'] = [
                'table' => 'satu_sehat_careplan',
                'id_col' => 'id_careplan',
                'keys' => ['no_rawat' => $patient['no_rawat'], 'tgl_perawatan' => $row['tgl_perawatan'], 'jam_rawat' => $row['jam_rawat'], 'status' => $statusRawat],
            ];
        }
        return $p;
    }

    // AllergyIntolerance (single per visit)

    private static function buildAllergy(\PDO $db, array $patient): ?array
    {
        $ihs = self::getIhsIds($patient);
        $stmt = $db->prepare("
            SELECT pr.no_rawat, pr.alergi, pr.tgl_perawatan, pr.jam_rawat,
                   pg.nama, pg.no_ktp AS ktpdokter, sse.id_encounter,
                   IFNULL(ssai.id_allergy_intolerance, '') AS id_allergy
            FROM pemeriksaan_ralan pr
            LEFT JOIN pegawai pg ON pg.nik = pr.nip
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = pr.no_rawat
            LEFT JOIN satu_sehat_allergy_intolerance ssai ON ssai.no_rawat = pr.no_rawat AND ssai.tgl_perawatan = pr.tgl_perawatan AND ssai.jam_rawat = pr.jam_rawat
            WHERE pr.no_rawat = ? AND pr.alergi IS NOT NULL AND pr.alergi != '' AND pr.alergi != '-'
            LIMIT 1
        ");
        $stmt->execute([$patient['no_rawat']]);
        $row = $stmt->fetch();
        if (!$row) {
            $stmt = $db->prepare("
                SELECT pr.no_rawat, pr.alergi, pr.tgl_perawatan, pr.jam_rawat,
                       pg.nama, pg.no_ktp AS ktpdokter, sse.id_encounter,
                       IFNULL(ssai.id_allergy_intolerance, '') AS id_allergy
                FROM pemeriksaan_ranap pr
                LEFT JOIN pegawai pg ON pg.nik = pr.nip
                LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = pr.no_rawat
                LEFT JOIN satu_sehat_allergy_intolerance ssai ON ssai.no_rawat = pr.no_rawat AND ssai.tgl_perawatan = pr.tgl_perawatan AND ssai.jam_rawat = pr.jam_rawat
                WHERE pr.no_rawat = ? AND pr.alergi IS NOT NULL AND pr.alergi != '' AND pr.alergi != '-'
                    AND (ssai.id_allergy_intolerance IS NULL OR ssai.id_allergy_intolerance IN ('', '-'))
                LIMIT 1
            ");
            $stmt->execute([$patient['no_rawat']]);
            $row = $stmt->fetch();
        }
        if (!$row) return null;
        $row['tgl_registrasi'] = $patient['tgl_registrasi'];
        $row['jam_reg'] = $patient['jam_reg'];
        $row['nm_pasien'] = $patient['nm_pasien'];
        $row['no_ktp'] = $patient['no_ktp'];
        $dictionary = new \SatuSehatAllergyDictionary(
            defined('BASE_DIR') ? BASE_DIR . '/cache/alergisatusehat.iyem' : __DIR__ . '/../../cache/alergisatusehat.iyem',
            new \Logger(
                defined('BASE_DIR') ? BASE_DIR . '/storage' : __DIR__ . '/../../storage',
                'panel_allergy'
            )
        );
        $allergyData = $dictionary->lookup($row['alergi'] ?? '');
        $p = \SatuSehatPayloadBuilder::allergyIntolerance($row, $allergyData, $ihs['pasien'], $ihs['dokter'], (string) Config::get('satusehat.org_id', ''), $row['id_allergy'] ?? '');
        if ($p !== null) {
            $p['_panel_persist_keys'] = [
                'table' => 'satu_sehat_allergy_intolerance',
                'id_col' => 'id_allergy_intolerance',
                'keys' => ['no_rawat' => $patient['no_rawat'], 'tgl_perawatan' => $row['tgl_perawatan'], 'jam_rawat' => $row['jam_rawat']],
            ];
        }
        return $p;
    }

    // Immunization (MULTI-ROW)

    private static function buildImmunizationMulti(\PDO $db, array $patient): array
    {
        $ihs = self::getIhsIds($patient);
        $stmt = $db->prepare("
            SELECT rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis,
                pj.nm_pasien, pj.no_ktp, rp.stts, rp.status_lanjut, sse.id_encounter,
                smv.vaksin_code, smv.vaksin_system, smv.kode_brng, smv.vaksin_display,
                smv.route_code, smv.route_system, smv.route_display,
                smv.dose_quantity_code, smv.dose_quantity_system, smv.dose_quantity_unit,
                dpo.no_batch, dpo.tgl_perawatan, dpo.jam, dpo.jml,
                IFNULL(ap.aturan, '') AS aturan, sml.id_lokasi_satusehat, pol.nm_poli,
                pg.nama, dpo.no_faktur, IFNULL(db.tgl_kadaluarsa, '') AS tgl_kadaluarsa,
                IFNULL(ssi.id_immunization, '') AS id_immunization
            FROM detail_pemberian_obat dpo
            JOIN reg_periksa rp ON rp.no_rawat = dpo.no_rawat
            JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            LEFT JOIN satu_sehat_mapping_vaksin smv ON smv.kode_brng = dpo.kode_brng
            LEFT JOIN aturan_pakai ap ON ap.tgl_perawatan = dpo.tgl_perawatan AND ap.jam = dpo.jam AND ap.no_rawat = dpo.no_rawat AND ap.kode_brng = dpo.kode_brng
            LEFT JOIN satu_sehat_mapping_lokasi_ralan sml ON sml.kd_poli = rp.kd_poli
            LEFT JOIN poliklinik pol ON pol.kd_poli = rp.kd_poli
            LEFT JOIN pegawai pg ON pg.nik = rp.kd_dokter
            LEFT JOIN data_batch db ON db.no_batch = dpo.no_batch AND db.kode_brng = dpo.kode_brng AND db.no_faktur = dpo.no_faktur
            LEFT JOIN satu_sehat_immunization ssi ON ssi.no_rawat = dpo.no_rawat AND ssi.tgl_perawatan = dpo.tgl_perawatan AND ssi.jam = dpo.jam AND ssi.kode_brng = dpo.kode_brng AND ssi.no_batch = dpo.no_batch AND ssi.no_faktur = dpo.no_faktur
            WHERE dpo.no_rawat = ? AND dpo.no_batch <> ''
              AND (ssi.id_immunization IS NULL OR ssi.id_immunization IN ('', '-'))
        ");
        $stmt->execute([$patient['no_rawat']]);
        $rows = $stmt->fetchAll();

        $payloads = [];
        foreach ($rows as $row) {
            $row['status_rawat'] = 'Ralan';
            $p = \SatuSehatPayloadBuilder::immunization($row, $ihs['pasien'], $ihs['dokter'], $row['id_immunization'] ?? '');
            if ($p !== null) {
                $p = self::withPersistKeys($p, 'satu_sehat_immunization', 'id_immunization', $row, ['no_rawat', 'tgl_perawatan', 'jam', 'kode_brng', 'no_batch', 'no_faktur']);
                $payloads[] = $p;
            }
        }
        return $payloads;
    }

    // ClinicalImpression (MULTI - ralan + ranap, matching CLI Database.php L2716-2800)

    private static function buildClinicalImpressionMulti(\PDO $db, array $patient): array
    {
        $ihs = self::getIhsIds($patient);
        $noRawat = $patient['no_rawat'];
        $payloads = [];

        // Ralan ClinicalImpressions
        try {
            $stmtR = $db->prepare("
                SELECT
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis,
                    p.nm_pasien, p.no_ktp AS nik_pasien, rp.stts,
                    'Ralan' as status_lanjut,
                    sse.id_encounter,
                    CONCAT(pem.keluhan, ', ', pem.pemeriksaan) as keluhan_pemeriksaan,
                    pem.penilaian, peg.nama AS nm_praktisi, peg.no_ktp AS nik_praktisi,
                    pem.tgl_perawatan, pem.jam_rawat, ssc.kd_penyakit, py.nm_penyakit,
                    ssc.id_condition, IFNULL(ssci.id_clinicalimpression, '') AS id_clinicalimpression
                FROM reg_periksa rp
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                LEFT JOIN satu_sehat_condition ssc ON ssc.no_rawat = rp.no_rawat AND ssc.status = 'Ralan'
                LEFT JOIN penyakit py ON py.kd_penyakit = ssc.kd_penyakit
                INNER JOIN pemeriksaan_ralan pem ON pem.no_rawat = rp.no_rawat
                INNER JOIN pegawai peg ON pem.nip = peg.nik
                LEFT JOIN satu_sehat_clinicalimpression ssci ON ssci.no_rawat = pem.no_rawat
                    AND ssci.tgl_perawatan = pem.tgl_perawatan
                    AND ssci.jam_rawat = pem.jam_rawat
                    AND ssci.status = 'Ralan'
                WHERE pem.penilaian <> '' AND rp.no_rawat = ?
                LIMIT 1
            ");
            $stmtR->execute([$noRawat]);
            $rowR = $stmtR->fetch();
            if ($rowR) {
                $p = \SatuSehatPayloadBuilder::clinicalImpression($rowR, $ihs['pasien'], $ihs['dokter'], $rowR['id_clinicalimpression'] ?? '');
                if ($p !== null) {
                    $p['_panel_persist_keys'] = [
                        'table' => 'satu_sehat_clinicalimpression',
                        'id_col' => 'id_clinicalimpression',
                        'keys' => ['no_rawat' => $noRawat, 'tgl_perawatan' => $rowR['tgl_perawatan'], 'jam_rawat' => $rowR['jam_rawat'], 'status' => 'Ralan'],
                    ];
                    $payloads[] = $p;
                }
            }
        } catch (\Throwable $e) { /* pemeriksaan_ralan may not have data */ }

        // Ranap ClinicalImpressions (only if no ralan found)
        if (empty($payloads)) {
            try {
                $stmtN = $db->prepare("
                    SELECT
                        rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis,
                        p.nm_pasien, p.no_ktp AS nik_pasien, rp.stts,
                        'Ranap' as status_lanjut,
                        sse.id_encounter,
                        CONCAT(pem.keluhan, ', ', pem.pemeriksaan) as keluhan_pemeriksaan,
                        pem.penilaian, peg.nama AS nm_praktisi, peg.no_ktp AS nik_praktisi,
                        pem.tgl_perawatan, pem.jam_rawat, ssc.kd_penyakit, py.nm_penyakit,
                        ssc.id_condition, IFNULL(ssci.id_clinicalimpression, '') AS id_clinicalimpression
                    FROM reg_periksa rp
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_condition ssc ON ssc.no_rawat = rp.no_rawat AND ssc.status = 'Ranap'
                    LEFT JOIN penyakit py ON py.kd_penyakit = ssc.kd_penyakit
                    INNER JOIN pemeriksaan_ranap pem ON pem.no_rawat = rp.no_rawat
                    INNER JOIN pegawai peg ON pem.nip = peg.nik
                    LEFT JOIN satu_sehat_clinicalimpression ssci ON ssci.no_rawat = pem.no_rawat
                        AND ssci.tgl_perawatan = pem.tgl_perawatan
                        AND ssci.jam_rawat = pem.jam_rawat
                        AND ssci.status = 'Ranap'
                    WHERE pem.penilaian <> '' AND rp.no_rawat = ?
                    LIMIT 1
                ");
                $stmtN->execute([$noRawat]);
                $rowN = $stmtN->fetch();
                if ($rowN) {
                    $p = \SatuSehatPayloadBuilder::clinicalImpression($rowN, $ihs['pasien'], $ihs['dokter'], $rowN['id_clinicalimpression'] ?? '');
                    if ($p !== null) {
                        $p['_panel_persist_keys'] = [
                            'table' => 'satu_sehat_clinicalimpression',
                            'id_col' => 'id_clinicalimpression',
                            'keys' => ['no_rawat' => $noRawat, 'tgl_perawatan' => $rowN['tgl_perawatan'], 'jam_rawat' => $rowN['jam_rawat'], 'status' => 'Ranap'],
                        ];
                        $payloads[] = $p;
                    }
                }
            } catch (\Throwable $e) { /* pemeriksaan_ranap may not have data */ }
        }

        return $payloads;
    }

    // Lab Pipeline (MULTI-ROW: all LabPK + LabMB + Radiologi)

    private static function buildLabPipelineMulti(\PDO $db, array $patient, string $orgId, string $stage): array
    {
        $ihs = self::getIhsIds($patient);
        $noRawat = $patient['no_rawat'];
        $payloads = [];

        // Collect rows from all three variants
        $variants = [
            ['rows' => self::safeLabFetch($db, $noRawat, 'pk', $stage), 'isRad' => false, 'variantName' => 'pk'],
            ['rows' => self::safeLabFetch($db, $noRawat, 'mb', $stage), 'isRad' => false, 'variantName' => 'mb'],
            ['rows' => self::safeLabFetch($db, $noRawat, 'rad', $stage), 'isRad' => true, 'variantName' => 'rad'],
        ];

        foreach ($variants as $v) {
            foreach ($v['rows'] as $row) {
                $row['tgl_registrasi'] = $patient['tgl_registrasi'];
                $row['jam_reg'] = $patient['jam_reg'];
                $row['nm_pasien'] = $patient['nm_pasien'];
                $row['no_ktp'] = $patient['no_ktp'];

                $payload = null;
                $isRad = $v['isRad'];
                $row['_panel_variant'] = $v['variantName'] ?? '';
                switch ($stage) {
                    case 'serviceRequest':
                        $payload = $isRad
                            ? \SatuSehatPayloadBuilder::serviceRequestRadiologi($row, $ihs['pasien'], $ihs['dokter'], $orgId, $row['id_servicerequest'] ?? '')
                            : \SatuSehatPayloadBuilder::serviceRequestLab($row, $ihs['pasien'], $ihs['dokter'], $orgId, $row['id_servicerequest'] ?? '');
                        break;
                    case 'specimen':
                        $payload = $isRad
                            ? \SatuSehatPayloadBuilder::specimenRadiologi($row, $ihs['pasien'], $orgId, $row['id_specimen'] ?? '')
                            : \SatuSehatPayloadBuilder::specimenLab($row, $ihs['pasien'], $orgId, $row['id_specimen'] ?? '');
                        break;
                    case 'observation':
                        // CLI parity: radiology Observation requires the
                        // ImagingStudy id — without it the builder emits a
                        // broken "ImagingStudy/" reference (rule 10428).
                        if ($isRad && empty(trim((string) ($row['id_imaging'] ?? '')))) {
                            PayloadAdapterWarnings::add("Observation radiologi ({$row['noorder']}/{$row['kd_jenis_prw']}): ImagingStudy belum terkirim — dilewati");
                            break;
                        }
                        $payload = $isRad
                            ? \SatuSehatPayloadBuilder::observationRadiologi($row, $ihs['pasien'], $ihs['dokter'], $orgId, $row['id_observation'] ?? '')
                            : \SatuSehatPayloadBuilder::observationLab($row, $ihs['pasien'], $ihs['dokter'], $orgId, $row['id_observation'] ?? '');
                        break;
                    case 'diagnosticReport':
                        if ($isRad && empty(trim((string) ($row['id_imaging'] ?? '')))) {
                            PayloadAdapterWarnings::add("DiagnosticReport radiologi ({$row['noorder']}/{$row['kd_jenis_prw']}): ImagingStudy belum terkirim — dilewati");
                            break;
                        }
                        $payload = \SatuSehatPayloadBuilder::diagnosticReport($row, $ihs['pasien'], $ihs['dokter'], $orgId, $row['id_diagnosticreport'] ?? '');
                        break;
                }
                if ($payload !== null) {
                    $payload = self::attachLabPersistKeys($payload, $isRad, $stage, $row);
                    $payloads[] = $payload;
                }
            }
        }
        return $payloads;
    }

    /**
     * Persist routing for the lab/rad pipeline. Table names follow the CLI's
     * satu_sehat_* mapping tables; keys match each table's real columns.
     */
    private static function attachLabPersistKeys(array $payload, bool $isRad, string $stage, array $row): array
    {
        $radSuffix = $isRad ? '_radiologi' : '';
        $variantSuffix = '';
        if (!$isRad) {
            $variantSuffix = str_ends_with((string) ($row['_panel_variant'] ?? ''), 'mb') ? '_mb' : '';
        }
        $tableById = [
            'serviceRequest'  => ['satu_sehat_servicerequest' . $radSuffix . $variantSuffix, 'id_servicerequest'],
            'specimen'        => ['satu_sehat_specimen' . $radSuffix . $variantSuffix, 'id_specimen'],
            'observation'     => ['satu_sehat_observation' . $radSuffix . $variantSuffix, 'id_observation'],
            'diagnosticReport' => ['satu_sehat_diagnosticreport' . $radSuffix . $variantSuffix, 'id_diagnosticreport'],
        ];
        // NOTE: rad tables are keyed (noorder, kd_jenis_prw); lab tables add
        // id_template. Only keys present on the row are emitted.
        $wanted = $isRad
            ? ['noorder', 'kd_jenis_prw']
            : ['noorder', 'kd_jenis_prw', 'id_template'];

        [$table, $idCol] = $tableById[$stage] ?? ['', ''];
        if ($table === '') {
            return $payload;
        }
        return self::withPersistKeys($payload, $table, $idCol, $row, $wanted);
    }

    private static function safeLabFetch(\PDO $db, string $noRawat, string $variant, string $stage): array
    {
        try {
            return self::fetchLabRows($db, $noRawat, $variant, $stage);
        } catch (\Throwable $e) {
            return [];  // Table may not exist
        }
    }

    private static function fetchLabRows(\PDO $db, string $noRawat, string $variant, string $stage): array
    {
        // Build table names based on variant
        $tables = match ($variant) {
            'pk' => [
                'perm' => 'permintaan_lab', 'detail' => 'permintaan_detail_permintaan_lab',
                'sr' => 'satu_sehat_servicerequest_lab', 'sp' => 'satu_sehat_specimen_lab',
                'obs' => 'satu_sehat_observation_lab', 'dr' => 'satu_sehat_diagnosticreport_lab',
                'map' => 'satu_sehat_mapping_lab',
            ],
            'mb' => [
                'perm' => 'permintaan_labmb', 'detail' => 'permintaan_detail_permintaan_labmb',
                'sr' => 'satu_sehat_servicerequest_lab_mb', 'sp' => 'satu_sehat_specimen_lab_mb',
                'obs' => 'satu_sehat_observation_lab_mb', 'dr' => 'satu_sehat_diagnosticreport_lab_mb',
                'map' => 'satu_sehat_mapping_lab',
            ],
            default => [],
        };

        if ($variant === 'rad') {
            return self::fetchRadRows($db, $noRawat, $stage);
        }
        if (empty($tables)) return [];

        $p = $tables['perm']; $d = $tables['detail']; $sr = $tables['sr'];
        $sp = $tables['sp']; $ob = $tables['obs']; $drT = $tables['dr']; $mp = $tables['map'];

        if ($stage === 'serviceRequest') {
            $stmt = $db->prepare("
                SELECT rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, pj.nm_pasien, pj.no_ktp, rp.status_lanjut,
                    sse.id_encounter, pl.noorder, pl.tgl_permintaan, pl.jam_permintaan, pl.diagnosa_klinis,
                    tl.id_template, tl.Pemeriksaan, sml.code, sml.system, sml.display, peg.nama AS nm_dokter,
                    IFNULL(sssl.id_servicerequest, '') AS id_servicerequest
                FROM reg_periksa rp JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
                LEFT JOIN pegawai peg ON peg.nik = rp.kd_dokter LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                INNER JOIN {$p} pl ON pl.no_rawat = rp.no_rawat
                INNER JOIN {$d} pdpl ON pdpl.noorder = pl.noorder
                INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                LEFT JOIN {$mp} sml ON sml.id_template = tl.id_template
                LEFT JOIN {$sr} sssl ON sssl.noorder = pdpl.noorder
                WHERE rp.no_rawat = ? AND sml.code IS NOT NULL
                  AND (sssl.id_servicerequest IS NULL OR sssl.id_servicerequest IN ('', '-'))
            ");
            $stmt->execute([$noRawat]);
            return $stmt->fetchAll();
        }
        if ($stage === 'specimen') {
            $stmt = $db->prepare("
                SELECT rp.no_rawat, rp.no_rkm_medis, rp.tgl_registrasi, rp.jam_reg, pj.nm_pasien,
                    pl.noorder, pl.tgl_sampel, pl.jam_sampel, tl.Pemeriksaan,
                    sml.sampel_code, sml.sampel_system, sml.sampel_display,
                    pdpl.id_template, sssl.id_servicerequest, pdpl.kd_jenis_prw,
                    IFNULL(sssp.id_specimen, '') AS id_specimen
                FROM reg_periksa rp JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
                INNER JOIN {$p} pl ON pl.no_rawat = rp.no_rawat
                INNER JOIN {$d} pdpl ON pdpl.noorder = pl.noorder
                INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                LEFT JOIN {$mp} sml ON sml.id_template = tl.id_template
                LEFT JOIN {$sr} sssl ON sssl.noorder = pdpl.noorder AND sssl.id_template = pdpl.id_template AND sssl.kd_jenis_prw = pdpl.kd_jenis_prw
                LEFT JOIN {$sp} sssp ON sssp.noorder = pdpl.noorder AND sssp.id_template = pdpl.id_template AND sssp.kd_jenis_prw = pdpl.kd_jenis_prw
                WHERE rp.no_rawat = ? AND sml.sampel_code IS NOT NULL
                  AND (sssp.id_specimen IS NULL OR sssp.id_specimen IN ('', '-'))
            ");
            $stmt->execute([$noRawat]);
            return $stmt->fetchAll();
        }
        if ($stage === 'observation') {
            $stmt = $db->prepare("
                SELECT rp.no_rawat, rp.no_rkm_medis, rp.tgl_registrasi, rp.jam_reg, pj.nm_pasien,
                    pl.noorder, pl.tgl_hasil, pl.jam_hasil, tl.Pemeriksaan, tl.satuan,
                    sml.code, sml.system, sml.display, dpl.nilai, dpl.nilai_rujukan, dpl.keterangan,
                    pdpl.id_template, sssp.id_specimen, pdpl.kd_jenis_prw,
                    peg.nama AS nm_dokter, sse.id_encounter,
                    IFNULL(sso.id_observation, '') AS id_observation
                FROM reg_periksa rp JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
                INNER JOIN {$p} pl ON pl.no_rawat = rp.no_rawat
                INNER JOIN {$d} pdpl ON pdpl.noorder = pl.noorder
                INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                LEFT JOIN {$mp} sml ON sml.id_template = tl.id_template
                LEFT JOIN {$sp} sssp ON sssp.noorder = pdpl.noorder AND sssp.id_template = pdpl.id_template AND sssp.kd_jenis_prw = pdpl.kd_jenis_prw
                INNER JOIN periksa_lab per ON per.no_rawat = pl.no_rawat AND per.tgl_periksa = pl.tgl_hasil AND per.jam = pl.jam_hasil AND per.noorder = pl.noorder
                INNER JOIN detail_periksa_lab dpl ON dpl.no_rawat = per.no_rawat AND dpl.tgl_periksa = per.tgl_periksa AND dpl.jam = per.jam AND dpl.id_template = pdpl.id_template AND dpl.kd_jenis_prw = pdpl.kd_jenis_prw
                LEFT JOIN pegawai peg ON peg.nik = per.kd_dokter
                LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                LEFT JOIN {$ob} sso ON sso.noorder = pdpl.noorder AND sso.id_template = pdpl.id_template AND sso.kd_jenis_prw = pdpl.kd_jenis_prw
                WHERE rp.no_rawat = ? AND sml.code IS NOT NULL
                  AND (sso.id_observation IS NULL OR sso.id_observation IN ('', '-'))
            ");
            $stmt->execute([$noRawat]);
            return $stmt->fetchAll();
        }
        if ($stage === 'diagnosticReport') {
            $stmt = $db->prepare("
                SELECT rp.no_rawat, rp.no_rkm_medis, rp.tgl_registrasi, rp.jam_reg, pj.nm_pasien,
                    pl.noorder, pl.tgl_hasil, pl.jam_hasil, pl.diagnosa_klinis,
                    tl.Pemeriksaan, sml.code, sml.system, sml.display, pdpl.id_template, pdpl.kd_jenis_prw,
                    sssr.id_servicerequest, sssp.id_specimen, sso.id_observation,
                    skl.kesan, peg.nama AS nm_dokter, sse.id_encounter,
                    IFNULL(ssdr.id_diagnosticreport, '') AS id_diagnosticreport
                FROM reg_periksa rp JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
                INNER JOIN {$p} pl ON pl.no_rawat = rp.no_rawat
                INNER JOIN {$d} pdpl ON pdpl.noorder = pl.noorder
                INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                LEFT JOIN {$mp} sml ON sml.id_template = tl.id_template
                LEFT JOIN {$sr} sssr ON sssr.noorder = pdpl.noorder AND sssr.id_template = pdpl.id_template AND sssr.kd_jenis_prw = pdpl.kd_jenis_prw
                LEFT JOIN {$sp} sssp ON sssr.noorder = sssp.noorder AND sssr.id_template = sssp.id_template AND sssr.kd_jenis_prw = sssp.kd_jenis_prw
                INNER JOIN periksa_lab per ON per.no_rawat = pl.no_rawat AND per.tgl_periksa = pl.tgl_hasil AND per.jam = pl.jam_hasil AND per.noorder = pl.noorder
                LEFT JOIN saran_kesan_lab skl ON per.no_rawat = skl.no_rawat AND per.tgl_periksa = skl.tgl_periksa AND per.jam = skl.jam
                LEFT JOIN {$ob} sso ON sssp.noorder = sso.noorder AND sssp.id_template = sso.id_template AND sssp.kd_jenis_prw = sso.kd_jenis_prw
                LEFT JOIN pegawai peg ON peg.nik = per.kd_dokter
                LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                LEFT JOIN {$drT} ssdr ON sssr.noorder = ssdr.noorder AND sssr.id_template = ssdr.id_template AND sssr.kd_jenis_prw = ssdr.kd_jenis_prw
                WHERE rp.no_rawat = ? AND sml.code IS NOT NULL
                  AND (ssdr.id_diagnosticreport IS NULL OR ssdr.id_diagnosticreport IN ('', '-'))
            ");
            $stmt->execute([$noRawat]);
            return $stmt->fetchAll();
        }
        return [];
    }

    private static function fetchRadRows(\PDO $db, string $noRawat, string $stage): array
    {
        if ($stage === 'serviceRequest') {
            $stmt = $db->prepare("
                SELECT rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, pj.nm_pasien, pj.no_ktp, rp.status_lanjut,
                    sse.id_encounter, pr.noorder, pr.tgl_permintaan, pr.jam_permintaan, pr.diagnosa_klinis,
                    ppr.kd_jenis_prw, jpr.nm_perawatan, smr.code, smr.system, smr.display, peg.nama AS nama,
                    IFNULL(ssr.id_servicerequest, '') AS id_servicerequest
                FROM reg_periksa rp JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
                LEFT JOIN pegawai peg ON peg.nik = rp.kd_dokter LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                INNER JOIN permintaan_radiologi pr ON pr.no_rawat = rp.no_rawat
                INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
                INNER JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = ppr.kd_jenis_prw
                LEFT JOIN satu_sehat_mapping_radiologi smr ON smr.kd_jenis_prw = jpr.kd_jenis_prw
                LEFT JOIN satu_sehat_servicerequest_radiologi ssr ON ssr.noorder = ppr.noorder AND ssr.kd_jenis_prw = ppr.kd_jenis_prw
                WHERE rp.no_rawat = ? AND smr.code IS NOT NULL
                  AND (ssr.id_servicerequest IS NULL OR ssr.id_servicerequest IN ('', '-'))
            ");
            $stmt->execute([$noRawat]);
            return $stmt->fetchAll();
        }
        if ($stage === 'specimen') {
            // FIXED: specimen JOIN uses ppr alias (not ssr) to avoid wrong-alias bug
            $stmt = $db->prepare("
                SELECT rp.no_rawat, rp.no_rkm_medis, rp.tgl_registrasi, rp.jam_reg, pj.nm_pasien,
                    pr.noorder, pr.tgl_sampel, pr.jam_sampel, jpr.nm_perawatan,
                    smr.sampel_code, smr.sampel_system, smr.sampel_display,
                    ppr.kd_jenis_prw, ssr.id_servicerequest,
                    IFNULL(sssp.id_specimen, '') AS id_specimen
                FROM reg_periksa rp JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
                INNER JOIN permintaan_radiologi pr ON pr.no_rawat = rp.no_rawat
                INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
                INNER JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = ppr.kd_jenis_prw
                LEFT JOIN satu_sehat_mapping_radiologi smr ON smr.kd_jenis_prw = jpr.kd_jenis_prw
                LEFT JOIN satu_sehat_servicerequest_radiologi ssr ON ssr.noorder = ppr.noorder AND ssr.kd_jenis_prw = ppr.kd_jenis_prw
                LEFT JOIN satu_sehat_specimen_radiologi sssp ON sssp.noorder = ppr.noorder AND sssp.kd_jenis_prw = ppr.kd_jenis_prw
                WHERE rp.no_rawat = ? AND smr.sampel_code IS NOT NULL
                  AND (sssp.id_specimen IS NULL OR sssp.id_specimen IN ('', '-'))
            ");
            $stmt->execute([$noRawat]);
            return $stmt->fetchAll();
        }
if ($stage === 'observation') {
            $stmt = $db->prepare("
                SELECT rp.no_rawat, rp.no_rkm_medis, rp.tgl_registrasi, rp.jam_reg, pj.nm_pasien,
                    pr.noorder, pr.tgl_hasil, pr.jam_hasil, jpr.nm_perawatan,
                    smr.code, smr.system, smr.display, hr.hasil, ppr.kd_jenis_prw, sssp.id_specimen,
                    peg.nama AS nm_dokter, sse.id_encounter,
                    smr.sampel_code, smr.sampel_system, smr.sampel_display,
                    IFNULL(ssi.id_imaging, '') AS id_imaging,
                    IFNULL(sso.id_observation, '') AS id_observation
                FROM reg_periksa rp JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
                INNER JOIN permintaan_radiologi pr ON pr.no_rawat = rp.no_rawat
                INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
                INNER JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = ppr.kd_jenis_prw
                LEFT JOIN satu_sehat_mapping_radiologi smr ON smr.kd_jenis_prw = jpr.kd_jenis_prw
                LEFT JOIN satu_sehat_specimen_radiologi sssp ON sssp.noorder = ppr.noorder AND sssp.kd_jenis_prw = ppr.kd_jenis_prw
                LEFT JOIN satu_sehat_imagingstudy_radiologi ssi ON ssi.noorder = ppr.noorder AND ssi.kd_jenis_prw = ppr.kd_jenis_prw
                INNER JOIN periksa_radiologi prad ON prad.no_rawat = pr.no_rawat AND prad.tgl_periksa = pr.tgl_hasil AND prad.jam = pr.jam_hasil
                INNER JOIN hasil_radiologi hr ON prad.no_rawat = hr.no_rawat AND prad.tgl_periksa = hr.tgl_periksa AND prad.jam = hr.jam AND hr.kd_jenis_prw = prad.kd_jenis_prw
                LEFT JOIN pegawai peg ON peg.nik = prad.kd_dokter
                LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                LEFT JOIN satu_sehat_observation_radiologi sso ON sso.noorder = ppr.noorder AND sso.kd_jenis_prw = ppr.kd_jenis_prw
                WHERE rp.no_rawat = ? AND smr.code IS NOT NULL
                  AND (sso.id_observation IS NULL OR sso.id_observation IN ('', '-'))
            ");
            $stmt->execute([$noRawat]);
            return $stmt->fetchAll();
        }
        if ($stage === 'diagnosticReport') {
            $stmt = $db->prepare("
                SELECT rp.no_rawat, rp.no_rkm_medis, rp.tgl_registrasi, rp.jam_reg, pj.nm_pasien,
                    pr.noorder, pr.tgl_hasil, pr.jam_hasil, pr.diagnosa_klinis,
                    jpr.nm_perawatan, IFNULL(smr.code,'') AS code, IFNULL(smr.system,'') AS system, IFNULL(smr.display,'') AS display,
                    ppr.kd_jenis_prw, ssr.id_servicerequest, sssp.id_specimen, sso.id_observation,
                    IFNULL(ssi.id_imaging, '') AS id_imaging,
                    hr.hasil, peg.nama AS nama, sse.id_encounter,
                    IFNULL(ssdr.id_diagnosticreport, '') AS id_diagnosticreport
                FROM reg_periksa rp JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
                INNER JOIN permintaan_radiologi pr ON pr.no_rawat = rp.no_rawat
                INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
                INNER JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = ppr.kd_jenis_prw
                LEFT JOIN satu_sehat_mapping_radiologi smr ON smr.kd_jenis_prw = jpr.kd_jenis_prw
                LEFT JOIN satu_sehat_servicerequest_radiologi ssr ON ssr.noorder = ppr.noorder AND ssr.kd_jenis_prw = ppr.kd_jenis_prw
                LEFT JOIN satu_sehat_specimen_radiologi sssp ON sssp.noorder = ppr.noorder AND sssp.kd_jenis_prw = ppr.kd_jenis_prw
                LEFT JOIN satu_sehat_imagingstudy_radiologi ssi ON ssi.noorder = ppr.noorder AND ssi.kd_jenis_prw = ppr.kd_jenis_prw
                INNER JOIN periksa_radiologi prad ON prad.no_rawat = pr.no_rawat AND prad.tgl_periksa = pr.tgl_hasil AND prad.jam = pr.jam_hasil
                INNER JOIN hasil_radiologi hr ON prad.no_rawat = hr.no_rawat AND prad.tgl_periksa = hr.tgl_periksa AND prad.jam = hr.jam AND hr.kd_jenis_prw = prad.kd_jenis_prw
                LEFT JOIN satu_sehat_observation_radiologi sso ON sso.noorder = ppr.noorder AND sso.kd_jenis_prw = ppr.kd_jenis_prw
                LEFT JOIN pegawai peg ON peg.nik = prad.kd_dokter
                LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                LEFT JOIN satu_sehat_diagnosticreport_radiologi ssdr ON ssdr.noorder = ppr.noorder AND ssdr.kd_jenis_prw = ppr.kd_jenis_prw
                WHERE rp.no_rawat = ?
                  AND (ssdr.id_diagnosticreport IS NULL OR ssdr.id_diagnosticreport IN ('', '-'))
            ");
            $stmt->execute([$noRawat]);
            return $stmt->fetchAll();
        }
        return [];
    }

    // Composition (single per visit — only when discharge note exists)

    private static function buildComposition(\PDO $db, array $patient, string $orgId, array $refs = []): ?array
    {
        $ihs = self::getIhsIds($patient);
        // Composition should only be sent after discharge — validate nota exists
        $stmt = $db->prepare("
            SELECT rp.no_rawat, rp.tgl_registrasi, rp.jam_reg, rp.no_rkm_medis,
                   pj.nm_pasien, pj.no_ktp, sse.id_encounter, IFNULL(ssc.id_composition, '') AS id_composition
            FROM reg_periksa rp
            JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            LEFT JOIN satu_sehat_composition ssc ON ssc.no_rawat = rp.no_rawat
            WHERE rp.no_rawat = ?
              AND (EXISTS (SELECT 1 FROM nota_jalan nj WHERE nj.no_rawat = rp.no_rawat)
                   OR EXISTS (SELECT 1 FROM nota_inap ni WHERE ni.no_rawat = rp.no_rawat))
              AND (ssc.id_composition IS NULL OR ssc.id_composition IN ('', '-'))
            LIMIT 1
        ");
        $stmt->execute([$patient['no_rawat']]);
        $row = $stmt->fetch();
        if (!$row) return null;
        // Correct argument order (the A2 bug passed id_composition as
        // $idEncounter and dropped $refs): sections now carry the in-bundle
        // resources, and the discharge summary is sent as status 'final'.
        $payload = \SatuSehatPayloadBuilder::composition(
            $orgId, $row, $ihs['pasien'], $ihs['dokter'],
            (string) ($row['id_encounter'] ?? ''),
            $refs,
            (string) ($row['id_composition'] ?? ''),
            'final'
        );
        if ($payload !== null) {
            $payload = self::withPersistKeys($payload, 'satu_sehat_composition', 'id_composition', $row, ['no_rawat']);
        }
        return $payload;
    }

    // QuestionnaireResponse (MULTI-ROW — all telaah_farmasi reviews per visit)

    private static function buildQuestionnaireResponseMulti(\PDO $db, array $patient): array
    {
        $ihs = self::getIhsIds($patient);
        $stmt = $db->prepare("
            SELECT tf.*, ro.tgl_peresepan, ro.jam_peresepan, sse.id_encounter,
                   IFNULL(ssqr.id_questionresponse, '') AS id_questionresponse
            FROM resep_obat ro INNER JOIN telaah_farmasi tf ON tf.no_resep = ro.no_resep
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = ro.no_rawat
            LEFT JOIN satu_sehat_questionresponse_telaah_farmasi ssqr ON ssqr.no_resep = ro.no_resep
            WHERE ro.no_rawat = ?
              AND (ssqr.id_questionresponse IS NULL OR ssqr.id_questionresponse IN ('', '-'))
            ORDER BY tf.tgl_telaah, tf.jam_telaah
        ");
        $stmt->execute([$patient['no_rawat']]);
        $rows = $stmt->fetchAll();

        $payloads = [];
        foreach ($rows as $row) {
            $row['nm_pasien'] = $patient['nm_pasien'];
            $row['no_ktp'] = $patient['no_ktp'];
            $payload = \SatuSehatPayloadBuilder::questionnaireResponse($row, $ihs['pasien'], $ihs['dokter'], $row['id_questionresponse'] ?? '');
            if ($payload !== null) {
                $payload = self::withPersistKeys($payload, 'satu_sehat_questionresponse_telaah_farmasi', 'id_questionresponse', $row, ['no_resep']);
                $payloads[] = $payload;
            }
        }
        return $payloads;
    }

    /**
     * ObservationTTV - builds individual Observations per vital sign.
     *
     * The CLI sends 10 separate FHIR Observations (suhu, nadi, respirasi,
     * spo2, tinggi, berat, lingkar_perut, tensi, gcs, kesadaran) each as
     * an independent resource with its own mapping table.
     *
     * V3: Iterate all 10 TTV types, produce one Observation per non-empty
     * vital sign, attach _panel_ttv_type metadata for persist routing.
     */
    private static function buildObservationTTVMulti(\PDO $db, array $patient): array
    {
        $ihs = self::getIhsIds($patient);
        $noRawat = $patient['no_rawat'];
        $statusRawat = 'Ralan';

        $stmt = $db->prepare("
            SELECT pr.*, sse.id_encounter, pg.nama, pg.no_ktp AS ktpdokter,
                   rp.kd_poli, pol.nm_poli, pr.tgl_perawatan AS tgl_observasi, pr.jam_rawat AS jam_observasi
            FROM pemeriksaan_ralan pr
            JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = pr.no_rawat
            LEFT JOIN pegawai pg ON pg.nik = pr.nip
            LEFT JOIN poliklinik pol ON pol.kd_poli = rp.kd_poli
            WHERE pr.no_rawat = ?
            ORDER BY pr.tgl_perawatan, pr.jam_rawat
        ");
        $stmt->execute([$noRawat]);
        $rows = $stmt->fetchAll();
        if (empty($rows)) {
            $statusRawat = 'Ranap';
            $stmt = $db->prepare("
                SELECT pr.*, sse.id_encounter, pg.nama, pg.no_ktp AS ktpdokter,
                       rp.kd_poli, pol.nm_poli, pr.tgl_perawatan AS tgl_observasi, pr.jam_rawat AS jam_observasi
                FROM pemeriksaan_ranap pr
                JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat
                LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = pr.no_rawat
                LEFT JOIN pegawai pg ON pg.nik = pr.nip
                LEFT JOIN poliklinik pol ON pol.kd_poli = rp.kd_poli
                WHERE pr.no_rawat = ?
                ORDER BY pr.tgl_perawatan, pr.jam_rawat
            ");
            $stmt->execute([$noRawat]);
            $rows = $stmt->fetchAll();
        }
        if (empty($rows)) return [];

        $definitions = \ObservationTTVDictionary::getDefinitions();
        $payloads = [];

        // Every exam of the visit, newest last (the old LIMIT 1 silently
        // dropped later examinations — the CLI iterates all of them).
        foreach ($rows as $row) {
            $row['nm_pasien'] = $patient['nm_pasien'];
            $row['no_ktp'] = $patient['no_ktp'];

            foreach ($definitions as $ttvKey => $def) {
                $dbCol = $def['db_column'];
                $value = trim((string) ($row[$dbCol] ?? ''));

                // Skip empty/null/dash values
                if ($value === '' || $value === '-' || $value === '0') continue;

                // Build per-TTV Observation using PayloadBuilder
                $ttvRow = $row;
                $ttvRow['value'] = $value;

                $payload = \SatuSehatPayloadBuilder::observationTTV($ttvRow, $ihs['pasien'], $ihs['dokter'], $def);
                if ($payload !== null) {
                    // Attach TTV type metadata for correct persist routing
                    $payload['_panel_ttv_type'] = $ttvKey;
                    $payload['_panel_persist_keys'] = [
                        'table' => $def['state_table'],
                        'id_col' => $def['state_id_col'],
                        'keys' => [
                            'no_rawat' => $noRawat,
                            'tgl_perawatan' => $row['tgl_perawatan'],
                            'jam_rawat' => $row['jam_rawat'],
                            'status' => $statusRawat,
                        ],
                    ];
                    $payloads[] = $payload;
                }
            }
        }
        return $payloads;
    }
}
