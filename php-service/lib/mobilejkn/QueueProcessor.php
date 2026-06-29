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

        foreach ($bookings as $b) {
            $nb = $b['nobooking'];

            // Auto-heal statuskirim: If booking already has tasks sent on BPJS, sync statuskirim=Sudah
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
                $hari   = $this->db->hariForDate($b['tanggalperiksa']);
                $jadwal = $this->db->fetchJadwal($hari, $b['kodedokter'], $b['kodepoli']);
                $jamMulai = $jadwal['jam_mulai'] ?? '08:00:00';

                // Get reg_periksa details (specifically jam_reg) for the patient
                $regInfo = $this->db->fetchPatientRegInfo($b['no_rawat']);
                $jamReg = $regInfo['jam_reg'] ?? '08:00:00';

                $datajam = RobotInference::inferTask3($b['tanggalperiksa'], $jamReg, $jamMulai);

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

        // Eager Load Task States, Prescriptions, Racikan, Mutasi Berkas, and Jadwal
        $noRawats        = array_column($patients, 'no_rawat');
        $taskStates      = $this->db->fetchBatchTaskStates($noRawats);
        $noResepMap      = $this->db->fetchBatchNoResep($noRawats);
        $racikanSet      = $this->db->fetchBatchIsRacikan(array_filter(array_values($noResepMap)));
        $mutasiBerkasMap = $this->db->fetchBatchMutasiBerkas($noRawats);
        $jadwalDict      = $this->db->fetchAllJadwal();

        foreach ($patients as $idx => $p) {
            $noRawat     = $p['no_rawat'];
            $kodebooking = $p['nobooking'];
            $this->log->info("[BLOCK 3] ── Patient " . ($idx + 1) . "/{$total}: {$noRawat} ──");

            // Load task state from pre-fetched dictionary
            $state = $taskStates[$noRawat] ?? ['3' => '', '4' => '', '5' => '', '6' => '', '7' => '', '99' => ''];

            // Check for missing master data from LEFT JOIN (BUG-D: zero patient loss)
            if (empty($p['nm_dokter']) || empty($p['nm_poli'])) {
                $this->log->warning("[BLOCK 3] {$noRawat}: missing master data (nm_dokter='{$p['nm_dokter']}', nm_poli='{$p['nm_poli']}') — patient fetched but needs manual review");
            }

            // Resolve jadwal from pre-loaded dictionary (Fix #7)
            $hari   = $this->db->hariForDate($p['tgl_registrasi']);
            $jadwal = $this->db->lookupJadwal($jadwalDict, $hari, $p['kd_dokter'], $p['kd_poli']);
            if (!$jadwal) {
                // Log which patient is being skipped and WHY (BUG-D: clear reason for skip)
                $this->log->warning("[BLOCK 3] {$noRawat}: no jadwal found (hari={$hari}, kd_dokter={$p['kd_dokter']}, kd_poli={$p['kd_poli']}) — patient fetched but SKIPPED (no schedule mapping)");
                continue;
            }

            // Defer check: if configured, skip today's patients until the polyclinic has closed
            if ($this->config->deferRobotInfer && $p['tgl_registrasi'] === date('Y-m-d') && date('H:i:s') < $jadwal['jam_selesai']) {
                $this->log->debug("[BLOCK 3] {$noRawat}: polyclinic is still active today — deferring robot inference until after {$jadwal['jam_selesai']}");
                continue;
            }

            // Load pre-fetched prescription number and racikan status (Fix #5)
            $noResep   = $noResepMap[$noRawat] ?? '';
            $isRacikan = isset($racikanSet[$noResep]);

            // Process task chain: 3 → 4 → 5 → [farmasi] → 6 → 7
            $this->processTaskChain($kodebooking, $noRawat, $p, $state, $jadwal, 'BLOCK 3', true, $noResep, $isRacikan, $mutasiBerkasMap[$noRawat] ?? '');
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

        $noRawats    = array_column($patients, 'no_rawat');
        $taskStates  = $this->db->fetchBatchTaskStates($noRawats);
        $noResepMap  = $this->db->fetchBatchNoResep($noRawats);
        $racikanSet  = $this->db->fetchBatchIsRacikan(array_filter(array_values($noResepMap)));
        $mutasiBerkasMap = $this->db->fetchBatchMutasiBerkas($noRawats);

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
            $jadwal = $this->db->lookupJadwal($jadwalDict, $hari, $p['kd_dokter'], $p['kd_poli']);
            if (!$jadwal) {
                // Log which patient is being skipped and WHY (BUG-D: clear reason for skip)
                $this->log->warning("[BLOCK 4] {$noRawat}: no jadwal found (hari={$hari}, kd_dokter={$p['kd_dokter']}, kd_poli={$p['kd_poli']}) — patient fetched but SKIPPED (no schedule mapping)");
                continue;
            }

            // Defer check: if configured, skip today's patients until the polyclinic has closed
            if ($this->config->deferRobotInfer && $p['tgl_registrasi'] === date('Y-m-d') && date('H:i:s') < $jadwal['jam_selesai']) {
                $this->log->debug("[BLOCK 4] {$noRawat}: polyclinic is still active today — deferring robot inference until after {$jadwal['jam_selesai']}");
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

            // Load existing task state from pre-fetched dictionary
            $state = $taskStates[$noRawat] ?? ['3' => '', '4' => '', '5' => '', '6' => '', '7' => '', '99' => ''];

            // Load pre-fetched prescription number and racikan status (Fix #5)
            $noResep   = $noResepMap[$noRawat] ?? '';
            $isRacikan = isset($racikanSet[$noResep]);

            // Directly run the task chain. If the booking is not registered on BPJS yet,
            // Task 3 will automatically detect 'booking_not_found' and register it dynamically.
            $this->processTaskChain($kodebooking, $noRawat, $p, $state, $jadwal, 'BLOCK 4', $isJkn, $noResep, $isRacikan, $mutasiBerkasMap[$noRawat] ?? '');
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Core: Per-patient task chain — exact Java robot logic
    // ═══════════════════════════════════════════════════════════════════════

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
        string $realTask3 = ''
    ): void {
        $jamMulai   = $jadwal['jam_mulai'] ?? '08:00:00';
        $jamSelesai = $jadwal['jam_selesai'] ?? '14:00:00';

        // Full-Robot Mode: always allow robot inference immediately (strict sequence gates apply)
        $allowRobot = true;

        // Determine prescription info from pre-loaded data (Fix #5)
        $jenisresep = empty($noResep) ? 'Tidak ada' : ($isRacikan ? 'Racikan' : 'Non racikan');

        // Smart-Bypass Caching: check if patient's active milestones are already fully completed locally
        $isCompleted = ($state['3'] === 'Sudah' && $state['4'] === 'Sudah' && $state['5'] === 'Sudah');
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

        // ── Task 3: mulai tunggu poli ─────────────────────────────────────
        if ($state['3'] === '') {
            // Fix #1: Use real Task 3 timestamp from mutasi_berkas.dikirim when available
            // (matches ANTROL-ROBOT.JAVA behavior). Only infer when real data is missing.
            if (!empty($realTask3)) {
                $datajam = $realTask3;
                $this->log->debug("[{$label}] {$noRawat} TaskID 3: real timestamp from mutasi_berkas.dikirim = {$datajam}");
            } else {
                $datajam = RobotInference::inferTask3($patient['tgl_registrasi'], $patient['jam_reg'], $jamMulai);
                $this->log->debug("[{$label}] {$noRawat} TaskID 3: robot-inferred to {$datajam}");
            }

            if (!empty($datajam)) {
                // Future-time gate: don't send if the time hasn't happened yet
                // e.g., jam_mulai=11:00 but now()=07:41 → wait until 11:00 passes
                $datajamTs = strtotime($datajam);
                if ($datajamTs !== false && $datajamTs > time()) {
                    $this->log->debug("[{$label}] {$noRawat} TaskID 3: time {$datajam} is in the future — wait");
                } else {
                    $r = $this->sendTaskId($kodebooking, $noRawat, '3', $datajam, $label, $jenisresep);
                    if ($r['ok']) {
                        $state['3'] = 'Sudah';
                        $state['waktu_3'] = $datajam;
                    } elseif ($r['reason'] === 'booking_not_found') {
                        if ($patient['tgl_registrasi'] < date('Y-m-d')) {
                            // Fix #2: Do NOT auto-mark as cancelled. Booking might be delayed
                            // in BPJS indexing (eventual consistency). Skip and retry next cycle.
                            $this->log->warning("[{$label}] {$noRawat} TaskID 3 failed: booking_not_found, past date ({$patient['tgl_registrasi']}). Skipping — will retry next cycle. (NOT marking cancelled)");
                            $state['3'] = 'Belum';
                        } else {
                            $this->log->info("[{$label}] {$noRawat} TaskID 3 failed: booking_not_found. Triggering dynamic booking recovery...");

                            // Dynamically resolve /antrean/add payload
                            $payload = null;
                            if ($isJkn) {
                                $bookingData = $this->db->fetchBookingByNoRawat($noRawat);
                                if ($bookingData) {
                                    $payload = PayloadBuilder::jknBooking($bookingData);
                                } else {
                                    $nomorRef = $this->db->fetchNomorReferensi($noRawat);
                                    $payload  = PayloadBuilder::onsitePatient($patient, true, $nomorRef);
                                }
                            } else {
                                $payload = PayloadBuilder::onsitePatient($patient, false, '');
                            }

                            if ($payload) {
                                $this->log->info("[{$label}] {$noRawat}: sending dynamic /antrean/add (jenispasien=" . ($isJkn ? 'JKN' : 'NON JKN') . ")");
                                $addResult = $this->api->addAntrean($payload);
                                $addCode   = $addResult['code'] ?? '';

                                if ($addResult['success'] || $addCode === '208') {
                                    $this->log->info("[{$label}] {$noRawat}: dynamic /antrean/add recovery accepted (code={$addCode}). Retrying Task ID 3 immediately.");
                                    if ($isJkn && !empty($bookingData['nobooking'])) {
                                        $this->db->markBookingAsSent($bookingData['nobooking']);
                                    }
                                    // Retry sending Task 3
                                    $retryR = $this->sendTaskId($kodebooking, $noRawat, '3', $datajam, $label, $jenisresep);
                                    if ($retryR['ok']) {
                                        $state['3'] = 'Sudah';
                                        $state['waktu_3'] = $datajam;
                                    } else {
                                        $state['3'] = 'Belum';
                                    }
                                } else {
                                    $this->log->warning("[{$label}] {$noRawat}: dynamic /antrean/add recovery failed ({$addCode}): {$addResult['message']}");
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
            // Ensure physician service (Task 4) is never inferred before polyclinic opens (jam_mulai)
            $openTime = $patient['tgl_registrasi'] . ' ' . $jamMulai;
            if (strtotime($prevWaktu) < strtotime($openTime)) {
                $prevWaktu = $openTime;
            }
            $datajam = $this->inferAndSendRobotTask($kodebooking, $noRawat, '4', $prevWaktu, false, $label, $jenisresep);
            if ($datajam !== null) {
                $state['4'] = 'Sudah';
                $state['waktu_4'] = $datajam;
            } else {
                $state = $this->db->loadTaskState($noRawat);
            }
        }

        // ── Task 5: selesai pelayanan poli ────────────────────────────────
        if ($state['99'] === '' && $state['4'] === 'Sudah' && $state['5'] === '') {
            $prevWaktu = $state['waktu_4'] ?? '';
            $datajam = $this->inferAndSendRobotTask($kodebooking, $noRawat, '5', $prevWaktu, false, $label, $jenisresep);
            if ($datajam !== null) {
                $state['5'] = 'Sudah';
                $state['waktu_5'] = $datajam;
            } else {
                $state = $this->db->loadTaskState($noRawat);
            }
        }

        // ── Farmasi + Task 6 ──────────────────────────────────────────────
        if ($state['99'] === '' && $state['5'] === 'Sudah' && $state['6'] === '') {
            // Skip tasks 6/7 if patient has no prescription and config says to skip
            if (empty($noResep) && $this->config->skipFarmasiNoResep) {
                $this->log->info("[{$label}] {$noRawat} TaskID 6,7: skip — no resep (MOBILEJKN_SKIP_FARMASI_NO_RESEP=true)");
            } else {
                $this->sendFarmasi($kodebooking, $noRawat, $noResep);

                $prevWaktu = $state['waktu_5'] ?? '';
                $datajam   = $this->inferAndSendRobotTask($kodebooking, $noRawat, '6', $prevWaktu, $isRacikan, $label, $jenisresep);
                if ($datajam !== null) {
                    $state['6'] = 'Sudah';
                    $state['waktu_6'] = $datajam;
                } else {
                    $state = $this->db->loadTaskState($noRawat);
                }
            }
        }

        // ── Task 7: selesai farmasi ───────────────────────────────────────
        if ($state['99'] === '' && $state['6'] === 'Sudah' && $state['7'] === '') {
            $prevWaktu = $state['waktu_6'] ?? '';
            $datajam   = $this->inferAndSendRobotTask($kodebooking, $noRawat, '7', $prevWaktu, $isRacikan, $label, $jenisresep);
            if ($datajam !== null) {
                $state['7'] = 'Sudah';
                $state['waktu_7'] = $datajam;
            } else {
                $state = $this->db->loadTaskState($noRawat);
            }
        }

        // ── Task 99: cancellation ─────────────────────────────────────────
        // Fix #6: Use 'stts' column from the query result instead of per-patient DB call
        if ($state['99'] === '') {
            if (($patient['stts'] ?? '') === 'Batal') {
                $nowStr = date('Y-m-d H:i:s');
                $this->sendTaskId($kodebooking, $noRawat, '99', $nowStr, $label, $jenisresep);
            }
        }
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
    private function sendTaskId(string $kodebooking, string $noRawat, string $taskId, string $waktuStr, string $label, string $jenisresep = 'Tidak ada'): array
    {
        // Step 1: Insert into DB (idempotency — Java: menyimpantf2)
        if (!$this->db->insertTaskId($noRawat, $taskId, $waktuStr)) {
            $this->log->debug("[{$label}] {$noRawat} TaskID {$taskId}: already in DB — skip");
            $this->skipCount++;
            return ['ok' => false, 'reason' => 'already_in_db'];
        }

        // Step 2: Convert to epoch ms (Java: parsedDate.getTime())
        $waktuMs = RobotInference::toEpochMs($waktuStr);
        if ($waktuMs === null) {
            $this->log->warning("[{$label}] {$noRawat} TaskID {$taskId}: invalid waktu '{$waktuStr}' — rollback");
            $this->db->deleteTaskId($noRawat, $taskId);
            $this->failCount++;
            return ['ok' => false, 'reason' => 'invalid_waktu'];
        }

        // Step 3: Send to BPJS
        $this->log->info("[{$label}] {$noRawat} TaskID {$taskId}: SEND waktu={$waktuMs} ({$waktuStr}) jenisresep={$jenisresep}");
        $result = $this->api->updateWaktu($kodebooking, $taskId, $waktuMs, $jenisresep);

        if ($result['success']) {
            $this->log->info("[{$label}] {$noRawat} TaskID {$taskId}: ✓ accepted");
            $this->successCount++;
            return ['ok' => true, 'reason' => 'accepted'];
        }

        // Step 4: Rollback on failure
        $this->db->deleteTaskId($noRawat, $taskId);
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

        $isNotFound = (
            str_contains($msgLower, 'tidak ditemukan') ||
            str_contains($msgLower, 'tidak terdaftar') ||
            str_contains($msgLower, 'belum terdaftar') ||
            str_contains($msgLower, 'tidak ada') ||
            str_contains($msgLower, 'booking')
        );

        if ($isPrecedingMissing) {
            $reason = 'preceding_tasks_missing';
        } elseif ($isNotFound) {
            $reason = 'booking_not_found';
        } else {
            $isTimeOrder = (str_contains($msg, 'tidak boleh kurang') || str_contains($msg, 'waktu sebelumnya'));
            $reason = $isTimeOrder ? 'time_order' : 'api_error';
        }

        $this->log->warning("[{$label}] {$noRawat} TaskID {$taskId}: ✗ {$code} — {$msg} (rolled back, reason={$reason})");
        $this->failCount++;
        return ['ok' => false, 'reason' => $reason];
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
        if (!$res['success'] || !is_array($res['data'])) {
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
        $possibleTasks = ['3', '4', '5', '6', '7', '99'];
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

        $noRawats       = array_column($patients, 'no_rawat');
        $taskStates     = $this->db->fetchBatchTaskStates($noRawats);
        $noResepMap     = $this->db->fetchBatchNoResep($noRawats);
        $racikanSet     = $this->db->fetchBatchIsRacikan(array_filter(array_values($noResepMap)));
        $mutasiBerkasMap = $this->db->fetchBatchMutasiBerkas($noRawats);

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
            $jadwal = $this->db->lookupJadwal($jadwalDict, $hari, $p['kd_dokter'], $p['kd_poli']);
            if (!$jadwal) {
                // Log which patient is being skipped and WHY (BUG-D: clear reason for skip)
                $this->log->warning("[BLOCK 5] {$noRawat}: no jadwal found (hari={$hari}, kd_dokter={$p['kd_dokter']}, kd_poli={$p['kd_poli']}) — patient fetched but SKIPPED (no schedule mapping)");
                continue;
            }

            // Defer check
            if ($this->config->deferRobotInfer && $p['tgl_registrasi'] === date('Y-m-d') && date('H:i:s') < $jadwal['jam_selesai']) {
                $this->log->debug("[BLOCK 5] {$noRawat}: polyclinic is still active today — deferring robot inference until after {$jadwal['jam_selesai']}");
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

            // Load pre-fetched state, prescription, racikan, mutasi berkas
            $state    = $taskStates[$noRawat] ?? ['3' => '', '4' => '', '5' => '', '6' => '', '7' => '', '99' => ''];
            $noResep  = $noResepMap[$noRawat] ?? '';
            $isRacikan = isset($racikanSet[$noResep]);
            $realTask3 = $mutasiBerkasMap[$noRawat] ?? '';

            // SEP patients are always kd_pj='BPJ' (JKN)
            // Run the task chain — Block 5 processes just like Block 4 (dynamic booking on need)
            $this->processTaskChain($kodebooking, $noRawat, $p, $state, $jadwal, 'BLOCK 5', true, $noResep, $isRacikan, $realTask3);
        }
    }
}
