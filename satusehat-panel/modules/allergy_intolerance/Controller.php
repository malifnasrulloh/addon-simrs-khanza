<?php

declare(strict_types=1);

namespace SatusehatPanel\Modules\AllergyIntolerance;

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
            $where .= " AND (rp.no_rawat LIKE ? OR pj.no_rkm_medis LIKE ? OR pj.nm_pasien LIKE ? OR pr.alergi LIKE ?)";
            $s = "%{$f['search']}%";
            array_push($params, $s, $s, $s, $s);
        }

        $sql = "
            SELECT 
                rp.no_rawat, rp.tgl_registrasi, rp.jam_reg, rp.status_bayar,
                pj.no_rkm_medis, pj.nm_pasien, pj.no_ktp as nik_pasien,
                pr.alergi, pr.tgl_perawatan, pr.jam_rawat, 'Ralan' as status_rawat,
                COALESCE(pg.nama, pg_dok.nama, '') as nm_dokter,
                COALESCE(pg.no_ktp, '') as nik_dokter,
                COALESCE(pg_dok.no_ktp, '') as nik_dokter_dpjp,
                IFNULL(sse.id_encounter, '') as id_encounter,
                IFNULL(ssai.id_allergy_intolerance, '') as id_allergy_intolerance
            FROM reg_periksa rp
            LEFT JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            INNER JOIN pemeriksaan_ralan pr ON pr.no_rawat = rp.no_rawat
            LEFT JOIN pegawai pg ON pg.nik = pr.nip
            LEFT JOIN pegawai pg_dok ON pg_dok.nik = rp.kd_dokter
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            LEFT JOIN satu_sehat_allergy_intolerance ssai ON ssai.no_rawat = pr.no_rawat AND ssai.tgl_perawatan = pr.tgl_perawatan AND ssai.jam_rawat = pr.jam_rawat AND ssai.status = 'Ralan'
            {$where} AND pr.alergi IS NOT NULL AND pr.alergi <> '' AND pr.alergi <> '-'
            ORDER BY pr.tgl_perawatan DESC, pr.jam_rawat DESC
            LIMIT {$f['per_page']} OFFSET {$f['offset']}
        ";

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll() ?: [];

            $sqlite = Database::getSqlite();
            $items = [];

            foreach ($rows as $r) {
                $compositeKey = md5($r['no_rawat'] . '_' . $r['tgl_perawatan'] . '_' . $r['jam_rawat'] . '_' . $r['alergi']);
                $localState = self::getLocalState('allergyintolerance_state', $compositeKey, $r['no_rawat'], 'AllergyIntolerance');

                $blockers = [];
                if (empty($r['id_encounter'])) {
                    $blockers[] = 'encounter';
                }
                if (empty($r['nik_pasien']) || strlen($r['nik_pasien']) < 16) {
                    $blockers[] = 'ihs_pasien';
                }
                if (empty($r['nik_dokter']) && empty($r['nik_dokter_dpjp'])) {
                    $blockers[] = 'ihs_dokter';
                }
                if (!str_contains(strtolower($r['status_bayar']), 'sudah')) {
                    $blockers[] = 'billing';
                }

                $statusInfo = self::evaluateStatus($r, $r['id_allergy_intolerance'], $localState, $blockers);

                if ($f['status_sync'] !== 'all' && $statusInfo['status'] !== $f['status_sync']) {
                    continue;
                }

                $items[] = array_merge($r, [
                    'item_key'     => [
                        'no_rawat'      => $r['no_rawat'],
                        'tgl_perawatan' => $r['tgl_perawatan'],
                        'jam_rawat'     => $r['jam_rawat'],
                        'status'        => $r['status_rawat'],
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
        $db = Database::getMysql();

        $stmt = $db->prepare("
            SELECT rp.*, pj.nm_pasien, pj.no_ktp, pj.no_rkm_medis
            FROM reg_periksa rp
            LEFT JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            WHERE rp.no_rawat = ?
        ");
        $stmt->execute([$noRawat]);
        $patient = $stmt->fetch();
        if (!$patient) return ['success' => false, 'error' => 'Pasien tidak ditemukan'];

        $payloads = PayloadAdapter::build('AllergyIntolerance', $noRawat, $patient);
        return ['success' => true, 'data' => $payloads[0] ?? null];
    }

    public static function send(): array
    {
        return self::executeSend(
            '/AllergyIntolerance',
            function (array|string $itemKey): array {
                $noRawat = is_array($itemKey) ? ($itemKey['no_rawat'] ?? '') : (string) $itemKey;
                $db = Database::getMysql();

                $stmt = $db->prepare("
                    SELECT rp.*, pj.nm_pasien, pj.no_ktp, pj.no_rkm_medis
                    FROM reg_periksa rp
                    LEFT JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
                    WHERE rp.no_rawat = ?
                ");
                $stmt->execute([$noRawat]);
                $patient = $stmt->fetch();
                if (!$patient) throw new \RuntimeException("Pasien {$noRawat} tidak ditemukan");

                $payloads = PayloadAdapter::build('AllergyIntolerance', $noRawat, $patient);
                if (empty($payloads)) throw new \RuntimeException("Payload AllergyIntolerance tidak ditemukan");

                return ['payload' => $payloads[0], 'meta' => $payloads[0]['_panel_persist_keys'] ?? []];
            },
            function (array|string $itemKey, string $satusehatId, array $outcome): void {
                $noRawat = is_array($itemKey) ? ($itemKey['no_rawat'] ?? '') : (string) $itemKey;
                $tgl = is_array($itemKey) ? ($itemKey['tgl_perawatan'] ?? '') : '';
                $jam = is_array($itemKey) ? ($itemKey['jam_rawat'] ?? '') : '';
                $status = is_array($itemKey) ? ($itemKey['status'] ?? 'Ralan') : 'Ralan';
                $db = Database::getMysql();

                $stmt = $db->prepare("
                    INSERT INTO satu_sehat_allergy_intolerance (no_rawat, tgl_perawatan, jam_rawat, status, id_allergy_intolerance)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE id_allergy_intolerance = VALUES(id_allergy_intolerance)
                ");
                $stmt->execute([$noRawat, $tgl, $jam, $status, $satusehatId]);
            }
        );
    }
}
