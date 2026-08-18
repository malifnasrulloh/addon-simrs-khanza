<?php

namespace SatusehatPanel\Controller;

use SatusehatPanel\Core\Database;
use SatusehatPanel\Core\Config;
use SatusehatPanel\Util\RuleNumberResolver;

class AuditController
{
    /**
     * List audit log entries (most recent first) with server-side
     * pagination + filters and per-entry outcome summaries.
     */
    public static function list(): array
    {
        self::pruneOldAudits();
        $db = Database::getSqlite();

        $page = max((int) ($_GET['page'] ?? 1), 1);
        $perPage = min(max((int) ($_GET['per_page'] ?? 25), 1), 200);
        $patientFilter = $_GET['patient'] ?? '';
        $statusFilter  = $_GET['status'] ?? '';   // success | failed | partial
        $ruleFilter    = (int) ($_GET['rule_number'] ?? 0);
        $since = self::validDate($_GET['since'] ?? '');
        $until = self::validDate($_GET['until'] ?? '');

        $where = "WHERE 1=1";
        $params = [];
        if ($patientFilter !== '') {
            $where .= " AND a.patient_id = ?";
            $params[] = $patientFilter;
        }
        if ($statusFilter !== '') {
            $where .= " AND a.status = ?";
            $params[] = $statusFilter;
        }
        if ($ruleFilter > 0) {
            // Audits containing at least one entry failing with this rule.
            $where .= " AND EXISTS (SELECT 1 FROM send_entries se WHERE se.audit_id = a.id AND se.rule_number = ?)";
            $params[] = $ruleFilter;
        }
        if ($since !== '') {
            // Sargable form: datetime(?, '+1 day') is applied to the constant,
            // not the column, so idx_audit_created stays usable.
            $where .= " AND a.created_at >= ?";
            $params[] = $since . ' 00:00:00';
        }
        if ($until !== '') {
            $where .= " AND a.created_at < datetime(?, '+1 day')";
            $params[] = $until;
        }

        $countStmt = $db->prepare("SELECT COUNT(*) FROM audit_logs a {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $pages = max((int) ceil($total / $perPage), 1);
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $stmt = $db->prepare("
            SELECT a.id, a.patient_id, a.resource_type, a.action, a.status,
                   a.error_message, a.user_identifier, a.created_at,
                   (SELECT COUNT(*) FROM send_entries se WHERE se.audit_id = a.id) AS entry_count,
                   (SELECT COUNT(*) FROM send_entries se WHERE se.audit_id = a.id AND se.status = 'sent') AS sent_count,
                   (SELECT COUNT(*) FROM send_entries se WHERE se.audit_id = a.id AND se.status != 'sent') AS failed_count
            FROM audit_logs a
            {$where}
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT ? OFFSET ?
        ");
        $params[] = $perPage;
        $params[] = $offset;
        $stmt->execute($params);
        $logs = $stmt->fetchAll();

        return [
            'success' => true,
            'data' => $logs,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'pages' => $pages,
            ],
        ];
    }

    /**
     * Detail of an audit log: request/response payloads + per-entry outcomes
     * with resolved RuleNumber messages.
     */
    public static function detail(int $id): array
    {
        $db = Database::getSqlite();
        $stmt = $db->prepare("SELECT * FROM audit_logs WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['success' => false, 'error' => 'Audit log not found'];
        }

        $entries = [];
        $stmt2 = $db->prepare("
            SELECT resource_type, status, rule_number, issue_text, satusehat_id, created_at
            FROM send_entries WHERE audit_id = ? ORDER BY id ASC
        ");
        $stmt2->execute([$id]);
        foreach ($stmt2->fetchAll() as $e) {
            $e['rule_message'] = $e['rule_number'] ? RuleNumberResolver::message((int) $e['rule_number']) : null;
            $entries[] = $e;
        }
        $row['entries'] = $entries;

        return ['success' => true, 'data' => $row];
    }

    /**
     * Aggregated stats for the ops view (T36): daily success rate and the
     * top failure rule numbers.
     */
    public static function stats(): array
    {
        $db = Database::getSqlite();

        $daily = $db->query("
            SELECT date(created_at) AS day,
                   COUNT(*) AS total,
                   SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS ok
            FROM audit_logs
            WHERE created_at >= datetime('now', '-30 days')
            GROUP BY date(created_at)
            ORDER BY day DESC
        ")->fetchAll();

        $ruleRows = $db->query("
            SELECT rule_number, COUNT(*) AS cnt
            FROM send_entries
            WHERE rule_number IS NOT NULL AND status != 'sent'
            GROUP BY rule_number
            ORDER BY cnt DESC
            LIMIT 20
        ")->fetchAll();

        $topRules = [];
        foreach ($ruleRows as $r) {
            $topRules[] = [
                'rule_number' => (int) $r['rule_number'],
                'count' => (int) $r['cnt'],
                'message' => RuleNumberResolver::message((int) $r['rule_number']),
            ];
        }

        $totals = $db->query("SELECT COUNT(*) AS total, SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) AS ok FROM audit_logs")->fetch();

        return [
            'success' => true,
            'data' => [
                'daily' => $daily,
                'top_rules' => $topRules,
                'totals' => [
                    'audits' => (int) ($totals['total'] ?? 0),
                    'success' => (int) ($totals['ok'] ?? 0),
                    'success_rate' => ($totals['total'] ?? 0) > 0 ? round(((int) $totals['ok']) / (int) $totals['total'] * 100, 1) : null,
                ],
            ],
        ];
    }

    /**
     * CSV export of audit rows (matching the current filters).
     */
    public static function export(): void
    {
        $db = Database::getSqlite();

        $where = "WHERE 1=1";
        $params = [];
        foreach (['patient', 'status', 'since', 'until'] as $key) {
            if ($key === 'patient' && ($_GET['patient'] ?? '') !== '') {
                $where .= " AND patient_id = ?";
                $params[] = $_GET['patient'];
            }
            if ($key === 'status' && ($_GET['status'] ?? '') !== '') {
                $where .= " AND status = ?";
                $params[] = $_GET['status'];
            }
            if ($key === 'since' && self::validDate($_GET['since'] ?? '') !== '') {
                $where .= " AND created_at >= ?";
                $params[] = self::validDate($_GET['since']) . ' 00:00:00';
            }
            if ($key === 'until' && self::validDate($_GET['until'] ?? '') !== '') {
                $where .= " AND created_at < datetime(?, '+1 day')";
                $params[] = self::validDate($_GET['until']);
            }
        }

        $stmt = $db->prepare("
            SELECT id, patient_id, resource_type, status, error_message, user_identifier, created_at
            FROM audit_logs {$where} ORDER BY created_at DESC
        ");
        $stmt->execute($params);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="satusehat-audit-' . date('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'No. Rawat', 'Resource', 'Status', 'Error', 'User', 'Waktu']);
        while ($row = $stmt->fetch()) {
            fputcsv($out, [
                $row['id'],
                $row['patient_id'],
                $row['resource_type'],
                $row['status'],
                $row['error_message'],
                $row['user_identifier'],
                $row['created_at'],
            ]);
        }
        fclose($out);
        exit;
    }

    /**
     * Validate an optional Y-m-d query parameter.
     * Returns '' for empty/invalid input (caller treats as "no constraint").
     */
    private static function validDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $d = \DateTime::createFromFormat('Y-m-d', $value);
        if ($d === false || $d->format('Y-m-d') !== $value) {
            return '';
        }
        return $value;
    }

    /**
     * Retention policy (T36): prune audit_logs + their send_entries older
     * than AUDIT_RETENTION_DAYS (default 90). Runs at most once per day —
     * the marker file avoids a sweep on every request.
     *
     * The marker is written only AFTER a successful prune so a failure is
     * retried on the next request, and the DELETE is chunked (SQLite caps
     * bound variables at 999) so large sweeps can't silently fail.
     */
    private static function pruneOldAudits(): void
    {
        $days = max(7, (int) Config::env('AUDIT_RETENTION_DAYS', '90'));
        $marker = __DIR__ . '/../../storage/.audit_prune_marker';
        if (is_file($marker) && (time() - (int) filemtime($marker)) < 86400) {
            return;
        }

        try {
            $db = Database::getSqlite();
            $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
            $stmt = $db->prepare("SELECT id FROM audit_logs WHERE created_at < ?");
            $stmt->execute([$cutoff]);
            $ids = array_column($stmt->fetchAll(), 'id');
            if (!empty($ids)) {
                foreach (array_chunk($ids, 400) as $chunk) {
                    $ph = implode(',', array_fill(0, count($chunk), '?'));
                    $db->prepare("DELETE FROM send_entries WHERE audit_id IN ({$ph})")->execute($chunk);
                    $db->prepare("DELETE FROM audit_logs WHERE id IN ({$ph})")->execute($chunk);
                }
            }
            @file_put_contents($marker, (string) time());
        } catch (\Throwable $e) {
            error_log('[PANEL] pruneOldAudits: ' . $e->getMessage());
        }
    }
}
