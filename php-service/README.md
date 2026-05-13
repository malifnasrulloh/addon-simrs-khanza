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

---

# iCare Auto Approval Service (PHP)

PHP CLI script to automate the BPJS iCare validation and approval flow for outpatient (Ralan) patients. Replaces the manual JavaFX WebView-based approval process with a headless cURL-based flow.

## How It Works

1. **Fetch** today's Ralan patients from the database (with doctor BPJS mapping)
2. **Validate** each patient against BPJS wsihs API (`POST /api/RS/validate`)
3. **Decrypt** the response (AES-256-CBC + LZString decompression → iCare URL)
4. **Approve** via headless browser simulation (cURL cookie jar session)
5. **Cache** results to avoid re-processing on subsequent runs

## Quick Start

```bash
# 1. Configure iCare credentials in .env
nano .env   # Fill in ICARE_CONS_ID, ICARE_SECRET_KEY, ICARE_USER_KEY

# 2. Test with dry-run (DB query only, no API calls)
php icare_auto_approve.php --dry-run

# 3. Run for real
php icare_auto_approve.php

# 4. Run with debug output
php icare_auto_approve.php --verbose
```

## CLI Options

| Flag | Description |
|------|-------------|
| `--help` | Show help message |
| `--dry-run` | Query DB only, skip API calls and approval |
| `--verbose` | Enable DEBUG-level logging |
| `--no-cache` | Ignore daily cache, re-process all patients |

## iCare Environment Variables

| Variable | Required | Description |
|---|---|---|
| `ICARE_CONS_ID` | ✅ | Cons-ID for iCare HMAC signature |
| `ICARE_SECRET_KEY` | ✅ | Secret key for iCare HMAC-SHA256 |
| `ICARE_USER_KEY` | ✅ | User key for iCare API header |
| `ICARE_BASE_URL` | ✅ | iCare wsihs base URL |

## Patient Selection Logic

- Uses **NIK** (16-digit) as the primary identifier
- Falls back to **No Kartu BPJS** if NIK is empty or not 16 digits
- Skips patients without a BPJS doctor mapping (`maping_dokter_dpjpvclaim`)

## Caching

- Cache file: `logs/icare_cache_YYYY-MM-DD.json` (daily)
- Tracks each patient+doctor pair as `success` or `failed`
- On subsequent runs, successfully approved patients are skipped
- Use `--no-cache` to force re-processing all patients
- Old cache files are cleaned based on `LOG_RETENTION_DAYS`

## Cron Setup

```bash
# Every 30 minutes during work hours (Mon-Sat, 7am-5pm)
0,30 7-17 * * 1-6 cd /path/to/php-service && php icare_auto_approve.php >> /dev/null 2>&1
```

## File Structure

```
php-service/
├── icare_auto_approve.php     # Main entry point
├── lib/
│   ├── BPJSICareApi.php       # API client + AES decryption
│   ├── HeadlessApproval.php   # cURL browser simulation
│   ├── LZString.php           # LZString decompression (Java port)
│   ├── Logger.php             # File + console logging
│   └── PatientCache.php       # Daily JSON cache
└── logs/
    ├── icare_YYYY-MM-DD.log   # Daily log files
    └── icare_cache_YYYY-MM-DD.json  # Daily cache
```

## Logging

- **File**: `logs/icare_YYYY-MM-DD.log` (daily rotation)
- **Terminal**: All entries print to stdout/stderr
- Anti-bruteforce: random 2-5 second delays between patients

## Exit Codes

| Code | Meaning |
|------|---------|
| `0` | All patients processed successfully |
| `1` | Fatal error (config, DB, etc.) |
| `2` | Partial failure (some patients failed) |

---

# Mobile JKN Queue Sync Service (PHP)

PHP CLI replacement for the Java `KhanzaHMSServiceMobileJKN` Swing application.
Synchronizes patient queue data (Task IDs 3, 4, 5, 6, 7, 99) with the BPJS Mobile JKN Antrean API.

## How It Works

1. **Block 1 — New JKN Bookings**: Sends unsent bookings to `/antrean/add`, marks as `Sudah`
2. **Block 2 — Cancellations**: Sends cancellations to `/antrean/batal` + `taskid=99`
3. **Block 3 — JKN Task Updates**: Checks all 6 task triggers per patient in a single query, sends updates via `curl_multi`
4. **Block 4 — Non-JKN Patients**: Adds non-BPJS patients to queue + processes their task IDs

### Task ID Reference

| Task ID | Trigger | Meaning |
|---------|---------|---------|
| 3 | `mutasi_berkas.dikirim` | Patient file sent to polyclinic (waiting) |
| 4 | `mutasi_berkas.diterima` | Patient file received (service starts) |
| 5 | `pemeriksaan_ralan` | Outpatient examination completed |
| 6 | `resep_obat.tgl_perawatan` | Prescription created |
| 7 | `resep_obat.tgl_penyerahan` | Prescription dispensed |
| 99 | `reg_periksa.stts='Batal'` | Visit cancelled |

## Quick Start

```bash
# 1. Configure Mobile JKN credentials in .env
nano .env   # Fill in MOBILEJKN_BASE_URL (and optionally MOBILEJKN_CONS_ID etc.)

# 2. Test with dry-run (DB query only, no API calls)
php mobilejkn_sync.php --dry-run --verbose

# 3. Run for real
php mobilejkn_sync.php
```

## CLI Options

| Flag | Description |
|------|-------------|
| `--help` | Show help message with task ID reference |
| `--dry-run` | Query DB only, skip all API calls |
| `--verbose` | Enable DEBUG-level logging |

## Environment Variables

| Variable | Required | Description |
|---|---|---|
| `MOBILEJKN_BASE_URL` | ✅ | BPJS Mobile JKN Antrean API base URL |
| `MOBILEJKN_CONS_ID` | ❌ | Cons-ID (auto-uses `APLICARE_CONS_ID` if empty) |
| `MOBILEJKN_SECRET_KEY` | ❌ | Secret key (auto-uses `APLICARE_SECRET_KEY` if empty) |
| `MOBILEJKN_USER_KEY` | ❌ | User key (auto-uses `APLICARE_USER_KEY` if empty) |
| `MOBILEJKN_BATCH_SIZE` | ❌ | Concurrent HTTP requests (default: 4) |
| `MOBILEJKN_LOOKBACK_DAYS` | ❌ | Days to look back for unsent bookings (default: 6) |
| `MOBILEJKN_INCLUDE_NON_JKN` | ❌ | Include non-BPJS patients (default: true) |

## Cron Setup

```bash
# Every 10 minutes (matches original Java scheduler)
*/10 * * * * cd /path/to/php-service && php mobilejkn_sync.php >> /dev/null 2>&1

# Or with output to a cron log
*/10 * * * * cd /path/to/php-service && php mobilejkn_sync.php >> /var/log/mobilejkn-cron.log 2>&1
```

## File Structure

```
php-service/
├── mobilejkn_sync.php            # CLI entry point
├── lib/mobilejkn/
│   ├── Config.php                # Env loader + credential fallback
│   ├── Database.php              # PDO wrapper (optimized batch queries)
│   ├── BpjsAntreanClient.php     # API client with curl_multi
│   └── QueueProcessor.php        # Business logic orchestrator
└── logs/
    └── mobilejkn_YYYY-MM-DD.log  # Daily log files
```

## Key Improvements over Java

| Java (before) | PHP (after) |
|---|---|
| Swing JFrame GUI | Pure CLI (`php mobilejkn_sync.php`) |
| `ScheduledExecutorService` (10-min loop) | System cron (`*/10 * * * *`) |
| 6 queries per patient (N+1) | 1 batch query per block |
| Sequential HTTP calls | Parallel via `curl_multi_*` |
| Copy-pasted JKN/Non-JKN logic (~180 lines) | Unified `processTaskIdsForPatient()` |
| SQL string concatenation | Parameterized prepared statements |
| No retry on API failure | Auto-retry via DB rollback pattern |

## Exit Codes

| Code | Meaning |
|------|---------|
| `0` | All operations completed successfully |
| `1` | Fatal error (config, DB connection, etc.) |
| `2` | Partial failure (some API calls failed) |
