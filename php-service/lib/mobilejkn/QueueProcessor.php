<?php

/**
 * QueueProcessor - Core business logic for Mobile JKN queue sync.
 *
 * Orchestrates all 4 processing blocks:
 *  1. Add JKN queue entries (statuskirim=Belum → /antrean/add)
 *  2. Process cancellations (/antrean/batal + taskid=99)
 *  3. Update task IDs for JKN patients (3,4,5,6,7,99 + farmasi)
 *  4. Add Non-JKN patients + their task IDs
 *
 * Key improvements over Java:
 *  - N+1 eliminated: all task data fetched in 1 query per block
 *  - Unified task processing: JKN and Non-JKN share processTaskIdsForPatient()
 *  - Parallel HTTP: task updates batched via curl_multi
 *  - Smart retry: checks sent_taskids to skip already-sent, retries on prior failure
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

        $today    = $this->config->todayDate();
        $lookback = $this->config->lookbackDate();

        // Block 1: New JKN bookings
        $this->processNewJknBookings($lookback, $today);

        // Block 2: Cancellations
        $this->processCancellations($lookback, $today);

        // Block 3: Task IDs for JKN patients
        $this->processJknTaskIds($today, $today);

        // Block 4: Non-JKN patients (optional)
        if ($this->config->includeNonJkn) {
            $this->processNonJknPatients($today, $today);
        } else {
            $this->log->info("[NON-JKN] Skipped (MOBILEJKN_INCLUDE_NON_JKN=false)");
        }

        return [
            'success' => $this->successCount,
            'fail'    => $this->failCount,
            'skip'    => $this->skipCount,
        ];
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
            $noRawat = $c['no_rawat'];

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

        try {
            $patients = $this->db->fetchJknPatientsWithTaskData($dateFrom, $dateTo);
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

        // Collect all task update requests across all patients
        $allRequests = [];

        foreach ($patients as $p) {
            $kodebooking = $p['nobooking'];
            $noRawat     = $p['no_rawat'];
            $sentTasks   = $this->parseSentTaskIds($p['sent_taskids'] ?? '');

            // Process farmasi queue (separate endpoint, not a task ID)
            $this->processFarmasiQueue($kodebooking, $noRawat, $p);

            // Collect task requests for this patient
            $patientRequests = $this->buildTaskRequests($kodebooking, $noRawat, $p, $sentTasks);
            $allRequests = array_merge($allRequests, $patientRequests);
        }

        $this->executeTaskBatch($allRequests, 'BLOCK 3');
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

        // Step 1: Add queue entries via /antrean/add (batch)
        $addRequests = [];
        foreach ($patients as $p) {
            $addRequests[] = [
                'id'       => 'add_nonjkn_' . $p['no_rawat'],
                'endpoint' => '/antrean/add',
                'payload'  => $this->buildNonJknAntreanPayload($p),
                '_patient' => $p,
            ];
        }

        $addResults = $this->api->executeBatch($addRequests);

        // Step 2: For each successfully added patient, process task IDs
        $allTaskRequests = [];

        foreach ($addRequests as $req) {
            $id      = $req['id'];
            $result  = $addResults[$id] ?? ['success' => false];
            $p       = $req['_patient'];
            $noRawat = $p['no_rawat'];

            if (!$result['success']) {
                // If code is 208 (already exists), still process tasks
                if (($result['code'] ?? '') !== '208') {
                    $this->log->debug("[BLOCK 4] Skipping tasks for {$noRawat} (add failed)");
                    continue;
                }
            }

            $kodebooking = $noRawat; // Non-JKN uses no_rawat as kodebooking
            $sentTasks   = $this->parseSentTaskIds($p['sent_taskids'] ?? '');

            // Farmasi queue
            $this->processFarmasiQueue($kodebooking, $noRawat, $p);

            // Collect task requests
            $patientRequests = $this->buildTaskRequests($kodebooking, $noRawat, $p, $sentTasks);
            $allTaskRequests = array_merge($allTaskRequests, $patientRequests);
        }

        $this->executeTaskBatch($allTaskRequests, 'BLOCK 4');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Unified task processing (DRY — used by Block 3 & 4)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Build task update API requests for a single patient.
     * Checks each task's trigger data and skips already-sent tasks.
     *
     * @param string   $kodebooking  Booking code (nobooking for JKN, no_rawat for Non-JKN)
     * @param string   $noRawat      Visit registration number
     * @param array    $patientData  Row from batch query (contains task3_waktu..task99 fields)
     * @param string[] $sentTasks    List of already-sent task IDs
     * @return array[] Requests for executeBatch()
     */
    private function buildTaskRequests(string $kodebooking, string $noRawat, array $patientData, array $sentTasks): array
    {
        $requests = [];

        // Define task-to-field mapping: [taskId => fieldName, ...]
        $taskMap = [
            '3'  => 'task3_waktu',   // File sent to polyclinic
            '4'  => 'task4_waktu',   // File received at polyclinic
            '5'  => 'task5_waktu',   // Outpatient exam completed
            '6'  => 'task6_waktu',   // Prescription created
            '7'  => 'task7_waktu',   // Prescription dispensed
        ];

        foreach ($taskMap as $taskId => $field) {
            $waktuStr = $patientData[$field] ?? '';

            // Skip if no trigger data available
            if (empty($waktuStr) || str_starts_with($waktuStr, '0000')) continue;

            // Skip if already sent successfully
            if (in_array($taskId, $sentTasks, true)) {
                $this->skipCount++;
                continue;
            }

            // Try inserting task record (idempotency guard)
            if (!$this->db->insertTaskId($noRawat, $taskId, $waktuStr)) {
                $this->log->debug("[TASK] TaskID {$taskId} already recorded for {$noRawat}");
                $this->skipCount++;
                continue;
            }

            $waktuMs = $this->toEpochMs($waktuStr);
            if ($waktuMs === null) continue;

            $requests[] = [
                'id'       => "task_{$taskId}_{$noRawat}",
                'endpoint' => '/antrean/updatewaktu',
                'payload'  => [
                    'kodebooking' => $kodebooking,
                    'taskid'      => $taskId,
                    'waktu'       => $waktuMs,
                ],
                '_taskId'  => $taskId,
                '_noRawat' => $noRawat,
            ];
        }

        // Task 99: Visit cancelled (special — triggered by stts='Batal')
        $isCancelled = $patientData['is_cancelled'] ?? null;
        if ($isCancelled === 'Batal' && !in_array('99', $sentTasks, true)) {
            $nowStr = date('Y-m-d H:i:s');
            if ($this->db->insertTaskId($noRawat, '99', $nowStr)) {
                $waktuMs = $this->toEpochMs($nowStr);
                if ($waktuMs !== null) {
                    $requests[] = [
                        'id'       => "task_99_{$noRawat}",
                        'endpoint' => '/antrean/updatewaktu',
                        'payload'  => [
                            'kodebooking' => $kodebooking,
                            'taskid'      => '99',
                            'waktu'       => $waktuMs,
                        ],
                        '_taskId'  => '99',
                        '_noRawat' => $noRawat,
                    ];
                }
            }
        }

        return $requests;
    }

    /**
     * Execute a batch of task requests and handle DB rollback on failure.
     */
    private function executeTaskBatch(array $requests, string $blockLabel): void
    {
        if (empty($requests)) {
            $this->log->info("[{$blockLabel}] No pending task updates.");
            return;
        }

        $this->log->info("[{$blockLabel}] Sending " . count($requests) . " task update(s)...");
        $results = $this->api->executeBatch($requests);

        foreach ($requests as $req) {
            $id     = $req['id'];
            $result = $results[$id] ?? ['success' => false, 'code' => '?', 'message' => 'Missing result'];

            if ($result['success']) {
                $this->log->info("[{$blockLabel}] ✓ {$id}");
                $this->successCount++;
            } else {
                // Rollback: delete task record so it can be retried next cycle
                $this->db->deleteTaskId($req['_noRawat'], $req['_taskId']);
                $this->log->warning("[{$blockLabel}] ✗ {$id}: {$result['message']} (rolled back for retry)");
                $this->failCount++;
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Farmasi queue processing
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Add pharmacy queue entry if a prescription exists.
     * This is NOT a task ID — it's a separate /antrean/farmasi/add call.
     */
    private function processFarmasiQueue(string $kodebooking, string $noRawat, array $p): void
    {
        $noResep = $p['no_resep'] ?? '';
        if (empty($noResep)) return;

        $this->log->info("[FARMASI] Adding pharmacy queue for {$noRawat} (resep: {$noResep})");

        try {
            $jenisResep = $this->db->fetchResepType($noResep);
        } catch (\PDOException $e) {
            $this->log->error("[FARMASI] DB error checking resep type: " . $e->getMessage());
            return;
        }

        $nomorAntrean = (int) substr($noResep, -4);

        $result = $this->api->addFarmasiAntrean([
            'kodebooking'  => $kodebooking,
            'jenisresep'   => $jenisResep,
            'nomorantrean'  => $nomorAntrean,
            'keterangan'   => 'Resep dibuat secara elektronik di poli',
        ]);

        if ($result['success']) {
            $this->log->info("[FARMASI] ✓ Pharmacy queue added for {$noRawat}");
        } else {
            $this->log->debug("[FARMASI] Pharmacy queue response for {$noRawat}: {$result['code']} — {$result['message']}");
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
    // Helpers
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Parse sent_taskids GROUP_CONCAT string into an array.
     * e.g. "3,4,5" → ['3', '4', '5']
     */
    private function parseSentTaskIds(string $csv): array
    {
        if (empty($csv)) return [];
        return array_map('trim', explode(',', $csv));
    }

    /**
     * Convert "Y-m-d H:i:s" string to epoch milliseconds.
     * Returns null on parse failure.
     */
    private function toEpochMs(string $datetime): ?int
    {
        $ts = strtotime($datetime);
        if ($ts === false || $ts <= 0) {
            $this->log->warning("[TIME] Failed to parse datetime: {$datetime}");
            return null;
        }
        return $ts * 1000;
    }
}
