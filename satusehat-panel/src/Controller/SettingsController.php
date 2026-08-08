<?php

namespace SatusehatPanel\Controller;

use SatusehatPanel\Core\Config;

/**
 * SettingsController — in-panel Satu Sehat credential settings
 * (plug-and-play: no .env required; creds stored in JSON).
 */
class SettingsController
{
    /**
     * GET /api/settings — return masked credential + environment.
     * client_secret is never returned in full; only a mask.
     */
    public static function get(): array
    {
        $cred = \CredentialLocator::loadCredential() ?? [];
        $secret = (string) ($cred['client_secret'] ?? '');
        $masked = $secret === '' ? '' : (str_repeat('•', 4) . substr($secret, -4));

        return [
            'success' => true,
            'data' => [
                'organization_id' => (string) ($cred['organization_id'] ?? ''),
                'client_id'       => (string) ($cred['client_id'] ?? ''),
                'client_secret'   => $masked,
                'has_secret'      => $secret !== '',
                'environment'     => (string) ($cred['environment'] ?? 'production'),
            ],
        ];
    }

    /**
     * POST /api/settings — save credential to JSON.
     * Body: { organization_id, client_id, client_secret, environment }
     * client_secret is kept if omitted/blank (masked round-trip).
     */
    public static function save(): array
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            return ['success' => false, 'error' => 'Invalid JSON body'];
        }

        $existing = \CredentialLocator::loadCredential() ?? [];

        $orgId  = trim((string) ($input['organization_id'] ?? ($existing['organization_id'] ?? '')));
        $client = trim((string) ($input['client_id'] ?? ($existing['client_id'] ?? '')));
        $env    = ($input['environment'] ?? ($existing['environment'] ?? 'production')) === 'sandbox' ? 'sandbox' : 'production';

        // Keep the existing secret unless a NEW one is submitted (masked
        // round-trip from the UI never overwrites).
        $secret = (string) ($input['client_secret'] ?? '');
        if ($secret === '' || str_starts_with($secret, '••••')) {
            $secret = (string) ($existing['client_secret'] ?? '');
        }

        if ($orgId === '' || $client === '' || $secret === '') {
            return [
                'success' => false,
                'error'   => 'Organization ID, Client ID, dan Client Secret wajib diisi',
            ];
        }

        $ok = \CredentialLocator::saveCredential([
            'organization_id' => $orgId,
            'client_id'       => $client,
            'client_secret'   => $secret,
            'environment'     => $env,
            'updated_by'      => (string) ($_SESSION['nama_user'] ?? 'admin'),
        ]);

        if (!$ok) {
            return ['success' => false, 'error' => 'Gagal menulis config/satusehat_credential.json. Pastikan folder dapat ditulis.'];
        }

        return ['success' => true, 'message' => 'Kredensial Satu Sehat tersimpan'];
    }
}