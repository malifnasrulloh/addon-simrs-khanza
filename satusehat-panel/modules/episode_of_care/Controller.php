<?php

declare(strict_types=1);

namespace SatusehatPanel\Modules\EpisodeOfCare;

use SatusehatPanel\Core\BaseModuleController;
use SatusehatPanel\Core\Database;
use SatusehatPanel\Util\PayloadAdapter;
use EpisodeOfCareType;

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
            $where .= " AND (rp.no_rawat LIKE ? OR pj.no_rkm_medis LIKE ? OR pj.nm_pasien LIKE ? OR dp.kd_penyakit LIKE ?)";
            $s = "%{$f['search']}%";
            array_push($params, $s, $s, $s, $s);
        }

        $sql = "
            SELECT 
                rp.no_rawat, rp.tgl_registrasi, rp.jam_reg, rp.status_bayar, rp.status_lanjut,
                pj.no_rkm_medis, pj.nm_pasien, pj.no_ktp as nik_pasien,
                dp.kd_penyakit, py.nm_penyakit, dp.status as status_diagnosa,
                peg.nama as nm_dokter, peg.no_ktp as nik_dokter,
                IFNULL(sse.id_encounter, '') as id_encounter,
                IFNULL(ssc.id_condition, '') as id_condition,
                IFNULL(sseo.id_episode_of_care, '') as id_episode_of_care
            FROM reg_periksa rp
            LEFT JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            INNER JOIN diagnosa_pasien dp ON dp.no_rawat = rp.no_rawat
            LEFT JOIN penyakit py ON py.kd_penyakit = dp.kd_penyakit
            LEFT JOIN pegawai peg ON peg.nik = rp.kd_dokter
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            LEFT JOIN satu_sehat_condition ssc ON ssc.no_rawat = rp.no_rawat AND ssc.kd_penyakit = dp.kd_penyakit
            LEFT JOIN satu_sehat_episode_of_care sseo ON sseo.no_rawat = rp.no_rawat AND sseo.kd_penyakit = dp.kd_penyakit AND sseo.status = dp.status
            {$where}
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
                $eocType = EpisodeOfCareType::fromIcdCode($r['kd_penyakit'] ?? '');
                if ($eocType === null) {
                    continue; // Only health program ICDs (TB, ANC, HIV, etc.)
                }

                $compositeKey = $r['no_rawat'] . '_' . $r['kd_penyakit'] . '_' . ($r['status_diagnosa'] ?? 'Ralan');
                $localState = self::getLocalState('episode_of_care_state', $compositeKey, $r['no_rawat'], 'EpisodeOfCare');

                // Blocker checks
                $blockers = [];
                if (empty($r['id_encounter'])) {
                    $blockers[] = 'encounter';
                }
                if (empty($r['id_condition'])) {
                    $blockers[] = 'Condition diagnosis belum terkirim';
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

                $statusInfo = self::evaluateStatus($r, $r['id_episode_of_care'], $localState, $blockers);

                if ($f['status_sync'] !== 'all' && $statusInfo['status'] !== $f['status_sync']) {
                    continue;
                }

                $items[] = array_merge($r, [
                    'item_key'     => [
                        'no_rawat'    => $r['no_rawat'],
                        'kd_penyakit' => $r['kd_penyakit'],
                        'status'      => $r['status_diagnosa'],
                    ],
                    'eoc_program'  => $eocType->display,
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
        $kdPenyakit = $parts[1] ?? '';
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

        $payloads = PayloadAdapter::build('EpisodeOfCare', $noRawat, $patient);
        $found = null;
        foreach ($payloads as $p) {
            $code = $p['_panel_persist_keys']['keys']['kd_penyakit'] ?? '';
            if ($code === $kdPenyakit || empty($kdPenyakit)) {
                $found = $p;
                break;
            }
        }

        return ['success' => true, 'data' => $found ?? ($payloads[0] ?? null)];
    }

    public static function send(): array
    {
        return self::executeSend(
            '/EpisodeOfCare',
            function (array|string $itemKey): array {
                $noRawat = is_array($itemKey) ? ($itemKey['no_rawat'] ?? '') : (string) $itemKey;
                $kdPenyakit = is_array($itemKey) ? ($itemKey['kd_penyakit'] ?? '') : '';
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

                $payloads = PayloadAdapter::build('EpisodeOfCare', $noRawat, $patient);
                foreach ($payloads as $p) {
                    $c = $p['_panel_persist_keys']['keys']['kd_penyakit'] ?? '';
                    if ($c === $kdPenyakit || empty($kdPenyakit)) {
                        return ['payload' => $p, 'meta' => $p['_panel_persist_keys'] ?? []];
                    }
                }
                throw new \RuntimeException("Payload EpisodeOfCare {$kdPenyakit} tidak ditemukan");
            },
            function (array|string $itemKey, string $satusehatId, array $outcome): void {
                $noRawat = is_array($itemKey) ? ($itemKey['no_rawat'] ?? '') : (string) $itemKey;
                $kdPenyakit = is_array($itemKey) ? ($itemKey['kd_penyakit'] ?? '') : '';
                $status = is_array($itemKey) ? ($itemKey['status'] ?? 'Ralan') : 'Ralan';
                $db = Database::getMysql();

                $stmt = $db->prepare("
                    INSERT INTO satu_sehat_episode_of_care (no_rawat, kd_penyakit, status, id_episode_of_care)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE id_episode_of_care = VALUES(id_episode_of_care)
                ");
                $stmt->execute([$noRawat, $kdPenyakit, $status, $satusehatId]);
            }
        );
    }
}
