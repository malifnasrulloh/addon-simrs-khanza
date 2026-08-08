<?php

namespace SatusehatPanel\Controller;

use SatusehatPanel\Core\Database;

class AuditController
{
    /**
     * List audit log entries (most recent first).
     */
    public static function list(): array
    {
        $db = Database::getSqlite();

        $limit = min(max((int)($_GET['limit'] ?? 100), 1), 500);
        $patientFilter = $_GET['patient'] ?? '';
        $statusFilter  = $_GET['status'] ?? '';
        $since = self::validDate($_GET['since'] ?? '');
        $until = self::validDate($_GET['until'] ?? '');

        $sql = "SELECT id, patient_id, resource_type, action, status, error_message, user_identifier, created_at FROM audit_logs WHERE 1=1";
        $params = [];
        if ($patientFilter !== '') {
            $sql .= " AND patient_id = ?";
            $params[] = $patientFilter;
        }
        if ($statusFilter !== '') {
            $sql .= " AND status = ?";
            $params[] = $statusFilter;
        }
        if ($since !== '') {
            $sql .= " AND date(created_at) >= ?";
            $params[] = $since;
        }
        if ($until !== '') {
            $sql .= " AND date(created_at) <= ?";
            $params[] = $until;
        }
        $sql .= " ORDER BY created_at DESC, id DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll();

        return ['success' => true, 'data' => $logs];
    }

    /**
     * Get detail of a specific audit log including request/response payloads.
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
        return ['success' => true, 'data' => $row];
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
}
