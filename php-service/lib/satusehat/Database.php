<?php

/**
 * Database - PDO Wrapper for MySQL & SQLite (for state tracking)
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class SatuSehatDatabase
{
    private PDO $mysql;
    private PDO $sqlite;
    private Logger $log;
    private SatuSehatClient $client;
    private SatuSehatConfig $config;
    private $lockFile;
    private static ?int $parentPid = null;

    public function __construct(SatuSehatConfig $config, Logger $log, SatuSehatClient $client)
    {
        $this->config = $config;
        $this->log = $log;
        $this->client = $client;

        if (self::$parentPid === null) {
            self::$parentPid = getmypid() ?: null;
        }

        // ── Process Lock to Prevent Cron Overlap (Only for Parent Process)
        if (getmypid() === self::$parentPid) {
            $lockName = defined('SERVICE_NAME') ? SERVICE_NAME : 'satusehat_default';
            $lockFilePath = sys_get_temp_dir() . '/' . preg_replace('/[^a-zA-Z0-9_]/', '', $lockName) . '.lock';
            $this->lockFile = fopen($lockFilePath, 'c');
            if ($this->lockFile) {
                if (!flock($this->lockFile, LOCK_EX | LOCK_NB)) {
                    $this->log->warning("[LOCK] Another instance of {$lockName} is already running. Exiting.");
                    exit(0);
                }
            }
        }

        // ── MySQL Connection
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->dbHost, $config->dbPort, $config->dbName);
        
        $this->mysql = new PDO($dsn, $config->dbUser, $config->dbPass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // ── SQLite Local State Tracking
        $sqlitePath = rtrim($config->logDir, '/') . '/satusehat_state.sqlite';
        $this->sqlite = new PDO("sqlite:{$sqlitePath}");
        $this->sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->sqlite->exec("PRAGMA journal_mode=WAL;");
        
        // Ensure table exists
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS encounter_state (
            no_rawat VARCHAR(50) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for Episode of Care state tracking
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS episode_of_care_state (
            no_rawat VARCHAR(50) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for Condition state tracking
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS condition_state (
            composite_key VARCHAR(100) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for Observation-TTV state tracking
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS observationttv_state (
            composite_key VARCHAR(100) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for Procedure state tracking
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS procedure_state (
            composite_key VARCHAR(100) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for CarePlan state tracking
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS careplan_state (
            composite_key VARCHAR(100) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for AllergyIntolerance state tracking
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS allergyintolerance_state (
            composite_key VARCHAR(100) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for Immunization state tracking
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS immunization_state (
            composite_key VARCHAR(100) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for Medication state tracking
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS medication_state (
            kode_brng VARCHAR(50) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for MedicationRequest state tracking
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS medicationrequest_state (
            composite_key VARCHAR(100) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for MedicationDispense state tracking
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS medicationdispense_state (
            composite_key VARCHAR(150) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for MedicationStatement state tracking
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS medicationstatement_state (
            composite_key VARCHAR(150) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for Clinical Impression state tracking
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS clinical_impression_state (
            composite_key VARCHAR(150) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for QuestionnaireResponse state tracking
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS questionnaireresponse_state (
            no_resep VARCHAR(50) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for DiagnosticReport Lab PK state tracking
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS diagnosticreport_lab_pk_state (
            composite_key VARCHAR(150) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for DiagnosticReport Lab MB state tracking
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS diagnosticreport_lab_mb_state (
            composite_key VARCHAR(150) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for Observation Lab PK state tracking
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS observation_lab_pk_state (
            composite_key VARCHAR(150) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for Observation Lab MB state tracking
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS observation_lab_mb_state (
            composite_key VARCHAR(150) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for Patient lookup failure state tracking (TTL cache)
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS patient_sync_state (
            nik VARCHAR(20) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for ServiceRequest Lab PK state tracking
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS servicerequest_lab_pk_state (
            composite_key VARCHAR(150) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for ServiceRequest Lab MB state tracking
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS servicerequest_lab_mb_state (
            composite_key VARCHAR(150) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for Specimen Lab PK state tracking
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS specimen_lab_pk_state (
            composite_key VARCHAR(150) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for Specimen Lab MB state tracking
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS specimen_lab_mb_state (
            composite_key VARCHAR(150) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for ServiceRequest Radiologi state tracking
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS servicerequest_radiologi_state (
            composite_key VARCHAR(150) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for Specimen Radiologi state tracking
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS specimen_radiologi_state (
            composite_key VARCHAR(150) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for Observation Radiologi state tracking
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS observation_radiologi_state (
            composite_key VARCHAR(150) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for DiagnosticReport Radiologi state tracking
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS diagnosticreport_radiologi_state (
            composite_key VARCHAR(150) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Table for ImagingStudy Radiologi state tracking
        $this->sqlite->exec("CREATE TABLE IF NOT EXISTS imagingstudy_radiologi_state (
            composite_key VARCHAR(150) PRIMARY KEY,
            status VARCHAR(20),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }

    public function close(): void
    {
        // We do NOT unlock or close $this->lockFile here.
        // In parallel mode (--parallel), $db->close() is called before fork to avoid shared-connection issues.
        // If we release the lock here, a new cron instance could start while workers are still running.
        // Instead, we let the OS automatically release the lock when the parent process exits.
        unset($this->mysql);
        unset($this->sqlite);
    }

    public function getMysql(): PDO
    {
        return $this->mysql;
    }

    // ─── STATE TRACKING ────────────────────────────────────────────────────────

    public function getLocalState(string $noRawat): ?string
    {
        $stmt = $this->sqlite->prepare("SELECT status FROM encounter_state WHERE no_rawat = :nr");
        $stmt->execute(['nr' => $noRawat]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public function updateLocalState(string $noRawat, string $status): void
    {
        $stmt = $this->sqlite->prepare("
            INSERT INTO encounter_state (no_rawat, status, updated_at) 
            VALUES (:nr, :st, CURRENT_TIMESTAMP)
            ON CONFLICT(no_rawat) DO UPDATE SET status = excluded.status, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['nr' => $noRawat, 'st' => $status]);
    }

    // ─── MYSQL ENCOUNTER OPERATIONS ────────────────────────────────────────────

    /**
     * Fetch pending 'arrived' encounters (Registered but not in satu_sehat_encounter).
     */
    public function fetchPendingArrived(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT 
                rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, 
                p.nm_pasien, p.no_ktp, rp.kd_dokter, pg.nama, pg.no_ktp as ktpdokter, 
                rp.kd_poli, pol.nm_poli, smlr.id_lokasi_satusehat, rp.stts, rp.status_lanjut
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN pegawai pg ON pg.nik = rp.kd_dokter
            INNER JOIN poliklinik pol ON rp.kd_poli = pol.kd_poli
            INNER JOIN satu_sehat_mapping_lokasi_ralan smlr ON smlr.kd_poli = pol.kd_poli
            WHERE rp.status_bayar = 'Sudah Bayar' 
              AND rp.tgl_registrasi BETWEEN :df AND :dt
              AND rp.no_rawat NOT IN (SELECT no_rawat FROM satu_sehat_encounter)
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    /**
     * Fetch encounters needing 'in-progress' state.
     * Must be in satu_sehat_encounter, have a medical check time, and local state < 'in-progress'
     */
    public function fetchPendingInProgress(string $dateFrom, string $dateTo): array
    {
        // For simplicity, Java used tgl_registrasi for in-progress start, or tgl_perawatan from pemeriksaan_ralan
        $sql = "
            SELECT 
                rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, 
                p.nm_pasien, p.no_ktp, rp.kd_dokter, pg.nama, pg.no_ktp as ktpdokter, 
                rp.kd_poli, pol.nm_poli, smlr.id_lokasi_satusehat, rp.stts, rp.status_lanjut,
                sse.id_encounter,
                CONCAT(pr.tgl_perawatan, 'T', pr.jam_rawat, '+07:00') as waktu_perawatan
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN pegawai pg ON pg.nik = rp.kd_dokter
            INNER JOIN poliklinik pol ON rp.kd_poli = pol.kd_poli
            INNER JOIN satu_sehat_mapping_lokasi_ralan smlr ON smlr.kd_poli = pol.kd_poli
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            INNER JOIN pemeriksaan_ralan pr ON pr.no_rawat = rp.no_rawat
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    /**
     * Fetch encounters needing 'finished' state.
     * Must be in satu_sehat_encounter, have billing time (nota_jalan/nota_inap).
     */
    public function fetchPendingFinished(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT 
                rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, 
                p.nm_pasien, p.no_ktp, rp.kd_dokter, pg.nama, pg.no_ktp as ktpdokter, 
                rp.kd_poli, pol.nm_poli, smlr.id_lokasi_satusehat, rp.stts, rp.status_lanjut,
                sse.id_encounter,
                CASE 
                    WHEN rp.status_lanjut = 'Ralan' THEN CONCAT(nj.tanggal, 'T', nj.jam, '+07:00') 
                    WHEN rp.status_lanjut = 'Ranap' THEN CONCAT(ni.tanggal, 'T', ni.jam, '+07:00') 
                END as waktu_pulang,
                pr.tgl_perawatan, pr.jam_rawat
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN pegawai pg ON pg.nik = rp.kd_dokter
            INNER JOIN poliklinik pol ON rp.kd_poli = pol.kd_poli
            INNER JOIN satu_sehat_mapping_lokasi_ralan smlr ON smlr.kd_poli = pol.kd_poli
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            LEFT JOIN nota_jalan nj ON nj.no_rawat = rp.no_rawat
            LEFT JOIN nota_inap ni ON ni.no_rawat = rp.no_rawat
            LEFT JOIN pemeriksaan_ralan pr ON pr.no_rawat = rp.no_rawat
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
              AND (nj.tanggal IS NOT NULL OR ni.tanggal IS NOT NULL)
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function fetchDiagnoses(string $noRawat): array
    {
        $sql = "
            SELECT 
                ssc.id_condition, p.nm_penyakit, dp.prioritas 
            FROM satu_sehat_condition ssc
            INNER JOIN penyakit p ON ssc.kd_penyakit = p.kd_penyakit 
            INNER JOIN diagnosa_pasien dp ON ssc.kd_penyakit = dp.kd_penyakit 
            WHERE ssc.no_rawat = :nr 
            ORDER BY dp.prioritas ASC
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['nr' => $noRawat]);
        return $stmt->fetchAll();
    }

    public function saveEncounter(string $noRawat, string $idEncounter): bool
    {
        $sql = "INSERT INTO satu_sehat_encounter (no_rawat, id_encounter) VALUES (:nr, :id) ON DUPLICATE KEY UPDATE id_encounter = :id2";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute(['nr' => $noRawat, 'id' => $idEncounter, 'id2' => $idEncounter]);
    }

    // ─── IHS LOOKUPS ───────────────────────────────────────────────────────────

    private function isValidNik(string $nik): bool
    {
        $nik = trim($nik);
        return strlen($nik) === 16 && ctype_digit($nik);
    }

    public function getIhsPatient(string $nik): ?string
    {
        $nik = trim($nik);
        if (!$this->isValidNik($nik)) {
            $this->log->debug("[DB] Invalid Patient NIK format: '{$nik}' (skipping IHS lookup)");
            return null;
        }

        $stmt = $this->mysql->prepare("SELECT ihspasien FROM satu_sehat_ihs_patient WHERE nikpasien = :nik LIMIT 1");
        $stmt->execute(['nik' => $nik]);
        $row = $stmt->fetch();

        $mysqlVal = null;
        if ($row && !empty($row['ihspasien'])) {
            $mysqlVal = trim($row['ihspasien']);
            if ($mysqlVal !== '' && $mysqlVal !== '-') {
                return $mysqlVal;
            }
        }

        // MySQL has no mapping OR has '-' mapping. Check SQLite TTL cache.
        $stmt = $this->sqlite->prepare("SELECT status, updated_at FROM patient_sync_state WHERE nik = :nik LIMIT 1");
        $stmt->execute(['nik' => $nik]);
        $stateRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($stateRow) {
            $elapsed = time() - strtotime($stateRow['updated_at']);
            $ttl = 30 * 86400; // 30-day TTL for self-healing retries
            if ($elapsed < $ttl) {
                $this->log->debug("[DB] Patient NIK '{$nik}' has cached failure state '{$stateRow['status']}' in SQLite (updated " . round($elapsed / 86400, 2) . " days ago). Skipping API lookup.");
                return null;
            }
            $this->log->info("[DB] Patient NIK '{$nik}' failure cache in SQLite has expired (updated " . round($elapsed / 86400, 2) . " days ago). Retrying API lookup...");
        }

        // Fallback to API lookup
        $this->log->info("[API] Patient NIK {$nik} not found or cached as failed. Searching via Satu Sehat...");
        $result = $this->client->get("/Patient?identifier=https://fhir.kemkes.go.id/id/nik|{$nik}");
        
        if ($result['success']) {
            if (isset($result['data']['entry'][0]['resource']['id'])) {
                $ihsId = $result['data']['entry'][0]['resource']['id'];
                $this->log->info("[API] Found IHS Patient ID: {$ihsId}. Saving to DB...");
                
                $insert = $this->mysql->prepare("INSERT INTO satu_sehat_ihs_patient (nikpasien, ihspasien) VALUES (:n, :i) ON DUPLICATE KEY UPDATE ihspasien = :i2");
                $insert->execute(['n' => $nik, 'i' => $ihsId, 'i2' => $ihsId]);
                
                // Clear SQLite state on success
                $delete = $this->sqlite->prepare("DELETE FROM patient_sync_state WHERE nik = :nik");
                $delete->execute(['nik' => $nik]);

                return $ihsId;
            } else {
                // Not registered in Satu Sehat
                $this->log->warning("[API] Patient NIK {$nik} is NOT registered in Satu Sehat. Caching '-' in DB...");
                $insert = $this->mysql->prepare("INSERT INTO satu_sehat_ihs_patient (nikpasien, ihspasien) VALUES (:n, '-') ON DUPLICATE KEY UPDATE ihspasien = '-'");
                $insert->execute(['n' => $nik]);

                // Record failure state in SQLite
                $insertSqlite = $this->sqlite->prepare("INSERT OR REPLACE INTO patient_sync_state (nik, status, updated_at) VALUES (:nik, 'not_found', CURRENT_TIMESTAMP)");
                $insertSqlite->execute(['nik' => $nik]);
            }
        } else {
            $httpCode = $result['code'] ?? 0;
            if ($httpCode === 400) {
                // Invalid NIK format/value rejected by Satu Sehat API
                $this->log->warning("[API] Patient NIK {$nik} lookup rejected (HTTP 400). Caching '-' in DB...");
                $insert = $this->mysql->prepare("INSERT INTO satu_sehat_ihs_patient (nikpasien, ihspasien) VALUES (:n, '-') ON DUPLICATE KEY UPDATE ihspasien = '-'");
                $insert->execute(['n' => $nik]);

                // Record failure state in SQLite
                $insertSqlite = $this->sqlite->prepare("INSERT OR REPLACE INTO patient_sync_state (nik, status, updated_at) VALUES (:nik, 'invalid_nik', CURRENT_TIMESTAMP)");
                $insertSqlite->execute(['nik' => $nik]);
            } else {
                // Transient error => do not write or update SQLite state, so it retries on next event/run
                $this->log->error("[API] Patient NIK {$nik} lookup transient failure (HTTP {$httpCode}). Will retry next cycle.");
            }
        }

        return null;
    }

    public function getIhsPractitioner(string $nik): ?string
    {
        $nik = trim($nik);
        if (!$this->isValidNik($nik)) {
            $this->log->debug("[DB] Invalid Practitioner NIK format: '{$nik}' (skipping IHS lookup)");
            return null;
        }

        $stmt = $this->mysql->prepare("SELECT ihspegawai FROM satu_sehat_ihs_practitioner WHERE nikpegawai = :nik LIMIT 1");
        $stmt->execute(['nik' => $nik]);
        $row = $stmt->fetch();

        if ($row && !empty($row['ihspegawai'])) {
            $val = trim($row['ihspegawai']);
            if ($val === '' || $val === '-') {
                return null;
            }
            return $val;
        }

        // Fallback to API lookup
        $this->log->info("[API] Practitioner NIK {$nik} not found in DB. Searching via Satu Sehat...");
        $result = $this->client->get("/Practitioner?identifier=https://fhir.kemkes.go.id/id/nik|{$nik}");
        
        if ($result['success']) {
            if (isset($result['data']['entry'][0]['resource']['id'])) {
                $ihsId = $result['data']['entry'][0]['resource']['id'];
                $this->log->info("[API] Found IHS Practitioner ID: {$ihsId}. Saving to DB...");
                
                $insert = $this->mysql->prepare("INSERT INTO satu_sehat_ihs_practitioner (nikpegawai, ihspegawai) VALUES (:n, :i) ON DUPLICATE KEY UPDATE ihspegawai = :i2");
                $insert->execute(['n' => $nik, 'i' => $ihsId, 'i2' => $ihsId]);
                return $ihsId;
            } else {
                // Not registered in Satu Sehat
                $this->log->warning("[API] Practitioner NIK {$nik} is NOT registered in Satu Sehat. Caching '-' in DB...");
                $insert = $this->mysql->prepare("INSERT INTO satu_sehat_ihs_practitioner (nikpegawai, ihspegawai) VALUES (:n, '-') ON DUPLICATE KEY UPDATE ihspegawai = '-'");
                $insert->execute(['n' => $nik]);
            }
        } elseif (isset($result['code']) && $result['code'] === 400) {
            // Invalid NIK format/value rejected by Satu Sehat API
            $this->log->warning("[API] Practitioner NIK {$nik} lookup rejected (HTTP 400). Caching '-' in DB...");
            $insert = $this->mysql->prepare("INSERT INTO satu_sehat_ihs_practitioner (nikpegawai, ihspegawai) VALUES (:n, '-') ON DUPLICATE KEY UPDATE ihspegawai = '-'");
            $insert->execute(['n' => $nik]);
        }

        return null;
    }

    // ─── EPISODE OF CARE STATE TRACKING ────────────────────────────────────────

    public function getEocLocalState(string $noRawat): ?string
    {
        $stmt = $this->sqlite->prepare("SELECT status FROM episode_of_care_state WHERE no_rawat = :nr");
        $stmt->execute(['nr' => $noRawat]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public function updateEocLocalState(string $noRawat, string $status): void
    {
        $stmt = $this->sqlite->prepare("
            INSERT INTO episode_of_care_state (no_rawat, status, updated_at) 
            VALUES (:nr, :st, CURRENT_TIMESTAMP)
            ON CONFLICT(no_rawat) DO UPDATE SET status = excluded.status, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['nr' => $noRawat, 'st' => $status]);
    }

    // ─── EPISODE OF CARE MYSQL OPERATIONS ──────────────────────────────────────

    public function fetchPendingEocActive(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT 
                rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, 
                p.nm_pasien, p.no_ktp, rp.kd_dokter, pg.nama, pg.no_ktp as ktpdokter, 
                dp.kd_penyakit, py.nm_penyakit, rp.stts, rp.status_lanjut, dp.status,
                pr.tgl_perawatan, pr.jam_rawat, ki.tgl_keluar, ki.jam_keluar
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN pegawai pg ON pg.nik = rp.kd_dokter
            INNER JOIN diagnosa_pasien dp ON dp.no_rawat = rp.no_rawat
            INNER JOIN penyakit py ON py.kd_penyakit = dp.kd_penyakit
            LEFT JOIN pemeriksaan_ralan pr ON pr.no_rawat = rp.no_rawat
            LEFT JOIN kamar_inap ki ON ki.no_rawat = rp.no_rawat
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
              AND rp.no_rawat NOT IN (SELECT no_rawat FROM satu_sehat_episode_of_care)
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function fetchPendingEocFinished(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT 
                rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, 
                p.nm_pasien, p.no_ktp, rp.kd_dokter, pg.nama, pg.no_ktp as ktpdokter, 
                dp.kd_penyakit, py.nm_penyakit, rp.stts, rp.status_lanjut, dp.status,
                sseoc.id_episode_of_care,
                CASE 
                    WHEN rp.status_lanjut = 'Ralan' THEN CONCAT(nj.tanggal, 'T', nj.jam, '+07:00') 
                    WHEN rp.status_lanjut = 'Ranap' THEN CONCAT(ni.tanggal, 'T', ni.jam, '+07:00') 
                END as waktu_pulang
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN pegawai pg ON pg.nik = rp.kd_dokter
            INNER JOIN diagnosa_pasien dp ON dp.no_rawat = rp.no_rawat
            INNER JOIN penyakit py ON py.kd_penyakit = dp.kd_penyakit
            INNER JOIN satu_sehat_episode_of_care sseoc ON sseoc.no_rawat = rp.no_rawat
            LEFT JOIN nota_jalan nj ON nj.no_rawat = rp.no_rawat
            LEFT JOIN nota_inap ni ON ni.no_rawat = rp.no_rawat
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
              AND (nj.tanggal IS NOT NULL OR ni.tanggal IS NOT NULL)
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function saveEpisodeOfCare(string $noRawat, string $kdPenyakit, string $status, string $idEpisode): bool
    {
        $sql = "INSERT INTO satu_sehat_episode_of_care (no_rawat, kd_penyakit, status, id_episode_of_care) 
                VALUES (:nr, :kd, :st, :id) 
                ON DUPLICATE KEY UPDATE id_episode_of_care = :id2, status = :st2";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'nr'  => $noRawat,
            'kd'  => $kdPenyakit,
            'st'  => $status,
            'id'  => $idEpisode,
            'id2' => $idEpisode,
            'st2' => $status
        ]);
    }

    public function getSavedEpisodeOfCareId(string $noRawat): ?string
    {
        $sql = "SELECT id_episode_of_care FROM satu_sehat_episode_of_care WHERE no_rawat = :nr LIMIT 1";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['nr' => $noRawat]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['id_episode_of_care'] : null;
    }

    // ─── CONDITION STATE TRACKING ──────────────────────────────────────────────

    public function getConditionLocalState(string $noRawat, string $kdPenyakit): ?string
    {
        $compositeKey = $noRawat . '_' . $kdPenyakit;
        $stmt = $this->sqlite->prepare("SELECT status FROM condition_state WHERE composite_key = :ck");
        $stmt->execute(['ck' => $compositeKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public function updateConditionLocalState(string $noRawat, string $kdPenyakit, string $status): void
    {
        $compositeKey = $noRawat . '_' . $kdPenyakit;
        $stmt = $this->sqlite->prepare("
            INSERT INTO condition_state (composite_key, status, updated_at) 
            VALUES (:ck, :st, CURRENT_TIMESTAMP)
            ON CONFLICT(composite_key) DO UPDATE SET status = excluded.status, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['ck' => $compositeKey, 'st' => $status]);
    }

    // ─── CONDITION MYSQL OPERATIONS ────────────────────────────────────────────

    public function fetchPendingConditionActive(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT 
                rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, 
                p.nm_pasien, p.no_ktp, rp.stts, rp.status_lanjut, 
                CONCAT(rp.tgl_registrasi, ' ', rp.jam_reg) as pulang, 
                sse.id_encounter, dp.kd_penyakit, py.nm_penyakit, dp.status
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            INNER JOIN diagnosa_pasien dp ON dp.no_rawat = rp.no_rawat
            INNER JOIN penyakit py ON dp.kd_penyakit = py.kd_penyakit
            LEFT JOIN satu_sehat_condition ssc ON ssc.no_rawat = dp.no_rawat 
                AND ssc.kd_penyakit = dp.kd_penyakit 
                AND ssc.status = dp.status
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
              AND ssc.id_condition IS NULL
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function fetchPendingConditionUpdate(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT 
                rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, 
                p.nm_pasien, p.no_ktp, rp.stts, rp.status_lanjut, 
                CONCAT(rp.tgl_registrasi, ' ', rp.jam_reg) as pulang, 
                sse.id_encounter, dp.kd_penyakit, py.nm_penyakit, dp.status,
                ssc.id_condition
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            INNER JOIN diagnosa_pasien dp ON dp.no_rawat = rp.no_rawat
            INNER JOIN penyakit py ON dp.kd_penyakit = py.kd_penyakit
            INNER JOIN satu_sehat_condition ssc ON ssc.no_rawat = dp.no_rawat 
                AND ssc.kd_penyakit = dp.kd_penyakit 
                AND ssc.status = dp.status
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function saveCondition(string $noRawat, string $kdPenyakit, string $status, string $idCondition): bool
    {
        $sql = "INSERT INTO satu_sehat_condition (no_rawat, kd_penyakit, status, id_condition) 
                VALUES (:nr, :kd, :st, :id) 
                ON DUPLICATE KEY UPDATE id_condition = :id2";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'nr'  => $noRawat,
            'kd'  => $kdPenyakit,
            'st'  => $status,
            'id'  => $idCondition,
            'id2' => $idCondition
        ]);
    }

    // ─── OBSERVATION-TTV STATE TRACKING ────────────────────────────────────────

    public function getObservationLocalState(string $ttvType, string $noRawat, string $tgl, string $jam): ?string
    {
        $compositeKey = "{$ttvType}_{$noRawat}_{$tgl}_{$jam}";
        $stmt = $this->sqlite->prepare("SELECT status FROM observationttv_state WHERE composite_key = :ck");
        $stmt->execute(['ck' => $compositeKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public function updateObservationLocalState(string $ttvType, string $noRawat, string $tgl, string $jam, string $status): void
    {
        $compositeKey = "{$ttvType}_{$noRawat}_{$tgl}_{$jam}";
        $stmt = $this->sqlite->prepare("
            INSERT INTO observationttv_state (composite_key, status, updated_at) 
            VALUES (:ck, :st, CURRENT_TIMESTAMP)
            ON CONFLICT(composite_key) DO UPDATE SET status = excluded.status, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['ck' => $compositeKey, 'st' => $status]);
    }

    // ─── OBSERVATION LAB PK/MB STATE TRACKING ──────────────────────────────────

    public function getObservationLabPKLocalState(string $noorder, string $kdJenisPrw, int $idTemplate): ?string
    {
        $compositeKey = "{$noorder}_{$kdJenisPrw}_{$idTemplate}";
        $stmt = $this->sqlite->prepare("SELECT status FROM observation_lab_pk_state WHERE composite_key = :ck");
        $stmt->execute(['ck' => $compositeKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public function updateObservationLabPKLocalState(string $noorder, string $kdJenisPrw, int $idTemplate, string $status): void
    {
        $compositeKey = "{$noorder}_{$kdJenisPrw}_{$idTemplate}";
        $stmt = $this->sqlite->prepare("
            INSERT INTO observation_lab_pk_state (composite_key, status, updated_at) 
            VALUES (:ck, :st, CURRENT_TIMESTAMP)
            ON CONFLICT(composite_key) DO UPDATE SET status = excluded.status, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['ck' => $compositeKey, 'st' => $status]);
    }

    public function getObservationLabMBLocalState(string $noorder, string $kdJenisPrw, int $idTemplate): ?string
    {
        $compositeKey = "{$noorder}_{$kdJenisPrw}_{$idTemplate}";
        $stmt = $this->sqlite->prepare("SELECT status FROM observation_lab_mb_state WHERE composite_key = :ck");
        $stmt->execute(['ck' => $compositeKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public function updateObservationLabMBLocalState(string $noorder, string $kdJenisPrw, int $idTemplate, string $status): void
    {
        $compositeKey = "{$noorder}_{$kdJenisPrw}_{$idTemplate}";
        $stmt = $this->sqlite->prepare("
            INSERT INTO observation_lab_mb_state (composite_key, status, updated_at) 
            VALUES (:ck, :st, CURRENT_TIMESTAMP)
            ON CONFLICT(composite_key) DO UPDATE SET status = excluded.status, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['ck' => $compositeKey, 'st' => $status]);
    }

    public function getServiceRequestLabPKLocalState(string $noorder, string $kdJenisPrw, int $idTemplate): ?string
    {
        $compositeKey = "{$noorder}_{$kdJenisPrw}_{$idTemplate}";
        $stmt = $this->sqlite->prepare("SELECT status FROM servicerequest_lab_pk_state WHERE composite_key = :ck");
        $stmt->execute(['ck' => $compositeKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public function updateServiceRequestLabPKLocalState(string $noorder, string $kdJenisPrw, int $idTemplate, string $status): void
    {
        $compositeKey = "{$noorder}_{$kdJenisPrw}_{$idTemplate}";
        $stmt = $this->sqlite->prepare("
            INSERT INTO servicerequest_lab_pk_state (composite_key, status, updated_at) 
            VALUES (:ck, :st, CURRENT_TIMESTAMP)
            ON CONFLICT(composite_key) DO UPDATE SET status = excluded.status, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['ck' => $compositeKey, 'st' => $status]);
    }

    public function getServiceRequestLabMBLocalState(string $noorder, string $kdJenisPrw, int $idTemplate): ?string
    {
        $compositeKey = "{$noorder}_{$kdJenisPrw}_{$idTemplate}";
        $stmt = $this->sqlite->prepare("SELECT status FROM servicerequest_lab_mb_state WHERE composite_key = :ck");
        $stmt->execute(['ck' => $compositeKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public function updateServiceRequestLabMBLocalState(string $noorder, string $kdJenisPrw, int $idTemplate, string $status): void
    {
        $compositeKey = "{$noorder}_{$kdJenisPrw}_{$idTemplate}";
        $stmt = $this->sqlite->prepare("
            INSERT INTO servicerequest_lab_mb_state (composite_key, status, updated_at) 
            VALUES (:ck, :st, CURRENT_TIMESTAMP)
            ON CONFLICT(composite_key) DO UPDATE SET status = excluded.status, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['ck' => $compositeKey, 'st' => $status]);
    }

    public function getSpecimenLabPKLocalState(string $noorder, string $kdJenisPrw, int $idTemplate): ?string
    {
        $compositeKey = "{$noorder}_{$kdJenisPrw}_{$idTemplate}";
        $stmt = $this->sqlite->prepare("SELECT status FROM specimen_lab_pk_state WHERE composite_key = :ck");
        $stmt->execute(['ck' => $compositeKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public function updateSpecimenLabPKLocalState(string $noorder, string $kdJenisPrw, int $idTemplate, string $status): void
    {
        $compositeKey = "{$noorder}_{$kdJenisPrw}_{$idTemplate}";
        $stmt = $this->sqlite->prepare("
            INSERT INTO specimen_lab_pk_state (composite_key, status, updated_at) 
            VALUES (:ck, :st, CURRENT_TIMESTAMP)
            ON CONFLICT(composite_key) DO UPDATE SET status = excluded.status, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['ck' => $compositeKey, 'st' => $status]);
    }

    public function getSpecimenLabMBLocalState(string $noorder, string $kdJenisPrw, int $idTemplate): ?string
    {
        $compositeKey = "{$noorder}_{$kdJenisPrw}_{$idTemplate}";
        $stmt = $this->sqlite->prepare("SELECT status FROM specimen_lab_mb_state WHERE composite_key = :ck");
        $stmt->execute(['ck' => $compositeKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public function updateSpecimenLabMBLocalState(string $noorder, string $kdJenisPrw, int $idTemplate, string $status): void
    {
        $compositeKey = "{$noorder}_{$kdJenisPrw}_{$idTemplate}";
        $stmt = $this->sqlite->prepare("
            INSERT INTO specimen_lab_mb_state (composite_key, status, updated_at) 
            VALUES (:ck, :st, CURRENT_TIMESTAMP)
            ON CONFLICT(composite_key) DO UPDATE SET status = excluded.status, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['ck' => $compositeKey, 'st' => $status]);
    }

    // ─── RADIOLOGY STATE TRACKING ────────────────────────────────────────────────────────

    public function getServiceRequestRadiologiLocalState(string $noorder, string $kdJenisPrw): ?string
    {
        $compositeKey = "{$noorder}_{$kdJenisPrw}";
        $stmt = $this->sqlite->prepare("SELECT status FROM servicerequest_radiologi_state WHERE composite_key = :ck");
        $stmt->execute(['ck' => $compositeKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public function updateServiceRequestRadiologiLocalState(string $noorder, string $kdJenisPrw, string $status): void
    {
        $compositeKey = "{$noorder}_{$kdJenisPrw}";
        $stmt = $this->sqlite->prepare("
            INSERT INTO servicerequest_radiologi_state (composite_key, status, updated_at) 
            VALUES (:ck, :st, CURRENT_TIMESTAMP)
            ON CONFLICT(composite_key) DO UPDATE SET status = excluded.status, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['ck' => $compositeKey, 'st' => $status]);
    }

    public function getSpecimenRadiologiLocalState(string $noorder, string $kdJenisPrw): ?string
    {
        $compositeKey = "{$noorder}_{$kdJenisPrw}";
        $stmt = $this->sqlite->prepare("SELECT status FROM specimen_radiologi_state WHERE composite_key = :ck");
        $stmt->execute(['ck' => $compositeKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public function updateSpecimenRadiologiLocalState(string $noorder, string $kdJenisPrw, string $status): void
    {
        $compositeKey = "{$noorder}_{$kdJenisPrw}";
        $stmt = $this->sqlite->prepare("
            INSERT INTO specimen_radiologi_state (composite_key, status, updated_at) 
            VALUES (:ck, :st, CURRENT_TIMESTAMP)
            ON CONFLICT(composite_key) DO UPDATE SET status = excluded.status, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['ck' => $compositeKey, 'st' => $status]);
    }

    public function getObservationRadiologiLocalState(string $noorder, string $kdJenisPrw): ?string
    {
        $compositeKey = "{$noorder}_{$kdJenisPrw}";
        $stmt = $this->sqlite->prepare("SELECT status FROM observation_radiologi_state WHERE composite_key = :ck");
        $stmt->execute(['ck' => $compositeKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public function updateObservationRadiologiLocalState(string $noorder, string $kdJenisPrw, string $status): void
    {
        $compositeKey = "{$noorder}_{$kdJenisPrw}";
        $stmt = $this->sqlite->prepare("
            INSERT INTO observation_radiologi_state (composite_key, status, updated_at) 
            VALUES (:ck, :st, CURRENT_TIMESTAMP)
            ON CONFLICT(composite_key) DO UPDATE SET status = excluded.status, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['ck' => $compositeKey, 'st' => $status]);
    }

    public function getDiagnosticReportRadiologiLocalState(string $noorder, string $kdJenisPrw): ?string
    {
        $compositeKey = "{$noorder}_{$kdJenisPrw}";
        $stmt = $this->sqlite->prepare("SELECT status FROM diagnosticreport_radiologi_state WHERE composite_key = :ck");
        $stmt->execute(['ck' => $compositeKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public function updateDiagnosticReportRadiologiLocalState(string $noorder, string $kdJenisPrw, string $status): void
    {
        $compositeKey = "{$noorder}_{$kdJenisPrw}";
        $stmt = $this->sqlite->prepare("
            INSERT INTO diagnosticreport_radiologi_state (composite_key, status, updated_at) 
            VALUES (:ck, :st, CURRENT_TIMESTAMP)
            ON CONFLICT(composite_key) DO UPDATE SET status = excluded.status, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['ck' => $compositeKey, 'st' => $status]);
    }

    public function getImagingStudyLocalState(string $noorder, string $kdJenisPrw): ?string
    {
        $compositeKey = "{$noorder}_{$kdJenisPrw}";
        $stmt = $this->sqlite->prepare("SELECT status FROM imagingstudy_radiologi_state WHERE composite_key = :ck");
        $stmt->execute(['ck' => $compositeKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public function updateImagingStudyLocalState(string $noorder, string $kdJenisPrw, string $status): void
    {
        $compositeKey = "{$noorder}_{$kdJenisPrw}";
        $stmt = $this->sqlite->prepare("
            INSERT INTO imagingstudy_radiologi_state (composite_key, status, updated_at) 
            VALUES (:ck, :st, CURRENT_TIMESTAMP)
            ON CONFLICT(composite_key) DO UPDATE SET status = excluded.status, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['ck' => $compositeKey, 'st' => $status]);
    }

    // ─── OBSERVATION-TTV MYSQL OPERATIONS ──────────────────────────────────────

    public function fetchPendingObservations(string $ttvTypeKey, array $def, string $dateFrom, string $dateTo): array
    {
        $dbCol   = $def['db_column'];
        $stTable = $def['state_table'];
        $idCol   = $def['state_id_col'] ?? 'id_observation';

        $params = ['df' => $dateFrom, 'dt' => $dateTo];

        $ralanQuery = "
            SELECT 
                rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, 
                p.nm_pasien, p.no_ktp, rp.kd_dokter, pg.nama, pg.no_ktp as ktpdokter, 
                rp.stts, rp.status_lanjut, 
                CONCAT(rp.tgl_registrasi, ' ', rp.jam_reg) as pulang, 
                sse.id_encounter, 
                pr.{$dbCol} as value, pr.tgl_perawatan as tgl_observasi, pr.jam_rawat as jam_observasi,
                st.{$idCol} as synced_id
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN pegawai pg ON pg.nik = rp.kd_dokter
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            INNER JOIN pemeriksaan_ralan pr ON pr.no_rawat = rp.no_rawat
            LEFT JOIN {$stTable} st ON st.no_rawat = pr.no_rawat AND st.tgl_perawatan = pr.tgl_perawatan AND st.jam_rawat = pr.jam_rawat
            WHERE pr.tgl_perawatan BETWEEN :df AND :dt
              AND pr.{$dbCol} IS NOT NULL AND pr.{$dbCol} != '' AND pr.{$dbCol} != '-'
        ";

        if ($dbCol === 'lingkar_perut') {
            $sql = "
                SELECT * FROM (
                    {$ralanQuery}
                ) as combined
                WHERE synced_id IS NULL
            ";
        } else {
            $params['df2'] = $dateFrom;
            $params['dt2'] = $dateTo;
            $sql = "
                SELECT * FROM (
                    {$ralanQuery}
                    UNION ALL
                    SELECT 
                        rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, 
                        p.nm_pasien, p.no_ktp, rp.kd_dokter, pg.nama, pg.no_ktp as ktpdokter, 
                        rp.stts, rp.status_lanjut, 
                        CONCAT(rp.tgl_registrasi, ' ', rp.jam_reg) as pulang, 
                        sse.id_encounter, 
                        pi.{$dbCol} as value, pi.tgl_perawatan as tgl_observasi, pi.jam_rawat as jam_observasi,
                        st.{$idCol} as synced_id
                    FROM reg_periksa rp
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    INNER JOIN pegawai pg ON pg.nik = rp.kd_dokter
                    INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    INNER JOIN pemeriksaan_ranap pi ON pi.no_rawat = rp.no_rawat
                    LEFT JOIN {$stTable} st ON st.no_rawat = pi.no_rawat AND st.tgl_perawatan = pi.tgl_perawatan AND st.jam_rawat = pi.jam_rawat
                    WHERE pi.tgl_perawatan BETWEEN :df2 AND :dt2
                      AND pi.{$dbCol} IS NOT NULL AND pi.{$dbCol} != '' AND pi.{$dbCol} != '-'
                ) as combined
                WHERE synced_id IS NULL
            ";
        }

        $stmt = $this->mysql->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function saveObservationTTV(string $stTable, string $idCol, string $noRawat, string $tgl, string $jam, string $statusRawat, string $idObservation): bool
    {
        // Table schema: no_rawat, tgl_perawatan, jam_rawat, status, id_observation
        $sql = "INSERT INTO {$stTable} (no_rawat, tgl_perawatan, jam_rawat, status, {$idCol}) 
                VALUES (:nr, :tgl, :jam, :st, :id) 
                ON DUPLICATE KEY UPDATE {$idCol} = :id2";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'nr'  => $noRawat,
            'tgl' => $tgl,
            'jam' => $jam,
            'st'  => $statusRawat,
            'id'  => $idObservation,
            'id2' => $idObservation
        ]);
    }

    // ─── PROCEDURE STATE TRACKING ──────────────────────────────────────────────

    public function getProcedureLocalState(string $noRawat, string $kode): ?string
    {
        $compositeKey = $noRawat . '_' . $kode;
        $stmt = $this->sqlite->prepare("SELECT status FROM procedure_state WHERE composite_key = :ck");
        $stmt->execute(['ck' => $compositeKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public function updateProcedureLocalState(string $noRawat, string $kode, string $status): void
    {
        $compositeKey = $noRawat . '_' . $kode;
        $stmt = $this->sqlite->prepare("
            INSERT INTO procedure_state (composite_key, status, updated_at) 
            VALUES (:ck, :st, CURRENT_TIMESTAMP)
            ON CONFLICT(composite_key) DO UPDATE SET status = excluded.status, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['ck' => $compositeKey, 'st' => $status]);
    }

    // ─── PROCEDURE MYSQL OPERATIONS ────────────────────────────────────────────

    public function fetchPendingProcedureActive(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT 
                rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, 
                p.nm_pasien, p.no_ktp, rp.stts, rp.status_lanjut, 
                CONCAT(rp.tgl_registrasi, 'T', rp.jam_reg, '+07:00') as waktu_registrasi, 
                sse.id_encounter, pp.kode, py.deskripsi_panjang, pp.status,
                CASE 
                    WHEN rp.status_lanjut = 'Ralan' THEN (SELECT CONCAT(tanggal, 'T', jam, '+07:00') FROM nota_jalan WHERE no_rawat = rp.no_rawat LIMIT 1)
                    WHEN rp.status_lanjut = 'Ranap' THEN (SELECT CONCAT(tanggal, 'T', jam, '+07:00') FROM nota_inap WHERE no_rawat = rp.no_rawat LIMIT 1)
                END as waktu_pulang
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            INNER JOIN prosedur_pasien pp ON pp.no_rawat = rp.no_rawat
            INNER JOIN icd9 py ON pp.kode = py.kode
            LEFT JOIN satu_sehat_procedure ssp ON ssp.no_rawat = pp.no_rawat 
                AND ssp.kode = pp.kode 
                AND ssp.status = pp.status
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
              AND ssp.id_procedure IS NULL
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function fetchPendingProcedureUpdate(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT 
                rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, 
                p.nm_pasien, p.no_ktp, rp.stts, rp.status_lanjut, 
                CONCAT(rp.tgl_registrasi, 'T', rp.jam_reg, '+07:00') as waktu_registrasi, 
                sse.id_encounter, pp.kode, py.deskripsi_panjang, pp.status,
                ssp.id_procedure,
                CASE 
                    WHEN rp.status_lanjut = 'Ralan' THEN (SELECT CONCAT(tanggal, 'T', jam, '+07:00') FROM nota_jalan WHERE no_rawat = rp.no_rawat LIMIT 1)
                    WHEN rp.status_lanjut = 'Ranap' THEN (SELECT CONCAT(tanggal, 'T', jam, '+07:00') FROM nota_inap WHERE no_rawat = rp.no_rawat LIMIT 1)
                END as waktu_pulang
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            INNER JOIN prosedur_pasien pp ON pp.no_rawat = rp.no_rawat
            INNER JOIN icd9 py ON pp.kode = py.kode
            INNER JOIN satu_sehat_procedure ssp ON ssp.no_rawat = pp.no_rawat 
                AND ssp.kode = pp.kode 
                AND ssp.status = pp.status
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function saveProcedure(string $noRawat, string $kode, string $status, string $idProcedure): bool
    {
        $sql = "INSERT INTO satu_sehat_procedure (no_rawat, kode, status, id_procedure) 
                VALUES (:nr, :kd, :st, :id) 
                ON DUPLICATE KEY UPDATE id_procedure = :id2";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'nr'  => $noRawat,
            'kd'  => $kode,
            'st'  => $status,
            'id'  => $idProcedure,
            'id2' => $idProcedure
        ]);
    }

    // ─── CAREPLAN STATE TRACKING ─────────────────────────────────────────────────────────

    public function getCarePlanLocalState(string $noRawat, string $tglPerawatan, string $jamRawat, string $status): ?string
    {
        $compositeKey = md5($noRawat . '_' . $tglPerawatan . '_' . $jamRawat . '_' . $status);
        $stmt = $this->sqlite->prepare("SELECT status FROM careplan_state WHERE composite_key = :ck");
        $stmt->execute(['ck' => $compositeKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public function updateCarePlanLocalState(string $noRawat, string $tglPerawatan, string $jamRawat, string $status, string $localStatus): void
    {
        $compositeKey = md5($noRawat . '_' . $tglPerawatan . '_' . $jamRawat . '_' . $status);
        $stmt = $this->sqlite->prepare("
            INSERT INTO careplan_state (composite_key, status, updated_at) 
            VALUES (:ck, :st, CURRENT_TIMESTAMP)
            ON CONFLICT(composite_key) DO UPDATE SET status = excluded.status, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['ck' => $compositeKey, 'st' => $localStatus]);
    }

    // ─── CAREPLAN MYSQL OPERATIONS ───────────────────────────────────────────────────────

    public function fetchPendingCarePlanActive(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT * FROM (
                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, 
                    p.nm_pasien, p.no_ktp, sse.id_encounter, pr.rtl, 
                    pg.nama, pg.no_ktp as ktppraktisi, pr.tgl_perawatan, pr.jam_rawat, 
                    ssc.id_careplan, 'Ralan' as status_lanjut, rp.kd_poli
                FROM reg_periksa rp 
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis 
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat 
                INNER JOIN pemeriksaan_ralan pr ON pr.no_rawat = rp.no_rawat 
                INNER JOIN pegawai pg ON pr.nip = pg.nik 
                LEFT JOIN satu_sehat_careplan ssc ON ssc.no_rawat = pr.no_rawat 
                    AND ssc.tgl_perawatan = pr.tgl_perawatan 
                    AND ssc.jam_rawat = pr.jam_rawat 
                    AND ssc.status = 'Ralan'
                WHERE pr.rtl <> '' 
                  AND rp.tgl_registrasi BETWEEN :df AND :dt
                  AND ssc.id_careplan IS NULL

                UNION ALL

                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, 
                    p.nm_pasien, p.no_ktp, sse.id_encounter, pi.rtl, 
                    pg.nama, pg.no_ktp as ktppraktisi, pi.tgl_perawatan, pi.jam_rawat, 
                    ssc.id_careplan, 'Ranap' as status_lanjut, rp.kd_poli
                FROM reg_periksa rp 
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis 
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat 
                INNER JOIN pemeriksaan_ranap pi ON pi.no_rawat = rp.no_rawat 
                INNER JOIN pegawai pg ON pi.nip = pg.nik 
                LEFT JOIN satu_sehat_careplan ssc ON ssc.no_rawat = pi.no_rawat 
                    AND ssc.tgl_perawatan = pi.tgl_perawatan 
                    AND ssc.jam_rawat = pi.jam_rawat 
                    AND ssc.status = 'Ranap'
                WHERE pi.rtl <> '' 
                  AND rp.tgl_registrasi BETWEEN :df2 AND :dt2
                  AND ssc.id_careplan IS NULL
            ) AS combined
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo, 'df2' => $dateFrom, 'dt2' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function fetchPendingCarePlanUpdate(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT * FROM (
                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, 
                    p.nm_pasien, p.no_ktp, sse.id_encounter, pr.rtl, 
                    pg.nama, pg.no_ktp as ktppraktisi, pr.tgl_perawatan, pr.jam_rawat, 
                    ssc.id_careplan, 'Ralan' as status_lanjut, rp.kd_poli
                FROM reg_periksa rp 
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis 
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat 
                INNER JOIN pemeriksaan_ralan pr ON pr.no_rawat = rp.no_rawat 
                INNER JOIN pegawai pg ON pr.nip = pg.nik 
                INNER JOIN satu_sehat_careplan ssc ON ssc.no_rawat = pr.no_rawat 
                    AND ssc.tgl_perawatan = pr.tgl_perawatan 
                    AND ssc.jam_rawat = pr.jam_rawat 
                    AND ssc.status = 'Ralan'
                WHERE pr.rtl <> '' 
                  AND rp.tgl_registrasi BETWEEN :df AND :dt

                UNION ALL

                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, 
                    p.nm_pasien, p.no_ktp, sse.id_encounter, pi.rtl, 
                    pg.nama, pg.no_ktp as ktppraktisi, pi.tgl_perawatan, pi.jam_rawat, 
                    ssc.id_careplan, 'Ranap' as status_lanjut, rp.kd_poli
                FROM reg_periksa rp 
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis 
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat 
                INNER JOIN pemeriksaan_ranap pi ON pi.no_rawat = rp.no_rawat 
                INNER JOIN pegawai pg ON pi.nip = pg.nik 
                INNER JOIN satu_sehat_careplan ssc ON ssc.no_rawat = pi.no_rawat 
                    AND ssc.tgl_perawatan = pi.tgl_perawatan 
                    AND ssc.jam_rawat = pi.jam_rawat 
                    AND ssc.status = 'Ranap'
                WHERE pi.rtl <> '' 
                  AND rp.tgl_registrasi BETWEEN :df2 AND :dt2
            ) AS combined
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo, 'df2' => $dateFrom, 'dt2' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function saveCarePlan(string $noRawat, string $tglPerawatan, string $jamRawat, string $status, string $idCarePlan): bool
    {
        $sql = "INSERT INTO satu_sehat_careplan (no_rawat, tgl_perawatan, jam_rawat, status, id_careplan) 
                VALUES (:nr, :tgl, :jam, :st, :id) 
                ON DUPLICATE KEY UPDATE id_careplan = :id2";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'nr'   => $noRawat,
            'tgl'  => $tglPerawatan,
            'jam'  => $jamRawat,
            'st'   => $status,
            'id'   => $idCarePlan,
            'id2'  => $idCarePlan
        ]);
    }

    // ─── ALLERGY INTOLERANCE STATE TRACKING ──────────────────────────────────────────────

    public function getAllergyLocalState(string $noRawat, string $tglPerawatan, string $jamRawat, string $alergi): ?string
    {
        $compositeKey = md5($noRawat . '_' . $tglPerawatan . '_' . $jamRawat . '_' . $alergi);
        $stmt = $this->sqlite->prepare("SELECT status FROM allergyintolerance_state WHERE composite_key = :ck");
        $stmt->execute(['ck' => $compositeKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public function updateAllergyLocalState(string $noRawat, string $tglPerawatan, string $jamRawat, string $alergi, string $status): void
    {
        $compositeKey = md5($noRawat . '_' . $tglPerawatan . '_' . $jamRawat . '_' . $alergi);
        $stmt = $this->sqlite->prepare("
            INSERT INTO allergyintolerance_state (composite_key, status, updated_at) 
            VALUES (:ck, :st, CURRENT_TIMESTAMP)
            ON CONFLICT(composite_key) DO UPDATE SET status = excluded.status, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['ck' => $compositeKey, 'st' => $status]);
    }

    // ─── ALLERGY INTOLERANCE MYSQL OPERATIONS ────────────────────────────────────────────

    public function fetchPendingAllergyActive(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT * FROM (
                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, 
                    p.nm_pasien, p.no_ktp, sse.id_encounter, pr.alergi, 
                    pg.nama, pg.no_ktp as ktppraktisi, pr.tgl_perawatan, pr.jam_rawat, 
                    ssai.id_allergy_intolerance, 'Ralan' as status_rawat
                FROM reg_periksa rp 
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis 
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat 
                INNER JOIN pemeriksaan_ralan pr ON pr.no_rawat = rp.no_rawat 
                INNER JOIN pegawai pg ON pr.nip = pg.nik 
                LEFT JOIN satu_sehat_allergy_intolerance ssai ON ssai.no_rawat = pr.no_rawat 
                    AND ssai.tgl_perawatan = pr.tgl_perawatan 
                    AND ssai.jam_rawat = pr.jam_rawat 
                WHERE pr.alergi <> '' 
                  AND rp.tgl_registrasi BETWEEN :df AND :dt
                  AND ssai.id_allergy_intolerance IS NULL

                UNION ALL

                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, 
                    p.nm_pasien, p.no_ktp, sse.id_encounter, pi.alergi, 
                    pg.nama, pg.no_ktp as ktppraktisi, pi.tgl_perawatan, pi.jam_rawat, 
                    ssai.id_allergy_intolerance, 'Ranap' as status_rawat
                FROM reg_periksa rp 
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis 
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat 
                INNER JOIN pemeriksaan_ranap pi ON pi.no_rawat = rp.no_rawat 
                INNER JOIN pegawai pg ON pi.nip = pg.nik 
                LEFT JOIN satu_sehat_allergy_intolerance ssai ON ssai.no_rawat = pi.no_rawat 
                    AND ssai.tgl_perawatan = pi.tgl_perawatan 
                    AND ssai.jam_rawat = pi.jam_rawat 
                WHERE pi.alergi <> '' 
                  AND rp.tgl_registrasi BETWEEN :df2 AND :dt2
                  AND ssai.id_allergy_intolerance IS NULL
            ) AS combined
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo, 'df2' => $dateFrom, 'dt2' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function fetchPendingAllergyUpdate(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT * FROM (
                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, 
                    p.nm_pasien, p.no_ktp, sse.id_encounter, pr.alergi, 
                    pg.nama, pg.no_ktp as ktppraktisi, pr.tgl_perawatan, pr.jam_rawat, 
                    ssai.id_allergy_intolerance, 'Ralan' as status_rawat
                FROM reg_periksa rp 
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis 
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat 
                INNER JOIN pemeriksaan_ralan pr ON pr.no_rawat = rp.no_rawat 
                INNER JOIN pegawai pg ON pr.nip = pg.nik 
                INNER JOIN satu_sehat_allergy_intolerance ssai ON ssai.no_rawat = pr.no_rawat 
                    AND ssai.tgl_perawatan = pr.tgl_perawatan 
                    AND ssai.jam_rawat = pr.jam_rawat 
                WHERE pr.alergi <> '' 
                  AND rp.tgl_registrasi BETWEEN :df AND :dt

                UNION ALL

                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, 
                    p.nm_pasien, p.no_ktp, sse.id_encounter, pi.alergi, 
                    pg.nama, pg.no_ktp as ktppraktisi, pi.tgl_perawatan, pi.jam_rawat, 
                    ssai.id_allergy_intolerance, 'Ranap' as status_rawat
                FROM reg_periksa rp 
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis 
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat 
                INNER JOIN pemeriksaan_ranap pi ON pi.no_rawat = rp.no_rawat 
                INNER JOIN pegawai pg ON pi.nip = pg.nik 
                INNER JOIN satu_sehat_allergy_intolerance ssai ON ssai.no_rawat = pi.no_rawat 
                    AND ssai.tgl_perawatan = pi.tgl_perawatan 
                    AND ssai.jam_rawat = pi.jam_rawat 
                WHERE pi.alergi <> '' 
                  AND rp.tgl_registrasi BETWEEN :df2 AND :dt2
            ) AS combined
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo, 'df2' => $dateFrom, 'dt2' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function saveAllergyIntolerance(string $noRawat, string $tglPerawatan, string $jamRawat, string $statusRawat, string $idAllergy): bool
    {
        $sql = "INSERT INTO satu_sehat_allergy_intolerance (no_rawat, tgl_perawatan, jam_rawat, status, id_allergy_intolerance) 
                VALUES (:nr, :tgl, :jam, :st, :id) 
                ON DUPLICATE KEY UPDATE id_allergy_intolerance = :id2";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'nr'  => $noRawat,
            'tgl' => $tglPerawatan,
            'jam' => $jamRawat,
            'st'  => $statusRawat,
            'id'  => $idAllergy,
            'id2' => $idAllergy
        ]);
    }

    // ─── IMMUNIZATION STATE TRACKING ─────────────────────────────────────────────

    public function getImmunizationLocalState(string $noRawat, string $tglPerawatan, string $jam, string $kodeBrng, string $noBatch, string $noFaktur): ?string
    {
        $compositeKey = md5($noRawat . '_' . $tglPerawatan . '_' . $jam . '_' . $kodeBrng . '_' . $noBatch . '_' . $noFaktur);
        $stmt = $this->sqlite->prepare("SELECT status FROM immunization_state WHERE composite_key = :ck");
        $stmt->execute(['ck' => $compositeKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public function updateImmunizationLocalState(string $noRawat, string $tglPerawatan, string $jam, string $kodeBrng, string $noBatch, string $noFaktur, string $status): void
    {
        $compositeKey = md5($noRawat . '_' . $tglPerawatan . '_' . $jam . '_' . $kodeBrng . '_' . $noBatch . '_' . $noFaktur);
        $stmt = $this->sqlite->prepare("
            INSERT INTO immunization_state (composite_key, status, updated_at) 
            VALUES (:ck, :st, CURRENT_TIMESTAMP)
            ON CONFLICT(composite_key) DO UPDATE SET status = excluded.status, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['ck' => $compositeKey, 'st' => $status]);
    }

    // ─── IMMUNIZATION MYSQL OPERATIONS ───────────────────────────────────────────

    public function fetchPendingImmunizationActive(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT * FROM (
                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, pasien.nm_pasien, pasien.no_ktp,
                    rp.stts, rp.status_lanjut, sse.id_encounter, smv.vaksin_code, smv.vaksin_system,
                    smv.kode_brng, smv.vaksin_display, smv.route_code, smv.route_system,
                    smv.route_display, smv.dose_quantity_code, smv.dose_quantity_system,
                    smv.dose_quantity_unit, dpo.no_batch, dpo.tgl_perawatan, dpo.jam,
                    dpo.jml, IFNULL(ap.aturan,'') AS aturan, sml.id_lokasi_satusehat, pol.nm_poli, pg.nama, pg.no_ktp AS ktppraktisi,
                    IFNULL(ssi.id_immunization,'') AS id_immunization, dpo.no_faktur, IFNULL(db.tgl_kadaluarsa,'') AS tgl_kadaluarsa,
                    'Ralan' AS status_rawat
                FROM reg_periksa rp
                INNER JOIN pasien ON rp.no_rkm_medis = pasien.no_rkm_medis 
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat 
                INNER JOIN detail_pemberian_obat dpo ON dpo.no_rawat = rp.no_rawat 
                INNER JOIN satu_sehat_mapping_vaksin smv ON smv.kode_brng = dpo.kode_brng 
                LEFT JOIN aturan_pakai ap ON ap.tgl_perawatan = dpo.tgl_perawatan AND ap.jam = dpo.jam AND 
                    ap.no_rawat = dpo.no_rawat AND ap.kode_brng = dpo.kode_brng 
                INNER JOIN satu_sehat_mapping_lokasi_ralan sml ON sml.kd_poli = rp.kd_poli 
                INNER JOIN poliklinik pol ON pol.kd_poli = sml.kd_poli 
                INNER JOIN pegawai pg ON rp.kd_dokter = pg.nik 
                INNER JOIN nota_jalan nj ON nj.no_rawat = rp.no_rawat 
                LEFT JOIN data_batch db ON db.no_batch = dpo.no_batch AND db.kode_brng = dpo.kode_brng AND db.no_faktur = dpo.no_faktur 
                LEFT JOIN satu_sehat_immunization ssi ON ssi.no_rawat = dpo.no_rawat AND ssi.tgl_perawatan = dpo.tgl_perawatan AND 
                    ssi.jam = dpo.jam AND ssi.kode_brng = dpo.kode_brng AND 
                    ssi.no_batch = dpo.no_batch AND ssi.no_faktur = dpo.no_faktur 
                WHERE dpo.no_batch <> '' 
                  AND nj.tanggal BETWEEN :df AND :dt
                  AND (ssi.id_immunization IS NULL OR ssi.id_immunization = '')

                UNION ALL

                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, pasien.nm_pasien, pasien.no_ktp,
                    rp.stts, rp.status_lanjut, sse.id_encounter, smv.vaksin_code, smv.vaksin_system,
                    smv.kode_brng, smv.vaksin_display, smv.route_code, smv.route_system,
                    smv.route_display, smv.dose_quantity_code, smv.dose_quantity_system,
                    smv.dose_quantity_unit, dpo.no_batch, dpo.tgl_perawatan, dpo.jam,
                    dpo.jml, IFNULL(ap.aturan,'') AS aturan, sml.id_lokasi_satusehat, pol.nm_poli, pg.nama, pg.no_ktp AS ktppraktisi,
                    IFNULL(ssi.id_immunization,'') AS id_immunization, dpo.no_faktur, IFNULL(db.tgl_kadaluarsa,'') AS tgl_kadaluarsa,
                    'Ranap' AS status_rawat
                FROM reg_periksa rp
                INNER JOIN pasien ON rp.no_rkm_medis = pasien.no_rkm_medis 
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat 
                INNER JOIN detail_pemberian_obat dpo ON dpo.no_rawat = rp.no_rawat 
                INNER JOIN satu_sehat_mapping_vaksin smv ON smv.kode_brng = dpo.kode_brng 
                LEFT JOIN aturan_pakai ap ON ap.tgl_perawatan = dpo.tgl_perawatan AND ap.jam = dpo.jam AND 
                    ap.no_rawat = dpo.no_rawat AND ap.kode_brng = dpo.kode_brng 
                INNER JOIN satu_sehat_mapping_lokasi_ralan sml ON sml.kd_poli = rp.kd_poli 
                INNER JOIN poliklinik pol ON pol.kd_poli = sml.kd_poli 
                INNER JOIN pegawai pg ON rp.kd_dokter = pg.nik 
                INNER JOIN nota_inap ni ON ni.no_rawat = rp.no_rawat 
                LEFT JOIN data_batch db ON db.no_batch = dpo.no_batch AND db.kode_brng = dpo.kode_brng AND db.no_faktur = dpo.no_faktur 
                LEFT JOIN satu_sehat_immunization ssi ON ssi.no_rawat = dpo.no_rawat AND ssi.tgl_perawatan = dpo.tgl_perawatan AND 
                    ssi.jam = dpo.jam AND ssi.kode_brng = dpo.kode_brng AND 
                    ssi.no_batch = dpo.no_batch AND ssi.no_faktur = dpo.no_faktur 
                WHERE dpo.no_batch <> '' 
                  AND ni.tanggal BETWEEN :df2 AND :dt2
                  AND (ssi.id_immunization IS NULL OR ssi.id_immunization = '')
            ) AS combined
            ORDER BY tgl_perawatan, jam
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo, 'df2' => $dateFrom, 'dt2' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function fetchPendingImmunizationUpdate(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT * FROM (
                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, pasien.nm_pasien, pasien.no_ktp,
                    rp.stts, rp.status_lanjut, sse.id_encounter, smv.vaksin_code, smv.vaksin_system,
                    smv.kode_brng, smv.vaksin_display, smv.route_code, smv.route_system,
                    smv.route_display, smv.dose_quantity_code, smv.dose_quantity_system,
                    smv.dose_quantity_unit, dpo.no_batch, dpo.tgl_perawatan, dpo.jam,
                    dpo.jml, IFNULL(ap.aturan,'') AS aturan, sml.id_lokasi_satusehat, pol.nm_poli, pg.nama, pg.no_ktp AS ktppraktisi,
                    ssi.id_immunization, dpo.no_faktur, IFNULL(db.tgl_kadaluarsa,'') AS tgl_kadaluarsa,
                    'Ralan' AS status_rawat
                FROM reg_periksa rp
                INNER JOIN pasien ON rp.no_rkm_medis = pasien.no_rkm_medis 
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat 
                INNER JOIN detail_pemberian_obat dpo ON dpo.no_rawat = rp.no_rawat 
                INNER JOIN satu_sehat_mapping_vaksin smv ON smv.kode_brng = dpo.kode_brng 
                LEFT JOIN aturan_pakai ap ON ap.tgl_perawatan = dpo.tgl_perawatan AND ap.jam = dpo.jam AND 
                    ap.no_rawat = dpo.no_rawat AND ap.kode_brng = dpo.kode_brng 
                INNER JOIN satu_sehat_mapping_lokasi_ralan sml ON sml.kd_poli = rp.kd_poli 
                INNER JOIN poliklinik pol ON pol.kd_poli = sml.kd_poli 
                INNER JOIN pegawai pg ON rp.kd_dokter = pg.nik 
                INNER JOIN nota_jalan nj ON nj.no_rawat = rp.no_rawat 
                LEFT JOIN data_batch db ON db.no_batch = dpo.no_batch AND db.kode_brng = dpo.kode_brng AND db.no_faktur = dpo.no_faktur 
                INNER JOIN satu_sehat_immunization ssi ON ssi.no_rawat = dpo.no_rawat AND ssi.tgl_perawatan = dpo.tgl_perawatan AND 
                    ssi.jam = dpo.jam AND ssi.kode_brng = dpo.kode_brng AND 
                    ssi.no_batch = dpo.no_batch AND ssi.no_faktur = dpo.no_faktur 
                WHERE dpo.no_batch <> '' 
                  AND nj.tanggal BETWEEN :df AND :dt

                UNION ALL

                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, pasien.nm_pasien, pasien.no_ktp,
                    rp.stts, rp.status_lanjut, sse.id_encounter, smv.vaksin_code, smv.vaksin_system,
                    smv.kode_brng, smv.vaksin_display, smv.route_code, smv.route_system,
                    smv.route_display, smv.dose_quantity_code, smv.dose_quantity_system,
                    smv.dose_quantity_unit, dpo.no_batch, dpo.tgl_perawatan, dpo.jam,
                    dpo.jml, IFNULL(ap.aturan,'') AS aturan, sml.id_lokasi_satusehat, pol.nm_poli, pg.nama, pg.no_ktp AS ktppraktisi,
                    ssi.id_immunization, dpo.no_faktur, IFNULL(db.tgl_kadaluarsa,'') AS tgl_kadaluarsa,
                    'Ranap' AS status_rawat
                FROM reg_periksa rp
                INNER JOIN pasien ON rp.no_rkm_medis = pasien.no_rkm_medis 
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat 
                INNER JOIN detail_pemberian_obat dpo ON dpo.no_rawat = rp.no_rawat 
                INNER JOIN satu_sehat_mapping_vaksin smv ON smv.kode_brng = dpo.kode_brng 
                LEFT JOIN aturan_pakai ap ON ap.tgl_perawatan = dpo.tgl_perawatan AND ap.jam = dpo.jam AND 
                    ap.no_rawat = dpo.no_rawat AND ap.kode_brng = dpo.kode_brng 
                INNER JOIN satu_sehat_mapping_lokasi_ralan sml ON sml.kd_poli = rp.kd_poli 
                INNER JOIN poliklinik pol ON pol.kd_poli = sml.kd_poli 
                INNER JOIN pegawai pg ON rp.kd_dokter = pg.nik 
                INNER JOIN nota_inap ni ON ni.no_rawat = rp.no_rawat 
                LEFT JOIN data_batch db ON db.no_batch = dpo.no_batch AND db.kode_brng = dpo.kode_brng AND db.no_faktur = dpo.no_faktur 
                INNER JOIN satu_sehat_immunization ssi ON ssi.no_rawat = dpo.no_rawat AND ssi.tgl_perawatan = dpo.tgl_perawatan AND 
                    ssi.jam = dpo.jam AND ssi.kode_brng = dpo.kode_brng AND 
                    ssi.no_batch = dpo.no_batch AND ssi.no_faktur = dpo.no_faktur 
                WHERE dpo.no_batch <> '' 
                  AND ni.tanggal BETWEEN :df2 AND :dt2
            ) AS combined
            ORDER BY tgl_perawatan, jam
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo, 'df2' => $dateFrom, 'dt2' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function saveImmunization(string $noRawat, string $tglPerawatan, string $jam, string $kodeBrng, string $noBatch, string $noFaktur, string $idImmunization): bool
    {
        $sql = "INSERT INTO satu_sehat_immunization (no_rawat, tgl_perawatan, jam, kode_brng, no_batch, no_faktur, id_immunization) 
                VALUES (:nr, :tgl, :jam, :kode_brng, :no_batch, :no_faktur, :id) 
                ON DUPLICATE KEY UPDATE id_immunization = :id2";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'nr'        => $noRawat,
            'tgl'       => $tglPerawatan,
            'jam'       => $jam,
            'kode_brng' => $kodeBrng,
            'no_batch'  => $noBatch,
            'no_faktur' => $noFaktur,
            'id'        => $idImmunization,
            'id2'       => $idImmunization
        ]);
    }

    // ─── MEDICATION STATE TRACKING ──────────────────────────────────────────────

    public function getMedicationLocalState(string $kodeBrng): ?string
    {
        $stmt = $this->sqlite->prepare("SELECT status FROM medication_state WHERE kode_brng = :kb");
        $stmt->execute(['kb' => $kodeBrng]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public function updateMedicationLocalState(string $kodeBrng, string $status): void
    {
        $stmt = $this->sqlite->prepare("
            INSERT INTO medication_state (kode_brng, status, updated_at) 
            VALUES (:kb, :st, CURRENT_TIMESTAMP)
            ON CONFLICT(kode_brng) DO UPDATE SET status = excluded.status, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['kb' => $kodeBrng, 'st' => $status]);
    }

    // ─── MEDICATION MYSQL OPERATIONS ────────────────────────────────────────────

    /**
     * Fetch pending medications that do not have an id_medication yet.
     */
    public function fetchPendingMedicationActive(): array
    {
        $sql = "
            SELECT 
                ssmo.obat_code, ssmo.obat_system, db.status,
                ssmo.kode_brng, ssmo.obat_display, ssmo.form_code,
                ssmo.form_system, ssmo.form_display, 
                IFNULL(ssm.id_medication, '') as id_medication
            FROM satu_sehat_mapping_obat ssmo
            INNER JOIN databarang db ON ssmo.kode_brng = db.kode_brng
            LEFT JOIN satu_sehat_medication ssm ON ssm.kode_brng = ssmo.kode_brng
            WHERE ssm.id_medication IS NULL OR ssm.id_medication = ''
        ";
        $stmt = $this->mysql->query($sql);
        return $stmt->fetchAll();
    }

    /**
     * Fetch all medications that already have an id_medication (for update verification).
     */
    public function fetchPendingMedicationUpdate(): array
    {
        $sql = "
            SELECT 
                ssmo.obat_code, ssmo.obat_system, db.status,
                ssmo.kode_brng, ssmo.obat_display, ssmo.form_code,
                ssmo.form_system, ssmo.form_display, 
                ssm.id_medication
            FROM satu_sehat_mapping_obat ssmo
            INNER JOIN databarang db ON ssmo.kode_brng = db.kode_brng
            INNER JOIN satu_sehat_medication ssm ON ssm.kode_brng = ssmo.kode_brng
        ";
        $stmt = $this->mysql->query($sql);
        return $stmt->fetchAll();
    }

    /**
     * Save the returned Satu Sehat Medication ID back to MySQL.
     */
    public function saveMedication(string $kodeBrng, string $idMedication): bool
    {
        $sql = "INSERT INTO satu_sehat_medication (kode_brng, id_medication) 
                VALUES (:kb, :id) 
                ON DUPLICATE KEY UPDATE id_medication = :id2";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'kb'  => $kodeBrng,
            'id'  => $idMedication,
            'id2' => $idMedication
        ]);
    }

    // ─── MEDICATION REQUEST STATE TRACKING ──────────────────────────────────────

    public function getMedicationRequestLocalState(string $noResep, string $kodeBrng, string $noRacik): ?string
    {
        $key = "{$noResep}|{$kodeBrng}|{$noRacik}";
        $stmt = $this->sqlite->prepare("SELECT status FROM medicationrequest_state WHERE composite_key = :key");
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public function updateMedicationRequestLocalState(string $noResep, string $kodeBrng, string $noRacik, string $status): void
    {
        $key = "{$noResep}|{$kodeBrng}|{$noRacik}";
        $stmt = $this->sqlite->prepare("
            INSERT INTO medicationrequest_state (composite_key, status, updated_at) 
            VALUES (:key, :st, CURRENT_TIMESTAMP)
            ON CONFLICT(composite_key) DO UPDATE SET status = excluded.status, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['key' => $key, 'st' => $status]);
    }

    // ─── MEDICATION REQUEST MYSQL OPERATIONS ────────────────────────────────────

    /**
     * Unified query to fetch pending medication requests across Ralan/Ranap and Non-racikan/Racikan.
     */
    public function fetchPendingMedicationRequestActive(string $dateFrom, string $dateTo): array
    {
        $sql = "
            (
                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp,
                    peg.nama, peg.no_ktp as ktppraktisi, sse.id_encounter, ssmo.obat_code, ssmo.obat_system,
                    rd.kode_brng, ssmo.obat_display, ssmo.form_code, ssmo.form_system, ssmo.form_display,
                    ssmo.route_code, ssmo.route_system, ssmo.route_display, ssmo.denominator_code,
                    ssmo.denominator_system, ro.tgl_peresepan, ro.jam_peresepan, rd.jml, ssm.id_medication,
                    rd.aturan_pakai, rd.no_resep, IFNULL(ssmr.id_medicationrequest, '') as id_medicationrequest,
                    rp.status_lanjut, '0' as is_racikan, '' as no_racik
                FROM reg_periksa rp
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                INNER JOIN resep_obat ro ON rp.no_rawat = ro.no_rawat
                INNER JOIN pegawai peg ON ro.kd_dokter = peg.nik
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                INNER JOIN resep_dokter rd ON rd.no_resep = ro.no_resep
                INNER JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = rd.kode_brng
                INNER JOIN satu_sehat_medication ssm ON ssm.kode_brng = ssmo.kode_brng
                LEFT JOIN satu_sehat_medicationrequest ssmr ON ssmr.no_resep = rd.no_resep AND ssmr.kode_brng = rd.kode_brng
                WHERE rp.tgl_registrasi BETWEEN :df1 AND :dt1
                  AND (ssmr.id_medicationrequest IS NULL OR ssmr.id_medicationrequest = '')
            )
            UNION ALL
            (
                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp,
                    peg.nama, peg.no_ktp as ktppraktisi, sse.id_encounter, ssmo.obat_code, ssmo.obat_system,
                    rdrd.kode_brng, ssmo.obat_display, ssmo.form_code, ssmo.form_system, ssmo.form_display,
                    ssmo.route_code, ssmo.route_system, ssmo.route_display, ssmo.denominator_code,
                    ssmo.denominator_system, ro.tgl_peresepan, ro.jam_peresepan, rdrd.jml, ssm.id_medication,
                    rdr.aturan_pakai, rdr.no_resep, IFNULL(ssmrr.id_medicationrequest, '') as id_medicationrequest,
                    rp.status_lanjut, '1' as is_racikan, rdrd.no_racik
                FROM reg_periksa rp
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                INNER JOIN resep_obat ro ON rp.no_rawat = ro.no_rawat
                INNER JOIN pegawai peg ON ro.kd_dokter = peg.nik
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                INNER JOIN resep_dokter_racikan rdr ON rdr.no_resep = ro.no_resep
                INNER JOIN resep_dokter_racikan_detail rdrd ON rdrd.no_resep = rdr.no_resep AND rdrd.no_racik = rdr.no_racik
                INNER JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = rdrd.kode_brng
                INNER JOIN satu_sehat_medication ssm ON ssm.kode_brng = ssmo.kode_brng
                LEFT JOIN satu_sehat_medicationrequest_racikan ssmrr ON ssmrr.no_resep = rdrd.no_resep AND ssmrr.kode_brng = rdrd.kode_brng AND ssmrr.no_racik = rdrd.no_racik
                WHERE rp.tgl_registrasi BETWEEN :df2 AND :dt2
                  AND (ssmrr.id_medicationrequest IS NULL OR ssmrr.id_medicationrequest = '')
            )
            ORDER BY tgl_registrasi ASC, jam_reg ASC
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute([
            'df1' => $dateFrom, 'dt1' => $dateTo,
            'df2' => $dateFrom, 'dt2' => $dateTo
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Unified query to fetch existing medication requests that have an ID (for PUT updates).
     */
    public function fetchPendingMedicationRequestUpdate(string $dateFrom, string $dateTo): array
    {
        $sql = "
            (
                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp,
                    peg.nama, peg.no_ktp as ktppraktisi, sse.id_encounter, ssmo.obat_code, ssmo.obat_system,
                    rd.kode_brng, ssmo.obat_display, ssmo.form_code, ssmo.form_system, ssmo.form_display,
                    ssmo.route_code, ssmo.route_system, ssmo.route_display, ssmo.denominator_code,
                    ssmo.denominator_system, ro.tgl_peresepan, ro.jam_peresepan, rd.jml, ssm.id_medication,
                    rd.aturan_pakai, rd.no_resep, ssmr.id_medicationrequest,
                    rp.status_lanjut, '0' as is_racikan, '' as no_racik
                FROM reg_periksa rp
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                INNER JOIN resep_obat ro ON rp.no_rawat = ro.no_rawat
                INNER JOIN pegawai peg ON ro.kd_dokter = peg.nik
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                INNER JOIN resep_dokter rd ON rd.no_resep = ro.no_resep
                INNER JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = rd.kode_brng
                INNER JOIN satu_sehat_medication ssm ON ssm.kode_brng = ssmo.kode_brng
                INNER JOIN satu_sehat_medicationrequest ssmr ON ssmr.no_resep = rd.no_resep AND ssmr.kode_brng = rd.kode_brng
                WHERE rp.tgl_registrasi BETWEEN :df1 AND :dt1
            )
            UNION ALL
            (
                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp,
                    peg.nama, peg.no_ktp as ktppraktisi, sse.id_encounter, ssmo.obat_code, ssmo.obat_system,
                    rdrd.kode_brng, ssmo.obat_display, ssmo.form_code, ssmo.form_system, ssmo.form_display,
                    ssmo.route_code, ssmo.route_system, ssmo.route_display, ssmo.denominator_code,
                    ssmo.denominator_system, ro.tgl_peresepan, ro.jam_peresepan, rdrd.jml, ssm.id_medication,
                    rdr.aturan_pakai, rdr.no_resep, ssmrr.id_medicationrequest,
                    rp.status_lanjut, '1' as is_racikan, rdrd.no_racik
                FROM reg_periksa rp
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                INNER JOIN resep_obat ro ON rp.no_rawat = ro.no_rawat
                INNER JOIN pegawai peg ON ro.kd_dokter = peg.nik
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                INNER JOIN resep_dokter_racikan rdr ON rdr.no_resep = ro.no_resep
                INNER JOIN resep_dokter_racikan_detail rdrd ON rdrd.no_resep = rdr.no_resep AND rdrd.no_racik = rdr.no_racik
                INNER JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = rdrd.kode_brng
                INNER JOIN satu_sehat_medication ssm ON ssm.kode_brng = ssmo.kode_brng
                INNER JOIN satu_sehat_medicationrequest_racikan ssmrr ON ssmrr.no_resep = rdrd.no_resep AND ssmrr.kode_brng = rdrd.kode_brng AND ssmrr.no_racik = rdrd.no_racik
                WHERE rp.tgl_registrasi BETWEEN :df2 AND :dt2
            )
            ORDER BY tgl_registrasi ASC, jam_reg ASC
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute([
            'df1' => $dateFrom, 'dt1' => $dateTo,
            'df2' => $dateFrom, 'dt2' => $dateTo
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Save the returned Satu Sehat MedicationRequest ID back to MySQL (handling both racikan and non-racikan).
     */
    public function saveMedicationRequest(
        string $noResep, 
        string $kodeBrng, 
        string $noRacik, 
        string $idMedicationRequest, 
        bool $isRacikan
    ): bool {
        if ($isRacikan) {
            $sql = "INSERT INTO satu_sehat_medicationrequest_racikan (no_resep, kode_brng, no_racik, id_medicationrequest) 
                    VALUES (:nr, :kb, :nrc, :id) 
                    ON DUPLICATE KEY UPDATE id_medicationrequest = :id2";
            $stmt = $this->mysql->prepare($sql);
            return $stmt->execute([
                'nr'  => $noResep,
                'kb'  => $kodeBrng,
                'nrc' => $noRacik,
                'id'  => $idMedicationRequest,
                'id2' => $idMedicationRequest
            ]);
        } else {
            $sql = "INSERT INTO satu_sehat_medicationrequest (no_resep, kode_brng, id_medicationrequest) 
                    VALUES (:nr, :kb, :id) 
                    ON DUPLICATE KEY UPDATE id_medicationrequest = :id2";
            $stmt = $this->mysql->prepare($sql);
            return $stmt->execute([
                'nr'  => $noResep,
                'kb'  => $kodeBrng,
                'id'  => $idMedicationRequest,
                'id2' => $idMedicationRequest
            ]);
        }
    }

    // ─── MEDICATION DISPENSE STATE TRACKING ─────────────────────────────────────

    public function getMedicationDispenseLocalState(
        string $noRawat, 
        string $tglPerawatan, 
        string $jam, 
        string $kodeBrng, 
        string $noBatch, 
        string $noFaktur
    ): ?string {
        $key = "{$noRawat}|{$tglPerawatan}|{$jam}|{$kodeBrng}|{$noBatch}|{$noFaktur}";
        $stmt = $this->sqlite->prepare("SELECT status FROM medicationdispense_state WHERE composite_key = :key");
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public function updateMedicationDispenseLocalState(
        string $noRawat, 
        string $tglPerawatan, 
        string $jam, 
        string $kodeBrng, 
        string $noBatch, 
        string $noFaktur, 
        string $status
    ): void {
        $key = "{$noRawat}|{$tglPerawatan}|{$jam}|{$kodeBrng}|{$noBatch}|{$noFaktur}";
        $stmt = $this->sqlite->prepare("
            INSERT INTO medicationdispense_state (composite_key, status, updated_at) 
            VALUES (:key, :st, CURRENT_TIMESTAMP)
            ON CONFLICT(composite_key) DO UPDATE SET status = excluded.status, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['key' => $key, 'st' => $status]);
    }

    // ─── MEDICATION DISPENSE MYSQL OPERATIONS ───────────────────────────────────

    /**
     * Helper to retrieve ID of synced MedicationRequest (if any).
     */
    public function getMedicationRequestId(string $noResep, string $kodeBrng): string
    {
        $sql = "SELECT id_medicationrequest FROM satu_sehat_medicationrequest WHERE no_resep = :nr AND kode_brng = :kb";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['nr' => $noResep, 'kb' => $kodeBrng]);
        return $stmt->fetchColumn() ?: '';
    }

    /**
     * Fetch pending MedicationDispense records cross Ralan and Ranap.
     */
    public function fetchPendingMedicationDispenseActive(string $dateFrom, string $dateTo): array
    {
        $sql = "
            (
                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp,
                    peg.nama, peg.no_ktp as ktppraktisi, sse.id_encounter, ssmo.obat_code, ssmo.obat_system,
                    dpo.kode_brng, ssmo.obat_display, ssmo.form_code, ssmo.form_system, ssmo.form_display,
                    ssmo.route_code, ssmo.route_system, ssmo.route_display, ssmo.denominator_code,
                    ssmo.denominator_system, ro.tgl_peresepan, ro.jam_peresepan, dpo.jml, ssm.id_medication,
                    ap.aturan, ro.no_resep, IFNULL(ssmd.id_medicationdispanse, '') as id_medicationdispanse,
                    dpo.no_batch, dpo.no_faktur, dpo.tgl_perawatan, dpo.jam,
                    ssml.id_lokasi_satusehat, b.nm_bangsal, 'Ralan' as status_pemberian
                FROM reg_periksa rp
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                INNER JOIN resep_obat ro ON rp.no_rawat = ro.no_rawat
                INNER JOIN pegawai peg ON ro.kd_dokter = peg.nik
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                INNER JOIN detail_pemberian_obat dpo ON dpo.no_rawat = ro.no_rawat 
                  AND dpo.tgl_perawatan = ro.tgl_perawatan AND dpo.jam = ro.jam
                INNER JOIN aturan_pakai ap ON dpo.no_rawat = ap.no_rawat 
                  AND dpo.tgl_perawatan = ap.tgl_perawatan AND dpo.jam = ap.jam AND dpo.kode_brng = ap.kode_brng
                INNER JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = dpo.kode_brng
                INNER JOIN bangsal b ON b.kd_bangsal = dpo.kd_bangsal
                INNER JOIN satu_sehat_mapping_lokasi_depo_farmasi ssml ON ssml.kd_bangsal = b.kd_bangsal
                INNER JOIN satu_sehat_medication ssm ON ssm.kode_brng = ssmo.kode_brng
                LEFT JOIN satu_sehat_medicationdispense ssmd ON ssmd.no_rawat = dpo.no_rawat 
                  AND ssmd.tgl_perawatan = dpo.tgl_perawatan AND ssmd.jam = dpo.jam 
                  AND ssmd.kode_brng = dpo.kode_brng AND ssmd.no_batch = dpo.no_batch AND ssmd.no_faktur = dpo.no_faktur
                WHERE dpo.status = 'Ralan' AND rp.tgl_registrasi BETWEEN :df1 AND :dt1
                  AND (ssmd.id_medicationdispanse IS NULL OR ssmd.id_medicationdispanse = '')
            )
            UNION ALL
            (
                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp,
                    peg.nama, peg.no_ktp as ktppraktisi, sse.id_encounter, ssmo.obat_code, ssmo.obat_system,
                    dpo.kode_brng, ssmo.obat_display, ssmo.form_code, ssmo.form_system, ssmo.form_display,
                    ssmo.route_code, ssmo.route_system, ssmo.route_display, ssmo.denominator_code,
                    ssmo.denominator_system, ro.tgl_peresepan, ro.jam_peresepan, dpo.jml, ssm.id_medication,
                    ap.aturan, ro.no_resep, IFNULL(ssmd.id_medicationdispanse, '') as id_medicationdispanse,
                    dpo.no_batch, dpo.no_faktur, dpo.tgl_perawatan, dpo.jam,
                    ssml.id_lokasi_satusehat, b.nm_bangsal, 'Ranap' as status_pemberian
                FROM reg_periksa rp
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                INNER JOIN resep_obat ro ON rp.no_rawat = ro.no_rawat
                INNER JOIN pegawai peg ON ro.kd_dokter = peg.nik
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                INNER JOIN detail_pemberian_obat dpo ON dpo.no_rawat = ro.no_rawat 
                  AND dpo.tgl_perawatan = ro.tgl_perawatan AND dpo.jam = ro.jam
                INNER JOIN aturan_pakai ap ON dpo.no_rawat = ap.no_rawat 
                  AND dpo.tgl_perawatan = ap.tgl_perawatan AND dpo.jam = ap.jam AND dpo.kode_brng = ap.kode_brng
                INNER JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = dpo.kode_brng
                INNER JOIN bangsal b ON b.kd_bangsal = dpo.kd_bangsal
                INNER JOIN satu_sehat_mapping_lokasi_depo_farmasi ssml ON ssml.kd_bangsal = b.kd_bangsal
                INNER JOIN satu_sehat_medication ssm ON ssm.kode_brng = ssmo.kode_brng
                LEFT JOIN satu_sehat_medicationdispense ssmd ON ssmd.no_rawat = dpo.no_rawat 
                  AND ssmd.tgl_perawatan = dpo.tgl_perawatan AND ssmd.jam = dpo.jam 
                  AND ssmd.kode_brng = dpo.kode_brng AND ssmd.no_batch = dpo.no_batch AND ssmd.no_faktur = dpo.no_faktur
                WHERE dpo.status = 'Ranap' AND rp.tgl_registrasi BETWEEN :df2 AND :dt2
                  AND (ssmd.id_medicationdispanse IS NULL OR ssmd.id_medicationdispanse = '')
            )
            ORDER BY tgl_registrasi ASC, jam_reg ASC
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute([
            'df1' => $dateFrom, 'dt1' => $dateTo,
            'df2' => $dateFrom, 'dt2' => $dateTo
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Fetch existing MedicationDispense records cross Ralan and Ranap (for Phase 2 updates).
     */
    public function fetchPendingMedicationDispenseUpdate(string $dateFrom, string $dateTo): array
    {
        $sql = "
            (
                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp,
                    peg.nama, peg.no_ktp as ktppraktisi, sse.id_encounter, ssmo.obat_code, ssmo.obat_system,
                    dpo.kode_brng, ssmo.obat_display, ssmo.form_code, ssmo.form_system, ssmo.form_display,
                    ssmo.route_code, ssmo.route_system, ssmo.route_display, ssmo.denominator_code,
                    ssmo.denominator_system, ro.tgl_peresepan, ro.jam_peresepan, dpo.jml, ssm.id_medication,
                    ap.aturan, ro.no_resep, ssmd.id_medicationdispanse,
                    dpo.no_batch, dpo.no_faktur, dpo.tgl_perawatan, dpo.jam,
                    ssml.id_lokasi_satusehat, b.nm_bangsal, 'Ralan' as status_pemberian
                FROM reg_periksa rp
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                INNER JOIN resep_obat ro ON rp.no_rawat = ro.no_rawat
                INNER JOIN pegawai peg ON ro.kd_dokter = peg.nik
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                INNER JOIN detail_pemberian_obat dpo ON dpo.no_rawat = ro.no_rawat 
                  AND dpo.tgl_perawatan = ro.tgl_perawatan AND dpo.jam = ro.jam
                INNER JOIN aturan_pakai ap ON dpo.no_rawat = ap.no_rawat 
                  AND dpo.tgl_perawatan = ap.tgl_perawatan AND dpo.jam = ap.jam AND dpo.kode_brng = ap.kode_brng
                INNER JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = dpo.kode_brng
                INNER JOIN bangsal b ON b.kd_bangsal = dpo.kd_bangsal
                INNER JOIN satu_sehat_mapping_lokasi_depo_farmasi ssml ON ssml.kd_bangsal = b.kd_bangsal
                INNER JOIN satu_sehat_medication ssm ON ssm.kode_brng = ssmo.kode_brng
                INNER JOIN satu_sehat_medicationdispense ssmd ON ssmd.no_rawat = dpo.no_rawat 
                  AND ssmd.tgl_perawatan = dpo.tgl_perawatan AND ssmd.jam = dpo.jam 
                  AND ssmd.kode_brng = dpo.kode_brng AND ssmd.no_batch = dpo.no_batch AND ssmd.no_faktur = dpo.no_faktur
                WHERE dpo.status = 'Ralan' AND rp.tgl_registrasi BETWEEN :df1 AND :dt1
            )
            UNION ALL
            (
                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp,
                    peg.nama, peg.no_ktp as ktppraktisi, sse.id_encounter, ssmo.obat_code, ssmo.obat_system,
                    dpo.kode_brng, ssmo.obat_display, ssmo.form_code, ssmo.form_system, ssmo.form_display,
                    ssmo.route_code, ssmo.route_system, ssmo.route_display, ssmo.denominator_code,
                    ssmo.denominator_system, ro.tgl_peresepan, ro.jam_peresepan, dpo.jml, ssm.id_medication,
                    ap.aturan, ro.no_resep, ssmd.id_medicationdispanse,
                    dpo.no_batch, dpo.no_faktur, dpo.tgl_perawatan, dpo.jam,
                    ssml.id_lokasi_satusehat, b.nm_bangsal, 'Ranap' as status_pemberian
                FROM reg_periksa rp
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                INNER JOIN resep_obat ro ON rp.no_rawat = ro.no_rawat
                INNER JOIN pegawai peg ON ro.kd_dokter = peg.nik
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                INNER JOIN detail_pemberian_obat dpo ON dpo.no_rawat = ro.no_rawat 
                  AND dpo.tgl_perawatan = ro.tgl_perawatan AND dpo.jam = ro.jam
                INNER JOIN aturan_pakai ap ON dpo.no_rawat = ap.no_rawat 
                  AND dpo.tgl_perawatan = ap.tgl_perawatan AND dpo.jam = ap.jam AND dpo.kode_brng = ap.kode_brng
                INNER JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = dpo.kode_brng
                INNER JOIN bangsal b ON b.kd_bangsal = dpo.kd_bangsal
                INNER JOIN satu_sehat_mapping_lokasi_depo_farmasi ssml ON ssml.kd_bangsal = b.kd_bangsal
                INNER JOIN satu_sehat_medication ssm ON ssm.kode_brng = ssmo.kode_brng
                INNER JOIN satu_sehat_medicationdispense ssmd ON ssmd.no_rawat = dpo.no_rawat 
                  AND ssmd.tgl_perawatan = dpo.tgl_perawatan AND ssmd.jam = dpo.jam 
                  AND ssmd.kode_brng = dpo.kode_brng AND ssmd.no_batch = dpo.no_batch AND ssmd.no_faktur = dpo.no_faktur
                WHERE dpo.status = 'Ranap' AND rp.tgl_registrasi BETWEEN :df2 AND :dt2
            )
            ORDER BY tgl_registrasi ASC, jam_reg ASC
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute([
            'df1' => $dateFrom, 'dt1' => $dateTo,
            'df2' => $dateFrom, 'dt2' => $dateTo
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Save the returned Satu Sehat MedicationDispense ID back to MySQL.
     */
    public function saveMedicationDispense(
        string $noRawat, 
        string $tglPerawatan, 
        string $jam, 
        string $kodeBrng, 
        string $noBatch, 
        string $noFaktur, 
        string $idMedicationDispense
    ): bool {
        $sql = "INSERT INTO satu_sehat_medicationdispense (no_rawat, tgl_perawatan, jam, kode_brng, no_batch, no_faktur, id_medicationdispanse) 
                VALUES (:nr, :tp, :jm, :kb, :nb, :nf, :id) 
                ON DUPLICATE KEY UPDATE id_medicationdispanse = :id2";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'nr'  => $noRawat,
            'tp'  => $tglPerawatan,
            'jm'  => $jam,
            'kb'  => $kodeBrng,
            'nb'  => $noBatch,
            'nf'  => $noFaktur,
            'id'  => $idMedicationDispense,
            'id2' => $idMedicationDispense
        ]);
    }

    // ─── MEDICATION STATEMENT STATE TRACKING ────────────────────────────────────

    public function getMedicationStatementLocalState(
        string $noResep, 
        string $kodeBrng, 
        string $noRacik
    ): ?string {
        $key = "{$noResep}|{$kodeBrng}|{$noRacik}";
        $stmt = $this->sqlite->prepare("SELECT status FROM medicationstatement_state WHERE composite_key = :key");
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public function updateMedicationStatementLocalState(
        string $noResep, 
        string $kodeBrng, 
        string $noRacik, 
        string $status
    ): void {
        $key = "{$noResep}|{$kodeBrng}|{$noRacik}";
        $stmt = $this->sqlite->prepare("
            INSERT INTO medicationstatement_state (composite_key, status, updated_at) 
            VALUES (:key, :st, CURRENT_TIMESTAMP)
            ON CONFLICT(composite_key) DO UPDATE SET status = excluded.status, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['key' => $key, 'st' => $status]);
    }

    // ─── MEDICATION STATEMENT MYSQL OPERATIONS ──────────────────────────────────

    /**
     * Fetch pending MedicationStatement records cross Ralan and Ranap, racikan and non-racikan.
     */
    public function fetchPendingMedicationStatementActive(string $dateFrom, string $dateTo): array
    {
        $sql = "
            (
                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp,
                    peg.nama, peg.no_ktp as ktppraktisi, sse.id_encounter, ssmo.obat_code, ssmo.obat_system,
                    rd.kode_brng, ssmo.obat_display, ssmo.form_code, ssmo.form_system, ssmo.form_display,
                    ssmo.route_code, ssmo.route_system, ssmo.route_display, ssmo.denominator_code,
                    ssmo.denominator_system, ro.tgl_penyerahan, ro.jam_penyerahan, rd.jml, ssm.id_medication,
                    rd.aturan_pakai, rd.no_resep, IFNULL(ssms.id_medicationstatement, '') as id_medicationstatement,
                    '' as no_racik, 'Ralan' as status_lanjut, 0 as is_racikan
                FROM reg_periksa rp
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                INNER JOIN resep_obat ro ON rp.no_rawat = ro.no_rawat
                INNER JOIN pegawai peg ON ro.kd_dokter = peg.nik
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                INNER JOIN resep_dokter rd ON rd.no_resep = ro.no_resep
                INNER JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = rd.kode_brng
                INNER JOIN satu_sehat_medication ssm ON ssm.kode_brng = ssmo.kode_brng
                LEFT JOIN satu_sehat_medicationstatement ssms ON ssms.no_resep = rd.no_resep AND ssms.kode_brng = rd.kode_brng
                WHERE rp.status_lanjut = 'Ralan' AND ro.tgl_penyerahan <> '0000-00-00' AND rp.tgl_registrasi BETWEEN :df1 AND :dt1
                  AND (ssms.id_medicationstatement IS NULL OR ssms.id_medicationstatement = '')
            )
            UNION ALL
            (
                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp,
                    peg.nama, peg.no_ktp as ktppraktisi, sse.id_encounter, ssmo.obat_code, ssmo.obat_system,
                    rd.kode_brng, ssmo.obat_display, ssmo.form_code, ssmo.form_system, ssmo.form_display,
                    ssmo.route_code, ssmo.route_system, ssmo.route_display, ssmo.denominator_code,
                    ssmo.denominator_system, ro.tgl_penyerahan, ro.jam_penyerahan, rd.jml, ssm.id_medication,
                    rd.aturan_pakai, rd.no_resep, IFNULL(ssms.id_medicationstatement, '') as id_medicationstatement,
                    '' as no_racik, 'Ranap' as status_lanjut, 0 as is_racikan
                FROM reg_periksa rp
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                INNER JOIN resep_obat ro ON rp.no_rawat = ro.no_rawat
                INNER JOIN pegawai peg ON ro.kd_dokter = peg.nik
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                INNER JOIN resep_dokter rd ON rd.no_resep = ro.no_resep
                INNER JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = rd.kode_brng
                INNER JOIN satu_sehat_medication ssm ON ssm.kode_brng = ssmo.kode_brng
                LEFT JOIN satu_sehat_medicationstatement ssms ON ssms.no_resep = rd.no_resep AND ssms.kode_brng = rd.kode_brng
                WHERE rp.status_lanjut = 'Ranap' AND ro.tgl_penyerahan <> '0000-00-00' AND rp.tgl_registrasi BETWEEN :df2 AND :dt2
                  AND (ssms.id_medicationstatement IS NULL OR ssms.id_medicationstatement = '')
            )
            UNION ALL
            (
                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp,
                    peg.nama, peg.no_ktp as ktppraktisi, sse.id_encounter, ssmo.obat_code, ssmo.obat_system,
                    rrd.kode_brng, ssmo.obat_display, ssmo.form_code, ssmo.form_system, ssmo.form_display,
                    ssmo.route_code, ssmo.route_system, ssmo.route_display, ssmo.denominator_code,
                    ssmo.denominator_system, ro.tgl_penyerahan, ro.jam_penyerahan, rrd.jml, ssm.id_medication,
                    rr.aturan_pakai, rr.no_resep, IFNULL(ssmsr.id_medicationstatement, '') as id_medicationstatement,
                    rrd.no_racik, 'Ralan' as status_lanjut, 1 as is_racikan
                FROM reg_periksa rp
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                INNER JOIN resep_obat ro ON rp.no_rawat = ro.no_rawat
                INNER JOIN pegawai peg ON ro.kd_dokter = peg.nik
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                INNER JOIN resep_dokter_racikan rr ON rr.no_resep = ro.no_resep
                INNER JOIN resep_dokter_racikan_detail rrd ON rrd.no_resep = rr.no_resep AND rrd.no_racik = rr.no_racik
                INNER JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = rrd.kode_brng
                INNER JOIN satu_sehat_medication ssm ON ssm.kode_brng = ssmo.kode_brng
                LEFT JOIN satu_sehat_medicationstatement_racikan ssmsr ON ssmsr.no_resep = rrd.no_resep 
                  AND ssmsr.kode_brng = rrd.kode_brng AND ssmsr.no_racik = rrd.no_racik
                WHERE rp.status_lanjut = 'Ralan' AND ro.tgl_penyerahan <> '0000-00-00' AND rp.tgl_registrasi BETWEEN :df3 AND :dt3
                  AND (ssmsr.id_medicationstatement IS NULL OR ssmsr.id_medicationstatement = '')
            )
            UNION ALL
            (
                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp,
                    peg.nama, peg.no_ktp as ktppraktisi, sse.id_encounter, ssmo.obat_code, ssmo.obat_system,
                    rrd.kode_brng, ssmo.obat_display, ssmo.form_code, ssmo.form_system, ssmo.form_display,
                    ssmo.route_code, ssmo.route_system, ssmo.route_display, ssmo.denominator_code,
                    ssmo.denominator_system, ro.tgl_penyerahan, ro.jam_penyerahan, rrd.jml, ssm.id_medication,
                    rr.aturan_pakai, rr.no_resep, IFNULL(ssmsr.id_medicationstatement, '') as id_medicationstatement,
                    rrd.no_racik, 'Ranap' as status_lanjut, 1 as is_racikan
                FROM reg_periksa rp
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                INNER JOIN resep_obat ro ON rp.no_rawat = ro.no_rawat
                INNER JOIN pegawai peg ON ro.kd_dokter = peg.nik
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                INNER JOIN resep_dokter_racikan rr ON rr.no_resep = ro.no_resep
                INNER JOIN resep_dokter_racikan_detail rrd ON rrd.no_resep = rr.no_resep AND rrd.no_racik = rr.no_racik
                INNER JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = rrd.kode_brng
                INNER JOIN satu_sehat_medication ssm ON ssm.kode_brng = ssmo.kode_brng
                LEFT JOIN satu_sehat_medicationstatement_racikan ssmsr ON ssmsr.no_resep = rrd.no_resep 
                  AND ssmsr.kode_brng = rrd.kode_brng AND ssmsr.no_racik = rrd.no_racik
                WHERE rp.status_lanjut = 'Ranap' AND ro.tgl_penyerahan <> '0000-00-00' AND rp.tgl_registrasi BETWEEN :df4 AND :dt4
                  AND (ssmsr.id_medicationstatement IS NULL OR ssmsr.id_medicationstatement = '')
            )
            ORDER BY tgl_registrasi ASC, jam_reg ASC
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute([
            'df1' => $dateFrom, 'dt1' => $dateTo,
            'df2' => $dateFrom, 'dt2' => $dateTo,
            'df3' => $dateFrom, 'dt3' => $dateTo,
            'df4' => $dateFrom, 'dt4' => $dateTo
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Fetch existing MedicationStatement records cross Ralan and Ranap, racikan and non-racikan (for updates).
     */
    public function fetchPendingMedicationStatementUpdate(string $dateFrom, string $dateTo): array
    {
        $sql = "
            (
                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp,
                    peg.nama, peg.no_ktp as ktppraktisi, sse.id_encounter, ssmo.obat_code, ssmo.obat_system,
                    rd.kode_brng, ssmo.obat_display, ssmo.form_code, ssmo.form_system, ssmo.form_display,
                    ssmo.route_code, ssmo.route_system, ssmo.route_display, ssmo.denominator_code,
                    ssmo.denominator_system, ro.tgl_penyerahan, ro.jam_penyerahan, rd.jml, ssm.id_medication,
                    rd.aturan_pakai, rd.no_resep, ssms.id_medicationstatement,
                    '' as no_racik, 'Ralan' as status_lanjut, 0 as is_racikan
                FROM reg_periksa rp
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                INNER JOIN resep_obat ro ON rp.no_rawat = ro.no_rawat
                INNER JOIN pegawai peg ON ro.kd_dokter = peg.nik
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                INNER JOIN resep_dokter rd ON rd.no_resep = ro.no_resep
                INNER JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = rd.kode_brng
                INNER JOIN satu_sehat_medication ssm ON ssm.kode_brng = ssmo.kode_brng
                INNER JOIN satu_sehat_medicationstatement ssms ON ssms.no_resep = rd.no_resep AND ssms.kode_brng = rd.kode_brng
                WHERE rp.status_lanjut = 'Ralan' AND ro.tgl_penyerahan <> '0000-00-00' AND rp.tgl_registrasi BETWEEN :df1 AND :dt1
            )
            UNION ALL
            (
                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp,
                    peg.nama, peg.no_ktp as ktppraktisi, sse.id_encounter, ssmo.obat_code, ssmo.obat_system,
                    rd.kode_brng, ssmo.obat_display, ssmo.form_code, ssmo.form_system, ssmo.form_display,
                    ssmo.route_code, ssmo.route_system, ssmo.route_display, ssmo.denominator_code,
                    ssmo.denominator_system, ro.tgl_penyerahan, ro.jam_penyerahan, rd.jml, ssm.id_medication,
                    rd.aturan_pakai, rd.no_resep, ssms.id_medicationstatement,
                    '' as no_racik, 'Ranap' as status_lanjut, 0 as is_racikan
                FROM reg_periksa rp
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                INNER JOIN resep_obat ro ON rp.no_rawat = ro.no_rawat
                INNER JOIN pegawai peg ON ro.kd_dokter = peg.nik
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                INNER JOIN resep_dokter rd ON rd.no_resep = ro.no_resep
                INNER JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = rd.kode_brng
                INNER JOIN satu_sehat_medication ssm ON ssm.kode_brng = ssmo.kode_brng
                INNER JOIN satu_sehat_medicationstatement ssms ON ssms.no_resep = rd.no_resep AND ssms.kode_brng = rd.kode_brng
                WHERE rp.status_lanjut = 'Ranap' AND ro.tgl_penyerahan <> '0000-00-00' AND rp.tgl_registrasi BETWEEN :df2 AND :dt2
            )
            UNION ALL
            (
                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp,
                    peg.nama, peg.no_ktp as ktppraktisi, sse.id_encounter, ssmo.obat_code, ssmo.obat_system,
                    rrd.kode_brng, ssmo.obat_display, ssmo.form_code, ssmo.form_system, ssmo.form_display,
                    ssmo.route_code, ssmo.route_system, ssmo.route_display, ssmo.denominator_code,
                    ssmo.denominator_system, ro.tgl_penyerahan, ro.jam_penyerahan, rrd.jml, ssm.id_medication,
                    rr.aturan_pakai, rr.no_resep, ssmsr.id_medicationstatement,
                    rrd.no_racik, 'Ralan' as status_lanjut, 1 as is_racikan
                FROM reg_periksa rp
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                INNER JOIN resep_obat ro ON rp.no_rawat = ro.no_rawat
                INNER JOIN pegawai peg ON ro.kd_dokter = peg.nik
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                INNER JOIN resep_dokter_racikan rr ON rr.no_resep = ro.no_resep
                INNER JOIN resep_dokter_racikan_detail rrd ON rrd.no_resep = rr.no_resep AND rrd.no_racik = rr.no_racik
                INNER JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = rrd.kode_brng
                INNER JOIN satu_sehat_medication ssm ON ssm.kode_brng = ssmo.kode_brng
                INNER JOIN satu_sehat_medicationstatement_racikan ssmsr ON ssmsr.no_resep = rrd.no_resep 
                  AND ssmsr.kode_brng = rrd.kode_brng AND ssmsr.no_racik = rrd.no_racik
                WHERE rp.status_lanjut = 'Ralan' AND ro.tgl_penyerahan <> '0000-00-00' AND rp.tgl_registrasi BETWEEN :df3 AND :dt3
            )
            UNION ALL
            (
                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp,
                    peg.nama, peg.no_ktp as ktppraktisi, sse.id_encounter, ssmo.obat_code, ssmo.obat_system,
                    rrd.kode_brng, ssmo.obat_display, ssmo.form_code, ssmo.form_system, ssmo.form_display,
                    ssmo.route_code, ssmo.route_system, ssmo.route_display, ssmo.denominator_code,
                    ssmo.denominator_system, ro.tgl_penyerahan, ro.jam_penyerahan, rrd.jml, ssm.id_medication,
                    rr.aturan_pakai, rr.no_resep, ssmsr.id_medicationstatement,
                    rrd.no_racik, 'Ranap' as status_lanjut, 1 as is_racikan
                FROM reg_periksa rp
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                INNER JOIN resep_obat ro ON rp.no_rawat = ro.no_rawat
                INNER JOIN pegawai peg ON ro.kd_dokter = peg.nik
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                INNER JOIN resep_dokter_racikan rr ON rr.no_resep = ro.no_resep
                INNER JOIN resep_dokter_racikan_detail rrd ON rrd.no_resep = rr.no_resep AND rrd.no_racik = rr.no_racik
                INNER JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = rrd.kode_brng
                INNER JOIN satu_sehat_medication ssm ON ssm.kode_brng = ssmo.kode_brng
                INNER JOIN satu_sehat_medicationstatement_racikan ssmsr ON ssmsr.no_resep = rrd.no_resep 
                  AND ssmsr.kode_brng = rrd.kode_brng AND ssmsr.no_racik = rrd.no_racik
                WHERE rp.status_lanjut = 'Ranap' AND ro.tgl_penyerahan <> '0000-00-00' AND rp.tgl_registrasi BETWEEN :df4 AND :dt4
            )
            ORDER BY tgl_registrasi ASC, jam_reg ASC
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute([
            'df1' => $dateFrom, 'dt1' => $dateTo,
            'df2' => $dateFrom, 'dt2' => $dateTo,
            'df3' => $dateFrom, 'dt3' => $dateTo,
            'df4' => $dateFrom, 'dt4' => $dateTo
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Save the returned Satu Sehat MedicationStatement ID back to MySQL (handling both racikan and non-racikan).
     */
    public function saveMedicationStatement(
        string $noResep, 
        string $kodeBrng, 
        string $noRacik, 
        string $idMedicationStatement, 
        bool $isRacikan
    ): bool {
        if ($isRacikan) {
            $sql = "INSERT INTO satu_sehat_medicationstatement_racikan (no_resep, kode_brng, no_racik, id_medicationstatement) 
                    VALUES (:nr, :kb, :nrc, :id) 
                    ON DUPLICATE KEY UPDATE id_medicationstatement = :id2";
            $stmt = $this->mysql->prepare($sql);
            return $stmt->execute([
                'nr'  => $noResep,
                'kb'  => $kodeBrng,
                'nrc' => $noRacik,
                'id'  => $idMedicationStatement,
                'id2' => $idMedicationStatement
            ]);
        } else {
            $sql = "INSERT INTO satu_sehat_medicationstatement (no_resep, kode_brng, id_medicationstatement) 
                    VALUES (:nr, :kb, :id) 
                    ON DUPLICATE KEY UPDATE id_medicationstatement = :id2";
            $stmt = $this->mysql->prepare($sql);
            return $stmt->execute([
                'nr'  => $noResep,
                'kb'  => $kodeBrng,
                'id'  => $idMedicationStatement,
                'id2' => $idMedicationStatement
            ]);
        }
    }

    // ─── CLINICAL IMPRESSION STATE TRACKING ──────────────────────────────────────

    public function getClinicalImpressionLocalState(string $noRawat, string $tglPerawatan, string $jamRawat, string $status, string $kdPenyakit = ''): ?string
    {
        $compositeKey = $noRawat . '_' . $tglPerawatan . '_' . $jamRawat . '_' . $status . ($kdPenyakit !== '' ? '_' . $kdPenyakit : '');
        $stmt = $this->sqlite->prepare("SELECT status FROM clinical_impression_state WHERE composite_key = :ck");
        $stmt->execute(['ck' => $compositeKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public function updateClinicalImpressionLocalState(string $noRawat, string $tglPerawatan, string $jamRawat, string $status, string $localStatus, string $kdPenyakit = ''): void
    {
        $compositeKey = $noRawat . '_' . $tglPerawatan . '_' . $jamRawat . '_' . $status . ($kdPenyakit !== '' ? '_' . $kdPenyakit : '');
        $stmt = $this->sqlite->prepare("
            INSERT INTO clinical_impression_state (composite_key, status, updated_at) 
            VALUES (:ck, :st, CURRENT_TIMESTAMP)
            ON CONFLICT(composite_key) DO UPDATE SET status = excluded.status, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['ck' => $compositeKey, 'st' => $localStatus]);
    }

    // ─── DIAGNOSTIC REPORT LAB PK STATE TRACKING ──────────────────────────────

    public function getDiagnosticReportLabPkLocalState(string $noorder, int $idTemplate, string $code): ?string
    {
        $compositeKey = $noorder . '_' . $idTemplate . '_' . $code;
        $stmt = $this->sqlite->prepare("SELECT status FROM diagnosticreport_lab_pk_state WHERE composite_key = :ck");
        $stmt->execute(['ck' => $compositeKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public function updateDiagnosticReportLabPkLocalState(string $noorder, int $idTemplate, string $code, string $status): void
    {
        $compositeKey = $noorder . '_' . $idTemplate . '_' . $code;
        $stmt = $this->sqlite->prepare("
            INSERT INTO diagnosticreport_lab_pk_state (composite_key, status, updated_at) 
            VALUES (:ck, :st, CURRENT_TIMESTAMP)
            ON CONFLICT(composite_key) DO UPDATE SET status = excluded.status, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['ck' => $compositeKey, 'st' => $status]);
    }

    // ─── DIAGNOSTIC REPORT LAB MB STATE TRACKING ──────────────────────────────

    public function getDiagnosticReportLabMbLocalState(string $noorder, int $idTemplate, string $code): ?string
    {
        $compositeKey = $noorder . '_' . $idTemplate . '_' . $code;
        $stmt = $this->sqlite->prepare("SELECT status FROM diagnosticreport_lab_mb_state WHERE composite_key = :ck");
        $stmt->execute(['ck' => $compositeKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public function updateDiagnosticReportLabMbLocalState(string $noorder, int $idTemplate, string $code, string $status): void
    {
        $compositeKey = $noorder . '_' . $idTemplate . '_' . $code;
        $stmt = $this->sqlite->prepare("
            INSERT INTO diagnosticreport_lab_mb_state (composite_key, status, updated_at) 
            VALUES (:ck, :st, CURRENT_TIMESTAMP)
            ON CONFLICT(composite_key) DO UPDATE SET status = excluded.status, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['ck' => $compositeKey, 'st' => $status]);
    }

    // ─── CLINICAL IMPRESSION MYSQL OPERATIONS ───────────────────────────────────

    public function fetchPendingClinicalImpressionActive(string $dateFrom, string $dateTo): array
    {
        $ralanSql = "
            SELECT 
                rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis,
                p.nm_pasien, p.no_ktp as nik_pasien, rp.stts,
                'Ralan' as status_lanjut,
                CONCAT(rp.tgl_registrasi, ' ', rp.jam_reg) as pulang,
                sse.id_encounter, 
                CONCAT(pem.keluhan, ', ', pem.pemeriksaan) as keluhan_pemeriksaan,
                pem.penilaian, peg.nama as nm_praktisi, peg.no_ktp as nik_praktisi,
                pem.tgl_perawatan, pem.jam_rawat, ssc.kd_penyakit, py.nm_penyakit,
                ssc.id_condition, '' as id_clinicalimpression
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            INNER JOIN satu_sehat_condition ssc ON ssc.no_rawat = rp.no_rawat AND ssc.status = 'Ralan'
            INNER JOIN penyakit py ON py.kd_penyakit = ssc.kd_penyakit
            INNER JOIN pemeriksaan_ralan pem ON pem.no_rawat = rp.no_rawat
            INNER JOIN pegawai peg ON pem.nip = peg.nik
            LEFT JOIN satu_sehat_clinicalimpression ssci ON ssci.no_rawat = pem.no_rawat
                AND ssci.tgl_perawatan = pem.tgl_perawatan
                AND ssci.jam_rawat = pem.jam_rawat
                AND ssci.status = 'Ralan'
            WHERE pem.penilaian <> ''
              AND rp.tgl_registrasi BETWEEN :df AND :dt
              AND ssci.id_clinicalimpression IS NULL
        ";

        $ranapSql = "
            SELECT 
                rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis,
                p.nm_pasien, p.no_ktp as nik_pasien, rp.stts,
                'Ranap' as status_lanjut,
                CONCAT(rp.tgl_registrasi, ' ', rp.jam_reg) as pulang,
                sse.id_encounter, 
                CONCAT(pem.keluhan, ', ', pem.pemeriksaan) as keluhan_pemeriksaan,
                pem.penilaian, peg.nama as nm_praktisi, peg.no_ktp as nik_praktisi,
                pem.tgl_perawatan, pem.jam_rawat, ssc.kd_penyakit, py.nm_penyakit,
                ssc.id_condition, '' as id_clinicalimpression
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            INNER JOIN satu_sehat_condition ssc ON ssc.no_rawat = rp.no_rawat AND ssc.status = 'Ranap'
            INNER JOIN penyakit py ON py.kd_penyakit = ssc.kd_penyakit
            INNER JOIN pemeriksaan_ranap pem ON pem.no_rawat = rp.no_rawat
            INNER JOIN pegawai peg ON pem.nip = peg.nik
            LEFT JOIN satu_sehat_clinicalimpression ssci ON ssci.no_rawat = pem.no_rawat
                AND ssci.tgl_perawatan = pem.tgl_perawatan
                AND ssci.jam_rawat = pem.jam_rawat
                AND ssci.status = 'Ranap'
            WHERE pem.penilaian <> ''
              AND rp.tgl_registrasi BETWEEN :df2 AND :dt2
              AND ssci.id_clinicalimpression IS NULL
        ";

        $stmtRalan = $this->mysql->prepare($ralanSql);
        $stmtRalan->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        $ralan = $stmtRalan->fetchAll();

        $stmtRanap = $this->mysql->prepare($ranapSql);
        $stmtRanap->execute(['df2' => $dateFrom, 'dt2' => $dateTo]);
        $ranap = $stmtRanap->fetchAll();

        return array_merge($ralan, $ranap);
    }

    public function fetchPendingClinicalImpressionUpdate(string $dateFrom, string $dateTo): array
    {
        $ralanSql = "
            SELECT 
                rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis,
                p.nm_pasien, p.no_ktp as nik_pasien, rp.stts,
                'Ralan' as status_lanjut,
                CONCAT(rp.tgl_registrasi, ' ', rp.jam_reg) as pulang,
                sse.id_encounter, 
                CONCAT(pem.keluhan, ', ', pem.pemeriksaan) as keluhan_pemeriksaan,
                pem.penilaian, peg.nama as nm_praktisi, peg.no_ktp as nik_praktisi,
                pem.tgl_perawatan, pem.jam_rawat, ssc.kd_penyakit, py.nm_penyakit,
                ssc.id_condition, ssci.id_clinicalimpression
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            INNER JOIN satu_sehat_condition ssc ON ssc.no_rawat = rp.no_rawat AND ssc.status = 'Ralan'
            INNER JOIN penyakit py ON py.kd_penyakit = ssc.kd_penyakit
            INNER JOIN pemeriksaan_ralan pem ON pem.no_rawat = rp.no_rawat
            INNER JOIN pegawai peg ON pem.nip = peg.nik
            INNER JOIN satu_sehat_clinicalimpression ssci ON ssci.no_rawat = pem.no_rawat
                AND ssci.tgl_perawatan = pem.tgl_perawatan
                AND ssci.jam_rawat = pem.jam_rawat
                AND ssci.status = 'Ralan'
            WHERE pem.penilaian <> ''
              AND rp.tgl_registrasi BETWEEN :df AND :dt
        ";

        $ranapSql = "
            SELECT 
                rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis,
                p.nm_pasien, p.no_ktp as nik_pasien, rp.stts,
                'Ranap' as status_lanjut,
                CONCAT(rp.tgl_registrasi, ' ', rp.jam_reg) as pulang,
                sse.id_encounter, 
                CONCAT(pem.keluhan, ', ', pem.pemeriksaan) as keluhan_pemeriksaan,
                pem.penilaian, peg.nama as nm_praktisi, peg.no_ktp as nik_praktisi,
                pem.tgl_perawatan, pem.jam_rawat, ssc.kd_penyakit, py.nm_penyakit,
                ssc.id_condition, ssci.id_clinicalimpression
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            INNER JOIN satu_sehat_condition ssc ON ssc.no_rawat = rp.no_rawat AND ssc.status = 'Ranap'
            INNER JOIN penyakit py ON py.kd_penyakit = ssc.kd_penyakit
            INNER JOIN pemeriksaan_ranap pem ON pem.no_rawat = rp.no_rawat
            INNER JOIN pegawai peg ON pem.nip = peg.nik
            INNER JOIN satu_sehat_clinicalimpression ssci ON ssci.no_rawat = pem.no_rawat
                AND ssci.tgl_perawatan = pem.tgl_perawatan
                AND ssci.jam_rawat = pem.jam_rawat
                AND ssci.status = 'Ranap'
            WHERE pem.penilaian <> ''
              AND rp.tgl_registrasi BETWEEN :df2 AND :dt2
        ";

        $stmtRalan = $this->mysql->prepare($ralanSql);
        $stmtRalan->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        $ralan = $stmtRalan->fetchAll();

        $stmtRanap = $this->mysql->prepare($ranapSql);
        $stmtRanap->execute(['df2' => $dateFrom, 'dt2' => $dateTo]);
        $ranap = $stmtRanap->fetchAll();

        return array_merge($ralan, $ranap);
    }

    public function saveClinicalImpression(
        string $noRawat, 
        string $tglPerawatan, 
        string $jamRawat, 
        string $status, 
        string $idClinicalImpression
    ): bool {
        $sql = "INSERT INTO satu_sehat_clinicalimpression (no_rawat, tgl_perawatan, jam_rawat, status, id_clinicalimpression) 
                VALUES (:nr, :tgl, :jam, :st, :id) 
                ON DUPLICATE KEY UPDATE id_clinicalimpression = :id2";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'nr'  => $noRawat,
            'tgl' => $tglPerawatan,
            'jam' => $jamRawat,
            'st'  => $status,
            'id'  => $idClinicalImpression,
            'id2' => $idClinicalImpression
        ]);
    }

    // ─── SERVICEREQUEST RADIOLOGI MYSQL OPERATIONS ──────────────────────────────

    public function fetchPendingServiceRequestRadiologiActive(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT 
                rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp as nik_pasien, 
                p.tgl_lahir, p.jk, rp.kd_dokter, peg.nama, peg.no_ktp as nik_praktisi,
                sse.id_encounter, pr.noorder, pr.tgl_permintaan, pr.jam_permintaan, 
                pr.diagnosa_klinis, jpr.nm_perawatan,
                IFNULL(smr.code, '') as code, IFNULL(smr.system, '') as system, IFNULL(smr.display, '') as display,
                ppr.kd_jenis_prw, '' as id_servicerequest
            FROM permintaan_radiologi pr
            INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN pegawai peg ON peg.nik = rp.kd_dokter
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
            INNER JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = ppr.kd_jenis_prw
            LEFT JOIN satu_sehat_mapping_radiologi smr ON smr.kd_jenis_prw = jpr.kd_jenis_prw
            LEFT JOIN satu_sehat_servicerequest_radiologi ssr ON ssr.noorder = ppr.noorder 
                AND ssr.kd_jenis_prw = ppr.kd_jenis_prw
            WHERE pr.tgl_permintaan BETWEEN :df AND :dt
              AND (ssr.id_servicerequest IS NULL OR ssr.id_servicerequest = '' OR ssr.id_servicerequest = '-')
            GROUP BY ppr.noorder, ppr.kd_jenis_prw
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function fetchPendingServiceRequestRadiologiUpdate(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT 
                rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp as nik_pasien, 
                p.tgl_lahir, p.jk, rp.kd_dokter, peg.nama, peg.no_ktp as nik_praktisi,
                sse.id_encounter, pr.noorder, pr.tgl_permintaan, pr.jam_permintaan, 
                pr.diagnosa_klinis, jpr.nm_perawatan,
                IFNULL(smr.code, '') as code, IFNULL(smr.system, '') as system, IFNULL(smr.display, '') as display,
                ppr.kd_jenis_prw, ssr.id_servicerequest
            FROM permintaan_radiologi pr
            INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN pegawai peg ON peg.nik = rp.kd_dokter
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
            INNER JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = ppr.kd_jenis_prw
            LEFT JOIN satu_sehat_mapping_radiologi smr ON smr.kd_jenis_prw = jpr.kd_jenis_prw
            INNER JOIN satu_sehat_servicerequest_radiologi ssr ON ssr.noorder = ppr.noorder 
                AND ssr.kd_jenis_prw = ppr.kd_jenis_prw
            WHERE pr.tgl_permintaan BETWEEN :df AND :dt
              AND ssr.id_servicerequest IS NOT NULL AND ssr.id_servicerequest <> '' AND ssr.id_servicerequest <> '-'
            GROUP BY ppr.noorder, ppr.kd_jenis_prw
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function saveServiceRequestRadiologi(
        string $noorder, 
        string $kdJenisPrw, 
        string $idServiceRequest
    ): bool {
        $sql = "INSERT INTO satu_sehat_servicerequest_radiologi (noorder, kd_jenis_prw, id_servicerequest) 
                VALUES (:noorder, :kd, :id) 
                ON DUPLICATE KEY UPDATE id_servicerequest = :id2";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'noorder' => $noorder,
            'kd'      => $kdJenisPrw,
            'id'      => $idServiceRequest,
            'id2'     => $idServiceRequest
        ]);
    }

    // ─── DIAGNOSTICREPORT RADIOLOGI MYSQL OPERATIONS ────────────────────────────

    public function fetchPendingDiagnosticReportRadiologiActive(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT DISTINCT 
                rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp as nik_pasien, 
                prad.kd_dokter, peg.nama, peg.no_ktp as nik_praktisi,
                sse.id_encounter, pr.noorder, pr.tgl_hasil, pr.jam_hasil, pr.diagnosa_klinis,
                jpr.nm_perawatan, IFNULL(smr.code, '') as code, IFNULL(smr.system, '') as system, IFNULL(smr.display, '') as display,
                ssr.id_servicerequest, ppr.kd_jenis_prw, sssp.id_specimen,
                ssi.id_imaging, sso.id_observation, 
                '' as id_diagnosticreport, hr.hasil
            FROM reg_periksa rp 
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat 
            INNER JOIN permintaan_radiologi pr ON pr.no_rawat = rp.no_rawat
            INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
            INNER JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = ppr.kd_jenis_prw
            INNER JOIN satu_sehat_mapping_radiologi smr ON smr.kd_jenis_prw = jpr.kd_jenis_prw
            INNER JOIN satu_sehat_servicerequest_radiologi ssr ON ssr.noorder = ppr.noorder AND ssr.kd_jenis_prw = ppr.kd_jenis_prw
            INNER JOIN satu_sehat_imagingstudy_radiologi ssi ON ssi.noorder = ppr.noorder AND ssi.kd_jenis_prw = ppr.kd_jenis_prw
            INNER JOIN satu_sehat_specimen_radiologi sssp ON ssr.noorder = sssp.noorder AND ssr.kd_jenis_prw = sssp.kd_jenis_prw
            INNER JOIN periksa_radiologi prad ON prad.no_rawat = pr.no_rawat AND prad.tgl_periksa = pr.tgl_hasil AND prad.jam = pr.jam_hasil AND prad.dokter_perujuk = pr.dokter_perujuk
            INNER JOIN hasil_radiologi hr ON prad.no_rawat = hr.no_rawat AND prad.tgl_periksa = hr.tgl_periksa AND prad.jam = hr.jam
            INNER JOIN satu_sehat_observation_radiologi sso ON sssp.noorder = sso.noorder AND sssp.kd_jenis_prw = sso.kd_jenis_prw
            LEFT JOIN satu_sehat_diagnosticreport_radiologi ssdr ON ssr.noorder = ssdr.noorder AND ssr.kd_jenis_prw = ssdr.kd_jenis_prw
            INNER JOIN pegawai peg ON prad.kd_dokter = peg.nik
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
              AND (ssdr.id_diagnosticreport IS NULL OR ssdr.id_diagnosticreport = '' OR ssdr.id_diagnosticreport = '-')
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function fetchPendingDiagnosticReportRadiologiUpdate(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT DISTINCT 
                rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp as nik_pasien, 
                prad.kd_dokter, peg.nama, peg.no_ktp as nik_praktisi,
                sse.id_encounter, pr.noorder, pr.tgl_hasil, pr.jam_hasil, pr.diagnosa_klinis,
                jpr.nm_perawatan, IFNULL(smr.code, '') as code, IFNULL(smr.system, '') as system, IFNULL(smr.display, '') as display,
                ssr.id_servicerequest, ppr.kd_jenis_prw, sssp.id_specimen,
                ssi.id_imaging, sso.id_observation, 
                ssdr.id_diagnosticreport, hr.hasil
            FROM reg_periksa rp 
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat 
            INNER JOIN permintaan_radiologi pr ON pr.no_rawat = rp.no_rawat
            INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
            INNER JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = ppr.kd_jenis_prw
            INNER JOIN satu_sehat_mapping_radiologi smr ON smr.kd_jenis_prw = jpr.kd_jenis_prw
            INNER JOIN satu_sehat_servicerequest_radiologi ssr ON ssr.noorder = ppr.noorder AND ssr.kd_jenis_prw = ppr.kd_jenis_prw
            INNER JOIN satu_sehat_imagingstudy_radiologi ssi ON ssi.noorder = ppr.noorder AND ssi.kd_jenis_prw = ppr.kd_jenis_prw
            INNER JOIN satu_sehat_specimen_radiologi sssp ON ssr.noorder = sssp.noorder AND ssr.kd_jenis_prw = sssp.kd_jenis_prw
            INNER JOIN periksa_radiologi prad ON prad.no_rawat = pr.no_rawat AND prad.tgl_periksa = pr.tgl_hasil AND prad.jam = pr.jam_hasil AND prad.dokter_perujuk = pr.dokter_perujuk
            INNER JOIN hasil_radiologi hr ON prad.no_rawat = hr.no_rawat AND prad.tgl_periksa = hr.tgl_periksa AND prad.jam = hr.jam
            INNER JOIN satu_sehat_observation_radiologi sso ON sssp.noorder = sso.noorder AND sssp.kd_jenis_prw = sso.kd_jenis_prw
            INNER JOIN satu_sehat_diagnosticreport_radiologi ssdr ON ssr.noorder = ssdr.noorder AND ssr.kd_jenis_prw = ssdr.kd_jenis_prw
            INNER JOIN pegawai peg ON prad.kd_dokter = peg.nik
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
              AND ssdr.id_diagnosticreport IS NOT NULL AND ssdr.id_diagnosticreport <> '' AND ssdr.id_diagnosticreport <> '-'
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function saveDiagnosticReportRadiologi(
        string $noorder, 
        string $kdJenisPrw, 
        string $idDiagnosticReport
    ): bool {
        $sql = "INSERT INTO satu_sehat_diagnosticreport_radiologi (noorder, kd_jenis_prw, id_diagnosticreport) 
                VALUES (:noorder, :kd, :id) 
                ON DUPLICATE KEY UPDATE id_diagnosticreport = :id2";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'noorder' => $noorder,
            'kd'      => $kdJenisPrw,
            'id'      => $idDiagnosticReport,
            'id2'     => $idDiagnosticReport
        ]);
    }

    // ─── SPECIMEN RADIOLOGI MYSQL OPERATIONS ────────────────────────────────────

    public function fetchPendingSpecimenRadiologiActive(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT DISTINCT 
                rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp as nik_pasien, 
                pr.noorder, pr.tgl_sampel, pr.jam_sampel, jpr.nm_perawatan,
                smr.sampel_code, smr.sampel_system, smr.sampel_display,
                ssr.id_servicerequest, ppr.kd_jenis_prw, '' as id_specimen
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN permintaan_radiologi pr ON pr.no_rawat = rp.no_rawat
            INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
            INNER JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = ppr.kd_jenis_prw
            INNER JOIN satu_sehat_mapping_radiologi smr ON smr.kd_jenis_prw = jpr.kd_jenis_prw
            INNER JOIN satu_sehat_servicerequest_radiologi ssr ON ssr.noorder = ppr.noorder AND ssr.kd_jenis_prw = ppr.kd_jenis_prw
            LEFT JOIN satu_sehat_specimen_radiologi sssp ON ssr.noorder = sssp.noorder AND ssr.kd_jenis_prw = sssp.kd_jenis_prw
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
              AND (sssp.id_specimen IS NULL OR sssp.id_specimen = '' OR sssp.id_specimen = '-')
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function fetchPendingSpecimenRadiologiUpdate(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT DISTINCT 
                rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp as nik_pasien, 
                pr.noorder, pr.tgl_sampel, pr.jam_sampel, jpr.nm_perawatan,
                smr.sampel_code, smr.sampel_system, smr.sampel_display,
                ssr.id_servicerequest, ppr.kd_jenis_prw, sssp.id_specimen
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN permintaan_radiologi pr ON pr.no_rawat = rp.no_rawat
            INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
            INNER JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = ppr.kd_jenis_prw
            INNER JOIN satu_sehat_mapping_radiologi smr ON smr.kd_jenis_prw = jpr.kd_jenis_prw
            INNER JOIN satu_sehat_servicerequest_radiologi ssr ON ssr.noorder = ppr.noorder AND ssr.kd_jenis_prw = ppr.kd_jenis_prw
            INNER JOIN satu_sehat_specimen_radiologi sssp ON ssr.noorder = sssp.noorder AND ssr.kd_jenis_prw = sssp.kd_jenis_prw
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
              AND sssp.id_specimen IS NOT NULL AND sssp.id_specimen <> '' AND sssp.id_specimen <> '-'
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function saveSpecimenRadiologi(
        string $noorder, 
        string $kdJenisPrw, 
        string $idSpecimen
    ): bool {
        $sql = "INSERT INTO satu_sehat_specimen_radiologi (noorder, kd_jenis_prw, id_specimen) 
                VALUES (:noorder, :kd, :id) 
                ON DUPLICATE KEY UPDATE id_specimen = :id2";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'noorder' => $noorder,
            'kd'      => $kdJenisPrw,
            'id'      => $idSpecimen,
            'id2'     => $idSpecimen
        ]);
    }

    // ─── OBSERVATION RADIOLOGI MYSQL OPERATIONS ─────────────────────────────────

    public function fetchPendingObservationRadiologiActive(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT DISTINCT 
                rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp as nik_pasien, 
                pr.noorder, pr.tgl_hasil, pr.jam_hasil, jpr.nm_perawatan,
                smr.code, smr.system, smr.display, hr.hasil, ppr.kd_jenis_prw,
                sssp.id_specimen, prad.kd_dokter, peg.nama as nm_dokter, peg.no_ktp as nik_praktisi,
                sse.id_encounter, '' as id_observation,
                smr.sampel_code, smr.sampel_system, smr.sampel_display,
                ssi.id_servicerequest, ssi.id_imaging
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN permintaan_radiologi pr ON pr.no_rawat = rp.no_rawat
            INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
            INNER JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = ppr.kd_jenis_prw
            INNER JOIN satu_sehat_mapping_radiologi smr ON smr.kd_jenis_prw = jpr.kd_jenis_prw
            INNER JOIN satu_sehat_specimen_radiologi sssp ON sssp.noorder = ppr.noorder AND sssp.kd_jenis_prw = ppr.kd_jenis_prw
            INNER JOIN periksa_radiologi prad ON prad.no_rawat = pr.no_rawat AND prad.tgl_periksa = pr.tgl_hasil AND prad.jam = pr.jam_hasil AND prad.dokter_perujuk = pr.dokter_perujuk
            INNER JOIN hasil_radiologi hr ON prad.no_rawat = hr.no_rawat AND prad.tgl_periksa = hr.tgl_periksa AND prad.jam = hr.jam
            INNER JOIN satu_sehat_imagingstudy_radiologi ssi ON sssp.noorder = ssi.noorder AND sssp.kd_jenis_prw = ssi.kd_jenis_prw
            LEFT JOIN satu_sehat_observation_radiologi sso ON sssp.noorder = sso.noorder AND sssp.kd_jenis_prw = sso.kd_jenis_prw
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            INNER JOIN pegawai peg ON prad.kd_dokter = peg.nik
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
              AND (sso.id_observation IS NULL OR sso.id_observation = '' OR sso.id_observation = '-')
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function fetchPendingObservationRadiologiUpdate(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT DISTINCT 
                rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp as nik_pasien, 
                pr.noorder, pr.tgl_hasil, pr.jam_hasil, jpr.nm_perawatan,
                smr.code, smr.system, smr.display, hr.hasil, ppr.kd_jenis_prw,
                sssp.id_specimen, prad.kd_dokter, peg.nama as nm_dokter, peg.no_ktp as nik_praktisi,
                sse.id_encounter, sso.id_observation,
                smr.sampel_code, smr.sampel_system, smr.sampel_display,
                ssi.id_servicerequest, ssi.id_imaging
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN permintaan_radiologi pr ON pr.no_rawat = rp.no_rawat
            INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
            INNER JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = ppr.kd_jenis_prw
            INNER JOIN satu_sehat_mapping_radiologi smr ON smr.kd_jenis_prw = jpr.kd_jenis_prw
            INNER JOIN satu_sehat_specimen_radiologi sssp ON sssp.noorder = ppr.noorder AND sssp.kd_jenis_prw = ppr.kd_jenis_prw
            INNER JOIN periksa_radiologi prad ON prad.no_rawat = pr.no_rawat AND prad.tgl_periksa = pr.tgl_hasil AND prad.jam = pr.jam_hasil AND prad.dokter_perujuk = pr.dokter_perujuk
            INNER JOIN hasil_radiologi hr ON prad.no_rawat = hr.no_rawat AND prad.tgl_periksa = hr.tgl_periksa AND prad.jam = hr.jam
            INNER JOIN satu_sehat_imagingstudy_radiologi ssi ON sssp.noorder = ssi.noorder AND sssp.kd_jenis_prw = ssi.kd_jenis_prw
            INNER JOIN satu_sehat_observation_radiologi sso ON sssp.noorder = sso.noorder AND sssp.kd_jenis_prw = sso.kd_jenis_prw
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            INNER JOIN pegawai peg ON prad.kd_dokter = peg.nik
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
              AND sso.id_observation IS NOT NULL AND sso.id_observation <> '' AND sso.id_observation <> '-'
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function saveObservationRadiologi(
        string $noorder, 
        string $kdJenisPrw, 
        string $idObservation
    ): bool {
        $sql = "INSERT INTO satu_sehat_observation_radiologi (noorder, kd_jenis_prw, id_observation) 
                VALUES (:noorder, :kd, :id) 
                ON DUPLICATE KEY UPDATE id_observation = :id2";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'noorder' => $noorder,
            'kd'      => $kdJenisPrw,
            'id'      => $idObservation,
            'id2'     => $idObservation
        ]);
    }

    // ─── SERVICE REQUEST LAB PK MYSQL OPERATIONS ────────────────────────────────

    public function fetchPendingServiceRequestLabPKActive(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT DISTINCT 
                rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp as nik_pasien, 
                rp.kd_dokter, peg.nama as nm_dokter, peg.no_ktp as nik_praktisi,
                rp.tgl_registrasi, rp.jam_reg,
                sse.id_encounter, pl.noorder, pl.tgl_permintaan, pl.jam_permintaan, pl.diagnosa_klinis,
                tl.Pemeriksaan, sml.code, sml.system, sml.display,
                '' as id_servicerequest, pdpl.id_template, pdpl.kd_jenis_prw
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN pegawai peg ON peg.nik = rp.kd_dokter
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            INNER JOIN permintaan_lab pl ON pl.no_rawat = rp.no_rawat
            INNER JOIN permintaan_detail_permintaan_lab pdpl ON pdpl.noorder = pl.noorder
            INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
            INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
            LEFT JOIN satu_sehat_servicerequest_lab sssl ON sssl.noorder = pdpl.noorder
              AND sssl.id_template = pdpl.id_template
              AND sssl.kd_jenis_prw = pdpl.kd_jenis_prw
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
              AND (sssl.id_servicerequest IS NULL OR sssl.id_servicerequest = '' OR sssl.id_servicerequest = '-')
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function fetchPendingServiceRequestLabPKUpdate(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT DISTINCT 
                rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp as nik_pasien, 
                rp.kd_dokter, peg.nama as nm_dokter, peg.no_ktp as nik_praktisi,
                rp.tgl_registrasi, rp.jam_reg,
                sse.id_encounter, pl.noorder, pl.tgl_permintaan, pl.jam_permintaan, pl.diagnosa_klinis,
                tl.Pemeriksaan, sml.code, sml.system, sml.display,
                sssl.id_servicerequest, pdpl.id_template, pdpl.kd_jenis_prw
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN pegawai peg ON peg.nik = rp.kd_dokter
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            INNER JOIN permintaan_lab pl ON pl.no_rawat = rp.no_rawat
            INNER JOIN permintaan_detail_permintaan_lab pdpl ON pdpl.noorder = pl.noorder
            INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
            INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
            INNER JOIN satu_sehat_servicerequest_lab sssl ON sssl.noorder = pdpl.noorder
              AND sssl.id_template = pdpl.id_template
              AND sssl.kd_jenis_prw = pdpl.kd_jenis_prw
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
              AND sssl.id_servicerequest IS NOT NULL AND sssl.id_servicerequest <> '' AND sssl.id_servicerequest <> '-'
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function saveServiceRequestLabPK(
        string $noorder, 
        string $kdJenisPrw, 
        int $idTemplate, 
        string $idServiceRequest
    ): bool {
        $sql = "INSERT INTO satu_sehat_servicerequest_lab (noorder, kd_jenis_prw, id_template, id_servicerequest) 
                VALUES (:noorder, :kd, :id_template, :id) 
                ON DUPLICATE KEY UPDATE id_servicerequest = :id2";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'noorder'     => $noorder,
            'kd'          => $kdJenisPrw,
            'id_template' => $idTemplate,
            'id'          => $idServiceRequest,
            'id2'         => $idServiceRequest
        ]);
    }

    // ─── SERVICE REQUEST LAB MB MYSQL OPERATIONS ────────────────────────────────

    public function fetchPendingServiceRequestLabMBActive(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT DISTINCT 
                rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp as nik_pasien, 
                rp.kd_dokter, peg.nama as nm_dokter, peg.no_ktp as nik_praktisi,
                rp.tgl_registrasi, rp.jam_reg,
                sse.id_encounter, pl.noorder, pl.tgl_permintaan, pl.jam_permintaan, pl.diagnosa_klinis,
                tl.Pemeriksaan, sml.code, sml.system, sml.display,
                '' as id_servicerequest, pdpl.id_template, pdpl.kd_jenis_prw
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN pegawai peg ON peg.nik = rp.kd_dokter
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            INNER JOIN permintaan_labmb pl ON pl.no_rawat = rp.no_rawat
            INNER JOIN permintaan_detail_permintaan_labmb pdpl ON pdpl.noorder = pl.noorder
            INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
            INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
            LEFT JOIN satu_sehat_servicerequest_lab_mb sssl ON sssl.noorder = pdpl.noorder
              AND sssl.id_template = pdpl.id_template
              AND sssl.kd_jenis_prw = pdpl.kd_jenis_prw
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
              AND (sssl.id_servicerequest IS NULL OR sssl.id_servicerequest = '' OR sssl.id_servicerequest = '-')
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function fetchPendingServiceRequestLabMBUpdate(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT DISTINCT 
                rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp as nik_pasien, 
                rp.kd_dokter, peg.nama as nm_dokter, peg.no_ktp as nik_praktisi,
                rp.tgl_registrasi, rp.jam_reg,
                sse.id_encounter, pl.noorder, pl.tgl_permintaan, pl.jam_permintaan, pl.diagnosa_klinis,
                tl.Pemeriksaan, sml.code, sml.system, sml.display,
                sssl.id_servicerequest, pdpl.id_template, pdpl.kd_jenis_prw
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN pegawai peg ON peg.nik = rp.kd_dokter
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            INNER JOIN permintaan_labmb pl ON pl.no_rawat = rp.no_rawat
            INNER JOIN permintaan_detail_permintaan_labmb pdpl ON pdpl.noorder = pl.noorder
            INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
            INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
            INNER JOIN satu_sehat_servicerequest_lab_mb sssl ON sssl.noorder = pdpl.noorder
              AND sssl.id_template = pdpl.id_template
              AND sssl.kd_jenis_prw = pdpl.kd_jenis_prw
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
              AND sssl.id_servicerequest IS NOT NULL AND sssl.id_servicerequest <> '' AND sssl.id_servicerequest <> '-'
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function saveServiceRequestLabMB(
        string $noorder, 
        string $kdJenisPrw, 
        int $idTemplate, 
        string $idServiceRequest
    ): bool {
        $sql = "INSERT INTO satu_sehat_servicerequest_lab_mb (noorder, kd_jenis_prw, id_template, id_servicerequest) 
                VALUES (:noorder, :kd, :id_template, :id) 
                ON DUPLICATE KEY UPDATE id_servicerequest = :id2";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'noorder'     => $noorder,
            'kd'          => $kdJenisPrw,
            'id_template' => $idTemplate,
            'id'          => $idServiceRequest,
            'id2'         => $idServiceRequest
        ]);
    }

    // ─── SPECIMEN LAB PK MYSQL OPERATIONS ────────────────────────────────────────

    public function fetchPendingSpecimenLabPKActive(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT DISTINCT 
                rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp as nik_pasien,
                pl.noorder, pl.tgl_sampel, pl.jam_sampel, tl.Pemeriksaan,
                sml.sampel_code, sml.sampel_system, sml.sampel_display, sssl.id_servicerequest,
                pdpl.id_template, '' as id_specimen, pdpl.kd_jenis_prw,
                rp.tgl_registrasi, rp.jam_reg
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN permintaan_lab pl ON pl.no_rawat = rp.no_rawat
            INNER JOIN permintaan_detail_permintaan_lab pdpl ON pdpl.noorder = pl.noorder
            INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
            INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
            INNER JOIN satu_sehat_servicerequest_lab sssl ON sssl.noorder = pdpl.noorder
              AND sssl.id_template = pdpl.id_template
              AND sssl.kd_jenis_prw = pdpl.kd_jenis_prw
            LEFT JOIN satu_sehat_specimen_lab sssp ON sssp.noorder = pdpl.noorder
              AND sssp.id_template = pdpl.id_template
              AND sssp.kd_jenis_prw = pdpl.kd_jenis_prw
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
              AND sssl.id_servicerequest IS NOT NULL AND sssl.id_servicerequest <> '' AND sssl.id_servicerequest <> '-'
              AND (sssp.id_specimen IS NULL OR sssp.id_specimen = '' OR sssp.id_specimen = '-')
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function fetchPendingSpecimenLabPKUpdate(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT DISTINCT 
                rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp as nik_pasien,
                pl.noorder, pl.tgl_sampel, pl.jam_sampel, tl.Pemeriksaan,
                sml.sampel_code, sml.sampel_system, sml.sampel_display, sssl.id_servicerequest,
                pdpl.id_template, sssp.id_specimen, pdpl.kd_jenis_prw,
                rp.tgl_registrasi, rp.jam_reg
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN permintaan_lab pl ON pl.no_rawat = rp.no_rawat
            INNER JOIN permintaan_detail_permintaan_lab pdpl ON pdpl.noorder = pl.noorder
            INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
            INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
            INNER JOIN satu_sehat_servicerequest_lab sssl ON sssl.noorder = pdpl.noorder
              AND sssl.id_template = pdpl.id_template
              AND sssl.kd_jenis_prw = pdpl.kd_jenis_prw
            INNER JOIN satu_sehat_specimen_lab sssp ON sssp.noorder = pdpl.noorder
              AND sssp.id_template = pdpl.id_template
              AND sssp.kd_jenis_prw = pdpl.kd_jenis_prw
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
              AND sssl.id_servicerequest IS NOT NULL AND sssl.id_servicerequest <> '' AND sssl.id_servicerequest <> '-'
              AND sssp.id_specimen IS NOT NULL AND sssp.id_specimen <> '' AND sssp.id_specimen <> '-'
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function saveSpecimenLabPK(
        string $noorder, 
        string $kdJenisPrw, 
        int $idTemplate, 
        string $idSpecimen
    ): bool {
        $sql = "INSERT INTO satu_sehat_specimen_lab (noorder, kd_jenis_prw, id_template, id_specimen) 
                VALUES (:noorder, :kd, :id_template, :id) 
                ON DUPLICATE KEY UPDATE id_specimen = :id2";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'noorder'     => $noorder,
            'kd'          => $kdJenisPrw,
            'id_template' => $idTemplate,
            'id'          => $idSpecimen,
            'id2'         => $idSpecimen
        ]);
    }

    // ─── SPECIMEN LAB MB MYSQL OPERATIONS ────────────────────────────────────────

    public function fetchPendingSpecimenLabMBActive(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT DISTINCT 
                rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp as nik_pasien,
                pl.noorder, pl.tgl_sampel, pl.jam_sampel, tl.Pemeriksaan,
                sml.sampel_code, sml.sampel_system, sml.sampel_display, sssl.id_servicerequest,
                pdpl.id_template, '' as id_specimen, pdpl.kd_jenis_prw,
                rp.tgl_registrasi, rp.jam_reg
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN permintaan_labmb pl ON pl.no_rawat = rp.no_rawat
            INNER JOIN permintaan_detail_permintaan_labmb pdpl ON pdpl.noorder = pl.noorder
            INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
            INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
            INNER JOIN satu_sehat_servicerequest_lab_mb sssl ON sssl.noorder = pdpl.noorder
              AND sssl.id_template = pdpl.id_template
              AND sssl.kd_jenis_prw = pdpl.kd_jenis_prw
            LEFT JOIN satu_sehat_specimen_lab_mb sssp ON sssp.noorder = pdpl.noorder
              AND sssp.id_template = pdpl.id_template
              AND sssp.kd_jenis_prw = pdpl.kd_jenis_prw
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
              AND sssl.id_servicerequest IS NOT NULL AND sssl.id_servicerequest <> '' AND sssl.id_servicerequest <> '-'
              AND (sssp.id_specimen IS NULL OR sssp.id_specimen = '' OR sssp.id_specimen = '-')
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function fetchPendingSpecimenLabMBUpdate(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT DISTINCT 
                rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp as nik_pasien,
                pl.noorder, pl.tgl_sampel, pl.jam_sampel, tl.Pemeriksaan,
                sml.sampel_code, sml.sampel_system, sml.sampel_display, sssl.id_servicerequest,
                pdpl.id_template, sssp.id_specimen, pdpl.kd_jenis_prw,
                rp.tgl_registrasi, rp.jam_reg
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN permintaan_labmb pl ON pl.no_rawat = rp.no_rawat
            INNER JOIN permintaan_detail_permintaan_labmb pdpl ON pdpl.noorder = pl.noorder
            INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
            INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
            INNER JOIN satu_sehat_servicerequest_lab_mb sssl ON sssl.noorder = pdpl.noorder
              AND sssl.id_template = pdpl.id_template
              AND sssl.kd_jenis_prw = pdpl.kd_jenis_prw
            INNER JOIN satu_sehat_specimen_lab_mb sssp ON sssp.noorder = pdpl.noorder
              AND sssp.id_template = pdpl.id_template
              AND sssp.kd_jenis_prw = pdpl.kd_jenis_prw
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
              AND sssl.id_servicerequest IS NOT NULL AND sssl.id_servicerequest <> '' AND sssl.id_servicerequest <> '-'
              AND sssp.id_specimen IS NOT NULL AND sssp.id_specimen <> '' AND sssp.id_specimen <> '-'
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function saveSpecimenLabMB(
        string $noorder, 
        string $kdJenisPrw, 
        int $idTemplate, 
        string $idSpecimen
    ): bool {
        $sql = "INSERT INTO satu_sehat_specimen_lab_mb (noorder, kd_jenis_prw, id_template, id_specimen) 
                VALUES (:noorder, :kd, :id_template, :id) 
                ON DUPLICATE KEY UPDATE id_specimen = :id2";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'noorder'     => $noorder,
            'kd'          => $kdJenisPrw,
            'id_template' => $idTemplate,
            'id'          => $idSpecimen,
            'id2'         => $idSpecimen
        ]);
    }

    public function fetchPendingObservationLabPKActive(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT DISTINCT 
                rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp as nik_pasien,
                pl.noorder, pl.tgl_hasil, pl.jam_hasil, tl.Pemeriksaan,
                sml.code, sml.system, sml.display,
                dpl.nilai, dpl.nilai_rujukan, dpl.keterangan, tl.satuan,
                pdpl.id_template, sssp.id_specimen, pdpl.kd_jenis_prw,
                per.kd_dokter, peg.nama as nama_dokter, peg.no_ktp as nik_dokter,
                sse.id_encounter, '' as id_observation
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN permintaan_lab pl ON pl.no_rawat = rp.no_rawat
            INNER JOIN permintaan_detail_permintaan_lab pdpl ON pdpl.noorder = pl.noorder
            INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
            INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
            INNER JOIN satu_sehat_specimen_lab sssp ON sssp.noorder = pdpl.noorder
              AND sssp.id_template = pdpl.id_template
              AND sssp.kd_jenis_prw = pdpl.kd_jenis_prw
            INNER JOIN periksa_lab per ON per.no_rawat = pl.no_rawat
              AND per.tgl_periksa = pl.tgl_hasil
              AND per.jam = pl.jam_hasil
              AND per.dokter_perujuk = pl.dokter_perujuk
            INNER JOIN detail_periksa_lab dpl ON dpl.no_rawat = per.no_rawat
              AND dpl.tgl_periksa = per.tgl_periksa
              AND dpl.jam = per.jam
              AND dpl.id_template = pdpl.id_template
              AND dpl.kd_jenis_prw = pdpl.kd_jenis_prw
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            INNER JOIN pegawai peg ON per.kd_dokter = peg.nik
            LEFT JOIN satu_sehat_observation_lab sso ON sso.noorder = pdpl.noorder
              AND sso.id_template = pdpl.id_template
              AND sso.kd_jenis_prw = pdpl.kd_jenis_prw
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
              AND sssp.id_specimen IS NOT NULL AND sssp.id_specimen <> '' AND sssp.id_specimen <> '-'
              AND (sso.id_observation IS NULL OR sso.id_observation = '' OR sso.id_observation = '-')
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function fetchPendingObservationLabPKUpdate(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT DISTINCT 
                rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp as nik_pasien,
                pl.noorder, pl.tgl_hasil, pl.jam_hasil, tl.Pemeriksaan,
                sml.code, sml.system, sml.display,
                dpl.nilai, dpl.nilai_rujukan, dpl.keterangan, tl.satuan,
                pdpl.id_template, sssp.id_specimen, pdpl.kd_jenis_prw,
                per.kd_dokter, peg.nama as nama_dokter, peg.no_ktp as nik_dokter,
                sse.id_encounter, sso.id_observation
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN permintaan_lab pl ON pl.no_rawat = rp.no_rawat
            INNER JOIN permintaan_detail_permintaan_lab pdpl ON pdpl.noorder = pl.noorder
            INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
            INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
            INNER JOIN satu_sehat_specimen_lab sssp ON sssp.noorder = pdpl.noorder
              AND sssp.id_template = pdpl.id_template
              AND sssp.kd_jenis_prw = pdpl.kd_jenis_prw
            INNER JOIN periksa_lab per ON per.no_rawat = pl.no_rawat
              AND per.tgl_periksa = pl.tgl_hasil
              AND per.jam = pl.jam_hasil
              AND per.dokter_perujuk = pl.dokter_perujuk
            INNER JOIN detail_periksa_lab dpl ON dpl.no_rawat = per.no_rawat
              AND dpl.tgl_periksa = per.tgl_periksa
              AND dpl.jam = per.jam
              AND dpl.id_template = pdpl.id_template
              AND dpl.kd_jenis_prw = pdpl.kd_jenis_prw
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            INNER JOIN pegawai peg ON per.kd_dokter = peg.nik
            INNER JOIN satu_sehat_observation_lab sso ON sso.noorder = pdpl.noorder
              AND sso.id_template = pdpl.id_template
              AND sso.kd_jenis_prw = pdpl.kd_jenis_prw
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
              AND sssp.id_specimen IS NOT NULL AND sssp.id_specimen <> '' AND sssp.id_specimen <> '-'
              AND sso.id_observation IS NOT NULL AND sso.id_observation <> '' AND sso.id_observation <> '-'
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function saveObservationLabPK(
        string $noorder, 
        string $kdJenisPrw, 
        int $idTemplate, 
        string $idObservation
    ): bool {
        $sql = "INSERT INTO satu_sehat_observation_lab (noorder, kd_jenis_prw, id_template, id_observation) 
                VALUES (:noorder, :kd, :id_template, :id) 
                ON DUPLICATE KEY UPDATE id_observation = :id2";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'noorder'     => $noorder,
            'kd'          => $kdJenisPrw,
            'id_template' => $idTemplate,
            'id'          => $idObservation,
            'id2'         => $idObservation
        ]);
    }

    public function fetchPendingObservationLabMBActive(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT DISTINCT 
                rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp as nik_pasien,
                pl.noorder, pl.tgl_hasil, pl.jam_hasil, tl.Pemeriksaan,
                sml.code, sml.system, sml.display,
                dpl.nilai, dpl.nilai_rujukan, dpl.keterangan, tl.satuan,
                pdpl.id_template, sssp.id_specimen, pdpl.kd_jenis_prw,
                per.kd_dokter, peg.nama as nama_dokter, peg.no_ktp as nik_dokter,
                sse.id_encounter, '' as id_observation
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN permintaan_labmb pl ON pl.no_rawat = rp.no_rawat
            INNER JOIN permintaan_detail_permintaan_labmb pdpl ON pdpl.noorder = pl.noorder
            INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
            INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
            INNER JOIN satu_sehat_specimen_lab_mb sssp ON sssp.noorder = pdpl.noorder
              AND sssp.id_template = pdpl.id_template
              AND sssp.kd_jenis_prw = pdpl.kd_jenis_prw
            INNER JOIN periksa_lab per ON per.no_rawat = pl.no_rawat
              AND per.tgl_periksa = pl.tgl_hasil
              AND per.jam = pl.jam_hasil
              AND per.dokter_perujuk = pl.dokter_perujuk
            INNER JOIN detail_periksa_lab dpl ON dpl.no_rawat = per.no_rawat
              AND dpl.tgl_periksa = per.tgl_periksa
              AND dpl.jam = per.jam
              AND dpl.id_template = pdpl.id_template
              AND dpl.kd_jenis_prw = pdpl.kd_jenis_prw
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            INNER JOIN pegawai peg ON per.kd_dokter = peg.nik
            LEFT JOIN satu_sehat_observation_lab_mb sso ON sso.noorder = pdpl.noorder
              AND sso.id_template = pdpl.id_template
              AND sso.kd_jenis_prw = pdpl.kd_jenis_prw
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
              AND sssp.id_specimen IS NOT NULL AND sssp.id_specimen <> '' AND sssp.id_specimen <> '-'
              AND (sso.id_observation IS NULL OR sso.id_observation = '' OR sso.id_observation = '-')
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function fetchPendingObservationLabMBUpdate(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT DISTINCT 
                rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp as nik_pasien,
                pl.noorder, pl.tgl_hasil, pl.jam_hasil, tl.Pemeriksaan,
                sml.code, sml.system, sml.display,
                dpl.nilai, dpl.nilai_rujukan, dpl.keterangan, tl.satuan,
                pdpl.id_template, sssp.id_specimen, pdpl.kd_jenis_prw,
                per.kd_dokter, peg.nama as nama_dokter, peg.no_ktp as nik_dokter,
                sse.id_encounter, sso.id_observation
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN permintaan_labmb pl ON pl.no_rawat = rp.no_rawat
            INNER JOIN permintaan_detail_permintaan_labmb pdpl ON pdpl.noorder = pl.noorder
            INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
            INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
            INNER JOIN satu_sehat_specimen_lab_mb sssp ON sssp.noorder = pdpl.noorder
              AND sssp.id_template = pdpl.id_template
              AND sssp.kd_jenis_prw = pdpl.kd_jenis_prw
            INNER JOIN periksa_lab per ON per.no_rawat = pl.no_rawat
              AND per.tgl_periksa = pl.tgl_hasil
              AND per.jam = pl.jam_hasil
              AND per.dokter_perujuk = pl.dokter_perujuk
            INNER JOIN detail_periksa_lab dpl ON dpl.no_rawat = per.no_rawat
              AND dpl.tgl_periksa = per.tgl_periksa
              AND dpl.jam = per.jam
              AND dpl.id_template = pdpl.id_template
              AND dpl.kd_jenis_prw = pdpl.kd_jenis_prw
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            INNER JOIN pegawai peg ON per.kd_dokter = peg.nik
            INNER JOIN satu_sehat_observation_lab_mb sso ON sso.noorder = pdpl.noorder
              AND sso.id_template = pdpl.id_template
              AND sso.kd_jenis_prw = pdpl.kd_jenis_prw
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
              AND sssp.id_specimen IS NOT NULL AND sssp.id_specimen <> '' AND sssp.id_specimen <> '-'
              AND sso.id_observation IS NOT NULL AND sso.id_observation <> '' AND sso.id_observation <> '-'
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function saveObservationLabMB(
        string $noorder, 
        string $kdJenisPrw, 
        int $idTemplate, 
        string $idObservation
    ): bool {
        $sql = "INSERT INTO satu_sehat_observation_lab_mb (noorder, kd_jenis_prw, id_template, id_observation) 
                VALUES (:noorder, :kd, :id_template, :id) 
                ON DUPLICATE KEY UPDATE id_observation = :id2";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'noorder'     => $noorder,
            'kd'          => $kdJenisPrw,
            'id_template' => $idTemplate,
            'id'          => $idObservation,
            'id2'         => $idObservation
        ]);
    }









    public function fetchPendingDiagnosticReportLabPKActive(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT DISTINCT 
            rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp as nik_pasien,
            per.kd_dokter, peg.nama as nama_dokter, peg.no_ktp as nik_dokter,
            sse.id_encounter, pl.noorder, pl.tgl_hasil, pl.jam_hasil, pl.diagnosa_klinis,
            tl.Pemeriksaan, sml.code, sml.system, sml.display,
            sssr.id_servicerequest, pdpl.id_template, sssp.id_specimen,
            sso.id_observation, '' as id_diagnosticreport, skl.kesan,
            pdpl.kd_jenis_prw
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            INNER JOIN permintaan_lab pl ON pl.no_rawat = rp.no_rawat
            INNER JOIN permintaan_detail_permintaan_lab pdpl ON pdpl.noorder = pl.noorder
            INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
            INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
            INNER JOIN satu_sehat_servicerequest_lab sssr ON sssr.noorder = pdpl.noorder
              AND sssr.id_template = pdpl.id_template
              AND sssr.kd_jenis_prw = pdpl.kd_jenis_prw
            INNER JOIN satu_sehat_specimen_lab sssp ON sssr.noorder = sssp.noorder
              AND sssr.id_template = sssp.id_template
              AND sssr.kd_jenis_prw = sssp.kd_jenis_prw
            INNER JOIN periksa_lab per ON per.no_rawat = pl.no_rawat
              AND per.tgl_periksa = pl.tgl_hasil
              AND per.jam = pl.jam_hasil
              AND per.dokter_perujuk = pl.dokter_perujuk
            LEFT JOIN saran_kesan_lab skl ON per.no_rawat = skl.no_rawat
              AND per.tgl_periksa = skl.tgl_periksa
              AND per.jam = skl.jam
            INNER JOIN satu_sehat_observation_lab sso ON sssp.noorder = sso.noorder
              AND sssp.id_template = sso.id_template
              AND sssp.kd_jenis_prw = sso.kd_jenis_prw
            LEFT JOIN satu_sehat_diagnosticreport_lab ssdr ON sssr.noorder = ssdr.noorder
              AND sssr.id_template = ssdr.id_template
              AND sssr.kd_jenis_prw = ssdr.kd_jenis_prw
            INNER JOIN pegawai peg ON per.kd_dokter = peg.nik
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
              AND sssr.id_servicerequest IS NOT NULL AND sssr.id_servicerequest <> '' AND sssr.id_servicerequest <> '-'
              AND sssp.id_specimen IS NOT NULL AND sssp.id_specimen <> '' AND sssp.id_specimen <> '-'
              AND sso.id_observation IS NOT NULL AND sso.id_observation <> '' AND sso.id_observation <> '-'
              AND (ssdr.id_diagnosticreport IS NULL OR ssdr.id_diagnosticreport = '' OR ssdr.id_diagnosticreport = '-')
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function fetchPendingDiagnosticReportLabPKUpdate(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT DISTINCT 
            rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp as nik_pasien,
            per.kd_dokter, peg.nama as nama_dokter, peg.no_ktp as nik_dokter,
            sse.id_encounter, pl.noorder, pl.tgl_hasil, pl.jam_hasil, pl.diagnosa_klinis,
            tl.Pemeriksaan, sml.code, sml.system, sml.display,
            sssr.id_servicerequest, pdpl.id_template, sssp.id_specimen,
            sso.id_observation, ssdr.id_diagnosticreport, skl.kesan,
            pdpl.kd_jenis_prw
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            INNER JOIN permintaan_lab pl ON pl.no_rawat = rp.no_rawat
            INNER JOIN permintaan_detail_permintaan_lab pdpl ON pdpl.noorder = pl.noorder
            INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
            INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
            INNER JOIN satu_sehat_servicerequest_lab sssr ON sssr.noorder = pdpl.noorder
              AND sssr.id_template = pdpl.id_template
              AND sssr.kd_jenis_prw = pdpl.kd_jenis_prw
            INNER JOIN satu_sehat_specimen_lab sssp ON sssr.noorder = sssp.noorder
              AND sssr.id_template = sssp.id_template
              AND sssr.kd_jenis_prw = sssp.kd_jenis_prw
            INNER JOIN periksa_lab per ON per.no_rawat = pl.no_rawat
              AND per.tgl_periksa = pl.tgl_hasil
              AND per.jam = pl.jam_hasil
              AND per.dokter_perujuk = pl.dokter_perujuk
            LEFT JOIN saran_kesan_lab skl ON per.no_rawat = skl.no_rawat
              AND per.tgl_periksa = skl.tgl_periksa
              AND per.jam = skl.jam
            INNER JOIN satu_sehat_observation_lab sso ON sssp.noorder = sso.noorder
              AND sssp.id_template = sso.id_template
              AND sssp.kd_jenis_prw = sso.kd_jenis_prw
            INNER JOIN satu_sehat_diagnosticreport_lab ssdr ON sssr.noorder = ssdr.noorder
              AND sssr.id_template = ssdr.id_template
              AND sssr.kd_jenis_prw = ssdr.kd_jenis_prw
            INNER JOIN pegawai peg ON per.kd_dokter = peg.nik
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
              AND sssr.id_servicerequest IS NOT NULL AND sssr.id_servicerequest <> '' AND sssr.id_servicerequest <> '-'
              AND sssp.id_specimen IS NOT NULL AND sssp.id_specimen <> '' AND sssp.id_specimen <> '-'
              AND sso.id_observation IS NOT NULL AND sso.id_observation <> '' AND sso.id_observation <> '-'
              AND ssdr.id_diagnosticreport IS NOT NULL AND ssdr.id_diagnosticreport <> '' AND ssdr.id_diagnosticreport <> '-'
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function saveDiagnosticReportLabPK(
        string $noorder, 
        string $kdJenisPrw, 
        int $idTemplate, 
        string $idDiagnosticReport
    ): bool {
        $sql = "INSERT INTO satu_sehat_diagnosticreport_lab (noorder, kd_jenis_prw, id_template, id_diagnosticreport) 
                VALUES (:noorder, :kd, :id_template, :id) 
                ON DUPLICATE KEY UPDATE id_diagnosticreport = :id2";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'noorder'     => $noorder,
            'kd'          => $kdJenisPrw,
            'id_template' => $idTemplate,
            'id'          => $idDiagnosticReport,
            'id2'         => $idDiagnosticReport
        ]);
    }

    public function fetchPendingDiagnosticReportLabMBActive(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT DISTINCT 
            rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp as nik_pasien,
            per.kd_dokter, peg.nama as nama_dokter, peg.no_ktp as nik_dokter,
            sse.id_encounter, pl.noorder, pl.tgl_hasil, pl.jam_hasil, pl.diagnosa_klinis,
            tl.Pemeriksaan, sml.code, sml.system, sml.display,
            sssr.id_servicerequest, pdpl.id_template, sssp.id_specimen,
            sso.id_observation, '' as id_diagnosticreport, skl.kesan,
            pdpl.kd_jenis_prw
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            INNER JOIN permintaan_labmb pl ON pl.no_rawat = rp.no_rawat
            INNER JOIN permintaan_detail_permintaan_labmb pdpl ON pdpl.noorder = pl.noorder
            INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
            INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
            INNER JOIN satu_sehat_servicerequest_lab_mb sssr ON sssr.noorder = pdpl.noorder
              AND sssr.id_template = pdpl.id_template
              AND sssr.kd_jenis_prw = pdpl.kd_jenis_prw
            INNER JOIN satu_sehat_specimen_lab_mb sssp ON sssr.noorder = sssp.noorder
              AND sssr.id_template = sssp.id_template
              AND sssr.kd_jenis_prw = sssp.kd_jenis_prw
            INNER JOIN periksa_lab per ON per.no_rawat = pl.no_rawat
              AND per.tgl_periksa = pl.tgl_hasil
              AND per.jam = pl.jam_hasil
              AND per.dokter_perujuk = pl.dokter_perujuk
            LEFT JOIN saran_kesan_lab skl ON per.no_rawat = skl.no_rawat
              AND per.tgl_periksa = skl.tgl_periksa
              AND per.jam = skl.jam
            INNER JOIN satu_sehat_observation_lab_mb sso ON sssp.noorder = sso.noorder
              AND sssp.id_template = sso.id_template
              AND sssp.kd_jenis_prw = sso.kd_jenis_prw
            LEFT JOIN satu_sehat_diagnosticreport_lab_mb ssdr ON sssr.noorder = ssdr.noorder
              AND sssr.id_template = ssdr.id_template
              AND sssr.kd_jenis_prw = ssdr.kd_jenis_prw
            INNER JOIN pegawai peg ON per.kd_dokter = peg.nik
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
              AND sssr.id_servicerequest IS NOT NULL AND sssr.id_servicerequest <> '' AND sssr.id_servicerequest <> '-'
              AND sssp.id_specimen IS NOT NULL AND sssp.id_specimen <> '' AND sssp.id_specimen <> '-'
              AND sso.id_observation IS NOT NULL AND sso.id_observation <> '' AND sso.id_observation <> '-'
              AND (ssdr.id_diagnosticreport IS NULL OR ssdr.id_diagnosticreport = '' OR ssdr.id_diagnosticreport = '-')
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function fetchPendingDiagnosticReportLabMBUpdate(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT DISTINCT 
            rp.no_rawat, rp.no_rkm_medis, p.nm_pasien, p.no_ktp as nik_pasien,
            per.kd_dokter, peg.nama as nama_dokter, peg.no_ktp as nik_dokter,
            sse.id_encounter, pl.noorder, pl.tgl_hasil, pl.jam_hasil, pl.diagnosa_klinis,
            tl.Pemeriksaan, sml.code, sml.system, sml.display,
            sssr.id_servicerequest, pdpl.id_template, sssp.id_specimen,
            sso.id_observation, ssdr.id_diagnosticreport, skl.kesan,
            pdpl.kd_jenis_prw
            FROM reg_periksa rp
            INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            INNER JOIN permintaan_labmb pl ON pl.no_rawat = rp.no_rawat
            INNER JOIN permintaan_detail_permintaan_labmb pdpl ON pdpl.noorder = pl.noorder
            INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
            INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
            INNER JOIN satu_sehat_servicerequest_lab_mb sssr ON sssr.noorder = pdpl.noorder
              AND sssr.id_template = pdpl.id_template
              AND sssr.kd_jenis_prw = pdpl.kd_jenis_prw
            INNER JOIN satu_sehat_specimen_lab_mb sssp ON sssr.noorder = sssp.noorder
              AND sssr.id_template = sssp.id_template
              AND sssr.kd_jenis_prw = sssp.kd_jenis_prw
            INNER JOIN periksa_lab per ON per.no_rawat = pl.no_rawat
              AND per.tgl_periksa = pl.tgl_hasil
              AND per.jam = pl.jam_hasil
              AND per.dokter_perujuk = pl.dokter_perujuk
            LEFT JOIN saran_kesan_lab skl ON per.no_rawat = skl.no_rawat
              AND per.tgl_periksa = skl.tgl_periksa
              AND per.jam = skl.jam
            INNER JOIN satu_sehat_observation_lab_mb sso ON sssp.noorder = sso.noorder
              AND sssp.id_template = sso.id_template
              AND sssp.kd_jenis_prw = sso.kd_jenis_prw
            INNER JOIN satu_sehat_diagnosticreport_lab_mb ssdr ON sssr.noorder = ssdr.noorder
              AND sssr.id_template = ssdr.id_template
              AND sssr.kd_jenis_prw = ssdr.kd_jenis_prw
            INNER JOIN pegawai peg ON per.kd_dokter = peg.nik
            WHERE rp.tgl_registrasi BETWEEN :df AND :dt
              AND sssr.id_servicerequest IS NOT NULL AND sssr.id_servicerequest <> '' AND sssr.id_servicerequest <> '-'
              AND sssp.id_specimen IS NOT NULL AND sssp.id_specimen <> '' AND sssp.id_specimen <> '-'
              AND sso.id_observation IS NOT NULL AND sso.id_observation <> '' AND sso.id_observation <> '-'
              AND ssdr.id_diagnosticreport IS NOT NULL AND ssdr.id_diagnosticreport <> '' AND ssdr.id_diagnosticreport <> '-'
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function saveDiagnosticReportLabMB(
        string $noorder, 
        string $kdJenisPrw, 
        int $idTemplate, 
        string $idDiagnosticReport
    ): bool {
        $sql = "INSERT INTO satu_sehat_diagnosticreport_lab_mb (noorder, kd_jenis_prw, id_template, id_diagnosticreport) 
                VALUES (:noorder, :kd, :id_template, :id) 
                ON DUPLICATE KEY UPDATE id_diagnosticreport = :id2";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'noorder'     => $noorder,
            'kd'          => $kdJenisPrw,
            'id_template' => $idTemplate,
            'id'          => $idDiagnosticReport,
            'id2'         => $idDiagnosticReport
        ]);
    }

    // ─── QUESTIONNAIRE RESPONSE STATE TRACKING ───────────────────────────────────

    public function getQuestionnaireResponseLocalState(string $noResep): ?string
    {
        $stmt = $this->sqlite->prepare("SELECT status FROM questionnaireresponse_state WHERE no_resep = :nr");
        $stmt->execute(['nr' => $noResep]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public function updateQuestionnaireResponseLocalState(string $noResep, string $status): void
    {
        $stmt = $this->sqlite->prepare("
            INSERT INTO questionnaireresponse_state (no_resep, status, updated_at) 
            VALUES (:nr, :st, CURRENT_TIMESTAMP)
            ON CONFLICT(no_resep) DO UPDATE SET status = excluded.status, updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute(['nr' => $noResep, 'st' => $status]);
    }

    // ─── QUESTIONNAIRE RESPONSE MYSQL OPERATIONS ───────────────────────────────

    public function fetchPendingQuestionnaireResponseActive(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT 
                rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, pasien.nm_pasien, pasien.no_ktp,
                pegawai.nama, pegawai.no_ktp as ktppraktisi, sse.id_encounter, resep_obat.tgl_peresepan, resep_obat.jam_peresepan,
                resep_obat.no_resep, tf.resep_identifikasi_pasien, tf.resep_ket_identifikasi_pasien,
                tf.resep_tepat_obat, tf.resep_ket_tepat_obat, tf.resep_tepat_dosis, tf.resep_ket_tepat_dosis,
                tf.resep_tepat_cara_pemberian, tf.resep_ket_tepat_cara_pemberian, tf.resep_tepat_waktu_pemberian,
                tf.resep_ket_tepat_waktu_pemberian, tf.resep_ada_tidak_duplikasi_obat, tf.resep_ket_ada_tidak_duplikasi_obat,
                tf.resep_interaksi_obat, tf.resep_ket_interaksi_obat, tf.resep_kontra_indikasi_obat, tf.resep_ket_kontra_indikasi_obat,
                tf.obat_tepat_pasien, tf.obat_tepat_obat, tf.obat_tepat_dosis, tf.obat_tepat_cara_pemberian, tf.obat_tepat_waktu_pemberian 
            FROM reg_periksa rp
            INNER JOIN pasien ON rp.no_rkm_medis = pasien.no_rkm_medis 
            INNER JOIN resep_obat ON rp.no_rawat = resep_obat.no_rawat 
            INNER JOIN telaah_farmasi tf ON tf.no_resep = resep_obat.no_resep 
            INNER JOIN pegawai ON tf.nip = pegawai.nik 
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat 
            LEFT JOIN satu_sehat_questionresponse_telaah_farmasi ssqr ON ssqr.no_resep = resep_obat.no_resep 
            WHERE resep_obat.tgl_peresepan BETWEEN :df AND :dt
              AND (ssqr.id_questionresponse IS NULL OR ssqr.id_questionresponse = '')
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchPendingQuestionnaireResponseUpdate(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT 
                rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, pasien.nm_pasien, pasien.no_ktp,
                pegawai.nama, pegawai.no_ktp as ktppraktisi, sse.id_encounter, resep_obat.tgl_peresepan, resep_obat.jam_peresepan,
                resep_obat.no_resep, ssqr.id_questionresponse, tf.resep_identifikasi_pasien, tf.resep_ket_identifikasi_pasien,
                tf.resep_tepat_obat, tf.resep_ket_tepat_obat, tf.resep_tepat_dosis, tf.resep_ket_tepat_dosis,
                tf.resep_tepat_cara_pemberian, tf.resep_ket_tepat_cara_pemberian, tf.resep_tepat_waktu_pemberian,
                tf.resep_ket_tepat_waktu_pemberian, tf.resep_ada_tidak_duplikasi_obat, tf.resep_ket_ada_tidak_duplikasi_obat,
                tf.resep_interaksi_obat, tf.resep_ket_interaksi_obat, tf.resep_kontra_indikasi_obat, tf.resep_ket_kontra_indikasi_obat,
                tf.obat_tepat_pasien, tf.obat_tepat_obat, tf.obat_tepat_dosis, tf.obat_tepat_cara_pemberian, tf.obat_tepat_waktu_pemberian 
            FROM reg_periksa rp
            INNER JOIN pasien ON rp.no_rkm_medis = pasien.no_rkm_medis 
            INNER JOIN resep_obat ON rp.no_rawat = resep_obat.no_rawat 
            INNER JOIN telaah_farmasi tf ON tf.no_resep = resep_obat.no_resep 
            INNER JOIN pegawai ON tf.nip = pegawai.nik 
            INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat 
            INNER JOIN satu_sehat_questionresponse_telaah_farmasi ssqr ON ssqr.no_resep = resep_obat.no_resep 
            WHERE resep_obat.tgl_peresepan BETWEEN :df AND :dt
              AND ssqr.id_questionresponse IS NOT NULL AND ssqr.id_questionresponse <> ''
        ";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveQuestionnaireResponse(string $noResep, string $idQuestionResponse): bool
    {
        $sql = "INSERT INTO satu_sehat_questionresponse_telaah_farmasi (no_resep, id_questionresponse) 
                VALUES (:nr, :id) 
                ON DUPLICATE KEY UPDATE id_questionresponse = :id2";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'nr'  => $noResep,
            'id'  => $idQuestionResponse,
            'id2' => $idQuestionResponse
        ]);
    }

    public function getSavedQuestionnaireResponseId(string $noResep): ?string
    {
        $sql = "SELECT id_questionresponse FROM satu_sehat_questionresponse_telaah_farmasi WHERE no_resep = :nr LIMIT 1";
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['nr' => $noResep]);
        $val = $stmt->fetchColumn();
        return $val ?: null;
    }

    public function printSyncDiagnostics(string $resourceType, string $dateFrom, string $dateTo): void
    {
        $this->log->info("🔍 [DIAGNOSTICS] Calculating synchronization metrics...");
        $df = $dateFrom;
        $dt = $dateTo;

        try {
            switch (strtolower($resourceType)) {
                case 'encounter':
                    $stmtTotal = $this->mysql->prepare("SELECT COUNT(*) FROM reg_periksa WHERE tgl_registrasi BETWEEN :df AND :dt");
                    $stmtTotal->execute(['df' => $df, 'dt' => $dt]);
                    $total = (int) $stmtTotal->fetchColumn();

                    $stmtUnpaid = $this->mysql->prepare("SELECT COUNT(*) FROM reg_periksa WHERE tgl_registrasi BETWEEN :df AND :dt AND status_bayar = 'Belum Bayar'");
                    $stmtUnpaid->execute(['df' => $df, 'dt' => $dt]);
                    $unpaid = (int) $stmtUnpaid->fetchColumn();

                    $stmtUnmapped = $this->mysql->prepare("
                        SELECT COUNT(*) FROM reg_periksa rp
                        INNER JOIN poliklinik pol ON rp.kd_poli = pol.kd_poli
                        LEFT JOIN satu_sehat_mapping_lokasi_ralan smlr ON smlr.kd_poli = pol.kd_poli
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt 
                          AND rp.status_bayar = 'Sudah Bayar'
                          AND smlr.id_lokasi_satusehat IS NULL
                    ");
                    $stmtUnmapped->execute(['df' => $df, 'dt' => $dt]);
                    $unmapped = (int) $stmtUnmapped->fetchColumn();

                    $stmtSynced = $this->mysql->prepare("
                        SELECT COUNT(*) FROM reg_periksa rp
                        INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                    ");
                    $stmtSynced->execute(['df' => $df, 'dt' => $dt]);
                    $synced = (int) $stmtSynced->fetchColumn();

                    $pending = $total - $unpaid - $unmapped - $synced;
                    if ($pending < 0) $pending = 0;

                    $this->log->info("   ├─ Total Patient Registrations in SIMRS : {$total}");
                    $this->log->info("   ├─ Unpaid (Filtered Out)               : {$unpaid}");
                    $this->log->info("   ├─ Unmapped Clinics (Filtered Out)     : {$unmapped}");
                    $this->log->info("   ├─ Already Synced to Satu Sehat        : {$synced}");
                    $this->log->info("   └─ Pending / Ready to Sync             : {$pending}");
                    break;

                case 'episode_of_care':
                    $stmtTotal = $this->mysql->prepare("SELECT COUNT(*) FROM diagnosa_pasien dp INNER JOIN reg_periksa rp ON dp.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt");
                    $stmtTotal->execute(['df' => $df, 'dt' => $dt]);
                    $total = (int) $stmtTotal->fetchColumn();

                    $stmtNoEnc = $this->mysql->prepare("
                        SELECT COUNT(*) FROM diagnosa_pasien dp
                        INNER JOIN reg_periksa rp ON dp.no_rawat = rp.no_rawat
                        LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt AND sse.id_encounter IS NULL
                    ");
                    $stmtNoEnc->execute(['df' => $df, 'dt' => $dt]);
                    $noEnc = (int) $stmtNoEnc->fetchColumn();

                    $stmtSynced = $this->mysql->prepare("
                        SELECT COUNT(*) FROM satu_sehat_episode_of_care eoc
                        INNER JOIN reg_periksa rp ON eoc.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                    ");
                    $stmtSynced->execute(['df' => $df, 'dt' => $dt]);
                    $synced = (int) $stmtSynced->fetchColumn();

                    $pending = $total - $noEnc - $synced;
                    if ($pending < 0) $pending = 0;

                    $this->log->info("   ├─ Total EpisodeOfCare Records in SIMRS: {$total}");
                    $this->log->info("   ├─ Blocked (No Parent Encounter Created): {$noEnc}");
                    $this->log->info("   ├─ Already Synced to Satu Sehat        : {$synced}");
                    $this->log->info("   └─ Pending / Ready to Sync             : {$pending}");
                    break;

                case 'condition':
                    $stmtTotal = $this->mysql->prepare("SELECT COUNT(*) FROM diagnosa_pasien dp INNER JOIN reg_periksa rp ON dp.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt");
                    $stmtTotal->execute(['df' => $df, 'dt' => $dt]);
                    $total = (int) $stmtTotal->fetchColumn();

                    $stmtNoEnc = $this->mysql->prepare("
                        SELECT COUNT(*) FROM diagnosa_pasien dp
                        INNER JOIN reg_periksa rp ON dp.no_rawat = rp.no_rawat
                        LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt AND sse.id_encounter IS NULL
                    ");
                    $stmtNoEnc->execute(['df' => $df, 'dt' => $dt]);
                    $noEnc = (int) $stmtNoEnc->fetchColumn();

                    $stmtSynced = $this->mysql->prepare("
                        SELECT COUNT(*) FROM satu_sehat_condition ssc
                        INNER JOIN reg_periksa rp ON ssc.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                    ");
                    $stmtSynced->execute(['df' => $df, 'dt' => $dt]);
                    $synced = (int) $stmtSynced->fetchColumn();

                    $pending = $total - $noEnc - $synced;
                    if ($pending < 0) $pending = 0;

                    $this->log->info("   ├─ Total Diagnoses (Condition) in SIMRS: {$total}");
                    $this->log->info("   ├─ Blocked (No Parent Encounter Created): {$noEnc}");
                    $this->log->info("   ├─ Already Synced to Satu Sehat        : {$synced}");
                    $this->log->info("   └─ Pending / Ready to Sync             : {$pending}");
                    break;

                case 'observationttv':
                    $stmtTotal = $this->mysql->prepare("
                        SELECT 
                            (SELECT COUNT(*) FROM pemeriksaan_ralan pr INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt) +
                            (SELECT COUNT(*) FROM pemeriksaan_ranap pr INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df2 AND :dt2)
                    ");
                    $stmtTotal->execute(['df' => $df, 'dt' => $dt, 'df2' => $df, 'dt2' => $dt]);
                    $total = (int) $stmtTotal->fetchColumn();

                    $stmtNoEnc = $this->mysql->prepare("
                        SELECT 
                            (SELECT COUNT(*) FROM pemeriksaan_ralan pr INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt AND sse.id_encounter IS NULL) +
                            (SELECT COUNT(*) FROM pemeriksaan_ranap pr INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df2 AND :dt2 AND sse.id_encounter IS NULL)
                    ");
                    $stmtNoEnc->execute(['df' => $df, 'dt' => $dt, 'df2' => $df, 'dt2' => $dt]);
                    $noEnc = (int) $stmtNoEnc->fetchColumn();

                    $this->log->info("   ├─ Total Vital Signs Records in SIMRS   : {$total}");
                    $this->log->info("   └─ Blocked (No Parent Encounter Created): {$noEnc}");
                    break;

                case 'procedure':
                    $stmtTotal = $this->mysql->prepare("SELECT COUNT(*) FROM prosedur_pasien pp INNER JOIN reg_periksa rp ON pp.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt");
                    $stmtTotal->execute(['df' => $df, 'dt' => $dt]);
                    $total = (int) $stmtTotal->fetchColumn();

                    $stmtNoEnc = $this->mysql->prepare("
                        SELECT COUNT(*) FROM prosedur_pasien pp
                        INNER JOIN reg_periksa rp ON pp.no_rawat = rp.no_rawat
                        LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt AND sse.id_encounter IS NULL
                    ");
                    $stmtNoEnc->execute(['df' => $df, 'dt' => $dt]);
                    $noEnc = (int) $stmtNoEnc->fetchColumn();

                    $stmtSynced = $this->mysql->prepare("
                        SELECT COUNT(*) FROM satu_sehat_procedure ssp
                        INNER JOIN reg_periksa rp ON ssp.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                    ");
                    $stmtSynced->execute(['df' => $df, 'dt' => $dt]);
                    $synced = (int) $stmtSynced->fetchColumn();

                    $pending = $total - $noEnc - $synced;
                    if ($pending < 0) $pending = 0;

                    $this->log->info("   ├─ Total Procedures in SIMRS           : {$total}");
                    $this->log->info("   ├─ Blocked (No Parent Encounter Created): {$noEnc}");
                    $this->log->info("   ├─ Already Synced to Satu Sehat        : {$synced}");
                    $this->log->info("   └─ Pending / Ready to Sync             : {$pending}");
                    break;

                case 'careplan':
                    $sqlTotal = "
                        SELECT COUNT(*) FROM (
                            SELECT pr.no_rawat FROM pemeriksaan_ralan pr 
                            INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat 
                            WHERE rp.tgl_registrasi BETWEEN :df AND :dt AND pr.rtl <> ''
                            UNION ALL
                            SELECT pi.no_rawat FROM pemeriksaan_ranap pi 
                            INNER JOIN reg_periksa rp ON pi.no_rawat = rp.no_rawat 
                            WHERE rp.tgl_registrasi BETWEEN :df2 AND :dt2 AND pi.rtl <> ''
                        ) AS combined
                    ";
                    $stmtTotal = $this->mysql->prepare($sqlTotal);
                    $stmtTotal->execute(['df' => $df, 'dt' => $dt, 'df2' => $df, 'dt2' => $dt]);
                    $total = (int) $stmtTotal->fetchColumn();

                    $sqlNoEnc = "
                        SELECT COUNT(*) FROM (
                            SELECT pr.no_rawat FROM pemeriksaan_ralan pr 
                            INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat 
                            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                            WHERE rp.tgl_registrasi BETWEEN :df AND :dt AND pr.rtl <> '' AND sse.id_encounter IS NULL
                            UNION ALL
                            SELECT pi.no_rawat FROM pemeriksaan_ranap pi 
                            INNER JOIN reg_periksa rp ON pi.no_rawat = rp.no_rawat 
                            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                            WHERE rp.tgl_registrasi BETWEEN :df2 AND :dt2 AND pi.rtl <> '' AND sse.id_encounter IS NULL
                        ) AS combined
                    ";
                    $stmtNoEnc = $this->mysql->prepare($sqlNoEnc);
                    $stmtNoEnc->execute(['df' => $df, 'dt' => $dt, 'df2' => $df, 'dt2' => $dt]);
                    $noEnc = (int) $stmtNoEnc->fetchColumn();

                    $stmtSynced = $this->mysql->prepare("
                        SELECT COUNT(*) FROM satu_sehat_careplan ssc
                        INNER JOIN reg_periksa rp ON ssc.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                    ");
                    $stmtSynced->execute(['df' => $df, 'dt' => $dt]);
                    $synced = (int) $stmtSynced->fetchColumn();

                    $pending = $total - $noEnc - $synced;
                    if ($pending < 0) $pending = 0;

                    $this->log->info("   ├─ Total CarePlan Records in SIMRS      : {$total}");
                    $this->log->info("   ├─ Blocked (No Parent Encounter Created): {$noEnc}");
                    $this->log->info("   ├─ Already Synced to Satu Sehat        : {$synced}");
                    $this->log->info("   └─ Pending / Ready to Sync             : {$pending}");
                    break;

                case 'allergy_intolerance':
                    $sqlTotal = "
                        SELECT COUNT(*) FROM (
                            SELECT pr.no_rawat FROM pemeriksaan_ralan pr 
                            INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat 
                            WHERE rp.tgl_registrasi BETWEEN :df AND :dt AND pr.alergi <> ''
                            UNION ALL
                            SELECT pi.no_rawat FROM pemeriksaan_ranap pi 
                            INNER JOIN reg_periksa rp ON pi.no_rawat = rp.no_rawat 
                            WHERE rp.tgl_registrasi BETWEEN :df2 AND :dt2 AND pi.alergi <> ''
                        ) AS combined
                    ";
                    $stmtTotal = $this->mysql->prepare($sqlTotal);
                    $stmtTotal->execute(['df' => $df, 'dt' => $dt, 'df2' => $df, 'dt2' => $dt]);
                    $total = (int) $stmtTotal->fetchColumn();

                    $sqlNoEnc = "
                        SELECT COUNT(*) FROM (
                            SELECT pr.no_rawat FROM pemeriksaan_ralan pr 
                            INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat 
                            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                            WHERE rp.tgl_registrasi BETWEEN :df AND :dt AND pr.alergi <> '' AND sse.id_encounter IS NULL
                            UNION ALL
                            SELECT pi.no_rawat FROM pemeriksaan_ranap pi 
                            INNER JOIN reg_periksa rp ON pi.no_rawat = rp.no_rawat 
                            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                            WHERE rp.tgl_registrasi BETWEEN :df2 AND :dt2 AND pi.alergi <> '' AND sse.id_encounter IS NULL
                        ) AS combined
                    ";
                    $stmtNoEnc = $this->mysql->prepare($sqlNoEnc);
                    $stmtNoEnc->execute(['df' => $df, 'dt' => $dt, 'df2' => $df, 'dt2' => $dt]);
                    $noEnc = (int) $stmtNoEnc->fetchColumn();

                    $stmtSynced = $this->mysql->prepare("
                        SELECT COUNT(*) FROM satu_sehat_allergy_intolerance ssa
                        INNER JOIN reg_periksa rp ON ssa.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                    ");
                    $stmtSynced->execute(['df' => $df, 'dt' => $dt]);
                    $synced = (int) $stmtSynced->fetchColumn();

                    $pending = $total - $noEnc - $synced;
                    if ($pending < 0) $pending = 0;

                    $this->log->info("   ├─ Total Allergy Records in SIMRS      : {$total}");
                    $this->log->info("   ├─ Blocked (No Parent Encounter Created): {$noEnc}");
                    $this->log->info("   ├─ Already Synced to Satu Sehat        : {$synced}");
                    $this->log->info("   └─ Pending / Ready to Sync             : {$pending}");
                    break;

                case 'immunization':
                    $stmtTotal = $this->mysql->prepare("
                        SELECT COUNT(*) FROM detail_pemberian_obat dpo
                        INNER JOIN reg_periksa rp ON dpo.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                    ");
                    $stmtTotal->execute(['df' => $df, 'dt' => $dt]);
                    $total = (int) $stmtTotal->fetchColumn();

                    $stmtUnmapped = $this->mysql->prepare("
                        SELECT COUNT(*) FROM detail_pemberian_obat dpo
                        INNER JOIN reg_periksa rp ON dpo.no_rawat = rp.no_rawat
                        LEFT JOIN satu_sehat_mapping_vaksin smv ON smv.kode_brng = dpo.kode_brng
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt AND smv.kode_brng IS NULL
                    ");
                    $stmtUnmapped->execute(['df' => $df, 'dt' => $dt]);
                    $unmapped = (int) $stmtUnmapped->fetchColumn();

                    $stmtNoEnc = $this->mysql->prepare("
                        SELECT COUNT(*) FROM detail_pemberian_obat dpo
                        INNER JOIN reg_periksa rp ON dpo.no_rawat = rp.no_rawat
                        INNER JOIN satu_sehat_mapping_vaksin smv ON smv.kode_brng = dpo.kode_brng
                        LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt AND sse.id_encounter IS NULL
                    ");
                    $stmtNoEnc->execute(['df' => $df, 'dt' => $dt]);
                    $noEnc = (int) $stmtNoEnc->fetchColumn();

                    $stmtSynced = $this->mysql->prepare("
                        SELECT COUNT(*) FROM satu_sehat_immunization ssi
                        INNER JOIN reg_periksa rp ON ssi.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                    ");
                    $stmtSynced->execute(['df' => $df, 'dt' => $dt]);
                    $synced = (int) $stmtSynced->fetchColumn();

                    $this->log->info("   ├─ Total Drug Administrations in SIMRS : {$total}");
                    $this->log->info("   ├─ Unmapped Vaccines (Filtered Out)    : {$unmapped}");
                    $this->log->info("   ├─ Blocked (No Parent Encounter Created): {$noEnc}");
                    $this->log->info("   └─ Already Synced to Satu Sehat        : {$synced}");
                    break;

                case 'medication':
                    $total = (int) $this->mysql->query("SELECT COUNT(*) FROM databarang")->fetchColumn();
                    $unmapped = (int) $this->mysql->query("
                        SELECT COUNT(*) FROM databarang db
                        LEFT JOIN satu_sehat_mapping_obat ssmo ON db.kode_brng = ssmo.kode_brng
                        WHERE ssmo.kode_brng IS NULL
                    ")->fetchColumn();
                    $synced = (int) $this->mysql->query("SELECT COUNT(*) FROM satu_sehat_medication")->fetchColumn();
                    $pending = $total - $unmapped - $synced;
                    if ($pending < 0) $pending = 0;

                    $this->log->info("   ├─ Total Drugs in SIMRS databarang     : {$total}");
                    $this->log->info("   ├─ Unmapped to Satu Sehat (Filtered)   : {$unmapped}");
                    $this->log->info("   ├─ Already Synced to Satu Sehat        : {$synced}");
                    $this->log->info("   └─ Pending Sync / Ready to Master Sync : {$pending}");
                    break;

                case 'medication_request':
                    $stmtTotal = $this->mysql->prepare("
                        SELECT COUNT(*) FROM resep_dokter rd
                        INNER JOIN resep_obat ro ON rd.no_resep = ro.no_resep
                        INNER JOIN reg_periksa rp ON ro.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                    ");
                    $stmtTotal->execute(['df' => $df, 'dt' => $dt]);
                    $total = (int) $stmtTotal->fetchColumn();

                    $stmtNoEnc = $this->mysql->prepare("
                        SELECT COUNT(*) FROM resep_dokter rd
                        INNER JOIN resep_obat ro ON rd.no_resep = ro.no_resep
                        INNER JOIN reg_periksa rp ON ro.no_rawat = rp.no_rawat
                        LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt AND sse.id_encounter IS NULL
                    ");
                    $stmtNoEnc->execute(['df' => $df, 'dt' => $dt]);
                    $noEnc = (int) $stmtNoEnc->fetchColumn();

                    $stmtSynced = $this->mysql->prepare("
                        SELECT 
                            (SELECT COUNT(*) FROM satu_sehat_medicationrequest ssmr INNER JOIN resep_obat ro ON ssmr.no_resep = ro.no_resep INNER JOIN reg_periksa rp ON ro.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt) +
                            (SELECT COUNT(*) FROM satu_sehat_medicationrequest_racikan ssmrr INNER JOIN resep_obat ro ON ssmrr.no_resep = ro.no_resep INNER JOIN reg_periksa rp ON ro.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df2 AND :dt2)
                    ");
                    $stmtSynced->execute(['df' => $df, 'dt' => $dt, 'df2' => $df, 'dt2' => $dt]);
                    $synced = (int) $stmtSynced->fetchColumn();

                    $this->log->info("   ├─ Total Prescriptions (Resep) in SIMRS: {$total}");
                    $this->log->info("   ├─ Blocked (No Parent Encounter Created): {$noEnc}");
                    $this->log->info("   └─ Already Synced to Satu Sehat        : {$synced}");
                    break;

                case 'medication_dispense':
                    $stmtTotal = $this->mysql->prepare("
                        SELECT COUNT(*) FROM detail_pemberian_obat dpo
                        INNER JOIN reg_periksa rp ON dpo.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                    ");
                    $stmtTotal->execute(['df' => $df, 'dt' => $dt]);
                    $total = (int) $stmtTotal->fetchColumn();

                    $stmtNoEnc = $this->mysql->prepare("
                        SELECT COUNT(*) FROM detail_pemberian_obat dpo
                        INNER JOIN reg_periksa rp ON dpo.no_rawat = rp.no_rawat
                        LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt AND sse.id_encounter IS NULL
                    ");
                    $stmtNoEnc->execute(['df' => $df, 'dt' => $dt]);
                    $noEnc = (int) $stmtNoEnc->fetchColumn();

                    $stmtSynced = $this->mysql->prepare("
                        SELECT COUNT(*) FROM satu_sehat_medicationdispense ssm
                        INNER JOIN reg_periksa rp ON ssm.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                    ");
                    $stmtSynced->execute(['df' => $df, 'dt' => $dt]);
                    $synced = (int) $stmtSynced->fetchColumn();

                    $this->log->info("   ├─ Total Drug Dispenses in SIMRS       : {$total}");
                    $this->log->info("   ├─ Blocked (No Parent Encounter Created): {$noEnc}");
                    $this->log->info("   └─ Already Synced to Satu Sehat        : {$synced}");
                    break;

                case 'medication_statement':
                    $stmtTotal = $this->mysql->prepare("
                        SELECT COUNT(*) FROM resep_dokter rd
                        INNER JOIN resep_obat ro ON rd.no_resep = ro.no_resep
                        INNER JOIN reg_periksa rp ON ro.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                    ");
                    $stmtTotal->execute(['df' => $df, 'dt' => $dt]);
                    $total = (int) $stmtTotal->fetchColumn();

                    $stmtNoEnc = $this->mysql->prepare("
                        SELECT COUNT(*) FROM resep_dokter rd
                        INNER JOIN resep_obat ro ON rd.no_resep = ro.no_resep
                        INNER JOIN reg_periksa rp ON ro.no_rawat = rp.no_rawat
                        LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt AND sse.id_encounter IS NULL
                    ");
                    $stmtNoEnc->execute(['df' => $df, 'dt' => $dt]);
                    $noEnc = (int) $stmtNoEnc->fetchColumn();

                    $stmtSynced = $this->mysql->prepare("
                        SELECT 
                            (SELECT COUNT(*) FROM satu_sehat_medicationstatement ssms INNER JOIN resep_obat ro ON ssms.no_resep = ro.no_resep INNER JOIN reg_periksa rp ON ro.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt) +
                            (SELECT COUNT(*) FROM satu_sehat_medicationstatement_racikan ssmsr INNER JOIN resep_obat ro ON ssmsr.no_resep = ro.no_resep INNER JOIN reg_periksa rp ON ro.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df2 AND :dt2)
                    ");
                    $stmtSynced->execute(['df' => $df, 'dt' => $dt, 'df2' => $df, 'dt2' => $dt]);
                    $synced = (int) $stmtSynced->fetchColumn();

                    $this->log->info("   ├─ Total MedicationStatements in SIMRS : {$total}");
                    $this->log->info("   ├─ Blocked (No Parent Encounter Created): {$noEnc}");
                    $this->log->info("   └─ Already Synced to Satu Sehat        : {$synced}");
                    break;

                case 'clinical_impression':
                    // Total assessments
                    $stmtTotalRalan = $this->mysql->prepare("
                        SELECT COUNT(*) FROM pemeriksaan_ralan pr 
                        INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat 
                        WHERE pr.penilaian <> '' AND rp.tgl_registrasi BETWEEN :df AND :dt
                    ");
                    $stmtTotalRalan->execute(['df' => $df, 'dt' => $dt]);
                    $totalRalan = (int) $stmtTotalRalan->fetchColumn();

                    $stmtTotalRanap = $this->mysql->prepare("
                        SELECT COUNT(*) FROM pemeriksaan_ranap pr 
                        INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat 
                        WHERE pr.penilaian <> '' AND rp.tgl_registrasi BETWEEN :df AND :dt
                    ");
                    $stmtTotalRanap->execute(['df' => $df, 'dt' => $dt]);
                    $totalRanap = (int) $stmtTotalRanap->fetchColumn();

                    $total = $totalRalan + $totalRanap;

                    // Blocked due to missing parent Encounter
                    $stmtNoEncRalan = $this->mysql->prepare("
                        SELECT COUNT(*) FROM pemeriksaan_ralan pr 
                        INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat 
                        LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                        WHERE pr.penilaian <> '' AND rp.tgl_registrasi BETWEEN :df AND :dt AND sse.id_encounter IS NULL
                    ");
                    $stmtNoEncRalan->execute(['df' => $df, 'dt' => $dt]);
                    $noEncRalan = (int) $stmtNoEncRalan->fetchColumn();

                    $stmtNoEncRanap = $this->mysql->prepare("
                        SELECT COUNT(*) FROM pemeriksaan_ranap pr 
                        INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat 
                        LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                        WHERE pr.penilaian <> '' AND rp.tgl_registrasi BETWEEN :df AND :dt AND sse.id_encounter IS NULL
                    ");
                    $stmtNoEncRanap->execute(['df' => $df, 'dt' => $dt]);
                    $noEncRanap = (int) $stmtNoEncRanap->fetchColumn();

                    $noEnc = $noEncRalan + $noEncRanap;

                    // Blocked due to missing Condition (but has Encounter)
                    $stmtNoCondRalan = $this->mysql->prepare("
                        SELECT COUNT(*) FROM pemeriksaan_ralan pr 
                        INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat 
                        INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                        LEFT JOIN satu_sehat_condition ssc ON ssc.no_rawat = rp.no_rawat AND ssc.status = 'Ralan'
                        WHERE pr.penilaian <> '' AND rp.tgl_registrasi BETWEEN :df AND :dt AND ssc.id_condition IS NULL
                    ");
                    $stmtNoCondRalan->execute(['df' => $df, 'dt' => $dt]);
                    $noCondRalan = (int) $stmtNoCondRalan->fetchColumn();

                    $stmtNoCondRanap = $this->mysql->prepare("
                        SELECT COUNT(*) FROM pemeriksaan_ranap pr 
                        INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat 
                        INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                        LEFT JOIN satu_sehat_condition ssc ON ssc.no_rawat = rp.no_rawat AND ssc.status = 'Ranap'
                        WHERE pr.penilaian <> '' AND rp.tgl_registrasi BETWEEN :df AND :dt AND ssc.id_condition IS NULL
                    ");
                    $stmtNoCondRanap->execute(['df' => $df, 'dt' => $dt]);
                    $noCondRanap = (int) $stmtNoCondRanap->fetchColumn();

                    $noCond = $noCondRalan + $noCondRanap;

                    // Synced
                    $stmtSynced = $this->mysql->prepare("
                        SELECT COUNT(*) FROM satu_sehat_clinicalimpression ssci
                        INNER JOIN reg_periksa rp ON ssci.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                    ");
                    $stmtSynced->execute(['df' => $df, 'dt' => $dt]);
                    $synced = (int) $stmtSynced->fetchColumn();

                    $this->log->info("   ├─ Total ClinicalAssessments in SIMRS  : {$total} (Ralan: {$totalRalan}, Ranap: {$totalRanap})");
                    $this->log->info("   ├─ Blocked (No Parent Encounter Mapped): {$noEnc}");
                    $this->log->info("   ├─ Blocked (No Parent Condition Mapped): {$noCond}");
                    $this->log->info("   └─ Already Synced to Satu Sehat        : {$synced}");
                    break;

                case 'servicerequest_radiologi':
                    $stmtTotal = $this->mysql->prepare("
                        SELECT COUNT(*) 
                        FROM permintaan_pemeriksaan_radiologi ppr
                        INNER JOIN permintaan_radiologi pr ON ppr.noorder = pr.noorder
                        WHERE pr.tgl_permintaan BETWEEN :df AND :dt
                    ");
                    $stmtTotal->execute(['df' => $df, 'dt' => $dt]);
                    $total = (int) $stmtTotal->fetchColumn();

                    $stmtNoEnc = $this->mysql->prepare("
                        SELECT COUNT(*) 
                        FROM permintaan_pemeriksaan_radiologi ppr
                        INNER JOIN permintaan_radiologi pr ON ppr.noorder = pr.noorder
                        LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = pr.no_rawat
                        WHERE pr.tgl_permintaan BETWEEN :df AND :dt AND sse.id_encounter IS NULL
                    ");
                    $stmtNoEnc->execute(['df' => $df, 'dt' => $dt]);
                    $noEnc = (int) $stmtNoEnc->fetchColumn();

                    $stmtSynced = $this->mysql->prepare("
                        SELECT COUNT(*) 
                        FROM satu_sehat_servicerequest_radiologi ssr
                        INNER JOIN permintaan_radiologi pr ON ssr.noorder = pr.noorder
                        WHERE pr.tgl_permintaan BETWEEN :df AND :dt
                          AND ssr.id_servicerequest IS NOT NULL AND ssr.id_servicerequest <> '' AND ssr.id_servicerequest <> '-'
                    ");
                    $stmtSynced->execute(['df' => $df, 'dt' => $dt]);
                    $synced = (int) $stmtSynced->fetchColumn();

                    $pending = $total - $noEnc - $synced;
                    if ($pending < 0) $pending = 0;

                    $this->log->info("   ├─ Total Radiology Requests in SIMRS   : {$total}");
                    $this->log->info("   ├─ Blocked (No Parent Encounter Mapped): {$noEnc}");
                    $this->log->info("   ├─ Already Synced to Satu Sehat        : {$synced}");
                    $this->log->info("   └─ Pending / Ready to Sync             : {$pending}");
                    break;

                case 'imagingstudy_radiologi':
                    $stmtTotal = $this->mysql->prepare("
                        SELECT COUNT(*) 
                        FROM periksa_radiologi prad
                        INNER JOIN permintaan_radiologi pr ON pr.no_rawat = prad.no_rawat AND pr.tgl_hasil = prad.tgl_periksa
                        INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder AND ppr.kd_jenis_prw = prad.kd_jenis_prw
                        WHERE prad.tgl_periksa BETWEEN :df AND :dt
                    ");
                    $stmtTotal->execute(['df' => $df, 'dt' => $dt]);
                    $total = (int) $stmtTotal->fetchColumn();

                    $stmtNoReq = $this->mysql->prepare("
                        SELECT COUNT(*) 
                        FROM periksa_radiologi prad
                        INNER JOIN permintaan_radiologi pr ON pr.no_rawat = prad.no_rawat AND pr.tgl_hasil = prad.tgl_periksa
                        INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder AND ppr.kd_jenis_prw = prad.kd_jenis_prw
                        LEFT JOIN satu_sehat_servicerequest_radiologi ssr ON ssr.noorder = ppr.noorder AND ssr.kd_jenis_prw = ppr.kd_jenis_prw
                        WHERE prad.tgl_periksa BETWEEN :df AND :dt 
                          AND (ssr.id_servicerequest IS NULL OR ssr.id_servicerequest = '' OR ssr.id_servicerequest = '-')
                    ");
                    $stmtNoReq->execute(['df' => $df, 'dt' => $dt]);
                    $noReq = (int) $stmtNoReq->fetchColumn();

                    $stmtSynced = $this->mysql->prepare("
                        SELECT COUNT(*) 
                        FROM satu_sehat_imagingstudy_radiologi ssi
                        INNER JOIN permintaan_radiologi pr ON ssi.noorder = pr.noorder
                        WHERE pr.tgl_hasil BETWEEN :df AND :dt
                          AND ssi.id_imaging IS NOT NULL AND ssi.id_imaging <> '' AND ssi.id_imaging <> '-'
                    ");
                    $stmtSynced->execute(['df' => $df, 'dt' => $dt]);
                    $synced = (int) $stmtSynced->fetchColumn();

                    $pending = $total - $noReq - $synced;
                    if ($pending < 0) $pending = 0;

                    $this->log->info("   ├─ Total Radiology Exams in SIMRS      : {$total}");
                    $this->log->info("   ├─ Blocked (No ServiceRequest Mapped)  : {$noReq}");
                    $this->log->info("   ├─ Already Synced to Satu Sehat        : {$synced}");
                    $this->log->info("   └─ Pending / Ready to Sync             : {$pending}");
                    break;

                case 'diagnosticreport_radiologi':
                    $stmtTotal = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM hasil_radiologi hr
                        INNER JOIN periksa_radiologi prad ON hr.no_rawat = prad.no_rawat AND hr.tgl_periksa = prad.tgl_periksa AND hr.jam = prad.jam
                        INNER JOIN permintaan_radiologi pr ON pr.no_rawat = prad.no_rawat AND pr.tgl_hasil = prad.tgl_periksa AND pr.jam_hasil = prad.jam AND pr.dokter_perujuk = prad.dokter_perujuk
                        INNER JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                    ");
                    $stmtTotal->execute(['df' => $df, 'dt' => $dt]);
                    $total = (int) $stmtTotal->fetchColumn();

                    $stmtNoEnc = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM hasil_radiologi hr
                        INNER JOIN periksa_radiologi prad ON hr.no_rawat = prad.no_rawat AND hr.tgl_periksa = prad.tgl_periksa AND hr.jam = prad.jam
                        INNER JOIN permintaan_radiologi pr ON pr.no_rawat = prad.no_rawat AND pr.tgl_hasil = prad.tgl_periksa AND pr.jam_hasil = prad.jam AND pr.dokter_perujuk = prad.dokter_perujuk
                        INNER JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat
                        LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt AND sse.id_encounter IS NULL
                    ");
                    $stmtNoEnc->execute(['df' => $df, 'dt' => $dt]);
                    $noEnc = (int) $stmtNoEnc->fetchColumn();

                    $stmtNoReq = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM hasil_radiologi hr
                        INNER JOIN periksa_radiologi prad ON hr.no_rawat = prad.no_rawat AND hr.tgl_periksa = prad.tgl_periksa AND hr.jam = prad.jam
                        INNER JOIN permintaan_radiologi pr ON pr.no_rawat = prad.no_rawat AND pr.tgl_hasil = prad.tgl_periksa AND pr.jam_hasil = prad.jam AND pr.dokter_perujuk = prad.dokter_perujuk
                        INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
                        INNER JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat
                        LEFT JOIN satu_sehat_servicerequest_radiologi ssr ON ssr.noorder = ppr.noorder AND ssr.kd_jenis_prw = ppr.kd_jenis_prw
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt 
                          AND (ssr.id_servicerequest IS NULL OR ssr.id_servicerequest = '' OR ssr.id_servicerequest = '-')
                    ");
                    $stmtNoReq->execute(['df' => $df, 'dt' => $dt]);
                    $noReq = (int) $stmtNoReq->fetchColumn();

                    $stmtNoSpec = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM hasil_radiologi hr
                        INNER JOIN periksa_radiologi prad ON hr.no_rawat = prad.no_rawat AND hr.tgl_periksa = prad.tgl_periksa AND hr.jam = prad.jam
                        INNER JOIN permintaan_radiologi pr ON pr.no_rawat = prad.no_rawat AND pr.tgl_hasil = prad.tgl_periksa AND pr.jam_hasil = prad.jam AND pr.dokter_perujuk = prad.dokter_perujuk
                        INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
                        INNER JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat
                        LEFT JOIN satu_sehat_specimen_radiologi sssp ON sssp.noorder = ppr.noorder AND sssp.kd_jenis_prw = ppr.kd_jenis_prw
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt 
                          AND (sssp.id_specimen IS NULL OR sssp.id_specimen = '' OR sssp.id_specimen = '-')
                    ");
                    $stmtNoSpec->execute(['df' => $df, 'dt' => $dt]);
                    $noSpec = (int) $stmtNoSpec->fetchColumn();

                    $stmtNoObs = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM hasil_radiologi hr
                        INNER JOIN periksa_radiologi prad ON hr.no_rawat = prad.no_rawat AND hr.tgl_periksa = prad.tgl_periksa AND hr.jam = prad.jam
                        INNER JOIN permintaan_radiologi pr ON pr.no_rawat = prad.no_rawat AND pr.tgl_hasil = prad.tgl_periksa AND pr.jam_hasil = prad.jam AND pr.dokter_perujuk = prad.dokter_perujuk
                        INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
                        INNER JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat
                        LEFT JOIN satu_sehat_observation_radiologi sso ON sso.noorder = ppr.noorder AND sso.kd_jenis_prw = ppr.kd_jenis_prw
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt 
                          AND (sso.id_observation IS NULL OR sso.id_observation = '' OR sso.id_observation = '-')
                    ");
                    $stmtNoObs->execute(['df' => $df, 'dt' => $dt]);
                    $noObs = (int) $stmtNoObs->fetchColumn();

                    $stmtNoImg = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM hasil_radiologi hr
                        INNER JOIN periksa_radiologi prad ON hr.no_rawat = prad.no_rawat AND hr.tgl_periksa = prad.tgl_periksa AND hr.jam = prad.jam
                        INNER JOIN permintaan_radiologi pr ON pr.no_rawat = prad.no_rawat AND pr.tgl_hasil = prad.tgl_periksa AND pr.jam_hasil = prad.jam AND pr.dokter_perujuk = prad.dokter_perujuk
                        INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
                        INNER JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat
                        LEFT JOIN satu_sehat_imagingstudy_radiologi ssi ON ssi.noorder = ppr.noorder AND ssi.kd_jenis_prw = ppr.kd_jenis_prw
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt 
                          AND (ssi.id_imaging IS NULL OR ssi.id_imaging = '' OR ssi.id_imaging = '-')
                    ");
                    $stmtNoImg->execute(['df' => $df, 'dt' => $dt]);
                    $noImg = (int) $stmtNoImg->fetchColumn();

                    $stmtSynced = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM satu_sehat_diagnosticreport_radiologi ssdr
                        INNER JOIN permintaan_radiologi pr ON ssdr.noorder = pr.noorder
                        INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                          AND ssdr.id_diagnosticreport IS NOT NULL AND ssdr.id_diagnosticreport <> '' AND ssdr.id_diagnosticreport <> '-'
                    ");
                    $stmtSynced->execute(['df' => $df, 'dt' => $dt]);
                    $synced = (int) $stmtSynced->fetchColumn();

                    $pending = $total - $synced;
                    if ($pending < 0) $pending = 0;

                    $this->log->info("   ├─ Total Diagnostic Reports in SIMRS   : {$total}");
                    $this->log->info("   ├─ Blocked (No Parent Encounter Mapped): {$noEnc}");
                    $this->log->info("   ├─ Blocked (No ServiceRequest Mapped)  : {$noReq}");
                    $this->log->info("   ├─ Blocked (No Specimen Mapped)        : {$noSpec}");
                    $this->log->info("   ├─ Blocked (No Observation Mapped)     : {$noObs}");
                    $this->log->info("   ├─ Blocked (No ImagingStudy Mapped)    : {$noImg}");
                    $this->log->info("   ├─ Already Synced to Satu Sehat        : {$synced}");
                    $this->log->info("   └─ Pending / Ready to Sync             : {$pending}");
                    break;

                case 'specimen_radiologi':
                    $stmtTotal = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM permintaan_pemeriksaan_radiologi ppr
                        INNER JOIN permintaan_radiologi pr ON ppr.noorder = pr.noorder
                        INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                    ");
                    $stmtTotal->execute(['df' => $df, 'dt' => $dt]);
                    $total = (int) $stmtTotal->fetchColumn();

                    $stmtNoReq = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM permintaan_pemeriksaan_radiologi ppr
                        INNER JOIN permintaan_radiologi pr ON ppr.noorder = pr.noorder
                        INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
                        LEFT JOIN satu_sehat_servicerequest_radiologi ssr ON ssr.noorder = ppr.noorder AND ssr.kd_jenis_prw = ppr.kd_jenis_prw
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt 
                          AND (ssr.id_servicerequest IS NULL OR ssr.id_servicerequest = '' OR ssr.id_servicerequest = '-')
                    ");
                    $stmtNoReq->execute(['df' => $df, 'dt' => $dt]);
                    $noReq = (int) $stmtNoReq->fetchColumn();

                    $stmtSynced = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM satu_sehat_specimen_radiologi sssp
                        INNER JOIN permintaan_radiologi pr ON sssp.noorder = pr.noorder
                        INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                          AND sssp.id_specimen IS NOT NULL AND sssp.id_specimen <> '' AND sssp.id_specimen <> '-'
                    ");
                    $stmtSynced->execute(['df' => $df, 'dt' => $dt]);
                    $synced = (int) $stmtSynced->fetchColumn();

                    $pending = $total - $synced;
                    if ($pending < 0) $pending = 0;

                    $this->log->info("   ├─ Total Specimen Radiologi in SIMRS   : {$total}");
                    $this->log->info("   ├─ Blocked (No ServiceRequest Mapped)  : {$noReq}");
                    $this->log->info("   ├─ Already Synced to Satu Sehat        : {$synced}");
                    $this->log->info("   └─ Pending / Ready to Sync             : {$pending}");
                    break;

                case 'observation_radiologi':
                    $stmtTotal = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM hasil_radiologi hr
                        INNER JOIN periksa_radiologi prad ON hr.no_rawat = prad.no_rawat AND hr.tgl_periksa = prad.tgl_periksa AND hr.jam = prad.jam
                        INNER JOIN permintaan_radiologi pr ON pr.no_rawat = prad.no_rawat AND pr.tgl_hasil = prad.tgl_periksa AND pr.jam_hasil = prad.jam AND pr.dokter_perujuk = prad.dokter_perujuk
                        INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
                        INNER JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat
                        INNER JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = ppr.kd_jenis_prw
                        INNER JOIN satu_sehat_mapping_radiologi smr ON smr.kd_jenis_prw = jpr.kd_jenis_prw
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                    ");
                    $stmtTotal->execute(['df' => $df, 'dt' => $dt]);
                    $total = (int) $stmtTotal->fetchColumn();

                    $stmtNoEnc = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM hasil_radiologi hr
                        INNER JOIN periksa_radiologi prad ON hr.no_rawat = prad.no_rawat AND hr.tgl_periksa = prad.tgl_periksa AND hr.jam = prad.jam
                        INNER JOIN permintaan_radiologi pr ON pr.no_rawat = prad.no_rawat AND pr.tgl_hasil = prad.tgl_periksa AND pr.jam_hasil = prad.jam AND pr.dokter_perujuk = prad.dokter_perujuk
                        INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
                        INNER JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat
                        INNER JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = ppr.kd_jenis_prw
                        INNER JOIN satu_sehat_mapping_radiologi smr ON smr.kd_jenis_prw = jpr.kd_jenis_prw
                        LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt 
                          AND (sse.id_encounter IS NULL OR sse.id_encounter = '' OR sse.id_encounter = '-')
                    ");
                    $stmtNoEnc->execute(['df' => $df, 'dt' => $dt]);
                    $noEnc = (int) $stmtNoEnc->fetchColumn();

                    $stmtNoReq = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM hasil_radiologi hr
                        INNER JOIN periksa_radiologi prad ON hr.no_rawat = prad.no_rawat AND hr.tgl_periksa = prad.tgl_periksa AND hr.jam = prad.jam
                        INNER JOIN permintaan_radiologi pr ON pr.no_rawat = prad.no_rawat AND pr.tgl_hasil = prad.tgl_periksa AND pr.jam_hasil = prad.jam AND pr.dokter_perujuk = prad.dokter_perujuk
                        INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
                        INNER JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat
                        INNER JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = ppr.kd_jenis_prw
                        INNER JOIN satu_sehat_mapping_radiologi smr ON smr.kd_jenis_prw = jpr.kd_jenis_prw
                        LEFT JOIN satu_sehat_servicerequest_radiologi ssr ON ssr.noorder = ppr.noorder AND ssr.kd_jenis_prw = ppr.kd_jenis_prw
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt 
                          AND (ssr.id_servicerequest IS NULL OR ssr.id_servicerequest = '' OR ssr.id_servicerequest = '-')
                    ");
                    $stmtNoReq->execute(['df' => $df, 'dt' => $dt]);
                    $noReq = (int) $stmtNoReq->fetchColumn();

                    $stmtNoSpec = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM hasil_radiologi hr
                        INNER JOIN periksa_radiologi prad ON hr.no_rawat = prad.no_rawat AND hr.tgl_periksa = prad.tgl_periksa AND hr.jam = prad.jam
                        INNER JOIN permintaan_radiologi pr ON pr.no_rawat = prad.no_rawat AND pr.tgl_hasil = prad.tgl_periksa AND pr.jam_hasil = prad.jam AND pr.dokter_perujuk = prad.dokter_perujuk
                        INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
                        INNER JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat
                        INNER JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = ppr.kd_jenis_prw
                        INNER JOIN satu_sehat_mapping_radiologi smr ON smr.kd_jenis_prw = jpr.kd_jenis_prw
                        LEFT JOIN satu_sehat_specimen_radiologi sssp ON sssp.noorder = ppr.noorder AND sssp.kd_jenis_prw = ppr.kd_jenis_prw
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt 
                          AND (sssp.id_specimen IS NULL OR sssp.id_specimen = '' OR sssp.id_specimen = '-')
                    ");
                    $stmtNoSpec->execute(['df' => $df, 'dt' => $dt]);
                    $noSpec = (int) $stmtNoSpec->fetchColumn();

                    $stmtNoImg = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM hasil_radiologi hr
                        INNER JOIN periksa_radiologi prad ON hr.no_rawat = prad.no_rawat AND hr.tgl_periksa = prad.tgl_periksa AND hr.jam = prad.jam
                        INNER JOIN permintaan_radiologi pr ON pr.no_rawat = prad.no_rawat AND pr.tgl_hasil = prad.tgl_periksa AND pr.jam_hasil = prad.jam AND pr.dokter_perujuk = prad.dokter_perujuk
                        INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
                        INNER JOIN reg_periksa rp ON rp.no_rawat = pr.no_rawat
                        INNER JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = ppr.kd_jenis_prw
                        INNER JOIN satu_sehat_mapping_radiologi smr ON smr.kd_jenis_prw = jpr.kd_jenis_prw
                        LEFT JOIN satu_sehat_imagingstudy_radiologi ssi ON ssi.noorder = ppr.noorder AND ssi.kd_jenis_prw = ppr.kd_jenis_prw
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt 
                          AND (ssi.id_imaging IS NULL OR ssi.id_imaging = '' OR ssi.id_imaging = '-')
                    ");
                    $stmtNoImg->execute(['df' => $df, 'dt' => $dt]);
                    $noImg = (int) $stmtNoImg->fetchColumn();

                    $stmtSynced = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM satu_sehat_observation_radiologi sso
                        INNER JOIN permintaan_radiologi pr ON sso.noorder = pr.noorder
                        INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                          AND sso.id_observation IS NOT NULL AND sso.id_observation <> '' AND sso.id_observation <> '-'
                    ");
                    $stmtSynced->execute(['df' => $df, 'dt' => $dt]);
                    $synced = (int) $stmtSynced->fetchColumn();

                    $pending = $total - $synced;
                    if ($pending < 0) $pending = 0;

                    $this->log->info("   ├─ Total Observations in SIMRS         : {$total}");
                    $this->log->info("   ├─ Blocked (No Parent Encounter Mapped): {$noEnc}");
                    $this->log->info("   ├─ Blocked (No ServiceRequest Mapped)  : {$noReq}");
                    $this->log->info("   ├─ Blocked (No Specimen Mapped)        : {$noSpec}");
                    $this->log->info("   ├─ Blocked (No ImagingStudy Mapped)    : {$noImg}");
                    $this->log->info("   ├─ Already Synced to Satu Sehat        : {$synced}");
                    $this->log->info("   └─ Pending / Ready to Sync             : {$pending}");
                    break;

                case 'servicerequest_lab_pk':
                    $stmtTotal = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM permintaan_detail_permintaan_lab pdpl
                        INNER JOIN permintaan_lab pl ON pdpl.noorder = pl.noorder
                        INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                        INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                        INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                    ");
                    $stmtTotal->execute(['df' => $df, 'dt' => $dt]);
                    $total = (int) $stmtTotal->fetchColumn();

                    $stmtNoEnc = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM permintaan_detail_permintaan_lab pdpl
                        INNER JOIN permintaan_lab pl ON pdpl.noorder = pl.noorder
                        INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                        INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                        INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
                        LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt 
                          AND (sse.id_encounter IS NULL OR sse.id_encounter = '' OR sse.id_encounter = '-')
                    ");
                    $stmtNoEnc->execute(['df' => $df, 'dt' => $dt]);
                    $noEnc = (int) $stmtNoEnc->fetchColumn();

                    $stmtSynced = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM satu_sehat_servicerequest_lab sssl
                        INNER JOIN permintaan_lab pl ON sssl.noorder = pl.noorder
                        INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                          AND sssl.id_servicerequest IS NOT NULL AND sssl.id_servicerequest <> '' AND sssl.id_servicerequest <> '-'
                    ");
                    $stmtSynced->execute(['df' => $df, 'dt' => $dt]);
                    $synced = (int) $stmtSynced->fetchColumn();

                    $pending = $total - $synced;
                    if ($pending < 0) $pending = 0;

                    $this->log->info("   ├─ Total ServiceRequests Lab PK        : {$total}");
                    $this->log->info("   ├─ Blocked (No Parent Encounter Mapped): {$noEnc}");
                    $this->log->info("   ├─ Already Synced to Satu Sehat        : {$synced}");
                    $this->log->info("   └─ Pending / Ready to Sync             : {$pending}");
                    break;

                case 'servicerequest_lab_mb':
                    $stmtTotal = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM permintaan_detail_permintaan_labmb pdpl
                        INNER JOIN permintaan_labmb pl ON pdpl.noorder = pl.noorder
                        INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                        INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                        INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                    ");
                    $stmtTotal->execute(['df' => $df, 'dt' => $dt]);
                    $total = (int) $stmtTotal->fetchColumn();

                    $stmtNoEnc = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM permintaan_detail_permintaan_labmb pdpl
                        INNER JOIN permintaan_labmb pl ON pdpl.noorder = pl.noorder
                        INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                        INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                        INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
                        LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt 
                          AND (sse.id_encounter IS NULL OR sse.id_encounter = '' OR sse.id_encounter = '-')
                    ");
                    $stmtNoEnc->execute(['df' => $df, 'dt' => $dt]);
                    $noEnc = (int) $stmtNoEnc->fetchColumn();

                    $stmtSynced = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM satu_sehat_servicerequest_lab_mb sssl
                        INNER JOIN permintaan_labmb pl ON sssl.noorder = pl.noorder
                        INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                          AND sssl.id_servicerequest IS NOT NULL AND sssl.id_servicerequest <> '' AND sssl.id_servicerequest <> '-'
                    ");
                    $stmtSynced->execute(['df' => $df, 'dt' => $dt]);
                    $synced = (int) $stmtSynced->fetchColumn();

                    $pending = $total - $synced;
                    if ($pending < 0) $pending = 0;

                    $this->log->info("   ├─ Total ServiceRequests Lab MB        : {$total}");
                    $this->log->info("   ├─ Blocked (No Parent Encounter Mapped): {$noEnc}");
                    $this->log->info("   ├─ Already Synced to Satu Sehat        : {$synced}");
                    $this->log->info("   └─ Pending / Ready to Sync             : {$pending}");
                    break;

                case 'specimen_lab_pk':
                    $stmtTotal = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM permintaan_detail_permintaan_lab pdpl
                        INNER JOIN permintaan_lab pl ON pdpl.noorder = pl.noorder
                        INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                        INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                        INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                    ");
                    $stmtTotal->execute(['df' => $df, 'dt' => $dt]);
                    $total = (int) $stmtTotal->fetchColumn();

                    $stmtNoReq = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM permintaan_detail_permintaan_lab pdpl
                        INNER JOIN permintaan_lab pl ON pdpl.noorder = pl.noorder
                        INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                        INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                        INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
                        LEFT JOIN satu_sehat_servicerequest_lab sssl ON sssl.noorder = pdpl.noorder
                          AND sssl.id_template = pdpl.id_template
                          AND sssl.kd_jenis_prw = pdpl.kd_jenis_prw
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt 
                          AND (sssl.id_servicerequest IS NULL OR sssl.id_servicerequest = '' OR sssl.id_servicerequest = '-')
                    ");
                    $stmtNoReq->execute(['df' => $df, 'dt' => $dt]);
                    $noReq = (int) $stmtNoReq->fetchColumn();

                    $stmtSynced = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM satu_sehat_specimen_lab sssp
                        INNER JOIN permintaan_lab pl ON sssp.noorder = pl.noorder
                        INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                          AND sssp.id_specimen IS NOT NULL AND sssp.id_specimen <> '' AND sssp.id_specimen <> '-'
                    ");
                    $stmtSynced->execute(['df' => $df, 'dt' => $dt]);
                    $synced = (int) $stmtSynced->fetchColumn();

                    $pending = $total - $synced;
                    if ($pending < 0) $pending = 0;

                    $this->log->info("   ├─ Total Specimens Lab PK              : {$total}");
                    $this->log->info("   ├─ Blocked (No Parent ServiceRequest)  : {$noReq}");
                    $this->log->info("   ├─ Already Synced to Satu Sehat        : {$synced}");
                    $this->log->info("   └─ Pending / Ready to Sync             : {$pending}");
                    break;

                case 'specimen_lab_mb':
                    $stmtTotal = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM permintaan_detail_permintaan_labmb pdpl
                        INNER JOIN permintaan_labmb pl ON pdpl.noorder = pl.noorder
                        INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                        INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                        INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                    ");
                    $stmtTotal->execute(['df' => $df, 'dt' => $dt]);
                    $total = (int) $stmtTotal->fetchColumn();

                    $stmtNoReq = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM permintaan_detail_permintaan_labmb pdpl
                        INNER JOIN permintaan_labmb pl ON pdpl.noorder = pl.noorder
                        INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                        INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                        INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
                        LEFT JOIN satu_sehat_servicerequest_lab_mb sssl ON sssl.noorder = pdpl.noorder
                          AND sssl.id_template = pdpl.id_template
                          AND sssl.kd_jenis_prw = pdpl.kd_jenis_prw
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt 
                          AND (sssl.id_servicerequest IS NULL OR sssl.id_servicerequest = '' OR sssl.id_servicerequest = '-')
                    ");
                    $stmtNoReq->execute(['df' => $df, 'dt' => $dt]);
                    $noReq = (int) $stmtNoReq->fetchColumn();

                    $stmtSynced = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM satu_sehat_specimen_lab_mb sssp
                        INNER JOIN permintaan_labmb pl ON sssp.noorder = pl.noorder
                        INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                          AND sssp.id_specimen IS NOT NULL AND sssp.id_specimen <> '' AND sssp.id_specimen <> '-'
                    ");
                    $stmtSynced->execute(['df' => $df, 'dt' => $dt]);
                    $synced = (int) $stmtSynced->fetchColumn();

                    $pending = $total - $synced;
                    if ($pending < 0) $pending = 0;

                    $this->log->info("   ├─ Total Specimens Lab MB              : {$total}");
                    $this->log->info("   ├─ Blocked (No Parent ServiceRequest)  : {$noReq}");
                    $this->log->info("   ├─ Already Synced to Satu Sehat        : {$synced}");
                    $this->log->info("   └─ Pending / Ready to Sync             : {$pending}");
                    break;

                case 'observation_lab_pk':
                    $stmtTotal = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM permintaan_detail_permintaan_lab pdpl
                        INNER JOIN permintaan_lab pl ON pdpl.noorder = pl.noorder
                        INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                        INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                        INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
                        INNER JOIN periksa_lab per ON per.no_rawat = pl.no_rawat
                          AND per.tgl_periksa = pl.tgl_hasil
                          AND per.jam = pl.jam_hasil
                          AND per.dokter_perujuk = pl.dokter_perujuk
                        INNER JOIN detail_periksa_lab dpl ON dpl.no_rawat = per.no_rawat
                          AND dpl.tgl_periksa = per.tgl_periksa
                          AND dpl.jam = per.jam
                          AND dpl.id_template = pdpl.id_template
                          AND dpl.kd_jenis_prw = pdpl.kd_jenis_prw
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                    ");
                    $stmtTotal->execute(['df' => $df, 'dt' => $dt]);
                    $total = (int) $stmtTotal->fetchColumn();

                    $stmtNoSpec = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM permintaan_detail_permintaan_lab pdpl
                        INNER JOIN permintaan_lab pl ON pdpl.noorder = pl.noorder
                        INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                        INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                        INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
                        INNER JOIN periksa_lab per ON per.no_rawat = pl.no_rawat
                          AND per.tgl_periksa = pl.tgl_hasil
                          AND per.jam = pl.jam_hasil
                          AND per.dokter_perujuk = pl.dokter_perujuk
                        INNER JOIN detail_periksa_lab dpl ON dpl.no_rawat = per.no_rawat
                          AND dpl.tgl_periksa = per.tgl_periksa
                          AND dpl.jam = per.jam
                          AND dpl.id_template = pdpl.id_template
                          AND dpl.kd_jenis_prw = pdpl.kd_jenis_prw
                        LEFT JOIN satu_sehat_specimen_lab sssp ON sssp.noorder = pdpl.noorder
                          AND sssp.id_template = pdpl.id_template
                          AND sssp.kd_jenis_prw = pdpl.kd_jenis_prw
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                          AND (sssp.id_specimen IS NULL OR sssp.id_specimen = '' OR sssp.id_specimen = '-')
                    ");
                    $stmtNoSpec->execute(['df' => $df, 'dt' => $dt]);
                    $noSpec = (int) $stmtNoSpec->fetchColumn();

                    $stmtSynced = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM satu_sehat_observation_lab sso
                        INNER JOIN permintaan_lab pl ON sso.noorder = pl.noorder
                        INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                          AND sso.id_observation IS NOT NULL AND sso.id_observation <> '' AND sso.id_observation <> '-'
                    ");
                    $stmtSynced->execute(['df' => $df, 'dt' => $dt]);
                    $synced = (int) $stmtSynced->fetchColumn();

                    $pending = $total - $synced;
                    if ($pending < 0) $pending = 0;

                    $this->log->info("   ├─ Total Observations Lab PK           : {$total}");
                    $this->log->info("   ├─ Blocked (No Parent Specimen)        : {$noSpec}");
                    $this->log->info("   ├─ Already Synced to Satu Sehat        : {$synced}");
                    $this->log->info("   └─ Pending / Ready to Sync             : {$pending}");
                    break;

                case 'observation_lab_mb':
                    $stmtTotal = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM permintaan_detail_permintaan_labmb pdpl
                        INNER JOIN permintaan_labmb pl ON pdpl.noorder = pl.noorder
                        INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                        INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                        INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
                        INNER JOIN periksa_lab per ON per.no_rawat = pl.no_rawat
                          AND per.tgl_periksa = pl.tgl_hasil
                          AND per.jam = pl.jam_hasil
                          AND per.dokter_perujuk = pl.dokter_perujuk
                        INNER JOIN detail_periksa_lab dpl ON dpl.no_rawat = per.no_rawat
                          AND dpl.tgl_periksa = per.tgl_periksa
                          AND dpl.jam = per.jam
                          AND dpl.id_template = pdpl.id_template
                          AND dpl.kd_jenis_prw = pdpl.kd_jenis_prw
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                    ");
                    $stmtTotal->execute(['df' => $df, 'dt' => $dt]);
                    $total = (int) $stmtTotal->fetchColumn();

                    $stmtNoSpec = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM permintaan_detail_permintaan_labmb pdpl
                        INNER JOIN permintaan_labmb pl ON pdpl.noorder = pl.noorder
                        INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                        INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                        INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
                        INNER JOIN periksa_lab per ON per.no_rawat = pl.no_rawat
                          AND per.tgl_periksa = pl.tgl_hasil
                          AND per.jam = pl.jam_hasil
                          AND per.dokter_perujuk = pl.dokter_perujuk
                        INNER JOIN detail_periksa_lab dpl ON dpl.no_rawat = per.no_rawat
                          AND dpl.tgl_periksa = per.tgl_periksa
                          AND dpl.jam = per.jam
                          AND dpl.id_template = pdpl.id_template
                          AND dpl.kd_jenis_prw = pdpl.kd_jenis_prw
                        LEFT JOIN satu_sehat_specimen_lab_mb sssp ON sssp.noorder = pdpl.noorder
                          AND sssp.id_template = pdpl.id_template
                          AND sssp.kd_jenis_prw = pdpl.kd_jenis_prw
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                          AND (sssp.id_specimen IS NULL OR sssp.id_specimen = '' OR sssp.id_specimen = '-')
                    ");
                    $stmtNoSpec->execute(['df' => $df, 'dt' => $dt]);
                    $noSpec = (int) $stmtNoSpec->fetchColumn();

                    $stmtSynced = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM satu_sehat_observation_lab_mb sso
                        INNER JOIN permintaan_labmb pl ON sso.noorder = pl.noorder
                        INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                          AND sso.id_observation IS NOT NULL AND sso.id_observation <> '' AND sso.id_observation <> '-'
                    ");
                    $stmtSynced->execute(['df' => $df, 'dt' => $dt]);
                    $synced = (int) $stmtSynced->fetchColumn();

                    $pending = $total - $synced;
                    if ($pending < 0) $pending = 0;

                    $this->log->info("   ├─ Total Observations Lab MB           : {$total}");
                    $this->log->info("   ├─ Blocked (No Parent Specimen)        : {$noSpec}");
                    $this->log->info("   ├─ Already Synced to Satu Sehat        : {$synced}");
                    $this->log->info("   └─ Pending / Ready to Sync             : {$pending}");
                    break;

                case 'diagnosticreport_lab_pk':
                    $stmtTotal = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM permintaan_detail_permintaan_lab pdpl
                        INNER JOIN permintaan_lab pl ON pdpl.noorder = pl.noorder
                        INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                        INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                        INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
                        INNER JOIN periksa_lab per ON per.no_rawat = pl.no_rawat
                          AND per.tgl_periksa = pl.tgl_hasil
                          AND per.jam = pl.jam_hasil
                          AND per.dokter_perujuk = pl.dokter_perujuk
                        INNER JOIN saran_kesan_lab skl ON per.no_rawat = skl.no_rawat
                          AND per.tgl_periksa = skl.tgl_periksa
                          AND per.jam = skl.jam
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                    ");
                    $stmtTotal->execute(['df' => $df, 'dt' => $dt]);
                    $total = (int) $stmtTotal->fetchColumn();

                    $stmtNoObs = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM permintaan_detail_permintaan_lab pdpl
                        INNER JOIN permintaan_lab pl ON pdpl.noorder = pl.noorder
                        INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                        INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                        INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
                        INNER JOIN periksa_lab per ON per.no_rawat = pl.no_rawat
                          AND per.tgl_periksa = pl.tgl_hasil
                          AND per.jam = pl.jam_hasil
                          AND per.dokter_perujuk = pl.dokter_perujuk
                        INNER JOIN saran_kesan_lab skl ON per.no_rawat = skl.no_rawat
                          AND per.tgl_periksa = skl.tgl_periksa
                          AND per.jam = skl.jam
                        LEFT JOIN satu_sehat_observation_lab sso ON sso.noorder = pdpl.noorder
                          AND sso.id_template = pdpl.id_template
                          AND sso.kd_jenis_prw = pdpl.kd_jenis_prw
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                          AND (sso.id_observation IS NULL OR sso.id_observation = '' OR sso.id_observation = '-')
                    ");
                    $stmtNoObs->execute(['df' => $df, 'dt' => $dt]);
                    $noObs = (int) $stmtNoObs->fetchColumn();

                    $stmtSynced = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM satu_sehat_diagnosticreport_lab ssdr
                        INNER JOIN permintaan_lab pl ON ssdr.noorder = pl.noorder
                        INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                          AND ssdr.id_diagnosticreport IS NOT NULL AND ssdr.id_diagnosticreport <> '' AND ssdr.id_diagnosticreport <> '-'
                    ");
                    $stmtSynced->execute(['df' => $df, 'dt' => $dt]);
                    $synced = (int) $stmtSynced->fetchColumn();

                    $pending = $total - $synced;
                    if ($pending < 0) $pending = 0;

                    $this->log->info("   ├─ Total Diagnostic Reports Lab PK     : {$total}");
                    $this->log->info("   ├─ Blocked (No Parent Observation)     : {$noObs}");
                    $this->log->info("   ├─ Already Synced to Satu Sehat        : {$synced}");
                    $this->log->info("   └─ Pending / Ready to Sync             : {$pending}");
                    break;

                case 'diagnosticreport_lab_mb':
                    $stmtTotal = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM permintaan_detail_permintaan_labmb pdpl
                        INNER JOIN permintaan_labmb pl ON pdpl.noorder = pl.noorder
                        INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                        INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                        INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
                        INNER JOIN periksa_lab per ON per.no_rawat = pl.no_rawat
                          AND per.tgl_periksa = pl.tgl_hasil
                          AND per.jam = pl.jam_hasil
                          AND per.dokter_perujuk = pl.dokter_perujuk
                        INNER JOIN saran_kesan_lab skl ON per.no_rawat = skl.no_rawat
                          AND per.tgl_periksa = skl.tgl_periksa
                          AND per.jam = skl.jam
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                    ");
                    $stmtTotal->execute(['df' => $df, 'dt' => $dt]);
                    $total = (int) $stmtTotal->fetchColumn();

                    $stmtNoObs = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM permintaan_detail_permintaan_labmb pdpl
                        INNER JOIN permintaan_labmb pl ON pdpl.noorder = pl.noorder
                        INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                        INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                        INNER JOIN satu_sehat_mapping_lab sml ON sml.id_template = tl.id_template
                        INNER JOIN periksa_lab per ON per.no_rawat = pl.no_rawat
                          AND per.tgl_periksa = pl.tgl_hasil
                          AND per.jam = pl.jam_hasil
                          AND per.dokter_perujuk = pl.dokter_perujuk
                        INNER JOIN saran_kesan_lab skl ON per.no_rawat = skl.no_rawat
                          AND per.tgl_periksa = skl.tgl_periksa
                          AND per.jam = skl.jam
                        LEFT JOIN satu_sehat_observation_lab_mb sso ON sso.noorder = pdpl.noorder
                          AND sso.id_template = pdpl.id_template
                          AND sso.kd_jenis_prw = pdpl.kd_jenis_prw
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                          AND (sso.id_observation IS NULL OR sso.id_observation = '' OR sso.id_observation = '-')
                    ");
                    $stmtNoObs->execute(['df' => $df, 'dt' => $dt]);
                    $noObs = (int) $stmtNoObs->fetchColumn();

                    $stmtSynced = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM satu_sehat_diagnosticreport_lab_mb ssdr
                        INNER JOIN permintaan_labmb pl ON ssdr.noorder = pl.noorder
                        INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                        WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                          AND ssdr.id_diagnosticreport IS NOT NULL AND ssdr.id_diagnosticreport <> '' AND ssdr.id_diagnosticreport <> '-'
                    ");
                    $stmtSynced->execute(['df' => $df, 'dt' => $dt]);
                    $synced = (int) $stmtSynced->fetchColumn();

                    $pending = $total - $synced;
                    if ($pending < 0) $pending = 0;

                    $this->log->info("   ├─ Total Diagnostic Reports Lab MB     : {$total}");
                    $this->log->info("   ├─ Blocked (No Parent Observation)     : {$noObs}");
                    $this->log->info("   ├─ Already Synced to Satu Sehat        : {$synced}");
                    $this->log->info("   └─ Pending / Ready to Sync             : {$pending}");
                    break;

                case 'questionnaireresponse':
                    $stmtTotal = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM resep_obat ro
                        INNER JOIN telaah_farmasi tf ON tf.no_resep = ro.no_resep
                        INNER JOIN reg_periksa rp ON ro.no_rawat = rp.no_rawat
                        INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                        WHERE ro.tgl_peresepan BETWEEN :df AND :dt
                    ");
                    $stmtTotal->execute(['df' => $df, 'dt' => $dt]);
                    $total = (int) $stmtTotal->fetchColumn();

                    $stmtSynced = $this->mysql->prepare("
                        SELECT COUNT(*)
                        FROM satu_sehat_questionresponse_telaah_farmasi ssqr
                        INNER JOIN resep_obat ro ON ssqr.no_resep = ro.no_resep
                        INNER JOIN reg_periksa rp ON ro.no_rawat = rp.no_rawat
                        WHERE ro.tgl_peresepan BETWEEN :df AND :dt
                          AND ssqr.id_questionresponse IS NOT NULL AND ssqr.id_questionresponse <> ''
                    ");
                    $stmtSynced->execute(['df' => $df, 'dt' => $dt]);
                    $synced = (int) $stmtSynced->fetchColumn();

                    $pending = $total - $synced;
                    if ($pending < 0) $pending = 0;

                    $this->log->info("   ├─ Total Questionnaire Responses       : {$total}");
                    $this->log->info("   ├─ Already Synced to Satu Sehat        : {$synced}");
                    $this->log->info("   └─ Pending / Ready to Sync             : {$pending}");
                    break;

                case 'patient':
                    $total = (int) $this->mysql->query("SELECT COUNT(*) FROM pasien")->fetchColumn();
                    $validNik = (int) $this->mysql->query("SELECT COUNT(*) FROM pasien WHERE no_ktp REGEXP '^[0-9]{16}$'")->fetchColumn();
                    $invalidNik = $total - $validNik;
                    
                    $synced = (int) $this->mysql->query("SELECT COUNT(*) FROM satu_sehat_ihs_patient WHERE ihspasien IS NOT NULL AND ihspasien <> ''")->fetchColumn();
                    
                    $pending = (int) $this->mysql->query("
                        SELECT COUNT(*) FROM pasien p 
                        LEFT JOIN satu_sehat_ihs_patient i ON p.no_ktp = i.nikpasien 
                        WHERE i.ihspasien IS NULL AND p.no_ktp REGEXP '^[0-9]{16}$'
                    ")->fetchColumn();

                    $this->log->info("   ├─ Total Patients in SIMRS             : {$total}");
                    $this->log->info("   ├─ Patients with Valid 16-digit NIK    : {$validNik}");
                    $this->log->info("   ├─ Invalid/Missing NIK (Filtered Out)  : {$invalidNik}");
                    $this->log->info("   ├─ Already Synced / Mapped IHS Numbers : {$synced}");
                    $this->log->info("   └─ Pending Sync / Ready to Sync        : {$pending}");
                    break;
            }
        } catch (\Throwable $e) {
            $this->log->warning("   [DIAGNOSTICS] Failed to calculate diagnostic metrics: " . $e->getMessage());
        }
        $this->log->info("──────────────────────────────────────────────────────────────");
    }

    /**
     * Self-healing mechanism for failed or stuck DICOM webhook callbacks.
     * Queries Satu Sehat directly by accession number (ACSN) identifier,
     * and recovers/updates the local imaging study ID if found.
     *
     * @param string|null $dateFrom Optional lookback date start
     * @param string|null $dateTo Optional lookback date end
     * @return int Number of records successfully healed
     */
    public function healFailedImagingStudies(?string $dateFrom = null, ?string $dateTo = null): int
    {
        $this->log->info("[HEALING] Starting ImagingStudy self-healing check...");
        
        $sql = "
            SELECT ssi.noorder, ssi.kd_jenis_prw, ssi.acsn, ssi.status_webhook 
            FROM satu_sehat_imagingstudy_radiologi ssi
        ";
        
        $params = [];
        if ($dateFrom !== null && $dateTo !== null) {
            $sql .= "
                INNER JOIN permintaan_radiologi pr ON ssi.noorder = pr.noorder
                WHERE pr.tgl_permintaan BETWEEN :df AND :dt
                  AND (ssi.id_imaging IS NULL OR ssi.id_imaging = '' OR ssi.id_imaging = '-')
                  AND (ssi.status_webhook = 'FAILED' OR ssi.status_webhook = 'PENDING')
                  AND ssi.acsn IS NOT NULL AND ssi.acsn <> '' AND ssi.acsn <> '-'
            ";
            $params['df'] = $dateFrom;
            $params['dt'] = $dateTo;
        } else {
            $sql .= "
                WHERE (ssi.id_imaging IS NULL OR ssi.id_imaging = '' OR ssi.id_imaging = '-')
                  AND (ssi.status_webhook = 'FAILED' OR ssi.status_webhook = 'PENDING')
                  AND ssi.acsn IS NOT NULL AND ssi.acsn <> '' AND ssi.acsn <> '-'
            ";
        }
        
        try {
            $stmt = $this->mysql->prepare($sql);
            $stmt->execute($params);
            $records = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $this->log->error("[HEALING] Database query failed: " . $e->getMessage());
            return 0;
        }
        
        $totalFound = count($records);
        if ($totalFound === 0) {
            $this->log->info("[HEALING] No unsynced/failed ImagingStudy records found for self-healing.");
            return 0;
        }
        
        $this->log->info("[HEALING] Found {$totalFound} potentially eligible records to check on Satu Sehat.");
        $healedCount = 0;
        
        foreach ($records as $record) {
            $noorder = $record['noorder'];
            $kdJenisPrw = $record['kd_jenis_prw'];
            $acsn = $record['acsn'];
            
            $this->log->debug("[HEALING] Checking ACSN '{$acsn}' on Satu Sehat...");
            
            // Format identifier: http://sys-ids.kemkes.go.id/acsn/{orgId}|{acsn}
            $identifier = "http://sys-ids.kemkes.go.id/acsn/" . $this->config->orgId . "|" . $acsn;
            $endpoint = "/ImagingStudy?identifier=" . urlencode($identifier);
            
            $res = $this->client->get($endpoint);
            
            if (!$res['success']) {
                $this->log->warning("[HEALING] Failed to query Satu Sehat for ACSN '{$acsn}': " . ($res['message'] ?? 'Unknown API Error'));
                continue;
            }
            
            $bundle = $res['data'] ?? [];
            if (!empty($bundle['entry']) && is_array($bundle['entry'])) {
                $resource = $bundle['entry'][0]['resource'] ?? [];
                $imagingId = $resource['id'] ?? '';
                
                if (!empty($imagingId)) {
                    $this->log->info("[HEALING] Found existing ImagingStudy '{$imagingId}' on Satu Sehat for ACSN '{$acsn}'. Healing database record...");
                    
                    try {
                        $updateSql = "
                            UPDATE satu_sehat_imagingstudy_radiologi 
                            SET id_imaging = :id, 
                                status_webhook = 'SUCCESS', 
                                message_webhook = 'DICOM berhasil dikirim (self-healed)' 
                            WHERE noorder = :noorder AND kd_jenis_prw = :kd
                        ";
                        $updateStmt = $this->mysql->prepare($updateSql);
                        $updateStmt->execute([
                            'id' => $imagingId,
                            'noorder' => $noorder,
                            'kd' => $kdJenisPrw
                        ]);
                        $healedCount++;
                    } catch (\PDOException $e) {
                        $this->log->error("[HEALING] Failed to update MySQL database for ACSN '{$acsn}': " . $e->getMessage());
                    }
                } else {
                    $this->log->debug("[HEALING] Empty resource ID in entry for ACSN '{$acsn}'.");
                }
            } else {
                $this->log->debug("[HEALING] ImagingStudy not found on Satu Sehat for ACSN '{$acsn}'.");
            }
        }
        
        $this->log->info("[HEALING] ImagingStudy self-healing completed. Healed: {$healedCount} / {$totalFound} checked.");
        return $healedCount;
    }

    public function fetchPendingImagingStudies(string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT 
                prad.no_rawat,
                prad.kd_jenis_prw,
                prad.tgl_periksa,
                prad.jam AS jam_periksa,
                pr.noorder,
                pr.tgl_permintaan,
                pr.jam_permintaan,
                jpr.nm_perawatan,
                d_perujuk.nm_dokter AS nm_dokter_perujuk,
                d_dokter.nm_dokter AS nm_dokter,
                p.no_rkm_medis AS no_rm,
                p.nm_pasien,
                p.tgl_lahir,
                p.jk,
                ssr.id_servicerequest,
                ssi.id_imaging,
                ssi.status_webhook,
                ssi.message_webhook,
                sse.id_encounter
            FROM periksa_radiologi prad
            INNER JOIN permintaan_radiologi pr ON pr.no_rawat = prad.no_rawat AND pr.tgl_hasil = prad.tgl_periksa
            INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder AND ppr.kd_jenis_prw = prad.kd_jenis_prw
            INNER JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = prad.kd_jenis_prw
            INNER JOIN reg_periksa rp ON rp.no_rawat = prad.no_rawat
            INNER JOIN pasien p ON p.no_rkm_medis = rp.no_rkm_medis
            LEFT JOIN dokter d_perujuk ON d_perujuk.kd_dokter = prad.dokter_perujuk
            LEFT JOIN dokter d_dokter ON d_dokter.kd_dokter = prad.kd_dokter
            INNER JOIN satu_sehat_servicerequest_radiologi ssr ON ssr.noorder = ppr.noorder AND ssr.kd_jenis_prw = ppr.kd_jenis_prw
            LEFT JOIN satu_sehat_imagingstudy_radiologi ssi ON ssi.noorder = ppr.noorder AND ssi.kd_jenis_prw = ppr.kd_jenis_prw
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            WHERE prad.tgl_periksa BETWEEN :df AND :dt
              AND ssr.id_servicerequest IS NOT NULL AND ssr.id_servicerequest <> '' AND ssr.id_servicerequest <> '-'
              AND (
                  ssi.id_imaging IS NULL 
                  OR ssi.id_imaging = '' 
                  OR ssi.id_imaging = '-' 
                  OR ssi.status_webhook = 'FAILED'
              )
        ";
        try {
            $stmt = $this->mysql->prepare($sql);
            $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $this->log->error("[DATABASE] Failed to fetch pending ImagingStudies: " . $e->getMessage());
            return [];
        }
    }

    public function fetchRadiologyImages(string $noRawat, string $kdJenisPrw, string $noorder): array
    {
        $sql = "
            SELECT gr.lokasi_gambar 
            FROM periksa_radiologi prad
            INNER JOIN gambar_radiologi gr ON gr.no_rawat = prad.no_rawat 
                AND gr.tgl_periksa = prad.tgl_periksa 
                AND gr.jam = prad.jam
            INNER JOIN permintaan_radiologi pr ON pr.no_rawat = prad.no_rawat 
                AND pr.tgl_hasil = prad.tgl_periksa
            WHERE prad.no_rawat = :norawat 
              AND prad.kd_jenis_prw = :kd 
              AND pr.noorder = :noorder
        ";
        try {
            $stmt = $this->mysql->prepare($sql);
            $stmt->execute([
                'norawat' => $noRawat,
                'kd' => $kdJenisPrw,
                'noorder' => $noorder
            ]);
            return $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\PDOException $e) {
            $this->log->error("[DATABASE] Failed to fetch radiology images: " . $e->getMessage());
            return [];
        }
    }

    public function saveImagingStudyInitial(string $noorder, string $kdJenisPrw, string $acsn, ?string $idServiceRequest): void
    {
        $sql = "
            INSERT INTO satu_sehat_imagingstudy_radiologi (noorder, kd_jenis_prw, id_servicerequest, acsn, status_webhook, message_webhook)
            VALUES (:noorder, :kd_jenis_prw, :id_servicerequest, :acsn, 'PENDING', 'DICOM router transmission pending')
            ON DUPLICATE KEY UPDATE 
                id_servicerequest = VALUES(id_servicerequest),
                acsn = VALUES(acsn),
                status_webhook = VALUES(status_webhook),
                message_webhook = VALUES(message_webhook)
        ";
        try {
            $stmt = $this->mysql->prepare($sql);
            $stmt->execute([
                'noorder' => $noorder,
                'kd_jenis_prw' => $kdJenisPrw,
                'id_servicerequest' => $idServiceRequest,
                'acsn' => $acsn
            ]);
        } catch (\PDOException $e) {
            $this->log->error("[DATABASE] Failed to save/update initial ImagingStudy: " . $e->getMessage());
        }
    }

    public function updateImagingStudyMySQLState(string $noorder, string $kdJenisPrw, string $status, string $message, ?string $idImaging = null): void
    {
        $sql = "
            UPDATE satu_sehat_imagingstudy_radiologi 
            SET status_webhook = :status,
                message_webhook = :message
        ";
        $params = [
            'status' => $status,
            'message' => $message,
            'noorder' => $noorder,
            'kd' => $kdJenisPrw
        ];
        if ($idImaging !== null) {
            $sql .= ", id_imaging = :id";
            $params['id'] = $idImaging;
        }
        $sql .= " WHERE noorder = :noorder AND kd_jenis_prw = :kd";
        
        try {
            $stmt = $this->mysql->prepare($sql);
            $stmt->execute($params);
        } catch (\PDOException $e) {
            $this->log->error("[DATABASE] Failed to update MySQL ImagingStudy state: " . $e->getMessage());
        }
    }
}
