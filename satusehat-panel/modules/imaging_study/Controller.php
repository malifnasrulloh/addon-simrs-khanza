<?php

declare(strict_types=1);

namespace SatusehatPanel\Modules\ImagingStudy;

use SatusehatPanel\Core\BaseModuleController;
use SatusehatPanel\Core\Database;
use SatusehatPanel\Core\Config;
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
            $where .= " AND (prad.no_rawat LIKE ? OR pj.nm_pasien LIKE ? OR jpr.nm_perawatan LIKE ? OR ssi.acsn LIKE ?)";
            $s = "%{$f['search']}%";
            array_push($params, $s, $s, $s, $s);
        }

        $sql = "
            SELECT 
                prad.no_rawat, prad.tgl_periksa, prad.jam as jam_periksa, prad.kd_jenis_prw,
                rp.tgl_registrasi, rp.status_bayar,
                pj.no_rkm_medis, pj.nm_pasien, pj.no_ktp as nik_pasien,
                jpr.nm_perawatan, pr.noorder,
                IFNULL(ssi.id_servicerequest, '') as id_servicerequest,
                IFNULL(ssi.id_imaging, '') as id_imaging,
                IFNULL(ssi.acsn, '') as acsn
            FROM periksa_radiologi prad
            JOIN reg_periksa rp ON rp.no_rawat = prad.no_rawat
            LEFT JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = prad.kd_jenis_prw
            LEFT JOIN permintaan_radiologi pr ON pr.no_rawat = prad.no_rawat AND pr.tgl_hasil = prad.tgl_periksa AND pr.jam_hasil = prad.jam
            LEFT JOIN satu_sehat_imagingstudy_radiologi ssi ON ssi.noorder = pr.noorder AND ssi.kd_jenis_prw = prad.kd_jenis_prw
            {$where}
            ORDER BY prad.tgl_periksa DESC, prad.jam DESC
            LIMIT {$f['per_page']} OFFSET {$f['offset']}
        ";

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll() ?: [];

            $items = [];

            foreach ($rows as $r) {
                $blockers = [];
                if (empty($r['id_servicerequest'])) {
                    $blockers[] = 'ServiceRequest Radiologi belum terkirim';
                }
                if (empty($r['nik_pasien']) || strlen($r['nik_pasien']) < 16) {
                    $blockers[] = 'ihs_pasien';
                }
                if (!str_contains(strtolower($r['status_bayar']), 'sudah')) {
                    $blockers[] = 'billing';
                }

                $statusInfo = self::evaluateStatus($r, $r['id_imaging'], null, $blockers);

                if ($f['status_sync'] !== 'all' && $statusInfo['status'] !== $f['status_sync']) {
                    continue;
                }

                $items[] = array_merge($r, [
                    'item_key'     => [
                        'no_rawat'      => $r['no_rawat'],
                        'kd_jenis_prw'  => $r['kd_jenis_prw'],
                        'tgl_periksa'   => $r['tgl_periksa'],
                        'jam_periksa'   => $r['jam_periksa'],
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
        $kdJenisPrw = $parts[1] ?? '';

        $db = Database::getMysql();
        $stmt = $db->prepare("
            SELECT prad.*, pj.nm_pasien, pj.no_ktp as nik_pasien, pj.no_rkm_medis,
                   jpr.nm_perawatan, pr.noorder, pr.tgl_permintaan, pr.jam_permintaan,
                   IFNULL(ssi.id_servicerequest, '') as id_servicerequest,
                   IFNULL(ssi.id_imaging, '') as id_imaging,
                   IFNULL(ssi.acsn, '') as acsn
            FROM periksa_radiologi prad
            JOIN reg_periksa rp ON rp.no_rawat = prad.no_rawat
            LEFT JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = prad.kd_jenis_prw
            LEFT JOIN permintaan_radiologi pr ON pr.no_rawat = prad.no_rawat AND pr.tgl_hasil = prad.tgl_periksa AND pr.jam_hasil = prad.jam
            LEFT JOIN satu_sehat_imagingstudy_radiologi ssi ON ssi.noorder = pr.noorder AND ssi.kd_jenis_prw = prad.kd_jenis_prw
            WHERE prad.no_rawat = ?
            LIMIT 1
        ");
        $stmt->execute([$noRawat]);
        $row = $stmt->fetch();
        if (!$row) return ['success' => false, 'error' => 'Data radiologi tidak ditemukan'];

        $orgId = (string) Config::get('satusehat.org_id', '');
        $acsn = $row['acsn'] ?: ($row['noorder'] . '.' . $row['kd_jenis_prw']);
        $payload = [
            'resourceType' => 'ImagingStudy',
            'identifier' => [
                [
                    'system' => 'http://sys-ids.kemkes.go.id/acsn/' . $orgId,
                    'use'    => 'official',
                    'value'  => $acsn
                ]
            ],
            'status' => 'available',
            'modality' => [
                [
                    'system' => 'http://dicom.nema.org/resources/ontology/DCM',
                    'code'   => 'DX',
                    'display'=> $row['nm_perawatan']
                ]
            ],
            'subject' => [
                'reference' => 'Patient/' . ($row['nik_pasien'] ? 'IHS-' . $row['nik_pasien'] : 'P-PASIEN-IHS'),
                'display'   => $row['nm_pasien']
            ],
            'started' => $row['tgl_periksa'] . 'T' . ($row['jam'] ?? '00:00:00') . '+07:00',
            'basedOn' => [
                [
                    'reference' => 'ServiceRequest/' . ($row['id_servicerequest'] ?: 'SR-ID')
                ]
            ],
            'description' => $row['nm_perawatan']
        ];

        if (!empty($row['id_imaging'])) {
            $payload['id'] = $row['id_imaging'];
        }

        return ['success' => true, 'data' => $payload];
    }

    public static function send(): array
    {
        return self::executeSend(
            '/ImagingStudy',
            function (array|string $itemKey): array {
                $noRawat = is_array($itemKey) ? ($itemKey['no_rawat'] ?? '') : (string) $itemKey;
                $kdJenisPrw = is_array($itemKey) ? ($itemKey['kd_jenis_prw'] ?? '') : '';

                $db = Database::getMysql();
                $stmt = $db->prepare("
                    SELECT prad.*, pj.nm_pasien, pj.no_ktp as nik_pasien, pj.no_rkm_medis,
                           jpr.nm_perawatan, pr.noorder, pr.tgl_permintaan, pr.jam_permintaan,
                           IFNULL(ssi.id_servicerequest, '') as id_servicerequest,
                           IFNULL(ssi.id_imaging, '') as id_imaging,
                           IFNULL(ssi.acsn, '') as acsn
                    FROM periksa_radiologi prad
                    JOIN reg_periksa rp ON rp.no_rawat = prad.no_rawat
                    LEFT JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
                    JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = prad.kd_jenis_prw
                    LEFT JOIN permintaan_radiologi pr ON pr.no_rawat = prad.no_rawat AND pr.tgl_hasil = prad.tgl_periksa AND pr.jam_hasil = prad.jam
                    LEFT JOIN satu_sehat_imagingstudy_radiologi ssi ON ssi.noorder = pr.noorder AND ssi.kd_jenis_prw = prad.kd_jenis_prw
                    WHERE prad.no_rawat = ?
                    LIMIT 1
                ");
                $stmt->execute([$noRawat]);
                $row = $stmt->fetch();
                if (!$row) throw new \RuntimeException("Data radiologi {$noRawat} tidak ditemukan");

                $orgId = (string) Config::get('satusehat.org_id', '');
                $acsn = $row['acsn'] ?: ($row['noorder'] . '.' . ($kdJenisPrw ?: $row['kd_jenis_prw']));
                $payload = [
                    'resourceType' => 'ImagingStudy',
                    'identifier' => [
                        [
                            'system' => 'http://sys-ids.kemkes.go.id/acsn/' . $orgId,
                            'use'    => 'official',
                            'value'  => $acsn
                        ]
                    ],
                    'status' => 'available',
                    'modality' => [
                        [
                            'system' => 'http://dicom.nema.org/resources/ontology/DCM',
                            'code'   => 'DX',
                            'display'=> $row['nm_perawatan']
                        ]
                    ],
                    'subject' => [
                        'reference' => 'Patient/' . ($row['nik_pasien'] ? 'IHS-' . $row['nik_pasien'] : 'P-PASIEN-IHS'),
                        'display'   => $row['nm_pasien']
                    ],
                    'started' => $row['tgl_periksa'] . 'T' . ($row['jam'] ?? '00:00:00') . '+07:00',
                    'basedOn' => [
                        [
                            'reference' => 'ServiceRequest/' . ($row['id_servicerequest'] ?: 'SR-ID')
                        ]
                    ],
                    'description' => $row['nm_perawatan']
                ];

                if (!empty($row['id_imaging'])) {
                    $payload['id'] = $row['id_imaging'];
                }

                return ['payload' => $payload, 'meta' => ['no_rawat' => $noRawat, 'kd_jenis_prw' => $kdJenisPrw]];
            },
            function (array|string $itemKey, string $satusehatId, array $outcome): void {
                $noRawat = is_array($itemKey) ? ($itemKey['no_rawat'] ?? '') : (string) $itemKey;
                $kdJenisPrw = is_array($itemKey) ? ($itemKey['kd_jenis_prw'] ?? '') : '';
                $db = Database::getMysql();

                // Find noorder
                $stmtOrd = $db->prepare("SELECT noorder FROM permintaan_radiologi WHERE no_rawat = ? LIMIT 1");
                $stmtOrd->execute([$noRawat]);
                $noorder = (string)($stmtOrd->fetchColumn() ?: '');

                $stmt = $db->prepare("
                    INSERT INTO satu_sehat_imagingstudy_radiologi (noorder, kd_jenis_prw, id_imaging)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE id_imaging = VALUES(id_imaging)
                ");
                $stmt->execute([$noorder, $kdJenisPrw, $satusehatId]);
            }
        );
    }
}
