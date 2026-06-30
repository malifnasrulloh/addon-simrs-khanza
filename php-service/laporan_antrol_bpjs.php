<?php
session_start();
require_once 'config/koneksi.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$page_title = "Pengiriman Antrean Online BPJS";
include 'includes/header.php';

$tgl1 = isset($_GET['tgl1']) ? $_GET['tgl1'] : date('Y-m-d', strtotime('-7 days'));
$tgl2 = isset($_GET['tgl2']) ? $_GET['tgl2'] : date('Y-m-d');

// Metrik
$metrics = [
    'total_encounter' => 0,
    'total_resep' => 0,
    't3_sent' => 0,
    't4_sent' => 0,
    't5_sent' => 0,
    't6_sent' => 0,
    't7_sent' => 0,
    'complete_journey' => 0,
    'incomplete_journey' => 0
];

$tableRows = "";
$tableRowsRaw = "";

function renderRawCell($erm, $bpjs) {
    // Cleaning ERM Time
    $erm = (empty($erm) || $erm == ' ') ? '-' : $erm;
    $erm_t = ($erm !== '-' && strlen($erm) > 10) ? substr($erm, 11, 8) : $erm;
    
    // Cleaning BPJS Time
    $bpjs = (strpos($bpjs, ':') === false) ? '-' : $bpjs;
    $bpjs_t = ($bpjs !== '-' && strlen($bpjs) > 10) ? substr($bpjs, 11, 8) : $bpjs;

    $out_erm = "<div class='small text-truncate' style='max-width: 140px;'><span class='text-secondary'>ERM RS:</span> <b>$erm_t</b></div>";
    $out_bpjs = "<div class='small text-truncate' style='max-width: 140px;'><span class='text-primary fw-bold'>BPJS:</span> $bpjs_t</div>";

    $flags = "";
    
    // Skenario 1: ERM kosong tapi BPJS jalan (Bot menambal kelalaian PPA/User)
    if ($erm === '-' && $bpjs !== '-') {
        $flags .= "<div class='mt-1'><span class='badge shadow-sm text-dark' style='background-color:#fd7e14; font-size: 0.65rem;' title='Petugas tidak mengisi di SIMRS, tetapi Bot mem-bypass validasi dan menambal task ID ke server BPJS'><i class='fas fa-robot'></i> Ditambal Bot</span></div>";
    }
    
    // Skenario 2: ERM terisi, BPJS kosong (Gagal bridging). Beri red flag menyala.
    if ($erm !== '-' && $bpjs === '-') {
        $flags .= "<div class='mt-1'><span class='badge bg-danger shadow-sm' style='font-size: 0.65rem;' title='Aktivitas tercatat baik di SIMRS, namun SIMRS gagal mem-bridging ke BPJS (Bisa karena Timeout/Error API)'><i class='fas fa-exclamation-triangle'></i> Gagal Bridging</span></div>";
    }
    
    // Skenario 3: Keduanya jalan, tapi selisih > 20 menit (Bot delay push)
    if ($erm !== '-' && $bpjs !== '-') {
         $et = strtotime("1970-01-01 " . $erm_t);
         $bt = strtotime("1970-01-01 " . $bpjs_t);
         
         if ($et && $bt) {
             $diff_mins = ($bt - $et) / 60;
             if ($diff_mins > 20) {
                 $flags .= "<div class='mt-1'><span class='badge bg-warning text-dark shadow-sm' style='font-size: 0.65rem;' title='Terdapat selisih waktu " . round($diff_mins) . " Menit. Injeksi Task ID susulan dieksekusi oleh Bot.'>Selisih ".round($diff_mins)."m <i class='fas fa-robot'></i></span></div>";
             }
         }
    }
    
    // Jika keduanya murni kosong (Misal: Px tidak diresepkan obat farmasi)
    if ($erm === '-' && $bpjs === '-') {
        return "<div class='text-muted small text-center w-100'>-</div>";
    }

    return $out_erm . $out_bpjs . $flags;
}

$sql = "SELECT
    rp.no_rawat,
    rmj.nobooking,
    rp.tgl_registrasi,
    ps.no_rkm_medis,
    ps.nm_pasien,
    pj.png_jawab,
    d.nm_dokter,
    COALESCE(mpb.nm_poli_bpjs, '') AS `nm_poli_bpjs`,
    pl.nm_poli AS `poli_rs`,
    COALESCE(jdl.jam_praktek, '-') AS `jadwal`,
    COALESCE(jdl.kuota_jadwal, 0) AS `kuota`,
    IFNULL(rmj.status, 'On Site') AS `Status Checkin MJKN`,
    COALESCE(md.kd_dokter_bpjs, '') AS `kd_dokter_bpjs`,
    COALESCE(mpb.kd_poli_bpjs, '') AS `kd_poli_bpjs`,
    DATE_FORMAT(MAX(CASE WHEN rmjt.taskid = '1' THEN rmjt.waktu END), '%H:%i:%s') AS `log TID_1`,
    DATE_FORMAT(MAX(CASE WHEN rmjt.taskid = '2' THEN rmjt.waktu END), '%H:%i:%s') AS `log TID_2`,
    COALESCE((CASE WHEN rp.stts = 'Batal' THEN rp.stts END), '-') AS `Cancel`,
    rmj.status AS `status_mjkn`,
    rmb.statuskirim AS `statuskirim_batal`,
    rmb.keterangan AS `ket_batal`,
    bs.no_sep AS `no_sep`,
    CONCAT(rp.tgl_registrasi, ' ', rp.jam_reg) AS `Jam Registrasi`,
    rmj.validasi AS `Jam Checkin`,
    COALESCE(DATE_FORMAT(MAX(CASE WHEN rmjt.taskid = '3' THEN rmjt.waktu END), '%H:%i:%s'), 'SEP tdk Bridging / telat checkin') AS `log TID_3`,
    COALESCE(
        (SELECT CONCAT(pr.tgl_perawatan, ' ', pr.jam_rawat) FROM pemeriksaan_ralan pr WHERE pr.no_rawat = rp.no_rawat LIMIT 1),
        (SELECT pmr.tanggal FROM penilaian_medis_ralan pmr WHERE pmr.no_rawat = rp.no_rawat LIMIT 1),
        (SELECT CONCAT(rjd.tgl_perawatan, ' ', rjd.jam_rawat) FROM rawat_jl_dr rjd WHERE rjd.no_rawat = rp.no_rawat LIMIT 1),
        (SELECT CONCAT(cp.tanggal, ' ', cp.jam) FROM catatan_perawatan cp WHERE cp.no_rawat = rp.no_rawat LIMIT 1)
    ) AS `TID 4 input CPPT`,
    COALESCE(DATE_FORMAT(MAX(CASE WHEN rmjt.taskid = '4' THEN rmjt.waktu END), '%H:%i:%s'), 'tdk isi cppt') AS `log TID_4`,
    mb.kembali AS `TID 5 set status SUDAH`,
    COALESCE(DATE_FORMAT(MAX(CASE WHEN rmjt.taskid = '5' THEN rmjt.waktu END), '%H:%i:%s'), 'tdk klik yes di cppt') AS `log TID_5`,
    ro.no_resep,
    CONCAT(ro.tgl_perawatan, ' ', ro.jam) AS `TID 6 validasi resep`,
    COALESCE(DATE_FORMAT(MAX(CASE WHEN rmjt.taskid = '6' THEN rmjt.waktu END), '%H:%i:%s'), 'Apt tdk validasi') AS `log TID_6`,
    CONCAT(ro.tgl_penyerahan, ' ', ro.jam_penyerahan) AS `TID 7 penyerahan resep`,
    COALESCE(DATE_FORMAT(MAX(CASE WHEN rmjt.taskid = '7' THEN rmjt.waktu END), '%H:%i:%s'), 'Apt tdk penyerahan obt') AS `log TID_7`
FROM reg_periksa rp
LEFT JOIN penjab pj ON rp.kd_pj = pj.kd_pj
LEFT JOIN pasien ps ON rp.no_rkm_medis = ps.no_rkm_medis
LEFT JOIN dokter d ON rp.kd_dokter = d.kd_dokter
LEFT JOIN poliklinik pl ON rp.kd_poli = pl.kd_poli
LEFT JOIN maping_poli_bpjs mpb ON rp.kd_poli = mpb.kd_poli_rs
LEFT JOIN maping_dokter_dpjpvclaim md ON rp.kd_dokter = md.kd_dokter
LEFT JOIN (
    SELECT
        kd_dokter, kd_poli, hari_kerja,
        GROUP_CONCAT(CONCAT(DATE_FORMAT(jam_mulai, '%H:%i'), '-', DATE_FORMAT(jam_selesai, '%H:%i')) SEPARATOR ', ') as jam_praktek,
        SUM(kuota) as kuota_jadwal
    FROM jadwal
    GROUP BY kd_dokter, kd_poli, hari_kerja
) jdl ON rp.kd_dokter = jdl.kd_dokter
    AND rp.kd_poli = jdl.kd_poli
    AND jdl.hari_kerja = (CASE DAYNAME(rp.tgl_registrasi)
        WHEN 'Monday' THEN 'SENIN'
        WHEN 'Tuesday' THEN 'SELASA'
        WHEN 'Wednesday' THEN 'RABU'
        WHEN 'Thursday' THEN 'KAMIS'
        WHEN 'Friday' THEN 'JUMAT'
        WHEN 'Saturday' THEN 'SABTU'
        WHEN 'Sunday' THEN 'AKHAD'
    END)
LEFT JOIN referensi_mobilejkn_bpjs_taskid rmjt ON rp.no_rawat = rmjt.no_rawat
LEFT JOIN referensi_mobilejkn_bpjs rmj ON rp.no_rawat = rmj.no_rawat
LEFT JOIN mutasi_berkas mb ON rp.no_rawat = mb.no_rawat
LEFT JOIN resep_obat ro ON rp.no_rawat = ro.no_rawat
LEFT JOIN referensi_mobilejkn_bpjs_batal rmb ON rp.no_rawat = rmb.no_rawat_batal
LEFT JOIN bridging_sep bs ON rp.no_rawat = bs.no_rawat
WHERE rp.tgl_registrasi BETWEEN ? AND ?
    AND rp.kd_poli != 'IGDK'
    AND rp.kd_pj = 'BPJ'
    AND rp.no_rawat NOT IN (
        SELECT no_rawat FROM referensi_mobilejkn_bpjs_taskid WHERE taskid = '99'
    )
GROUP BY rp.no_rawat
ORDER BY rp.no_rawat ASC";

if ($stmt = $koneksi->prepare($sql)) {
    $stmt->bind_param("ss", $tgl1, $tgl2);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $t3 = (strpos($row['log TID_3'], ':') !== false);

            $is_batal_rs = ($row['Cancel'] === 'Batal');
            $is_anomaly_batal = false;
            
            if ($is_batal_rs && $t3) {
                if ($row['status_mjkn'] !== 'Batal' || $row['statuskirim_batal'] !== 'Sudah') {
                    $is_anomaly_batal = true;
                }
            }

            if ($is_batal_rs && !$is_anomaly_batal) continue; // Lewati pasien batal normal

            if (!$is_anomaly_batal) {
                $metrics['total_encounter']++;
            }
            
            $t4 = (strpos($row['log TID_4'], ':') !== false);
            $t5 = (strpos($row['log TID_5'], ':') !== false);
            $t6 = (strpos($row['log TID_6'], ':') !== false);
            $t7 = (strpos($row['log TID_7'], ':') !== false);
            
            $has_resep = !empty($row['no_resep']);
            $is_complete = false;

            if (!$is_anomaly_batal) {
                if ($has_resep) {
                    $metrics['total_resep']++;
                }

                if ($t3) $metrics['t3_sent']++;
                if ($t4) $metrics['t4_sent']++;
                if ($t5) $metrics['t5_sent']++;
                if ($has_resep && $t6) $metrics['t6_sent']++;
                if ($has_resep && $t7) $metrics['t7_sent']++;

                if ($has_resep) {
                    if ($t3 && $t4 && $t5 && $t6 && $t7) $is_complete = true;
                } else {
                    if ($t3 && $t4 && $t5) $is_complete = true;
                }

                if ($is_complete) {
                    $metrics['complete_journey']++;
                } else {
                    $metrics['incomplete_journey']++;
                }

                $poli_name = $row['Poliklinik'] ?? 'Unknown';
                if (!isset($poly_stats[$poli_name])) {
                    $poly_stats[$poli_name] = ['total' => 0, 'complete' => 0];
                }
                $poly_stats[$poli_name]['total']++;
                if ($is_complete) $poly_stats[$poli_name]['complete']++;

                $tgl_reg = $row['tgl_registrasi'];
                if (!isset($trend_stats[$tgl_reg])) {
                    $trend_stats[$tgl_reg] = ['total' => 0, 'complete' => 0];
                }
                $trend_stats[$tgl_reg]['total']++;
                if ($is_complete) $trend_stats[$tgl_reg]['complete']++;
            }

            // Eksekutif Table Badges
            $b3 = $t3 ? "<span class='badge bg-success' title='Sent: {$row['log TID_3']}'><i class='fas fa-check'></i> {$row['log TID_3']}</span>" : "<span class='badge bg-danger' title='Belum terkirim'><i class='fas fa-times'></i> Gagal</span>";
            $b4 = $t4 ? "<span class='badge bg-success' title='Sent: {$row['log TID_4']}'><i class='fas fa-check'></i> {$row['log TID_4']}</span>" : "<span class='badge bg-danger' title='Belum terkirim'><i class='fas fa-times'></i> Gagal</span>";
            $b5 = $t5 ? "<span class='badge bg-success' title='Sent: {$row['log TID_5']}'><i class='fas fa-check'></i> {$row['log TID_5']}</span>" : "<span class='badge bg-danger' title='Belum terkirim'><i class='fas fa-times'></i> Gagal</span>";
            
            if ($has_resep) {
                $b6 = $t6 ? "<span class='badge bg-success' title='Sent: {$row['log TID_6']}'><i class='fas fa-check'></i> {$row['log TID_6']}</span>" : "<span class='badge bg-danger' title='Belum terkirim'><i class='fas fa-times'></i> Gagal</span>";
                $b7 = $t7 ? "<span class='badge bg-success' title='Sent: {$row['log TID_7']}'><i class='fas fa-check'></i> {$row['log TID_7']}</span>" : "<span class='badge bg-danger' title='Belum terkirim'><i class='fas fa-times'></i> Gagal</span>";
            } else {
                $b6 = "<span class='badge bg-secondary' title='Tidak diresepkan obat'>N/A</span>";
                $b7 = "<span class='badge bg-secondary' title='Tidak diresepkan obat'>N/A</span>";
            }
            
            if ($is_anomaly_batal) {
                $ket = (!empty($row['ket_batal'])) ? htmlspecialchars($row['ket_batal']) : "Task 99 BPJS belum terkirim";
                $journey_badge = "<div class='badge bg-danger shadow-sm text-wrap' style='max-width: 140px; font-size: 0.75rem;'><i class='fas fa-bomb'></i> ANOMALI BATAL:<br><small class='fw-normal'>$ket</small></div>";
            } else {
                $journey_badge = $is_complete ? "<span class='badge bg-primary'><i class='fas fa-certificate'></i> LENGKAP</span>" : "<span class='badge bg-dark'><i class='fas fa-exclamation-triangle'></i> BOCOR</span>";
            }

            $mjkn_badge = !empty($row['nobooking']) ? "<br><span class='badge shadow-sm mt-1' style='background-color: #0dcaf0; color: #000; font-size: 0.70rem;'><i class='fas fa-mobile-alt me-1'></i> MJKN: {$row['nobooking']}</span>" : "";
            $sep_badge = !empty($row['no_sep']) ? "<br><span class='badge mt-1 text-dark shadow-sm' style='background-color: #ffc107; font-size: 0.70rem;'><i class='fas fa-file-medical-alt me-1'></i> SEP: {$row['no_sep']}</span>" : "";
            
            $mjkn_badge .= $sep_badge;

            if ($is_anomaly_batal) {
                $mjkn_badge .= "<br><span class='badge bg-danger mt-1' style='font-size: 0.70rem;'><i class='fas fa-exclamation-triangle'></i> Anomali Batal</span>";
            }

            // Check for missing BPJS mappings (sync service alignment)
            $noMappingBadge = "";
            if (empty($row['Poliklinik']) || $row['Poliklinik'] === $row['poli_rs']) {
                $noMappingBadge = "<br><span class='badge bg-warning text-dark mt-1' style='font-size: 0.65rem;' title='Tidak ada mapping BPJS untuk poli/dokter ini — pasien tetap tampil tapi mungkin tidak ter-sync'><i class='fas fa-exclamation-circle'></i> No BPJS Mapping</span>";
            }

            $tableRows .= "<tr>
                <td>{$row['tgl_registrasi']}</td>
                <td><strong>{$row['no_rawat']}</strong> {$mjkn_badge}</td>
                <td>{$row['nm_pasien']}<br><small class='text-muted'>{$row['nm_dokter']}</small></td>
                <td>
                    <span class='fw-bold'>{$row['Poliklinik']}</span>{$noMappingBadge}<br>
                    <small class='text-muted'>{$row['poli_rs']}</small><br>
                    <span class='badge bg-info text-dark' style='font-size:0.65rem;'><i class='fas fa-clock me-1'></i>{$row['jadwal']}</span>
                    <span class='badge bg-secondary' style='font-size:0.65rem;' title='Kuota Sesi'><i class='fas fa-users me-1'></i>{$row['kuota']}</span>
                </td>
                <td>$b3</td>
                <td>$b4</td>
                <td>$b5</td>
                <td>$b6</td>
                <td>$b7</td>
                <td>$journey_badge</td>
            </tr>";
            
            // Raw Data Analytics
            $jam_checkin_t3 = (!empty($row['Jam Checkin']) && $row['Jam Checkin'] !== '-') ? $row['Jam Checkin'] : $row['Jam Registrasi'];
            $c3 = renderRawCell($jam_checkin_t3, $row['log TID_3']);
            $c4 = renderRawCell($row['TID 4 input CPPT'], $row['log TID_4']);
            $c5 = renderRawCell($row['TID 5 set status SUDAH'], $row['log TID_5']);
            $c6 = renderRawCell($row['TID 6 validasi resep'], $row['log TID_6']);
            $c7 = renderRawCell($row['TID 7 penyerahan resep'], $row['log TID_7']);

            $tableRowsRaw .= "<tr>
                <td><strong>{$row['no_rawat']}</strong> {$mjkn_badge}</td>
                <td>{$row['nm_pasien']}<br><small class='text-muted'>Jadwal: {$row['jadwal']} (Q:{$row['kuota']})</small></td>
                <td>$c3</td>
                <td>$c4</td>
                <td>$c5</td>
                <td>$c6</td>
                <td>$c7</td>
            </tr>";
        }
    }
}

function prc($sent, $total) {
    if ($total == 0) return 0;
    return round(($sent / $total) * 100, 1);
}

$pct3 = prc($metrics['t3_sent'], $metrics['total_encounter']);
$pct4 = prc($metrics['t4_sent'], $metrics['total_encounter']);
$pct5 = prc($metrics['t5_sent'], $metrics['total_encounter']);
$pct6 = prc($metrics['t6_sent'], $metrics['total_resep']);
$pct7 = prc($metrics['t7_sent'], $metrics['total_resep']);

// Sort poly_stats by completion rate ascending
$poly_lowest = [];
foreach ($poly_stats as $name => $stat) {
    if ($stat['total'] > 5) { // Only consider polyclinics with > 5 patients for statistically relevant lowest rates
         $poly_lowest[$name] = round(($stat['complete'] / $stat['total']) * 100, 1);
    }
}
asort($poly_lowest);
$poly_lowest = array_slice($poly_lowest, 0, 4, true);

ksort($trend_stats);
?>

<style>
    .glass-card { background: rgba(255, 255, 255, 0.95); border: 1px solid rgba(255,255,255,0.4); border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
    .scorecard { padding: 15px; border-radius: 12px; text-align: center; border: 1px solid #e9ecef; background: #fff; transition: transform 0.2s; }
    .scorecard:hover { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
    .scorecard h5 { font-size: 0.8rem; color: #6c757d; font-weight: 600; text-transform: uppercase; margin-bottom: 5px; }
    .scorecard h2 { font-size: 1.6rem; font-weight: 700; margin-bottom: 0px; }
    .text-danger-score { color: #dc3545; }
    .text-success-score { color: #198754; }
    .table-container { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); border-top-left-radius: 0; }
    
    /* Toggle Switch Styles */
    .view-toggle { display: inline-flex; background: #f8f9fa; border-radius: 20px; padding: 3px; border: 1px solid #dee2e6; }
    .view-toggle .btn { border-radius: 17px; padding: 4px 15px; font-size: 0.85rem; font-weight: 600; border: none; }
    .view-toggle .btn.active { background: #0d6efd; color: white; box-shadow: 0 2px 5px rgba(13, 110, 253, 0.3); }
    .view-toggle .btn:not(.active) { color: #6c757d; }

    /* Nav Tabs */
    .nav-tabs .nav-link { font-size: 0.95rem; font-weight: 600; color: #6c757d; border-radius: 10px 10px 0 0; border: none; padding: 12px 20px; margin-right: 5px; }
    .nav-tabs .nav-link.active { background-color: #fff; border: 1px solid rgba(0,0,0,0.08); border-bottom-color: transparent; box-shadow: 0 -3px 10px rgba(0,0,0,0.03); }
    .nav-tabs { border-bottom: 2px solid rgba(0,0,0,0.05); }
</style>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
    <h1 class="h2"><i class="fas fa-mobile-alt text-success me-2"></i> Eksekutif Report: Antrean Online BPJS</h1>
    <form class="d-flex" method="GET" action="laporan_antrol_bpjs.php" onsubmit="document.getElementById('globalLoadingOverlay').style.display='flex';">
        <div class="input-group input-group-sm shadow-sm">
            <span class="input-group-text bg-white"><i class="fas fa-calendar-alt"></i></span>
            <input type="date" class="form-control" name="tgl1" value="<?php echo $tgl1; ?>" required>
            <span class="input-group-text bg-white">s.d</span>
            <input type="date" class="form-control" name="tgl2" value="<?php echo $tgl2; ?>" required>
            <button class="btn btn-success" type="submit"><i class="fas fa-filter me-1"></i> Render Data</button>
        </div>
    </form>
</div>

<!-- SCORECARDS -->
<div class="row mb-4 g-3">
    <div class="col-md-2 col-6">
        <div class="scorecard bg-light border-0">
            <h5>Pendaftaran BPJS</h5>
            <h2 class="text-dark"><?php echo number_format($metrics['total_encounter'], 0, ',', '.'); ?></h2>
            <small class="text-muted text-truncate w-100 d-inline-block">Px dengan resep: <?php echo number_format($metrics['total_resep'], 0, ',', '.'); ?></small>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="scorecard border-top-0 border-end-0 border-bottom-0 border-4 border-primary">
            <h5>Task 3 (Admisi)</h5>
            <h2 class="<?php echo ($pct3 < 90) ? 'text-danger-score' : 'text-success-score'; ?>"><?php echo $pct3; ?>%</h2>
            <small class="text-muted"><?php echo number_format($metrics['t3_sent'],0,',','.'); ?> terkirim</small>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="scorecard border-top-0 border-end-0 border-bottom-0 border-4 border-info">
            <h5>Task 4 (Layan Dok)</h5>
            <h2 class="<?php echo ($pct4 < 90) ? 'text-danger-score' : 'text-success-score'; ?>"><?php echo $pct4; ?>%</h2>
            <small class="text-muted"><?php echo number_format($metrics['t4_sent'],0,',','.'); ?> terkirim</small>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="scorecard border-top-0 border-end-0 border-bottom-0 border-4 border-success">
            <h5>Task 5 (Usai Poli)</h5>
            <h2 class="<?php echo ($pct5 < 90) ? 'text-danger-score' : 'text-success-score'; ?>"><?php echo $pct5; ?>%</h2>
            <small class="text-muted"><?php echo number_format($metrics['t5_sent'],0,',','.'); ?> terkirim</small>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="scorecard border-top-0 border-end-0 border-bottom-0 border-4 border-warning">
            <h5>Task 6 (Apotek Val)</h5>
            <h2 class="<?php echo ($pct6 < 90) ? 'text-danger-score' : 'text-success-score'; ?>"><?php echo $pct6; ?>%</h2>
            <small class="text-muted text-truncate w-100 d-inline-block">Dihitung dari: <?php echo number_format($metrics['total_resep'], 0, ',', '.'); ?> Resep</small>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="scorecard border-top-0 border-end-0 border-bottom-0 border-4 border-danger">
            <h5>Task 7 (Ambil Obat)</h5>
            <h2 class="<?php echo ($pct7 < 90) ? 'text-danger-score' : 'text-success-score'; ?>"><?php echo $pct7; ?>%</h2>
            <small class="text-muted text-truncate w-100 d-inline-block">Dihitung dari: <?php echo number_format($metrics['total_resep'], 0, ',', '.'); ?> Resep</small>
        </div>
    </div>
</div>

<!-- CHARTS ROW -->
<div class="row mb-4 g-4">
    <div class="col-lg-4">
        <div class="glass-card p-3 h-100 d-flex flex-column">
            <h6 class="text-muted fw-bold"><i class="fas fa-ring me-2 text-primary"></i> Journey Completion Status</h6>
            <p class="small text-muted mb-3">Persentase Rantai Bridging Utuh (Px Non-Obat lulus di T5, Px dengan Resep lulus di T7).</p>
            <div class="flex-grow-1 position-relative" style="min-height: 220px;">
                <canvas id="doughnutChart"></canvas>
            </div>
            <div class="text-center mt-3">
                <span class="badge bg-success me-2">Lengkap: <?php echo $metrics['complete_journey']; ?></span>
                <span class="badge bg-danger">Bocor: <?php echo $metrics['incomplete_journey']; ?></span>
            </div>
        </div>
    </div>
    
    <div class="col-lg-8">
        <div class="glass-card p-3 h-100 d-flex flex-column">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h6 class="text-muted fw-bold mb-0"><i class="fas fa-chart-bar me-2 text-success"></i> Analisis Tingkat Drop-Off</h6>
                    <small class="text-muted">Pantau di titik mana rekam bridging sering terputus.</small>
                </div>
                <div class="view-toggle">
                    <button class="btn active" id="btnBar" onclick="switchChart('bar')"><i class="fas fa-chart-column"></i> Bar</button>
                    <button class="btn" id="btnFunnel" onclick="switchChart('funnel')"><i class="fas fa-filter"></i> Funnel</button>
                </div>
            </div>
            <div class="flex-grow-1 position-relative" style="min-height: 250px;">
                <canvas id="pipelineChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- TREND & POLI ROW -->
<div class="row mb-4 g-4">
    <div class="col-lg-8">
        <div class="glass-card p-3 h-100 d-flex flex-column">
            <h6 class="text-muted fw-bold"><i class="fas fa-chart-line me-2 text-info"></i> Trend Harian Keparipurnaan</h6>
            <div class="flex-grow-1 position-relative" style="min-height: 250px;">
                <canvas id="trendChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="glass-card p-3 h-100 d-flex flex-column">
            <h6 class="text-muted fw-bold"><i class="fas fa-hospital-user me-2 text-danger"></i> Poliklinik Terendah</h6>
            <p class="small text-muted mb-3">Tingkat paripurna terendah (Min 5 Px).</p>
            <ul class="list-group list-group-flush flex-grow-1">
                <?php
                if (empty($poly_lowest)) {
                    echo "<li class='list-group-item text-center text-muted'>Data belum memadai</li>";
                } else {
                    foreach ($poly_lowest as $poli => $rate) {
                        $badgeClass = $rate < 90 ? 'bg-danger' : 'bg-warning text-dark';
                        echo "<li class='list-group-item d-flex justify-content-between align-items-center'>
                                <span class='small text-truncate' style='max-width:200px;'>{$poli}</span>
                                <span class='badge {$badgeClass} rounded-pill'>{$rate}%</span>
                              </li>";
                    }
                }
                ?>
            </ul>
        </div>
    </div>
</div>

<!-- TABS DATA -->
<ul class="nav nav-tabs border-0 mt-5" id="antrolTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active text-success" id="matrix-tab" data-bs-toggle="tab" data-bs-target="#matrix-pane" type="button" role="tab" aria-controls="matrix-pane" aria-selected="true"><i class="fas fa-tasks me-2"></i>Matrix Keparipurnaan (Eksekutif)</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link text-primary" id="raw-tab" data-bs-toggle="tab" data-bs-target="#raw-pane" type="button" role="tab" aria-controls="raw-pane" aria-selected="false"><i class="fas fa-code-branch me-2"></i>Data Timestamp & AI Bot (IT)</button>
    </li>
</ul>

<div class="tab-content" id="antrolTabsContent">
    <div class="tab-pane fade show active" id="matrix-pane" role="tabpanel" aria-labelledby="matrix-tab" tabindex="0">
        <div class="table-container pt-4 border border-top-0">
            <h5 class="fw-bold mb-3"><i class="fas fa-shield-alt text-success me-2"></i>Laporan Validasi Antrol Standard</h5>
            <div class="table-responsive">
                <table id="antrolTable" class="table table-striped table-hover align-middle w-100" style="font-size: 0.82rem;">
                    <thead class="table-dark">
                        <tr>
                            <th>Terdaftar (Tgl)</th>
                            <th>No Rawat / Nomer Antrean</th>
                            <th>Pasien / Dokter</th>
                            <th>Poli & Jadwal Praktek</th>
                            <th>[3] Admisi</th>
                            <th>[4] CPPT</th>
                            <th>[5] End Layan</th>
                            <th>[6] Val Obat</th>
                            <th>[7] Beri Obat</th>
                            <th class="text-center">Status Journey</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php echo $tableRows; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB 2: IT RAW DATA / BOT ANALYTICS -->
    <div class="tab-pane fade" id="raw-pane" role="tabpanel" aria-labelledby="raw-tab" tabindex="0">
        <div class="table-container pt-4 border border-top-0">
            <h5 class="fw-bold mb-3"><i class="fas fa-laptop-code text-primary me-2"></i>Analisis Timestamp (Bot IT)</h5>
            <div class="alert alert-info py-2 small mb-3">
                <i class="fas fa-info-circle me-1"></i> Data menyandingkan jejak ERM RS vs BPJS. <b>Orange Flag</b> mendandakan Bot IT menambal kekosongan input petugas, sedangkan <b>Red Flag</b> (Merah Menyala) secara eksklusif membuktikan SIMRS gagal melakukan <i>push/bridging</i> padahal petugas sudah entri data di sistem.
            </div>
            <div class="table-responsive">
                <table id="rawTable" class="table table-bordered table-hover align-middle w-100" style="font-size: 0.82rem;">
                    <thead class="table-light">
                        <tr>
                            <th width="12%">No Rawat</th>
                            <th width="20%">Pasien & Sesi Jadwal</th>
                            <th>Task 3 (Admisi)</th>
                            <th>Task 4 (Awal Medis)</th>
                            <th>Task 5 (Selesai Medis)</th>
                            <th>Task 6 (Farmasi Obat)</th>
                            <th>Task 7 (Penyerahan)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php echo $tableRowsRaw; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
let pipelineChartInst = null;
const ctxPipeline = document.getElementById('pipelineChart').getContext('2d');

const barData = {
    labels: ['Task 3 (Admisi)', 'Task 4 (CPPT Dokter)', 'Task 5 (Selesai Poli)', 'Task 6 (Apotek Val)', 'Task 7 (Penyerahan Obat)'],
    datasets: [{
        label: 'Volume Berhasil Terkirim ke BPJS',
        data: [
            <?php echo $metrics['t3_sent']; ?>, 
            <?php echo $metrics['t4_sent']; ?>, 
            <?php echo $metrics['t5_sent']; ?>, 
            <?php echo $metrics['t6_sent']; ?>, 
            <?php echo $metrics['t7_sent']; ?>
        ],
        backgroundColor: ['#0d6efd', '#0dcaf0', '#198754', '#ffc107', '#dc3545'],
        borderRadius: 6
    }]
};

const funnelData = {
    labels: ['Task 3 (Masuk)', 'Task 4 (Lolos)', 'Task 5 (Lolos)', 'Task 6 (Sub-Farmasi)', 'Task 7 (Sub-Farmasi)'],
    datasets: [{
        axis: 'y',
        label: 'Volume Melewati Titik',
        data: [
            <?php echo $metrics['t3_sent']; ?>, 
            <?php echo $metrics['t4_sent']; ?>, 
            <?php echo $metrics['t5_sent']; ?>, 
            <?php echo $metrics['t6_sent']; ?>, 
            <?php echo $metrics['t7_sent']; ?>
        ],
        fill: false,
        backgroundColor: 'rgba(25, 135, 84, 0.7)',
        borderColor: '#198754',
        borderWidth: 1,
        borderRadius: 4
    }]
};

function renderBarChart() {
    if(pipelineChartInst) pipelineChartInst.destroy();
    pipelineChartInst = new Chart(ctxPipeline, {
        type: 'bar',
        data: barData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true } }
        }
    });
}

function renderFunnelChart() {
    if(pipelineChartInst) pipelineChartInst.destroy();
    pipelineChartInst = new Chart(ctxPipeline, {
        type: 'bar',
        data: funnelData,
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { x: { beginAtZero: true } }
        }
    });
}

function switchChart(type) {
    $('.view-toggle .btn').removeClass('active');
    if(type === 'bar') {
        $('#btnBar').addClass('active');
        renderBarChart();
    } else {
        $('#btnFunnel').addClass('active');
        renderFunnelChart();
    }
}

$(document).ready(function() {
    renderBarChart();
    
    const trendCtx = document.getElementById('trendChart').getContext('2d');
    new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_keys($trend_stats)); ?>,
            datasets: [{
                label: 'Tingkat Paripurna (%)',
                data: <?php 
                    $trend_rates = [];
                    foreach ($trend_stats as $stat) {
                        $trend_rates[] = $stat['total'] > 0 ? round(($stat['complete'] / $stat['total']) * 100, 1) : 0;
                    }
                    echo json_encode($trend_rates); 
                ?>,
                borderColor: '#0dcaf0',
                backgroundColor: 'rgba(13, 202, 240, 0.2)',
                borderWidth: 2,
                fill: true,
                tension: 0.3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { min: 0, max: 100 }
            }
        }
    });

    const doughnutCtx = document.getElementById('doughnutChart').getContext('2d');
    new Chart(doughnutCtx, {
        type: 'doughnut',
        data: {
            labels: ['Rantai Penuh (Komplit)', 'Bocor (Incomplete Journey)'],
            datasets: [{
                data: [<?php echo $metrics['complete_journey']; ?>, <?php echo $metrics['incomplete_journey']; ?>],
                backgroundColor: ['#198754', '#dc3545'],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            plugins: { legend: { position: 'bottom' } }
        }
    });
    
    $('#antrolTable').DataTable({
        dom: '<"row mb-3"<"col-md-6"B><"col-md-6"f>>rt<"row"<"col-md-6"i><"col-md-6"p>>',
        buttons: [{
            extend: 'excelHtml5',
            text: '<i class="fas fa-file-excel me-1"></i> Export Eksekutif',
            className: 'btn btn-success btn-sm',
            title: 'Matriks Data Kepatuhan Task ID BPJS (<?php echo $tgl1; ?> sd <?php echo $tgl2; ?>)'
        }],
        pageLength: 25,
        language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/id.json' }
    });

    $('#rawTable').DataTable({
        dom: '<"row mb-3"<"col-md-6"B><"col-md-6"f>>rt<"row"<"col-md-6"i><"col-md-6"p>>',
        buttons: [{
            extend: 'excelHtml5',
            text: '<i class="fas fa-file-excel me-1"></i> Export Data Analisis Bot',
            className: 'btn btn-primary btn-sm',
            title: 'Raw Data Timestamp ERM vs BPJS Bot Analytics (<?php echo $tgl1; ?> sd <?php echo $tgl2; ?>)'
        }],
        pageLength: 25,
        language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/id.json' }
    });
});
</script>

<?php include 'includes/footer.php'; ?>
