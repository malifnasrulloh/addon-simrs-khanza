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
 * Resolution order (per key):
 *   1. panel root .env (existing deployments / dev override), non-empty wins
 *   2. config/satusehat_credential.json (plug-and-play, written via UI)
 *
 * The merged variable set is handed to SatuSehatConfig in-memory — no
 * temporary env file is ever written to disk.
 */

class CredentialLocator
{
    /** @internal — test-only path overrides (null = default locations) */
    private static ?string $envPathOverride = null;
    private static ?string $jsonPathOverride = null;

    // Paths computed at runtime (PHP 7.3+ compatible — __DIR__ in const
    // requires PHP 8.3+, which most Khanza installations don't have yet).
    private static function jsonPath(): string
    {
        return self::$jsonPathOverride ?? (__DIR__ . '/../../config/satusehat_credential.json');
    }
    private static function envPath(): string
    {
        return self::$envPathOverride ?? (__DIR__ . '/../../.env');
    }

    /** @internal — make path resolution controllable from tests. */
    public static function setPathsForTesting(?string $envPath, ?string $jsonPath): void
    {
        self::$envPathOverride = $envPath;
        self::$jsonPathOverride = $jsonPath;
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

        $path = self::jsonPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Atomic write: temp file + flock + rename, mode 0600.
        $tmp = $path . '.tmp.' . getmypid();
        $fh = @fopen($tmp, 'w');
        if ($fh === false) {
            return false;
        }
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            @unlink($tmp);
            return false;
        }
        fwrite($fh, $json);
        fflush($fh);
        @chmod($tmp, 0600);
        flock($fh, LOCK_UN);
        fclose($fh);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        @chmod($path, 0600);
        return true;
    }

    /**
     * Build a SatuSehatConfig for the send/preview path.
     *
     * Merged resolution — the .env (if present) is read for DB + panel
     * settings; SATUSEHAT keys come from .env ONLY when non-empty, otherwise
     * from config/satusehat_credential.json. Nothing is written to disk.
     *
     * @throws \RuntimeException if neither source provides the required keys.
     */
    public static function buildSatuSehatConfig(): \SatuSehatConfig
    {
        $envVars = [];
        if (is_file(self::envPath())) {
            $envVars = \SatuSehatConfig::parseEnvFile(self::envPath());
        }
        $cred = self::loadCredential();

        $orgId   = self::pick($envVars, 'SATUSEHAT_ORG_ID', $cred, 'organization_id');
        $clientId = self::pick($envVars, 'SATUSEHAT_CLIENT_ID', $cred, 'client_id');
        $secret   = self::pick($envVars, 'SATUSEHAT_SECRET_KEY', $cred, 'client_secret');

        if ($orgId === '' || $clientId === '' || $secret === '') {
            throw new \RuntimeException(
                'Kredensial SATUSEHAT belum diatur. Buka panel → Pengaturan (#/settings) lalu isi Organization ID, Client ID, Client Secret.'
            );
        }

        // URL selection: .env wins; otherwise derive from the credential's
        // environment flag via the single environment table (T33).
        $authUrl = trim($envVars['SATUSEHAT_AUTH_URL'] ?? '');
        $baseUrl = trim($envVars['SATUSEHAT_BASE_URL'] ?? '');
        if ($authUrl === '' || $baseUrl === '') {
            $envName = strtolower(trim((string) ($cred['environment'] ?? '')));
            if ($envName !== 'production' && $envName !== 'sandbox') {
                $envName = strtolower(trim((string) ($envVars['SATUSEHAT_ENVIRONMENT'] ?? '')));
            }
            if ($envName !== 'production') {
                $envName = 'sandbox';
            }
            $envTable = require __DIR__ . '/../../config/satusehat_environments.php';
            $authUrl = $envTable[$envName]['auth_url'];
            $baseUrl = $envTable[$envName]['base_url'];
        }

        // Defaults mirror the old temp-env rendering (kept for parity).
        $merged = array_merge([
            'DB_HOST'                  => 'localhost',
            'DB_PORT'                  => '3306',
            'DB_NAME'                  => 'sik',
            'DB_USER'                  => 'root',
            'DB_PASS'                  => '',
            'SATUSEHAT_TOKEN_TIMEOUT'  => '3000',
            'SATUSEHAT_DELAY_MS'       => '500',
            'SATUSEHAT_LOOKBACK_DAYS'  => '0',
            'SATUSEHAT_BATCH_SIZE'     => '500',
            'SATUSEHAT_MEMORY_LIMIT'   => '512M',
            'SATUSEHAT_VERBOSE_PAYLOAD' => 'false',
            'TIMEZONE'                 => 'Asia/Jakarta',
            'LOG_DIR'                  => 'storage',
            'LOG_LEVEL'                => 'INFO',
            'LOG_RETENTION_DAYS'       => '30',
            'WEBHOOK_USER'             => 'user_webhook_rs',
            'WEBHOOK_PASSWORD'         => 'password_webhook_rs',
            'ORTHANC_URL'              => 'http://localhost',
            'ORTHANC_PORT'             => '8042',
            'ORTHANC_USER'             => 'admin',
            'ORTHANC_PASS'             => 'password',
            'DICOM_CONVERTER_URL'      => 'http://localhost',
            'DICOM_CONVERTER_PORT'     => '8080',
            'DICOM_ROUTER_AE'          => 'DCMROUTER',
            'SIMRS_WEBAPPS_URL'        => 'http://localhost/webapps',
            // The panel verifies TLS by default (unlike the legacy CLI).
            'SATUSEHAT_VERIFY_TLS'     => 'true',
        ], $envVars, [
            'SATUSEHAT_ORG_ID'     => $orgId,
            'SATUSEHAT_CLIENT_ID'   => $clientId,
            'SATUSEHAT_SECRET_KEY'  => $secret,
            'SATUSEHAT_AUTH_URL'    => $authUrl,
            'SATUSEHAT_BASE_URL'    => $baseUrl,
        ]);

        // One-time cleanup of the legacy temp env file (no longer written).
        $legacyTmp = __DIR__ . '/../../storage/.satusehat_env.tmp';
        if (is_file($legacyTmp)) {
            @unlink($legacyTmp);
        }

        return new \SatuSehatConfig('', $merged);
    }

    /**
     * Non-empty value from the .env vars, else non-empty from the credential
     * JSON, else ''.
     */
    private static function pick(array $envVars, string $envKey, ?array $cred, string $jsonKey): string
    {
        $fromEnv = trim((string) ($envVars[$envKey] ?? ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }
        return trim((string) ($cred[$jsonKey] ?? ''));
    }
}