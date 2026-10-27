#!/usr/bin/env php8.4
<?php
/**
 * mobilejkn_sync_test.php — Unit tests for Mobile JKN Queue Sync Service.
 *
 * Runs without database or external network access.
 * Usage: php8.4 php-service/tests/mobilejkn_sync_test.php
 */

declare(strict_types=1);

define('BASE_DIR', dirname(__DIR__));

require_once BASE_DIR . '/lib/Logger.php';
require_once BASE_DIR . '/lib/mobilejkn/BpjsAntreanClient.php';
require_once BASE_DIR . '/lib/mobilejkn/Database.php';
require_once BASE_DIR . '/lib/mobilejkn/RobotInference.php';
require_once BASE_DIR . '/lib/mobilejkn/PayloadBuilder.php';

$passed = 0;
$failed = 0;

function ok(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  PASS  {$label}\n";
    } else {
        $failed++;
        echo "  FAIL  {$label}\n";
    }
}

echo "── 1. BpjsAntreanClient Response Code Parsing ──────────\n";

$log = new Logger(BASE_DIR . '/logs', 'test', 'ERROR', false, false);
$client = new BpjsAntreanClient(
    '12345',
    'secret',
    'userkey',
    'https://example.com/antreanrs',
    1,
    $log,
    false
);

$refClass = new ReflectionClass($client);
$parseMethod = $refClass->getMethod('parseApiResponse');
$parseMethod->setAccessible(true);

// Test metadata code 200 (standard success)
$res200 = $parseMethod->invoke($client, 'test', json_encode([
    'metadata' => ['code' => 200, 'message' => 'OK'],
    'response' => null
]), 200, time());
ok($res200['success'] === true, 'parseApiResponse accepts code 200 as success');

// Test metadata code 208 (duplicate booking accepted)
$res208 = $parseMethod->invoke($client, 'test', json_encode([
    'metadata' => ['code' => 208, 'message' => 'Data sudah ada'],
    'response' => null
]), 200, time());
ok($res208['success'] === true, 'parseApiResponse accepts code 208 as success');

// Test metadata code 1 (BPJS reference endpoints success)
$res1 = $parseMethod->invoke($client, 'test', json_encode([
    'metadata' => ['code' => 1, 'message' => 'OK'],
    'response' => null
]), 200, time());
ok($res1['success'] === true, 'parseApiResponse accepts code 1 as success for reference endpoints');

// Test metadata code 201 (business failure)
$res201 = $parseMethod->invoke($client, 'test', json_encode([
    'metadata' => ['code' => 201, 'message' => 'Gagal'],
    'response' => null
]), 200, time());
ok($res201['success'] === false, 'parseApiResponse rejects code 201 as success');

echo "\n── 2. Dynamic Schedule Fallback ────────────────────────\n";

$synthetic = MobileJknDatabase::buildSyntheticJadwal('JUMAT', '08:30:00');
ok($synthetic['hari_kerja'] === 'JUMAT', 'buildSyntheticJadwal sets correct hari');
ok($synthetic['jam_mulai'] === '08:30:00', 'buildSyntheticJadwal starts from jam_reg');
ok($synthetic['jam_selesai'] === '10:30:00', 'buildSyntheticJadwal sets 2-hour window');
ok($synthetic['kuota'] === 30, 'buildSyntheticJadwal defaults to 30 kuota');

$jadwalDict = [
    'SENIN|DOC1|POLI1' => [
        ['hari_kerja' => 'SENIN', 'kd_dokter' => 'DOC1', 'kd_poli' => 'POLI1', 'jam_mulai' => '08:00:00', 'jam_selesai' => '12:00:00', 'kuota' => 25]
    ]
];

$matched = MobileJknDatabase::fetchJadwalWithFallback($jadwalDict, 'SENIN', 'DOC1', 'POLI1', '09:00:00');
ok($matched['jam_mulai'] === '08:00:00' && $matched['kuota'] === 25, 'fetchJadwalWithFallback returns real schedule when present');

$fallback = MobileJknDatabase::fetchJadwalWithFallback($jadwalDict, 'JUMAT', 'DOC1', 'POLI1', '13:15:00');
ok($fallback['jam_mulai'] === '13:15:00' && $fallback['jam_selesai'] === '15:15:00' && $fallback['kuota'] === 30, 'fetchJadwalWithFallback falls back to synthetic schedule when missing');

echo "\n── 3. Universal Task 1 & 2 Monotonicity ────────────────\n";

$monotonicFailures = 0;
$waktu3 = '2026-10-26 10:00:00';
for ($i = 0; $i < 50; $i++) {
    $t1 = RobotInference::inferPrecedingTask('1', $waktu3);
    $t2 = RobotInference::inferPrecedingTask('2', $waktu3);
    $ts1 = strtotime($t1);
    $ts2 = strtotime($t2);
    $ts3 = strtotime($waktu3);
    if (!($ts1 < $ts2 && $ts2 < $ts3 && ($ts2 - $ts1 >= 180) && ($ts3 - $ts2 >= 180))) {
        $monotonicFailures++;
    }
}
ok($monotonicFailures === 0, 'inferPrecedingTask guarantees T1 < T2 < T3 with min 180s gap across 50 iterations');

echo "\n── 4. Smart Hybrid Pharmacy & Grace Period ─────────────\n";

require_once BASE_DIR . '/lib/mobilejkn/QueueProcessor.php';

// Past date should always be past grace period
$isPast1 = QueueProcessor::isPastGracePeriod('2026-10-01', '12:00:00', strtotime('2026-10-02 08:00:00'));
ok($isPast1 === true, 'isPastGracePeriod returns true for past dates');

// Today during clinic operating hours + 30 min (within 1 hour grace period)
$today = date('Y-m-d');
$nowOperating = strtotime("{$today} 12:30:00");
$isPast2 = QueueProcessor::isPastGracePeriod($today, '12:00:00', $nowOperating);
ok($isPast2 === false, 'isPastGracePeriod returns false within 1 hour after clinic closes');

// Today 2 hours after clinic closes (past 1 hour grace period)
$nowClosed = strtotime("{$today} 13:01:00");
$isPast3 = QueueProcessor::isPastGracePeriod($today, '12:00:00', $nowClosed);
ok($isPast3 === true, 'isPastGracePeriod returns true after 1 hour grace period expires');

// Sequential inference: T5 < T6 < T7 <= now
$waktu5 = '2026-10-26 10:00:00';
$waktu6 = RobotInference::infer('6', $waktu5, false);
ok(!empty($waktu6) && strtotime($waktu6) > strtotime($waktu5), 'RobotInference generates T6 > T5');
$waktu7 = RobotInference::infer('7', $waktu6, false);
ok(!empty($waktu7) && strtotime($waktu7) > strtotime($waktu6), 'RobotInference generates T7 > T6');

echo "\n── 5. Non-JKN Payload & Cancellation Resilience ────────\n";

$nonJknPatient = [
    'no_rawat' => '2026/10/26/000999',
    'no_reg' => 12,
    'kd_poli_bpjs' => 'INT',
    'nm_poli' => 'PENYAKIT DALAM',
    'stts_daftar' => 'Lama',
    'no_rkm_medis' => '123456',
    'tgl_registrasi' => '2026-10-26',
    'kd_dokter_bpjs' => '17277',
    'nm_dokter' => 'dr. Sp.PD',
    'jam_mulai' => '08:00:00',
    'jam_selesai' => '12:00:00',
    'kuota' => 30,
];

$payloadNonJkn = PayloadBuilder::onsitePatient($nonJknPatient, false, '', '20261026000099');
ok($payloadNonJkn['jenispasien'] === 'NON JKN', 'onsitePatient generates jenispasien NON JKN');
ok($payloadNonJkn['nomorkartu'] === '-', 'onsitePatient generates nomorkartu - for NON JKN');
ok($payloadNonJkn['nomorreferensi'] === '-', 'onsitePatient generates nomorreferensi - for NON JKN');

$isSepErr = QueueProcessor::isSepExternalError('Nomor Rujukan 0197R... sudah terbit SEP');
ok($isSepErr === true, 'isSepExternalError detects SEP already issued error message');

echo "\n── 6. Daemon Worker & Range Execution Methods ─────────\n";

$qpClass = new ReflectionClass(QueueProcessor::class);
ok($qpClass->hasMethod('runLiveToday'), 'QueueProcessor has runLiveToday method');
ok($qpClass->hasMethod('runCatchUpLookback'), 'QueueProcessor has runCatchUpLookback method');
ok($qpClass->hasMethod('runDateRange'), 'QueueProcessor has runDateRange method');

$dbClass = new ReflectionClass(MobileJknDatabase::class);
ok($dbClass->hasMethod('getPdo'), 'MobileJknDatabase has getPdo accessor method');
ok($dbClass->hasMethod('markBookingAsFailed'), 'MobileJknDatabase has markBookingAsFailed method');

echo "\n── 7. Review Findings Verification ─────────────────────\n";

// Test cancellation message matching
ok(QueueProcessor::isCancellationResponse(201, 'Antrean sudah dibatalkan sebelumnya'), 'isCancellationResponse recognizes "sudah dibatalkan"');
ok(QueueProcessor::isCancellationResponse(201, 'TaskId terakhir 99'), 'isCancellationResponse recognizes "terakhir 99"');
ok(QueueProcessor::isCancellationResponse(200, 'Ok'), 'isCancellationResponse recognizes code 200');

// Test synthetic midnight clamping
$lateJadwal = MobileJknDatabase::buildSyntheticJadwal('SENIN', '23:30:00');
ok($lateJadwal['jam_selesai'] === '23:59:00', 'buildSyntheticJadwal clamps late night schedule before midnight rollover');

echo "\n── 8. Production Remedies Verification ─────────────────\n";

// Test that JKN on-site patient without referral number is skipped from blind /antrean/add
$jknNoRef = [
    'kd_pj' => 'BPJ',
    'no_rawat' => '2026/10/27/000991',
    'nomorreferensi' => '',
];
ok(QueueProcessor::shouldSkipBlindJknAdd($jknNoRef, '') === true, 'shouldSkipBlindJknAdd returns true for JKN without referral');
ok(QueueProcessor::shouldSkipBlindJknAdd($jknNoRef, '-') === true, 'shouldSkipBlindJknAdd returns true for JKN with literal dash referral');
ok(QueueProcessor::shouldSkipBlindJknAdd($jknNoRef, '  -  ') === true, 'shouldSkipBlindJknAdd returns true for JKN with whitespace dash referral');
ok(QueueProcessor::shouldSkipBlindJknAdd($jknNoRef, '0197R0061026K001234') === false, 'shouldSkipBlindJknAdd returns false when referral exists');
ok(QueueProcessor::shouldSkipBlindJknAdd(['kd_pj' => 'A22'], '') === false, 'shouldSkipBlindJknAdd returns false for Non-JKN');

// Test stable booking code generation across multiple calls for same no_rawat
require_once BASE_DIR . '/lib/mobilejkn/Config.php';
$testConfig = new MobileJknConfig(BASE_DIR . '/.env');
$testDb = new MobileJknDatabase($testConfig, $log);
$patientA = ['no_rawat' => '2026/10/27/999999', 'tgl_registrasi' => '2026-10-27'];
$kb1 = $testDb->fetchOrGenerateNobooking($patientA, true);
$kb2 = $testDb->fetchOrGenerateNobooking($patientA, true);
ok($kb1 === $kb2, 'fetchOrGenerateNobooking returns identical booking code on repeated calls for same no_rawat');

// Test detection of Task 2 demand from BPJS error message
$msg1 = 'Task=1 ada. TaskId=2 belum ada';
ok(QueueProcessor::isTask2DemandedError($msg1) === true, 'isTask2DemandedError detects "Task=1 ada. TaskId=2 belum ada"');
$msg2 = 'TaskId=2 belum ada';
ok(QueueProcessor::isTask2DemandedError($msg2) === true, 'isTask2DemandedError detects "TaskId=2 belum ada"');
$msg3 = 'TaskId=3 belum ada';
ok(QueueProcessor::isTask2DemandedError($msg3) === false, 'isTask2DemandedError returns false for other task numbers');

// Test that mobilejkn_sync.php daemon initializes lastCatchUp to time() instead of 0
$syncFileContent = file_get_contents(BASE_DIR . '/mobilejkn_sync.php');
ok(!str_contains($syncFileContent, '$lastCatchUp = 0;'), 'mobilejkn_sync daemon does not initialize $lastCatchUp to 0');

echo "\nSummary: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
