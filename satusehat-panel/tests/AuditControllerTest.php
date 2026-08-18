<?php

declare(strict_types=1);

namespace SatusehatPanel\Tests;

use PHPUnit\Framework\TestCase;
use SatusehatPanel\Core\Database;

/**
 * Audit v2 backend: per-entry outcomes, rule filters, pagination, stats and
 * retention pruning against a temp SQLite store.
 */
final class AuditControllerTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = PANEL_TEST_STORAGE . '/audit-' . uniqid() . '.db';
        Database::setSqlitePathForTesting($this->dbPath);
        Database::getSqlite();
    }

    protected function tearDown(): void
    {
        Database::setSqlitePathForTesting(null);
        @unlink($this->dbPath);
    }

    private function seed(): void
    {
        $db = Database::getSqlite();
        $db->prepare("INSERT INTO audit_logs (patient_id, resource_type, action, status, request_payload, response_payload, error_message, user_identifier) VALUES (?, 'Condition', 'send', ?, '{}', '{}', ?, 'test')")
            ->execute(['V-1', 'success', null]);
        $auditId = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO send_entries (audit_id, patient_id, resource_type, key_hash, status, rule_number, issue_text) VALUES (?, 'V-1', 'Condition', 'k1', 'sent', NULL, NULL)")
            ->execute([$auditId]);

        $db->prepare("INSERT INTO audit_logs (patient_id, resource_type, action, status, request_payload, response_payload, error_message, user_identifier) VALUES (?, 'Bundle', 'send', 'partial', '{}', '{}', 'Some entries failed', 'test')")
            ->execute(['V-2']);
        $auditId2 = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO send_entries (audit_id, patient_id, resource_type, key_hash, status, rule_number, issue_text) VALUES (?, 'V-2', 'Condition', 'k2', 'sent', NULL, NULL)")
            ->execute([$auditId2]);
        $db->prepare("INSERT INTO send_entries (audit_id, patient_id, resource_type, key_hash, status, rule_number, issue_text) VALUES (?, 'V-2', 'Observation', 'k3', 'failed_rule', 10403, 'format datetime salah')")
            ->execute([$auditId2]);
    }

    public function testListReturnsPaginatedWithEntryCounts(): void
    {
        $this->seed();
        $res = \SatusehatPanel\Controller\AuditController::list();
        $this->assertTrue($res['success']);
        $this->assertSame(2, $res['meta']['total']);
        $this->assertCount(2, $res['data']);
        foreach ($res['data'] as $row) {
            $this->assertArrayHasKey('entry_count', $row);
            $this->assertArrayHasKey('sent_count', $row);
            $this->assertArrayHasKey('failed_count', $row);
        }
    }

    public function testRuleFilterFindsAffectedAudits(): void
    {
        $this->seed();
        $_GET['rule_number'] = '10403';
        $res = \SatusehatPanel\Controller\AuditController::list();
        $this->assertSame(1, $res['meta']['total']);
        $this->assertSame('V-2', $res['data'][0]['patient_id']);
        unset($_GET['rule_number']);
    }

    public function testStatusFilterPartial(): void
    {
        $this->seed();
        $_GET['status'] = 'partial';
        $res = \SatusehatPanel\Controller\AuditController::list();
        $this->assertSame(1, $res['meta']['total']);
        $this->assertSame('partial', $res['data'][0]['status']);
        unset($_GET['status']);
    }

    public function testDetailIncludesEntriesWithResolvedMessages(): void
    {
        $this->seed();
        $list = \SatusehatPanel\Controller\AuditController::list();
        $detail = \SatusehatPanel\Controller\AuditController::detail((int) $list['data'][0]['id']);
        $this->assertTrue($detail['success']);
        $this->assertNotEmpty($detail['data']['entries']);
        foreach ($detail['data']['entries'] as $e) {
            $this->assertArrayHasKey('rule_message', $e);
        }
    }

    public function testStatsAggregates(): void
    {
        $this->seed();
        $res = \SatusehatPanel\Controller\AuditController::stats();
        $this->assertSame(2, $res['data']['totals']['audits']);
        $this->assertSame(1, $res['data']['totals']['success']);
        $this->assertSame(50.0, $res['data']['totals']['success_rate']);
        $found = false;
        foreach ($res['data']['top_rules'] as $r) {
            if ($r['rule_number'] === 10403) {
                $found = true;
                $this->assertNotNull($r['message']);
            }
        }
        $this->assertTrue($found, 'top rules must include 10403 with a resolved message');
    }

    public function testPruneRemovesOldAuditsAndEntries(): void
    {
        $this->seed();
        $db = Database::getSqlite();
        $db->exec("UPDATE audit_logs SET created_at = datetime('now', '-200 days')");
        $marker = PANEL_BASE . '/storage/.audit_prune_marker';
        @unlink($marker);
        \SatusehatPanel\Controller\AuditController::list();
        $this->assertSame(0, (int) $db->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn());
        $this->assertSame(0, (int) $db->query("SELECT COUNT(*) FROM send_entries")->fetchColumn());
        @unlink($marker);
    }

    public function testPruneIsChunkedAcrossLargeSweeps(): void
    {
        // SQLite caps bound variables at 999; the prune deletes in chunks of
        // 400. A sweep larger than one chunk must fully drain, never fail.
        $db = Database::getSqlite();
        $db->beginTransaction();
        $stmt = $db->prepare("
            INSERT INTO audit_logs (patient_id, resource_type, action, status, request_payload, response_payload, user_identifier, created_at)
            VALUES (?, 'Condition', 'send', 'success', '{}', '{}', 'test', datetime('now', '-200 days'))
        ");
        for ($i = 0; $i < 450; $i++) {
            $stmt->execute(['V-P' . $i]);
            $auditId = (int) $db->lastInsertId();
            $db->prepare("INSERT INTO send_entries (audit_id, patient_id, resource_type, status) VALUES (?, 'V-P{$i}', 'Condition', 'sent')")
                ->execute([$auditId]);
        }
        $db->commit();

        $marker = PANEL_BASE . '/storage/.audit_prune_marker';
        @unlink($marker);
        \SatusehatPanel\Controller\AuditController::list();
        $this->assertSame(0, (int) $db->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn());
        $this->assertSame(0, (int) $db->query("SELECT COUNT(*) FROM send_entries")->fetchColumn());
        $this->assertFileExists($marker, 'marker must be written after a successful chunked prune');
        @unlink($marker);
    }

    public function testPruneKeepsFreshAudits(): void
    {
        $this->seed();
        $db = Database::getSqlite();
        // Age only the first audit (V-1); V-2 stays fresh.
        $db->exec("UPDATE audit_logs SET created_at = datetime('now', '-200 days') WHERE patient_id = 'V-1'");
        $marker = PANEL_BASE . '/storage/.audit_prune_marker';
        @unlink($marker);
        $res = \SatusehatPanel\Controller\AuditController::list();
        $this->assertSame(1, $res['meta']['total']);
        $this->assertSame('V-2', $res['data'][0]['patient_id']);
        @unlink($marker);
    }
}
