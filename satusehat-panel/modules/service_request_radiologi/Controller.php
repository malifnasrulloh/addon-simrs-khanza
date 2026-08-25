<?php

declare(strict_types=1);

namespace SatusehatPanel\Modules\ServiceRequestRadiologi;

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
                pr.noorder, pr.no_rawat, pr.tgl_permintaan, pr.jam_permintaan, pr.diagnosa_klinis,
                rp.tgl_registrasi, rp.status_bayar, rp.status_lanjut,
                pj.no_rkm_medis, pj.nm_pasien, pj.no_ktp as nik_pasien,
                ppr.kd_jenis_prw, jpr.nm_perawatan,
                COALESCE(peg.nama, '') as nm_dokter,
                COALESCE(peg.no_ktp, '') as nik_dokter,
                IFNULL(sse.id_encounter, '') as id_encounter,
                IFNULL(ssr.id_servicerequest, '') as id_servicerequest
            FROM permintaan_radiologi pr
            JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat
            LEFT JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
            INNER JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = ppr.kd_jenis_prw
            LEFT JOIN satu_sehat_mapping_radiologi smr ON smr.kd_jenis_prw = jpr.kd_jenis_prw
            LEFT JOIN pegawai peg ON peg.nik = rp.kd_dokter
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            LEFT JOIN satu_sehat_servicerequest_radiologi ssr ON ssr.noorder = ppr.noorder AND ssr.kd_jenis_prw = ppr.kd_jenis_prw
            {$where}
            ORDER BY pr.tgl_permintaan DESC, pr.jam_permintaan DESC
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
                $localState = self::getLocalState('servicerequest_radiologi_state', $compositeKey, $r['no_rawat'], 'ServiceRequest');

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

                $statusInfo = self::evaluateStatus($r, $r['id_servicerequest'], $localState, $blockers);

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
            SELECT pr.*, rp.tgl_registrasi, rp.jam_reg, rp.status_lanjut, rp.kd_dokter,
                   pj.nm_pasien, pj.no_ktp, pj.no_rkm_medis,
                   ppr.kd_jenis_prw, jpr.nm_perawatan,
                   smr.code, smr.system, smr.display,
                   peg.nama as nama,
                   IFNULL(sse.id_encounter, '') as id_encounter,
                   IFNULL(ssr.id_servicerequest, '') as id_servicerequest
            FROM permintaan_radiologi pr
            JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat
            LEFT JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
            INNER JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = ppr.kd_jenis_prw
            LEFT JOIN satu_sehat_mapping_radiologi smr ON smr.kd_jenis_prw = jpr.kd_jenis_prw
            LEFT JOIN pegawai peg ON peg.nik = rp.kd_dokter
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            LEFT JOIN satu_sehat_servicerequest_radiologi ssr ON ssr.noorder = ppr.noorder AND ssr.kd_jenis_prw = ppr.kd_jenis_prw
            WHERE pr.no_rawat = ? AND pr.noorder = ? AND ppr.kd_jenis_prw = ?
            LIMIT 1
        ");
        $stmt->execute([$noRawat, $noorder, $kdJenisPrw]);
        $row = $stmt->fetch();
        if (!$row) return ['success' => false, 'error' => 'Permintaan radiologi tidak ditemukan'];

        $orgId = (string) \SatusehatPanel\Core\Config::get('satusehat.org_id', '');
        $ihs = self::resolveIhs($db, $row['no_ktp'] ?? '', $row['kd_dokter'] ?? '');
        $payload = \SatuSehatPayloadBuilder::serviceRequestRadiologi(
            $row, $ihs['pasien'], $ihs['dokter'], $orgId, $row['id_servicerequest'] ?: ''
        );

        return ['success' => true, 'data' => $payload];
    }

    public static function send(): array
    {
        return self::executeSend(
            '/ServiceRequest',
            function (array|string $itemKey): array {
                $noRawat = is_array($itemKey) ? ($itemKey['no_rawat'] ?? '') : (string) $itemKey;
                $noorder = is_array($itemKey) ? ($itemKey['noorder'] ?? '') : '';
                $kdJenisPrw = is_array($itemKey) ? ($itemKey['kd_jenis_prw'] ?? '') : '';

                $db = Database::getMysql();
                $stmt = $db->prepare("
                    SELECT pr.*, rp.tgl_registrasi, rp.jam_reg, rp.status_lanjut, rp.kd_dokter,
                           pj.nm_pasien, pj.no_ktp, pj.no_rkm_medis,
                           ppr.kd_jenis_prw, jpr.nm_perawatan,
                           smr.code, smr.system, smr.display,
                           peg.nama as nama,
                           IFNULL(sse.id_encounter, '') as id_encounter,
                           IFNULL(ssr.id_servicerequest, '') as id_servicerequest
                    FROM permintaan_radiologi pr
                    JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat
                    LEFT JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
                    INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
                    INNER JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = ppr.kd_jenis_prw
                    LEFT JOIN satu_sehat_mapping_radiologi smr ON smr.kd_jenis_prw = jpr.kd_jenis_prw
                    LEFT JOIN pegawai peg ON peg.nik = rp.kd_dokter
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_servicerequest_radiologi ssr ON ssr.noorder = ppr.noorder AND ssr.kd_jenis_prw = ppr.kd_jenis_prw
                    WHERE pr.no_rawat = ? AND pr.noorder = ? AND ppr.kd_jenis_prw = ?
                    LIMIT 1
                ");
                $stmt->execute([$noRawat, $noorder, $kdJenisPrw]);
                $row = $stmt->fetch();
                if (!$row) throw new \RuntimeException("Permintaan radiologi {$noorder} tidak ditemukan");

                $orgId = (string) \SatusehatPanel\Core\Config::get('satusehat.org_id', '');
                $ihs = self::resolveIhs($db, $row['no_ktp'] ?? '', $row['kd_dokter'] ?? '');
                $payload = \SatuSehatPayloadBuilder::serviceRequestRadiologi(
                    $row, $ihs['pasien'], $ihs['dokter'], $orgId, $row['id_servicerequest'] ?: ''
                );

                return ['payload' => $payload, 'meta' => ['noorder' => $noorder, 'kd_jenis_prw' => $kdJenisPrw]];
            },
            function (array|string $itemKey, string $satusehatId, array $outcome): void {
                $noorder = is_array($itemKey) ? ($itemKey['noorder'] ?? '') : '';
                $kdJenisPrw = is_array($itemKey) ? ($itemKey['kd_jenis_prw'] ?? '') : '';
                $db = Database::getMysql();

                $stmt = $db->prepare("
                    INSERT INTO satu_sehat_servicerequest_radiologi (noorder, kd_jenis_prw, id_servicerequest)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE id_servicerequest = VALUES(id_servicerequest)
                ");
                $stmt->execute([$noorder, $kdJenisPrw, $satusehatId]);
            }
        );
    }
}
