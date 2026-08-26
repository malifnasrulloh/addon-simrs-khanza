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
    r.nobooking, r.no_rawat, r.norm as no_rkm_medis, p.nm_pasien,
    r.nohp, r.nomorkartu, r.nik, r.tanggalperiksa,
    COALESCE(mp.nm_poli_bpjs, '') as nm_poli, COALESCE(md.nm_dokter_bpjs, '') as nm_dokter, r.jampraktek,
    r.jeniskunjungan, r.nomorreferensi, r.status, r.validasi,
    r.kodepoli, r.pasienbaru, r.kodedokter,
    r.nomorantrean, r.angkaantrean, r.estimasidilayani,
    r.sisakuotajkn, r.kuotajkn, r.sisakuotanonjkn, r.kuotanonjkn
FROM referensi_mobilejkn_bpjs r
INNER JOIN pasien p ON r.norm = p.no_rkm_medis
LEFT JOIN maping_poli_bpjs mp ON r.kodepoli = mp.kd_poli_bpjs
LEFT JOIN maping_dokter_dpjpvclaim md ON r.kodedokter = md.kd_dokter_bpjs
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
     *
     * LEFT JOIN on dokter/poliklinik ensures patients with missing master records
     * are still fetched (BUG-D: zero patient loss).
     */
    public function fetchJknPatientsForTasks(string $dateFrom, string $dateTo): array
    {
        $sql = <<<'SQL'
SELECT
    r.nobooking, r.no_rawat,
    rp.tgl_registrasi, rp.jam_reg, rp.kd_dokter, rp.kd_poli, rp.stts,
    COALESCE(d.nm_dokter, '') as nm_dokter,
    COALESCE(pol.nm_poli, '') as nm_poli
FROM referensi_mobilejkn_bpjs r
INNER JOIN reg_periksa rp ON rp.no_rawat = r.no_rawat
LEFT JOIN dokter d ON rp.kd_dokter = d.kd_dokter
LEFT JOIN poliklinik pol ON rp.kd_poli = pol.kd_poli
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
     *
     * LEFT JOIN on dokter/poliklinik/pasien ensures patients with missing master
     * records are still fetched (BUG-D: zero patient loss). COALESCE provides
     * safe defaults for nullable columns.
     *
     * Excludes cancelled patients (taskid=99) to prevent re-processing (BUG-A).
     * The kd_pj check (BPJ vs non-BPJ) happens per-patient in QueueProcessor.
     */
    public function fetchMissingOnsitePatients(string $dateFrom, string $dateTo): array
    {
        $sql = <<<'SQL'
SELECT
    rp.no_reg, rp.no_rawat, rp.tgl_registrasi, rp.jam_reg,
    rp.kd_dokter, COALESCE(d.nm_dokter, '') as nm_dokter,
    rp.kd_poli, COALESCE(pol.nm_poli, '') as nm_poli,
    rp.stts_daftar, rp.no_rkm_medis, rp.kd_pj, rp.stts,
    COALESCE(p.no_ktp, '-') as no_ktp, COALESCE(p.no_peserta, '') as no_peserta, COALESCE(p.no_tlp, '-') as no_tlp
FROM reg_periksa rp
LEFT JOIN dokter d ON rp.kd_dokter = d.kd_dokter
LEFT JOIN poliklinik pol ON rp.kd_poli = pol.kd_poli
LEFT JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
WHERE rp.tgl_registrasi BETWEEN :df AND :dt
  AND rp.no_rawat NOT IN (
      SELECT rmb.no_rawat FROM referensi_mobilejkn_bpjs rmb
      WHERE rmb.tanggalperiksa BETWEEN :df2 AND :dt2
  )
  AND rp.no_rawat NOT IN (
      SELECT t.no_rawat FROM referensi_mobilejkn_bpjs_taskid t
      WHERE t.taskid = '99'
  )
ORDER BY CONCAT(rp.tgl_registrasi, ' ', rp.jam_reg)
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['df' => $dateFrom, 'dt' => $dateTo, 'df2' => $dateFrom, 'dt2' => $dateTo]);
        return $stmt->fetchAll();
    }

    /**
     * Fetch jadwal (schedule) for a doctor+poli+day combination.
     * Matches Java ANTROL-ROBOT.JAVA line 736.
     */
    public function fetchJadwal(string $hari, string $kdDokter, string $kdPoli, string $jamReg = ''): ?array
    {
        $sql = "SELECT * FROM jadwal WHERE hari_kerja=:h AND kd_dokter=:d AND kd_poli=:p ORDER BY jam_mulai ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['h' => $hari, 'd' => $kdDokter, 'p' => $kdPoli]);
        $slots = $stmt->fetchAll();
        if (empty($slots)) return null;

        return self::matchBestJadwalSlot($slots, $jamReg);
    }

    /**
     * Eager-loads ALL task states for a batch of patients.
     * Prevents executing loadTaskState sequentially in a loop.
     *
     * @param string[] $noRawats
     * @return array<string, array> Map of no_rawat => task state array
     */
    public function fetchBatchTaskStates(array $noRawats): array
    {
        if (empty($noRawats)) return [];
        // Initialize default empty states
        $states = [];
        foreach ($noRawats as $nr) {
            $states[$nr] = ['1' => '', '2' => '', '3' => '', '4' => '', '5' => '', '6' => '', '7' => '', '99' => ''];
        }
        
        $placeholders = implode(',', array_fill(0, count($noRawats), '?'));
        $sql = "SELECT no_rawat, taskid, SUBSTRING(waktu, 1, 19) as waktu FROM referensi_mobilejkn_bpjs_taskid WHERE no_rawat IN ($placeholders)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($noRawats));
        
        while ($row = $stmt->fetch()) {
            $tid = (string) $row['taskid'];
            $states[$row['no_rawat']][$tid] = 'Sudah';
            $states[$row['no_rawat']]["waktu_{$tid}"] = $row['waktu'];
        }
        return $states;
    }

    /**
     * Legacy single-patient state loader.
     */
    public function loadTaskState(string $noRawat): array
    {
        return $this->fetchBatchTaskStates([$noRawat])[$noRawat];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Resep / Farmasi
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Eager-loads ALL prescription numbers for a batch of patients.
     * Prevents sequential SELECT queries inside loops.
     *
     * @param string[] $noRawats
     * @return array<string, string> Map of no_rawat => no_resep
     */
    public function fetchBatchNoResep(array $noRawats): array
    {
        if (empty($noRawats)) return [];
        $placeholders = implode(',', array_fill(0, count($noRawats), '?'));
        $sql = "SELECT no_rawat, no_resep FROM resep_obat WHERE no_rawat IN ($placeholders)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($noRawats));
        
        $map = [];
        while ($row = $stmt->fetch()) {
            $map[$row['no_rawat']] = $row['no_resep'];
        }
        return $map;
    }

    /**
     * Legacy single-patient prescription lookup.
     */
    public function fetchNoResep(string $noRawat): string
    {
        $map = $this->fetchBatchNoResep([$noRawat]);
        return $map[$noRawat] ?? '';
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

    /**
     * Update task ID waktu timestamp in DB (used for repeated tasks like 2nd Task 3).
     */
    public function updateTaskIdWaktu(string $noRawat, string $taskId, string $waktu): bool
    {
        $sql = "UPDATE referensi_mobilejkn_bpjs_taskid SET waktu = :w WHERE no_rawat = :nr AND taskid = :tid";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(['nr' => $noRawat, 'tid' => $taskId, 'w' => $waktu]);
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
     * Eager-loads ALL doctor BPJS mappings into an in-memory O(1) hash map.
     * Prevents executing a database SELECT query for every single patient 
     * in the synchronization loop (solving the N+1 problem).
     *
     * @return array<string, string> Associative array of ['kd_dokter' => 'kd_dokter_bpjs']
     */
    public function fetchAllDokterBpjsMappings(): array
    {
        $sql = "SELECT kd_dokter, kd_dokter_bpjs FROM maping_dokter_dpjpvclaim";
        $stmt = $this->pdo->query($sql);
        
        $map = [];
        while ($row = $stmt->fetch()) {
            $map[$row['kd_dokter']] = $row['kd_dokter_bpjs'];
        }
        return $map;
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
     * Eager-loads ALL polyclinic BPJS mappings into an in-memory O(1) hash map.
     *
     * @return array<string, string> Associative array of ['kd_poli_rs' => 'kd_poli_bpjs']
     */
    public function fetchAllPoliBpjsMappings(): array
    {
        $sql = "SELECT kd_poli_rs, kd_poli_bpjs FROM maping_poli_bpjs";
        $stmt = $this->pdo->query($sql);
        
        $map = [];
        while ($row = $stmt->fetch()) {
            $map[$row['kd_poli_rs']] = $row['kd_poli_bpjs'];
        }
        return $map;
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
     * Fetch patient registration details (tgl_registrasi, jam_reg).
     */
    public function fetchPatientRegInfo(string $noRawat): ?array
    {
        $sql = "SELECT tgl_registrasi, jam_reg FROM reg_periksa WHERE no_rawat = :nr LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['nr' => $noRawat]);
        $row = $stmt->fetch();
        return $row ? $row : null;
    }

    /**
     * Fetch existing nobooking from referensi_mobilejkn_bpjs, or generate a unified MAX+1
     * nobooking (matching index.php lines 523–524) for on-site patients when formatOnsite is enabled.
     * Note: This method only computes/resolves the nobooking string and does NOT insert into referensi_mobilejkn_bpjs.
     */
    public function fetchOrGenerateNobooking(array $p, bool $formatOnsite = true): string
    {
        $noRawat = $p['no_rawat'] ?? '';
        if (empty($noRawat)) return '';

        // 1. Check existing referensi_mobilejkn_bpjs record
        $sql = "SELECT nobooking FROM referensi_mobilejkn_bpjs WHERE no_rawat = :nr LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['nr' => $noRawat]);
        $row = $stmt->fetch();
        if ($row && !empty($row['nobooking'])) {
            return $row['nobooking'];
        }

        // 2. If formatting is disabled, fallback to raw no_rawat
        if (!$formatOnsite) {
            return $noRawat;
        }

        // 3. Compute MAX(nobooking)+1 matching index.php
        $tglPeriksa = $p['tgl_registrasi'] ?? date('Y-m-d');
        $sqlMax = "SELECT IFNULL(MAX(CONVERT(RIGHT(nobooking, 6), SIGNED)), 0) + 1 AS maxb FROM referensi_mobilejkn_bpjs WHERE tanggalperiksa = :tgl";
        $stmtMax = $this->pdo->prepare($sqlMax);
        $stmtMax->execute(['tgl' => $tglPeriksa]);
        $maxRow = $stmtMax->fetch();
        $maxNum = (int) ($maxRow['maxb'] ?? 1);

        return str_replace('-', '', $tglPeriksa) . sprintf('%06d', $maxNum);
    }

    /**
     * Save/synchronize booking record to referensi_mobilejkn_bpjs upon successful /antrean/add response.
     */
    public function saveToReferensiMobileJkn(array $p, string $nobooking, string $nomorRef = ''): bool
    {
        $noRawat = $p['no_rawat'] ?? '';
        if (empty($noRawat) || empty($nobooking)) return false;

        $tglPeriksa = $p['tgl_registrasi'] ?? date('Y-m-d');
        $noReg      = (int) ($p['no_reg'] ?? 1);
        $isJkn      = (($p['kd_pj'] ?? '') === 'BPJ');
        $statusDaftar = match ($p['stts_daftar'] ?? '-') {
            'Baru' => '1',
            default => '0',
        };
        $jamMulai   = substr($p['jam_mulai'] ?? '08:00:00', 0, 5);
        $jamSelesai = substr($p['jam_selesai'] ?? '16:00:00', 0, 5);
        $jamPraktek = "{$jamMulai}-{$jamSelesai}";
        $kdPoliBpjs = $p['kd_poli_bpjs'] ?? '';
        $kdDokterBpjs = $p['kd_dokter_bpjs'] ?? '';
        $kuota      = (int) ($p['kuota'] ?? 30);
        $estimasiMs = strtotime("{$tglPeriksa} {$jamMulai}") * 1000;

        $insertSql = "INSERT INTO referensi_mobilejkn_bpjs 
            (nobooking, no_rawat, nomorkartu, nik, nohp, kodepoli, pasienbaru, norm, tanggalperiksa, kodedokter, jampraktek, jeniskunjungan, nomorreferensi, nomorantrean, angkaantrean, estimasidilayani, sisakuotajkn, kuotajkn, sisakuotanonjkn, kuotanonjkn, status, validasi, statuskirim)
            VALUES (:nb, :nr, :nk, :nik, :hp, :kp, :pb, :rm, :tgl, :kd, :jp, '3 (Kontrol)', :ref, :na, :aa, :est, :skj, :kj, :sknj, :knj, 'Checkin', NOW(), 'Sudah')
            ON DUPLICATE KEY UPDATE statuskirim = 'Sudah'";

        $stmtIns = $this->pdo->prepare($insertSql);
        return $stmtIns->execute([
            'nb' => $nobooking,
            'nr' => $noRawat,
            'nk' => $isJkn ? ($p['no_peserta'] ?: '-') : '-',
            'nik' => $isJkn ? ($p['no_ktp'] ?: '-') : '-',
            'hp' => $p['no_tlp'] ?: '-',
            'kp' => $kdPoliBpjs,
            'pb' => $statusDaftar,
            'rm' => $p['no_rkm_medis'] ?? '',
            'tgl' => $tglPeriksa,
            'kd' => $kdDokterBpjs,
            'jp' => $jamPraktek,
            'ref' => $nomorRef,
            'na' => "{$kdPoliBpjs}-{$noReg}",
            'aa' => $noReg,
            'est' => $estimasiMs,
            'skj' => max(0, $kuota - $noReg),
            'kj' => $kuota,
            'sknj' => max(0, $kuota - $noReg),
            'knj' => $kuota,
        ]);
    }

    /**
     * Delete unconfirmed or failed booking record from referensi_mobilejkn_bpjs.
     */
    public function deleteReferensiMobileJkn(string $noRawat, string $nobooking = ''): bool
    {
        if (empty($noRawat) && empty($nobooking)) return false;

        $sql = "DELETE FROM referensi_mobilejkn_bpjs WHERE (no_rawat = :nr OR nobooking = :nb) AND statuskirim != 'Sudah'";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'nr' => $noRawat,
            'nb' => $nobooking ?: $noRawat,
        ]);
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

    // ═══════════════════════════════════════════════════════════════════════
    // Batch Eager-Loading (Fix #5, #7 — eliminate N+1 queries)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Eager-load racikan status for a batch of prescriptions.
     * Prevents per-patient SELECT inside the task chain loop.
     *
     * @param string[] $noReseps
     * @return array<string, bool> Set of no_resep => true for racikan prescriptions
     */
    public function fetchBatchIsRacikan(array $noReseps): array
    {
        if (empty($noReseps)) return [];
        $placeholders = implode(',', array_fill(0, count($noReseps), '?'));
        $sql = "SELECT no_resep FROM resep_dokter_racikan WHERE no_resep IN ($placeholders) GROUP BY no_resep";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($noReseps));
        $set = [];
        while ($row = $stmt->fetch()) {
            $set[$row['no_resep']] = true;
        }
        return $set;
    }

    /**
     * Eager-load ALL jadwal into an in-memory hash map keyed by "hari|dokter|poli".
     * Supports multiple working shifts/slots per doctor per day.
     *
     * @return array<string, array[]> Map of "hari_kerja|kd_dokter|kd_poli" => list of jadwal slots
     */
    public function fetchAllJadwal(): array
    {
        $sql = "SELECT hari_kerja, kd_dokter, kd_poli, jam_mulai, jam_selesai, kuota FROM jadwal ORDER BY jam_mulai ASC";
        $stmt = $this->pdo->query($sql);
        $map = [];
        while ($row = $stmt->fetch()) {
            $key = "{$row['hari_kerja']}|{$row['kd_dokter']}|{$row['kd_poli']}";
            if (!isset($map[$key])) {
                $map[$key] = [];
            }
            $map[$key][] = $row;
        }
        return $map;
    }

    /**
     * Lookup best matching jadwal slot from pre-loaded hash map.
     */
    public function lookupJadwal(array $jadwalDict, string $hari, string $kdDokter, string $kdPoli, string $jamReg = ''): ?array
    {
        $key = "{$hari}|{$kdDokter}|{$kdPoli}";
        $slots = $jadwalDict[$key] ?? [];
        if (empty($slots)) {
            return null;
        }
        return self::matchBestJadwalSlot($slots, $jamReg);
    }

    /**
     * Match the best schedule slot for a given patient registration/appointment time (jamReg).
     */
    public static function matchBestJadwalSlot(array $slots, string $jamReg): array
    {
        if (count($slots) === 1 || empty($jamReg)) {
            return $slots[0];
        }

        $targetTime = strlen($jamReg) === 5 ? $jamReg . ':00' : $jamReg;

        // 1. Exact match: targetTime falls between jam_mulai and jam_selesai
        foreach ($slots as $slot) {
            if ($targetTime >= $slot['jam_mulai'] && $targetTime <= $slot['jam_selesai']) {
                return $slot;
            }
        }

        // 2. Proximity match: find slot with minimum time distance from targetTime
        $bestSlot = $slots[0];
        $targetTs = strtotime("1970-01-01 $targetTime");
        $minDiff  = abs($targetTs - strtotime("1970-01-01 " . $slots[0]['jam_mulai']));

        foreach ($slots as $slot) {
            $diffStart = abs($targetTs - strtotime("1970-01-01 " . $slot['jam_mulai']));
            if ($diffStart < $minDiff) {
                $minDiff  = $diffStart;
                $bestSlot = $slot;
            }
        }

        return $bestSlot;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Fix #1: Real Task 3 timestamps from mutasi_berkas (ANTROL-ROBOT.JAVA)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Eager-load real Task 3 timestamps from mutasi_berkas.dikirim.
     * ANTROL-ROBOT.JAVA uses real delivery timestamps when available,
     * only falling back to inference when missing.
     *
     * @param string[] $noRawats
     * @return array<string, string> Map of no_rawat => dikirim datetime (Y-m-d H:i:s)
     */
    public function fetchBatchMutasiBerkas(array $noRawats): array
    {
        if (empty($noRawats)) return [];
        $placeholders = implode(',', array_fill(0, count($noRawats), '?'));
        $sql = "SELECT no_rawat, dikirim FROM mutasi_berkas
                WHERE no_rawat IN ($placeholders)
                  AND dikirim IS NOT NULL
                  AND dikirim != ''
                  AND dikirim NOT LIKE '0000%'";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($noRawats));
        $map = [];
        while ($row = $stmt->fetch()) {
            $map[$row['no_rawat']] = substr($row['dikirim'], 0, 19);
        }
        return $map;
    }

    /**
     * Eager-load real Task 4 timestamps from pemeriksaan_ralan (tgl_perawatan + jam_rawat).
     * Matches frmUtama.java line 703.
     */
    public function fetchBatchPemeriksaanRalan(array $noRawats): array
    {
        if (empty($noRawats)) return [];
        $placeholders = implode(',', array_fill(0, count($noRawats), '?'));
        $sql = "SELECT no_rawat, CONCAT(tgl_perawatan, ' ', jam_rawat) as waktu FROM pemeriksaan_ralan
                WHERE no_rawat IN ($placeholders)
                  AND tgl_perawatan IS NOT NULL
                  AND tgl_perawatan != '0000-00-00'
                ORDER BY tgl_perawatan ASC, jam_rawat ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($noRawats));
        $map = [];
        while ($row = $stmt->fetch()) {
            if (!isset($map[$row['no_rawat']])) {
                $map[$row['no_rawat']] = substr($row['waktu'], 0, 19);
            }
        }
        return $map;
    }

    /**
     * Eager-load real Task 4 fallback timestamps from mutasi_berkas.diterima.
     * Matches frmUtama.java line 705.
     */
    public function fetchBatchMutasiDiterima(array $noRawats): array
    {
        if (empty($noRawats)) return [];
        $placeholders = implode(',', array_fill(0, count($noRawats), '?'));
        $sql = "SELECT no_rawat, diterima FROM mutasi_berkas
                WHERE no_rawat IN ($placeholders)
                  AND diterima IS NOT NULL
                  AND diterima != ''
                  AND diterima NOT LIKE '0000%'";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($noRawats));
        $map = [];
        while ($row = $stmt->fetch()) {
            $map[$row['no_rawat']] = substr($row['diterima'], 0, 19);
        }
        return $map;
    }

    /**
     * Eager-load real Task 5 timestamps from mutasi_berkas.kembali.
     * Matches frmUtama.java line 735.
     */
    public function fetchBatchMutasiKembali(array $noRawats): array
    {
        if (empty($noRawats)) return [];
        $placeholders = implode(',', array_fill(0, count($noRawats), '?'));
        $sql = "SELECT no_rawat, kembali FROM mutasi_berkas
                WHERE no_rawat IN ($placeholders)
                  AND kembali IS NOT NULL
                  AND kembali != ''
                  AND kembali NOT LIKE '0000%'";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($noRawats));
        $map = [];
        while ($row = $stmt->fetch()) {
            $map[$row['no_rawat']] = substr($row['kembali'], 0, 19);
        }
        return $map;
    }

    /**
     * Eager-load real Task 6 timestamps from resep_obat (tgl_perawatan + jam).
     * Matches frmUtama.java line 788.
     */
    public function fetchBatchResepObatRalan(array $noRawats): array
    {
        if (empty($noRawats)) return [];
        $placeholders = implode(',', array_fill(0, count($noRawats), '?'));
        $sql = "SELECT no_rawat, CONCAT(tgl_perawatan, ' ', jam) as waktu FROM resep_obat
                WHERE no_rawat IN ($placeholders)
                  AND status = 'ralan'
                  AND tgl_perawatan IS NOT NULL
                  AND tgl_perawatan != '0000-00-00'";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($noRawats));
        $map = [];
        while ($row = $stmt->fetch()) {
            $map[$row['no_rawat']] = substr($row['waktu'], 0, 19);
        }
        return $map;
    }

    /**
     * Eager-load real Task 7 timestamps from resep_obat (tgl_penyerahan + jam_penyerahan).
     * Matches frmUtama.java line 817.
     */
    public function fetchBatchResepObatPenyerahan(array $noRawats): array
    {
        if (empty($noRawats)) return [];
        $placeholders = implode(',', array_fill(0, count($noRawats), '?'));
        $sql = "SELECT no_rawat, CONCAT(tgl_penyerahan, ' ', jam_penyerahan) as waktu FROM resep_obat
                WHERE no_rawat IN ($placeholders)
                  AND status = 'ralan'
                  AND tgl_penyerahan IS NOT NULL
                  AND tgl_penyerahan != '0000-00-00'
                  AND jam_penyerahan != '00:00:00'";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($noRawats));
        $map = [];
        while ($row = $stmt->fetch()) {
            $map[$row['no_rawat']] = substr($row['waktu'], 0, 19);
        }
        return $map;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Fix #4: Block 5 — Unsent SEP Recovery (from ANTROL-ROBOT.JAVA)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Fetch BPJS patients who have a SEP but zero taskid records.
     * This is the safety net for patients completely missed by Blocks 1-4.
     *
     * Matches ANTROL-ROBOT.JAVA lines 1233-1238 exactly:
     *  - NOT IN taskid is INSIDE the bridging_sep subquery (no date filter)
     *  - stts='Sudah' filter ensures only completed visits are processed (BUG-B)
     *  - LEFT JOIN ensures patients with missing master records are fetched (BUG-D)
     */
    public function fetchUnsentSepPatients(string $dateFrom, string $dateTo): array
    {
        $sql = <<<'SQL'
SELECT
    rp.no_reg, rp.no_rawat, rp.tgl_registrasi, rp.jam_reg,
    rp.kd_dokter, COALESCE(d.nm_dokter, '') as nm_dokter,
    rp.kd_poli, COALESCE(pol.nm_poli, '') as nm_poli,
    rp.stts_daftar, rp.no_rkm_medis, rp.kd_pj, rp.stts,
    COALESCE(p.no_ktp, '-') as no_ktp, COALESCE(p.no_peserta, '') as no_peserta, COALESCE(p.no_tlp, '-') as no_tlp
FROM reg_periksa rp
LEFT JOIN dokter d ON rp.kd_dokter = d.kd_dokter
LEFT JOIN poliklinik pol ON rp.kd_poli = pol.kd_poli
LEFT JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
WHERE rp.tgl_registrasi BETWEEN :df AND :dt
  AND rp.kd_pj = 'BPJ'
  AND rp.stts = 'Sudah'
  AND rp.status_lanjut = 'Ralan'
  AND rp.kd_poli != 'IGDK'
  AND rp.no_rawat IN (
      SELECT c.no_rawat FROM bridging_sep c
      WHERE c.tglsep BETWEEN :df2 AND :dt2
        AND c.no_rawat NOT IN (
            SELECT no_rawat FROM referensi_mobilejkn_bpjs_taskid
        )
  )
ORDER BY CONCAT(rp.tgl_registrasi, ' ', rp.jam_reg)
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'df'  => $dateFrom, 'dt'  => $dateTo,
            'df2' => $dateFrom, 'dt2' => $dateTo,
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Fetch the full JKN booking record by no_rawat to support dynamic on-demand booking addition.
     */
    public function fetchBookingByNoRawat(string $noRawat): ?array
    {
        $sql = <<<'SQL'
SELECT
    r.nobooking, r.no_rawat, r.norm as no_rkm_medis, p.nm_pasien,
    r.nohp, r.nomorkartu, r.nik, r.tanggalperiksa,
    COALESCE(mp.nm_poli_bpjs, '') as nm_poli, COALESCE(md.nm_dokter_bpjs, '') as nm_dokter, r.jampraktek,
    r.jeniskunjungan, r.nomorreferensi, r.status, r.validasi,
    r.kodepoli, r.pasienbaru, r.kodedokter,
    r.nomorantrean, r.angkaantrean, r.estimasidilayani,
    r.sisakuotajkn, r.kuotajkn, r.sisakuotanonjkn, r.kuotanonjkn
FROM referensi_mobilejkn_bpjs r
INNER JOIN pasien p ON r.norm = p.no_rkm_medis
LEFT JOIN maping_poli_bpjs mp ON r.kodepoli = mp.kd_poli_bpjs
LEFT JOIN maping_dokter_dpjpvclaim md ON r.kodedokter = md.kd_dokter_bpjs
WHERE r.no_rawat = :nr
LIMIT 1
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['nr' => $noRawat]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}

