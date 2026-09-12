<?php

declare(strict_types=1);

namespace SatusehatPanel\Modules\MedicationStatement;

defined('PANEL_BASE') || exit('Direct script access denied.');

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
            $where .= " AND (ro.no_rawat LIKE ? OR pj.nm_pasien LIKE ? OR db.nama_brng LIKE ?)";
            $s = "%{$f['search']}%";
            array_push($params, $s, $s, $s);
        }

        $sql = "
            SELECT
                ro.no_rawat, ro.no_resep, ro.tgl_peresepan, ro.jam_peresepan,
                rd.kode_brng, rd.jml, rd.aturan_pakai as aturan,
                db.nama_brng,
                rp.tgl_registrasi, rp.status_bayar,
                pj.no_rkm_medis, pj.nm_pasien, pj.no_ktp as nik_pasien,
                IFNULL(sse.id_encounter, '') as id_encounter,
                IFNULL(ssm.id_medication, '') as id_medication,
                IFNULL(ssms.id_medicationstatement, '') as id_medicationstatement
            FROM resep_obat ro
            JOIN reg_periksa rp ON rp.no_rawat = ro.no_rawat
            JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            INNER JOIN resep_dokter rd ON rd.no_resep = ro.no_resep
            JOIN databarang db ON db.kode_brng = rd.kode_brng
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            LEFT JOIN satu_sehat_medication ssm ON ssm.kode_brng = rd.kode_brng
            LEFT JOIN satu_sehat_medicationstatement ssms ON ssms.no_resep = rd.no_resep AND ssms.kode_brng = rd.kode_brng
            {$where}
            ORDER BY ro.tgl_peresepan DESC, ro.jam_peresepan DESC
            LIMIT {$f['per_page']} OFFSET {$f['offset']}
        ";

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll() ?: [];

            $items = [];

            foreach ($rows as $r) {
                $compositeKey = $r['no_resep'] . '_' . $r['kode_brng'];
                $localState = self::getLocalState('medication_statement_state', $compositeKey, $r['no_rawat'], 'MedicationStatement');

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
                if (!str_contains(strtolower($r['status_bayar']), 'sudah')) {
                    $blockers[] = 'billing';
                }

                $statusInfo = self::evaluateStatus($r, $r['id_medicationstatement'], $localState, $blockers);

                if ($f['status_sync'] !== 'all' && $statusInfo['status'] !== $f['status_sync']) {
                    continue;
                }

                $items[] = array_merge($r, [
                    'item_key'     => [
                        'no_rawat'      => $r['no_rawat'],
                        'no_resep'      => $r['no_resep'],
                        'kode_brng'     => $r['kode_brng'],
                        'no_racik'      => '',
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
            SELECT rp.*, pj.nm_pasien, pj.no_ktp, pj.no_rkm_medis
            FROM reg_periksa rp
            LEFT JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            WHERE rp.no_rawat = ?
        ");
        $stmt->execute([$noRawat]);
        $patient = $stmt->fetch();
        if (!$patient) return ['success' => false, 'error' => 'Pasien tidak ditemukan'];

        $payloads = PayloadAdapter::build('MedicationStatement', $noRawat, $patient, [], true);
        $found = null;
        foreach ($payloads as $p) {
            $meta = $p['_panel_persist_keys']['keys'] ?? [];
            if ((empty($noResep) || ($meta['no_resep'] ?? '') === $noResep) && (empty($kodeBrng) || ($meta['kode_brng'] ?? '') === $kodeBrng)) {
                $found = $p;
                break;
            }
        }

        return ['success' => true, 'data' => $found ?? ($payloads[0] ?? null)];
    }

    public static function send(): array
    {
        return self::executeSend(
            '/MedicationStatement',
            function (array|string $itemKey): array {
                $noRawat = is_array($itemKey) ? ($itemKey['no_rawat'] ?? '') : (string) $itemKey;
                $noResep = is_array($itemKey) ? ($itemKey['no_resep'] ?? '') : '';
                $kodeBrng = is_array($itemKey) ? ($itemKey['kode_brng'] ?? '') : '';

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

                $payloads = PayloadAdapter::build('MedicationStatement', $noRawat, $patient, [], true);
                if (!empty($payloads)) {
                    foreach ($payloads as $p) {
                        $meta = $p['_panel_persist_keys']['keys'] ?? [];
                        if ((empty($noResep) || ($meta['no_resep'] ?? '') === $noResep) && (empty($kodeBrng) || ($meta['kode_brng'] ?? '') === $kodeBrng)) {
                            return ['payload' => $p, 'meta' => $p['_panel_persist_keys'] ?? []];
                        }
                    }
                    return ['payload' => $payloads[0], 'meta' => $payloads[0]['_panel_persist_keys'] ?? []];
                }
                throw new \RuntimeException("Payload MedicationStatement {$kodeBrng} tidak ditemukan");
            },
            function (array|string $itemKey, string $satusehatId, array $outcome): void {
                $noResep = is_array($itemKey) ? ($itemKey['no_resep'] ?? '') : '';
                $kodeBrng = is_array($itemKey) ? ($itemKey['kode_brng'] ?? '') : '';
                if (empty($noResep)) return;

                $db = Database::getMysql();
                $stmt = $db->prepare("
                    INSERT INTO satu_sehat_medicationstatement (no_resep, kode_brng, id_medicationstatement)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE id_medicationstatement = VALUES(id_medicationstatement)
                ");
                $stmt->execute([$noResep, $kodeBrng, $satusehatId]);
            }
        );
    }
}
