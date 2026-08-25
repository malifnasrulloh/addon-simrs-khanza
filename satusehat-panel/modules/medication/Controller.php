<?php

declare(strict_types=1);

namespace SatusehatPanel\Modules\Medication;

use SatusehatPanel\Core\BaseModuleController;
use SatusehatPanel\Core\Database;
use SatusehatPanel\Core\Config;

class Controller extends BaseModuleController
{
    public static function list(): array
    {
        $f = self::parseFilters();
        $db = Database::getMysql();

        $where = "WHERE 1=1";
        $params = [];

        if ($f['search'] !== '') {
            $where .= " AND (db.kode_brng LIKE ? OR db.nama_brng LIKE ? OR ssmo.obat_code LIKE ? OR ssmo.obat_display LIKE ?)";
            $s = "%{$f['search']}%";
            array_push($params, $s, $s, $s, $s);
        }

        $sql = "
            SELECT 
                db.kode_brng, db.nama_brng,
                ssmo.obat_code, ssmo.obat_system, ssmo.obat_display,
                ssmo.form_code, ssmo.form_system, ssmo.form_display,
                ssmo.route_code, ssmo.route_system, ssmo.route_display,
                ssmo.numerator_code, ssmo.numerator_system,
                ssmo.denominator_code, ssmo.denominator_system,
                IFNULL(ssm.id_medication, '') as id_medication
            FROM databarang db
            LEFT JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = db.kode_brng
            LEFT JOIN satu_sehat_medication ssm ON ssm.kode_brng = db.kode_brng
            {$where}
            ORDER BY db.kode_brng ASC
            LIMIT {$f['per_page']} OFFSET {$f['offset']}
        ";

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll() ?: [];

            $sqlite = Database::getSqlite();
            $items = [];

            foreach ($rows as $r) {
                $localState = self::getLocalState('medication_state', $r['kode_brng'], $r['kode_brng'], 'Medication');

                $blockers = [];
                if (empty($r['obat_code'])) {
                    $blockers[] = 'Belum dipetakan ke kode KFA SATUSEHAT';
                }

                $statusInfo = self::evaluateStatus($r, $r['id_medication'], $localState, $blockers);

                if ($f['status_sync'] !== 'all' && $statusInfo['status'] !== $f['status_sync']) {
                    continue;
                }

                $items[] = array_merge($r, [
                    'item_key'    => $r['kode_brng'],
                    'status_info' => $statusInfo,
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
            SELECT db.kode_brng, db.nama_brng,
                   ssmo.obat_code, ssmo.obat_system, ssmo.obat_display,
                   ssmo.form_code, ssmo.form_system, ssmo.form_display,
                   ssmo.route_code, ssmo.route_system, ssmo.route_display,
                   ssmo.numerator_code, ssmo.numerator_system,
                   ssmo.denominator_code, ssmo.denominator_system,
                   IFNULL(ssm.id_medication, '') as id_medication
            FROM databarang db
            LEFT JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = db.kode_brng
            LEFT JOIN satu_sehat_medication ssm ON ssm.kode_brng = db.kode_brng
            WHERE db.kode_brng = ?
        ");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if (!$row) return ['success' => false, 'error' => 'Obat tidak ditemukan'];

        $orgId = (string) Config::get('satusehat.org_id', '');
        $payload = \SatuSehatPayloadBuilder::medication($orgId, $row, $row['id_medication'] ?? null);

        return ['success' => true, 'data' => $payload];
    }

    public static function send(): array
    {
        return self::executeSend(
            '/Medication',
            function (array|string $itemKey): array {
                $kodeBrng = is_array($itemKey) ? ($itemKey['kode_brng'] ?? '') : (string) $itemKey;
                $db = Database::getMysql();
                $stmt = $db->prepare("
                    SELECT db.kode_brng, db.nama_brng,
                           ssmo.obat_code, ssmo.obat_system, ssmo.obat_display,
                           ssmo.form_code, ssmo.form_system, ssmo.form_display,
                           ssmo.route_code, ssmo.route_system, ssmo.route_display,
                           ssmo.numerator_code, ssmo.numerator_system,
                           ssmo.denominator_code, ssmo.denominator_system,
                           IFNULL(ssm.id_medication, '') as id_medication
                    FROM databarang db
                    LEFT JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = db.kode_brng
                    LEFT JOIN satu_sehat_medication ssm ON ssm.kode_brng = db.kode_brng
                    WHERE db.kode_brng = ?
                ");
                $stmt->execute([$kodeBrng]);
                $row = $stmt->fetch();
                if (!$row) throw new \RuntimeException("Obat {$kodeBrng} tidak ditemukan");

                $orgId = (string) Config::get('satusehat.org_id', '');
                $payload = \SatuSehatPayloadBuilder::medication($orgId, $row, $row['id_medication'] ?? null);

                return ['payload' => $payload, 'meta' => ['kode_brng' => $kodeBrng]];
            },
            function (array|string $itemKey, string $satusehatId, array $outcome): void {
                $kodeBrng = is_array($itemKey) ? ($itemKey['kode_brng'] ?? '') : (string) $itemKey;
                $db = Database::getMysql();
                $stmt = $db->prepare("
                    INSERT INTO satu_sehat_medication (kode_brng, id_medication)
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE id_medication = VALUES(id_medication)
                ");
                $stmt->execute([$kodeBrng, $satusehatId]);
            }
        );
    }
}
