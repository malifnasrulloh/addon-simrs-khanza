<?php

declare(strict_types=1);

namespace SatusehatPanel\Modules\ObservationTtv;

use SatusehatPanel\Core\BaseModuleController;
use SatusehatPanel\Core\Database;
use ObservationTTVDictionary;
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
                rp.no_rawat, rp.tgl_registrasi, rp.jam_reg, rp.status_bayar,
                pj.no_rkm_medis, pj.nm_pasien, pj.no_ktp as nik_pasien,
                COALESCE(pg.nama, pg_dok.nama, '') as nm_dokter,
                COALESCE(pg.no_ktp, '') as nik_dokter,
                COALESCE(pg_dok.no_ktp, '') as nik_dokter_dpjp,
                COALESCE(pol.nm_poli, 'Rawat Inap') as nm_poli,
                IFNULL(sse.id_encounter, '') as id_encounter,
                pr.tgl_perawatan, pr.jam_rawat, 'Ralan' as status_rawat,
                pr.suhu_tubuh, pr.tensi, pr.nadi, pr.respirasi, pr.spo2, pr.tinggi, pr.berat, pr.lingkar_perut, pr.gcs, pr.kesadaran,
                IFNULL(st_suhu.id_observation, '') as id_suhu,
                IFNULL(st_tensi.id_observation, '') as id_tensi,
                IFNULL(st_nadi.id_observation, '') as id_nadi,
                IFNULL(st_respirasi.id_observation, '') as id_respirasi,
                IFNULL(st_spo2.id_observation, '') as id_spo2,
                IFNULL(st_tb.id_observation, '') as id_tb,
                IFNULL(st_bb.id_observation, '') as id_bb,
                IFNULL(st_lp.id_observation, '') as id_lp,
                IFNULL(st_gcs.id_observation, '') as id_gcs,
                IFNULL(st_kesadaran.id_observation, '') as id_kesadaran
            FROM reg_periksa rp
            LEFT JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            INNER JOIN pemeriksaan_ralan pr ON pr.no_rawat = rp.no_rawat
            LEFT JOIN pegawai pg ON pg.nik = pr.nip
            LEFT JOIN pegawai pg_dok ON pg_dok.nik = rp.kd_dokter
            LEFT JOIN poliklinik pol ON pol.kd_poli = rp.kd_poli
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            LEFT JOIN satu_sehat_observationttvsuhu st_suhu ON st_suhu.no_rawat = pr.no_rawat AND st_suhu.tgl_perawatan = pr.tgl_perawatan AND st_suhu.jam_rawat = pr.jam_rawat AND st_suhu.status = 'Ralan'
            LEFT JOIN satu_sehat_observationttvtensi st_tensi ON st_tensi.no_rawat = pr.no_rawat AND st_tensi.tgl_perawatan = pr.tgl_perawatan AND st_tensi.jam_rawat = pr.jam_rawat AND st_tensi.status = 'Ralan'
            LEFT JOIN satu_sehat_observationttvnadi st_nadi ON st_nadi.no_rawat = pr.no_rawat AND st_nadi.tgl_perawatan = pr.tgl_perawatan AND st_nadi.jam_rawat = pr.jam_rawat AND st_nadi.status = 'Ralan'
            LEFT JOIN satu_sehat_observationttvrespirasi st_respirasi ON st_respirasi.no_rawat = pr.no_rawat AND st_respirasi.tgl_perawatan = pr.tgl_perawatan AND st_respirasi.jam_rawat = pr.jam_rawat AND st_respirasi.status = 'Ralan'
            LEFT JOIN satu_sehat_observationttvspo2 st_spo2 ON st_spo2.no_rawat = pr.no_rawat AND st_spo2.tgl_perawatan = pr.tgl_perawatan AND st_spo2.jam_rawat = pr.jam_rawat AND st_spo2.status = 'Ralan'
            LEFT JOIN satu_sehat_observationttvtb st_tb ON st_tb.no_rawat = pr.no_rawat AND st_tb.tgl_perawatan = pr.tgl_perawatan AND st_tb.jam_rawat = pr.jam_rawat AND st_tb.status = 'Ralan'
            LEFT JOIN satu_sehat_observationttvbb st_bb ON st_bb.no_rawat = pr.no_rawat AND st_bb.tgl_perawatan = pr.tgl_perawatan AND st_bb.jam_rawat = pr.jam_rawat AND st_bb.status = 'Ralan'
            LEFT JOIN satu_sehat_observationttvlp st_lp ON st_lp.no_rawat = pr.no_rawat AND st_lp.tgl_perawatan = pr.tgl_perawatan AND st_lp.jam_rawat = pr.jam_rawat AND st_lp.status = 'Ralan'
            LEFT JOIN satu_sehat_observationttvgcs st_gcs ON st_gcs.no_rawat = pr.no_rawat AND st_gcs.tgl_perawatan = pr.tgl_perawatan AND st_gcs.jam_rawat = pr.jam_rawat AND st_gcs.status = 'Ralan'
            LEFT JOIN satu_sehat_observationttvkesadaran st_kesadaran ON st_kesadaran.no_rawat = pr.no_rawat AND st_kesadaran.tgl_perawatan = pr.tgl_perawatan AND st_kesadaran.jam_rawat = pr.jam_rawat AND st_kesadaran.status = 'Ralan'
            {$where}
            ORDER BY pr.tgl_perawatan DESC, pr.jam_rawat DESC
            LIMIT {$f['per_page']} OFFSET {$f['offset']}
        ";

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll() ?: [];

            $sqlite = Database::getSqlite();
            $defs = ObservationTTVDictionary::getDefinitions();
            $items = [];

            foreach ($rows as $r) {
                // Fan out examination row into each non-empty vital sign item
                foreach ($defs as $ttvKey => $def) {
                    $col = $def['db_column'];
                    $val = trim((string)($r[$col] ?? ''));
                    if ($val === '' || $val === '-') {
                        continue;
                    }

                    $mappingId = (string)($r["id_{$ttvKey}"] ?? '');
                    $compKey = "{$ttvKey}_{$r['no_rawat']}_{$r['tgl_perawatan']}_{$r['jam_rawat']}_{$r['status_rawat']}";
                    $localState = self::getLocalState('observationttv_state', $compKey, $r['no_rawat'], 'Observation');

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

                    $statusInfo = self::evaluateStatus($r, $mappingId, $localState, $blockers);

                    if ($f['status_sync'] !== 'all' && $statusInfo['status'] !== $f['status_sync']) {
                        continue;
                    }

                    $items[] = [
                        'item_key'       => [
                            'no_rawat'      => $r['no_rawat'],
                            'ttv_type'      => $ttvKey,
                            'tgl_perawatan' => $r['tgl_perawatan'],
                            'jam_rawat'     => $r['jam_rawat'],
                            'status'        => $r['status_rawat'],
                        ],
                        'no_rawat'       => $r['no_rawat'],
                        'no_rkm_medis'   => $r['no_rkm_medis'],
                        'nm_pasien'      => $r['nm_pasien'],
                        'tgl_observasi'  => $r['tgl_perawatan'],
                        'jam_observasi'  => $r['jam_rawat'],
                        'nm_poli'        => $r['nm_poli'],
                        'nm_dokter'      => $r['nm_dokter'],
                        'status_bayar'   => $r['status_bayar'],
                        'ttv_type'       => $ttvKey,
                        'ttv_label'      => $def['display'],
                        'ttv_code'       => $def['code'],
                        'ttv_value'      => $val,
                        'ttv_unit'       => $def['unit_display'] ?? ($def['unit'] ?? ''),
                        'status_info'    => $statusInfo,
                    ];
                }
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
        $ttvType = $parts[1] ?? '';
        $tgl = $parts[2] ?? '';
        $jam = $parts[3] ?? '';

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

        $payloads = PayloadAdapter::build('ObservationTTV', $noRawat, $patient);
        $found = null;
        foreach ($payloads as $p) {
            $meta = $p['_panel_persist_keys']['keys'] ?? [];
            if (($p['_panel_ttv_type'] ?? '') === $ttvType || ($meta['tgl_perawatan'] ?? '') === $tgl) {
                $found = $p;
                break;
            }
        }

        return ['success' => true, 'data' => $found ?? ($payloads[0] ?? null)];
    }

    public static function send(): array
    {
        return self::executeSend(
            '/Observation',
            function (array|string $itemKey): array {
                $noRawat = is_array($itemKey) ? ($itemKey['no_rawat'] ?? '') : (string) $itemKey;
                $ttvType = is_array($itemKey) ? ($itemKey['ttv_type'] ?? '') : '';
                $tgl = is_array($itemKey) ? ($itemKey['tgl_perawatan'] ?? '') : '';
                $jam = is_array($itemKey) ? ($itemKey['jam_rawat'] ?? '') : '';

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

                $payloads = PayloadAdapter::build('ObservationTTV', $noRawat, $patient);
                foreach ($payloads as $p) {
                    $typeMatch = ($p['_panel_ttv_type'] ?? '') === $ttvType;
                    $meta = $p['_panel_persist_keys']['keys'] ?? [];
                    $dateMatch = empty($tgl) || ($meta['tgl_perawatan'] ?? '') === $tgl;
                    if ($typeMatch && $dateMatch) {
                        return ['payload' => $p, 'meta' => $p['_panel_persist_keys'] ?? []];
                    }
                }
                throw new \RuntimeException("Payload Observation TTV {$ttvType} tidak ditemukan");
            },
            function (array|string $itemKey, string $satusehatId, array $outcome): void {
                $noRawat = is_array($itemKey) ? ($itemKey['no_rawat'] ?? '') : (string) $itemKey;
                $ttvType = is_array($itemKey) ? ($itemKey['ttv_type'] ?? '') : '';
                $tgl = is_array($itemKey) ? ($itemKey['tgl_perawatan'] ?? '') : '';
                $jam = is_array($itemKey) ? ($itemKey['jam_rawat'] ?? '') : '';
                $status = is_array($itemKey) ? ($itemKey['status'] ?? 'Ralan') : 'Ralan';

                $defs = ObservationTTVDictionary::getDefinitions();
                $def = $defs[$ttvType] ?? null;
                if (!$def) return;

                $db = Database::getMysql();
                $table = $def['state_table'];
                $idCol = $def['state_id_col'];

                $stmt = $db->prepare("
                    INSERT INTO {$table} (no_rawat, tgl_perawatan, jam_rawat, status, {$idCol})
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE {$idCol} = VALUES({$idCol})
                ");
                $stmt->execute([$noRawat, $tgl, $jam, $status, $satusehatId]);
            }
        );
    }
}
