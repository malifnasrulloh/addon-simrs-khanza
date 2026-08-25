<?php

declare(strict_types=1);

namespace SatusehatPanel\Modules\Bundle;

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
        $noRawat = (string) ($input['no_rawat'] ?? ($input['items'][0] ?? ''));
        if (empty($noRawat)) {
            return ['success' => false, 'error' => 'no_rawat required'];
        }
        return SendController::sendBundle($noRawat);
    }
}
