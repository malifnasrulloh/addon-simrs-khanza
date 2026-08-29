<?php
/**
 * QueueProcessor — Core orchestrator for Mobile JKN queue sync.
 *
 * Exact port of Java ANTROL-ROBOT.JAVA logic:
 *  1. Send unsent JKN bookings (/antrean/add)
 *  2. Process cancellations (/antrean/batal + taskid=99)
 *  3. Process JKN task chain (3→4→5→farmasi→6→7) using local DB state only
 *  4. Process missing on-site BPJ patients (/antrean/add + task chain)
 *
 * Key design: NO overthinking. Match Java robot exactly:
 *  - Local DB state (referensi_mobilejkn_bpjs_taskid) is the ONLY authority
 *  - Robot inference uses exact Java random ranges + two simple gates
 *  - No BPJS cache gates, no working hours validation, no statistical analysis
 *
 * @author malifnasrulloh (ported from Java by Antigravity)
 */
declare(strict_types=1);

require_once __DIR__ . '/RobotInference.php';
require_once __DIR__ . '/PayloadBuilder.php';

class QueueProcessor
{
    private MobileJknDatabase $db;
    private BpjsAntreanClient $api;
    private MobileJknConfig   $config;
    private Logger            $log;

    private int $successCount = 0;
    private int $failCount    = 0;
    private int $skipCount    = 0;

    /** @var array<string, true> Dedup: no_rawat => true for farmasi sent this cycle */
    private array $farmasiSent = [];

    /** @var array<string, array<string, true>> Track tasks sent this cycle: no_rawat => [taskId => true] (Fix #8) */
    private array $sentThisCycle = [];

    public function __construct(MobileJknDatabase $db, BpjsAntreanClient $api, MobileJknConfig $config, Logger $log)
    {
        $this->db     = $db;
        $this->api    = $api;
        $this->config = $config;
        $this->log    = $log;
    }

    /**
     * Run all processing blocks. Returns stats.
     * @return array{success: int, fail: int, skip: int}
     */
    public function run(): array
    {
        $this->successCount = 0;
        $this->failCount    = 0;
        $this->skipCount    = 0;
        $this->farmasiSent  = [];
        $this->sentThisCycle = [];

        $today    = $this->config->todayDate();
        $lookback = $this->config->lookbackDate();

        // Block 1: Add unsent JKN bookings
        $this->processNewJknBookings($lookback, $today);

        // Block 2: Cancellations
        $this->processCancellations($lookback, $today);

        // Block 3: JKN task chain (statuskirim='Sudah')
        $this->processJknTasks($lookback, $today);

        // Block 4: Missing on-site BPJ patients
        $this->processMissingOnsitePatients($lookback, $today);

        // Block 5: Unsent SEP recovery (safety net from ANTROL-ROBOT.JAVA)
        $this->processUnsentSepPatients($lookback, $today);

        return [
            'success' => $this->successCount,
            'fail'    => $this->failCount,
            'skip'    => $this->skipCount,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Block 1: Add new JKN queue entries
    // Java ANTROL-ROBOT.JAVA lines 73–146
    // ═══════════════════════════════════════════════════════════════════════

    private function processNewJknBookings(string $dateFrom, string $dateTo): void
    {
        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[BLOCK 1] Adding JKN queue entries (statuskirim=Belum)...");

        try {
            $bookings = $this->db->fetchUnsentJknBookings($dateFrom, $dateTo);
        } catch (\PDOException $e) {
            $this->log->error("[BLOCK 1] DB query failed: " . $e->getMessage());
            $this->failCount++;
            return;
        }

        if (empty($bookings)) {
            $this->log->info("[BLOCK 1] No unsent JKN bookings found.");
            return;
        }
        $this->log->info("[BLOCK 1] Found " . count($bookings) . " unsent booking(s).");

        // Fetch existing BPJS queue registrations for dates in range via GET /antrean/pendaftaran/tanggal/{tanggal}
        $existingBpjsBookings = [];
        $uniqueDates = array_unique(array_column($bookings, 'tanggalperiksa'));
        foreach ($uniqueDates as $tgl) {
            $pendaftaranRes = $this->api->getAntreanPendaftaranTanggal($tgl);
            if ($pendaftaranRes['success'] && is_array($pendaftaranRes['data'])) {
                foreach ($pendaftaranRes['data'] as $item) {
                    $kb = (string) ($item['kodebooking'] ?? '');
                    if (!empty($kb)) {
                        $existingBpjsBookings[$kb] = true;
                    }
                }
            }
        }

        foreach ($bookings as $b) {
            $nb = $b['nobooking'];

            // Check via GET /antrean/pendaftaran/tanggal list first
            if (isset($existingBpjsBookings[$nb])) {
                try {
                    $this->db->markBookingAsSent($nb);
                    $this->log->info("[BLOCK 1] {$nb}: auto-synced statuskirim=Sudah (found in BPJS /antrean/pendaftaran/tanggal list)");
                    $this->successCount++;
                    continue;
                } catch (\PDOException $e) {
                    $this->log->error("[BLOCK 1] DB update failed for {$nb}: " . $e->getMessage());
                    $this->failCount++;
                    continue;
                }
            }

            // Fallback check: getListTask
            $listRes = $this->api->getListTask($nb);
            if ($listRes['success'] && !empty($listRes['data'])) {
                try {
                    $this->db->markBookingAsSent($nb);
                    $this->log->info("[BLOCK 1] {$nb}: auto-synced statuskirim=Sudah (found existing tasks on BPJS)");
                    $this->successCount++;
                    continue; // Skip /antrean/add since booking exists on BPJS
                } catch (\PDOException $e) {
                    $this->log->error("[BLOCK 1] DB update failed for {$nb}: " . $e->getMessage());
                    $this->failCount++;
                    continue;
                }
            }

            $payload = PayloadBuilder::jknBooking($b);

            $this->log->info("[BLOCK 1] {$nb}: SEND /antrean/add");
            $result = $this->api->addAntrean($payload);

            $code = $result['code'] ?? '';
            // 200=OK, 208=duplicate (already exists on BPJS) → accepted.
            // 201 is treated as a validation failure.
            if ($result['success'] || $code === '208') {
                try {
                    $this->db->markBookingAsSent($nb);
                    $this->log->info("[BLOCK 1] {$nb}: ✓ accepted (code={$code})");
                    $this->successCount++;
                } catch (\PDOException $e) {
                    $this->log->error("[BLOCK 1] DB update failed for {$nb}: " . $e->getMessage());
                    $this->failCount++;
                }
            } else {
                $this->log->warning("[BLOCK 1] {$nb}: ✗ {$code} — {$result['message']}");
                $this->failCount++;

                // Fallback Active Auto-Healing: try to send TaskID 3 to see if booking actually exists
                $this->log->info("[BLOCK 1] {$nb}: attempting fallback TaskID 3 auto-healing...");
                $regInfo = $this->db->fetchPatientRegInfo($b['no_rawat']);
                $jamReg  = $regInfo['jam_reg'] ?? '08:00:00';
                $hari    = $this->db->hariForDate($b['tanggalperiksa']);
                $jadwal  = $this->db->fetchJadwal($hari, $b['kodedokter'], $b['kodepoli'], $jamReg);
                $jamMulai = $jadwal['jam_mulai'] ?? '08:00:00';

                $datajam = RobotInference::inferTask3($b['tanggalperiksa'], $jamReg, $jamMulai, $this->config->robotRanges);

                if (!empty($datajam)) {
                    $r = $this->sendTaskId($nb, $b['no_rawat'], '3', $datajam, 'BLOCK 1');
                    if ($r['ok']) {
                        // If BPJS accepted TaskID 3, it means the booking actually exists on BPJS!
                        // Mark statuskirim = 'Sudah' and record TaskID 3 locally.
                        try {
                            $this->db->markBookingAsSent($nb);
                            $this->db->insertTaskId($b['no_rawat'], '3', $datajam);
                            $this->log->info("[BLOCK 1] {$nb}: ✓ Fallback TaskID 3 accepted. Auto-healed statuskirim=Sudah");
                            $this->successCount++;
                        } catch (\PDOException $e) {
                            $this->log->error("[BLOCK 1] DB update failed for fallback {$nb}: " . $e->getMessage());
                        }
                    } else {
                        $this->log->debug("[BLOCK 1] {$nb}: Fallback TaskID 3 rejected — booking likely does not exist on BPJS");
                    }
                }
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Block 2: Process cancellations
    // Java ANTROL-ROBOT.JAVA lines 149–225
    // ═══════════════════════════════════════════════════════════════════════

    private function processCancellations(string $dateFrom, string $dateTo): void
    {
        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[BLOCK 2] Processing cancellations...");

        try {
            $cancellations = $this->db->fetchPendingCancellations($dateFrom, $dateTo);
        } catch (\PDOException $e) {
            $this->log->error("[BLOCK 2] DB query failed: " . $e->getMessage());
            $this->failCount++;
            return;
        }

        if (empty($cancellations)) {
            $this->log->info("[BLOCK 2] No pending cancellations.");
            return;
        }
        $this->log->info("[BLOCK 2] Found " . count($cancellations) . " cancellation(s).");

        foreach ($cancellations as $c) {
            $nb      = $c['nobooking'];
            $noRawat = $c['no_rawat_batal'] ?? '';

            // Step 1: /antrean/batal
            $result = $this->api->batalAntrean($nb, $c['keterangan'] ?? 'Dibatalkan');

            if ($result['success']) {
                $this->db->markCancellationAsSent($c['nomorreferensi']);

                // Step 2: Send taskid=99
                $waktuStr = $c['tanggalbatal'] ?? '';
                if (!empty($waktuStr) && !empty($noRawat)) {
                    $this->sendTaskId($nb, $noRawat, '99', $waktuStr, 'BLOCK 2');
                }
            } else {
                $this->log->warning("[BLOCK 2] ✗ Cancel failed {$nb}: {$result['message']}");
                $this->failCount++;
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Block 3: JKN task chain processing
    // Java ANTROL-ROBOT.JAVA lines 227–692
    // ═══════════════════════════════════════════════════════════════════════

    private function processJknTasks(string $dateFrom, string $dateTo): void
    {
        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[BLOCK 3] Updating task IDs for JKN patients...");

        try {
            $patients = $this->db->fetchJknPatientsForTasks($dateFrom, $dateTo);
        } catch (\PDOException $e) {
            $this->log->error("[BLOCK 3] DB query failed: " . $e->getMessage());
            $this->failCount++;
            return;
        }

        $total = count($patients);
        if ($total === 0) {
            $this->log->info("[BLOCK 3] No JKN patients with pending tasks.");
            return;
        }
        $this->log->info("[BLOCK 3] Processing {$total} JKN patient(s)...");

        // Eager Load Task States, Prescriptions, Racikan, Mutasi Berkas, Real SIMRS events, and Jadwal
        $noRawats              = array_column($patients, 'no_rawat');
        $taskStates            = $this->db->fetchBatchTaskStates($noRawats);
        $noResepMap            = $this->db->fetchBatchNoResep($noRawats);
        $racikanSet            = $this->db->fetchBatchIsRacikan(array_filter(array_values($noResepMap)));
        $mutasiBerkasMap       = $this->db->fetchBatchMutasiBerkas($noRawats);
        $pemeriksaanRalanMap   = $this->db->fetchBatchPemeriksaanRalan($noRawats);
        $mutasiDiterimaMap     = $this->db->fetchBatchMutasiDiterima($noRawats);
        $mutasiKembaliMap      = $this->db->fetchBatchMutasiKembali($noRawats);
        $resepRalanMap         = $this->db->fetchBatchResepObatRalan($noRawats);
        $resepPenyerahanMap    = $this->db->fetchBatchResepObatPenyerahan($noRawats);
        $jadwalDict            = $this->db->fetchAllJadwal();

        foreach ($patients as $idx => $p) {
            $noRawat     = $p['no_rawat'];
            $kodebooking = $p['nobooking'];
            $this->log->info("[BLOCK 3] ── Patient " . ($idx + 1) . "/{$total}: {$noRawat} ──");

            // Load task state from pre-fetched dictionary
            $state = $taskStates[$noRawat] ?? ['1' => '', '2' => '', '3' => '', '4' => '', '5' => '', '6' => '', '7' => '', '99' => ''];

            // Check for missing master data from LEFT JOIN (BUG-D: zero patient loss)
            if (empty($p['nm_dokter']) || empty($p['nm_poli'])) {
                $this->log->warning("[BLOCK 3] {$noRawat}: missing master data (nm_dokter='{$p['nm_dokter']}', nm_poli='{$p['nm_poli']}') — patient fetched but needs manual review");
            }

            // Resolve jadwal from pre-loaded dictionary (Fix #7)
            $hari   = $this->db->hariForDate($p['tgl_registrasi']);
            $jamReg = $p['jam_reg'] ?? '08:00:00';
            $jadwal = $this->db->lookupJadwal($jadwalDict, $hari, $p['kd_dokter'], $p['kd_poli'], $jamReg);
            if (!$jadwal) {
                // Log which patient is being skipped and WHY (BUG-D: clear reason for skip)
                $this->log->warning("[BLOCK 3] {$noRawat}: no jadwal found (hari={$hari}, kd_dokter={$p['kd_dokter']}, kd_poli={$p['kd_poli']}) — patient fetched but SKIPPED (no schedule mapping)");
                continue;
            }

            // Load pre-fetched prescription number and racikan status (Fix #5)
            $noResep   = $noResepMap[$noRawat] ?? '';
            $isRacikan = isset($racikanSet[$noResep]);

            $realEvents = [
                '3' => $mutasiBerkasMap[$noRawat] ?? '',
                '4' => $pemeriksaanRalanMap[$noRawat] ?? ($mutasiDiterimaMap[$noRawat] ?? ''),
                '5' => $mutasiKembaliMap[$noRawat] ?? '',
                '6' => $resepRalanMap[$noRawat] ?? '',
                '7' => $resepPenyerahanMap[$noRawat] ?? '',
            ];

            // Process task chain: 1 → 2 → 3 → 4 → 5 → [farmasi] → 6 → 7
            $this->processTaskChain($kodebooking, $noRawat, $p, $state, $jadwal, 'BLOCK 3', true, $noResep, $isRacikan, $realEvents);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Block 4: Missing on-site patients (ALL payer types)
    // Java ANTROL-ROBOT.JAVA lines 694–1770
    // Java does NOT filter by kd_pj in SQL — it checks per-patient in loop
    // ═══════════════════════════════════════════════════════════════════════

    private function processMissingOnsitePatients(string $dateFrom, string $dateTo): void
    {
        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[BLOCK 4] Processing missing on-site patients...");

        try {
            $patients = $this->db->fetchMissingOnsitePatients($dateFrom, $dateTo);
        } catch (\PDOException $e) {
            $this->log->error("[BLOCK 4] DB query failed: " . $e->getMessage());
            $this->failCount++;
            return;
        }

        $total = count($patients);
        if ($total === 0) {
            $this->log->info("[BLOCK 4] No missing on-site patients found.");
            return;
        }
        $this->log->info("[BLOCK 4] Found {$total} missing on-site patient(s).");

        // Eager Load ALL dictionaries to prevent N+1 Queries (Fix #5, #7)
        $dokterDict  = $this->db->fetchAllDokterBpjsMappings();
        $poliDict    = $this->db->fetchAllPoliBpjsMappings();
        $jadwalDict  = $this->db->fetchAllJadwal();

        $noRawats              = array_column($patients, 'no_rawat');
        $taskStates            = $this->db->fetchBatchTaskStates($noRawats);
        $noResepMap            = $this->db->fetchBatchNoResep($noRawats);
        $racikanSet            = $this->db->fetchBatchIsRacikan(array_filter(array_values($noResepMap)));
        $mutasiBerkasMap       = $this->db->fetchBatchMutasiBerkas($noRawats);
        $pemeriksaanRalanMap   = $this->db->fetchBatchPemeriksaanRalan($noRawats);
        $mutasiDiterimaMap     = $this->db->fetchBatchMutasiDiterima($noRawats);
        $mutasiKembaliMap      = $this->db->fetchBatchMutasiKembali($noRawats);
        $resepRalanMap         = $this->db->fetchBatchResepObatRalan($noRawats);
        $resepPenyerahanMap    = $this->db->fetchBatchResepObatPenyerahan($noRawats);

        foreach ($patients as $idx => $p) {
            $noRawat     = $p['no_rawat'];
            $kodebooking = $noRawat; // Java uses no_rawat as kodebooking for on-site
            $kdPj        = $p['kd_pj'] ?? '';
            $isJkn       = ($kdPj === 'BPJ');
            $this->log->info("[BLOCK 4] ── Patient " . ($idx + 1) . "/{$total}: {$noRawat} (kd_pj={$kdPj}) ──");

            // Check for missing master data from LEFT JOIN (BUG-D: zero patient loss)
            if (empty($p['nm_dokter']) || empty($p['nm_poli']) || empty($p['no_ktp']) || empty($p['no_peserta'])) {
                $this->log->warning("[BLOCK 4] {$noRawat}: missing master data (nm_dokter='{$p['nm_dokter']}', nm_poli='{$p['nm_poli']}', no_ktp='{$p['no_ktp']}', no_peserta='{$p['no_peserta']}') — patient fetched but needs manual review");
            }

            // Resolve jadwal from pre-loaded dictionary (Fix #7)
            $hari   = $this->db->hariForDate($p['tgl_registrasi']);
            $jamReg = $p['jam_reg'] ?? '08:00:00';
            $jadwal = $this->db->lookupJadwal($jadwalDict, $hari, $p['kd_dokter'], $p['kd_poli'], $jamReg);
            if (!$jadwal) {
                // Log which patient is being skipped and WHY (BUG-D: clear reason for skip)
                $this->log->warning("[BLOCK 4] {$noRawat}: no jadwal found (hari={$hari}, kd_dokter={$p['kd_dokter']}, kd_poli={$p['kd_poli']}) — patient fetched but SKIPPED (no schedule mapping)");
                continue;
            }

            // Java: per-patient mapping lookup (lines 718–724)
            $dokterBpjs = $dokterDict[$p['kd_dokter']] ?? '';
            $poliBpjs   = $poliDict[$p['kd_poli']] ?? '';
            if (empty($dokterBpjs) || empty($poliBpjs)) {
                $this->log->debug("[BLOCK 4] {$noRawat}: no BPJS mapping — skipping");
                continue;
            }

            $p['jam_mulai']      = $jadwal['jam_mulai'];
            $p['jam_selesai']    = $jadwal['jam_selesai'];
            $p['kuota']          = $jadwal['kuota'];
            $p['kd_dokter_bpjs'] = $dokterBpjs;
            $p['kd_poli_bpjs']   = $poliBpjs;

            // Clean up any stale records if no_rawat was recycled after previous patient cancellation/deletion
            if ($this->db->purgeStalePatientRecords($noRawat, $p['no_rkm_medis'] ?? '')) {
                $taskStates[$noRawat] = ['1' => '', '2' => '', '3' => '', '4' => '', '5' => '', '6' => '', '7' => '', '99' => ''];
            }

            // Resolve kodebooking matching index.php (unified MAX+1 sequence when formatOnsiteKodebooking=true)
            $kodebooking = $this->db->fetchOrGenerateNobooking($p, $this->config->formatOnsiteKodebooking);

            // Load existing task state, prescription, racikan from pre-fetched dictionaries
            $state     = $taskStates[$noRawat] ?? ['1' => '', '2' => '', '3' => '', '4' => '', '5' => '', '6' => '', '7' => '', '99' => ''];
            $noResep   = $noResepMap[$noRawat] ?? '';
            $isRacikan = isset($racikanSet[$noResep]);

            // ── IMMEDIATE /antrean/add ────────────────────────────────────
            // Matches Java robot: if Task 3 is empty locally, send /antrean/add IMMEDIATELY!
            if (($state['3'] ?? '') === '') {
                $nomorRef = $isJkn ? $this->db->fetchNomorReferensi($noRawat) : '';
                $payload  = PayloadBuilder::onsitePatient($p, $isJkn, $nomorRef, $kodebooking, $this->config->nomorantreanFormat);
                $this->log->info("[BLOCK 4] {$noRawat} (kodebooking={$kodebooking}): SEND /antrean/add (jenispasien=" . ($isJkn ? 'JKN' : 'NON JKN') . ")");
                $addResult = $this->api->addAntrean($payload);
                $addCode   = $addResult['code'] ?? '';
                if ($addResult['success'] || $addCode === '208') {
                    $this->db->saveToReferensiMobileJkn($p, $kodebooking, $nomorRef);
                    $this->log->info("[BLOCK 4] {$noRawat}: ✓ /antrean/add accepted (code={$addCode})");
                    $this->successCount++;
                } else {
                    $this->log->warning("[BLOCK 4] {$noRawat}: ✗ /antrean/add failed ({$addCode}) — {$addResult['message']}");
                    $this->db->deleteReferensiMobileJkn($noRawat, $kodebooking);
                    $this->failCount++;
                    continue; // Skip task chain because booking was not accepted on BPJS
                }
            }

            $realEvents = [
                '3' => $mutasiBerkasMap[$noRawat] ?? '',
                '4' => $pemeriksaanRalanMap[$noRawat] ?? ($mutasiDiterimaMap[$noRawat] ?? ''),
                '5' => $mutasiKembaliMap[$noRawat] ?? '',
                '6' => $resepRalanMap[$noRawat] ?? '',
                '7' => $resepPenyerahanMap[$noRawat] ?? '',
            ];

            // Directly run the task chain
            $this->processTaskChain($kodebooking, $noRawat, $p, $state, $jadwal, 'BLOCK 4', $isJkn, $noResep, $isRacikan, $realEvents);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Core: Per-patient task chain — exact Java robot logic
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Check if task chain processing should be deferred.
     * Booking creation (antrean/add) always runs immediately.
     * Task chain (updatewaktu) can be deferred until after polyclinic closes.
     */
    private function shouldDeferTaskChain(array $patient, string $hari, string $kdDokter, string $kdPoli): bool
    {
        if (!$this->config->deferTaskChain) return false;
        if ($patient['tgl_registrasi'] !== date('Y-m-d')) return false;

        $jadwalDict = $this->db->fetchAllJadwal();
        $jadwal = $this->db->lookupJadwal($jadwalDict, $hari, $kdDokter, $kdPoli);
        if (!$jadwal) return false;

        return date('H:i:s') < $jadwal['jam_selesai'];
    }

    /**
     * Process task chain 3→4→5→[farmasi]→6→7→[99] for a single patient.
     *
     * Strategy per task: use real DB data first (Fix #1) → if missing, try robot inference.
     * Cancellations ONLY from explicit sources (Fix #2).
     *
     * @param string $realTask3 Real Task 3 timestamp from mutasi_berkas.dikirim, or '' if unavailable (Fix #1)
     * @param bool   $isRacikan Pre-loaded racikan status (Fix #5)
     */
    private function processTaskChain(
        string $kodebooking,
        string $noRawat,
        array  $patient,
        array  $state,
        array  $jadwal,
        string $label,
        bool   $isJkn,
        string $noResep = '',
        bool   $isRacikan = false,
        array  $realEvents = []
    ): void {
        $jamMulai   = $jadwal['jam_mulai'] ?? '08:00:00';
        $jamSelesai = $jadwal['jam_selesai'] ?? '14:00:00';
        $isRealtime = ($this->config->syncMode === 'realtime');

        // Early abort if patient registration is marked as Batal
        if (($patient['stts'] ?? '') === 'Batal') {
            $this->log->debug("[{$label}] {$noRawat}: registration status is 'Batal' — skipping task chain");
            return;
        }

        // Determine prescription info from pre-loaded data (Fix #5)
        $jenisresep = empty($noResep) ? 'Tidak ada' : ($isRacikan ? 'Racikan' : 'Non racikan');

        // Smart-Bypass Caching: check if patient's active milestones are already fully completed locally
        $isCompleted = ($state['1'] === 'Sudah' && $state['2'] === 'Sudah' && $state['3'] === 'Sudah' && $state['4'] === 'Sudah' && $state['5'] === 'Sudah');
        if ($isCompleted) {
            $hasPrescription = !empty($noResep);
            if ($hasPrescription || !$this->config->skipFarmasiNoResep) {
                $isCompleted = ($state['6'] === 'Sudah' && $state['7'] === 'Sudah');
            }
        }

        // Bidirectional auto-healing: only sync/double-check BPJS API if patient is NOT fully completed locally and not cancelled
        if (!$isCompleted && $state['99'] === '') {
            $this->syncTaskStateFromBpjs($kodebooking, $noRawat, $state, $label);
        } else {
            $this->log->debug("[{$label}] {$noRawat}: patient is already completed locally or cancelled — skipping BPJS getlisttask verification");
        }

        // Defer task chain processing (but NOT booking creation)
        $hari = $this->db->hariForDate($patient['tgl_registrasi']);
        if ($this->shouldDeferTaskChain($patient, $hari, $patient['kd_dokter'], $patient['kd_poli'])) {
            $this->log->debug("[{$label}] {$noRawat}: deferring task chain until after polyclinic closes");
            return;
        }

        // ── Resolve Task 3 timestamp first as anchor for preceding tasks (Task 1 & 2) ──
        $waktu3Str = $state['waktu_3'] ?? '';
        if (empty($waktu3Str)) {
            if (!$isRealtime) {
                // Pure Robot Mode: always compute Task 3 via RobotInference
                $waktu3Str = RobotInference::inferTask3($patient['tgl_registrasi'], $patient['jam_reg'] ?? '08:00:00', $jamMulai, $this->config->robotRanges);
            } else {
                $real3 = $realEvents['3'] ?? '';
                if (!empty($real3)) {
                    $waktu3Str = $real3;
                } else {
                    // Realtime Mode: fallback anchor to max(reg_periksa.jam_reg, jadwal.jam_mulai)
                    $regDateTime   = $patient['tgl_registrasi'] . ' ' . ($patient['jam_reg'] ?? '08:00:00');
                    $startDateTime = $patient['tgl_registrasi'] . ' ' . $jamMulai;
                    $waktu3Str     = ($regDateTime > $startDateTime) ? $regDateTime : $startDateTime;
                }
            }
        }

        // ── Task 1: pendaftaran antrean ───────────────────────────────────
        if ($state['99'] === '' && $state['1'] === '') {
            $waktu1Str = RobotInference::inferPrecedingTask('1', $waktu3Str, $this->config->robotRanges);
            if (!empty($waktu1Str) && strtotime($waktu1Str) <= time()) {
                $r1 = $this->sendTaskId($kodebooking, $noRawat, '1', $waktu1Str, $label, $jenisresep);
                if ($r1['ok']) {
                    $state['1'] = 'Sudah';
                    $state['waktu_1'] = $waktu1Str;
                }
            }
        }

        // ── Task 2: pendaftaran dilayani ──────────────────────────────────
        if ($state['99'] === '' && $state['1'] === 'Sudah' && $state['2'] === '') {
            $waktu2Str = RobotInference::inferPrecedingTask('2', $waktu3Str, $this->config->robotRanges);
            // Monotonicity Gate: Ensure T1 < T2
            $t1Ts = strtotime($state['waktu_1'] ?? '');
            if ($t1Ts !== false && strtotime($waktu2Str) <= $t1Ts) {
                $waktu2Str = date('Y-m-d H:i:s', $t1Ts + 180); // 3 minutes after Task 1
            }

            if (!empty($waktu2Str) && strtotime($waktu2Str) <= time()) {
                $r2 = $this->sendTaskId($kodebooking, $noRawat, '2', $waktu2Str, $label, $jenisresep);
                if ($r2['ok']) {
                    $state['2'] = 'Sudah';
                    $state['waktu_2'] = $waktu2Str;
                }
            }
        }

        // ── Task 3: mulai tunggu poli ─────────────────────────────────────
        if ($state['99'] === '' && $state['2'] === 'Sudah' && $state['3'] === '') {
            // Monotonicity Gate: Ensure T2 < T3
            $t2Ts = strtotime($state['waktu_2'] ?? '');
            if ($t2Ts !== false && strtotime($waktu3Str) <= $t2Ts) {
                $waktu3Str = date('Y-m-d H:i:s', $t2Ts + 180); // 3 minutes after Task 2
            }

            if (!empty($waktu3Str)) {
                $datajamTs = strtotime($waktu3Str);
                if ($datajamTs !== false && $datajamTs > time()) {
                    $this->log->debug("[{$label}] {$noRawat} TaskID 3: time {$waktu3Str} is in the future — wait");
                } else {
                    $r = $this->sendTaskId($kodebooking, $noRawat, '3', $waktu3Str, $label, $jenisresep);
                    if ($r['ok']) {
                        $state['3'] = 'Sudah';
                        $state['waktu_3'] = $waktu3Str;

                        // ── Repeated Task 3 (1-2-3-3-4-5-6-7) ─────────────────────
                        if ($this->config->repeatTask3) {
                            $t3bTs = strtotime($waktu3Str) + 180; // 3 minutes after 1st Task 3
                            if ($t3bTs <= time()) {
                                $waktu3bStr = date('Y-m-d H:i:s', $t3bTs);
                                $this->sendTaskId($kodebooking, $noRawat, '3', $waktu3bStr, $label, $jenisresep, true);
                            }
                        }
                    } elseif (($r['reason'] ?? '') === 'preceding_tasks_missing') {
                        $missingId = $r['missing_taskid'] ?? null;
                        if ($this->healPrecedingTasks($kodebooking, $noRawat, $patient, $state, $jadwal, $label, $isJkn, '3', $jenisresep, $missingId)) {
                            $retryR3 = $this->sendTaskId($kodebooking, $noRawat, '3', $waktu3Str, $label, $jenisresep);
                            if ($retryR3['ok']) {
                                $state['3'] = 'Sudah';
                                $state['waktu_3'] = $waktu3Str;
                            }
                        }
                    } elseif (($r['reason'] ?? '') === 'booking_not_found') {
                        if ($patient['tgl_registrasi'] < date('Y-m-d')) {
                            $this->log->warning("[{$label}] {$noRawat} TaskID 3 failed: booking_not_found, past date ({$patient['tgl_registrasi']}). Skipping — will retry next cycle.");
                            $state['3'] = 'Belum';
                        } else {
                            $this->log->info("[{$label}] {$noRawat} TaskID 3 failed: booking_not_found. Triggering dynamic booking recovery...");

                            // Dynamically resolve /antrean/add payload
                            $payload = null;
                            if ($isJkn) {
                                $bookingData = $this->db->fetchBookingByNoRawat($noRawat, $patient['no_rkm_medis'] ?? '');
                                if ($bookingData) {
                                    $payload = PayloadBuilder::jknBooking($bookingData);
                                } else {
                                    $nomorRef = $this->db->fetchNomorReferensi($noRawat);
                                    $payload  = PayloadBuilder::onsitePatient($patient, true, $nomorRef, '', $this->config->nomorantreanFormat);
                                }
                            } else {
                                $payload = PayloadBuilder::onsitePatient($patient, false, '', '', $this->config->nomorantreanFormat);
                            }

                            if ($payload) {
                                $this->log->info("[{$label}] {$noRawat}: sending dynamic /antrean/add (jenispasien=" . ($isJkn ? 'JKN' : 'NON JKN') . ")");
                                $addResult = $this->api->addAntrean($payload);
                                $addCode   = $addResult['code'] ?? '';

                                if ($addResult['success'] || $addCode === '208') {
                                    $this->log->info("[{$label}] {$noRawat}: dynamic /antrean/add recovery accepted (code={$addCode}). Retrying Task ID 3 immediately.");
                                    if ($isJkn && !empty($bookingData['nobooking'])) {
                                        $this->db->markBookingAsSent($bookingData['nobooking']);
                                    } else {
                                        $this->db->saveToReferensiMobileJkn($patient, $kodebooking, $nomorRef ?? '');
                                    }
                                    // Retry sending Task 3
                                    $retryR = $this->sendTaskId($kodebooking, $noRawat, '3', $waktu3Str, $label, $jenisresep);
                                    if ($retryR['ok']) {
                                        $state['3'] = 'Sudah';
                                        $state['waktu_3'] = $waktu3Str;
                                    } else {
                                        $state['3'] = 'Belum';
                                    }
                                } else {
                                    $this->log->warning("[{$label}] {$noRawat}: dynamic /antrean/add recovery failed ({$addCode}): {$addResult['message']}");
                                    $this->db->deleteReferensiMobileJkn($noRawat, $kodebooking);
                                    $state['3'] = 'Belum';
                                }
                            } else {
                                $this->log->error("[{$label}] {$noRawat}: failed to resolve booking payload for dynamic recovery");
                                $state['3'] = 'Belum';
                            }
                        }
                    } else {
                        $state['3'] = 'Belum';
                    }
                }
            } else {
                $this->log->debug("[{$label}] {$noRawat} TaskID 3: patient has not checked in (waiting for digital/physical check-in or SEP) — pausing task chain");
            }
        }

        // ── Task 4: mulai pelayanan poli ──────────────────────────────────
        if ($state['99'] === '' && $state['3'] === 'Sudah' && $state['4'] === '') {
            $prevWaktu = $state['waktu_3'] ?? '';
            $openTime  = $patient['tgl_registrasi'] . ' ' . $jamMulai;
            if (strtotime($prevWaktu) < strtotime($openTime)) {
                $prevWaktu = $openTime;
            }

            if (!$isRealtime) {
                // Pure Robot Mode
                $waktu4Str = RobotInference::infer('4', $prevWaktu, false, $this->config->robotRanges);
            } else {
                // Realtime Mode: strictly from real SIMRS tables
                $waktu4Str = $realEvents['4'] ?? '';
            }

            if (!empty($waktu4Str)) {
                $targetDate = $patient['tgl_registrasi'];
                if (date('Y-m-d', strtotime($waktu4Str)) !== $targetDate) {
                    $timePart  = date('H:i:s', strtotime($waktu4Str));
                    $waktu4Str = "{$targetDate} {$timePart}";
                }
                $t3Ts = strtotime($prevWaktu);
                if ($t3Ts !== false && strtotime($waktu4Str) <= $t3Ts) {
                    $waktu4Str = date('Y-m-d H:i:s', $t3Ts + 180);
                }
                if (strtotime($waktu4Str) <= time()) {
                    $r4 = $this->sendTaskId($kodebooking, $noRawat, '4', $waktu4Str, $label, $jenisresep);
                    if ($r4['ok']) {
                        $state['4'] = 'Sudah';
                        $state['waktu_4'] = $waktu4Str;
                    } elseif (($r4['reason'] ?? '') === 'preceding_tasks_missing') {
                        $missingId = $r4['missing_taskid'] ?? null;
                        if ($this->healPrecedingTasks($kodebooking, $noRawat, $patient, $state, $jadwal, $label, $isJkn, '4', $jenisresep, $missingId)) {
                            $retryR4 = $this->sendTaskId($kodebooking, $noRawat, '4', $waktu4Str, $label, $jenisresep);
                            if ($retryR4['ok']) {
                                $state['4'] = 'Sudah';
                                $state['waktu_4'] = $waktu4Str;
                            }
                        }
                    }
                }
            } else {
                $this->log->debug("[{$label}] {$noRawat} TaskID 4: real SIMRS event missing — waiting for examination entry in pemeriksaan_ralan / mutasi_berkas");
            }
        }

        // ── Task 5: selesai pelayanan poli ────────────────────────────────
        if ($state['99'] === '' && $state['4'] === 'Sudah' && $state['5'] === '') {
            $prevWaktu = $state['waktu_4'] ?? '';

            if (!$isRealtime) {
                // Pure Robot Mode
                $waktu5Str = RobotInference::infer('5', $prevWaktu, false, $this->config->robotRanges);
            } else {
                // Realtime Mode: strictly from real SIMRS tables
                $waktu5Str = $realEvents['5'] ?? '';
                if (empty($waktu5Str) && ($patient['stts'] ?? '') === 'Sudah' && $patient['tgl_registrasi'] === date('Y-m-d')) {
                    $waktu5Str = date('Y-m-d H:i:s');
                }
            }

            if (!empty($waktu5Str)) {
                $targetDate = $patient['tgl_registrasi'];
                if (date('Y-m-d', strtotime($waktu5Str)) !== $targetDate) {
                    $timePart  = date('H:i:s', strtotime($waktu5Str));
                    $waktu5Str = "{$targetDate} {$timePart}";
                }
                $t4Ts = strtotime($prevWaktu);
                if ($t4Ts !== false && strtotime($waktu5Str) <= $t4Ts) {
                    $waktu5Str = date('Y-m-d H:i:s', $t4Ts + 180);
                }
                if (strtotime($waktu5Str) <= time()) {
                    $r5 = $this->sendTaskId($kodebooking, $noRawat, '5', $waktu5Str, $label, $jenisresep);
                    if ($r5['ok']) {
                        $state['5'] = 'Sudah';
                        $state['waktu_5'] = $waktu5Str;
                    } elseif (($r5['reason'] ?? '') === 'preceding_tasks_missing') {
                        $missingId = $r5['missing_taskid'] ?? null;
                        if ($this->healPrecedingTasks($kodebooking, $noRawat, $patient, $state, $jadwal, $label, $isJkn, '5', $jenisresep, $missingId)) {
                            $retryR5 = $this->sendTaskId($kodebooking, $noRawat, '5', $waktu5Str, $label, $jenisresep);
                            if ($retryR5['ok']) {
                                $state['5'] = 'Sudah';
                                $state['waktu_5'] = $waktu5Str;
                            }
                        }
                    }
                }
            } else {
                $this->log->debug("[{$label}] {$noRawat} TaskID 5: real SIMRS event missing — waiting for polyclinic completion in mutasi_berkas / reg_periksa.stts='Sudah'");
            }
        }

        // ── Farmasi + Task 6 ──────────────────────────────────────────────
        if ($state['99'] === '' && $state['5'] === 'Sudah' && $state['6'] === '') {
            if (empty($noResep) && $this->config->skipFarmasiNoResep) {
                $this->log->info("[{$label}] {$noRawat} TaskID 6,7: skip — no resep (MOBILEJKN_SKIP_FARMASI_NO_RESEP=true)");
            } else {
                $prevWaktu = $state['waktu_5'] ?? '';

                if (!$isRealtime) {
                    // Pure Robot Mode
                    $waktu6Str = RobotInference::infer('6', $prevWaktu, $isRacikan, $this->config->robotRanges);
                } else {
                    // Realtime Mode: strictly from real SIMRS tables
                    $waktu6Str = $realEvents['6'] ?? '';
                }

                if (!empty($waktu6Str)) {
                    $targetDate = $patient['tgl_registrasi'];
                    if (date('Y-m-d', strtotime($waktu6Str)) !== $targetDate) {
                        $timePart  = date('H:i:s', strtotime($waktu6Str));
                        $waktu6Str = "{$targetDate} {$timePart}";
                    }
                    $this->sendFarmasi($kodebooking, $noRawat, $noResep);
                    $t5Ts = strtotime($prevWaktu);
                    if ($t5Ts !== false && strtotime($waktu6Str) <= $t5Ts) {
                        $waktu6Str = date('Y-m-d H:i:s', $t5Ts + 180);
                    }
                    if (strtotime($waktu6Str) <= time()) {
                        $r6 = $this->sendTaskId($kodebooking, $noRawat, '6', $waktu6Str, $label, $jenisresep);
                        if ($r6['ok']) {
                            $state['6'] = 'Sudah';
                            $state['waktu_6'] = $waktu6Str;
                        } elseif (($r6['reason'] ?? '') === 'preceding_tasks_missing') {
                            $missingId = $r6['missing_taskid'] ?? null;
                            if ($this->healPrecedingTasks($kodebooking, $noRawat, $patient, $state, $jadwal, $label, $isJkn, '6', $jenisresep, $missingId)) {
                                $retryR6 = $this->sendTaskId($kodebooking, $noRawat, '6', $waktu6Str, $label, $jenisresep);
                                if ($retryR6['ok']) {
                                    $state['6'] = 'Sudah';
                                    $state['waktu_6'] = $waktu6Str;
                                }
                            }
                        }
                    }
                } else {
                    $this->log->debug("[{$label}] {$noRawat} TaskID 6: real SIMRS event missing — waiting for prescription in resep_obat");
                }
            }
        }

        // ── Task 7: selesai farmasi ───────────────────────────────────────
        if ($state['99'] === '' && $state['6'] === 'Sudah' && $state['7'] === '') {
            $prevWaktu = $state['waktu_6'] ?? '';

            if (!$isRealtime) {
                // Pure Robot Mode
                $waktu7Str = RobotInference::infer('7', $prevWaktu, $isRacikan, $this->config->robotRanges);
            } else {
                // Realtime Mode: strictly from real SIMRS tables
                $waktu7Str = $realEvents['7'] ?? '';
            }

            if (!empty($waktu7Str)) {
                $targetDate = $patient['tgl_registrasi'];
                if (date('Y-m-d', strtotime($waktu7Str)) !== $targetDate) {
                    $timePart  = date('H:i:s', strtotime($waktu7Str));
                    $waktu7Str = "{$targetDate} {$timePart}";
                }
                $t6Ts = strtotime($prevWaktu);
                if ($t6Ts !== false && strtotime($waktu7Str) <= $t6Ts) {
                    $waktu7Str = date('Y-m-d H:i:s', $t6Ts + 180);
                }
                if (strtotime($waktu7Str) <= time()) {
                    $r7 = $this->sendTaskId($kodebooking, $noRawat, '7', $waktu7Str, $label, $jenisresep);
                    if ($r7['ok']) {
                        $state['7'] = 'Sudah';
                        $state['waktu_7'] = $waktu7Str;
                    } elseif (($r7['reason'] ?? '') === 'preceding_tasks_missing') {
                        $missingId = $r7['missing_taskid'] ?? null;
                        if ($this->healPrecedingTasks($kodebooking, $noRawat, $patient, $state, $jadwal, $label, $isJkn, '7', $jenisresep, $missingId)) {
                            $retryR7 = $this->sendTaskId($kodebooking, $noRawat, '7', $waktu7Str, $label, $jenisresep);
                            if ($retryR7['ok']) {
                                $state['7'] = 'Sudah';
                                $state['waktu_7'] = $waktu7Str;
                            }
                        }
                    }
                }
            } else {
                $this->log->debug("[{$label}] {$noRawat} TaskID 7: real SIMRS event missing — waiting for drug dispensing in resep_obat (tgl_penyerahan + jam_penyerahan)");
            }
        }

        // ── Task 99: cancellation ─────────────────────────────────────────
        // Fix #6: Use 'stts' column from the query result instead of per-patient DB call
        if ($state['99'] === '') {
            if (($patient['stts'] ?? '') === 'Batal') {
                $targetDate = $patient['tgl_registrasi'];
                $jamReg = substr($patient['jam_reg'] ?? '12:00:00', 0, 8);
                $cancelStr = "{$targetDate} {$jamReg}";
                $this->sendTaskId($kodebooking, $noRawat, '99', $cancelStr, $label, $jenisresep);
            }
        }
    }

    /**
     * Auto-heal missing preceding tasks (Task 1, 2, 3) when BPJS rejects Task N
     * with "TaskId=X belum ada" or "preceding_tasks_missing".
     */
    private function healPrecedingTasks(
        string $kodebooking,
        string $noRawat,
        array  $patient,
        array  &$state,
        array  $jadwal,
        string $label,
        bool   $isJkn,
        string $targetTaskId,
        string $jenisresep,
        ?string $missingTaskId = null
    ): bool {
        $missingId = $missingTaskId ?? (string)((int)$targetTaskId - 1);
        $this->log->warning("[HEAL] {$noRawat}: BPJS rejected TaskID {$targetTaskId} because preceding TaskID {$missingId} is missing. Initiating auto-healing...");

        $jamMulai  = $jadwal['jam_mulai'] ?? '08:00:00';
        $waktu3Str = $state['waktu_3'] ?? '';
        if (empty($waktu3Str)) {
            $waktu3Str = RobotInference::inferTask3($patient['tgl_registrasi'], $patient['jam_reg'] ?? '08:00:00', $jamMulai, $this->config->robotRanges);
        }

        // 1. Heal Task 1 if needed
        if ($missingId === '1' || ($state['1'] ?? '') !== 'Sudah') {
            $this->db->deleteTaskId($noRawat, '1');
            $waktu1Str = RobotInference::inferPrecedingTask('1', $waktu3Str, $this->config->robotRanges);
            if (!empty($waktu1Str)) {
                $r1 = $this->sendTaskId($kodebooking, $noRawat, '1', $waktu1Str, $label, $jenisresep);
                if ($r1['ok']) {
                    $state['1'] = 'Sudah';
                    $state['waktu_1'] = $waktu1Str;
                    $this->log->info("[HEAL] {$noRawat}: ✓ TaskID 1 auto-healed on BPJS.");
                }
            }
        }

        // 2. Heal Task 2 if needed
        if ($missingId === '2' || ($state['2'] ?? '') !== 'Sudah') {
            $this->db->deleteTaskId($noRawat, '2');
            $waktu2Str = RobotInference::inferPrecedingTask('2', $waktu3Str, $this->config->robotRanges);
            $t1Ts = strtotime($state['waktu_1'] ?? '');
            if ($t1Ts !== false && strtotime($waktu2Str) <= $t1Ts) {
                $waktu2Str = date('Y-m-d H:i:s', $t1Ts + 180);
            }
            if (!empty($waktu2Str)) {
                $r2 = $this->sendTaskId($kodebooking, $noRawat, '2', $waktu2Str, $label, $jenisresep);
                if ($r2['ok']) {
                    $state['2'] = 'Sudah';
                    $state['waktu_2'] = $waktu2Str;
                    $this->log->info("[HEAL] {$noRawat}: ✓ TaskID 2 auto-healed on BPJS.");
                }
            }
        }

        // 3. Heal Task 3 if needed
        if ($missingId === '3' || ($state['3'] ?? '') !== 'Sudah') {
            $this->db->deleteTaskId($noRawat, '3');
            $t2Ts = strtotime($state['waktu_2'] ?? '');
            if ($t2Ts !== false && strtotime($waktu3Str) <= $t2Ts) {
                $waktu3Str = date('Y-m-d H:i:s', $t2Ts + 180);
            }
            if (!empty($waktu3Str)) {
                $r3 = $this->sendTaskId($kodebooking, $noRawat, '3', $waktu3Str, $label, $jenisresep);
                if ($r3['ok']) {
                    $state['3'] = 'Sudah';
                    $state['waktu_3'] = $waktu3Str;
                    $this->log->info("[HEAL] {$noRawat}: ✓ TaskID 3 auto-healed on BPJS.");
                    return true;
                }
            }
        }

        return (($state['3'] ?? '') === 'Sudah');
    }

    /**
     * Generate inferred timing for a task using Box-Muller normal distribution
     * and send it to the BPJS API.
     */
    private function inferAndSendRobotTask(
        string $kodebooking,
        string $noRawat,
        string $taskId,
        string $prevWaktu,
        bool   $isRacikan,
        string $label,
        string $jenisresep = 'Tidak ada'
    ): ?string {
        $robotTime = RobotInference::infer($taskId, $prevWaktu, $isRacikan, $this->config->robotRanges);
        if (empty($robotTime)) {
            $this->log->debug("[{$label}] {$noRawat} TaskID {$taskId}: robot gates not satisfied — skip");
            return null;
        }

        $r = $this->sendTaskId($kodebooking, $noRawat, $taskId, $robotTime, $label, $jenisresep);
        if ($r['ok']) {
            // Fix #8: Track this send so syncTaskStateFromBpjs won't prune it
            if (!isset($this->sentThisCycle[$noRawat])) {
                $this->sentThisCycle[$noRawat] = [];
            }
            $this->sentThisCycle[$noRawat][$taskId] = true;
        }
        if ($r['reason'] === 'preceding_tasks_missing') {
            if ($this->healMissingPrecedingTasksOnDemand($kodebooking, $noRawat, $prevWaktu, $label, $jenisresep)) {
                $r = $this->sendTaskId($kodebooking, $noRawat, $taskId, $robotTime, $label, $jenisresep);
                if ($r['ok']) {
                    if (!isset($this->sentThisCycle[$noRawat])) {
                        $this->sentThisCycle[$noRawat] = [];
                    }
                    $this->sentThisCycle[$noRawat][$taskId] = true;
                }
            }
        }
        return $r['ok'] ? $robotTime : null;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Send a single task ID update to BPJS.
     * Matches Java pattern: INSERT → API call → DELETE on failure.
     *
     * @return array{ok: bool, reason: string} 'ok'=accepted, 'reason'=failure type
     *   reason: 'accepted', 'already_in_db', 'invalid_waktu', 'time_order' (BPJS time rejection), 'api_error'
     */
    private function sendTaskId(
        string $kodebooking,
        string $noRawat,
        string $taskId,
        string $waktuStr,
        string $label,
        string $jenisresep = 'Tidak ada',
        bool   $isRepeat = false
    ): array {
        // Step 1: Insert into DB (idempotency — Java: menyimpantf2)
        if (!$isRepeat) {
            if (!$this->db->insertTaskId($noRawat, $taskId, $waktuStr)) {
                $this->log->debug("[{$label}] {$noRawat} TaskID {$taskId}: already in DB — skip");
                $this->skipCount++;
                return ['ok' => false, 'reason' => 'already_in_db'];
            }
        }

        // Step 2: Convert to epoch ms (Java: parsedDate.getTime())
        $waktuMs = RobotInference::toEpochMs($waktuStr);
        if ($waktuMs === null) {
            $this->log->warning("[{$label}] {$noRawat} TaskID {$taskId}: invalid waktu '{$waktuStr}' — rollback");
            if (!$isRepeat) {
                $this->db->deleteTaskId($noRawat, $taskId);
            }
            $this->failCount++;
            return ['ok' => false, 'reason' => 'invalid_waktu'];
        }

        // Step 3: Send to BPJS
        $this->log->info("[{$label}] {$noRawat} TaskID {$taskId}" . ($isRepeat ? ' (repeat)' : '') . ": SEND waktu={$waktuMs} ({$waktuStr}) jenisresep={$jenisresep}");
        $result = $this->api->updateWaktu($kodebooking, $taskId, $waktuMs, $jenisresep);

        if ($result['success']) {
            $this->log->info("[{$label}] {$noRawat} TaskID {$taskId}" . ($isRepeat ? ' (repeat)' : '') . ": ✓ accepted");
            if ($isRepeat) {
                $this->db->updateTaskIdWaktu($noRawat, $taskId, $waktuStr);
            }
            $this->successCount++;
            return ['ok' => true, 'reason' => 'accepted'];
        }

        // Step 4: Rollback on failure
        if (!$isRepeat) {
            $this->db->deleteTaskId($noRawat, $taskId);
        }
        $msg = $result['message'] ?? '';
        $code = $result['code'] ?? '';
        $msgLower = strtolower($msg);

        // Detect if visit is cancelled/aborted (Task 99) on BPJS side
        if (str_contains($msgLower, 'taskid terakhir 99') || str_contains($msgLower, 'task id terakhir 99') || (str_contains($msgLower, 'terakhir') && str_contains($msgLower, '99'))) {
            $this->log->warning("[{$label}] {$noRawat} TaskID {$taskId}: BPJS reported Task 99 (Cancelled) — saving Task 99 locally to stop future retries.");
            $this->db->insertTaskId($noRawat, '99', date('Y-m-d H:i:s'));
            $this->failCount++;
            return ['ok' => false, 'reason' => 'cancelled_on_bpjs'];
        }

        // Detect BPJS time-ordering or booking-not-found rejections
        $isPrecedingMissing = (
            str_contains($msgLower, 'belum terkirim') ||
            str_contains($msgLower, 'sebelumnya belum') ||
            str_contains($msgLower, 'belum ada')
        );

        $missingTaskId = null;
        if ($isPrecedingMissing) {
            if (preg_match('/task\s*id\s*=?\s*(\d+)/i', $msg, $matches)) {
                $missingTaskId = $matches[1];
            }
            $reason = 'preceding_tasks_missing';
        } else {
            $isNotFound = (
                str_contains($msgLower, 'tidak ditemukan') ||
                str_contains($msgLower, 'tidak terdaftar') ||
                str_contains($msgLower, 'belum terdaftar') ||
                str_contains($msgLower, 'tidak ada') ||
                str_contains($msgLower, 'booking')
            );
            if ($isNotFound) {
                $reason = 'booking_not_found';
            } else {
                $isTimeOrder = (str_contains($msg, 'tidak boleh kurang') || str_contains($msg, 'waktu sebelumnya'));
                $reason = $isTimeOrder ? 'time_order' : 'api_error';
            }
        }

        $this->log->warning("[{$label}] {$noRawat} TaskID {$taskId}: ✗ {$code} — {$msg} (rolled back, reason={$reason})");
        $this->failCount++;
        return ['ok' => false, 'reason' => $reason, 'missing_taskid' => $missingTaskId];
    }

    /**
     * Send /antrean/farmasi/add for a patient.
     */
    private function sendFarmasi(string $kodebooking, string $noRawat, string $noResep): void
    {
        if (isset($this->farmasiSent[$noRawat])) return;

        if (empty($noResep)) {
            $this->log->debug("[FARMASI] {$noRawat}: no resep — skip farmasi");
            return;
        }

        $jenisResep = $this->db->fetchResepType($noResep);
        $payload    = PayloadBuilder::farmasi($kodebooking, $noResep, $jenisResep);

        $this->log->info("[FARMASI] {$noRawat}: SEND /antrean/farmasi/add (resep: {$noResep})");
        $result = $this->api->addFarmasiAntrean($payload);

        $this->farmasiSent[$noRawat] = true;

        $code = (string) ($result['code'] ?? '');
        $msg  = (string) ($result['message'] ?? '');
        $isFarmasiSuccess = $result['success'] || $code === '208' || ($code === '201' && str_contains(strtolower($msg), 'sudah ada'));

        if ($isFarmasiSuccess) {
            $this->log->info("[FARMASI] {$noRawat}: ✓ accepted (code={$code})");
        } else {
            $this->log->warning("[FARMASI] {$noRawat}: ✗ {$code} — {$msg}");
        }
    }

    /**
     * Synchronize task state from BPJS (/antrean/getlisttask) to the local database.
     * This handles cases where tasks were already sent to BPJS by other apps/portals,
     * but are missing in the local referensi_mobilejkn_bpjs_taskid table.
     */
    private function syncTaskStateFromBpjs(string $kodebooking, string $noRawat, array &$state, string $label): void
    {
        $res = $this->api->getListTask($kodebooking);
        if (!$res['success'] || !isset($res['data']) || !is_array($res['data'])) {
            return;
        }

        $tasks = $res['data'];
        $bpjsTasks = [];
        foreach ($tasks as $t) {
            $tId = (string) ($t['taskid'] ?? '');
            if (!empty($tId)) {
                $bpjsTasks[$tId] = $t;
            }
        }

        $updatedLocal = false;

        // 1. Sync BPJS -> Local (Add missing tasks locally, heal corrupted, or correct mismatched timestamps)
        foreach ($bpjsTasks as $tId => $t) {
            $tId = (string) $tId;
            $currentWaktu = (($state[$tId] ?? '') === 'Sudah') ? ($state['waktu_' . $tId] ?? '') : '';

            $waktuStr = '';
            if (!empty($t['wakturs'])) {
                $waktuStr = $this->parseBpjsDatetime((string) $t['wakturs']) ?? '';
            }
            if (empty($waktuStr) && !empty($t['waktu'])) {
                // Fallback to epoch milliseconds
                $waktuStr = date('Y-m-d H:i:s', (int) round($t['waktu'] / 1000));
            }

            $isCorrupted = (($state[$tId] ?? '') === 'Sudah') && (empty($currentWaktu) || str_starts_with($currentWaktu, '0000'));
            $isMismatch  = (($state[$tId] ?? '') === 'Sudah') && !empty($waktuStr) && ($currentWaktu !== $waktuStr);

            if (($state[$tId] ?? '') !== 'Sudah' || $isCorrupted || $isMismatch) {
                if (!empty($waktuStr)) {
                    if ($isCorrupted || $isMismatch) {
                        $this->db->deleteTaskId($noRawat, $tId);
                        $this->log->info("[{$label}] {$noRawat} TaskID {$tId}: corrected/aligned datetime in DB (old: '{$currentWaktu}', new: '{$waktuStr}')");
                    }
                    if ($this->db->insertTaskId($noRawat, $tId, $waktuStr)) {
                        if (!$isCorrupted && !$isMismatch) {
                            $this->log->info("[{$label}] {$noRawat} TaskID {$tId}: auto-synced from BPJS (waktu: {$waktuStr})");
                        }
                        $updatedLocal = true;
                    }
                }
            }
        }

        // 2. Sync Local -> BPJS (Prune local tasks that BPJS does NOT have)
        // If BPJS doesn't have it, local DB is out of sync (e.g. booking reset or failed API propagation)
        // Fix #8: Skip pruning for tasks just sent in this cycle (BPJS eventual consistency)
        $possibleTasks = ['1', '2', '3', '4', '5', '6', '7', '99'];
        foreach ($possibleTasks as $tId) {
            if (($state[$tId] ?? '') === 'Sudah' && !isset($bpjsTasks[$tId])) {
                // Guard: don't prune tasks we just successfully sent this cycle
                if (isset($this->sentThisCycle[$noRawat][$tId])) {
                    $this->log->debug("[{$label}] {$noRawat} TaskID {$tId}: recently sent this cycle, not pruning (BPJS may still be indexing)");
                    continue;
                }
                $this->log->warning("[{$label}] {$noRawat} TaskID {$tId}: local DB has 'Sudah' but BPJS doesn't — pruning local state to trigger recovery");
                $this->db->deleteTaskId($noRawat, $tId);
                $updatedLocal = true;
            }
        }

        // If we updated local records, reload the state array so the task chain has the latest data
        if ($updatedLocal) {
            $state = $this->db->loadTaskState($noRawat);
        }
    }

    /**
     * Safely parse BPJS datetime formats (d-m-Y H:i:s or Y-m-d H:i:s)
     * and normalize to standard Y-m-d H:i:s.
     */
    private function parseBpjsDatetime(string $waktuStr): ?string
    {
        $waktuClean = trim(str_replace([' WIB', ' WITA', ' WIT'], '', $waktuStr));
        if (empty($waktuClean)) {
            return null;
        }

        // Try Y-m-d H:i:s
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $waktuClean);
        if ($dt && $dt->format('Y-m-d H:i:s') === $waktuClean) {
            return $waktuClean;
        }

        // Try d-m-Y H:i:s
        $dt2 = \DateTime::createFromFormat('d-m-Y H:i:s', $waktuClean);
        if ($dt2 && $dt2->format('d-m-Y H:i:s') === $waktuClean) {
            return $dt2->format('Y-m-d H:i:s');
        }

        // Fallback to strtotime
        $ts = strtotime($waktuClean);
        if ($ts !== false) {
            return date('Y-m-d H:i:s', $ts);
        }

        return null;
    }



    /**
     * Heal missing Task 1 and Task 2 on-demand when a later task is rejected by BPJS.
     */
    private function healMissingPrecedingTasksOnDemand(string $kodebooking, string $noRawat, string $waktu3Str, string $label, string $jenisresep): bool
    {
        if (empty($waktu3Str)) {
            return false;
        }

        $t3Ts = strtotime($waktu3Str);
        if ($t3Ts === false) {
            return false;
        }

        $this->log->info("[{$label}] {$noRawat}: healing missing preceding tasks (Task 1 & 2) on-demand using Task 3 time '{$waktu3Str}'");

        // Send Task 1
        $waktu1Str = date('Y-m-d H:i:s', $t3Ts - 1800); // 30 minutes before Task 3
        $waktu1Ms = $t3Ts * 1000 - 1800000;
        $this->log->info("[{$label}] {$noRawat} TaskID 1: sending on-demand waktu={$waktu1Ms} ({$waktu1Str})");

        // Save locally first
        $this->db->insertTaskId($noRawat, '1', $waktu1Str);
        $res1 = $this->api->updateWaktu($kodebooking, '1', $waktu1Ms, $jenisresep);
        if ($res1['success']) {
            $this->log->info("[{$label}] {$noRawat} TaskID 1: ✓ healed successfully");
        } else {
            $this->log->warning("[{$label}] {$noRawat} TaskID 1: ✗ failed to heal ({$res1['code']}): {$res1['message']}");
            $this->db->deleteTaskId($noRawat, '1');
            return false; // If Task 1 fails, we cannot proceed
        }

        // Send Task 2
        $waktu2Str = date('Y-m-d H:i:s', $t3Ts - 900); // 15 minutes before Task 3
        $waktu2Ms = $t3Ts * 1000 - 900000;
        $this->log->info("[{$label}] {$noRawat} TaskID 2: sending on-demand waktu={$waktu2Ms} ({$waktu2Str})");

        // Save locally first
        $this->db->insertTaskId($noRawat, '2', $waktu2Str);
        $res2 = $this->api->updateWaktu($kodebooking, '2', $waktu2Ms, $jenisresep);
        if (!$res2['success']) {
            $this->log->warning("[{$label}] {$noRawat} TaskID 2: ✗ failed to heal ({$res2['code']}): {$res2['message']}");
            $this->db->deleteTaskId($noRawat, '2');
            return false;
        }
        $this->log->info("[{$label}] {$noRawat} TaskID 2: ✓ healed successfully");

        // Resend Task 3 to BPJS to advance the state machine back to Task 3
        $this->log->info("[{$label}] {$noRawat} TaskID 3: resending to BPJS to advance state machine after healing");
        $res3 = $this->api->updateWaktu($kodebooking, '3', $t3Ts * 1000, $jenisresep);
        if ($res3['success']) {
            $this->log->info("[{$label}] {$noRawat} TaskID 3: ✓ resent successfully");
            return true;
        } else {
            $this->log->warning("[{$label}] {$noRawat} TaskID 3: ✗ failed to resend ({$res3['code']}): {$res3['message']}");
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Block 5: Unsent SEP Recovery (Fix #4 — from ANTROL-ROBOT.JAVA)
    // Matches Java ANTROL-ROBOT.JAVA lines 1233-1769
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Process BPJS patients who have a SEP in bridging_sep but zero taskid records.
     * This is the safety net for patients completely missed by Blocks 1-4.
     * Dynamically creates bookings and runs the full task chain.
     */
    private function processUnsentSepPatients(string $dateFrom, string $dateTo): void
    {
        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[BLOCK 5] Processing unsent SEP patients (safety net)...");

        try {
            $patients = $this->db->fetchUnsentSepPatients($dateFrom, $dateTo);
        } catch (\PDOException $e) {
            $this->log->error("[BLOCK 5] DB query failed: " . $e->getMessage());
            $this->failCount++;
            return;
        }

        $total = count($patients);
        if ($total === 0) {
            $this->log->info("[BLOCK 5] No unsent SEP patients found.");
            return;
        }
        $this->log->info("[BLOCK 5] Found {$total} unsent SEP patient(s) — full recovery needed.");

        // Eager Load ALL dictionaries (same pattern as Block 4)
        $dokterDict  = $this->db->fetchAllDokterBpjsMappings();
        $poliDict    = $this->db->fetchAllPoliBpjsMappings();
        $jadwalDict  = $this->db->fetchAllJadwal();

        $noRawats              = array_column($patients, 'no_rawat');
        $taskStates            = $this->db->fetchBatchTaskStates($noRawats);
        $noResepMap            = $this->db->fetchBatchNoResep($noRawats);
        $racikanSet            = $this->db->fetchBatchIsRacikan(array_filter(array_values($noResepMap)));
        $mutasiBerkasMap       = $this->db->fetchBatchMutasiBerkas($noRawats);
        $pemeriksaanRalanMap   = $this->db->fetchBatchPemeriksaanRalan($noRawats);
        $mutasiDiterimaMap     = $this->db->fetchBatchMutasiDiterima($noRawats);
        $mutasiKembaliMap      = $this->db->fetchBatchMutasiKembali($noRawats);
        $resepRalanMap         = $this->db->fetchBatchResepObatRalan($noRawats);
        $resepPenyerahanMap    = $this->db->fetchBatchResepObatPenyerahan($noRawats);

        foreach ($patients as $idx => $p) {
            $noRawat     = $p['no_rawat'];
            $kodebooking = $noRawat; // Java uses no_rawat as kodebooking for unsent SEP
            $this->log->info("[BLOCK 5] ── Patient " . ($idx + 1) . "/{$total}: {$noRawat} ──");

            // Check for missing master data from LEFT JOIN (BUG-D: zero patient loss)
            if (empty($p['nm_dokter']) || empty($p['nm_poli']) || empty($p['no_ktp']) || empty($p['no_peserta'])) {
                $this->log->warning("[BLOCK 5] {$noRawat}: missing master data (nm_dokter='{$p['nm_dokter']}', nm_poli='{$p['nm_poli']}', no_ktp='{$p['no_ktp']}', no_peserta='{$p['no_peserta']}') — patient fetched but needs manual review");
            }

            // Resolve jadwal from pre-loaded dictionary (Fix #7)
            $hari   = $this->db->hariForDate($p['tgl_registrasi']);
            $jamReg = $p['jam_reg'] ?? '08:00:00';
            $jadwal = $this->db->lookupJadwal($jadwalDict, $hari, $p['kd_dokter'], $p['kd_poli'], $jamReg);
            if (!$jadwal) {
                // Log which patient is being skipped and WHY (BUG-D: clear reason for skip)
                $this->log->warning("[BLOCK 5] {$noRawat}: no jadwal found (hari={$hari}, kd_dokter={$p['kd_dokter']}, kd_poli={$p['kd_poli']}) — patient fetched but SKIPPED (no schedule mapping)");
                continue;
            }

            // BPJS mapping lookup from pre-loaded dictionaries
            $dokterBpjs = $dokterDict[$p['kd_dokter']] ?? '';
            $poliBpjs   = $poliDict[$p['kd_poli']] ?? '';
            if (empty($dokterBpjs) || empty($poliBpjs)) {
                $this->log->debug("[BLOCK 5] {$noRawat}: no BPJS mapping — skipping");
                continue;
            }

            $p['jam_mulai']      = $jadwal['jam_mulai'];
            $p['jam_selesai']    = $jadwal['jam_selesai'];
            $p['kuota']          = $jadwal['kuota'];
            $p['kd_dokter_bpjs'] = $dokterBpjs;
            $p['kd_poli_bpjs']   = $poliBpjs;

            // Clean up any stale records if no_rawat was recycled after previous patient cancellation/deletion
            if ($this->db->purgeStalePatientRecords($noRawat, $p['no_rkm_medis'] ?? '')) {
                $taskStates[$noRawat] = ['1' => '', '2' => '', '3' => '', '4' => '', '5' => '', '6' => '', '7' => '', '99' => ''];
            }

            // Resolve kodebooking matching index.php (unified MAX+1 sequence when formatOnsiteKodebooking=true)
            $kodebooking = $this->db->fetchOrGenerateNobooking($p, $this->config->formatOnsiteKodebooking);

            // Load pre-fetched state, prescription, racikan, mutasi berkas
            $state    = $taskStates[$noRawat] ?? ['1' => '', '2' => '', '3' => '', '4' => '', '5' => '', '6' => '', '7' => '', '99' => ''];
            $noResep  = $noResepMap[$noRawat] ?? '';
            $isRacikan = isset($racikanSet[$noResep]);

            // SEP patients are always kd_pj='BPJ' (JKN)
            // ── IMMEDIATE /antrean/add ────────────────────────────────────
            // Matches Java robot: if Task 3 is empty locally, send /antrean/add IMMEDIATELY!
            if (($state['3'] ?? '') === '') {
                $nomorRef = $this->db->fetchNomorReferensi($noRawat);
                $payload  = PayloadBuilder::onsitePatient($p, true, $nomorRef, $kodebooking, $this->config->nomorantreanFormat);
                $this->log->info("[BLOCK 5] {$noRawat} (kodebooking={$kodebooking}): SEND /antrean/add (jenispasien=JKN)");
                $addResult = $this->api->addAntrean($payload);
                $addCode   = $addResult['code'] ?? '';
                if ($addResult['success'] || $addCode === '208') {
                    $this->db->saveToReferensiMobileJkn($p, $kodebooking, $nomorRef);
                    $this->log->info("[BLOCK 5] {$noRawat}: ✓ /antrean/add accepted (code={$addCode})");
                    $this->successCount++;
                } else {
                    $this->log->warning("[BLOCK 5] {$noRawat}: ✗ /antrean/add failed ({$addCode}) — {$addResult['message']}");
                    $this->db->deleteReferensiMobileJkn($noRawat, $kodebooking);
                    $this->failCount++;
                    continue; // Skip task chain because booking was not accepted on BPJS
                }
            }

            $realEvents = [
                '3' => $mutasiBerkasMap[$noRawat] ?? '',
                '4' => $pemeriksaanRalanMap[$noRawat] ?? ($mutasiDiterimaMap[$noRawat] ?? ''),
                '5' => $mutasiKembaliMap[$noRawat] ?? '',
                '6' => $resepRalanMap[$noRawat] ?? '',
                '7' => $resepPenyerahanMap[$noRawat] ?? '',
            ];

            // Run the task chain
            $this->processTaskChain($kodebooking, $noRawat, $p, $state, $jadwal, 'BLOCK 5', true, $noResep, $isRacikan, $realEvents);
        }
    }
}
