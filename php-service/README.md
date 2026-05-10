# Aplicare Bed Availability Update Service (PHP)

PHP CLI replacement for the Java `KhanzaHMSServiceAplicare` Swing application.  
Pushes bed availability data from SIMRS Khanza to BPJS Aplicare API.

## Requirements

- PHP 8.1+ with extensions: `pdo_mysql`, `curl`, `json`, `mbstring`, `openssl`
- MySQL/MariaDB access to SIMRS Khanza database

## Quick Start

```bash
# 1. Navigate to the service directory
cd php-service/

# 2. Copy and configure environment
cp .env.example .env
nano .env   # Fill in your credentials

# 3. Test with dry-run (no API calls)
php aplicare_update.php --dry-run

# 4. Run for real
php aplicare_update.php
```

## CLI Options

| Flag        | Description                                |
|-------------|--------------------------------------------|
| `--help`    | Show help message                          |
| `--dry-run` | Simulate without sending API requests      |
| `--verbose` | Enable DEBUG-level output in terminal      |

## Cron Setup

```bash
# Run every 5 minutes (matches original Java scheduler)
*/5 * * * * cd /path/to/php-service && php aplicare_update.php >> /dev/null 2>&1

# Or capture output to a separate cron log
*/5 * * * * cd /path/to/php-service && php aplicare_update.php >> /var/log/aplicare-cron.log 2>&1
```

## Environment Variables

See `.env.example` for all available configuration.

| Variable | Required | Description |
|---|---|---|
| `DB_HOST` | ✅ | MySQL host |
| `DB_PORT` | ❌ | MySQL port (default: 3306) |
| `DB_NAME` | ✅ | Database name |
| `DB_USER` | ✅ | Database user |
| `DB_PASS` | ❌ | Database password |
| `APLICARE_CONS_ID` | ✅ | Cons-ID for HMAC signature |
| `APLICARE_SECRET_KEY` | ✅ | Secret key for HMAC-SHA256 |
| `APLICARE_HEADER_CONS_ID` | ❌ | Cons-ID for X-Cons-ID header (defaults to CONS_ID) |
| `APLICARE_BASE_URL` | ✅ | Aplicare API base URL |
| `KODE_PPK` | ❌ | Provider code (auto-fetched from `setting` table if empty) |
| `LOG_LEVEL` | ❌ | DEBUG/INFO/WARNING/ERROR (default: INFO) |
| `LOG_RETENTION_DAYS` | ❌ | Auto-delete logs older than N days (default: 30) |

## Logging

- **File**: `logs/aplicare_YYYY-MM-DD.log` (daily rotation)
- **Terminal**: All log entries also print to stdout/stderr
- Old log files are automatically cleaned based on `LOG_RETENTION_DAYS`

## Exit Codes

| Code | Meaning |
|------|---------|
| `0` | All rooms updated successfully |
| `1` | Fatal error (config, DB, etc.) |
| `2` | Partial failure (some rooms failed) |

## Migration from Java

This service replaces the `frmUtama.java` Swing GUI scheduler.

| Java (before) | PHP (after) |
|---|---|
| `koneksiDB.condb()` + AES-encrypted XML | `.env` file with plain credentials |
| `BPJSSecurityUtil.generateSignaturePair()` | `generateSignature()` (HMAC-SHA256) |
| `AplicareHelper.buildUpdateKamarJson()` | Inline `json_encode()` |
| `ScheduledExecutorService` (5-min loop) | System cron (`*/5 * * * *`) |
| Swing JTable log display | Terminal + file logging |
