<?php

/**
 * QueueProcessor - Core business logic for Mobile JKN queue sync.
 *
 * Orchestrates 5 processing blocks:
 *  0. Global sync: fetch getlisttask from BPJS, reconcile DB, populate cache
 *  1. Add JKN queue entries (statuskirim=Belum → /antrean/add)
 *  2. Process cancellations (/antrean/batal + taskid=99)
 *  3. Update task IDs for JKN patients (3→4→5→farmasi→6→7, strictly sequential)
 *  4. Add Non-JKN patients + their task IDs
 *
 * Architecture:
 *  - Task chain processing is STRICTLY SEQUENTIAL per patient (one API call → confirm → next)
 *  - BPJS task state cached in-memory from Block 0 getlisttask and written to daily JSON file
 *  - Predecessor confirmation uses BPJS cache (not just local DB) for correctness
 *  - Farmasi queue sent after task 5 confirmed (patient finished in poli)
 *
 * @author  malifnasrulloh (converted from Java by Antigravity)
 */

declare(strict_types=1);

class QueueProcessor
{
    private MobileJknDatabase  $db;
    private BpjsAntreanClient  $api;
    private MobileJknConfig    $config;
    private Logger             $log;

    // ─── Counters ──────────────────────────────────────────────────────────
    private int $successCount = 0;
    private int $failCount    = 0;
    private int $skipCount    = 0;

    // ─── Cache ─────────────────────────────────────────────────────────────
    private array $dynamicGaps = [];

    /**
     * BPJS task cache: actual state from /antrean/getlisttask.
     * Keyed by kodebooking → ['task_ids' => ['3','4',...], 'tasks' => [...], 'fetched_at' => '...']
     */
    private array $bpjsTaskCache = [];
    private string $cacheDir = '';
    private string $cacheDate = '';
    private array $farmasiSent = [];

    public function __construct(
        MobileJknDatabase $db,
        BpjsAntreanClient $api,
        MobileJknConfig   $config,
        Logger            $log
    ) {
        $this->db     = $db;
        $this->api    = $api;
        $this->config = $config;
        $this->log    = $log;
    }

    /**
     * Run all processing blocks.
     *
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

        // Initialize daily cache (like iCare's PatientCache pattern)
        $this->cacheDate = $today;
        $this->cacheDir  = (defined('BASE_DIR') ? BASE_DIR : __DIR__) . '/cache/mobilejkn';
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
        $this->loadTaskCache();

        // Block 0: Global DB Sync with BPJS (populates bpjsTaskCache)
        $this->processGlobalSync($lookback, $today);

        // Block 1: New JKN bookings
        $this->processNewJknBookings($lookback, $today);

        // Block 2: Cancellations
        $this->processCancellations($lookback, $today);

        // Block 3: Task IDs for JKN patients (sequential per patient)
        $this->processJknTaskIds($today, $today);

        // Block 4: Non-JKN patients (optional)
        if ($this->config->includeNonJkn) {
            $this->processNonJknPatients($today, $today);
        } else {
            $this->log->info("[NON-JKN] Skipped (MOBILEJKN_INCLUDE_NON_JKN=false)");
        }

        // Persist cache to disk for debugging
        $this->saveTaskCache();

        return [
            'success' => $this->successCount,
            'fail'    => $this->failCount,
            'skip'    => $this->skipCount,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Block 0: Global Sync
    // ═══════════════════════════════════════════════════════════════════════

    private function processGlobalSync(string $dateFrom, string $dateTo): void
    {
        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[BLOCK 0] Global Task Synchronization...");

        try {
            $kodeBpjs = $this->db->fetchBpjsPayerCode();
            $patients = $this->db->fetchAllPatientsForSync($dateFrom, $dateTo, $kodeBpjs);
        } catch (\PDOException $e) {
            $this->log->error("[BLOCK 0] DB query failed: " . $e->getMessage());
            $this->failCount++;
            return;
        }

        $total = count($patients);
        if ($total === 0) {
            $this->log->info("[BLOCK 0] No patients found for sync.");
            return;
        }

        $this->log->info("[BLOCK 0] Found {$total} patient(s) to verify against BPJS.");
        
        // This will modify the DB directly. We don't need the $patients array back for anything else in this block.
        $this->syncTasksWithBpjs($patients, 'BLOCK 0');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Block 1: Add new JKN queue entries
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

        $total = count($bookings);
        if ($total === 0) {
            $this->log->info("[BLOCK 1] No unsent JKN bookings found.");
            return;
        }
        $this->log->info("[BLOCK 1] Found {$total} unsent booking(s).");

        // Build batch requests
        $requests = [];
        foreach ($bookings as $b) {
            $requests[] = [
                'id'       => 'add_jkn_' . $b['nobooking'],
                'endpoint' => '/antrean/add',
                'payload'  => $this->buildJknAntreanPayload($b),
                '_booking' => $b,
            ];
        }

        // Execute in parallel
        $results = $this->api->executeBatch($requests);

        // Process results
        foreach ($requests as $req) {
            $id     = $req['id'];
            $result = $results[$id] ?? ['success' => false, 'code' => '?', 'message' => 'Missing result'];
            $nb     = $req['_booking']['nobooking'];

            if ($result['success']) {
                try {
                    $this->db->markBookingAsSent($nb);
                    $this->log->info("[BLOCK 1] ✓ Sent & marked: {$nb}");
                    $this->successCount++;
                } catch (\PDOException $e) {
                    $this->log->error("[BLOCK 1] DB update failed for {$nb}: " . $e->getMessage());
                    $this->failCount++;
                }
            } else {
                $this->log->warning("[BLOCK 1] ✗ Failed {$nb}: {$result['code']} — {$result['message']}");
                $this->failCount++;
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Block 2: Process cancellations
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

        $total = count($cancellations);
        if ($total === 0) {
            $this->log->info("[BLOCK 2] No pending cancellations.");
            return;
        }
        $this->log->info("[BLOCK 2] Found {$total} cancellation(s).");

        // Cancellations are processed sequentially (they have a two-step flow)
        foreach ($cancellations as $c) {
            $nb = $c['nobooking'];
            $noRawat = $c['no_rawat_batal'];

            // Step 1: POST /antrean/batal
            $result = $this->api->batalAntrean($nb, $c['keterangan'] ?? 'Dibatalkan');

            if (!$result['success']) {
                $this->log->warning("[BLOCK 2] ✗ Cancel failed {$nb}: {$result['message']}");
                $this->failCount++;
                continue;
            }

            // Mark cancellation as sent
            try {
                $this->db->markCancellationAsSent($c['nomorreferensi']);
            } catch (\PDOException $e) {
                $this->log->error("[BLOCK 2] DB update failed for {$nb}: " . $e->getMessage());
            }

            // Step 2: Send taskid=99 for the cancellation
            $waktuStr = $c['tanggalbatal'] ?? '';
            if (empty($waktuStr)) continue;

            $inserted = $this->db->insertTaskId($noRawat, '99', $waktuStr);
            if (!$inserted) {
                $this->log->debug("[BLOCK 2] TaskID 99 already sent for {$noRawat}, skipping.");
                $this->skipCount++;
                continue;
            }

            $waktuMs = $this->toEpochMs($waktuStr);
            if ($waktuMs === null) continue;

            $taskResult = $this->api->updateWaktu($nb, '99', $waktuMs);
            if ($taskResult['success']) {
                $this->log->info("[BLOCK 2] ✓ TaskID 99 sent for {$noRawat}");
                $this->successCount++;
            } else {
                $this->db->deleteTaskId($noRawat, '99');
                $this->log->warning("[BLOCK 2] ✗ TaskID 99 failed for {$noRawat}, rolled back for retry.");
                $this->failCount++;
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Block 3: Update task IDs for JKN patients
    // ═══════════════════════════════════════════════════════════════════════

    private function processJknTaskIds(string $dateFrom, string $dateTo): void
    {
        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[BLOCK 3] Updating task IDs for JKN patients...");

        $hari = $this->config->todayHari();

        try {
            $patients = $this->db->fetchJknPatientsWithTaskData($dateFrom, $dateTo, $hari);
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

        // Calculate dynamic time gaps for natural time adjustments
        $this->dynamicGaps = $this->calculateDynamicGaps($patients);

        // Process each patient SEQUENTIALLY — task by task, action→response
        foreach ($patients as $idx => $p) {
            $kodebooking = $p['nobooking'];
            $noRawat     = $p['no_rawat'];

            $this->log->info("[BLOCK 3] ──── Patient " . ($idx + 1) . "/{$total}: {$noRawat} ────");

            $sentTasksData = $this->parseSentTasksData($p['sent_tasks_data'] ?? '');
            $this->processPatientTaskChain($kodebooking, $noRawat, $p, $sentTasksData, 'BLOCK 3');
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Block 4: Non-JKN patients (add + task IDs)
    // ═══════════════════════════════════════════════════════════════════════

    private function processNonJknPatients(string $dateFrom, string $dateTo): void
    {
        $this->log->info("──────────────────────────────────────────────────────────────");
        $this->log->info("[BLOCK 4] Processing Non-JKN patients...");

        $hari = $this->config->todayHari();
        $this->log->info("[BLOCK 4] Today: {$hari}");

        // Fetch BPJS payer code to exclude BPJS patients
        try {
            $kodeBpjs = $this->db->fetchBpjsPayerCode();
        } catch (\PDOException $e) {
            $this->log->error("[BLOCK 4] Failed to fetch BPJS payer code: " . $e->getMessage());
            $this->failCount++;
            return;
        }

        if (empty($kodeBpjs)) {
            $this->log->warning("[BLOCK 4] BPJS payer code not found in password_asuransi. Skipping Non-JKN.");
            return;
        }
        $this->log->debug("[BLOCK 4] BPJS payer code: {$kodeBpjs}");

        try {
            $patients = $this->db->fetchNonJknPatientsWithTaskData($dateFrom, $dateTo, $hari, $kodeBpjs);
        } catch (\PDOException $e) {
            $this->log->error("[BLOCK 4] DB query failed: " . $e->getMessage());
            $this->failCount++;
            return;
        }

        $total = count($patients);
        if ($total === 0) {
            $this->log->info("[BLOCK 4] No Non-JKN patients found.");
            return;
        }
        $this->log->info("[BLOCK 4] Found {$total} Non-JKN patient(s).");

        // Calculate dynamic gaps once for the whole block
        if (empty($this->dynamicGaps)) {
            $this->dynamicGaps = $this->calculateDynamicGaps($patients);
        }

        // Process each patient SEQUENTIALLY
        foreach ($patients as $idx => $p) {
            $noRawat       = $p['no_rawat'];
            $kodebooking   = $noRawat; // Non-JKN uses no_rawat as kodebooking
            $sentTasksData = $this->parseSentTasksData($p['sent_tasks_data'] ?? '');
            $hasSentTasks  = !empty($sentTasksData['ids']);
            $hasBpjsCache  = isset($this->bpjsTaskCache[$kodebooking]);

            $this->log->info("[BLOCK 4] ──── Patient " . ($idx + 1) . "/{$total}: {$noRawat} ────");

            // Step 1: /antrean/add — only if this patient has no tasks on BPJS yet
            if (!$hasSentTasks && !$hasBpjsCache) {
                $this->log->info("[BLOCK 4] {$noRawat}: SEND /antrean/add");
                $addResult = $this->api->addAntrean($this->buildNonJknAntreanPayload($p));
                $addOk = $addResult['success'] || ($addResult['code'] ?? '') === '208';
                if (!$addOk) {
                    $this->log->warning("[BLOCK 4] {$noRawat}: ✗ /antrean/add failed: {$addResult['message']} — skipping.");
                    $this->failCount++;
                    continue;
                }
                $this->log->info("[BLOCK 4] {$noRawat}: ✓ /antrean/add accepted");
                $this->successCount++;
            } elseif ($hasSentTasks || $hasBpjsCache) {
                $this->log->debug("[BLOCK 4] {$noRawat}: already on BPJS — skipping /antrean/add.");
            }

            // Step 2: Sequential task chain + farmasi (same as Block 3)
            $this->processPatientTaskChain($kodebooking, $noRawat, $p, $sentTasksData, 'BLOCK 4');
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Core: Sequential per-patient task processing (DRY — used by Block 3 & 4)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Process the task chain for a single patient, STRICTLY SEQUENTIALLY.
     *
     * Each task is sent one at a time: send → wait for BPJS response → log → proceed/stop.
     * Predecessor confirmation uses the BPJS cache (Block 0 getlisttask) as ground truth.
     *
     * Flow: Task 3 → 4 → 5 → [farmasi after 5] → 6 → 7 → [99 if cancelled]
     */
    private function processPatientTaskChain(
        string $kodebooking,
        string $noRawat,
        array  $patientData,
        array  $sentTasksData,
        string $blockLabel
    ): void {
        $sentTasks     = $sentTasksData['ids'];
        $resolvedWaktu = $sentTasksData['waktu'];

        // BPJS-confirmed tasks from Block 0 cache (ground truth)
        $bpjsConfirmed = $this->bpjsTaskCache[$kodebooking]['task_ids'] ?? [];

        $taskChain = [
            '3' => ['field' => 'task3_waktu', 'requires' => null, 'canInfer' => false],
            '4' => ['field' => 'task4_waktu', 'requires' => '3',  'canInfer' => true],
            '5' => ['field' => 'task5_waktu', 'requires' => '4',  'canInfer' => true],
            '6' => ['field' => 'task6_waktu', 'requires' => '5',  'canInfer' => true],
            '7' => ['field' => 'task7_waktu', 'requires' => '6',  'canInfer' => true],
        ];

        // Tasks confirmed THIS cycle (sent + accepted by BPJS in this run)
        $confirmedThisCycle = [];

        foreach ($taskChain as $taskId => $config) {
            $taskId       = (string) $taskId;
            $field        = $config['field'];
            $prerequisite = $config['requires'];
            $waktuStr     = $patientData[$field] ?? '';

            // ── Already on BPJS? Skip (confirmed by getlisttask) ────────
            if (in_array($taskId, $bpjsConfirmed, true)) {
                if (!empty($waktuStr) && !str_starts_with($waktuStr, '0000')) {
                    $resolvedWaktu[$taskId] = $waktuStr;
                }
                $this->log->debug("[{$blockLabel}] {$noRawat} TaskID {$taskId}: already on BPJS — skip");
                if ($taskId === '5') {
                    $this->processFarmasiQueue($kodebooking, $noRawat, $patientData);
                }
                continue;
            }

            // ── Already in local DB but not on BPJS? Skip ──────────────
            if (in_array($taskId, $sentTasks, true)) {
                if (!empty($waktuStr) && !str_starts_with($waktuStr, '0000')) {
                    $resolvedWaktu[$taskId] = $waktuStr;
                }
                $this->log->debug("[{$blockLabel}] {$noRawat} TaskID {$taskId}: in DB (awaiting confirm) — skip");
                continue;
            }

            // ── No trigger data? Try inference or pause ─────────────────
            if (empty($waktuStr) || str_starts_with($waktuStr, '0000')) {
                if ($config['canInfer']) {
                    $inferred = $this->inferTaskWaktu($taskId, $noRawat, $patientData, $resolvedWaktu);
                    if (!empty($inferred)) {
                        $waktuStr = $inferred;
                    } else {
                        $this->log->debug("[{$blockLabel}] {$noRawat} TaskID {$taskId}: no data + inference not ready — chain paused.");
                        break;
                    }
                } else {
                    $this->log->debug("[{$blockLabel}] {$noRawat} TaskID {$taskId}: no trigger data — chain paused.");
                    break;
                }
            }

            // ── Monotonic timestamp enforcement ─────────────────────────
            if ($prerequisite !== null) {
                $prevWaktuStr = $resolvedWaktu[$prerequisite] ?? null;
                if ($prevWaktuStr) {
                    $prevMs = $this->toEpochMs($prevWaktuStr) ?? 0;
                    $currMs = $this->toEpochMs($waktuStr) ?? 0;
                    if ($currMs <= $prevMs) {
                        $kdPoli = $patientData['kd_poli'] ?? 'UNKNOWN';
                        $offsetMs = $this->getNaturalOffsetMs("{$prerequisite}-{$taskId}", $kdPoli, $this->dynamicGaps);
                        $currMs = $prevMs + $offsetMs;
                        $waktuStr = date('Y-m-d H:i:s', (int)($currMs / 1000)) . sprintf('.%03d', $currMs % 1000);
                        $this->log->info("[{$blockLabel}] {$noRawat} TaskID {$taskId}: time inverted → corrected to {$waktuStr}");
                    }
                }
            }
            $resolvedWaktu[$taskId] = $waktuStr;

            // ── Prerequisite: must be on BPJS or confirmed this cycle ───
            if ($prerequisite !== null
                && !in_array($prerequisite, $bpjsConfirmed, true)
                && !in_array($prerequisite, $confirmedThisCycle, true)
            ) {
                $this->log->debug("[{$blockLabel}] {$noRawat} TaskID {$taskId}: prerequisite {$prerequisite} not confirmed — chain paused.");
                break;
            }

            // ── Working hours validation ────────────────────────────────
            if (!$this->validateWaktuInWorkingHours($waktuStr, $taskId, $noRawat, $patientData)) {
                break;
            }

            // ── Insert into DB (idempotency) ────────────────────────────
            if (!$this->db->insertTaskId($noRawat, $taskId, $waktuStr)) {
                $this->log->debug("[{$blockLabel}] {$noRawat} TaskID {$taskId}: already in DB — skip");
                $this->skipCount++;
                continue;
            }

            $waktuMs = $this->toEpochMs($waktuStr);
            if ($waktuMs === null) {
                $this->db->deleteTaskId($noRawat, $taskId);
                break;
            }

            // ── SEND: single sequential API call → immediate response ───
            $this->log->info("[{$blockLabel}] {$noRawat} TaskID {$taskId}: SEND waktu={$waktuMs} ({$waktuStr})");
            $result = $this->api->updateWaktu($kodebooking, $taskId, $waktuMs);

            if ($result['success']) {
                $this->log->info("[{$blockLabel}] {$noRawat} TaskID {$taskId}: ✓ accepted");
                $this->successCount++;
                $confirmedThisCycle[] = $taskId;
                $this->updateCacheTask($kodebooking, $taskId, $waktuStr);

                // After task 5 confirmed → send farmasi (patient finished in poli)
                if ($taskId === '5') {
                    $this->processFarmasiQueue($kodebooking, $noRawat, $patientData);
                }
            } else {
                $this->db->deleteTaskId($noRawat, $taskId);
                $this->log->warning("[{$blockLabel}] {$noRawat} TaskID {$taskId}: ✗ {$result['code']} — {$result['message']} (rolled back)");
                $this->failCount++;
                break; // Stop chain — cannot proceed
            }
        }

        // ── Task 99: cancellation (independent) ─────────────────────────
        $isCancelled = $patientData['is_cancelled'] ?? null;
        if ($isCancelled === 'Batal'
            && !in_array('99', $sentTasks, true)
            && !in_array('99', $bpjsConfirmed, true)
        ) {
            $nowStr = date('Y-m-d H:i:s');
            if ($this->db->insertTaskId($noRawat, '99', $nowStr)) {
                $waktuMs = $this->toEpochMs($nowStr);
                if ($waktuMs !== null) {
                    $this->log->info("[{$blockLabel}] {$noRawat} TaskID 99: SEND waktu={$waktuMs}");
                    $result = $this->api->updateWaktu($kodebooking, '99', $waktuMs);
                    if ($result['success']) {
                        $this->log->info("[{$blockLabel}] {$noRawat} TaskID 99: ✓ accepted");
                        $this->successCount++;
                    } else {
                        $this->db->deleteTaskId($noRawat, '99');
                        $this->log->warning("[{$blockLabel}] {$noRawat} TaskID 99: ✗ {$result['message']} (rolled back)");
                        $this->failCount++;
                    }
                }
            }
        }
    }

    /**
     * Infer a natural waktu for any robot-eligible task (4, 5, 6, 7) when DB data is missing.
     *
     * Mirrors the original Java robot logic from frmUtamaRobot.java, extended with
     * professional safety gates to prevent submitting inferred times while the
     * polyclinic is still in session.
     *
     * Gap ranges (matching Java robot exactly):
     *   Task 4 from task 3: rand(35–58 min) + rand(1–60 sec)
     *   Task 5 from task 4: rand(3–10 min)  + rand(1–60 sec)
     *   Task 6 from task 5: rand(6–15 min)  + rand(1–60 sec)  (Java line 711)
     *   Task 7 from task 6: rand(8–15 min) non-racikan / rand(11–30 min) racikan + rand(1–60 sec)
     *
     * These are blended with DB-derived historical min/max when richer data exists.
     *
     * Safety gates (applied in order):
     *   1. Clinic-closed gate: only infer after jam_selesai + rand(15–30 min) buffer.
     *   2. Past-time gate (Java original): inferred time must be strictly < now.
     *   3. Anchor-bound clamp: inferred time must be < next known anchor (at least 30 s).
     *
     * @return string  'Y-m-d H:i:s.mmm' on success, '' if guards not yet satisfied
     */
    private function inferTaskWaktu(
        string $taskId,
        string $noRawat,
        array  $patientData,
        array  $resolvedWaktu
    ): string {
        // ── Gate 1: polyclinic-closed guard ────────────────────────────────
        // Only infer after the clinic session has ended + 15–30 min buffer.
        // jam_selesai comes from jadwal.jam_selesai (e.g. "12:00:00").
        $jamSelesai = $patientData['jam_selesai'] ?? '';
        if (!empty($jamSelesai)) {
            $tglPeriksa    = $patientData['tgl_registrasi'] ?? date('Y-m-d');
            $tglPeriksa    = substr($tglPeriksa, 0, 10);
            $closingTs     = strtotime("{$tglPeriksa} {$jamSelesai}");
            $bufferSeconds = mt_rand(15 * 60, 30 * 60); // 15–30 min randomised buffer
            $earliestSubmit = $closingTs + $bufferSeconds;

            if (time() < $earliestSubmit) {
                $readableEarliest = date('Y-m-d H:i:s', $earliestSubmit);
                $this->log->debug("[INFERRED] {$noRawat} TaskID {$taskId}: "
                    . "clinic-closed gate not satisfied (earliest: {$readableEarliest}). Skipping.");
                return '';
            }
        }

        // ── Anchor: find the next known task after $taskId as upper bound ──
        // For tasks 4/5/6 we anchor against the nearest later task that has real data.
        // For task 7 there is no later task — it only needs to be in the past.
        $fullChain  = ['3', '4', '5', '6', '7'];
        $prevTaskMap = ['4' => '3', '5' => '4', '6' => '5', '7' => '6'];
        $prevTask   = $prevTaskMap[$taskId] ?? null;
        $prevWaktu  = $prevTask ? ($resolvedWaktu[$prevTask] ?? null) : null;
        if (empty($prevWaktu)) {
            return ''; // No lower bound available
        }

        // Seek the nearest later anchor with real data
        $anchorWaktu = null;
        $anchorLabel = '';
        $myIdx = array_search($taskId, $fullChain, true);
        for ($i = $myIdx + 1; $i < count($fullChain); $i++) {
            $candidate = $fullChain[$i];
            $candidateWaktu = $patientData["task{$candidate}_waktu"] ?? '';
            if (!empty($candidateWaktu) && !str_starts_with($candidateWaktu, '0000')) {
                $anchorWaktu = $candidateWaktu;
                $anchorLabel = "task{$candidate}";
                break;
            }
            // Also check resolvedWaktu (inferred tasks from this same cycle)
            if (!empty($resolvedWaktu[$candidate])) {
                $anchorWaktu = $resolvedWaktu[$candidate];
                $anchorLabel = "task{$candidate}(inferred)";
                break;
            }
        }

        $kdPoli  = $patientData['kd_poli'] ?? 'UNKNOWN';
        $prevMs  = $this->toEpochMs($prevWaktu) ?? 0;
        $anchorMs = $anchorWaktu ? $this->toEpochMs($anchorWaktu) : null;
        if ($anchorMs !== null && $anchorMs <= $prevMs) {
            $anchorMs = null; // Anchor predates lower bound — ignore it
        }

        // ── Gap calculation: Java robot ranges as baseline ─────────────────
        // Task 4 ← task 3: rand(35–58 min) (Java frmUtamaRobot.java line 559)
        // Task 5 ← task 4: rand(3–10  min) (Java line 621)
        // Task 6 ← task 5: rand(6–15  min) (Java line 711)
        // Task 7 ← task 6: rand(8–15  min) non-racikan / rand(11–30 min) racikan (Java line 773)
        $noResep = $patientData['no_resep'] ?? '';
        $isRacikan = false;
        if (!empty($noResep)) {
            try {
                $isRacikan = $this->db->fetchResepType($noResep) === 'Racikan';
            } catch (\PDOException $e) {
                $isRacikan = false; // safe fallback
            }
        }
        $defaultRanges = [
            '4' => [35 * 60_000, 58 * 60_000],
            '5' => [ 3 * 60_000, 10 * 60_000],
            '6' => [ 6 * 60_000, 15 * 60_000],
            '7' => $isRacikan ? [11 * 60_000, 30 * 60_000] : [8 * 60_000, 15 * 60_000],
        ];
        [$defaultMinMs, $defaultMaxMs] = $defaultRanges[$taskId] ?? [5 * 60_000, 20 * 60_000];

        // Fetch historical polyclinic gap and use the wider of the two ranges
        // (historical data wins when it represents a richer sample)
        $gapStats    = $this->db->fetchTaskTransitionGapMs($kdPoli, $prevTask, $taskId);
        $histMinMs   = (int)($gapStats['min_gap_ms']);
        $histMaxMs   = (int)($gapStats['max_gap_ms']);
        $finalMinMs  = ($histMaxMs > $defaultMinMs) ? min($histMinMs, $defaultMinMs) : $defaultMinMs;
        $finalMaxMs  = max($histMaxMs, $defaultMaxMs);

        // Safety ceilings
        $finalMinMs  = max(60_000,      $finalMinMs); // ≥ 1 min
        $finalMaxMs  = min(28_800_000,  $finalMaxMs); // ≤ 8 h
        if ($finalMinMs >= $finalMaxMs) {
            $finalMaxMs = $finalMinMs + 600_000;
        }

        // Random gap: minutes level + seconds level (Java parity) + ms jitter
        $gapMs      = mt_rand((int)$finalMinMs, (int)$finalMaxMs);
        $gapMs     += mt_rand(1_000, 60_000);   // +1–60 s (Java random seconds)
        $gapMs     += mt_rand(0, 999);           // sub-second jitter
        $inferredMs = $prevMs + $gapMs;

        // ── Gate 2: past-time gate (Java original logic) ───────────────────
        // The inferred time must be strictly in the past (< now).
        $nowMs = (int)(microtime(true) * 1000);
        if ($inferredMs >= $nowMs) {
            $this->log->debug("[INFERRED] {$noRawat} TaskID {$taskId}: "
                . 'inferred time is in the future — skipping (past-time gate).');
            return '';
        }

        // ── Gate 3: anchor-bound clamp ─────────────────────────────────────
        // Must stay at least 30 s before the next known anchor task.
        // If no anchor exists (e.g. task 7 is last in chain), only Gate 2 applies.
        if ($anchorMs !== null) {
            $maxAllowed = $anchorMs - 30_000;
            if ($inferredMs >= $maxAllowed) {
                $window     = $anchorMs - $prevMs;
                $fraction   = mt_rand(40, 85) / 100; // 40–85% into the window
                $inferredMs = $prevMs + (int)($window * $fraction) + mt_rand(0, 999);

                // Re-check past-time gate after adjustment
                if ($inferredMs >= $nowMs) {
                    $this->log->debug("[INFERRED] {$noRawat} TaskID {$taskId}: "
                        . 'clamped time still in future — skipping.');
                    return '';
                }
            }
        }

        $inferredStr = date('Y-m-d H:i:s', (int)($inferredMs / 1000))
            . sprintf('.%03d', $inferredMs % 1000);

        $anchorDisplay = $anchorLabel ?: 'none (past-time gate only)';
        $this->log->info("[INFERRED] {$noRawat} TaskID {$taskId}: "
            . "anchor={$anchorDisplay}, gap={$gapMs}ms "
            . "(poli={$kdPoli} range=[{$finalMinMs},{$finalMaxMs}]ms hist=[{$histMinMs},{$histMaxMs}]ms) "
            . "→ waktu={$inferredStr}");

        return $inferredStr;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Farmasi queue processing
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Add pharmacy queue entry if a prescription exists.
     * This is NOT a task ID — it's a separate /antrean/farmasi/add call.
     * Deduplicated: skips if already sent this cycle or BPJS returns 208 (already registered).
     */

    private function processFarmasiQueue(string $kodebooking, string $noRawat, array $p): void
    {
        $noResep = $p['no_resep'] ?? '';
        if (empty($noResep)) return;

        // Dedup: skip if already sent for this patient in this cycle
        if (isset($this->farmasiSent[$noRawat])) {
            $this->log->debug("[FARMASI] {$noRawat}: already sent this cycle — skip");
            return;
        }

        $this->log->info("[FARMASI] {$noRawat}: SEND /antrean/farmasi/add (resep: {$noResep})");

        try {
            $jenisResep = $this->db->fetchResepType($noResep);
        } catch (\PDOException $e) {
            $this->log->error("[FARMASI] DB error: " . $e->getMessage());
            return;
        }

        $nomorAntrean = (int) substr($noResep, -4);

        $result = $this->api->addFarmasiAntrean([
            'kodebooking'  => $kodebooking,
            'jenisresep'   => $jenisResep,
            'nomorantrean'  => $nomorAntrean,
            'keterangan'   => 'Resep dibuat secara elektronik di poli',
        ]);

        if ($result['success'] || ($result['code'] ?? '') === '208') {
            $this->farmasiSent[$noRawat] = true;
            $this->log->info("[FARMASI] {$noRawat}: ✓ " . ($result['code'] === '208' ? 'already registered' : 'accepted'));
        } else {
            $this->log->warning("[FARMASI] {$noRawat}: ✗ {$result['code']} — {$result['message']}");
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Payload builders
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Build /antrean/add payload for a JKN patient.
     */
    private function buildJknAntreanPayload(array $b): array
    {
        return [
            'kodebooking'     => $b['nobooking'],
            'jenispasien'     => 'JKN',
            'nomorkartu'      => $b['nomorkartu'],
            'nik'             => $b['nik'],
            'nohp'            => $b['nohp'],
            'kodepoli'        => $b['kodepoli'],
            'namapoli'        => $b['nm_poli'],
            'pasienbaru'      => $b['pasienbaru'],
            'norm'            => $b['no_rkm_medis'],
            'tanggalperiksa'  => $b['tanggalperiksa'],
            'kodedokter'      => $b['kodedokter'],
            'namadokter'      => $b['nm_dokter'],
            'jampraktek'      => $b['jampraktek'],
            'jeniskunjungan'  => substr($b['jeniskunjungan'] ?? '3', 0, 1),
            'nomorreferensi'  => $b['nomorreferensi'],
            'nomorantrean'    => $b['nomorantrean'],
            'angkaantrean'    => (int) $b['angkaantrean'],
            'estimasidilayani' => $b['estimasidilayani'],
            'sisakuotajkn'    => $b['sisakuotajkn'],
            'kuotajkn'        => $b['kuotajkn'],
            'sisakuotanonjkn' => $b['sisakuotanonjkn'],
            'kuotanonjkn'     => $b['kuotanonjkn'],
            'keterangan'      => 'Peserta harap 30 menit lebih awal guna pencatatan administrasi.',
        ];
    }

    /**
     * Build /antrean/add payload for a Non-JKN patient.
     */
    private function buildNonJknAntreanPayload(array $p): array
    {
        $noReg    = (int) $p['no_reg'];
        $jamMulai = substr($p['jam_mulai'], 0, 5);
        $jamSelesai = substr($p['jam_selesai'], 0, 5);
        $kuota    = (int) $p['kuota'];

        // Calculate estimated service time: reg_date + jam_mulai + (no_reg * 5 min)
        $baseTime     = strtotime($p['tgl_registrasi'] . ' ' . $p['jam_mulai']);
        $estimasi     = $baseTime + ($noReg * 5 * 60);
        $estimasiMs   = $estimasi * 1000;

        // Map stts_daftar → pasienbaru
        $pasienbaru = match ($p['stts_daftar'] ?? '-') {
            'Baru' => '1',
            default => '0',
        };

        return [
            'kodebooking'      => $p['no_rawat'],
            'jenispasien'      => 'NON JKN',
            'nomorkartu'       => '-',
            'nik'              => '-',
            'nohp'             => '-',
            'kodepoli'         => $p['kd_poli_bpjs'],
            'namapoli'         => $p['nm_poli'],
            'pasienbaru'       => $pasienbaru,
            'norm'             => $p['no_rkm_medis'],
            'tanggalperiksa'   => $p['tgl_registrasi'],
            'kodedokter'       => $p['kd_dokter_bpjs'],
            'namadokter'       => $p['nm_dokter'],
            'jampraktek'       => "{$jamMulai}-{$jamSelesai}",
            'jeniskunjungan'   => 3,
            'nomorreferensi'   => '-',
            'nomorantrean'     => (string) $noReg,
            'angkaantrean'     => $noReg,
            'estimasidilayani' => $estimasiMs,
            'sisakuotajkn'     => $kuota - $noReg,
            'kuotajkn'         => $kuota,
            'sisakuotanonjkn'  => $kuota - $noReg,
            'kuotanonjkn'      => $kuota,
            'keterangan'       => 'Peserta harap 30 menit lebih awal guna pencatatan administrasi.',
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Sync Task Status with BPJS API
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Fetch actual task list from BPJS API, populate bpjsTaskCache, and synchronize local DB.
     *
     * - Populates bpjsTaskCache with the raw getlisttask response (source of truth)
     * - Only tasks confirmed on BPJS API are trusted (DB-only tasks deleted for resend)
     * - Tasks on BPJS but not in DB are inserted (catch-up)
     * - Out-of-sequence gaps are logged and filled by processPatientTaskChain in Block 3/4
     */
    private function syncTasksWithBpjs(array &$patients, string $blockLabel): void
    {
        $this->log->info("[{$blockLabel}] Synchronizing local task status with BPJS API...");

        $requests = [];
        foreach ($patients as $idx => $p) {
            $kodebooking = $p['nobooking'] ?? $p['no_rawat'];
            $requests[] = [
                'id'           => "sync_{$idx}",
                'endpoint'     => '/antrean/getlisttask',
                'payload'      => ['kodebooking' => $kodebooking],
                '_idx'         => $idx,
                '_kodebooking' => $kodebooking,
            ];
        }

        if (empty($requests)) return;

        $results = $this->api->executeBatch($requests);

        foreach ($requests as $req) {
            $idx         = $req['_idx'];
            $p           = $patients[$idx];
            $noRawat     = $p['no_rawat'];
            $kodebooking = $req['_kodebooking'];
            $result      = $results[$req['id']] ?? null;

            // ── Connection / API failure: skip this patient safely ──────────
            if (!$result || !$result['success']) {
                $this->log->warning("[{$blockLabel}] Sync failed for {$kodebooking}: "
                    . ($result['message'] ?? 'Unknown error')
                    . " — skipping sync, local state preserved for retry.");
                continue;
            }

            // ── Extract BPJS task list ──────────────────────────────────────
            $data = $result['data'] ?? [];
            $list = [];
            if (is_array($data)) {
                $list = isset($data['list']) && is_array($data['list']) ? $data['list'] : $data;
            }

            $bpjsTaskIds = [];
            foreach ($list as $item) {
                if (is_array($item) && isset($item['taskid'])) {
                    $bpjsTaskIds[] = (string) $item['taskid'];
                }
            }

            // ── Populate BPJS task cache (source of truth) ─────────────────
            $this->bpjsTaskCache[$kodebooking] = [
                'task_ids'   => array_values(array_unique($bpjsTaskIds)),
                'tasks'      => $list,
                'fetched_at' => date('Y-m-d H:i:s'),
            ];

            // ── Strict sequence validation ──────────────────────────────────
            // Deduplicate: BPJS sometimes returns duplicate task IDs (e.g. task 3 twice)
            $chainTasks = array_values(array_unique(array_filter(
                $bpjsTaskIds,
                fn($t) => in_array($t, ['3', '4', '5', '6', '7'], true)
            )));
            sort($chainTasks);

            if (!empty($chainTasks) && !$this->isValidSequence($chainTasks)) {
                // Out-of-sequence detected. We do NOT attempt /antrean/batal here —
                // BPJS rejects cancellation once tasks are registered.
                // The gap tasks will be filled sequentially by processPatientTaskChain()
                // in Block 3/4 using robot-inferred timestamps.
                // Log the gap for monitoring and continue to reconcile normally.
                $this->log->warning("[{$blockLabel}] OUT-OF-SEQUENCE detected for {$noRawat}: "
                    . '[' . implode(',', $chainTasks) . ']. '
                    . 'Gap tasks will be filled sequentially by Block 3/4.');
            }

            // ── Reconcile DB ↔ BPJS ───────────────────────────────────────
            $dbTasksData = $this->parseSentTasksData($p['sent_tasks_data'] ?? '');
            $dbTaskIds   = $dbTasksData['ids'];
            $toDelete    = array_diff($dbTaskIds, $bpjsTaskIds);
            $toInsert    = array_diff($bpjsTaskIds, $dbTaskIds);

            // Delete tasks present locally but absent from BPJS (not truly accepted)
            foreach ($toDelete as $taskId) {
                if ($taskId === '99') continue; // Never auto-resend cancellations
                $this->log->info("[SYNC] {$noRawat} TaskID {$taskId}: in DB but absent from BPJS. Removing for resend.");
                $this->db->deleteTaskId($noRawat, $taskId);
                $key = array_search($taskId, $dbTaskIds, true);
                if ($key !== false) {
                    unset($dbTaskIds[$key]);
                    unset($dbTasksData['waktu'][$taskId]);
                }
            }

            // Insert tasks present in BPJS but missing locally (catch-up)
            foreach ($toInsert as $taskId) {
                $this->log->info("[SYNC] {$noRawat} TaskID {$taskId}: on BPJS but not in DB. Inserting.");
                $waktuRs = '';
                foreach ($list as $item) {
                    if ((string)($item['taskid'] ?? '') === $taskId) {
                        $waktuRs = $item['wakturs'] ?? '';
                        break;
                    }
                }
                if (empty($waktuRs)) {
                    $waktuRs = date('Y-m-d H:i:s');
                } else {
                    $cleaned  = preg_replace('/ WIB$/', '', trim($waktuRs));
                    $dateObj  = DateTime::createFromFormat('d-m-Y H:i:s', $cleaned);
                    $waktuRs  = $dateObj ? $dateObj->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
                }
                $this->db->insertTaskId($noRawat, $taskId, $waktuRs);
                $dbTaskIds[] = $taskId;
                $dbTasksData['waktu'][$taskId] = $waktuRs;
            }

            // Reconstruct sent_tasks_data for downstream blocks in this run
            $newSentTasksData = [];
            foreach ($dbTaskIds as $tId) {
                $w = $dbTasksData['waktu'][$tId] ?? '';
                $newSentTasksData[] = "{$tId}:{$w}";
            }
            $patients[$idx]['sent_tasks_data'] = implode(',', $newSentTasksData);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Sequence validation & recovery
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Validate that a set of chain task IDs [3..7] forms a gapless prefix.
     *
     * Valid:   [], [3], [3,4], [3,4,5], [3,4,5,6], [3,4,5,6,7]
     * Invalid: [3,5], [4,5,6], [3,6,7], [6], etc.
     */
    private function isValidSequence(array $sortedChainTaskIds): bool
    {
        $chain = ['3', '4', '5', '6', '7'];
        foreach ($sortedChainTaskIds as $pos => $taskId) {
            if (!isset($chain[$pos]) || $chain[$pos] !== $taskId) {
                return false;
            }
        }
        return true;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Waktu working-hours guard
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Validate that a task's waktu falls within reasonable polyclinic hours.
     *
     * Tasks 3-5 must be within jam_mulai – jam_selesai.
     * Tasks 6-7 (farmasi) are allowed up to +2 hours after jam_selesai since
     * pharmacy dispensing legitimately occurs after poli closes.
     *
     * @return bool  true = valid (proceed), false = out of range (skip)
     */
    private function validateWaktuInWorkingHours(
        string $waktuStr,
        string $taskId,
        string $noRawat,
        array  $patientData
    ): bool {
        $jamMulai   = $patientData['jam_mulai']   ?? null;
        $jamSelesai = $patientData['jam_selesai'] ?? null;
        $tanggal    = $patientData['tgl_registrasi'] ?? $patientData['tanggalperiksa'] ?? null;

        if (!$jamMulai || !$jamSelesai || !$tanggal) {
            return true;
        }

        $waktuTs = strtotime($waktuStr);
        if ($waktuTs === false || $waktuTs <= 0) {
            return true;
        }

        $date      = substr($tanggal, 0, 10);
        $mulaiTs   = strtotime($date . ' ' . $jamMulai);
        $selesaiTs = strtotime($date . ' ' . $jamSelesai);

        // Tasks 6/7 (pharmacy) get a +2h buffer after clinic closes
        $bufferSeconds = in_array($taskId, ['6', '7'], true) ? 7200 : 0;
        $upperBound    = $selesaiTs + $bufferSeconds;

        if ($waktuTs >= $mulaiTs && $waktuTs <= $upperBound) {
            return true;
        }

        $bufferLabel = $bufferSeconds > 0 ? ' (+2h farmasi buffer)' : '';
        $this->log->warning(
            "[WAKTU] {$noRawat} TaskID {$taskId}: '{$waktuStr}' outside "
            . "hours ({$jamMulai}–{$jamSelesai}{$bufferLabel}) — skipped."
        );
        return false;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Parse sent_tasks_data GROUP_CONCAT string into an array.
     * e.g. "3:2023-10-01 08:00:00,4:2023-10-01 08:15:00"
     * Returns: ['ids' => ['3', '4'], 'waktu' => ['3' => '2023-10-01 08:00:00', ...]]
     */
    private function parseSentTasksData(string $csv): array
    {
        $result = ['ids' => [], 'waktu' => []];
        if (empty($csv)) return $result;
        
        $items = explode(',', $csv);
        foreach ($items as $item) {
            $parts = explode(':', trim($item), 2);
            $taskId = trim($parts[0]);
            if ($taskId !== '') {
                $result['ids'][] = $taskId;
                if (isset($parts[1])) {
                    $result['waktu'][$taskId] = trim($parts[1]);
                }
            }
        }
        return $result;
    }

    /**
     * Calculate median time gaps between consecutive tasks for each polyclinic.
     * This provides highly realistic, data-driven offsets tailored to the hospital's actual speed today.
     */
    private function calculateDynamicGaps(array $patients): array
    {
        $gaps = []; // [kd_poli => [ '3-4' => [gap1, gap2], '4-5' => ... ]]
        
        foreach ($patients as $p) {
            $kdPoli = $p['kd_poli'] ?? 'UNKNOWN';
            if (!isset($gaps[$kdPoli])) {
                $gaps[$kdPoli] = ['3-4' => [], '4-5' => [], '5-6' => [], '6-7' => []];
            }
            
            $t3 = $this->toEpochMs($p['task3_waktu'] ?? '') ?? 0;
            $t4 = $this->toEpochMs($p['task4_waktu'] ?? '') ?? 0;
            $t5 = $this->toEpochMs($p['task5_waktu'] ?? '') ?? 0;
            $t6 = $this->toEpochMs($p['task6_waktu'] ?? '') ?? 0;
            $t7 = $this->toEpochMs($p['task7_waktu'] ?? '') ?? 0;
            
            if ($t3 > 0 && $t4 > $t3) $gaps[$kdPoli]['3-4'][] = $t4 - $t3;
            if ($t4 > 0 && $t5 > $t4) $gaps[$kdPoli]['4-5'][] = $t5 - $t4;
            if ($t5 > 0 && $t6 > $t5) $gaps[$kdPoli]['5-6'][] = $t6 - $t5;
            if ($t6 > 0 && $t7 > $t6) $gaps[$kdPoli]['6-7'][] = $t7 - $t6;
        }
        
        $medians = [];
        foreach ($gaps as $kdPoli => $transitions) {
            foreach ($transitions as $transition => $values) {
                if (count($values) > 0) {
                    sort($values);
                    $mid = intdiv(count($values), 2);
                    $medians[$kdPoli][$transition] = $values[$mid];
                }
            }
        }
        return $medians;
    }

    /**
     * Get a natural, realistic millisecond offset for a specific task transition.
     * Uses the dynamically calculated median for that polyclinic today, with a slight randomized jitter.
     * Falls back to realistic clinical bounds if no dynamic data is available.
     */
    private function getNaturalOffsetMs(string $transition, string $kdPoli, array $dynamicGaps): int
    {
        if (isset($dynamicGaps[$kdPoli][$transition])) {
            $median = $dynamicGaps[$kdPoli][$transition];
            // Add a slight random jitter to the median (+/- 15%) to avoid identically repeating exact medians
            $jitter = mt_rand(-15, 15) / 100;
            $offset = (int)($median + ($median * $jitter));
            // Ensure offset is at least 30 seconds
            return max(30000, $offset);
        }
        
        // Fallback to realistic clinical defaults if no data yet (first patient of the day)
        $min = 30000;
        $max = 300000;
        switch ($transition) {
            case '3-4': // Check-in to Service start
                $min = 10 * 60000; $max = 25 * 60000; break;
            case '4-5': // Service start to Service complete
                $min = 3 * 60000; $max = 15 * 60000; break;
            case '5-6': // Service complete to Prescription created
                $min = 5 * 60000; $max = 20 * 60000; break;
            case '6-7': // Prescription created to Prescription dispensed
                $min = 10 * 60000; $max = 20 * 60000; break;
        }
        
        // Add random milliseconds
        $base = mt_rand($min, $max);
        $ms = mt_rand(1, 999);
        return $base + $ms;
    }

    /**
     * Convert "Y-m-d H:i:s" or "Y-m-d H:i:s.v" string to epoch milliseconds.
     * Returns null on parse failure.
     */
    private function toEpochMs(string $datetime): ?int
    {
        // Handle millisecond timestamps (e.g. 2023-10-01 12:00:00.123)
        $format = strpos($datetime, '.') !== false ? 'Y-m-d H:i:s.u' : 'Y-m-d H:i:s';
        
        // If the timestamp has milliseconds (.123), pad it to microseconds (.123000) for DateTime::createFromFormat
        if ($format === 'Y-m-d H:i:s.u') {
            $parts = explode('.', $datetime);
            if (strlen($parts[1]) < 6) {
                $datetime = $parts[0] . '.' . str_pad($parts[1], 6, '0', STR_PAD_RIGHT);
            }
        }

        $date = DateTime::createFromFormat($format, $datetime);
        if ($date !== false) {
            return (int)($date->format('Uv'));
        }
        
        $ts = strtotime($datetime);
        if ($ts === false || $ts <= 0) {
            return null;
        }
        return $ts * 1000;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // BPJS Task Cache (daily JSON file + in-memory)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Load the daily BPJS task cache from disk.
     * Cache file: cache/mobilejkn/mobilejkn_tasks_YYYY-MM-DD.json
     *
     * Structure per kodebooking:
     * {
     *   "task_ids": ["3","4","5"],
     *   "tasks": [ {"taskid":3, "taskname":"...", "wakturs":"...", "waktu":"..."}, ... ],
     *   "fetched_at": "2026-05-19 09:10:05"
     * }
     */
    private function loadTaskCache(): void
    {
        $file = $this->cacheDir . '/mobilejkn_tasks_' . $this->cacheDate . '.json';
        if (file_exists($file)) {
            $raw = file_get_contents($file);
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $this->bpjsTaskCache = $decoded;
                $this->log->info("[CACHE] Loaded " . count($this->bpjsTaskCache) . " entries from {$file}");
                return;
            }
        }
        $this->bpjsTaskCache = [];
        $this->log->debug("[CACHE] No existing cache for {$this->cacheDate}");
    }

    /**
     * Persist the BPJS task cache to disk for debugging and cross-cycle reuse.
     */
    private function saveTaskCache(): void
    {
        $file = $this->cacheDir . '/mobilejkn_tasks_' . $this->cacheDate . '.json';
        file_put_contents(
            $file,
            json_encode($this->bpjsTaskCache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
        $this->log->info("[CACHE] Saved " . count($this->bpjsTaskCache) . " entries to {$file}");

        // Clean old cache files (keep 7 days)
        $cutoff = time() - (7 * 86400);
        foreach (glob($this->cacheDir . '/mobilejkn_tasks_*.json') as $oldFile) {
            if (filemtime($oldFile) < $cutoff) {
                @unlink($oldFile);
            }
        }
    }

    /**
     * Update cache after a task is successfully sent to BPJS.
     */
    private function updateCacheTask(string $kodebooking, string $taskId, string $waktuStr): void
    {
        if (!isset($this->bpjsTaskCache[$kodebooking])) {
            $this->bpjsTaskCache[$kodebooking] = [
                'task_ids'   => [],
                'tasks'      => [],
                'fetched_at' => date('Y-m-d H:i:s'),
            ];
        }

        if (!in_array($taskId, $this->bpjsTaskCache[$kodebooking]['task_ids'], true)) {
            $this->bpjsTaskCache[$kodebooking]['task_ids'][] = $taskId;
        }

        $this->bpjsTaskCache[$kodebooking]['tasks'][] = [
            'taskid'   => (int) $taskId,
            'taskname' => $this->taskIdToName($taskId),
            'wakturs'  => $waktuStr,
            'waktu'    => $waktuStr,
        ];
    }

    /**
     * Map task ID to human-readable name (for cache readability).
     */
    private function taskIdToName(string $taskId): string
    {
        return match ($taskId) {
            '1'  => 'mulai waktu tunggu admisi',
            '2'  => 'akhir waktu tunggu admisi / mulai waktu layan admisi',
            '3'  => 'akhir waktu layan admisi / mulai waktu tunggu poli',
            '4'  => 'akhir waktu tunggu poli / mulai waktu layan poli',
            '5'  => 'akhir waktu layan poli / mulai waktu tunggu farmasi',
            '6'  => 'akhir waktu tunggu farmasi / mulai waktu layan farmasi',
            '7'  => 'akhir waktu layan farmasi',
            '99' => 'batal',
            default => "task_{$taskId}",
        };
    }
}
