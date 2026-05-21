<?php
/**
 * Database — PDO wrapper for Mobile JKN Sync. Matches Java robot queries exactly.
 *
 * @author malifnasrulloh (converted from Java by Antigravity)
 */
declare(strict_types=1);

class MobileJknDatabase
{
    private PDO $pdo;
    private Logger $log;

    // Indonesian day-of-week map (ISO-8601: 1=Monday, 7=Sunday)
    private const HARI_MAP = [1=>'SENIN',2=>'SELASA',3=>'RABU',4=>'KAMIS',5=>'JUMAT',6=>'SABTU',7=>'AKHAD'];

    public function __construct(MobileJknConfig $config, Logger $log)
    {
        $this->log = $log;
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->dbHost, $config->dbPort, $config->dbName);
        $this->log->info("[DB] Connecting to {$config->dbHost}:{$config->dbPort}/{$config->dbName}...");
        $this->pdo = new PDO($dsn, $config->dbUser, $config->dbPass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]);
        $this->log->info("[DB] Connection established.");
    }

    public function close(): void { unset($this->pdo); }

    /**
     * Get Indonesian day name for a given date string.
     * Java robot calculates hCari per patient's tgl_registrasi, not today.
     */
    public function hariForDate(string $date): string
    {
        $dow = (int) date('N', strtotime($date)); // 1=Mon, 7=Sun
        return self::HARI_MAP[$dow] ?? 'SENIN';
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Block 1: Unsent JKN Bookings (statuskirim = 'Belum')
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Fetch JKN bookings not yet sent to BPJS.
     * Matches Java ANTROL-ROBOT.JAVA lines 73–86.
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
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function markBookingAsSent(string $nobooking): bool
    {
        $sql = "UPDATE referensi_mobilejkn_bpjs SET statuskirim = 'Sudah' WHERE nobooking = :nb";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(['nb' => $nobooking]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Block 2: Pending Cancellations
    // ═══════════════════════════════════════════════════════════════════════

    public function fetchPendingCancellations(string $dateFrom, string $dateTo): array
    {
        $sql = <<<'SQL'
SELECT * FROM referensi_mobilejkn_bpjs_batal
WHERE statuskirim = 'Belum'
  AND date_format(tanggalbatal,'%Y-%m-%d') BETWEEN :df AND :dt
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    public function markCancellationAsSent(string $nomorreferensi): bool
    {
        $sql = "UPDATE referensi_mobilejkn_bpjs_batal SET statuskirim = 'Sudah' WHERE nomorreferensi = :ref";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(['ref' => $nomorreferensi]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Block 3: JKN Patients with statuskirim='Sudah' — task chain processing
    // Matches Java ANTROL-ROBOT.JAVA lines 227–239 (main JKN query)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Fetch checked-in JKN patients for task chain processing.
     * Returns patients with their current task state from referensi_mobilejkn_bpjs_taskid.
     * The task chain logic (3→4→5→farmasi→6→7) is handled in QueueProcessor.
     */
    public function fetchJknPatientsForTasks(string $dateFrom, string $dateTo): array
    {
        $sql = <<<'SQL'
SELECT
    r.nobooking, r.no_rawat,
    rp.tgl_registrasi, rp.jam_reg, rp.kd_dokter, rp.kd_poli, rp.stts
FROM referensi_mobilejkn_bpjs r
INNER JOIN reg_periksa rp ON rp.no_rawat = r.no_rawat
INNER JOIN dokter d ON rp.kd_dokter = d.kd_dokter
INNER JOIN poliklinik pol ON rp.kd_poli = pol.kd_poli
WHERE r.statuskirim = 'Sudah'
  AND r.tanggalperiksa BETWEEN :df AND :dt
ORDER BY r.tanggalperiksa
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo]);
        return $stmt->fetchAll();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Block 4: Missing On-Site Patients (ALL patients not in referensi table)
    // Matches Java ANTROL-ROBOT.JAVA lines 696–701 exactly:
    //   NO kd_pj filter, NO status_lanjut filter, NO IGDK filter
    //   Java fetches ALL, then checks per-patient in loop
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Fetch ALL patients registered but missing from referensi_mobilejkn_bpjs.
     * Matches Java robot query exactly — no payer filter.
     * The kd_pj check (BPJ vs non-BPJ) happens per-patient in QueueProcessor.
     */
    public function fetchMissingOnsitePatients(string $dateFrom, string $dateTo): array
    {
        $sql = <<<'SQL'
SELECT
    rp.no_reg, rp.no_rawat, rp.tgl_registrasi, rp.jam_reg,
    rp.kd_dokter, d.nm_dokter,
    rp.kd_poli, pol.nm_poli,
    rp.stts_daftar, rp.no_rkm_medis, rp.kd_pj, rp.stts,
    p.no_ktp, p.no_peserta, p.no_tlp
FROM reg_periksa rp
INNER JOIN dokter d ON rp.kd_dokter = d.kd_dokter
INNER JOIN poliklinik pol ON rp.kd_poli = pol.kd_poli
INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
WHERE rp.tgl_registrasi BETWEEN :df AND :dt
  AND rp.no_rawat NOT IN (
      SELECT rmb.no_rawat FROM referensi_mobilejkn_bpjs rmb
      WHERE rmb.tanggalperiksa BETWEEN :df2 AND :dt2
  )
ORDER BY CONCAT(rp.tgl_registrasi, ' ', rp.jam_reg)
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['df'=>$dateFrom,'dt'=>$dateTo,'df2'=>$dateFrom,'dt2'=>$dateTo]);
        return $stmt->fetchAll();
    }

    /**
     * Fetch jadwal (schedule) for a doctor+poli+day combination.
     * Matches Java ANTROL-ROBOT.JAVA line 736.
     */
    public function fetchJadwal(string $hari, string $kdDokter, string $kdPoli): ?array
    {
        $sql = "SELECT * FROM jadwal WHERE hari_kerja=:h AND kd_dokter=:d AND kd_poli=:p LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['h' => $hari, 'd' => $kdDokter, 'p' => $kdPoli]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Fetch SEP reference number for a patient.
     * Java: noskdp first, fallback to no_rujukan.
     */
    public function fetchNomorReferensi(string $noRawat): string
    {
        $sql = "SELECT noskdp, no_rujukan FROM bridging_sep WHERE no_rawat = :nr ORDER BY noskdp DESC LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['nr' => $noRawat]);
        $row = $stmt->fetch();
        if (!$row) return '';
        $noskdp = trim($row['noskdp'] ?? '');
        return $noskdp !== '' ? $noskdp : ($row['no_rujukan'] ?? '');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Task ID State — per-patient task tracking
    // Matches Java: referensi_mobilejkn_bpjs_taskid table
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Load all task states for a patient.
     * Java ANTROL-ROBOT.JAVA lines 246–274: query taskid + SUBSTRING(waktu,1,19)
     *
     * @return array ['3'=>'Sudah', '4'=>'', ...] + ['waktu_3'=>'2025-...', ...]
     */
    public function loadTaskState(string $noRawat): array
    {
        $state = ['3'=>'','4'=>'','5'=>'','6'=>'','7'=>'','99'=>''];

        $sql = "SELECT taskid, SUBSTRING(waktu, 1, 19) as waktu FROM referensi_mobilejkn_bpjs_taskid WHERE no_rawat = :nr";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['nr' => $noRawat]);

        while ($row = $stmt->fetch()) {
            $tid = (string) $row['taskid'];
            $state[$tid] = 'Sudah';
            $state["waktu_{$tid}"] = $row['waktu'];
        }

        return $state;
    }

    /**
     * Insert task ID. Uses INSERT IGNORE for idempotency (Java: menyimpantf2).
     * @return bool True if new row inserted, false if already existed.
     */
    public function insertTaskId(string $noRawat, string $taskId, string $waktu): bool
    {
        $sql = "INSERT IGNORE INTO referensi_mobilejkn_bpjs_taskid (no_rawat, taskid, waktu) VALUES (:nr, :tid, :w)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['nr' => $noRawat, 'tid' => $taskId, 'w' => $waktu]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete task ID on API failure (rollback for retry).
     * Java: Sequel.queryu2("delete from referensi_mobilejkn_bpjs_taskid where taskid='X' and no_rawat='...'")
     */
    public function deleteTaskId(string $noRawat, string $taskId): bool
    {
        $sql = "DELETE FROM referensi_mobilejkn_bpjs_taskid WHERE no_rawat = :nr AND taskid = :tid";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(['nr' => $noRawat, 'tid' => $taskId]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Lookup Helpers
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Get the BPJS payer code. Returns 'BPJ' (hardcoded as confirmed from DB).
     */
    public function fetchBpjsPayerCode(): string
    {
        return 'BPJ';
    }

    /**
     * Per-patient BPJS doctor mapping lookup.
     * Matches Java robot: Sequel.cariIsi("select maping_dokter_dpjpvclaim.kd_dokter_bpjs ...")
     */
    public function fetchDokterBpjs(string $kdDokter): string
    {
        $sql = "SELECT kd_dokter_bpjs FROM maping_dokter_dpjpvclaim WHERE kd_dokter = :kd LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['kd' => $kdDokter]);
        $row = $stmt->fetch();
        return $row['kd_dokter_bpjs'] ?? '';
    }

    /**
     * Per-patient BPJS polyclinic mapping lookup.
     * Matches Java robot: Sequel.cariIsi("select maping_poli_bpjs.kd_poli_bpjs ...")
     */
    public function fetchPoliBpjs(string $kdPoli): string
    {
        $sql = "SELECT kd_poli_bpjs FROM maping_poli_bpjs WHERE kd_poli_rs = :kd LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['kd' => $kdPoli]);
        $row = $stmt->fetch();
        return $row['kd_poli_bpjs'] ?? '';
    }

    /**
     * Get task 3 waktu:
     *  1. Try mutasi_berkas.dikirim (real check-in file transfer time)
     *  2. Fallback: use jam_reg (actual registration time — unique per patient)
     *
     * This is the NON-JKN task 3 source and also the fallback for JKN.
     */
    public function resolveTask3Waktu(string $noRawat, string $tglRegistrasi, string $jamMulai): string
    {
        // Step 1: Try mutasi_berkas.dikirim (Java ANTROL-ROBOT.JAVA line 751)
        $sql = "SELECT dikirim FROM mutasi_berkas WHERE no_rawat = :nr AND dikirim <> '0000-00-00 00:00:00' LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['nr' => $noRawat]);
        $row = $stmt->fetch();
        $dikirim = $row['dikirim'] ?? '';
        if (!empty($dikirim) && !str_starts_with($dikirim, '0000')) {
            return $dikirim;
        }

        // Step 2: Fallback — use jam_reg (actual registration time)
        // Each patient has a unique jam_reg, no artificial polyclinic-open grouping
        $sql2 = "SELECT CONCAT(:tgl, ' ', jam_reg) as waktu FROM reg_periksa WHERE no_rawat = :nr";
        $stmt = $this->pdo->prepare($sql2);
        $stmt->execute(['tgl' => $tglRegistrasi, 'nr' => $noRawat]);
        $row = $stmt->fetch();
        return $row['waktu'] ?? '';
    }

    /**
     * Get task 3 waktu for JKN patients.
     * Try validasi first (Mobile JKN check-in), then fallback to resolveTask3Waktu.
     */
    public function resolveTask3WaktuJkn(string $noRawat, string $tglRegistrasi, string $jamMulai): string
    {
        // Try validasi first (JKN patients that checked in via Mobile JKN app)
        $sql = "SELECT validasi FROM referensi_mobilejkn_bpjs WHERE no_rawat = :nr AND validasi IS NOT NULL AND validasi <> '0000-00-00 00:00:00' LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['nr' => $noRawat]);
        $row = $stmt->fetch();
        $validasi = $row['validasi'] ?? '';
        if (!empty($validasi) && !str_starts_with($validasi, '0000')) {
            return $validasi;
        }
        // Fallback to mutasi_berkas.dikirim → jam_reg/jam_mulai
        return $this->resolveTask3Waktu($noRawat, $tglRegistrasi, $jamMulai);
    }

    /**
     * Get task 4 waktu from mutasi_berkas.diterima.
     * Java log line 58: "select mutasi_berkas.diterima..."
     */
    public function resolveTask4Waktu(string $noRawat): string
    {
        $sql = "SELECT diterima FROM mutasi_berkas WHERE no_rawat = :nr AND diterima <> '0000-00-00 00:00:00' LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['nr' => $noRawat]);
        $row = $stmt->fetch();
        return $row['diterima'] ?? '';
    }

    /**
     * Get task 5 waktu from pemeriksaan_ralan.
     * Java log line 68: "select concat(pemeriksaan_ralan.tgl_perawatan,' ',pemeriksaan_ralan.jam_rawat)..."
     */
    public function resolveTask5Waktu(string $noRawat): string
    {
        $sql = "SELECT CONCAT(tgl_perawatan, ' ', jam_rawat) as waktu FROM pemeriksaan_ralan WHERE no_rawat = :nr LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['nr' => $noRawat]);
        $row = $stmt->fetch();
        return $row['waktu'] ?? '';
    }

    /**
     * Get task 6 waktu from resep_obat (prescription created).
     * Java log line 98: "select concat(resep_obat.tgl_perawatan,' ',resep_obat.jam)..."
     */
    public function resolveTask6Waktu(string $noRawat): string
    {
        $sql = "SELECT CONCAT(tgl_perawatan, ' ', jam) as waktu FROM resep_obat WHERE tgl_perawatan <> '0000-00-00' AND status = 'ralan' AND no_rawat = :nr LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['nr' => $noRawat]);
        $row = $stmt->fetch();
        return $row['waktu'] ?? '';
    }

    /**
     * Get task 7 waktu from resep_obat (prescription dispensed).
     * Java log line 103: "select concat(resep_obat.tgl_penyerahan,' ',resep_obat.jam_penyerahan)..."
     */
    public function resolveTask7Waktu(string $noRawat): string
    {
        $sql = "SELECT CONCAT(tgl_penyerahan, ' ', jam_penyerahan) as waktu FROM resep_obat WHERE status = 'ralan' AND no_rawat = :nr AND CONCAT(tgl_penyerahan, ' ', jam_penyerahan) <> '0000-00-00 00:00:00' LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['nr' => $noRawat]);
        $row = $stmt->fetch();
        return $row['waktu'] ?? '';
    }

    /**
     * Get resep_obat.no_resep for a patient.
     * Java: Sequel.cariIsi("select resep_obat.no_resep from resep_obat where no_rawat=?")
     */
    public function fetchNoResep(string $noRawat): string
    {
        $sql = "SELECT no_resep FROM resep_obat WHERE no_rawat = :nr LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['nr' => $noRawat]);
        $row = $stmt->fetch();
        return $row['no_resep'] ?? '';
    }

    /**
     * Check if a prescription is racikan (compounded).
     * Java: Sequel.cariInteger("select count(*) from resep_dokter_racikan where no_resep=?") > 0
     */
    public function isRacikan(string $noResep): bool
    {
        $sql = "SELECT COUNT(*) AS cnt FROM resep_dokter_racikan WHERE no_resep = :nr";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['nr' => $noResep]);
        $row = $stmt->fetch();
        return ((int)($row['cnt'] ?? 0)) > 0;
    }

    /**
     * Get resep type string for API payload.
     */
    public function fetchResepType(string $noResep): string
    {
        return $this->isRacikan($noResep) ? 'Racikan' : 'Non Racikan';
    }

    /**
     * Check if patient is cancelled.
     * Java log line 108: "select now() from reg_periksa where stts='Batal' and no_rawat=?"
     */
    public function isCancelled(string $noRawat): bool
    {
        $sql = "SELECT 1 FROM reg_periksa WHERE stts = 'Batal' AND no_rawat = :nr LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['nr' => $noRawat]);
        return $stmt->fetch() !== false;
    }
}

