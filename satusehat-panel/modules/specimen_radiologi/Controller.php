<?php

declare(strict_types=1);

namespace SatusehatPanel\Modules\SpecimenRadiologi;

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
            $where .= " AND (pr.noorder LIKE ? OR pr.no_rawat LIKE ? OR pj.nm_pasien LIKE ? OR jpr.nm_perawatan LIKE ?)";
            $s = "%{$f['search']}%";
            array_push($params, $s, $s, $s, $s);
        }

        $sql = "
            SELECT 
                pr.noorder, pr.no_rawat, pr.tgl_sampel, pr.jam_sampel,
                rp.tgl_registrasi, rp.status_bayar, rp.status_lanjut,
                pj.no_rkm_medis, pj.nm_pasien, pj.no_ktp as nik_pasien,
                ppr.kd_jenis_prw, jpr.nm_perawatan,
                smr.sampel_code, smr.sampel_display,
                IFNULL(sse.id_encounter, '') as id_encounter,
                IFNULL(ssr.id_servicerequest, '') as id_servicerequest,
                IFNULL(sssp.id_specimen, '') as id_specimen
            FROM permintaan_radiologi pr
            JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat
            LEFT JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
            INNER JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = ppr.kd_jenis_prw
            LEFT JOIN satu_sehat_mapping_radiologi smr ON smr.kd_jenis_prw = jpr.kd_jenis_prw
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            LEFT JOIN satu_sehat_servicerequest_radiologi ssr ON ssr.noorder = ppr.noorder AND ssr.kd_jenis_prw = ppr.kd_jenis_prw
            LEFT JOIN satu_sehat_specimen_radiologi sssp ON sssp.noorder = ppr.noorder AND sssp.kd_jenis_prw = ppr.kd_jenis_prw
            {$where}
            ORDER BY pr.tgl_sampel DESC, pr.jam_sampel DESC
            LIMIT {$f['per_page']} OFFSET {$f['offset']}
        ";

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll() ?: [];

            $sqlite = Database::getSqlite();
            $items = [];

            foreach ($rows as $r) {
                $compositeKey = $r['noorder'] . '_' . $r['kd_jenis_prw'];
                $localState = self::getLocalState('specimen_radiologi_state', $compositeKey, $r['no_rawat'], 'Specimen');

                $blockers = [];
                if (empty($r['id_encounter'])) {
                    $blockers[] = 'encounter';
                }
                if (empty($r['id_servicerequest'])) {
                    $blockers[] = 'ServiceRequest Radiologi belum terkirim';
                }
                if (empty($r['sampel_code'])) {
                    $blockers[] = 'Kode sampel spesimen belum dipetakan';
                }
                if (empty($r['nik_pasien']) || strlen($r['nik_pasien']) < 16) {
                    $blockers[] = 'ihs_pasien';
                }
                if (!str_contains(strtolower($r['status_bayar']), 'sudah')) {
                    $blockers[] = 'billing';
                }

                $statusInfo = self::evaluateStatus($r, $r['id_specimen'], $localState, $blockers);

                if ($f['status_sync'] !== 'all' && $statusInfo['status'] !== $f['status_sync']) {
                    continue;
                }

                $items[] = array_merge($r, [
                    'item_key'     => [
                        'no_rawat'     => $r['no_rawat'],
                        'noorder'      => $r['noorder'],
                        'kd_jenis_prw' => $r['kd_jenis_prw'],
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
        $noorder = $parts[1] ?? '';
        $kdJenisPrw = $parts[2] ?? '';

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

        $payloads = PayloadAdapter::build('Specimen', $noRawat, $patient);
        $found = null;
        foreach ($payloads as $p) {
            $meta = $p['_panel_persist_keys']['keys'] ?? [];
            if (($meta['noorder'] ?? '') === $noorder || ($meta['kd_jenis_prw'] ?? '') === $kdJenisPrw) {
                $found = $p;
                break;
            }
        }

        return ['success' => true, 'data' => $found ?? ($payloads[0] ?? null)];
    }

    public static function send(): array
    {
        return self::executeSend(
            '/Specimen',
            function (array|string $itemKey): array {
                $noRawat = is_array($itemKey) ? ($itemKey['no_rawat'] ?? '') : (string) $itemKey;
                $noorder = is_array($itemKey) ? ($itemKey['noorder'] ?? '') : '';
                $kdJenisPrw = is_array($itemKey) ? ($itemKey['kd_jenis_prw'] ?? '') : '';

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

                $payloads = PayloadAdapter::build('Specimen', $noRawat, $patient);
                if (!empty($payloads)) {
                    foreach ($payloads as $p) {
                        $meta = $p['_panel_persist_keys']['keys'] ?? [];
                        if (($meta['noorder'] ?? '') === $noorder || ($meta['kd_jenis_prw'] ?? '') === $kdJenisPrw) {
                            return ['payload' => $p, 'meta' => $p['_panel_persist_keys'] ?? []];
                        }
                    }
                    return ['payload' => $payloads[0], 'meta' => $payloads[0]['_panel_persist_keys'] ?? []];
                }
                throw new \RuntimeException("Payload Specimen Radiologi {$noorder} tidak ditemukan");
            },
            function (array|string $itemKey, string $satusehatId, array $outcome): void {
                $noorder = is_array($itemKey) ? ($itemKey['noorder'] ?? '') : '';
                $kdJenisPrw = is_array($itemKey) ? ($itemKey['kd_jenis_prw'] ?? '') : '';
                $db = Database::getMysql();

                $stmt = $db->prepare("
                    INSERT INTO satu_sehat_specimen_radiologi (noorder, kd_jenis_prw, id_specimen)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE id_specimen = VALUES(id_specimen)
                ");
                $stmt->execute([$noorder, $kdJenisPrw, $satusehatId]);
            }
        );
    }
}
