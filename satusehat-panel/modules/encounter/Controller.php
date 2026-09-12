<?php

declare(strict_types=1);

namespace SatusehatPanel\Modules\Encounter;

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
            $where .= " AND (rp.no_rawat LIKE ? OR pj.no_rkm_medis LIKE ? OR pj.nm_pasien LIKE ? OR pj.no_ktp LIKE ?)";
            $s = "%{$f['search']}%";
            array_push($params, $s, $s, $s, $s);
        }

        $sql = "
            SELECT
                rp.no_rawat, rp.tgl_registrasi, rp.jam_reg, rp.status_bayar, rp.status_lanjut, rp.kd_poli,
                pj.no_rkm_medis, pj.nm_pasien, pj.no_ktp as nik_pasien, pj.tgl_lahir, pj.jk,
                peg.nama as nm_dokter, peg.no_ktp as nik_dokter,
                COALESCE(pol.nm_poli, 'Rawat Inap') as nm_poli,
                IFNULL(sse.id_encounter, '') as id_encounter,
                IFNULL(sml.id_lokasi_satusehat, '') as id_lokasi_satusehat,
                COALESCE(
                    (SELECT sseo.id_episode_of_care
                     FROM satu_sehat_episode_of_care sseo
                     WHERE sseo.no_rawat = rp.no_rawat
                       AND sseo.id_episode_of_care IS NOT NULL
                       AND sseo.id_episode_of_care NOT IN ('', '-')
                     LIMIT 1),
                    ''
                ) as id_episode_of_care,
                COALESCE(
                    (SELECT nj.tanggal FROM nota_jalan nj WHERE nj.no_rawat = rp.no_rawat LIMIT 1),
                    (SELECT ni.tanggal FROM nota_inap ni WHERE ni.no_rawat = rp.no_rawat LIMIT 1)
                ) as tgl_keluar
            FROM reg_periksa rp
            LEFT JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            LEFT JOIN pegawai peg ON peg.nik = rp.kd_dokter
            LEFT JOIN poliklinik pol ON pol.kd_poli = rp.kd_poli
            LEFT JOIN satu_sehat_mapping_lokasi_ralan sml ON sml.kd_poli = rp.kd_poli
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            {$where}
            ORDER BY rp.tgl_registrasi DESC, rp.jam_reg DESC
            LIMIT {$f['per_page']} OFFSET {$f['offset']}
        ";

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll() ?: [];

            $sqlite = null;
            try {
                $sqlite = Database::getSqlite();
            } catch (\Throwable $e) { }
            $items = [];

            foreach ($rows as $r) {
                // Check local state in SQLite
                $localState = self::getLocalState('encounter_state', $r['no_rawat'], $r['no_rawat'], 'Encounter');
                $eocLinked = 0;
                if ($sqlite) {
                    try {
                        $stmtE = $sqlite->prepare("SELECT status, eoc_linked FROM encounter_state WHERE no_rawat = ? LIMIT 1");
                        $stmtE->execute([$r['no_rawat']]);
                        $rowE = $stmtE->fetch();
                        if ($rowE) {
                            $localState = (string) $rowE['status'];
                            $eocLinked = (int) ($rowE['eoc_linked'] ?? 0);
                        }
                    } catch (\Throwable $e) { }
                }

                // Blocker checks
                $blockers = [];
                if (empty($r['id_lokasi_satusehat']) && $r['status_lanjut'] === 'Ralan') {
                    $blockers[] = 'location';
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

                $hasId = !empty($r['id_encounter']) && $r['id_encounter'] !== '-';
                $isDischarged = !empty($r['tgl_keluar']);
                $hasEoc = !empty($r['id_episode_of_care']);

                // Status transition & update hints
                $needsUpdate = false;
                $updateReason = null;
                $updateLabel = 'Perlu Update';

                if ($hasId) {
                    if ($isDischarged && $localState !== 'finished') {
                        $needsUpdate = true;
                        $updateLabel = 'Perlu Update (Selesai)';
                        $updateReason = 'Pasien sudah pulang — perbarui status Encounter ke finished';
                    } elseif ($hasEoc && ($localState !== 'finished' || empty($eocLinked))) {
                        $needsUpdate = true;
                        $updateLabel = 'Perlu Update (Tautkan EoC)';
                        $updateReason = 'EpisodeOfCare tersedia — perbarui Encounter untuk menautkan referensi';
                    }
                }

                $options = [
                    'needs_update'   => $needsUpdate,
                    'update_label'  => $updateLabel,
                    'update_reason' => $updateReason,
                    'allow_reupdate'=> true,
                ];

                $statusInfo = self::evaluateStatus($r, $r['id_encounter'], $localState, $blockers, $options);

                // Filter by status_sync if requested
                if ($f['status_sync'] !== 'all' && $statusInfo['status'] !== $f['status_sync']) {
                    continue;
                }

                $items[] = array_merge($r, [
                    'item_key'            => $r['no_rawat'],
                    'status_info'         => $statusInfo,
                    'is_finished'         => !empty($r['tgl_keluar']),
                    'id_episode_of_care'  => $r['id_episode_of_care'],
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
        if (!$patient) {
            return ['success' => false, 'error' => 'Pasien tidak ditemukan'];
        }

        try {
            $payloads = PayloadAdapter::build('Encounter', $key, $patient, [], true);
            return [
                'success' => true,
                'data'    => $payloads[0] ?? null,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public static function send(): array
    {
        return self::executeSend(
            '/Encounter',
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

                $payloads = PayloadAdapter::build('Encounter', $noRawat, $patient, [], true);
                if (empty($payloads)) throw new \RuntimeException("Gagal membuat payload Encounter untuk {$noRawat}");

                return ['payload' => $payloads[0], 'meta' => []];
            },
            function (array|string $itemKey, string $satusehatId, array $outcome): void {
                $noRawat = is_array($itemKey) ? ($itemKey['no_rawat'] ?? '') : (string) $itemKey;
                $db = Database::getMysql();
                $stmt = $db->prepare("
                    INSERT INTO satu_sehat_encounter (no_rawat, id_encounter)
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE id_encounter = VALUES(id_encounter)
                ");
                $stmt->execute([$noRawat, $satusehatId]);

                // Track finished status in SQLite if discharge note exists
                try {
                    $sqlite = Database::getSqlite();
                    $stmtCheck = $db->prepare("
                        SELECT
                            COALESCE(nj.tanggal, ni.tanggal) as tgl_keluar,
                            (SELECT sseo.id_episode_of_care FROM satu_sehat_episode_of_care sseo WHERE sseo.no_rawat = ? AND sseo.id_episode_of_care IS NOT NULL AND sseo.id_episode_of_care NOT IN ('', '-') LIMIT 1) as id_eoc
                        FROM reg_periksa rp
                        LEFT JOIN nota_jalan nj ON nj.no_rawat = rp.no_rawat
                        LEFT JOIN nota_inap ni ON ni.no_rawat = rp.no_rawat
                        WHERE rp.no_rawat = ? LIMIT 1
                    ");
                    $stmtCheck->execute([$noRawat, $noRawat]);
                    $disc = $stmtCheck->fetch();
                    $status = !empty($disc['tgl_keluar']) ? 'finished' : 'in-progress';
                    $eocLinked = !empty($disc['id_eoc']) ? 1 : 0;
                    $sqlite->prepare("
                        INSERT INTO encounter_state (no_rawat, status, eoc_linked, updated_at)
                        VALUES (?, ?, ?, CURRENT_TIMESTAMP)
                        ON CONFLICT(no_rawat) DO UPDATE SET status = excluded.status, eoc_linked = excluded.eoc_linked, updated_at = CURRENT_TIMESTAMP
                    ")->execute([$noRawat, $status, $eocLinked]);
                } catch (\Throwable $e) { /* non-fatal */ }
            }
        );
    }
}
