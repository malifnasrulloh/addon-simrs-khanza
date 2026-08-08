<?php

namespace SatusehatPanel\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $sqlite = null;
    private static ?PDO $mysql = null;

    public static function getSqlite(): PDO
    {
        if (self::$sqlite === null) {
            $path = __DIR__ . '/../../config/database.php';
            $config = require $path;
            $sqlitePath = $config['sqlite']['path'];

            // Ensure directory exists
            $dir = dirname($sqlitePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            self::$sqlite = new PDO(
                "sqlite:{$sqlitePath}",
                null,
                null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            // Wait up to 5s for locks instead of failing instantly — parallel
            // FPM workers inserting audit rows can otherwise hit "database is locked"
            self::$sqlite->exec('PRAGMA busy_timeout = 5000');
            self::$sqlite->exec('PRAGMA journal_mode = WAL');

            self::migrateSqlite();
        }

        return self::$sqlite;
    }

    public static function getMysql(): PDO
    {
        if (self::$mysql === null) {
            $path = __DIR__ . '/../../config/database.php';
            $config = require $path;
            $mysqlConfig = $config['mysql'];

            self::$mysql = new PDO(
                "mysql:host={$mysqlConfig['host']};port={$mysqlConfig['port']};dbname={$mysqlConfig['database']};charset={$mysqlConfig['charset']}",
                $mysqlConfig['username'],
                $mysqlConfig['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        }

        return self::$mysql;
    }

    private static function migrateSqlite(): void
    {
        $db = self::$sqlite;

        // Audit logs table
        $db->exec("
            CREATE TABLE IF NOT EXISTS audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                patient_id VARCHAR(50) NOT NULL,
                resource_type VARCHAR(50) NOT NULL,
                resource_id VARCHAR(100),
                action VARCHAR(20) NOT NULL, -- 'send', 'retry', 'cancel'
                status VARCHAR(20) NOT NULL, -- 'success', 'failed', 'pending'
                request_payload TEXT,
                response_payload TEXT,
                error_message TEXT,
                user_identifier VARCHAR(100), -- IP or user identifier
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Retry queue
        $db->exec("
            CREATE TABLE IF NOT EXISTS retry_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                patient_id VARCHAR(50) NOT NULL,
                resource_type VARCHAR(50) NOT NULL,
                resource_id VARCHAR(100),
                payload TEXT NOT NULL,
                attempt_count INTEGER DEFAULT 0,
                max_attempts INTEGER DEFAULT 3,
                last_error TEXT,
                status VARCHAR(20) DEFAULT 'pending', -- 'pending', 'processing', 'success', 'failed'
                scheduled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Idempotency keys
        $db->exec("
            CREATE TABLE IF NOT EXISTS idempotency_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                key_hash VARCHAR(64) NOT NULL UNIQUE,
                patient_id VARCHAR(50) NOT NULL,
                resource_type VARCHAR(50) NOT NULL,
                resource_id VARCHAR(100),
                response_data TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME
            )
        ");

        // Indexes
        $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_patient ON audit_logs(patient_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs(created_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_retry_status ON retry_queue(status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_retry_patient ON retry_queue(patient_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_idempotency_key ON idempotency_keys(key_hash)");
    }
}
