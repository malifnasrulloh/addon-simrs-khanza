<?php
/**
 * MWL (Modality Worklist) Generator — SIMRS Khanza → Orthanc PACS
 *
 * Generates DICOM MWL files from radiology orders in the SIMRS Khanza database,
 * converts them via DCMTK dump2dcm, and writes to the Orthanc worklist directory.
 *
 * When accessed via HTTP, serves a monitoring dashboard with Basic Auth.
 *
 * Based on SIMRS Khanza by mas-elkhanza
 * @see https://github.com/mas-elkhanza/SIMRS-Khanza/
 *
 * @author  SIMRS Khanza Community
 * @license MIT
 */

// ============================================================
// Configuration
// ============================================================

$dbHost = getenv('SIMRS_DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('SIMRS_DB_PORT') ?: '3306';
$dbUser = getenv('SIMRS_DB_USER');
$dbPass = getenv('SIMRS_DB_PASS');
$dbName = getenv('SIMRS_DB_NAME');

if ($tz = getenv('TZ')) {
    date_default_timezone_set($tz);
}

define('WL_DIR',               '/var/lib/orthanc/worklists/');
define('DUMP2DCM',             '/usr/bin/dump2dcm');
define('TMP_DIR',              '/tmp/mwl_dump/');
define('AET_JSON',             __DIR__ . '/modality_aet.json');
define('DASHBOARD_REFRESH_SEC', (int)(getenv('MWL_DASHBOARD_REFRESH_SEC') ?: getenv('MWL_RELOAD_SEC') ?: 300));
define('STALE_DAYS',           (int)(getenv('MWL_STALE_DAYS') ?: 2));
define('DEFAULT_AET',          'ORTHANC');
define('DICOM_UID_ROOT',       getenv('DICOM_UID_ROOT') ?: '2.25');

// ============================================================
// Authentication (Web Mode Only)
// ============================================================

if (php_sapi_name() !== 'cli') {
    $authUser = getenv('MWL_WEB_USER') ?: 'admin';
    $authPass = getenv('MWL_WEB_PASS') ?: 'changeme';

    if (!isset($_SERVER['PHP_AUTH_USER']) ||
        !hash_equals($authUser, $_SERVER['PHP_AUTH_USER']) ||
        !hash_equals($authPass, $_SERVER['PHP_AUTH_PW'] ?? '')) {

        header('WWW-Authenticate: Basic realm="MWL Dashboard"');
        header('HTTP/1.0 401 Unauthorized');
        die('Unauthorized');
    }
}

// ============================================================
// Helpers
// ============================================================

if (!is_dir(TMP_DIR)) {
    mkdir(TMP_DIR, 0700, true);
}

/**
 * Generate a deterministic, standards-compliant DICOM UID.
 *
 * Uses the configurable OID root (default: 2.25 per DICOM PS3.5 Annex B)
 * combined with date and a CRC32 hash to ensure uniqueness while remaining
 * idempotent for the same input seed.
 *
 * @param string $seed Unique identifier (e.g., order number + procedure code)
 * @param string $date Date string (YYYY-MM-DD)
 * @return string DICOM-compliant UID (max 64 characters)
 */
function generateStudyUid(string $seed, string $date): string {
    $root     = DICOM_UID_ROOT;
    $datePart = str_replace('-', '', $date);
    $hashPart = sprintf('%010u', abs(crc32($seed)));
    $uid      = "{$root}.{$datePart}.{$hashPart}";

    // DICOM UIDs must not exceed 64 characters
    return substr($uid, 0, 64);
}

/**
 * Map Indonesian sex code to DICOM Patient Sex (PS3.3 C.7.1.1).
 */
function mapSex(string $jk): string {
    return match (strtoupper(trim($jk))) {
        'L'     => 'M',
        'P'     => 'F',
        default => 'O',
    };
}

/**
 * Sanitize a string to ASCII-only for DICOM compliance.
 */
function dicomSanitize(string $value, int $maxLen = 64): string {
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', strtoupper($value));
    return substr($ascii, 0, $maxLen);
}

/**
 * Remove stale worklist files older than STALE_DAYS.
 *
 * @return int Number of files removed
 */
function cleanStaleWorklists(): int {
    $count     = 0;
    $threshold = time() - (STALE_DAYS * 86400);

    foreach (glob(WL_DIR . '*.wl') as $file) {
        if (filemtime($file) < $threshold) {
            if (unlink($file)) {
                $count++;
            } else {
                error_log("MWL: Failed to remove stale file: {$file}");
            }
        }
    }

    return $count;
}

// ============================================================
// Core MWL Generation
// ============================================================

/**
 * Query SIMRS Khanza for radiology orders and generate DICOM MWL files.
 *
 * @return array|null Results array with logs, stats, and timestamp; null on DB error.
 */
function generate_mwl(string $dbHost, string $dbPort, string $dbUser, string $dbPass, string $dbName): ?array {
    // --- Database Connection ---
    try {
        $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (\PDOException $e) {
        error_log('MWL DB Error: ' . $e->getMessage());
        return null;
    }

    $logs  = [];
    $stats = ['ok' => 0, 'skip' => 0, 'fail' => 0, 'cleaned' => 0];

    // --- Stale Cleanup ---
    $stats['cleaned'] = cleanStaleWorklists();

    // --- Load Modality AET Map ---
    $modalityMap = [];
    if (file_exists(AET_JSON)) {
        $modalityMap = json_decode(file_get_contents(AET_JSON), true) ?? [];
    }

    // --- Query Radiology Orders ---
    $sql = "SELECT p.noorder, p.no_rawat, r.no_rkm_medis, ps.nm_pasien,
                   ps.tgl_lahir, ps.jk,
                   j.kd_jenis_prw, j.nm_perawatan,
                   p.tgl_permintaan, p.jam_permintaan,
                   p.dokter_perujuk, d.nm_dokter,
                   pl.nm_poli, p.diagnosa_klinis
            FROM permintaan_radiologi p
            INNER JOIN reg_periksa r ON p.no_rawat = r.no_rawat
            INNER JOIN pasien ps ON r.no_rkm_medis = ps.no_rkm_medis
            INNER JOIN permintaan_pemeriksaan_radiologi pr ON p.noorder = pr.noorder
            INNER JOIN jns_perawatan_radiologi j ON j.kd_jenis_prw = pr.kd_jenis_prw
            INNER JOIN dokter d ON p.dokter_perujuk = d.kd_dokter
            INNER JOIN poliklinik pl ON r.kd_poli = pl.kd_poli
            WHERE p.tgl_permintaan >= CURDATE() - INTERVAL 1 DAY
            ORDER BY p.tgl_permintaan DESC, p.jam_permintaan DESC";

    $stmt = $pdo->query($sql);

    while ($row = $stmt->fetch()) {
        // --- File Identifiers ---
        $fileId   = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $row['noorder'] . '_' . $row['kd_jenis_prw']);
        $wlFile   = WL_DIR . $fileId . '.wl';
        $dumpFile = TMP_DIR . $fileId . '.dump';

        if (file_exists($wlFile)) {
            $stats['skip']++;
            $logs[] = ['status' => 'skip', 'noorder' => $row['noorder'], 'pasien' => $row['nm_pasien'], 'pesan' => 'Existing'];
            continue;
        }

        // --- Modality Detection ---
        $modality = 'OT';
        if (preg_match('/\(([A-Z]{2,3})\)\s*$/', $row['nm_perawatan'], $m)) {
            $modality = strtoupper($m[1]);
        }
        $stationAet = $modalityMap[$modality]['aet'] ?? DEFAULT_AET;

        // --- Prepare DICOM Values ---
        $nmPasien    = dicomSanitize($row['nm_pasien']);
        $nmDokter    = dicomSanitize($row['nm_dokter']);
        $accession   = substr($row['noorder'], 0, 16);
        $studyUid    = generateStudyUid($row['noorder'] . '|' . $row['kd_jenis_prw'], $row['tgl_permintaan']);
        $spsDate     = str_replace('-', '', $row['tgl_permintaan']);
        $spsTime     = str_replace(':', '', $row['jam_permintaan']);
        $birthDate   = !empty($row['tgl_lahir']) ? str_replace('-', '', $row['tgl_lahir']) : '';
        $patientSex  = mapSex($row['jk'] ?? '');
        $procDesc    = dicomSanitize($row['nm_perawatan']);
        $spsId       = substr($fileId, 0, 16);

        // --- Build DICOM Dump (Full MWL-compliant) ---
        $dump  = "";

        // Patient Module
        $dump .= "(0010,0010) PN [{$nmPasien}]\n";
        $dump .= "(0010,0020) LO [{$row['no_rkm_medis']}]\n";
        $dump .= "(0010,0030) DA [{$birthDate}]\n";
        $dump .= "(0010,0040) CS [{$patientSex}]\n";

        // General Study Module
        $dump .= "(0020,000d) UI [{$studyUid}]\n";
        $dump .= "(0008,0050) SH [{$accession}]\n";
        $dump .= "(0008,0090) PN [{$nmDokter}]\n";

        // Requested Procedure Module
        $dump .= "(0032,1060) LO [{$procDesc}]\n";
        $dump .= "(0040,1001) SH [{$accession}]\n";

        // Scheduled Procedure Step Sequence
        $dump .= "(0040,0100) SQ\n";
        $dump .= "(fffe,e000) na\n";
        $dump .= "(0008,0060) CS [{$modality}]\n";
        $dump .= "(0040,0001) AE [{$stationAet}]\n";
        $dump .= "(0040,0002) DA [{$spsDate}]\n";
        $dump .= "(0040,0003) TM [{$spsTime}]\n";
        $dump .= "(0040,0007) LO [{$procDesc}]\n";
        $dump .= "(0040,0009) SH [{$spsId}]\n";
        $dump .= "(fffe,e00d) na\n";
        $dump .= "(fffe,e0dd) na\n";

        // --- Convert to DICOM ---
        file_put_contents($dumpFile, $dump);
        $cmd    = DUMP2DCM . " " . escapeshellarg($dumpFile) . " " . escapeshellarg($wlFile) . " 2>&1";
        $output = shell_exec($cmd);

        if (file_exists($wlFile)) {
            $stats['ok']++;
            $logs[] = ['status' => 'ok', 'noorder' => $row['noorder'], 'pasien' => $row['nm_pasien'], 'pesan' => $modality];
        } else {
            $stats['fail']++;
            error_log("MWL dump2dcm failed [{$row['noorder']}]: {$output}");
            $logs[] = ['status' => 'fail', 'noorder' => $row['noorder'], 'pasien' => $row['nm_pasien'], 'pesan' => 'DCM Error'];
        }

        if (file_exists($dumpFile)) {
            unlink($dumpFile);
        }
    }

    return ['logs' => $logs, 'stats' => $stats, 'waktu' => date('d-m-Y H:i:s')];
}

// ============================================================
// CLI Mode — Daemon Output
// ============================================================

if (php_sapi_name() === 'cli') {
    $res = generate_mwl($dbHost, $dbPort, $dbUser, $dbPass, $dbName);
    if ($res) {
        $s = $res['stats'];
        echo "[{$res['waktu']}] OK:{$s['ok']} SKIP:{$s['skip']} FAIL:{$s['fail']} CLEANED:{$s['cleaned']}\n";
    } else {
        echo "[" . date('d-m-Y H:i:s') . "] ERROR: Database connection failed\n";
    }
    exit();
}

// ============================================================
// Web Mode — Dashboard
// ============================================================

$data = generate_mwl($dbHost, $dbPort, $dbUser, $dbPass, $dbName);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="<?= DASHBOARD_REFRESH_SEC ?>">
    <title>MWL Dashboard — Orthanc PACS</title>
    <style>
        :root {
            --bg: #0f1117;
            --card: #1a1d27;
            --border: #2a2d3a;
            --text: #e4e6ed;
            --muted: #8b8fa3;
            --accent: #6c8aff;
            --ok: #2ecc71;
            --fail: #e74c3c;
            --skip: #f39c12;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text);
            padding: 24px;
            line-height: 1.6;
        }
        h1 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 6px;
        }
        .subtitle {
            color: var(--muted);
            font-size: 0.85rem;
            margin-bottom: 20px;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            text-align: center;
        }
        .stat-card .value {
            font-size: 2rem;
            font-weight: 700;
        }
        .stat-card .label {
            font-size: 0.75rem;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-ok .value { color: var(--ok); }
        .stat-skip .value { color: var(--skip); }
        .stat-fail .value { color: var(--fail); }
        .stat-clean .value { color: var(--accent); }
        table {
            width: 100%;
            border-collapse: collapse;
            background: var(--card);
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid var(--border);
        }
        th {
            text-align: left;
            padding: 12px 16px;
            background: rgba(108, 138, 255, 0.08);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--muted);
            border-bottom: 1px solid var(--border);
        }
        td {
            padding: 10px 16px;
            border-bottom: 1px solid var(--border);
            font-size: 0.9rem;
        }
        tr:last-child td { border-bottom: none; }
        .badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .badge-ok   { background: rgba(46,204,113,0.15); color: var(--ok); }
        .badge-skip { background: rgba(243,156,18,0.15); color: var(--skip); }
        .badge-fail { background: rgba(231,76,60,0.15); color: var(--fail); }
        .footer {
            margin-top: 24px;
            text-align: center;
            font-size: 0.75rem;
            color: var(--muted);
        }
        .footer a { color: var(--accent); text-decoration: none; }
        .footer a:hover { text-decoration: underline; }
        .empty { text-align: center; padding: 40px; color: var(--muted); }
    </style>
</head>
<body>
    <h1>📡 MWL Dashboard</h1>
    <p class="subtitle">
        Last sync: <?= htmlspecialchars($data['waktu'] ?? '-') ?>
        &nbsp;·&nbsp; Auto-refresh: <?= DASHBOARD_REFRESH_SEC ?>s
    </p>

    <div class="stats">
        <div class="stat-card stat-ok">
            <div class="value"><?= $data['stats']['ok'] ?? 0 ?></div>
            <div class="label">Generated</div>
        </div>
        <div class="stat-card stat-skip">
            <div class="value"><?= $data['stats']['skip'] ?? 0 ?></div>
            <div class="label">Skipped</div>
        </div>
        <div class="stat-card stat-fail">
            <div class="value"><?= $data['stats']['fail'] ?? 0 ?></div>
            <div class="label">Failed</div>
        </div>
        <div class="stat-card stat-clean">
            <div class="value"><?= $data['stats']['cleaned'] ?? 0 ?></div>
            <div class="label">Cleaned</div>
        </div>
    </div>

    <?php if (!empty($data['logs'])): ?>
    <table>
        <thead>
            <tr><th>No Order</th><th>Pasien</th><th>Info</th><th>Status</th></tr>
        </thead>
        <tbody>
            <?php foreach ($data['logs'] as $log): ?>
            <tr>
                <td><?= htmlspecialchars($log['noorder']) ?></td>
                <td><?= htmlspecialchars($log['pasien']) ?></td>
                <td><?= htmlspecialchars($log['pesan']) ?></td>
                <td><span class="badge badge-<?= htmlspecialchars($log['status']) ?>"><?= htmlspecialchars(strtoupper($log['status'])) ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div class="empty">No worklist entries for today.</div>
    <?php endif; ?>

    <div class="footer">
        Orthanc PACS MWL Sync · Based on
        <a href="https://github.com/mas-elkhanza/SIMRS-Khanza/" target="_blank" rel="noopener">SIMRS Khanza</a>
        by mas-elkhanza
    </div>
</body>
</html>
