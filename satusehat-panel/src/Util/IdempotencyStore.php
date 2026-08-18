<?php

declare(strict_types=1);

namespace SatusehatPanel\Util;

use SatusehatPanel\Core\Database;

/**
 * IdempotencyStore — per-entry idempotency keys over the SQLite
 * `idempotency_keys` table (which existed but was never written).
 *
 * A key = hash(patient, resourceType, canonical persist keys) of one bundle
 * entry. Flow: claim (pending) before POST → settle(sent/failed) after an
 * outcome. If a previous attempt is still pending / unknown (timeout, 5xx),
 * a new send is refused until human verification. Replays return the stored
 * satusehat ids, so a retried send never duplicates (rule 20002).
 */
final class IdempotencyStore
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNKNOWN = 'unknown';

    /**
     * Pending claims expire after this long; sweep() clears them so a crashed
     * worker between claim and settle cannot block the key forever.
     */
    public const PENDING_TTL_HOURS = 6;

    /** SQLite datetime modifier matching PENDING_TTL_HOURS (no space after +). */
    private const PENDING_TTL_MODIFIER = '+6 hours';

    public static function canonicalKey(string $patientId, string $resourceType, array $keys): string
    {
        $canon = array_map('strval', $keys);
        ksort($canon);
        return hash('sha256', $patientId . '|' . $resourceType . '|' . json_encode($canon));
    }

    /**
     * Return the stored state for a key, or null when no prior attempt exists.
     *
     * @return array{key_hash:string,status:string,resource_id:?string,response_data:?string}|null
     */
    public static function lookup(string $key): ?array
    {
        try {
            $db = Database::getSqlite();
            $stmt = $db->prepare("SELECT key_hash, status, resource_id, response_data FROM idempotency_keys WHERE key_hash = ?");
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Claim a key as pending (insert only — never downgrades an existing
     * recorded outcome; callers must refuse to re-send when a pending or
     * unknown key already exists).
     *
     * Pending rows carry an expiry so a crashed worker between claim and
     * settle cannot block the key forever — sweep() clears it.
     */
    public static function claim(string $key, string $patientId, string $resourceType): void
    {
        try {
            $db = Database::getSqlite();
            $stmt = $db->prepare("
                INSERT OR IGNORE INTO idempotency_keys (key_hash, patient_id, resource_type, status, expires_at)
                VALUES (?, ?, ?, ?, datetime('now', '" . self::PENDING_TTL_MODIFIER . "'))
            ");
            $stmt->execute([$key, $patientId, $resourceType, self::STATUS_PENDING]);
        } catch (\Throwable $e) {
            error_log('[PANEL] idempotency claim failed: ' . $e->getMessage());
        }
    }

    /**
     * Check the claim set of an entire bundle: true when every key is either
     * brand-new or recorded as sent/failed; false when any key is still
     * pending/unknown (an earlier attempt did not reach a definitive end —
     * re-send must be refused until human verification).
     *
     * @param array<string,string> $keys key_hash => resource_type
     */
    public static function attemptsConflicted(array $keys): array
    {
        $conflicts = [];
        foreach ($keys as $key => $type) {
            $row = self::lookup($key);
            if ($row !== null && in_array($row['status'], [self::STATUS_PENDING, self::STATUS_UNKNOWN], true)) {
                $conflicts[$key] = ['type' => $type, 'status' => $row['status']];
            }
        }
        return $conflicts;
    }

    /** Settle after a DEFINITIVE outcome. */
    public static function settle(string $key, string $status, ?string $satusehatId, ?string $responseData = null): void
    {
        try {
            $db = Database::getSqlite();
            $stmt = $db->prepare("
                UPDATE idempotency_keys
                SET status = ?, resource_id = ?, response_data = ?,
                    expires_at = datetime('now', '+24 hours')
                WHERE key_hash = ?
            ");
            $stmt->execute([$status, $satusehatId, $responseData, $key]);
        } catch (\Throwable $e) {
            error_log('[PANEL] idempotency settle failed: ' . $e->getMessage());
        }
    }

    /**
     * Atomically check-then-claim a full key set (BEGIN IMMEDIATE).
     * Two concurrent sends of the same bundle cannot both pass the conflict
     * check — the first transaction commits its claims, the second sees the
     * pending rows and refuses.
     *
     * @param array<string,string> $keys key_hash => resource_type
     * @return array<string,array{type:string,status:string}> conflicts; empty
     *                                                         when all claimed
     */
    public static function claimAll(array $keys, string $patientId): array
    {
        if (empty($keys)) {
            return [];
        }
        $db = Database::getSqlite();
        $db->exec('BEGIN IMMEDIATE');
        try {
            $conflicts = [];
            $sel = $db->prepare('SELECT status FROM idempotency_keys WHERE key_hash = ?');
            foreach ($keys as $key => $type) {
                $sel->execute([$key]);
                $status = $sel->fetchColumn();
                if ($status !== false && in_array((string) $status, [self::STATUS_PENDING, self::STATUS_UNKNOWN], true)) {
                    $conflicts[$key] = ['type' => $type, 'status' => (string) $status];
                }
            }
            if (!empty($conflicts)) {
                $db->exec('ROLLBACK');
                return $conflicts;
            }
            $ins = $db->prepare("
                INSERT OR IGNORE INTO idempotency_keys (key_hash, patient_id, resource_type, status, expires_at)
                VALUES (?, ?, ?, ?, datetime('now', '" . self::PENDING_TTL_MODIFIER . "'))
            ");
            foreach ($keys as $key => $type) {
                $ins->execute([$key, $patientId, $type, self::STATUS_PENDING]);
            }
            $db->exec('COMMIT');
            return [];
        } catch (\Throwable $e) {
            try {
                $db->exec('ROLLBACK');
            } catch (\Throwable $ignored) {
                // connection may have died — nothing to roll back
            }
            error_log('[PANEL] idempotency claimAll failed: ' . $e->getMessage());
            return $conflicts ?? [];
        }
    }

    /** Prune records past their expiry + failed rows older than 24h. */
    public static function sweep(): void
    {
        try {
            $db = Database::getSqlite();
            $db->exec("DELETE FROM idempotency_keys WHERE expires_at IS NOT NULL AND expires_at < datetime('now')");
            $db->exec("DELETE FROM idempotency_keys WHERE status = 'failed' AND created_at < datetime('now', '-24 hours')");
        } catch (\Throwable $e) {
            error_log('[PANEL] idempotency sweep failed: ' . $e->getMessage());
        }
    }
}