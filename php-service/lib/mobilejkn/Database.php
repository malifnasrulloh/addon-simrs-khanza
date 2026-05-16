<?php

/**
 * Database - PDO wrapper with optimized queries for Mobile JKN Sync.
 *
 * All queries use parameterized prepared statements (no SQL injection).
 * N+1 queries eliminated via batch LEFT JOINs and correlated subqueries.
 *
 * @author  malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class MobileJknDatabase
{
    private PDO $pdo;
    private Logger $log;

    public function __construct(MobileJknConfig $config, Logger $log)
    {
        $this->log = $log;

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->dbHost,
            $config->dbPort,
            $config->dbName
        );

        $this->log->info("[DB] Connecting to {$config->dbHost}:{$config->dbPort}/{$config->dbName}...");

        $this->pdo = new PDO($dsn, $config->dbUser, $config->dbPass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]);

        $this->log->info("[DB] Connection established.");
    }

    /**
     * Close the PDO connection.
     */
    public function close(): void
    {
        unset($this->pdo);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Block 0: Global Sync
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Fetch all JKN and Non-JKN patients for the given date range that have been registered.
     * Only JKN patients with statuskirim='Sudah' and all Non-JKN patients are considered.
     */
    public function fetchAllPatientsForSync(string $dateFrom, string $dateTo, string $kodeBpjsPayer): array
    {
        $sql = <<<'SQL'
SELECT 
    r.nobooking, 
    r.no_rawat, 
    (SELECT GROUP_CONCAT(DISTINCT t.taskid ORDER BY t.taskid) FROM referensi_mobilejkn_bpjs_taskid t WHERE t.no_rawat = r.no_rawat) AS sent_taskids
FROM referensi_mobilejkn_bpjs r
WHERE r.tanggalperiksa BETWEEN :date_from_jkn AND :date_to_jkn
  AND r.statuskirim = 'Sudah'

UNION ALL

SELECT 
    rp.no_rawat AS nobooking, 
    rp.no_rawat, 
    (SELECT GROUP_CONCAT(DISTINCT t.taskid ORDER BY t.taskid) FROM referensi_mobilejkn_bpjs_taskid t WHERE t.no_rawat = rp.no_rawat) AS sent_taskids
FROM reg_periksa rp
INNER JOIN dokter d ON rp.kd_dokter = d.kd_dokter
INNER JOIN poliklinik pol ON rp.kd_poli = pol.kd_poli
LEFT JOIN maping_dokter_dpjpvclaim md ON md.kd_dokter = rp.kd_dokter
LEFT JOIN maping_poli_bpjs mp ON mp.kd_poli_rs = rp.kd_poli
WHERE rp.tgl_registrasi BETWEEN :date_from_njkn AND :date_to_njkn
  AND rp.kd_pj <> :kd_bpjs
  AND md.kd_dokter_bpjs IS NOT NULL AND md.kd_dokter_bpjs <> ''
  AND mp.kd_poli_bpjs IS NOT NULL AND mp.kd_poli_bpjs <> ''
  AND rp.no_rawat NOT IN (
      SELECT rmb.no_rawat FROM referensi_mobilejkn_bpjs rmb
      WHERE rmb.tanggalperiksa BETWEEN :date_from_sub AND :date_to_sub
  )
SQL;
        $this->log->debug("[SQL] fetchAllPatientsForSync: {$dateFrom} → {$dateTo}");
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'date_from_jkn'  => $dateFrom,
            'date_to_jkn'    => $dateTo,
            'date_from_njkn' => $dateFrom,
            'date_to_njkn'   => $dateTo,
            'kd_bpjs'        => $kodeBpjsPayer,
            'date_from_sub'  => $dateFrom,
            'date_to_sub'    => $dateTo,
        ]);
        return $stmt->fetchAll();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Block 1: Unsent JKN Bookings (statuskirim = 'Belum')
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Fetch JKN bookings not yet sent to BPJS.
     *
     * @return array[] List of booking rows
     */
    public function fetchUnsentJknBookings(string $dateFrom, string $dateTo): array
    {
        $sql = <<<'SQL'
SELECT
    r.nobooking, r.no_rawat, rp.no_rkm_medis, p.nm_pasien,
    r.nohp, r.nomorkartu, r.nik, r.tanggalperiksa,
    pol.nm_poli, d.nm_dokter, r.jampraktek,
    r.jeniskunjungan, r.nomorreferensi, r.status, r.validasi,
    r.kodepoli, r.pasienbaru, r.kodedokter,
    r.nomorantrean, r.angkaantrean, r.estimasidilayani,
    r.sisakuotajkn, r.kuotajkn, r.sisakuotanonjkn, r.kuotanonjkn
FROM referensi_mobilejkn_bpjs r
INNER JOIN reg_periksa rp ON r.no_rawat = rp.no_rawat
INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
INNER JOIN poliklinik pol ON rp.kd_poli = pol.kd_poli
INNER JOIN dokter d ON rp.kd_dokter = d.kd_dokter
WHERE r.statuskirim = 'Belum'
  AND r.tanggalperiksa BETWEEN :date_from AND :date_to
ORDER BY r.tanggalperiksa
SQL;
        $this->log->debug("[SQL] fetchUnsentJknBookings: {$dateFrom} → {$dateTo}");
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);
        return $stmt->fetchAll();
    }

    /**
     * Mark a JKN booking as sent.
     */
    public function markBookingAsSent(string $nobooking): bool
    {
        $sql = "UPDATE referensi_mobilejkn_bpjs SET statuskirim = 'Sudah' WHERE nobooking = :nobooking";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(['nobooking' => $nobooking]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Block 2: Pending Cancellations
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Fetch unsent cancellation records.
     */
    public function fetchPendingCancellations(string $dateFrom, string $dateTo): array
    {
        $sql = <<<'SQL'
SELECT *
FROM referensi_mobilejkn_bpjs_batal
WHERE statuskirim = 'Belum'
  AND tanggalbatal BETWEEN :date_from AND :date_to
SQL;
        $this->log->debug("[SQL] fetchPendingCancellations: {$dateFrom} → {$dateTo}");
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);
        return $stmt->fetchAll();
    }

    /**
     * Mark a cancellation record as sent.
     */
    public function markCancellationAsSent(string $nomorreferensi): bool
    {
        $sql = "UPDATE referensi_mobilejkn_bpjs_batal SET statuskirim = 'Sudah' WHERE nomorreferensi = :ref";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(['ref' => $nomorreferensi]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Block 3: JKN Patients with Task Data (N+1 eliminated)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Fetch checked-in JKN bookings with ALL task trigger data in a single query.
     * Eliminates the N+1 problem: instead of 6 queries per patient, 1 query total.
     *
     * Task sources use cascading merge (ERM → Original) for maximum delivery success:
     *   Task 3: validasi (check-in time) → mutasi_berkas.dikirim (file sent)
     *   Task 4: pemeriksaan_ralan (exam start) → mutasi_berkas.diterima (file received)
     *   Task 5: mutasi_berkas.kembali (file returned) → reg_periksa.stts=Sudah → pemeriksaan_ralan
     *   Task 6: resep_obat (prescription created)
     *   Task 7: resep_obat.tgl_penyerahan (prescription dispensed)
     *
     * @return array[] Each row contains nobooking, no_rawat, task3..task99 timestamps, sent_taskids
     */
    public function fetchJknPatientsWithTaskData(string $dateFrom, string $dateTo): array
    {
        $sql = <<<'SQL'
SELECT
    r.nobooking,
    r.no_rawat,
    -- Task 3: Check-in validation time (ERM) → File sent to polyclinic (Original)
    COALESCE(
        NULLIF(r.validasi, ''),
        (SELECT mb.dikirim
         FROM mutasi_berkas mb
         WHERE mb.no_rawat = r.no_rawat AND mb.dikirim <> '0000-00-00 00:00:00'
         LIMIT 1)
    ) AS task3_waktu,
    -- Task 4: Exam start (ERM) → File received at polyclinic (Original)
    COALESCE(
        (SELECT CONCAT(pr.tgl_perawatan, ' ', pr.jam_rawat)
         FROM pemeriksaan_ralan pr
         WHERE pr.no_rawat = r.no_rawat
         LIMIT 1),
        (SELECT mb.diterima
         FROM mutasi_berkas mb
         WHERE mb.no_rawat = r.no_rawat AND mb.diterima <> '0000-00-00 00:00:00'
         LIMIT 1)
    ) AS task4_waktu,
    -- Task 5: File returned (ERM) → Visit completed (ERM) → Exam record (Original)
    COALESCE(
        (SELECT IF(mb.kembali = '0000-00-00 00:00:00', NULL, mb.kembali)
         FROM mutasi_berkas mb
         WHERE mb.no_rawat = r.no_rawat
         LIMIT 1),
        (SELECT NOW()
         FROM reg_periksa rp
         WHERE rp.no_rawat = r.no_rawat AND rp.stts = 'Sudah'
         LIMIT 1),
        (SELECT CONCAT(pr.tgl_perawatan, ' ', pr.jam_rawat)
         FROM pemeriksaan_ralan pr
         WHERE pr.no_rawat = r.no_rawat
         LIMIT 1)
    ) AS task5_waktu,
    -- Farmasi: Prescription number (for /antrean/farmasi/add)
    (SELECT ro.no_resep
     FROM resep_obat ro
     WHERE ro.no_rawat = r.no_rawat
     LIMIT 1) AS no_resep,
    -- Task 6: Prescription created (outpatient only)
    (SELECT CONCAT(ro.tgl_perawatan, ' ', ro.jam)
     FROM resep_obat ro
     WHERE ro.no_rawat = r.no_rawat
       AND ro.tgl_perawatan <> '0000-00-00'
       AND ro.status = 'ralan'
     LIMIT 1) AS task6_waktu,
    -- Task 7: Prescription dispensed
    (SELECT CONCAT(ro.tgl_penyerahan, ' ', ro.jam_penyerahan)
     FROM resep_obat ro
     WHERE ro.no_rawat = r.no_rawat
       AND ro.status = 'ralan'
       AND CONCAT(ro.tgl_penyerahan, ' ', ro.jam_penyerahan) <> '0000-00-00 00:00:00'
     LIMIT 1) AS task7_waktu,
    -- Task 99: Visit cancelled?
    (SELECT rp.stts
     FROM reg_periksa rp
     WHERE rp.no_rawat = r.no_rawat AND rp.stts = 'Batal'
     LIMIT 1) AS is_cancelled,
    -- Already-sent task IDs (for skip/retry logic)
    (SELECT GROUP_CONCAT(DISTINCT t.taskid ORDER BY t.taskid)
     FROM referensi_mobilejkn_bpjs_taskid t
     WHERE t.no_rawat = r.no_rawat) AS sent_taskids
FROM referensi_mobilejkn_bpjs r
WHERE r.status = 'Checkin'
  AND r.tanggalperiksa BETWEEN :date_from AND :date_to
ORDER BY r.tanggalperiksa
SQL;
        $this->log->debug("[SQL] fetchJknPatientsWithTaskData: {$dateFrom} → {$dateTo}");
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);
        return $stmt->fetchAll();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Block 4: Non-JKN Patients (with schedule + BPJS mapping, N+1 eliminated)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Fetch Non-JKN patients with schedule, BPJS mappings, and task data.
     * Eliminates the inner N+1 for jadwal, dokter mapping, poli mapping.
     *
     * Task sources use cascading merge (ERM → Original) matching JKN behavior:
     *   Task 3: Calculated MAX(reg_time, schedule_start) → mutasi_berkas.dikirim
     *   Task 4: pemeriksaan_ralan → mutasi_berkas.diterima
     *   Task 5: mutasi_berkas.kembali → stts=Sudah → pemeriksaan_ralan
     *
     * @param string $hari Indonesian day name (e.g. "SENIN")
     */
    public function fetchNonJknPatientsWithTaskData(
        string $dateFrom,
        string $dateTo,
        string $hari,
        string $kodeBpjsPayer
    ): array {
        $sql = <<<'SQL'
SELECT
    rp.no_reg, rp.no_rawat, rp.tgl_registrasi, rp.jam_reg,
    rp.kd_dokter, d.nm_dokter,
    rp.kd_poli, pol.nm_poli,
    rp.stts_daftar, rp.no_rkm_medis, rp.kd_pj,
    j.jam_mulai, j.jam_selesai, j.kuota,
    md.kd_dokter_bpjs,
    mp.kd_poli_bpjs,
    -- Task 3: Calculated registration/schedule time → File sent (cascading)
    COALESCE(
        (SELECT IF(
            CONCAT(rp.tgl_registrasi, ' ', rp.jam_reg) > CONCAT(rp.tgl_registrasi, ' ', j.jam_mulai),
            CONCAT(rp.tgl_registrasi, ' ', rp.jam_reg),
            CONCAT(rp.tgl_registrasi, ' ', j.jam_mulai)
        )),
        (SELECT mb.dikirim FROM mutasi_berkas mb
         WHERE mb.no_rawat = rp.no_rawat AND mb.dikirim <> '0000-00-00 00:00:00'
         LIMIT 1)
    ) AS task3_waktu,
    -- Task 4: Exam start → File received (cascading)
    COALESCE(
        (SELECT CONCAT(pr.tgl_perawatan, ' ', pr.jam_rawat) FROM pemeriksaan_ralan pr
         WHERE pr.no_rawat = rp.no_rawat LIMIT 1),
        (SELECT IF(mb.diterima = '0000-00-00 00:00:00', NULL, mb.diterima) FROM mutasi_berkas mb
         WHERE mb.no_rawat = rp.no_rawat LIMIT 1)
    ) AS task4_waktu,
    -- Task 5: File returned → Visit completed → Exam record (cascading)
    COALESCE(
        (SELECT IF(mb.kembali = '0000-00-00 00:00:00', NULL, mb.kembali) FROM mutasi_berkas mb
         WHERE mb.no_rawat = rp.no_rawat LIMIT 1),
        (SELECT NOW() FROM reg_periksa rp2
         WHERE rp2.no_rawat = rp.no_rawat AND rp2.stts = 'Sudah' LIMIT 1),
        (SELECT CONCAT(pr.tgl_perawatan, ' ', pr.jam_rawat) FROM pemeriksaan_ralan pr
         WHERE pr.no_rawat = rp.no_rawat LIMIT 1)
    ) AS task5_waktu,
    (SELECT ro.no_resep FROM resep_obat ro
     WHERE ro.no_rawat = rp.no_rawat LIMIT 1) AS no_resep,
    (SELECT CONCAT(ro.tgl_perawatan, ' ', ro.jam) FROM resep_obat ro
     WHERE ro.no_rawat = rp.no_rawat AND ro.tgl_perawatan <> '0000-00-00' AND ro.status = 'ralan'
     LIMIT 1) AS task6_waktu,
    (SELECT CONCAT(ro.tgl_penyerahan, ' ', ro.jam_penyerahan) FROM resep_obat ro
     WHERE ro.no_rawat = rp.no_rawat AND ro.status = 'ralan'
       AND CONCAT(ro.tgl_penyerahan, ' ', ro.jam_penyerahan) <> '0000-00-00 00:00:00'
     LIMIT 1) AS task7_waktu,
    (SELECT rp2.stts FROM reg_periksa rp2
     WHERE rp2.no_rawat = rp.no_rawat AND rp2.stts = 'Batal' LIMIT 1) AS is_cancelled,
    (SELECT GROUP_CONCAT(DISTINCT t.taskid ORDER BY t.taskid) FROM referensi_mobilejkn_bpjs_taskid t
     WHERE t.no_rawat = rp.no_rawat) AS sent_taskids
FROM reg_periksa rp
INNER JOIN dokter d ON rp.kd_dokter = d.kd_dokter
INNER JOIN poliklinik pol ON rp.kd_poli = pol.kd_poli
INNER JOIN jadwal j ON j.hari_kerja = :hari AND j.kd_dokter = rp.kd_dokter AND j.kd_poli = rp.kd_poli
LEFT JOIN maping_dokter_dpjpvclaim md ON md.kd_dokter = rp.kd_dokter
LEFT JOIN maping_poli_bpjs mp ON mp.kd_poli_rs = rp.kd_poli
WHERE rp.tgl_registrasi BETWEEN :date_from AND :date_to
  AND rp.kd_pj <> :kd_bpjs
  AND md.kd_dokter_bpjs IS NOT NULL AND md.kd_dokter_bpjs <> ''
  AND mp.kd_poli_bpjs IS NOT NULL AND mp.kd_poli_bpjs <> ''
  AND rp.no_rawat NOT IN (
      SELECT rmb.no_rawat FROM referensi_mobilejkn_bpjs rmb
      WHERE rmb.tanggalperiksa BETWEEN :date_from2 AND :date_to2
  )
ORDER BY CONCAT(rp.tgl_registrasi, ' ', rp.jam_reg)
SQL;
        $this->log->debug("[SQL] fetchNonJknPatientsWithTaskData: {$dateFrom} → {$dateTo}, hari={$hari}");
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'hari'       => $hari,
            'date_from'  => $dateFrom,
            'date_to'    => $dateTo,
            'kd_bpjs'    => $kodeBpjsPayer,
            'date_from2' => $dateFrom,
            'date_to2'   => $dateTo,
        ]);
        return $stmt->fetchAll();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Task ID CRUD
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Insert a task ID record. Uses INSERT IGNORE for idempotency.
     *
     * @return bool True if a new row was inserted (task not yet sent),
     *              false if it already existed (task already sent successfully).
     */
    public function insertTaskId(string $noRawat, string $taskId, string $waktu): bool
    {
        $sql = "INSERT IGNORE INTO referensi_mobilejkn_bpjs_taskid (no_rawat, taskid, waktu) VALUES (:no_rawat, :taskid, :waktu)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['no_rawat' => $noRawat, 'taskid' => $taskId, 'waktu' => $waktu]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a task ID record (rollback on API failure, allows retry next cycle).
     */
    public function deleteTaskId(string $noRawat, string $taskId): bool
    {
        $sql = "DELETE FROM referensi_mobilejkn_bpjs_taskid WHERE no_rawat = :no_rawat AND taskid = :taskid";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(['no_rawat' => $noRawat, 'taskid' => $taskId]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Lookup Helpers
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Get the BPJS payer code from password_asuransi table.
     */
    public function fetchBpjsPayerCode(): string
    {
        $sql = "SELECT kd_pj FROM password_asuransi LIMIT 1";
        $stmt = $this->pdo->query($sql);
        $row = $stmt->fetch();
        return $row['kd_pj'] ?? '';
    }

    /**
     * Check if a prescription is racikan (compounded) or non-racikan.
     *
     * @return string "Racikan" or "Non Racikan"
     */
    public function fetchResepType(string $noResep): string
    {
        $sql = "SELECT COUNT(*) AS cnt FROM resep_dokter_racikan WHERE no_resep = :no_resep";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['no_resep' => $noResep]);
        $row = $stmt->fetch();
        return ((int)($row['cnt'] ?? 0)) > 0 ? 'Racikan' : 'Non Racikan';
    }
}
