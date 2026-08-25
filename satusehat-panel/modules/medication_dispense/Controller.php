<?php

declare(strict_types=1);

namespace SatusehatPanel\Modules\MedicationDispense;

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
            $where .= " AND (dpo.no_rawat LIKE ? OR pj.nm_pasien LIKE ? OR db.nama_brng LIKE ? OR dpo.no_batch LIKE ?)";
            $s = "%{$f['search']}%";
            array_push($params, $s, $s, $s, $s);
        }

        $sql = "
            SELECT 
                dpo.no_rawat, dpo.tgl_perawatan, dpo.jam, dpo.kode_brng, dpo.no_batch, dpo.no_faktur,
                dpo.jml, dpo.status as status_pemberian,
                db.nama_brng, IFNULL(ap.aturan, '') as aturan,
                rp.tgl_registrasi, rp.status_bayar,
                pj.no_rkm_medis, pj.nm_pasien, pj.no_ktp as nik_pasien,
                peg.nama as nm_dokter, peg.no_ktp as nik_dokter,
                IFNULL(sse.id_encounter, '') as id_encounter,
                IFNULL(ssm.id_medication, '') as id_medication,
                IFNULL(ssml.id_lokasi_satusehat, '') as id_lokasi_satusehat,
                IFNULL(ssmr.id_medicationrequest, '') as id_medicationrequest,
                IFNULL(ssmd.id_medicationdispanse, '') as id_medicationdispense
            FROM detail_pemberian_obat dpo
            JOIN reg_periksa rp ON rp.no_rawat = dpo.no_rawat
            LEFT JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            JOIN databarang db ON db.kode_brng = dpo.kode_brng
            LEFT JOIN aturan_pakai ap ON ap.no_rawat = dpo.no_rawat AND ap.tgl_perawatan = dpo.tgl_perawatan AND ap.jam = dpo.jam AND ap.kode_brng = dpo.kode_brng
            LEFT JOIN resep_obat ro ON ro.no_rawat = dpo.no_rawat AND ro.tgl_perawatan = dpo.tgl_perawatan AND ro.jam = dpo.jam
            LEFT JOIN pegawai peg ON peg.nik = ro.kd_dokter
            LEFT JOIN bangsal bm ON bm.kd_bangsal = dpo.kd_bangsal
            LEFT JOIN satu_sehat_mapping_lokasi_depo_farmasi ssml ON ssml.kd_bangsal = bm.kd_bangsal
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            LEFT JOIN satu_sehat_medication ssm ON ssm.kode_brng = dpo.kode_brng
            LEFT JOIN satu_sehat_medicationrequest ssmr ON ssmr.no_resep = ro.no_resep AND ssmr.kode_brng = dpo.kode_brng
            LEFT JOIN satu_sehat_medicationdispense ssmd ON ssmd.no_rawat = dpo.no_rawat 
                AND ssmd.tgl_perawatan = dpo.tgl_perawatan AND ssmd.jam = dpo.jam 
                AND ssmd.kode_brng = dpo.kode_brng AND ssmd.no_batch = dpo.no_batch AND ssmd.no_faktur = dpo.no_faktur
            {$where}
            ORDER BY dpo.tgl_perawatan DESC, dpo.jam DESC
            LIMIT {$f['per_page']} OFFSET {$f['offset']}
        ";

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll() ?: [];

            $sqlite = Database::getSqlite();
            $items = [];

            foreach ($rows as $r) {
                $compositeKey = $r['no_rawat'] . '_' . $r['tgl_perawatan'] . '_' . $r['jam'] . '_' . $r['kode_brng'] . '_' . $r['no_batch'] . '_' . $r['no_faktur'];
                $localState = self::getLocalState('medication_dispense_state', $compositeKey, $r['no_rawat'], 'MedicationDispense');

                $blockers = [];
                if (empty($r['id_encounter'])) {
                    $blockers[] = 'encounter';
                }
                if (empty($r['id_medication'])) {
                    $blockers[] = 'Master Obat KFA belum terkirim';
                }
                if (empty($r['id_medicationrequest'])) {
                    $blockers[] = 'MedicationRequest resep belum terkirim';
                }
                if (empty($r['nik_pasien']) || strlen($r['nik_pasien']) < 16) {
                    $blockers[] = 'ihs_pasien';
                }
                if (empty($r['id_lokasi_satusehat'])) {
                    $blockers[] = 'Depo farmasi belum dipetakan ke Lokasi SATUSEHAT';
                }
                if (!str_contains(strtolower($r['status_bayar']), 'sudah')) {
                    $blockers[] = 'billing';
                }

                $statusInfo = self::evaluateStatus($r, $r['id_medicationdispense'], $localState, $blockers);

                if ($f['status_sync'] !== 'all' && $statusInfo['status'] !== $f['status_sync']) {
                    continue;
                }

                $items[] = array_merge($r, [
                    'item_key'     => [
                        'no_rawat'      => $r['no_rawat'],
                        'tgl_perawatan' => $r['tgl_perawatan'],
                        'jam'           => $r['jam'],
                        'kode_brng'     => $r['kode_brng'],
                        'no_batch'      => $r['no_batch'],
                        'no_faktur'     => $r['no_faktur'],
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
        $kodeBrng = $parts[1] ?? '';
        $batch = $parts[2] ?? '';

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

        $payloads = PayloadAdapter::build('MedicationDispense', $noRawat, $patient);
        $found = null;
        foreach ($payloads as $p) {
            $meta = $p['_panel_persist_keys']['keys'] ?? [];
            if (($meta['kode_brng'] ?? '') === $kodeBrng || ($meta['no_batch'] ?? '') === $batch) {
                $found = $p;
                break;
            }
        }

        return ['success' => true, 'data' => $found ?? ($payloads[0] ?? null)];
    }

    public static function send(): array
    {
        return self::executeSend(
            '/MedicationDispense',
            function (array|string $itemKey): array {
                $noRawat = is_array($itemKey) ? ($itemKey['no_rawat'] ?? '') : (string) $itemKey;
                $kodeBrng = is_array($itemKey) ? ($itemKey['kode_brng'] ?? '') : '';
                $batch = is_array($itemKey) ? ($itemKey['no_batch'] ?? '') : '';

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

                $payloads = PayloadAdapter::build('MedicationDispense', $noRawat, $patient);
                if (!empty($payloads)) {
                    foreach ($payloads as $p) {
                        $meta = $p['_panel_persist_keys']['keys'] ?? [];
                        if (($meta['kode_brng'] ?? '') === $kodeBrng || ($meta['no_batch'] ?? '') === $batch) {
                            return ['payload' => $p, 'meta' => $p['_panel_persist_keys'] ?? []];
                        }
                    }
                    return ['payload' => $payloads[0], 'meta' => $payloads[0]['_panel_persist_keys'] ?? []];
                }
                throw new \RuntimeException("Payload MedicationDispense {$kodeBrng} tidak ditemukan");
            },
            function (array|string $itemKey, string $satusehatId, array $outcome): void {
                $noRawat = is_array($itemKey) ? ($itemKey['no_rawat'] ?? '') : (string) $itemKey;
                $tgl = is_array($itemKey) ? ($itemKey['tgl_perawatan'] ?? '') : '';
                $jam = is_array($itemKey) ? ($itemKey['jam'] ?? '') : '';
                $kodeBrng = is_array($itemKey) ? ($itemKey['kode_brng'] ?? '') : '';
                $batch = is_array($itemKey) ? ($itemKey['no_batch'] ?? '') : '';
                $faktur = is_array($itemKey) ? ($itemKey['no_faktur'] ?? '') : '';

                $db = Database::getMysql();
                $stmt = $db->prepare("
                    INSERT INTO satu_sehat_medicationdispense (no_rawat, tgl_perawatan, jam, kode_brng, no_batch, no_faktur, id_medicationdispanse)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE id_medicationdispanse = VALUES(id_medicationdispanse)
                ");
                $stmt->execute([$noRawat, $tgl, $jam, $kodeBrng, $batch, $faktur, $satusehatId]);
            }
        );
    }
}
