<?php

declare(strict_types=1);

namespace SatusehatPanel\Modules\Composition;

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
            $where .= " AND (rp.no_rawat LIKE ? OR pj.no_rkm_medis LIKE ? OR pj.nm_pasien LIKE ?)";
            $s = "%{$f['search']}%";
            array_push($params, $s, $s, $s);
        }

        $sql = "
            SELECT 
                rp.no_rawat, rp.tgl_registrasi, rp.jam_reg, rp.status_bayar, rp.status_lanjut, rp.kd_poli,
                pj.no_rkm_medis, pj.nm_pasien, pj.no_ktp as nik_pasien,
                COALESCE(
                    (SELECT nj.tanggal FROM nota_jalan nj WHERE nj.no_rawat = rp.no_rawat LIMIT 1),
                    (SELECT ni.tanggal FROM nota_inap ni WHERE ni.no_rawat = rp.no_rawat LIMIT 1)
                ) as tgl_keluar,
                COALESCE(
                    (SELECT nj.jam FROM nota_jalan nj WHERE nj.no_rawat = rp.no_rawat LIMIT 1),
                    (SELECT ni.jam FROM nota_inap ni WHERE ni.no_rawat = rp.no_rawat LIMIT 1)
                ) as jam_keluar,
                COALESCE(peg.nama, '') as nm_dokter,
                COALESCE(peg.no_ktp, '') as nik_dokter,
                IFNULL(sse.id_encounter, '') as id_encounter,
                IFNULL(sc.id_composition, '') as id_composition
            FROM reg_periksa rp
            LEFT JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            LEFT JOIN pegawai peg ON peg.nik = rp.kd_dokter
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            LEFT JOIN satu_sehat_composition sc ON sc.no_rawat = rp.no_rawat
            {$where}
              AND (
                EXISTS (SELECT 1 FROM nota_jalan WHERE no_rawat = rp.no_rawat)
                OR EXISTS (SELECT 1 FROM nota_inap WHERE no_rawat = rp.no_rawat)
              )
            ORDER BY rp.tgl_registrasi DESC, rp.jam_reg DESC
            LIMIT {$f['per_page']} OFFSET {$f['offset']}
        ";

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll() ?: [];

            $sqlite = Database::getSqlite();
            $items = [];

            foreach ($rows as $r) {
                $localState = self::getLocalState('composition_state', $r['no_rawat'], $r['no_rawat'], 'Composition');

                $blockers = [];
                if (empty($r['id_encounter'])) {
                    $blockers[] = 'encounter';
                }
                if (empty($r['tgl_keluar'])) {
                    $blockers[] = 'Nota billing (kepulangan) belum ada';
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

                $statusInfo = self::evaluateStatus($r, $r['id_composition'], $localState, $blockers);

                if ($f['status_sync'] !== 'all' && $statusInfo['status'] !== $f['status_sync']) {
                    continue;
                }

                $items[] = array_merge($r, [
                    'item_key'     => $r['no_rawat'],
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
        $db = Database::getMysql();
        $stmt = $db->prepare("
            SELECT rp.*, pj.nm_pasien, pj.no_ktp, pj.no_rkm_medis
            FROM reg_periksa rp
            LEFT JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            WHERE rp.no_rawat = ?
        ");
        $stmt->execute([$key]);
        $patient = $stmt->fetch();
        if (!$patient) return ['success' => false, 'error' => 'Pasien tidak ditemukan'];

        $payloads = PayloadAdapter::build('Composition', $key, $patient);
        return ['success' => true, 'data' => $payloads[0] ?? null];
    }

    public static function send(): array
    {
        return self::executeSend(
            '/Composition',
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

                $payloads = PayloadAdapter::build('Composition', $noRawat, $patient);
                if (!empty($payloads)) {
                    return ['payload' => $payloads[0], 'meta' => $payloads[0]['_panel_persist_keys'] ?? []];
                }
                throw new \RuntimeException("Payload Composition {$noRawat} tidak ditemukan");
            },
            function (array|string $itemKey, string $satusehatId, array $outcome): void {
                $noRawat = is_array($itemKey) ? ($itemKey['no_rawat'] ?? '') : (string) $itemKey;
                $db = Database::getMysql();

                $stmt = $db->prepare("
                    INSERT INTO satu_sehat_composition (no_rawat, id_composition)
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE id_composition = VALUES(id_composition)
                ");
                $stmt->execute([$noRawat, $satusehatId]);
            }
        );
    }
}
