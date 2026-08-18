<?php

declare(strict_types=1);

namespace SatusehatPanel\Tests;

use PHPUnit\Framework\TestCase;
use SatusehatPanel\Core\Database;
use SatusehatPanel\Util\IdempotencyStore;

/**
 * Idempotency store behavior: claim-before-send, refusal on pending/unknown
 * prior attempts, replay of fully-sent sets, sweep of stale rows.
 */
final class IdempotencyTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = PANEL_TEST_STORAGE . '/idem-' . uniqid() . '.db';
        Database::setSqlitePathForTesting($this->dbPath);
        // Touch to create the store with migrations.
        Database::getSqlite();
    }

    protected function tearDown(): void
    {
        Database::setSqlitePathForTesting(null);
        @unlink($this->dbPath);
    }

    public function testCanonicalKeyIsDeterministicAndSpecific(): void
    {
        $a = IdempotencyStore::canonicalKey('V-1', 'Condition', ['no_rawat' => 'V-1', 'kd_penyakit' => 'A00', 'status' => 'Ralan']);
        $b = IdempotencyStore::canonicalKey('V-1', 'Condition', ['no_rawat' => 'V-1', 'kd_penyakit' => 'A00', 'status' => 'Ralan']);
        $c = IdempotencyStore::canonicalKey('V-1', 'Condition', ['no_rawat' => 'V-1', 'kd_penyakit' => 'B00', 'status' => 'Ralan']);
        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
    }

    public function testFreshKeyHasNoConflicts(): void
    {
        $key = IdempotencyStore::canonicalKey('V-1', 'Encounter', ['no_rawat' => 'V-1']);
        $this->assertEmpty(IdempotencyStore::attemptsConflicted([$key => 'Encounter']));
    }

    public function testPendingKeyConflictsAndBlocksResend(): void
    {
        $key = IdempotencyStore::canonicalKey('V-1', 'Condition', ['no_rawat' => 'V-1', 'kd_penyakit' => 'A00']);
        IdempotencyStore::claim($key, 'V-1', 'Condition');
        $conflicts = IdempotencyStore::attemptsConflicted([$key => 'Condition']);
        $this->assertArrayHasKey($key, $conflicts);
        $this->assertSame('pending', $conflicts[$key]['status']);
    }

    public function testSettledFailedKeyAllowsRetry(): void
    {
        $key = IdempotencyStore::canonicalKey('V-1', 'Condition', ['no_rawat' => 'V-1', 'kd_penyakit' => 'A00']);
        IdempotencyStore::claim($key, 'V-1', 'Condition');
        IdempotencyStore::settle($key, IdempotencyStore::STATUS_FAILED, null);
        $this->assertEmpty(IdempotencyStore::attemptsConflicted([$key => 'Condition']));
    }

    public function testUnknownKeyConflictsUntilSettled(): void
    {
        $key = IdempotencyStore::canonicalKey('V-1', 'Observation', ['noorder' => 'ORD-1']);
        IdempotencyStore::claim($key, 'V-1', 'Observation');
        IdempotencyStore::settle($key, IdempotencyStore::STATUS_UNKNOWN, null);
        $conflicts = IdempotencyStore::attemptsConflicted([$key => 'Observation']);
        $this->assertArrayHasKey($key, $conflicts);
        $this->assertSame('unknown', $conflicts[$key]['status']);
    }

    public function testSentKeyIsReplayable(): void
    {
        $key = IdempotencyStore::canonicalKey('V-1', 'Encounter', ['no_rawat' => 'V-1']);
        IdempotencyStore::claim($key, 'V-1', 'Encounter');
        IdempotencyStore::settle($key, IdempotencyStore::STATUS_SENT, 'enc-123');
        $row = IdempotencyStore::lookup($key);
        $this->assertSame('sent', $row['status']);
        $this->assertSame('enc-123', $row['resource_id']);
        $this->assertEmpty(IdempotencyStore::attemptsConflicted([$key => 'Encounter']));
    }

    public function testSweepRemovesExpiredAndOldFailed(): void
    {
        $key = IdempotencyStore::canonicalKey('V-1', 'Encounter', ['no_rawat' => 'V-1']);
        IdempotencyStore::claim($key, 'V-1', 'Encounter');
        // Force expiry into the past.
        $db = Database::getSqlite();
        $db->prepare("UPDATE idempotency_keys SET expires_at = datetime('now', '-1 minute') WHERE key_hash = ?")
            ->execute([$key]);
        IdempotencyStore::sweep();
        $this->assertNull(IdempotencyStore::lookup($key));
    }

    public function testClaimSetsPendingExpirySoStuckKeysAreSwept(): void
    {
        $key = IdempotencyStore::canonicalKey('V-1', 'Condition', ['no_rawat' => 'V-1', 'kd_penyakit' => 'A00']);
        IdempotencyStore::claim($key, 'V-1', 'Condition');
        $db = Database::getSqlite();
        $expires = $db->prepare("SELECT expires_at FROM idempotency_keys WHERE key_hash = ?");
        $expires->execute([$key]);
        $this->assertNotNull($expires->fetchColumn(), 'pending claim must carry an expiry');

        // A crashed worker leaves the key pending forever; sweep clears it
        // once past its TTL so a re-send is possible again.
        $db->prepare("UPDATE idempotency_keys SET expires_at = datetime('now', '-1 minute') WHERE key_hash = ?")
            ->execute([$key]);
        IdempotencyStore::sweep();
        $this->assertNull(IdempotencyStore::lookup($key));
    }

    public function testClaimAllClaimsFreshKeySetAtomically(): void
    {
        $key = IdempotencyStore::canonicalKey('V-1', 'Encounter', ['no_rawat' => 'V-1']);
        $conflicts = IdempotencyStore::claimAll([$key => 'Encounter'], 'V-1');
        $this->assertSame([], $conflicts);
        $row = IdempotencyStore::lookup($key);
        $this->assertSame('pending', $row['status']);
    }

    public function testClaimAllRefusesAndDoesNotClaimWhenConflictExists(): void
    {
        $key = IdempotencyStore::canonicalKey('V-1', 'Condition', ['no_rawat' => 'V-1', 'kd_penyakit' => 'A00']);
        $other = IdempotencyStore::canonicalKey('V-1', 'Encounter', ['no_rawat' => 'V-1']);
        IdempotencyStore::claim($key, 'V-1', 'Condition');

        $conflicts = IdempotencyStore::claimAll([$key => 'Condition', $other => 'Encounter'], 'V-1');
        $this->assertArrayHasKey($key, $conflicts);
        // The non-conflicting key must NOT have been claimed either — the
        // whole set is atomic.
        $this->assertNull(IdempotencyStore::lookup($other));
    }

    public function testClaimAllDoesNotDowngradeSettledSentKey(): void
    {
        $key = IdempotencyStore::canonicalKey('V-1', 'Encounter', ['no_rawat' => 'V-1']);
        IdempotencyStore::claim($key, 'V-1', 'Encounter');
        IdempotencyStore::settle($key, IdempotencyStore::STATUS_SENT, 'enc-9');

        $conflicts = IdempotencyStore::claimAll([$key => 'Encounter'], 'V-1');
        $this->assertSame([], $conflicts);
        $row = IdempotencyStore::lookup($key);
        $this->assertSame('sent', $row['status']);
        $this->assertSame('enc-9', $row['resource_id']);
    }

    // ── sendBundle-shaped semantics (the decision logic sendBundle composes) ──

    public function testSendBundleReplayShapeEveryKeySent(): void
    {
        // All entries already committed: claimAll must yield NO conflicts so
        // sendBundle takes the full-replay branch (no new POST), and every
        // lookup must return its authoritative SATUSEHAT id.
        $keys = [
            IdempotencyStore::canonicalKey('V-1', 'Encounter', ['no_rawat' => 'V-1']) => 'Encounter',
            IdempotencyStore::canonicalKey('V-1', 'Condition', ['no_rawat' => 'V-1', 'kd_penyakit' => 'A00']) => 'Condition',
        ];
        $ids = ['enc-1', 'cond-7'];
        $i = 0;
        foreach ($keys as $key => $type) {
            IdempotencyStore::claim($key, 'V-1', $type);
            IdempotencyStore::settle($key, IdempotencyStore::STATUS_SENT, $ids[$i++]);
        }

        $this->assertSame([], IdempotencyStore::claimAll($keys, 'V-1'));
        foreach ($keys as $key => $type) {
            $row = IdempotencyStore::lookup($key);
            $this->assertSame('sent', $row['status']);
            $this->assertNotNull($row['resource_id']);
        }
    }

    public function testSendBundleConflictShapeMixedSetIsAtomic(): void
    {
        // One uncertain key + one fresh key: the whole set is refused
        // (needs_manual_verify) and the fresh key is NOT claimed either.
        $sentKey = IdempotencyStore::canonicalKey('V-1', 'Encounter', ['no_rawat' => 'V-1']);
        IdempotencyStore::claim($sentKey, 'V-1', 'Encounter');
        IdempotencyStore::settle($sentKey, IdempotencyStore::STATUS_SENT, 'enc-1');

        $unknownKey = IdempotencyStore::canonicalKey('V-1', 'Condition', ['no_rawat' => 'V-1', 'kd_penyakit' => 'A00']);
        IdempotencyStore::claim($unknownKey, 'V-1', 'Condition');
        IdempotencyStore::settle($unknownKey, IdempotencyStore::STATUS_UNKNOWN, null);

        $freshKey = IdempotencyStore::canonicalKey('V-1', 'Observation', ['noorder' => 'ORD-2']);

        $conflicts = IdempotencyStore::claimAll([
            $sentKey => 'Encounter',
            $unknownKey => 'Condition',
            $freshKey => 'Observation',
        ], 'V-1');
        $this->assertArrayHasKey($unknownKey, $conflicts, 'uncertain prior attempt must block the send');
        $this->assertSame('unknown', $conflicts[$unknownKey]['status']);
        $this->assertNull(IdempotencyStore::lookup($freshKey), 'fresh key must not be claimed when the set is refused');
        // The settled SENT key must survive untouched.
        $this->assertSame('sent', IdempotencyStore::lookup($sentKey)['status']);
    }

    public function testSendBundleFreshShapeClaimsWholeSet(): void
    {
        $keys = [
            IdempotencyStore::canonicalKey('V-1', 'Encounter', ['no_rawat' => 'V-1']) => 'Encounter',
            IdempotencyStore::canonicalKey('V-1', 'Condition', ['no_rawat' => 'V-1', 'kd_penyakit' => 'A00']) => 'Condition',
        ];
        $this->assertSame([], IdempotencyStore::claimAll($keys, 'V-1'));
        foreach ($keys as $key => $type) {
            $this->assertSame('pending', IdempotencyStore::lookup($key)['status']);
        }
    }

    public function testEntryKeyHashIsDeterministicAndOrderIndependent(): void
    {
        // sendBundle persists ReferenceRegistry::hashKeys(persist keys) into
        // send_entries.key_hash — it must be stable and order-independent so
        // the same entry always produces the same audit trail hash.
        $a = \SatusehatPanel\Util\ReferenceRegistry::hashKeys(['noorder' => 'ORD-1', 'kd_jenis_prw' => 'L01']);
        $b = \SatusehatPanel\Util\ReferenceRegistry::hashKeys(['kd_jenis_prw' => 'L01', 'noorder' => 'ORD-1']);
        $this->assertSame($a, $b);
        $c = \SatusehatPanel\Util\ReferenceRegistry::hashKeys(['noorder' => 'ORD-2', 'kd_jenis_prw' => 'L01']);
        $this->assertNotSame($a, $c);
        // Distinct from the idempotency key (which scopes by patient+type).
        $idem = IdempotencyStore::canonicalKey('V-1', 'Observation', ['noorder' => 'ORD-1', 'kd_jenis_prw' => 'L01']);
        $this->assertNotSame($a, $idem);
    }
}
