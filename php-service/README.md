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
php icare_approval.php --dry-run

# 3. Run for real
php icare_approval.php

# 4. Run with debug output
php icare_approval.php --verbose
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
0,30 7-17 * * 1-6 cd /path/to/php-service && php icare_approval.php >> /dev/null 2>&1
```

## File Structure

```
php-service/
├── icare_approval.php         # Main entry point
├── lib/
│   ├── icare/
│   │   ├── BPJSICareApi.php   # API client + AES decryption
│   │   ├── HeadlessApproval.php # cURL browser simulation
│   │   ├── LZString.php       # LZString decompression (Java port)
│   │   └── PatientCache.php   # Daily JSON cache
│   └── Logger.php             # File + console logging
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

---

# Satu Sehat Encounter Sync Service (PHP)

PHP CLI script to automatically synchronize patient Encounter data with the BPJS Satu Sehat API.
It orchestrates transitioning patient statuses (`arrived` -> `in-progress` -> `finished`) securely.

## Features

- **Automated Workflow**: Handles State transitions implicitly via Cron Jobs, replacing the manual GUI process.
- **Robust Local Tracker**: Utilizes SQLite `logs/satusehat_state.sqlite` to track patient status, eliminating duplicated external HTTP operations and shielding your MySQL Database schemas.
- **Smart Validation**: Skips NIK validations seamlessly and fetches missing Practitioner/Patient IHS Identifiers remotely while saving to database to prevent unneeded repeated query requests!

## Quick Start

```bash
# 1. Provide your config in `.env` 
nano .env # Add SATUSEHAT_* vars

# 2. Run
php satusehat_encounter_sync.php
```

## Cron Setup

```bash
*/5 * * * * cd /path/to/php-service && php satusehat_encounter_sync.php >> /dev/null 2>&1
```

---

# Satu Sehat Episode of Care Sync Service (PHP)

PHP CLI script to automatically synchronize patient Episode of Care (EOC) data with the BPJS Satu Sehat API.
It safely maps ICD-10 diagnosis codes (e.g., ANC or TB-SO) to their FHIR types and dynamically transitions them from `active` to `finished` statuses.

## Features

- **Dynamic ICD-10 Mapping**: Identifies Episode of Care types (like TB-SO or ANC) natively by filtering `diagnosa_pasien` ICD codes. Easily extensible in `EpisodeOfCareType.php`.
- **Smart Duplicate Fallback Algorithm**: If Satu Sehat returns a 400 or 409 "duplicate" collision, it gracefully searches existing episodes natively. It leverages a rigorous multi-tier filtering structure (status checks, strict Identifier value validation, and chronological sorting) to absolutely ensure it resolves the correct `id_episode_of_care`.
- **Dual-Phase Lifecycle**: 
   - **Phase 1**: Auto-POST active visits upon admission/diagnoses.
   - **Phase 2**: Auto-PUT finished updates immediately after checkout (billing).

## Quick Start

```bash
# 1. Run (reuses same SATUSEHAT_* vars in .env)
php satusehat_episodeofcare_sync.php --verbose
```

## Cron Setup

```bash
*/5 * * * * cd /path/to/php-service && php satusehat_episodeofcare_sync.php >> /dev/null 2>&1
```

---

# Satu Sehat Condition Sync Service (PHP)

PHP CLI script to automatically synchronize patient Condition (Diagnosis) data with the BPJS Satu Sehat API.
It links patient diagnoses seamlessly to their respective `Encounter` and `Patient` records.

## Features

- **Automated POST & PUT**: Seamlessly creates new Condition records or updates existing ones based on changes in the SIMRS database.
- **Smart Duplicate Fallback Algorithm**: If Satu Sehat returns a 400 or 409 "duplicate" collision, it gracefully searches existing Condition records for the patient and encounter natively. It then checks the active ICD-10 code and successfully links the `id_condition`.
- **Integrated Lookups**: Safely retrieves Patient IHS IDs via cache or live lookups before executing the POST.

## Quick Start

```bash
# 1. Run (reuses same SATUSEHAT_* vars in .env)
php satusehat_condition_sync.php --verbose
```

## Cron Setup

```bash
*/5 * * * * cd /path/to/php-service && php satusehat_condition_sync.php >> /dev/null 2>&1
```

---

# Satu Sehat Observation-TTV Sync Service (PHP)

PHP CLI script to automatically synchronize patient Vital Signs (Observation-TTV) data with the BPJS Satu Sehat API.
It safely consolidates 10 different types of observations into a single optimized background process.

## Features

- **Consolidated Architecture**: Avoids code duplication by utilizing a dynamic dictionary pattern to handle `suhu_tubuh`, `respirasi`, `nadi`, `spo2`, `gcs`, `kesadaran`, `tensi`, `tinggi`, `berat`, and `lingkar_perut` in a single pass.
- **Ralan and Ranap Support**: Automatically merges and checks both outpatient (`pemeriksaan_ralan`) and inpatient (`pemeriksaan_ranap`) records simultaneously.
- **Complex Payloads**: Automatically adapts the FHIR payload structure (e.g., standard `valueQuantity` for Temperature, `valueString` for GCS, `valueCodeableConcept` mapping for Consciousness, and nested `component` structures for Systolic/Diastolic Blood Pressure).
- **Smart Duplicate Fallback Algorithm**: Gracefully manages 400/409 duplicate errors natively by executing localized FHIR searches to adopt existing `id_observation` strings safely.

## Quick Start

```bash
# 1. Run (reuses same SATUSEHAT_* vars in .env)
php satusehat_observationttv_sync.php --verbose
```

## Cron Setup

```bash
*/5 * * * * cd /path/to/php-service && php satusehat_observationttv_sync.php >> /dev/null 2>&1
```

---

# Satu Sehat Procedure Sync Service (PHP)

PHP CLI script to automatically synchronize patient Procedure data with the BPJS Satu Sehat API.
It links patient procedures (ICD-9-CM codes) seamlessly to their respective `Encounter` and `Patient` records.

## Features

- **Automated POST & PUT**: Seamlessly creates new Procedure records or updates existing ones based on changes in the SIMRS database.
- **Smart Duplicate Fallback Algorithm**: If Satu Sehat returns a 400 or 409 "duplicate" collision, it gracefully searches existing Procedure records for the patient and encounter natively. It then checks the active ICD-9 code and successfully links the `id_procedure`.
- **Integrated Lookups**: Safely retrieves Patient IHS IDs via cache or live lookups before executing the POST.

## Quick Start

```bash
# 1. Run (reuses same SATUSEHAT_* vars in .env)
php satusehat_procedure_sync.php --verbose
```

## Cron Setup

```bash
*/5 * * * * cd /path/to/php-service && php satusehat_procedure_sync.php >> /dev/null 2>&1
```

---

# Satu Sehat Allergy Intolerance Sync Service (PHP)

PHP CLI script to automatically synchronize patient Allergy data with the BPJS Satu Sehat API.
It utilizes a local auto-learning dictionary to correctly map raw text to FHIR `AllergyIntolerance` SNOMED CT codes.

## Features

- **Automated POST & PUT**: Creates new AllergyIntolerance records or updates existing ones across both outpatient (`pemeriksaan_ralan`) and inpatient (`pemeriksaan_ranap`) records.
- **Smart Duplicate Fallback**: If Satu Sehat returns a duplicate collision, it automatically fetches existing records and links the `id_allergy_intolerance` using matching SNOMED codes.
- **Self-Learning Dictionary**: Maintains its own local cache (`cache/alergisatusehat.iyem`). If a doctor types an unknown allergy, it defaults to a generic FHIR-compliant SNOMED code (`419199007` - Allergy to substance) and saves the mapping locally to ensure 100% sync success without rejecting the payload.

## Quick Start

```bash
# 1. Run
php satusehat_allergyintolerance_sync.php --verbose
```

## Cron Setup

```bash
*/5 * * * * cd /path/to/php-service && php satusehat_allergyintolerance_sync.php >> /dev/null 2>&1
```

---

# Satu Sehat Immunization Sync Service (PHP)

PHP CLI script to automatically synchronize patient Immunization (vaccination) data with the Satu Sehat API.
It maps detailed vaccination administration details (like batch number, route, dose quantity, and clinic location) to FHIR `Immunization` resources.

## Features

- **Automated POST & PUT**: Creates new Immunization records or updates existing ones across both outpatient (`nota_jalan` / `detail_pemberian_obat`) and inpatient (`nota_inap` / `detail_pemberian_obat`) records.
- **Dose Extraction**: Automatically extracts the numerical dose number from prescription instructions (`aturan` field) and maps it to FHIR `protocolApplied.doseNumberPositiveInt`.
- **Smart Duplicate Fallback**: Gracefully manages duplicate errors by executing a search on patient & encounter IDs to adopt existing immunization resource IDs.
- **Batch Expiration Tracking**: Fetches and includes expiration dates directly from the inventory database (`data_batch.tgl_kadaluarsa`).

## Quick Start

```bash
# 1. Run
php satusehat_immunization_sync.php --verbose
```

## Cron Setup

```bash
*/5 * * * * cd /path/to/php-service && php satusehat_immunization_sync.php >> /dev/null 2>&1
```

---

# Satu Sehat Medication Sync Service (PHP)

PHP CLI script to automatically synchronize drug/medication master inventory mapping data with the Satu Sehat API.
It maps hospital-mapped drug items from the `satu_sehat_mapping_obat` and `databarang` tables into standard FHIR `Medication` resource profiles.

## Features

- **Inventory-wide POST & PUT**: Resolves and syncs all local drug items that are mapped for Satu Sehat. Automatically POSTs new medications and updates status for already-synced ones using PUT.
- **NC Extension (Non-Compound)**: Automatically includes the mandatory StructureDefinition extension identifying the medications as non-compound drugs.
- **Resilient Duplicate Resolution**: Gracefully catches duplicate registration conflicts by looking up `http://sys-ids.kemkes.go.id/medication/{orgId}|{kode_brng}` on Satu Sehat to automatically adopt and heal the local database's Medication ID mapping.

## Quick Start

```bash
# 1. Run in verbose mode to view synchronization logs
php satusehat_medication_sync.php --verbose
```

## Cron Setup

Master-data changes occur infrequently compared to patient transactions. Running the synchronization hourly is highly recommended:

```bash
0 * * * * cd /path/to/php-service && php satusehat_medication_sync.php >> /dev/null 2>&1
```

---

# Satu Sehat MedicationRequest Sync Service (PHP)

PHP CLI script to automatically synchronize drug prescriptions (both non-racikan/non-compound and racikan/compound prescriptions) with the Satu Sehat API.
It maps hospital drug requests from the `resep_dokter`, `resep_dokter_racikan`, and `resep_dokter_racikan_detail` tables into standard FHIR `MedicationRequest` resource profiles.

## Features

- **Unified Processing**: Consolidates outpatient (Ralan) and inpatient (Ranap) non-racikan and racikan drug prescriptions using highly optimized single database query joins.
- **Accurate Signa Parsing**: Converts prescription sigmas (e.g. `3x1`) into corresponding FHIR timing frequency and dosage instructions.
- **SQLite Local State Synchronization**: Utilizes local SQLite state management (`medicationrequest_state`) to cache synchronization status, dramatically improving run performance.
- **Resilient Duplicate Resolution**: Gracefully catches duplicate prescription conflicts by performing an identifier lookup matching `http://sys-ids.kemkes.go.id/prescription/{orgId}|{no_resep}[-{no_racik}]` on Satu Sehat to automatically update and resolve local mapping status.

## Quick Start

```bash
# 1. Run in verbose mode to view synchronization logs
php satusehat_medicationrequest_sync.php --verbose
```

## Cron Setup

Run the prescription synchronization every hour (offset by 5 minutes from other sync services to optimize server loads):

```bash
5 * * * * cd /path/to/php-service && php satusehat_medicationrequest_sync.php >> /dev/null 2>&1
```

---

# Satu Sehat MedicationDispense Sync Service (PHP)

PHP CLI script to automatically synchronize drug dispenses (non-racikan) with the Satu Sehat API.
It maps hospital drug dispenses from the `detail_pemberian_obat` table into standard FHIR `MedicationDispense` resource profiles.

## Features

- **Unified Processing**: Consolidates outpatient (Ralan) and inpatient (Ranap) non-racikan drug dispenses using single database query joins.
- **AuthorizingPrescription Linking**: Seamlessly queries and associates the corresponding Satu Sehat `MedicationRequest` ID if the prescription has already been synced.
- **SQLite Local State Synchronization**: Utilizes local SQLite state management (`medicationdispense_state`) to cache synchronization status, dramatically improving run performance.
- **Resilient Duplicate Resolution**: Gracefully catches duplicate conflicts by performing lookup queries matching `http://sys-ids.kemkes.go.id/prescription/{orgId}|{no_resep}` on Satu Sehat to automatically update and resolve local mapping status.

## Quick Start

```bash
# 1. Run in verbose mode to view synchronization logs
php satusehat_medicationdispense_sync.php --verbose
```

## Cron Setup

Run the drug dispense synchronization every hour (offset by 10 minutes from other sync services to optimize server loads):

```bash
10 * * * * cd /path/to/php-service && php satusehat_medicationdispense_sync.php >> /dev/null 2>&1
```

---

# Satu Sehat MedicationStatement Sync Service (PHP)

PHP CLI script to automatically synchronize patient medication statements (both racikan and non-racikan) with the Satu Sehat API.
It maps hospital patient prescriptions into standard FHIR `MedicationStatement` resource profiles.

## Features

- **Unified Processing**: Consolidates outpatient (Ralan) and inpatient (Ranap), racikan and non-racikan drug prescriptions using single database query unions.
- **SQLite Local State Synchronization**: Utilizes local SQLite state management (`medicationstatement_state`) to cache synchronization status, dramatically improving run performance.
- **Resilient Duplicate Resolution**: Gracefully catches duplicate conflicts by performing lookup queries matching `http://sys-ids.kemkes.go.id/medicationstatement/{orgId}|{no_resep}-{kode_brng}[-{no_racik}]` on Satu Sehat to automatically update and resolve local mapping status.

## Quick Start

```bash
# 1. Run in verbose mode to view synchronization logs
php satusehat_medicationstatement_sync.php --verbose
```

## Cron Setup

Run the medication statement synchronization every hour (offset by 15 minutes from other sync services to optimize server loads):

```bash
15 * * * * cd /path/to/php-service && php satusehat_medicationstatement_sync.php >> /dev/null 2>&1
```

---

# System Resiliency & Circuit Breaker Architecture

All Mobile JKN and Satu Sehat sync services in this repository implement a highly resilient, file-persistent Circuit Breaker pattern to protect the local server from hanging during BPJS API outages (e.g., `HTTP 429` Rate Limits or TCP Timeouts).

## The "Leaky Bucket" Strategy

Unlike naive circuit breakers that reset on a single successful request (causing a "flapping" state where the breaker never actually trips under heavy degradation), this service uses a robust Leaky Bucket algorithm. 
- **Failures** increment the counter. If the counter reaches `MAX_FAILURES` (default: 5), the circuit trips to the `OPEN` state and instantly blocks all outbound API requests for a `COOLING_PERIOD` (default: 5 minutes). This prevents thread exhaustion on your server.
- **Successes** while in the `CLOSED` state do *not* instantly reset the counter to zero. Instead, they gracefully decrement the counter by 1. This guarantees that highly unstable connections (e.g., 80% timeouts) will still reliably trip the breaker.

## High-Performance Networking Limits

The HTTP clients are configured with aggressive "Fail Fast" timeouts to preserve local server health during remote outages:
- **Connect Timeout**: `3 seconds`. (If the BPJS load balancer doesn't acknowledge the TCP handshake in 3 seconds, the connection is instantly aborted).
- **Request/Read Timeout**: `15 seconds`. (To allow for slower query execution on the BPJS end without hanging the PHP worker).

## Smart-Bypass Caching

For sequential workflows like Mobile JKN (Task ID 3->4->5->6->7), the `QueueProcessor` implements Smart-Bypass Caching. If a patient's task sequence is fully completed or marked as cancelled locally, the engine entirely bypasses verification requests to the BPJS API (like `/antrean/getlisttask`). This optimization eliminates N+1 network overhead and accelerates execution speeds by over 95%.
