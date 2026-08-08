<?php

/**
 * CredentialLocator — resolves SATUSEHAT API credentials for the panel.
 *
 * Plug-and-play: the panel works with NO .env (copy the folder into the
 * Khanza webroot and open ip/satusehat-panel/). SATUSEHAT credentials are
 * entered once via the in-panel Settings page (#/settings) and stored in
 * config/satusehat_credential.json (masked on load, JSON_PRETTY_PRINT on
 * save) — the same file-based pattern as mapping_satu_sehat.
 *
 * Resolution order for building a SatuSehatConfig:
 *   1. panel root .env            (existing deployments / dev override)
 *   2. config/satusehat_credential.json  (plug-and-play, written via UI)
 *
 * JSON -> SatuSehatConfig is bridged by rendering the creds into a
 * temporary .env-format file under storage/ (protected from the web under
 * Nginx) so the adopted CLI SatuSehatConfig parser sees exactly the keys it
 * requires, unchanged.
 */

class CredentialLocator
{
    // Paths computed at runtime (PHP 7.3+ compatible — __DIR__ in const
    // requires PHP 8.3+, which most Khanza installations don't have yet).
    private static function jsonPath(): string
    {
        return __DIR__ . '/../../config/satusehat_credential.json';
    }
    private static function envPath(): string
    {
        return __DIR__ . '/../../.env';
    }
    private static function kfEnv(): string
    {
        return __DIR__ . '/../../storage/.satusehat_env.tmp';
    }

    /** Read the credential JSON (all values, raw). */
    public static function loadCredential(): ?array
    {
        $path = self::jsonPath();
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** Persist credential array to JSON (pretty-printed, unescaped). */
    public static function saveCredential(array $data): bool
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        return @file_put_contents(self::jsonPath(), $json) !== false;
    }

    /**
     * Build a SatuSehatConfig for the send/preview path.
     * Prefers the panel .env; falls back to the JSON credential file.
     *
     * @throws \RuntimeException if neither source has the required keys.
     */
    public static function buildSatuSehatConfig(): \SatuSehatConfig
    {
        if (is_file(self::envPath())) {
            return new \SatuSehatConfig(self::envPath());
        }

        $cred = self::loadCredential();
        if ($cred === null || trim((string) ($cred['client_id'] ?? '')) === '') {
            throw new \RuntimeException(
                'Kredensial SATUSEHAT belum diatur. Buka panel → Pengaturan (#/settings) lalu isi Organization ID, Client ID, Client Secret.'
            );
        }

        $env = $cred['environment'] ?? 'production';
        $authUrl = 'https://api-satusehat-stg.dto.kemkes.go.id/oauth2/v1';
        $baseUrl = 'https://api-satusehat-stg.dto.kemkes.go.id/fhir-r4/v1';
        if ($env === 'production') {
            $authUrl = 'https://api-satusehat.kemkes.go.id/oauth2/v1';
            $baseUrl = 'https://api-satusehat.kemkes.go.id/fhir-r4/v1';
        }

        $lines = [
            'DB_HOST=localhost',
            'DB_PORT=3306',
            'DB_NAME=sik',
            'DB_USER=root',
            'DB_PASS=',
            'SATUSEHAT_ORG_ID=' . trim((string) ($cred['organization_id'] ?? '')),
            'SATUSEHAT_CLIENT_ID=' . trim((string) ($cred['client_id'] ?? '')),
            'SATUSEHAT_SECRET_KEY=' . trim((string) ($cred['client_secret'] ?? '')),
            'SATUSEHAT_AUTH_URL=' . $authUrl,
            'SATUSEHAT_BASE_URL=' . $baseUrl,
            'SATUSEHAT_TOKEN_TIMEOUT=3000',
            'SATUSEHAT_DELAY_MS=500',
            'SATUSEHAT_LOOKBACK_DAYS=0',
            'SATUSEHAT_BATCH_SIZE=500',
            'SATUSEHAT_MEMORY_LIMIT=512M',
            'SATUSEHAT_VERBOSE_PAYLOAD=false',
            'TIMEZONE=Asia/Jakarta',
            'LOG_DIR=storage',
            'LOG_LEVEL=INFO',
            'LOG_RETENTION_DAYS=30',
            'WEBHOOK_USER=user_webhook_rs',
            'WEBHOOK_PASSWORD=password_webhook_rs',
            'ORTHANC_URL=http://localhost',
            'ORTHANC_PORT=8042',
            'ORTHANC_USER=admin',
            'ORTHANC_PASS=password',
            'DICOM_CONVERTER_URL=http://localhost',
            'DICOM_CONVERTER_PORT=8080',
            'DICOM_ROUTER_AE=DCMROUTER',
            'SIMRS_WEBAPPS_URL=http://localhost/webapps',
        ];

        $dir = dirname(self::kfEnv());
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (@file_put_contents(self::kfEnv(), implode("\n", $lines) . "\n") === false) {
            throw new \RuntimeException('Gagal menulis berkas kredensial sementara di storage/.');
        }

        return new \SatuSehatConfig(self::kfEnv());
    }
}