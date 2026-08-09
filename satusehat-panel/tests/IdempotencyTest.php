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
}
