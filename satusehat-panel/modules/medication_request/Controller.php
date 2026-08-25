<?php

declare(strict_types=1);

namespace SatusehatPanel\Modules\MedicationRequest;

use SatusehatPanel\Core\BaseModuleController;
use SatusehatPanel\Core\Database;
use SatusehatPanel\Util\PayloadAdapter;

class Controller extends BaseModuleController
{
    public static function list(): array
    {
        $f = self::parseFilters();
        $db = Database::getMysql();

        $where = "WHERE rp.tgl_registrasi BETWEEN ? AND ?";
        $params = [$f['since'], $f['until']];

        if ($f['status_bayar'] !== 'all') {
            $where .= " AND rp.status_bayar = ?";
            $params[] = $f['status_bayar'];
        }
        if ($f['kd_poli'] !== '') {
            $where .= " AND rp.kd_poli = ?";
            $params[] = $f['kd_poli'];
        }
        if ($f['search'] !== '') {
            $where .= " AND (ro.no_resep LIKE ? OR ro.no_rawat LIKE ? OR pj.nm_pasien LIKE ? OR db.nama_brng LIKE ?)";
            $s = "%{$f['search']}%";
            array_push($params, $s, $s, $s, $s);
        }

        $sql = "
            SELECT 
                ro.no_resep, ro.no_rawat, ro.tgl_peresepan, ro.jam_peresepan,
                rp.tgl_registrasi, rp.status_bayar, rp.status_lanjut,
                pj.no_rkm_medis, pj.nm_pasien, pj.no_ktp as nik_pasien,
                rd.kode_brng, db.nama_brng, rd.jml, rd.aturan_pakai, '' as no_racik, 0 as is_racikan,
                COALESCE(peg.nama, '') as nm_dokter,
                COALESCE(peg.no_ktp, '') as nik_dokter,
                IFNULL(sse.id_encounter, '') as id_encounter,
                IFNULL(ssm.id_medication, '') as id_medication,
                IFNULL(ssmr.id_medicationrequest, '') as id_medicationrequest
            FROM resep_obat ro
            JOIN reg_periksa rp ON rp.no_rawat = ro.no_rawat
            LEFT JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            INNER JOIN resep_dokter rd ON rd.no_resep = ro.no_resep
            JOIN databarang db ON db.kode_brng = rd.kode_brng
            LEFT JOIN pegawai peg ON peg.nik = ro.kd_dokter
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            LEFT JOIN satu_sehat_medication ssm ON ssm.kode_brng = rd.kode_brng
            LEFT JOIN satu_sehat_medicationrequest ssmr ON ssmr.no_resep = rd.no_resep AND ssmr.kode_brng = rd.kode_brng
            {$where}
            ORDER BY ro.tgl_peresepan DESC, ro.jam_peresepan DESC
            LIMIT {$f['per_page']} OFFSET {$f['offset']}
        ";

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll() ?: [];

            $sqlite = Database::getSqlite();
            $items = [];

            foreach ($rows as $r) {
                $compositeKey = $r['no_resep'] . '_' . $r['kode_brng'];
                $localState = self::getLocalState('medication_request_state', $compositeKey, $r['no_rawat'], 'MedicationRequest');

                $blockers = [];
                if (empty($r['id_encounter'])) {
                    $blockers[] = 'encounter';
                }
                if (empty($r['id_medication'])) {
                    $blockers[] = 'Master Obat KFA belum terkirim';
                }
                if (empty($r['nik_pasien']) || strlen($r['nik_pasien']) < 16) {
                    $blockers[] = 'ihs_pasien';
                }
                if (empty($r['nik_dokter'])) {
                    $blockers[] = 'ihs_dokter';
                }
                if (!str_contains(strtolower($r['status_bayar']), 'sudah')) {
                    $blockers[] = 'billing';
                }

                $statusInfo = self::evaluateStatus($r, $r['id_medicationrequest'], $localState, $blockers);

                if ($f['status_sync'] !== 'all' && $statusInfo['status'] !== $f['status_sync']) {
                    continue;
                }

                $items[] = array_merge($r, [
                    'item_key'     => [
                        'no_rawat'  => $r['no_rawat'],
                        'no_resep'  => $r['no_resep'],
                        'kode_brng' => $r['kode_brng'],
                        'no_racik'  => '',
                    ],
                    'status_info'  => $statusInfo,
                ]);
            }

            return [
                'success' => true,
                'data'    => $items,
                'count'   => count($items),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public static function preview(string $key): array
    {
        $parts = explode('|', $key);
        $noRawat = $parts[0];
        $noResep = $parts[1] ?? '';
        $kodeBrng = $parts[2] ?? '';

        $db = Database::getMysql();
        $stmt = $db->prepare("
            SELECT rp.*, pj.nm_pasien, pj.no_ktp, pj.no_rkm_medis,
                rd.kode_brng, db.nama_brng, rd.jml, rd.aturan_pakai, ro.no_resep,
                ro.tgl_peresepan, ro.jam_peresepan,
                ssmo.obat_code, ssmo.obat_system, ssmo.obat_display,
                ssmo.form_code, ssmo.form_system, ssmo.form_display,
                ssmo.route_code, ssmo.route_system, ssmo.route_display,
                ssmo.denominator_code, ssmo.denominator_system,
                IFNULL(sse.id_encounter, '') as id_encounter,
                IFNULL(ssm.id_medication, '') as id_medication,
                IFNULL(ssmr.id_medicationrequest, '') as id_medicationrequest,
                peg.nama as nama, ro.kd_dokter
            FROM resep_obat ro
            JOIN reg_periksa rp ON rp.no_rawat = ro.no_rawat
            LEFT JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            INNER JOIN resep_dokter rd ON rd.no_resep = ro.no_resep
            JOIN databarang db ON db.kode_brng = rd.kode_brng
            LEFT JOIN pegawai peg ON peg.nik = ro.kd_dokter
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            LEFT JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = rd.kode_brng
            LEFT JOIN satu_sehat_medication ssm ON ssm.kode_brng = rd.kode_brng
            LEFT JOIN satu_sehat_medicationrequest ssmr ON ssmr.no_resep = rd.no_resep AND ssmr.kode_brng = rd.kode_brng
            WHERE ro.no_rawat = ? AND ro.no_resep = ? AND rd.kode_brng = ?
            LIMIT 1
        ");
        $stmt->execute([$noRawat, $noResep, $kodeBrng]);
        $row = $stmt->fetch();
        if (!$row) return ['success' => false, 'error' => 'Resep tidak ditemukan'];

        $orgId = (string) \SatusehatPanel\Core\Config::get('satusehat.org_id', '');
        $ihs = self::resolveIhs($db, $row['no_ktp'] ?? '', $row['kd_dokter'] ?? '');
        $payload = \SatuSehatPayloadBuilder::medicationRequest(
            $orgId, $row, $ihs['pasien'], $ihs['dokter'], $row['id_medicationrequest'] ?: null
        );

        return ['success' => true, 'data' => $payload];
    }

    public static function send(): array
    {
        return self::executeSend(
            '/MedicationRequest',
            function (array|string $itemKey): array {
                $noRawat = is_array($itemKey) ? ($itemKey['no_rawat'] ?? '') : (string) $itemKey;
                $noResep = is_array($itemKey) ? ($itemKey['no_resep'] ?? '') : '';
                $kodeBrng = is_array($itemKey) ? ($itemKey['kode_brng'] ?? '') : '';

                $db = Database::getMysql();
                $stmt = $db->prepare("
                    SELECT rp.*, pj.nm_pasien, pj.no_ktp, pj.no_rkm_medis,
                        rd.kode_brng, db.nama_brng, rd.jml, rd.aturan_pakai, ro.no_resep,
                        ro.tgl_peresepan, ro.jam_peresepan,
                        ssmo.obat_code, ssmo.obat_system, ssmo.obat_display,
                        ssmo.form_code, ssmo.form_system, ssmo.form_display,
                        ssmo.route_code, ssmo.route_system, ssmo.route_display,
                        ssmo.denominator_code, ssmo.denominator_system,
                        IFNULL(sse.id_encounter, '') as id_encounter,
                        IFNULL(ssm.id_medication, '') as id_medication,
                        IFNULL(ssmr.id_medicationrequest, '') as id_medicationrequest,
                        peg.nama as nama, ro.kd_dokter
                    FROM resep_obat ro
                    JOIN reg_periksa rp ON rp.no_rawat = ro.no_rawat
                    LEFT JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
                    INNER JOIN resep_dokter rd ON rd.no_resep = ro.no_resep
                    JOIN databarang db ON db.kode_brng = rd.kode_brng
                    LEFT JOIN pegawai peg ON peg.nik = ro.kd_dokter
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = rd.kode_brng
                    LEFT JOIN satu_sehat_medication ssm ON ssm.kode_brng = rd.kode_brng
                    LEFT JOIN satu_sehat_medicationrequest ssmr ON ssmr.no_resep = rd.no_resep AND ssmr.kode_brng = rd.kode_brng
                    WHERE ro.no_rawat = ? AND ro.no_resep = ? AND rd.kode_brng = ?
                    LIMIT 1
                ");
                $stmt->execute([$noRawat, $noResep, $kodeBrng]);
                $row = $stmt->fetch();
                if (!$row) throw new \RuntimeException("Resep {$noResep}/{$kodeBrng} tidak ditemukan");

                $orgId = (string) \SatusehatPanel\Core\Config::get('satusehat.org_id', '');
                $ihs = self::resolveIhs($db, $row['no_ktp'] ?? '', $row['kd_dokter'] ?? '');
                $payload = \SatuSehatPayloadBuilder::medicationRequest(
                    $orgId, $row, $ihs['pasien'], $ihs['dokter'], $row['id_medicationrequest'] ?: null
                );

                return ['payload' => $payload, 'meta' => ['no_resep' => $noResep, 'kode_brng' => $kodeBrng]];
            },
            function (array|string $itemKey, string $satusehatId, array $outcome): void {
                $noResep = is_array($itemKey) ? ($itemKey['no_resep'] ?? '') : '';
                $kodeBrng = is_array($itemKey) ? ($itemKey['kode_brng'] ?? '') : '';
                $db = Database::getMysql();

                $stmt = $db->prepare("
                    INSERT INTO satu_sehat_medicationrequest (no_resep, kode_brng, id_medicationrequest)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE id_medicationrequest = VALUES(id_medicationrequest)
                ");
                $stmt->execute([$noResep, $kodeBrng, $satusehatId]);
            }
        );
    }
}
