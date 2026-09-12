<?php

declare(strict_types=1);

namespace SatusehatPanel\Modules\Bundle;

defined('PANEL_BASE') || exit('Direct script access denied.');

use SatusehatPanel\Controller\PatientController;
use SatusehatPanel\Controller\SendController;

class Controller
{
    public static function list(): array
    {
        return PatientController::list();
    }

    public static function preview(string $key): array
    {
        return PatientController::detail($key);
    }

    public static function send(): array
    {
        $input = json_decode((string) file_get_contents('php://input'), true);
        $items = $input['items'] ?? (!empty($input['no_rawat']) ? [$input['no_rawat']] : []);
        if (empty($items)) {
            return ['success' => false, 'error' => 'no_rawat required'];
        }

        $successCount = 0;
        $failCount = 0;
        $results = [];

        foreach ($items as $item) {
            $noRawat = is_array($item) ? ($item['no_rawat'] ?? '') : (string) $item;
            if (empty($noRawat)) {
                continue;
            }

            $res = SendController::sendBundle($noRawat);
            $results[$noRawat] = $res;
            if (!empty($res['success'])) {
                $successCount++;
            } else {
                $failCount++;
            }
        }

        return [
            'success' => $failCount === 0 && $successCount > 0,
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'results' => $results,
        ];
    }
}
