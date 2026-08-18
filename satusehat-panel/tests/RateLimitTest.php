<?php

declare(strict_types=1);

namespace SatusehatPanel\Tests;

use PHPUnit\Framework\TestCase;
use SatusehatPanel\Core\Auth;
use SatusehatPanel\Core\Database;

/**
 * Login rate limiting (T24). Regression for the audit find: login_attempts
 * was upserted via ON CONFLICT(identifier_hash) while the column only had a
 * plain index — SQLite rejects that at prepare time, the exception was
 * swallowed, and the lockout never engaged.
 */
final class RateLimitTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = PANEL_TEST_STORAGE . '/ratelimit-' . uniqid() . '.db';
        Database::setSqlitePathForTesting($this->dbPath);
        Database::getSqlite(); // create store with migrations
    }

    protected function tearDown(): void
    {
        Database::setSqlitePathForTesting(null);
        @unlink($this->dbPath);
    }

    public function testFailuresAccumulatePerIdentifier(): void
    {
        Auth::recordLoginFailure('1.2.3.4|admin');
        Auth::recordLoginFailure('1.2.3.4|admin');
        $this->assertFalse(Auth::loginBlocked('1.2.3.4|admin'), 'under the cap the identifier is not blocked');
    }

    public function testLockoutEngagesAtTheCap(): void
    {
        for ($i = 0; $i < Auth::MAX_LOGIN_ATTEMPTS; $i++) {
            Auth::recordLoginFailure('1.2.3.4|admin');
        }
        $this->assertTrue(Auth::loginBlocked('1.2.3.4|admin'), 'at the cap the identifier must be blocked');
        $this->assertTrue(Auth::loginBlocked('1.2.3.4|admin'), 'idempotent — repeated checks stay blocked');
    }

    public function testUpsertKeepsOneRowPerIdentifier(): void
    {
        for ($i = 0; $i < Auth::MAX_LOGIN_ATTEMPTS + 2; $i++) {
            Auth::recordLoginFailure('1.2.3.4|admin');
        }
        $db = Database::getSqlite();
        $stmt = $db->query("SELECT COUNT(*) FROM login_attempts WHERE identifier_hash = ?");
        $stmt->execute([hash('sha256', '1.2.3.4|admin')]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'failures must upsert, not accumulate rows');
    }

    public function testDifferentIdentifiersAreIndependent(): void
    {
        for ($i = 0; $i < Auth::MAX_LOGIN_ATTEMPTS; $i++) {
            Auth::recordLoginFailure('1.2.3.4|admin');
        }
        $this->assertTrue(Auth::loginBlocked('1.2.3.4|admin'));
        $this->assertFalse(Auth::loginBlocked('5.6.7.8|other'), 'a different identifier is unaffected');
    }

    public function testSuccessfulLoginResetsTheWindow(): void
    {
        for ($i = 0; $i < Auth::MAX_LOGIN_ATTEMPTS; $i++) {
            Auth::recordLoginFailure('1.2.3.4|admin');
        }
        $this->assertTrue(Auth::loginBlocked('1.2.3.4|admin'));
        Auth::recordLoginSuccess('1.2.3.4|admin');
        $this->assertFalse(Auth::loginBlocked('1.2.3.4|admin'), 'a successful login clears the lockout');
    }
}
