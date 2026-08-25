<?php

declare(strict_types=1);

namespace SatusehatPanel\Core;

use SatusehatPanel\Util\EntryOutcomeClassifier;
use SatusehatPanel\Util\Logger;
use SatusehatPanel\Util\PayloadAdapter;
use SatusehatPanel\Util\ReferenceRegistry;

/**
 * BaseModuleController - Core foundation for standalone SATUSEHAT resource modules.
 *
 * Provides standardized web query filter parsing, 4-state status detection
 * (Sent / Ready / Failed / Blocked), IHS resolution with DPJP fallback,
 * direct FHIR dispatch, MySQL mapping upsert, and SQLite audit logging.
 */
abstract class BaseModuleController
{
    /**
     * Standard web query filter parsing directly from UI inputs (zero .env coupling).
     */
    public static function parseFilters(): array
    {
        $since = self::validDate($_GET['since'] ?? '') ?: date('Y-m-d');
        $until = self::validDate($_GET['until'] ?? '') ?: date('Y-m-d');
        $statusBayar = trim((string) ($_GET['status_bayar'] ?? 'all'));
        $statusSync = trim((string) ($_GET['status_sync'] ?? 'all'));
        $kdPoli = trim((string) ($_GET['kd_poli'] ?? ''));
        $search = trim((string) ($_GET['search'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(max(1, (int) ($_GET['per_page'] ?? 25)), 200);

        return [
            'since'        => $since,
            'until'        => $until,
            'status_bayar' => $statusBayar,
            'status_sync'  => $statusSync,
            'kd_poli'      => $kdPoli,
            'search'       => $search,
            'page'         => $page,
            'per_page'     => $perPage,
            'offset'       => ($page - 1) * $perPage,
        ];
    }

    /**
     * Safely read local state from SQLite state table or fallback to send_entries.
     */
    public static function getLocalState(string $tableName, string $compositeKey, string $patientId = '', string $resourceType = ''): ?string
    {
        try {
            $db = Database::getSqlite();
            // 1. Try dedicated state table if exists
            $stmt = $db->prepare("SELECT status FROM {$tableName} WHERE composite_key = ? LIMIT 1");
            $stmt->execute([$compositeKey]);
            $st = $stmt->fetchColumn();
            if ($st) return (string) $st;
        } catch (\Throwable $e) {
            // Table might not exist in panel.db — fallback to send_entries
        }

        if ($patientId !== '' && $resourceType !== '') {
            try {
                $db = Database::getSqlite();
                $stmt = $db->prepare("SELECT status FROM send_entries WHERE patient_id = ? AND resource_type = ? ORDER BY id DESC LIMIT 1");
                $stmt->execute([$patientId, ltrim($resourceType, '/')]);
                $st = $stmt->fetchColumn();
                if ($st) return (string) $st;
            } catch (\Throwable $e) {
                // non-fatal
            }
        }

        return null;
    }

    /**
     * Determine item sync status across 4 states:
     * - 'sent'    : SATUSEHAT ID exists in mapping table
     * - 'failed'  : Terminal error recorded in SQLite local state (failed_rule, invalid_code, privacy_error)
     * - 'blocked' : Missing dependencies (no encounter, missing IHS, unmapped location, unpaid billing)
     * - 'ready'   : Prerequisites met and ready to be sent
     *
     * @param array  $row        Database row
     * @param ?string $mappingId Real ID from MySQL mapping table
     * @param ?string $localState Status from SQLite state table
     * @param array  $blockers   List of missing prerequisites e.g. ['encounter', 'ihs_pasien', 'ihs_dokter', 'location', 'billing']
     * @return array ['status' => string, 'label' => string, 'badge' => string, 'blocker_reason' => ?string]
     */
    public static function evaluateStatus(array $row, ?string $mappingId, ?string $localState, array $blockers = []): array
    {
        $hasId = !empty($mappingId) && $mappingId !== '-';

        // 1. Sent
        if ($hasId) {
            return [
                'status'         => 'sent',
                'label'          => 'Sudah Kirim',
                'badge'          => 'badge-success',
                'satusehat_id'   => $mappingId,
                'blocker_reason' => null,
            ];
        }

        // 2. Failed (terminal error from prior API attempt)
        if (in_array($localState, ['failed_rule', 'invalid_code', 'privacy_error', 'failed'], true)) {
            $reason = match ($localState) {
                'invalid_code'  => 'Gagal: Kode/Terminologi Ditolak SATUSEHAT',
                'failed_rule'   => 'Gagal: Melanggar Aturan Bisnis SATUSEHAT (RuleNumber)',
                'privacy_error' => 'Gagal: Akses Ditolak / Pasien Belum Memberi Consent',
                default         => 'Gagal: Terjadi Kesalahan Pengiriman',
            };
            return [
                'status'         => 'failed',
                'label'          => 'Gagal Kirim',
                'badge'          => 'badge-danger',
                'satusehat_id'   => null,
                'blocker_reason' => $reason,
            ];
        }

        // 3. Blocked (missing prerequisites)
        if (!empty($blockers)) {
            $reasons = [];
            foreach ($blockers as $b) {
                $reasons[] = match ($b) {
                    'encounter'  => 'Encounter kunjungan ini belum terkirim',
                    'ihs_pasien' => 'NIK Pasien belum memiliki IHS ID',
                    'ihs_dokter' => 'Dokter/Praktisi belum memiliki IHS ID',
                    'location'   => 'Poliklinik/Kamar belum dipetakan ke Lokasi SATUSEHAT',
                    'billing'    => 'Status billing belum lunas (Belum Bayar)',
                    'parent_req' => 'ServiceRequest/Specimen belum terkirim',
                    default      => is_string($b) ? $b : 'Prasyarat belum terpenuhi',
                };
            }
            return [
                'status'         => 'blocked',
                'label'          => 'Terblokir',
                'badge'          => 'badge-warning',
                'satusehat_id'   => null,
                'blocker_reason' => implode(' · ', $reasons),
            ];
        }

        // 4. Ready (Siap Kirim)
        return [
            'status'         => 'ready',
            'label'          => 'Siap Kirim',
            'badge'          => 'badge-neutral',
            'satusehat_id'   => null,
            'blocker_reason' => null,
        ];
    }

    /**
     * Get SATUSEHAT client instance.
     */
    public static function getClient(): \SatuSehatClient
    {
        $config = \CredentialLocator::buildSatuSehatConfig();
        $logDir = defined('BASE_DIR') ? BASE_DIR . '/storage' : __DIR__ . '/../../storage';
        $log = new \Logger($logDir, 'panel_module', $config->logLevel, false);
        return new \SatuSehatClient($config, $log);
    }

    /**
     * Robust IHS resolution with DPJP fallback.
     */
    public static function resolveIhs(\PDO $db, string $nikPasien, string $nikDokter, ?string $nikDokterDpjp = null): array
    {
        $ihsPasien = PayloadAdapter::resolvePatientIhs($db, $nikPasien);
        $ihsDokter = PayloadAdapter::resolveDokterIhs($db, $nikDokter);

        // Fallback to attending physician (DPJP) if nurse/paramedis has no IHS ID
        if (($ihsDokter === '' || str_contains($ihsDokter, 'PLACEHOLDER')) && !empty($nikDokterDpjp)) {
            $ihsDpjp = PayloadAdapter::resolveDokterIhs($db, $nikDokterDpjp);
            if ($ihsDpjp !== '' && !str_contains($ihsDpjp, 'PLACEHOLDER')) {
                $ihsDokter = $ihsDpjp;
            }
        }

        return [
            'pasien' => $ihsPasien,
            'dokter' => $ihsDokter,
            'valid'  => (!empty($ihsPasien) && !str_contains($ihsPasien, 'PLACEHOLDER') && !empty($ihsDokter) && !str_contains($ihsDokter, 'PLACEHOLDER')),
        ];
    }

    /**
     * Generic standalone direct sender for single or multiple items.
     *
     * @param string $endpoint e.g. '/Condition', '/Observation', etc.
     * @param callable $payloadFactory fn(array $itemKey): array ['payload' => array, 'meta' => array]
     * @param callable $saveHandler fn(array $itemKey, string $satusehatId, array $outcome): void
     */
    public static function executeSend(string $endpoint, callable $payloadFactory, callable $saveHandler): array
    {
        $input = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($input)) {
            return ['success' => false, 'error' => 'Invalid JSON body'];
        }

        $items = $input['items'] ?? [];
        if (empty($items)) {
            return ['success' => false, 'error' => 'Tidak ada data yang dipilih'];
        }

        $customPayloads = $input['custom_payloads'] ?? [];
        $client = self::getClient();
        $results = [];
        $successCount = 0;
        $failCount = 0;

        foreach ($items as $itemKey) {
            $keyStr = is_array($itemKey) ? implode('|', $itemKey) : (string) $itemKey;
            $noRawat = is_array($itemKey) ? ($itemKey['no_rawat'] ?? '') : '';

            try {
                // Check if user provided manual JSON override in web editor
                if (!empty($customPayloads[$keyStr])) {
                    $payload = $customPayloads[$keyStr];
                    $meta = [];
                } else {
                    $build = $payloadFactory($itemKey);
                    if (empty($build) || empty($build['payload'])) {
                        throw new \RuntimeException('Gagal membangun payload resource');
                    }
                    $payload = $build['payload'];
                    $meta = $build['meta'] ?? [];
                }

                // Check for existing ID in payload (PUT) or new (POST)
                $hasId = !empty($payload['id']);
                $method = $hasId ? 'PUT' : 'POST';
                $url = $hasId ? $endpoint . '/' . $payload['id'] : $endpoint;

                $apiRes = ($method === 'PUT') ? $client->put($url, $payload) : $client->post($url, $payload);
                $classified = EntryOutcomeClassifier::classify($apiRes['data'] ?? []);

                $isSuccess = (!empty($apiRes['success']) && $apiRes['code'] >= 200 && $apiRes['code'] < 300)
                    || ($classified['status'] === EntryOutcomeClassifier::SENT);

                $satusehatId = $classified['satusehat_id']
                    ?? $apiRes['data']['id']
                    ?? ($hasId ? $payload['id'] : null);

                if ($isSuccess && !empty($satusehatId)) {
                    $saveHandler($itemKey, (string) $satusehatId, $classified);
                    $successCount++;
                    $status = 'sent';
                } else {
                    $failCount++;
                    $status = $classified['status'] ?: 'failed';
                }

                // Log to SQLite audit trail
                self::logAudit(
                    $noRawat,
                    $endpoint,
                    $method === 'PUT' ? 'update' : 'create',
                    $status,
                    $payload,
                    $apiRes,
                    $satusehatId,
                    $classified['rule_number'] ?? null,
                    $classified['issue_text'] ?? ($apiRes['message'] ?? null)
                );

                $results[$keyStr] = [
                    'success'      => $isSuccess,
                    'satusehat_id' => $satusehatId,
                    'status'       => $status,
                    'http_code'    => $apiRes['code'] ?? 0,
                    'rule_number'  => $classified['rule_number'] ?? null,
                    'issue_text'   => $classified['issue_text'] ?? ($apiRes['message'] ?? null),
                    'request_json' => $payload,
                    'response_json'=> $apiRes['data'] ?? $apiRes,
                ];
            } catch (\Throwable $e) {
                $failCount++;
                $results[$keyStr] = [
                    'success'    => false,
                    'status'     => 'failed',
                    'issue_text' => $e->getMessage(),
                ];
            }
        }

        return [
            'success'       => $failCount === 0,
            'total'         => count($items),
            'success_count' => $successCount,
            'fail_count'    => $failCount,
            'results'       => $results,
        ];
    }

    /**
     * Log transaction into SQLite audit_logs & send_entries.
     */
    public static function logAudit(
        string $noRawat,
        string $resourceType,
        string $action,
        string $status,
        array $requestPayload,
        array $responsePayload,
        ?string $satusehatId = null,
        ?int $ruleNumber = null,
        ?string $issueText = null
    ): void {
        try {
            $db = Database::getSqlite();
            $stmt = $db->prepare("
                INSERT INTO audit_logs (patient_id, resource_type, action, status, request_payload, response_payload, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $reqJson = json_encode($requestPayload, JSON_UNESCAPED_SLASHES);
            $respJson = json_encode($responsePayload, JSON_UNESCAPED_SLASHES);

            $stmt->execute([
                $noRawat,
                ltrim($resourceType, '/'),
                $action,
                $status,
                $reqJson,
                substr((string) $respJson, 0, 65535),
                $clientIp,
            ]);
            $auditId = (int) $db->lastInsertId();

            // Record entry in send_entries
            $stmtEntry = $db->prepare("
                INSERT INTO send_entries (audit_id, patient_id, resource_type, key_hash, status, rule_number, issue_text, satusehat_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtEntry->execute([
                $auditId,
                $noRawat,
                ltrim($resourceType, '/'),
                hash('sha256', $noRawat . '|' . $resourceType),
                $status,
                $ruleNumber,
                $issueText,
                $satusehatId,
            ]);
        } catch (\Throwable $e) {
            error_log('[PANEL] logAudit failed: ' . $e->getMessage());
        }
    }

    /**
     * Validate Y-m-d date format.
     */
    public static function validDate(string $val): string
    {
        $val = trim($val);
        if ($val === '') {
            return '';
        }
        $d = \DateTime::createFromFormat('Y-m-d', $val);
        if ($d === false || $d->format('Y-m-d') !== $val) {
            return '';
        }
        return $val;
    }
}
