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
            $states[$nr] = ['3' => '', '4' => '', '5' => '', '6' => '', '7' => '', '99' => ''];
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
     * Get task 3 waktu:
     * Prioritizes JKN validation check-in time (referensi_mobilejkn_bpjs.validasi),
     * and falls back to actual counter registration time (reg_periksa.jam_reg).
     */
    public function resolveTask3Waktu(string $noRawat, string $tglRegistrasi, string $jamMulai): string
    {
        // 1. Fetch validasi from JKN references (actual QR scan / self-checkin kiosk)
        $sqlVal = "SELECT validasi FROM referensi_mobilejkn_bpjs WHERE no_rawat = :nr LIMIT 1";
        $stmtVal = $this->pdo->prepare($sqlVal);
        $stmtVal->execute(['nr' => $noRawat]);
        $rowVal = $stmtVal->fetch();
        $validasi = $rowVal['validasi'] ?? '';
        if (!empty($validasi) && !str_starts_with($validasi, '0000') && $validasi !== '0000-00-00 00:00:00') {
            return $validasi;
        }

        // 2. Fallback to physical on-site counter registration time (reg_periksa.tgl_registrasi + jam_reg)
        $sqlReg = "SELECT tgl_registrasi, jam_reg FROM reg_periksa WHERE no_rawat = :nr LIMIT 1";
        $stmtReg = $this->pdo->prepare($sqlReg);
        $stmtReg->execute(['nr' => $noRawat]);
        $rowReg = $stmtReg->fetch();
        if ($rowReg) {
            $regTime = $rowReg['tgl_registrasi'] . ' ' . $rowReg['jam_reg'];
            if (!empty($regTime) && !str_starts_with($regTime, '0000') && !str_ends_with($regTime, '00:00:00')) {
                return $regTime;
            }
        }

        return '';
    }

    /**
     * Get task 3 waktu for JKN patients.
     * Aligns with the same unified check-in / validation priority logic.
     */
    public function resolveTask3WaktuJkn(string $noRawat, string $tglRegistrasi, string $jamMulai): string
    {
        return $this->resolveTask3Waktu($noRawat, $tglRegistrasi, $jamMulai);
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

