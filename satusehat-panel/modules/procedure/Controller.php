<?php

declare(strict_types=1);

namespace SatusehatPanel\Modules\Procedure;

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
            $where .= " AND (rp.no_rawat LIKE ? OR pj.no_rkm_medis LIKE ? OR pj.nm_pasien LIKE ? OR pp.kode LIKE ? OR icd.deskripsi_panjang LIKE ?)";
            $s = "%{$f['search']}%";
            array_push($params, $s, $s, $s, $s, $s);
        }

        $sql = "
            SELECT 
                rp.no_rawat, rp.tgl_registrasi, rp.jam_reg, rp.status_bayar, rp.status_lanjut,
                pj.no_rkm_medis, pj.nm_pasien, pj.no_ktp as nik_pasien,
                pp.kode, icd.deskripsi_panjang, pp.status as status_prosedur, pp.prioritas,
                peg.nama as nm_dokter, peg.no_ktp as nik_dokter,
                IFNULL(sse.id_encounter, '') as id_encounter,
                IFNULL(ssp.id_procedure, '') as id_procedure
            FROM reg_periksa rp
            LEFT JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            INNER JOIN prosedur_pasien pp ON pp.no_rawat = rp.no_rawat
            LEFT JOIN icd9 icd ON icd.kode = pp.kode
            LEFT JOIN pegawai peg ON peg.nik = rp.kd_dokter
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            LEFT JOIN satu_sehat_procedure ssp ON ssp.no_rawat = pp.no_rawat AND ssp.kode = pp.kode AND ssp.status = pp.status
            {$where}
            ORDER BY rp.tgl_registrasi DESC, rp.jam_reg DESC, pp.prioritas ASC
            LIMIT {$f['per_page']} OFFSET {$f['offset']}
        ";

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll() ?: [];

            $sqlite = Database::getSqlite();
            $items = [];

            foreach ($rows as $r) {
                $compositeKey = $r['no_rawat'] . '_' . $r['kode'] . '_' . ($r['status_prosedur'] ?? 'Ralan');
                $localState = self::getLocalState('procedure_state', $compositeKey, $r['no_rawat'], 'Procedure');

                $blockers = [];
                if (empty($r['id_encounter'])) {
                    $blockers[] = 'encounter';
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

                $statusInfo = self::evaluateStatus($r, $r['id_procedure'], $localState, $blockers);

                if ($f['status_sync'] !== 'all' && $statusInfo['status'] !== $f['status_sync']) {
                    continue;
                }

                $items[] = array_merge($r, [
                    'item_key'     => [
                        'no_rawat' => $r['no_rawat'],
                        'kode'     => $r['kode'],
                        'status'   => $r['status_prosedur'],
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
        $kode = $parts[1] ?? '';
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

        $payloads = PayloadAdapter::build('Procedure', $noRawat, $patient);
        $found = null;
        foreach ($payloads as $p) {
            $c = $p['code']['coding'][0]['code'] ?? ($p['_panel_persist_keys']['keys']['kode'] ?? '');
            if ($c === $kode || empty($kode)) {
                $found = $p;
                break;
            }
        }

        return ['success' => true, 'data' => $found ?? ($payloads[0] ?? null)];
    }

    public static function send(): array
    {
        return self::executeSend(
            '/Procedure',
            function (array|string $itemKey): array {
                $noRawat = is_array($itemKey) ? ($itemKey['no_rawat'] ?? '') : (string) $itemKey;
                $kode = is_array($itemKey) ? ($itemKey['kode'] ?? '') : '';
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

                $payloads = PayloadAdapter::build('Procedure', $noRawat, $patient);
                foreach ($payloads as $p) {
                    $c = $p['code']['coding'][0]['code'] ?? ($p['_panel_persist_keys']['keys']['kode'] ?? '');
                    if ($c === $kode || empty($kode)) {
                        return ['payload' => $p, 'meta' => $p['_panel_persist_keys'] ?? []];
                    }
                }
                throw new \RuntimeException("Payload Procedure {$kode} tidak ditemukan");
            },
            function (array|string $itemKey, string $satusehatId, array $outcome): void {
                $noRawat = is_array($itemKey) ? ($itemKey['no_rawat'] ?? '') : (string) $itemKey;
                $kode = is_array($itemKey) ? ($itemKey['kode'] ?? '') : '';
                $status = is_array($itemKey) ? ($itemKey['status'] ?? 'Ralan') : 'Ralan';
                $db = Database::getMysql();

                $stmt = $db->prepare("
                    INSERT INTO satu_sehat_procedure (no_rawat, kode, status, id_procedure)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE id_procedure = VALUES(id_procedure)
                ");
                $stmt->execute([$noRawat, $kode, $status, $satusehatId]);
            }
        );
    }
}
