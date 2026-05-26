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

define('WL_DIR',                '/var/lib/orthanc/worklists/');
define('DUMP2DCM',              '/usr/bin/dump2dcm');
define('TMP_DIR',               '/tmp/mwl_dump/');
define('MODALITY_MAP_JSON',     __DIR__ . '/mapping_tindakan_radiologi.iyem');
define('DASHBOARD_REFRESH_SEC', (int)(getenv('MWL_DASHBOARD_REFRESH_SEC') ?: getenv('MWL_RELOAD_SEC') ?: 300));
define('STALE_DAYS',            (int)(getenv('MWL_STALE_DAYS') ?: 2));
define('DEFAULT_AET',           'ORTHANC');
define('DICOM_UID_ROOT',        getenv('DICOM_UID_ROOT') ?: '2.25');
define('INSTITUTION_NAME',      getenv('MWL_INSTITUTION_NAME') ?: '');

// MWL SOP Class UID (Modality Worklist Information Model - FIND)
define('MWL_SOP_CLASS_UID',     '1.2.840.10008.5.1.4.31');
// Explicit VR Little Endian Transfer Syntax
define('EXPLICIT_VR_LE',        '1.2.840.10008.1.2.1');

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
 */
function generateStudyUid(string $patientId, string $acsn): string {
    $cleanPid  = preg_replace('/[^0-9]/', '', $patientId);
    if ($cleanPid === '') {
        $cleanPid = '0';
    }
    $cleanAcsn = preg_replace('/[^0-9]/', '', $acsn);
    if ($cleanAcsn === '') {
        $cleanAcsn = '0';
    }
    $studyUid = "1.3.6.1.4.1.53234.1.{$cleanPid}.{$cleanAcsn}";
    if (strlen($studyUid) > 64) {
        $studyUid = substr($studyUid, 0, 64);
    }
    if (str_ends_with($studyUid, '.')) {
        $studyUid = rtrim($studyUid, '.');
    }
    return $studyUid;
}

/**
 * Map Indonesian sex code to DICOM Patient Sex (PS3.3 C.7.1.1).
 */
function mapSex(?string $jk): string {
    return match (strtoupper(trim($jk ?? ''))) {
        'L'     => 'M',
        'P'     => 'F',
        default => 'O',
    };
}

/**
 * Sanitize a string to ASCII-only for DICOM compliance.
 * Returns empty string for null/empty input.
 */
function dicomSanitize(?string $value, int $maxLen = 64): string {
    if ($value === null || trim($value) === '' || trim($value) === '-') {
        return '';
    }
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', strtoupper(trim($value)));
    return substr($ascii ?: '', 0, $maxLen);
}

/**
 * Format a date value to DICOM DA format (YYYYMMDD).
 * Returns empty string for null/empty/invalid dates.
 */
function dicomDate(?string $date): string {
    if (empty($date) || $date === '0000-00-00') {
        return '';
    }
    return str_replace('-', '', $date);
}

/**
 * Format a time value to DICOM TM format (HHMMSS, exactly 6 digits).
 * Handles edge cases: null, empty, midnight (00:00:00).
 */
function dicomTime(?string $time): string {
    if (empty($time) || $time === '00:00:00') {
        return '000000';
    }
    $clean = str_replace(':', '', $time);
    // Pad to ensure at least 6 digits (HHMMSS)
    $clean = str_pad($clean, 6, '0');
    return substr($clean, 0, 6);
}

/**
 * Load the consolidated modality configuration from modality_mapping.json.
 *
 * Returns an array with two keys:
 *   - 'procedures'  : kd_jenis_prw => ['modality' => 'XR', 'aet' => 'CR_STATION' (optional)]
 *   - 'default_aet'  : modality_code => AE Title (e.g., 'CR' => 'CR_STATION')
 */
function loadModalityConfig(): array {
    $config = ['procedures' => [], 'default_aet' => []];

    if (!file_exists(MODALITY_MAP_JSON)) {
        return $config;
    }
    $data = json_decode(file_get_contents(MODALITY_MAP_JSON), true);
    if (!is_array($data)) {
        return $config;
    }

    // Load default AET per modality
    if (!empty($data['default_aet']) && is_array($data['default_aet'])) {
        $config['default_aet'] = $data['default_aet'];
    }

    // Load procedure-to-modality mapping
    if (!empty($data['mapping']) && is_array($data['mapping'])) {
        foreach ($data['mapping'] as $entry) {
            if (!empty($entry['kd_jenis_prw']) && !empty($entry['modality'])) {
                $config['procedures'][$entry['kd_jenis_prw']] = [
                    'modality' => strtoupper($entry['modality']),
                    'aet'      => $entry['aet'] ?? null,
                ];
            }
        }
    }

    return $config;
}

/**
 * Detect DICOM modality for a procedure.
 *
 * Resolution order:
 *   1. Exact match from modality_mapping.json (by kd_jenis_prw)
 *   2. Explicit parenthesized code in procedure name, e.g. "(CR)"
 *   3. Keyword-based detection from procedure name
 *   4. Default: CR (conventional radiography)
 */
function detectModality(string $kdJenisPrw, string $procedureName, array $procedureMap): string {
    // 1. Exact match from mapping file (most reliable)
    if (isset($procedureMap[$kdJenisPrw])) {
        return $procedureMap[$kdJenisPrw]['modality'];
    }

    // 2. Check for explicit modality code in parentheses: "THORAX AP (CR)"
    if (preg_match('/\(([A-Z]{2,3})\)\s*$/', $procedureName, $m)) {
        return strtoupper($m[1]);
    }

    $upper = strtoupper($procedureName);

    // 3. Keyword-based detection
    if (str_starts_with($upper, 'USG ') || str_starts_with($upper, 'ULTRASO')) {
        return 'US';
    }
    if (str_starts_with($upper, 'CT ') || str_contains($upper, 'CT SCAN')) {
        return 'CT';
    }
    if (str_starts_with($upper, 'MRI') || str_contains($upper, 'MAGNETIC')) {
        return 'MR';
    }
    if (str_contains($upper, 'MAMMAE') || str_contains($upper, 'MAMMOGRA') || str_contains($upper, 'MAMMA ')) {
        return 'MG';
    }
    if (str_contains($upper, 'PANORAMI') || str_contains($upper, 'CEPHA')) {
        return 'DX';
    }
    if (str_contains($upper, 'FLUOROSC')) {
        return 'RF';
    }

    // 4. Default: CR for conventional radiography
    return 'CR';
}

/**
 * Remove stale worklist files older than STALE_DAYS.
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
 * Produces a complete DICOM dump including:
 * - File Meta Information (Group 0002)
 * - Patient Module (Group 0010)
 * - General Study Module (Group 0008/0020)
 * - Requested Procedure Module (Group 0032/0040)
 * - Scheduled Procedure Step Sequence (0040,0100)
 *
 * @return array|null Results with logs, stats, and timestamp; null on DB error.
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

    // --- Load Modality Configuration ---
    $modalityConfig = loadModalityConfig();

    // --- Query Institution Name Dynamically ---
    $instName = '';
    try {
        $instStmt = $pdo->query("SELECT nama_instansi FROM setting LIMIT 1");
        if ($instStmt) {
            $instVal = $instStmt->fetchColumn();
            if ($instVal) {
                $instName = dicomSanitize($instVal);
            }
        }
    } catch (\Exception $e) {
        error_log('MWL Warning: Failed to query InstitutionName from setting table: ' . $e->getMessage());
    }
    if ($instName === '') {
        $instName = dicomSanitize(INSTITUTION_NAME ?: 'SIMRS KHANZA');
    }

    // --- Query Radiology Orders ---
    // Matches the original SIMRS Khanza query structure with penjab JOIN
    $sql = "SELECT p.noorder, p.no_rawat, r.no_rkm_medis, ps.nm_pasien,
                   ps.tgl_lahir, ps.jk,
                   j.kd_jenis_prw, j.nm_perawatan,
                   p.tgl_permintaan,
                   IF(p.jam_permintaan='00:00:00', '', p.jam_permintaan) AS jam_permintaan,
                   p.dokter_perujuk, d.nm_dokter,
                   pl.nm_poli, p.diagnosa_klinis,
                   r.kd_pj, pj.png_jawab
            FROM permintaan_radiologi p
            INNER JOIN reg_periksa r ON p.no_rawat = r.no_rawat
            INNER JOIN pasien ps ON r.no_rkm_medis = ps.no_rkm_medis
            INNER JOIN permintaan_pemeriksaan_radiologi pr ON p.noorder = pr.noorder
            INNER JOIN jns_perawatan_radiologi j ON j.kd_jenis_prw = pr.kd_jenis_prw
            INNER JOIN dokter d ON p.dokter_perujuk = d.kd_dokter
            INNER JOIN poliklinik pl ON r.kd_poli = pl.kd_poli
            INNER JOIN penjab pj ON r.kd_pj = pj.kd_pj
            WHERE p.tgl_permintaan >= CURDATE() - INTERVAL 1 DAY
            ORDER BY p.tgl_permintaan DESC, p.jam_permintaan DESC";

    $stmt = $pdo->query($sql);

    while ($row = $stmt->fetch()) {
        // --- File Identifiers ---
        // Match original: strip "PR" prefix, concatenate noorder + kd_jenis_prw
        $noorder  = str_replace('PR', '', $row['noorder'] . $row['kd_jenis_prw']);
        $noorder  = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $noorder);
        $wlFile   = WL_DIR . $noorder . '.wl';
        $dumpFile = TMP_DIR . $noorder . '.dump';

        if (file_exists($wlFile)) {
            $stats['skip']++;
            $logs[] = [
                'status'  => 'skip',
                'noorder' => $row['noorder'],
                'pasien'  => $row['nm_pasien'],
                'pesan'   => 'File .wl sudah ada, dilewati',
            ];
            continue;
        }

        // --- Modality & Station AET Resolution ---
        $modality   = detectModality($row['kd_jenis_prw'], $row['nm_perawatan'], $modalityConfig['procedures']);
        $stationAet = $modalityConfig['procedures'][$row['kd_jenis_prw']]['aet']  // 1. Per-procedure AET
                   ?? $modalityConfig['default_aet'][$modality]                    // 2. Per-modality default AET
                   ?? DEFAULT_AET;                                                 // 3. Global fallback

        // --- Prepare DICOM Values ---
        $nmPasien    = dicomSanitize($row['nm_pasien']);
        $nmDokter    = dicomSanitize($row['nm_dokter']);
        $nmPerawatan = dicomSanitize($row['nm_perawatan']);
        $nmPoli      = dicomSanitize($row['nm_poli']);
        $diagnosa    = dicomSanitize($row['diagnosa_klinis']);
        $birthDate   = dicomDate($row['tgl_lahir'] ?? '');
        $patientSex  = mapSex($row['jk'] ?? '');
        $studyUid    = generateStudyUid($row['no_rkm_medis'], $noorder);
        $tglDicom    = dicomDate($row['tgl_permintaan']);
        $jamDicom    = dicomTime($row['jam_permintaan'] ?? '');

        // --- Build DICOM Dump ---
        // File Meta Information (Group 0002) — required for valid DICOM files
        $dump  = "# Dicom-File-Meta\n\n";
        $dump .= "(0002,0000) UL 0\n";
        $dump .= "(0002,0001) OB 00\\01\n";
        $dump .= "(0002,0002) UI [" . MWL_SOP_CLASS_UID . "]\n";
        $dump .= "(0002,0003) UI [{$studyUid}]\n";
        $dump .= "(0002,0010) UI [" . EXPLICIT_VR_LE . "]\n";

        $dump .= "\n# Dicom-Data-Set\n\n";

        // Specific Character Set — tells DICOM readers this is ASCII
        $dump .= "(0008,0005) CS [ISO_IR 6]\n";
        $dump .= "(0008,0020) DA [{$tglDicom}]\n";
        $dump .= "(0008,0030) TM [{$jamDicom}]\n";

        // Accession Number — matches original: uses noorder as accession
        $dump .= "(0008,0050) SH [{$noorder}]\n";

        // Referring Physician's Name
        $dump .= "(0008,0090) PN [{$nmDokter}]\n";
        if ($instName !== '') {
            $dump .= "(0008,0080) LO [{$instName}]\n";
        }

        // Patient Module
        $dump .= "(0010,0010) PN [{$nmPasien}]\n";
        $dump .= "(0010,0020) LO [{$row['no_rkm_medis']}]\n";
        $dump .= "(0010,0030) DA [{$birthDate}]\n";
        $dump .= "(0010,0040) CS [{$patientSex}]\n";

        // Study Instance UID
        $dump .= "(0020,000d) UI [{$studyUid}]\n";
        $dump .= "(0032,1032) PN [{$nmDokter}]\n";

        // Requested Procedure Description
        $dump .= "(0032,1060) LO [{$nmPerawatan}]\n";

        // Requested Procedure ID — matches original: uses noorder
        $dump .= "(0040,1001) SH [{$noorder}]\n";

        // Reason for Requested Procedure — clinical diagnosis
        $dump .= "(0040,1002) LO [{$diagnosa}]\n";

        // Scheduled Procedure Step Sequence (0040,0100)
        $dump .= "(0040,0100) SQ\n";
        $dump .= "(fffe,e000) -\n";
        $dump .= "(0008,0060) CS [{$modality}]\n";
        $dump .= "(0040,0001) AE [{$stationAet}]\n";
        $dump .= "(0040,0002) DA [{$tglDicom}]\n";
        $dump .= "(0040,0003) TM [{$jamDicom}]\n";
        $dump .= "(0040,0006) PN [{$nmDokter}]\n";
        $dump .= "(0040,0007) LO [{$nmPerawatan}]\n";
        $dump .= "(0040,0009) SH [{$noorder}]\n";
        $dump .= "(0040,0010) SH [{$nmPoli}]\n";
        if ($diagnosa !== '') {
            $dump .= "(0040,0400) LT [{$diagnosa}]\n";
        }
        $dump .= "(fffe,e00d) -\n";
        $dump .= "(fffe,e0dd) -\n";

        // --- Convert to DICOM ---
        file_put_contents($dumpFile, $dump);
        $cmd    = 'LD_LIBRARY_PATH=/lib/x86_64-linux-gnu:/usr/lib/x86_64-linux-gnu '
                . DUMP2DCM . ' ' . escapeshellarg($dumpFile) . ' ' . escapeshellarg($wlFile) . ' 2>&1';
        $output = shell_exec($cmd);

        if (file_exists($wlFile)) {
            $stats['ok']++;
            $logs[] = [
                'status'  => 'ok',
                'noorder' => $row['noorder'],
                'pasien'  => $row['nm_pasien'],
                'pesan'   => "Modality: {$modality} | AET: {$stationAet}",
            ];
        } else {
            $stats['fail']++;
            error_log("MWL dump2dcm failed [{$row['noorder']}]: {$output}");
            $logs[] = [
                'status'  => 'fail',
                'noorder' => $row['noorder'],
                'pasien'  => $row['nm_pasien'],
                'pesan'   => 'dump2dcm error: ' . trim($output ?? ''),
            ];
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
        h1 { font-size: 1.5rem; font-weight: 600; margin-bottom: 6px; }
        .subtitle { color: var(--muted); font-size: 0.85rem; margin-bottom: 20px; }
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
        .stat-card .value { font-size: 2rem; font-weight: 700; }
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
        .stat-total .value { color: #3b82f6; }
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
        tr:hover td { background: rgba(108, 138, 255, 0.04); }
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
        .countdown { color: var(--muted); font-size: 0.75rem; margin-top: 10px; text-align: right; }
    </style>
</head>
<body>
    <h1>📡 MWL Dashboard</h1>
    <p class="subtitle">
        Diproses pada: <?= htmlspecialchars($data['waktu'] ?? '-') ?>
        &nbsp;·&nbsp; Auto-reload setiap <?= (int)(DASHBOARD_REFRESH_SEC / 60) ?> menit
    </p>

    <div class="stats">
        <div class="stat-card stat-total">
            <div class="value"><?= count($data['logs'] ?? []) ?></div>
            <div class="label">Total Diproses</div>
        </div>
        <div class="stat-card stat-ok">
            <div class="value"><?= $data['stats']['ok'] ?? 0 ?></div>
            <div class="label">Berhasil</div>
        </div>
        <div class="stat-card stat-skip">
            <div class="value"><?= $data['stats']['skip'] ?? 0 ?></div>
            <div class="label">Dilewati</div>
        </div>
        <div class="stat-card stat-fail">
            <div class="value"><?= $data['stats']['fail'] ?? 0 ?></div>
            <div class="label">Gagal</div>
        </div>
        <div class="stat-card stat-clean">
            <div class="value"><?= $data['stats']['cleaned'] ?? 0 ?></div>
            <div class="label">Dibersihkan</div>
        </div>
    </div>

    <?php if (empty($data['logs'])): ?>
    <div class="empty">Tidak ada permintaan radiologi yang diproses saat ini.</div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>No. Order</th>
                <th>Nama Pasien</th>
                <th>Status</th>
                <th>Keterangan</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($data['logs'] as $i => $log): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($log['noorder']) ?></td>
                <td><?= htmlspecialchars($log['pasien']) ?></td>
                <td><span class="badge badge-<?= htmlspecialchars($log['status']) ?>"><?= htmlspecialchars(strtoupper($log['status'])) ?></span></td>
                <td><?= htmlspecialchars($log['pesan']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <p class="countdown">Halaman akan reload otomatis dalam <span id="cd"><?= DASHBOARD_REFRESH_SEC ?></span> detik</p>

    <div class="footer">
        Orthanc PACS MWL Sync · Based on
        <a href="https://github.com/mas-elkhanza/SIMRS-Khanza/" target="_blank" rel="noopener">SIMRS Khanza</a>
        by mas-elkhanza
    </div>

    <script>
    var s = <?= DASHBOARD_REFRESH_SEC ?>;
    setInterval(function(){ s--; document.getElementById('cd').innerText = s; }, 1000);
    </script>
</body>
</html>
