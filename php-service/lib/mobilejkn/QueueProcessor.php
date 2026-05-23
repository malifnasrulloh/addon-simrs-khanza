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

        foreach ($patients as $idx => $p) {
            $noRawat     = $p['no_rawat'];
            $kodebooking = $p['nobooking'];
            $this->log->info("[BLOCK 3] ── Patient " . ($idx + 1) . "/{$total}: {$noRawat} ──");

            // Load task state from DB (Java lines 246–274)
            $state = $this->db->loadTaskState($noRawat);

            // Resolve jadwal for this patient's registration date
            $hari   = $this->db->hariForDate($p['tgl_registrasi']);
            $jadwal = $this->db->fetchJadwal($hari, $p['kd_dokter'], $p['kd_poli']);
            if (!$jadwal) {
                $this->log->debug("[BLOCK 3] {$noRawat}: no jadwal found for {$hari} — skipping");
                continue;
            }

            // Process task chain: 3 → 4 → 5 → [farmasi] → 6 → 7
            $this->processTaskChain($kodebooking, $noRawat, $p, $state, $jadwal, 'BLOCK 3', true);
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

        foreach ($patients as $idx => $p) {
            $noRawat     = $p['no_rawat'];
            $kodebooking = $noRawat; // Java uses no_rawat as kodebooking for on-site
            $kdPj        = $p['kd_pj'] ?? '';
            $isJkn       = ($kdPj === 'BPJ');
            $this->log->info("[BLOCK 4] ── Patient " . ($idx + 1) . "/{$total}: {$noRawat} (kd_pj={$kdPj}) ──");

            // Java: per-patient jadwal lookup (line 706–732)
            $hari   = $this->db->hariForDate($p['tgl_registrasi']);
            $jadwal = $this->db->fetchJadwal($hari, $p['kd_dokter'], $p['kd_poli']);
            if (!$jadwal) {
                $this->log->debug("[BLOCK 4] {$noRawat}: no jadwal for {$hari} — skipping");
                continue;
            }

            // Java: per-patient mapping lookup (lines 718–724)
            $dokterBpjs = $this->db->fetchDokterBpjs($p['kd_dokter']);
            $poliBpjs   = $this->db->fetchPoliBpjs($p['kd_poli']);
            if (empty($dokterBpjs) || empty($poliBpjs)) {
                $this->log->debug("[BLOCK 4] {$noRawat}: no BPJS mapping — skipping");
                continue;
            }

            $p['jam_mulai']     = $jadwal['jam_mulai'];
            $p['jam_selesai']   = $jadwal['jam_selesai'];
            $p['kuota']         = $jadwal['kuota'];
            $p['kd_dokter_bpjs'] = $dokterBpjs;
            $p['kd_poli_bpjs']   = $poliBpjs;

            // Load existing task state
            $state = $this->db->loadTaskState($noRawat);
            $hasSentTasks = ($state['3'] === 'Sudah' || $state['4'] === 'Sudah');

            // Step 1: /antrean/add — only if no tasks sent yet
            if (!$hasSentTasks) {
                $nomorRef = $isJkn ? $this->db->fetchNomorReferensi($noRawat) : '';
                $payload  = PayloadBuilder::onsitePatient($p, $isJkn, $nomorRef);

                $this->log->info("[BLOCK 4] {$noRawat}: SEND /antrean/add (jenispasien=" . ($isJkn ? 'JKN' : 'NON JKN') . ")");
                $addResult = $this->api->addAntrean($payload);
                $addCode   = $addResult['code'] ?? '';

                if ($addResult['success'] || $addCode === '208') {
                    // 200=OK, 208=duplicate booking
                    // Both mean booking is on BPJS side → proceed to task chain
                    $this->log->info("[BLOCK 4] {$noRawat}: ✓ /antrean/add accepted (code={$addCode})");
                    $this->successCount++;
                } else {
                    // Truly fatal: network error, auth failure, etc → skip this patient
                    $this->log->warning("[BLOCK 4] {$noRawat}: ✗ /antrean/add failed ({$addCode}): {$addResult['message']}");
                    $this->failCount++;
                    continue;
                }
            }

            // Step 2: Sequential task chain (same as Block 3)
            $this->processTaskChain($kodebooking, $noRawat, $p, $state, $jadwal, 'BLOCK 4', $isJkn);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Core: Per-patient task chain — exact Java robot logic
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Process task chain 3→4→5→[farmasi]→6→7→[99] for a single patient.
     *
     * Strategy per task: try real DB data first → if BPJS rejects with
     * "waktu tidak boleh kurang" (time_order), retry with robot inference.
     */
    private function processTaskChain(
        string $kodebooking,
        string $noRawat,
        array  $patient,
        array  $state,
        array  $jadwal,
        string $label,
        bool   $isJkn
    ): void {
        $jamMulai = $jadwal['jam_mulai'] ?? '08:00:00';

        // Bidirectional auto-healing: sync sent task state from BPJS to local DB
        $this->syncTaskStateFromBpjs($kodebooking, $noRawat, $state, $label);

        // Determine jenisresep once per patient (per BPJS spec)
        $noResep    = $this->db->fetchNoResep($noRawat);
        $isRacikan  = !empty($noResep) ? $this->db->isRacikan($noResep) : false;
        $jenisresep = empty($noResep) ? 'Tidak ada' : ($isRacikan ? 'Racikan' : 'Non racikan');

        // ── Task 3: mulai tunggu poli ─────────────────────────────────────
        if ($state['3'] === '') {
            $datajam = $isJkn
                ? $this->db->resolveTask3WaktuJkn($noRawat, $patient['tgl_registrasi'], $jamMulai)
                : $this->db->resolveTask3Waktu($noRawat, $patient['tgl_registrasi'], $jamMulai);

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
                    } else {
                        $state['3'] = 'Belum';
                    }
                }
            } else {
                $this->log->debug("[{$label}] {$noRawat} TaskID 3: patient has not checked in (waiting for digital/physical check-in or SEP) — pausing task chain");
            }
        }

        // ── Task 4: mulai pelayanan poli ──────────────────────────────────
        if ($state['3'] === 'Sudah' && $state['4'] === '') {
            $realTime = $this->db->resolveTask4Waktu($noRawat);
            $prevWaktu = $state['waktu_3'] ?? '';
            $datajam = $this->tryRealThenRobot($kodebooking, $noRawat, '4', $realTime, $prevWaktu, false, $label, $jenisresep);
            if ($datajam !== null) {
                $state['4'] = 'Sudah';
                $state['waktu_4'] = $datajam;
            } elseif ($datajam === null && !empty($realTime)) {
                $state['4'] = 'Belum';
            }
        }

        // ── Task 5: selesai pelayanan poli ────────────────────────────────
        if ($state['4'] === 'Sudah' && $state['5'] === '') {
            $realTime = $this->db->resolveTask5Waktu($noRawat);
            $prevWaktu = $state['waktu_4'] ?? '';
            $datajam = $this->tryRealThenRobot($kodebooking, $noRawat, '5', $realTime, $prevWaktu, false, $label, $jenisresep);
            if ($datajam !== null) {
                $state['5'] = 'Sudah';
                $state['waktu_5'] = $datajam;
            } elseif ($datajam === null && !empty($realTime)) {
                $state['5'] = 'Belum';
            }
        }

        // ── Farmasi + Task 6 ──────────────────────────────────────────────
        if ($state['5'] === 'Sudah' && $state['6'] === '') {
            // Skip tasks 6/7 if patient has no prescription and config says to skip
            if (empty($noResep) && $this->config->skipFarmasiNoResep) {
                $this->log->info("[{$label}] {$noRawat} TaskID 6,7: skip — no resep (MOBILEJKN_SKIP_FARMASI_NO_RESEP=true)");
            } else {
                $this->sendFarmasi($kodebooking, $noRawat, $noResep);

                $realTime  = $this->db->resolveTask6Waktu($noRawat);
                $prevWaktu = $state['waktu_5'] ?? '';
                $datajam   = $this->tryRealThenRobot($kodebooking, $noRawat, '6', $realTime, $prevWaktu, $isRacikan, $label, $jenisresep);
                if ($datajam !== null) {
                    $state['6'] = 'Sudah';
                    $state['waktu_6'] = $datajam;
                } elseif ($datajam === null && !empty($realTime)) {
                    $state['6'] = 'Belum';
                }
            }
        }

        // ── Task 7: selesai farmasi ───────────────────────────────────────
        if ($state['6'] === 'Sudah' && $state['7'] === '') {
            $realTime  = $this->db->resolveTask7Waktu($noRawat);
            $prevWaktu = $state['waktu_6'] ?? '';
            $datajam   = $this->tryRealThenRobot($kodebooking, $noRawat, '7', $realTime, $prevWaktu, $isRacikan, $label, $jenisresep);
            if ($datajam !== null) {
                $state['7'] = 'Sudah';
                $state['waktu_7'] = $datajam;
            } elseif ($datajam === null && !empty($realTime)) {
                $state['7'] = 'Belum';
            }
        }

        // ── Task 99: cancellation ─────────────────────────────────────────
        if ($state['99'] === '') {
            if ($this->db->isCancelled($noRawat)) {
                $nowStr = date('Y-m-d H:i:s');
                $this->sendTaskId($kodebooking, $noRawat, '99', $nowStr, $label, $jenisresep);
            }
        }
    }

    /**
     * Try sending with real DB time first. If BPJS rejects with time_order,
     * automatically retry with robot inference.
     *
     * @return string|null  The accepted waktu, or null if both attempts failed/skipped
     */
    private function tryRealThenRobot(
        string $kodebooking,
        string $noRawat,
        string $taskId,
        string $realTime,
        string $prevWaktu,
        bool   $isRacikan,
        string $label,
        string $jenisresep = 'Tidak ada'
    ): ?string {
        // Attempt 1: use real data if available
        if (!empty($realTime)) {
            $r = $this->sendTaskId($kodebooking, $noRawat, $taskId, $realTime, $label, $jenisresep);
            if ($r['ok']) return $realTime;
            if ($r['reason'] === 'already_in_db') return null;

            // If BPJS rejected because time <= previous, fall through to robot
            if ($r['reason'] !== 'time_order') {
                return null; // Other failure — don't retry
            }
            $this->log->info("[{$label}] {$noRawat} TaskID {$taskId}: time_order → retrying with robot inference");
        }

        // Attempt 2: robot inference from previous task
        $robotTime = RobotInference::infer($taskId, $prevWaktu, $isRacikan);
        if (empty($robotTime)) {
            $this->log->debug("[{$label}] {$noRawat} TaskID {$taskId}: robot gates not satisfied — skip");
            return null;
        }

        $r = $this->sendTaskId($kodebooking, $noRawat, $taskId, $robotTime, $label, $jenisresep);
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

        // Detect BPJS time-ordering rejection: "waktu tidak boleh kurang atau sama"
        $isTimeOrder = (str_contains($msg, 'tidak boleh kurang') || str_contains($msg, 'waktu sebelumnya'));
        $reason = $isTimeOrder ? 'time_order' : 'api_error';

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

        if ($result['success'] || ($result['code'] ?? '') === '208') {
            $this->log->info("[FARMASI] {$noRawat}: ✓ accepted");
        } else {
            $this->log->warning("[FARMASI] {$noRawat}: ✗ {$result['code']} — {$result['message']}");
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
        if (!$res['success'] || empty($res['data'])) {
            return;
        }

        $tasks = $res['data'];
        $updatedLocal = false;

        foreach ($tasks as $t) {
            $tId = (string) ($t['taskid'] ?? '');
            if (empty($tId)) {
                continue;
            }

            // If local DB doesn't think this task has been sent, but BPJS has it:
            if (($state[$tId] ?? '') !== 'Sudah') {
                $waktuStr = $t['wakturs'] ?? '';
                if (empty($waktuStr) && !empty($t['waktu'])) {
                    // Fallback to epoch milliseconds
                    $waktuStr = date('Y-m-d H:i:s', (int) round($t['waktu'] / 1000));
                }

                if (!empty($waktuStr)) {
                    if ($this->db->insertTaskId($noRawat, $tId, $waktuStr)) {
                        $this->log->info("[{$label}] {$noRawat} TaskID {$tId}: auto-synced from BPJS (waktu: {$waktuStr})");
                        $updatedLocal = true;
                    }
                }
            }
        }

        // If we updated local records, reload the state array so the task chain has the latest data
        if ($updatedLocal) {
            $state = $this->db->loadTaskState($noRawat);
        }
    }
}
