<?php

namespace SatusehatPanel\Controller;

use SatusehatPanel\Core\Database;
use SatusehatPanel\Util\PayloadAdapter;

class ResourceController
{
    /**
     * Preview (or edit) the payload for a single resource of a patient.
     *
     * Uses the SAME payload builder logic as the CLI (PayloadAdapter wraps
     * PayloadBuilder with the panel's DB access, so payloads are identical).
     */
    public static function preview(string $noRawat, string $resource): array
    {
        $db = Database::getMysql();

        $stmt = $db->prepare("
            SELECT rp.*, pj.nm_pasien, pj.no_ktp, pj.no_rkm_medis
            FROM reg_periksa rp
            JOIN pasien pj ON pj.no_rkm_medis = rp.no_rkm_medis
            WHERE rp.no_rawat = ?
        ");
        $stmt->execute([$noRawat]);
        $patient = $stmt->fetch();
        if (!$patient) {
            return ['success' => false, 'error' => 'Patient not found'];
        }

        try {
            $payloads = PayloadAdapter::build($resource, $noRawat, $patient);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Gagal membangun payload untuk resource ini: ' . $e->getMessage()];
        }

        if (empty($payloads)) {
            return ['success' => false, 'error' => "No data for resource '{$resource}' on this visit"];
        }

        return [
            'success' => true,
            'data' => $payloads,
            'count' => count($payloads),
        ];
    }
}
