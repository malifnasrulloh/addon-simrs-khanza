<?php

declare(strict_types=1);

namespace SatusehatPanel\Modules\ObservationLab;

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
            $where .= " AND (pl.noorder LIKE ? OR pl.no_rawat LIKE ? OR pj.nm_pasien LIKE ? OR tl.Pemeriksaan LIKE ?)";
            $s = "%{$f['search']}%";
            array_push($params, $s, $s, $s, $s);
        }

        $sql = "
            SELECT 
                pl.noorder, pl.no_rawat, pl.tgl_hasil, pl.jam_hasil,
                rp.tgl_registrasi, rp.status_bayar, rp.status_lanjut,
                pj.no_rkm_medis, pj.nm_pasien, pj.no_ktp as nik_pasien,
                pdpl.kd_jenis_prw, tl.id_template, tl.Pemeriksaan, tl.satuan,
                dpl.nilai, dpl.nilai_rujukan, dpl.keterangan,
                sml.code as loinc_code, sml.display as loinc_display,
                COALESCE(peg.nama, '') as nm_dokter,
                COALESCE(peg.no_ktp, '') as nik_dokter,
                IFNULL(sse.id_encounter, '') as id_encounter,
                IFNULL(sssp.id_specimen, '') as id_specimen,
                IFNULL(sso.id_observation, '') as id_observation
            FROM permintaan_lab pl
            JOIN reg_periksa rp ON rp.no_rawat = pl.no_rawat
            LEFT JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            INNER JOIN permintaan_detail_permintaan_lab pdpl ON pdpl.noorder = pl.noorder
            INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
            LEFT JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
            INNER JOIN periksa_lab per ON per.no_rawat = pl.no_rawat AND per.tgl_periksa = pl.tgl_hasil AND per.jam = pl.jam_hasil AND per.kd_jenis_prw = pdpl.kd_jenis_prw
            INNER JOIN detail_periksa_lab dpl ON dpl.no_rawat = per.no_rawat AND dpl.tgl_periksa = per.tgl_periksa AND dpl.jam = per.jam AND dpl.id_template = pdpl.id_template AND dpl.kd_jenis_prw = pdpl.kd_jenis_prw
            LEFT JOIN pegawai peg ON peg.nik = per.kd_dokter
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            LEFT JOIN satu_sehat_specimen_lab sssp ON sssp.noorder = pdpl.noorder AND sssp.id_template = pdpl.id_template AND sssp.kd_jenis_prw = pdpl.kd_jenis_prw
            LEFT JOIN satu_sehat_observation_lab sso ON sso.noorder = pdpl.noorder AND sso.id_template = pdpl.id_template AND sso.kd_jenis_prw = pdpl.kd_jenis_prw
            {$where}
            ORDER BY pl.tgl_hasil DESC, pl.jam_hasil DESC
            LIMIT {$f['per_page']} OFFSET {$f['offset']}
        ";

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll() ?: [];

            $sqlite = Database::getSqlite();
            $items = [];

            foreach ($rows as $r) {
                $compositeKey = $r['noorder'] . '_' . $r['kd_jenis_prw'] . '_' . $r['id_template'];
                $localState = self::getLocalState('observation_lab_pk_state', $compositeKey, $r['no_rawat'], 'Observation');

                $blockers = [];
                if (empty($r['id_encounter'])) {
                    $blockers[] = 'encounter';
                }
                if (empty($r['id_specimen'])) {
                    $blockers[] = 'Specimen Lab belum terkirim';
                }
                if (empty($r['loinc_code'])) {
                    $blockers[] = 'Template belum dipetakan ke LOINC';
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

                $statusInfo = self::evaluateStatus($r, $r['id_observation'], $localState, $blockers);

                if ($f['status_sync'] !== 'all' && $statusInfo['status'] !== $f['status_sync']) {
                    continue;
                }

                $items[] = array_merge($r, [
                    'item_key'     => [
                        'no_rawat'     => $r['no_rawat'],
                        'noorder'      => $r['noorder'],
                        'kd_jenis_prw' => $r['kd_jenis_prw'],
                        'id_template'  => $r['id_template'],
                        'variant'      => 'pk',
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
        $idTemplate = (int) ($parts[3] ?? 0);

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

        $payloads = PayloadAdapter::build('Observation', $noRawat, $patient);
        $found = null;
        foreach ($payloads as $p) {
            $meta = $p['_panel_persist_keys']['keys'] ?? [];
            if (($meta['noorder'] ?? '') === $noorder || ($meta['id_template'] ?? 0) === $idTemplate) {
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
                $noorder = is_array($itemKey) ? ($itemKey['noorder'] ?? '') : '';
                $idTemplate = is_array($itemKey) ? (int) ($itemKey['id_template'] ?? 0) : 0;

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

                $payloads = PayloadAdapter::build('Observation', $noRawat, $patient);
                if (!empty($payloads)) {
                    foreach ($payloads as $p) {
                        $meta = $p['_panel_persist_keys']['keys'] ?? [];
                        if (($meta['noorder'] ?? '') === $noorder || ($meta['id_template'] ?? 0) === $idTemplate) {
                            return ['payload' => $p, 'meta' => $p['_panel_persist_keys'] ?? []];
                        }
                    }
                    return ['payload' => $payloads[0], 'meta' => $payloads[0]['_panel_persist_keys'] ?? []];
                }
                throw new \RuntimeException("Payload Observation Lab {$noorder} tidak ditemukan");
            },
            function (array|string $itemKey, string $satusehatId, array $outcome): void {
                $noorder = is_array($itemKey) ? ($itemKey['noorder'] ?? '') : '';
                $kdJenisPrw = is_array($itemKey) ? ($itemKey['kd_jenis_prw'] ?? '') : '';
                $idTemplate = is_array($itemKey) ? ($itemKey['id_template'] ?? '') : '';
                $db = Database::getMysql();

                $stmt = $db->prepare("
                    INSERT INTO satu_sehat_observation_lab (noorder, kd_jenis_prw, id_template, id_observation)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE id_observation = VALUES(id_observation)
                ");
                $stmt->execute([$noorder, $kdJenisPrw, $idTemplate, $satusehatId]);
            }
        );
    }
}
