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

    public function __construct(SatuSehatConfig $config, Logger $log, SatuSehatClient $client)
    {
        $this->log = $log;
        $this->client = $client;

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
    }

    public function close(): void
    {
        unset($this->mysql);
        unset($this->sqlite);
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
        $sql = "INSERT INTO satu_sehat_encounter (no_rawat, id_encounter) VALUES (:nr, :id) ON DUPLICATE KEY UPDATE id_encounter = :id";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute(['nr' => $noRawat, 'id' => $idEncounter]);
    }

    // ─── IHS LOOKUPS ───────────────────────────────────────────────────────────

    private function isValidNik(string $nik): bool
    {
        $nik = trim($nik);
        return strlen($nik) === 16 && ctype_digit($nik);
    }

    public function getIhsPatient(string $nik): ?string
    {
        if (!$this->isValidNik($nik)) {
            $this->log->debug("[DB] Invalid Patient NIK format: '{$nik}' (skipping IHS lookup)");
            return null;
        }

        $stmt = $this->mysql->prepare("SELECT ihspasien FROM satu_sehat_ihs_patient WHERE nikpasien = :nik LIMIT 1");
        $stmt->execute(['nik' => $nik]);
        $row = $stmt->fetch();

        if ($row && !empty($row['ihspasien'])) {
            return $row['ihspasien'];
        }

        // Fallback to API lookup
        $this->log->info("[API] Patient NIK {$nik} not found in DB. Searching via Satu Sehat...");
        $result = $this->client->get("/Patient?identifier=https://fhir.kemkes.go.id/id/nik|{$nik}");
        
        if ($result['success'] && isset($result['data']['entry'][0]['resource']['id'])) {
            $ihsId = $result['data']['entry'][0]['resource']['id'];
            $this->log->info("[API] Found IHS Patient ID: {$ihsId}. Saving to DB...");
            
            $insert = $this->mysql->prepare("INSERT INTO satu_sehat_ihs_patient (nikpasien, ihspasien) VALUES (:n, :i) ON DUPLICATE KEY UPDATE ihspasien = :i");
            $insert->execute(['n' => $nik, 'i' => $ihsId]);
            return $ihsId;
        }

        return null;
    }

    public function getIhsPractitioner(string $nik): ?string
    {
        if (!$this->isValidNik($nik)) {
            $this->log->debug("[DB] Invalid Practitioner NIK format: '{$nik}' (skipping IHS lookup)");
            return null;
        }

        $stmt = $this->mysql->prepare("SELECT ihspegawai FROM satu_sehat_ihs_practitioner WHERE nikpegawai = :nik LIMIT 1");
        $stmt->execute(['nik' => $nik]);
        $row = $stmt->fetch();

        if ($row && !empty($row['ihspegawai'])) {
            return $row['ihspegawai'];
        }

        // Fallback to API lookup
        $this->log->info("[API] Practitioner NIK {$nik} not found in DB. Searching via Satu Sehat...");
        $result = $this->client->get("/Practitioner?identifier=https://fhir.kemkes.go.id/id/nik|{$nik}");
        
        if ($result['success'] && isset($result['data']['entry'][0]['resource']['id'])) {
            $ihsId = $result['data']['entry'][0]['resource']['id'];
            $this->log->info("[API] Found IHS Practitioner ID: {$ihsId}. Saving to DB...");
            
            $insert = $this->mysql->prepare("INSERT INTO satu_sehat_ihs_practitioner (nikpegawai, ihspegawai) VALUES (:n, :i) ON DUPLICATE KEY UPDATE ihspegawai = :i");
            $insert->execute(['n' => $nik, 'i' => $ihsId]);
            return $ihsId;
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
                ON DUPLICATE KEY UPDATE id_episode_of_care = :id, status = :st";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'nr' => $noRawat,
            'kd' => $kdPenyakit,
            'st' => $status,
            'id' => $idEpisode
        ]);
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
                ON DUPLICATE KEY UPDATE id_condition = :id";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'nr' => $noRawat,
            'kd' => $kdPenyakit,
            'st' => $status,
            'id' => $idCondition
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

    // ─── OBSERVATION-TTV MYSQL OPERATIONS ──────────────────────────────────────

    public function fetchPendingObservations(string $ttvTypeKey, array $def, string $dateFrom, string $dateTo): array
    {
        $dbCol   = $def['db_column'];
        $stTable = $def['state_table'];
        $idCol   = $def['state_id_col'] ?? 'id_observation';

        // Build a dynamic UNION query to get both Ralan and Ranap data
        // Only select rows where the specific TTV column is NOT NULL/empty, and no synced ID exists.
        $sql = "
            SELECT * FROM (
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

        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo, 'df2' => $dateFrom, 'dt2' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function saveObservationTTV(string $stTable, string $idCol, string $noRawat, string $tgl, string $jam, string $statusRawat, string $idObservation): bool
    {
        // Table schema: no_rawat, tgl_perawatan, jam_rawat, status, id_observation
        $sql = "INSERT INTO {$stTable} (no_rawat, tgl_perawatan, jam_rawat, status, {$idCol}) 
                VALUES (:nr, :tgl, :jam, :st, :id) 
                ON DUPLICATE KEY UPDATE {$idCol} = :id";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'nr'  => $noRawat,
            'tgl' => $tgl,
            'jam' => $jam,
            'st'  => $statusRawat,
            'id'  => $idObservation
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
                ON DUPLICATE KEY UPDATE id_procedure = :id";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'nr' => $noRawat,
            'kd' => $kode,
            'st' => $status,
            'id' => $idProcedure
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
                ON DUPLICATE KEY UPDATE id_allergy_intolerance = :id";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'nr'  => $noRawat,
            'tgl' => $tglPerawatan,
            'jam' => $jamRawat,
            'st'  => $statusRawat,
            'id'  => $idAllergy
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
                ON DUPLICATE KEY UPDATE id_immunization = :id";
        $stmt = $this->mysql->prepare($sql);
        return $stmt->execute([
            'nr'        => $noRawat,
            'tgl'       => $tglPerawatan,
            'jam'       => $jam,
            'kode_brng' => $kodeBrng,
            'no_batch'  => $noBatch,
            'no_faktur' => $noFaktur,
            'id'        => $idImmunization
        ]);
    }
}
