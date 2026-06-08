<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

define('BASE_DIR', __DIR__);
require_once BASE_DIR . '/lib/Logger.php';
require_once BASE_DIR . '/lib/satusehat/Config.php';
require_once BASE_DIR . '/lib/satusehat/SatuSehatClient.php';
require_once BASE_DIR . '/lib/JWT.php';

function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

try {
    $config = new SatuSehatConfig(BASE_DIR . '/.env');
    $log = new Logger($config->logDir, 'satusehat_portal', $config->logLevel, false);
    $pdo = new PDO(
        "mysql:host={$config->dbHost};port={$config->dbPort};dbname={$config->dbName}",
        $config->dbUser,
        $config->dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $client = new SatuSehatClient($config, $log);
    $jwtSecret = $config->jwtSecret;

} catch (Exception $e) {
    error_log("SatuSehat Portal Initialization failed: " . $e->getMessage());
    jsonResponse(['error' => 'Initialization failed', 'message' => 'An internal server error occurred.'], 500);
}

// RATE LIMITER: 60 requests per minute per IP
$ip = $_SERVER['REMOTE_ADDR'];
$cacheDir = BASE_DIR . '/cache';
if (!is_dir($cacheDir)) mkdir($cacheDir, 0777, true);

$minute = date('YmdHi');
$rateFile = $cacheDir . "/rate_" . md5($ip) . "_" . $minute;
$hits = file_exists($rateFile) ? (int)file_get_contents($rateFile) : 0;
if ($hits > 60) {
    jsonResponse(['success' => false, 'message' => 'Too Many Requests. Rate limit exceeded.'], 429);
}
file_put_contents($rateFile, $hits + 1);

// Cleanup old rate files occasionally (1% chance)
if (mt_rand(1, 100) === 1) {
    foreach (glob($cacheDir . "/rate_*") as $file) {
        if (filemtime($file) < time() - 120) @unlink($file);
    }
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Handle Auth
if ($action === 'login' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';
    
    // Check 'user' table first
    $stmt = $pdo->prepare("SELECT AES_DECRYPT(id_user, 'nur') as id_user, AES_DECRYPT(password, 'windi') as pwd FROM user WHERE AES_DECRYPT(id_user, 'nur') = :username");
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $role = 'user';

    // If not found, check 'admin' table
    if (!$user) {
        $stmtAdmin = $pdo->prepare("SELECT AES_DECRYPT(usere, 'nur') as id_user, AES_DECRYPT(passworde, 'windi') as pwd FROM admin WHERE AES_DECRYPT(usere, 'nur') = :username");
        $stmtAdmin->execute(['username' => $username]);
        $user = $stmtAdmin->fetch(PDO::FETCH_ASSOC);
        $role = 'admin';
    }

    if ($user && $user['pwd'] === $password) {
        $payload = [
            'iss' => 'simrs-khanza',
            'iat' => time(),
            'exp' => time() + (8 * 3600), // 8 hours expiration
            'user' => $username,
            'role' => $role
        ];
        $token = JWT::encode($payload, $jwtSecret);
        
        jsonResponse([
            'success' => true,
            'token' => $token,
            'user' => [
                'username' => $username,
                'role' => $role
            ]
        ]);
    }
    jsonResponse(['success' => false, 'message' => 'Invalid credentials'], 401);
}

// Ensure Auth Token for other routes
$headers = apache_request_headers();
$authHeader = $headers['Authorization'] ?? '';
$bearerToken = str_replace('Bearer ', '', $authHeader);

$userRole = 'user';
if ($action !== 'login') {
    if (empty($bearerToken)) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized. Missing Token.'], 401);
    }
    $decoded = JWT::decode($bearerToken, $jwtSecret);
    if (!$decoded) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized. Invalid or Expired Token.'], 401);
    }
    $userRole = $decoded['role'] ?? 'user';
}

if ($action === 'searchLocal' && $method === 'GET') {
    $no_rm = $_GET['no_rm'] ?? '';
    $nik = $_GET['nik'] ?? '';
    
    if (!$no_rm && !$nik) jsonResponse(['error' => 'no_rm or nik required'], 400);

    if ($no_rm) {
        $stmt = $pdo->prepare("SELECT p.no_rkm_medis, p.nm_pasien, p.no_ktp as nik, p.nm_ibu, p.tgl_lahir, 
                                      p.jk, p.alamat, p.no_tlp, p.stts_nikah, i.ihspasien 
                               FROM pasien p 
                               LEFT JOIN satu_sehat_ihs_patient i ON p.no_ktp = i.nikpasien 
                               WHERE p.no_rkm_medis = :rm LIMIT 1");
        $stmt->execute(['rm' => $no_rm]);
    } else {
        $stmt = $pdo->prepare("SELECT p.no_rkm_medis, p.nm_pasien, p.no_ktp as nik, p.nm_ibu, p.tgl_lahir, 
                                      p.jk, p.alamat, p.no_tlp, p.stts_nikah, i.ihspasien 
                               FROM pasien p 
                               LEFT JOIN satu_sehat_ihs_patient i ON p.no_ktp = i.nikpasien 
                               WHERE p.no_ktp = :nik LIMIT 1");
        $stmt->execute(['nik' => $nik]);
    }
    
    $pasien = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($pasien) {
        jsonResponse(['success' => true, 'data' => $pasien]);
    }
    jsonResponse(['success' => false, 'message' => 'Patient not found in local DB'], 404);
}

if ($action === 'searchSatuSehat' && $method === 'GET') {
    $nik = $_GET['nik'] ?? '';
    $nik_ibu = $_GET['nik_ibu'] ?? '';
    $birthdate = $_GET['birthdate'] ?? '';

    if ($nik) {
        $endpoint = "/Patient?identifier=https://fhir.kemkes.go.id/id/nik|{$nik}";
    } else if ($nik_ibu && $birthdate) {
        $endpoint = "/Patient?identifier=https://fhir.kemkes.go.id/id/nik-ibu|{$nik_ibu}&birthdate={$birthdate}";
    } else {
        jsonResponse(['error' => 'nik or (nik_ibu + birthdate) required'], 400);
    }

    try {
        $res = $client->get($endpoint);
        
        if ($res['success'] && !empty($res['data']['entry'])) {
            $resource = $res['data']['entry'][0]['resource'];
            $ihsNumber = $resource['id'];
            $nikFound = '';
            foreach ($resource['identifier'] as $id) {
                if ($id['system'] === 'https://fhir.kemkes.go.id/id/nik') {
                    $nikFound = $id['value'];
                }
            }
            
            if (empty($nikFound) || strpos($nikFound, '#') !== false) {
                $nikFound = $nik;
            }

            if ($nikFound) {
                try {
                    $stmt = $pdo->prepare("REPLACE INTO satu_sehat_ihs_patient (nikpasien, ihspasien) VALUES (:nik, :ihs)");
                    $stmt->execute(['nik' => $nikFound, 'ihs' => $ihsNumber]);
                } catch (PDOException $dbEx) {
                    if (isset($log)) {
                        $log->warning("Failed to save local IHS mapping for NIK {$nikFound}: " . $dbEx->getMessage());
                    }
                }
            }
        }
        
        if ($res['success']) {
            jsonResponse(['success' => true, 'data' => $res['data']]);
        } else {
            jsonResponse(['success' => false, 'message' => $res['message'], 'details' => $res['data']], $res['code']);
        }
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

if ($action === 'createPatient' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input)) {
        jsonResponse(['success' => false, 'message' => 'Empty payload'], 400);
    }

    try {
        $res = $client->post("/Patient", $input);
        
        if ($res['success'] && isset($res['data']['id'])) {
            $ihsNumber = $res['data']['id'];
            $nikFound = '';
            if (isset($res['data']['identifier'])) {
                foreach ($res['data']['identifier'] as $id) {
                    if ($id['system'] === 'https://fhir.kemkes.go.id/id/nik') {
                        $nikFound = $id['value'];
                    }
                }
            }
            if (empty($nikFound) || strpos($nikFound, '#') !== false) {
                if (isset($input['identifier'])) {
                    foreach ($input['identifier'] as $id) {
                        if ($id['system'] === 'https://fhir.kemkes.go.id/id/nik') {
                            $nikFound = $id['value'];
                        }
                    }
                }
            }

            if ($nikFound) {
                try {
                    $stmt = $pdo->prepare("REPLACE INTO satu_sehat_ihs_patient (nikpasien, ihspasien) VALUES (:nik, :ihs)");
                    $stmt->execute(['nik' => $nikFound, 'ihs' => $ihsNumber]);
                } catch (PDOException $dbEx) {
                    if (isset($log)) {
                        $log->warning("Failed to save local IHS mapping for NIK {$nikFound}: " . $dbEx->getMessage());
                    }
                }
            }
        }
        
        if ($res['success']) {
            jsonResponse(['success' => true, 'data' => $res['data']]);
        } else {
            jsonResponse(['success' => false, 'message' => $res['message'], 'details' => $res['data']], $res['code']);
        }
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

if ($action === 'getSyncStats' && $method === 'GET') {
    if ($userRole !== 'admin') {
        jsonResponse(['success' => false, 'message' => 'Forbidden. Admin privileges required.'], 403);
    }

    $resource = $_GET['resource'] ?? 'patient';
    $noRawat = $_GET['no_rawat'] ?? null;

    // Resolve dates
    $dateFrom = $_GET['dateFrom'] ?? null;
    $dateTo = $_GET['dateTo'] ?? null;
    if (!$dateFrom || !$dateTo) {
        if ($config->lookbackDays > 0) {
            $dateTo = date('Y-m-d', strtotime('-1 day'));
            $dateFrom = date('Y-m-d', strtotime('-' . $config->lookbackDays . ' days', strtotime(date('Y-m-d'))));
        } else {
            $dateFrom = $config->dateFrom;
            $dateTo = $config->dateTo;
        }
    }

    try {
        $stats = [];
        $params = $noRawat ? ['nr' => $noRawat] : ['df' => $dateFrom, 'dt' => $dateTo];
        $timeFilter = $noRawat ? "rp.no_rawat = :nr" : "rp.tgl_registrasi BETWEEN :df AND :dt";
        
        switch (strtolower($resource)) {
            case 'patient':
                if ($noRawat) {
                    $stmt = $pdo->prepare("
                        SELECT p.no_ktp, p.nm_pasien, ssp.ihspasien 
                        FROM reg_periksa rp
                        INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                        LEFT JOIN satu_sehat_ihs_patient ssp ON ssp.nikpasien = p.no_ktp
                        WHERE rp.no_rawat = :nr
                    ");
                    $stmt->execute(['nr' => $noRawat]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $stats = [
                        'total' => $row ? 1 : 0,
                        'synced' => ($row && !empty($row['ihspasien'])) ? 1 : 0,
                        'pending' => ($row && empty($row['ihspasien'])) ? 1 : 0,
                        'details' => $row
                    ];
                } else {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pasien WHERE no_ktp REGEXP '^[0-9]{16}$'");
                    $stmt->execute();
                    $total = (int)$stmt->fetchColumn();

                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM satu_sehat_ihs_patient WHERE nikpasien REGEXP '^[0-9]{16}$'");
                    $stmt->execute();
                    $mapped = (int)$stmt->fetchColumn();

                    $stats = [
                        'total' => $total,
                        'synced' => $mapped,
                        'pending' => max(0, $total - $mapped)
                    ];
                }
                break;

            case 'encounter':
                $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM reg_periksa rp WHERE {$timeFilter}");
                $stmtTotal->execute($params);
                $total = (int)$stmtTotal->fetchColumn();

                $stmtUnpaid = $pdo->prepare("SELECT COUNT(*) FROM reg_periksa rp WHERE {$timeFilter} AND rp.status_bayar = 'Belum Bayar'");
                $stmtUnpaid->execute($params);
                $unpaid = (int)$stmtUnpaid->fetchColumn();

                $stmtUnmapped = $pdo->prepare("
                    SELECT COUNT(*) FROM reg_periksa rp
                    INNER JOIN poliklinik pol ON rp.kd_poli = pol.kd_poli
                    LEFT JOIN satu_sehat_mapping_lokasi_ralan smlr ON smlr.kd_poli = pol.kd_poli
                    WHERE {$timeFilter} AND rp.status_bayar = 'Sudah Bayar' AND smlr.id_lokasi_satusehat IS NULL
                ");
                $stmtUnmapped->execute($params);
                $unmapped = (int)$stmtUnmapped->fetchColumn();

                $stmtSynced = $pdo->prepare("
                    SELECT COUNT(*) FROM reg_periksa rp
                    INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    WHERE {$timeFilter}
                ");
                $stmtSynced->execute($params);
                $synced = (int)$stmtSynced->fetchColumn();

                $stats = [
                    'total' => $total,
                    'unpaid' => $unpaid,
                    'unmapped' => $unmapped,
                    'synced' => $synced,
                    'pending' => max(0, $total - $unpaid - $unmapped - $synced)
                ];
                break;

            case 'episodeofcare':
            case 'episode_of_care':
                $stmtTotal = $pdo->prepare("
                    SELECT COUNT(*) FROM diagnosa_pasien dp 
                    INNER JOIN reg_periksa rp ON dp.no_rawat = rp.no_rawat 
                    WHERE {$timeFilter}
                ");
                $stmtTotal->execute($params);
                $total = (int)$stmtTotal->fetchColumn();

                $stmtBlocked = $pdo->prepare("
                    SELECT COUNT(*) FROM diagnosa_pasien dp
                    INNER JOIN reg_periksa rp ON dp.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    WHERE {$timeFilter} AND sse.id_encounter IS NULL
                ");
                $stmtBlocked->execute($params);
                $blocked = (int)$stmtBlocked->fetchColumn();

                $stmtSynced = $pdo->prepare("
                    SELECT COUNT(*) FROM satu_sehat_episode_of_care eoc
                    INNER JOIN reg_periksa rp ON eoc.no_rawat = rp.no_rawat
                    WHERE {$timeFilter}
                ");
                $stmtSynced->execute($params);
                $synced = (int)$stmtSynced->fetchColumn();

                $stats = [
                    'total' => $total,
                    'blocked' => $blocked,
                    'synced' => $synced,
                    'pending' => max(0, $total - $blocked - $synced)
                ];
                break;

            case 'condition':
                $stmtTotal = $pdo->prepare("
                    SELECT COUNT(*) FROM diagnosa_pasien dp 
                    INNER JOIN reg_periksa rp ON dp.no_rawat = rp.no_rawat 
                    WHERE {$timeFilter}
                ");
                $stmtTotal->execute($params);
                $total = (int)$stmtTotal->fetchColumn();

                $stmtBlocked = $pdo->prepare("
                    SELECT COUNT(*) FROM diagnosa_pasien dp
                    INNER JOIN reg_periksa rp ON dp.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    WHERE {$timeFilter} AND sse.id_encounter IS NULL
                ");
                $stmtBlocked->execute($params);
                $blocked = (int)$stmtBlocked->fetchColumn();

                $stmtSynced = $pdo->prepare("
                    SELECT COUNT(*) FROM satu_sehat_condition ssc
                    INNER JOIN reg_periksa rp ON ssc.no_rawat = rp.no_rawat
                    WHERE {$timeFilter}
                ");
                $stmtSynced->execute($params);
                $synced = (int)$stmtSynced->fetchColumn();

                $stats = [
                    'total' => $total,
                    'blocked' => $blocked,
                    'synced' => $synced,
                    'pending' => max(0, $total - $blocked - $synced)
                ];
                break;

            case 'observationttv':
                if ($noRawat) {
                    $stmtTotal = $pdo->prepare("
                        SELECT 
                            (SELECT COUNT(*) FROM pemeriksaan_ralan pr INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat WHERE rp.no_rawat = :nr) +
                            (SELECT COUNT(*) FROM pemeriksaan_ranap pr INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat WHERE rp.no_rawat = :nr2)
                    ");
                    $stmtTotal->execute(['nr' => $noRawat, 'nr2' => $noRawat]);
                    $total = (int)$stmtTotal->fetchColumn();

                    $stmtBlocked = $pdo->prepare("
                        SELECT 
                            (SELECT COUNT(*) FROM pemeriksaan_ralan pr INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat WHERE rp.no_rawat = :nr AND sse.id_encounter IS NULL) +
                            (SELECT COUNT(*) FROM pemeriksaan_ranap pr INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat WHERE rp.no_rawat = :nr2 AND sse.id_encounter IS NULL)
                    ");
                    $stmtBlocked->execute(['nr' => $noRawat, 'nr2' => $noRawat]);
                    $blocked = (int)$stmtBlocked->fetchColumn();
                } else {
                    $stmtTotal = $pdo->prepare("
                        SELECT 
                            (SELECT COUNT(*) FROM pemeriksaan_ralan pr INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt) +
                            (SELECT COUNT(*) FROM pemeriksaan_ranap pr INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df2 AND :dt2)
                    ");
                    $stmtTotal->execute(['df' => $dateFrom, 'dt' => $dateTo, 'df2' => $dateFrom, 'dt2' => $dateTo]);
                    $total = (int)$stmtTotal->fetchColumn();

                    $stmtBlocked = $pdo->prepare("
                        SELECT 
                            (SELECT COUNT(*) FROM pemeriksaan_ralan pr INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt AND sse.id_encounter IS NULL) +
                            (SELECT COUNT(*) FROM pemeriksaan_ranap pr INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df2 AND :dt2 AND sse.id_encounter IS NULL)
                    ");
                    $stmtBlocked->execute(['df' => $dateFrom, 'dt' => $dateTo, 'df2' => $dateFrom, 'dt2' => $dateTo]);
                    $blocked = (int)$stmtBlocked->fetchColumn();
                }

                // Synced observation count
                $ttvTables = [
                    'satu_sehat_observationttvsuhu', 'satu_sehat_observationttvrespirasi',
                    'satu_sehat_observationttvnadi', 'satu_sehat_observationttvspo2',
                    'satu_sehat_observationttvtb', 'satu_sehat_observationttvbb',
                    'satu_sehat_observationttvlp', 'satu_sehat_observationttvtensi',
                    'satu_sehat_observationttvgcs', 'satu_sehat_observationttvkesadaran'
                ];
                $synced = 0;
                foreach ($ttvTables as $table) {
                    if ($noRawat) {
                        $stmtS = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE no_rawat = :nr");
                        $stmtS->execute(['nr' => $noRawat]);
                    } else {
                        $stmtS = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE tgl_perawatan BETWEEN :df AND :dt");
                        $stmtS->execute(['df' => $dateFrom, 'dt' => $dateTo]);
                    }
                    $synced += (int)$stmtS->fetchColumn();
                }

                $stats = [
                    'total' => $total,
                    'blocked' => $blocked,
                    'synced' => $synced,
                    'pending' => max(0, $total - $blocked - $synced)
                ];
                break;

            case 'procedure':
                $stmtTotal = $pdo->prepare("
                    SELECT COUNT(*) FROM prosedur_pasien pp 
                    INNER JOIN reg_periksa rp ON pp.no_rawat = rp.no_rawat 
                    WHERE {$timeFilter}
                ");
                $stmtTotal->execute($params);
                $total = (int)$stmtTotal->fetchColumn();

                $stmtBlocked = $pdo->prepare("
                    SELECT COUNT(*) FROM prosedur_pasien pp
                    INNER JOIN reg_periksa rp ON pp.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    WHERE {$timeFilter} AND sse.id_encounter IS NULL
                ");
                $stmtBlocked->execute($params);
                $blocked = (int)$stmtBlocked->fetchColumn();

                $stmtSynced = $pdo->prepare("
                    SELECT COUNT(*) FROM satu_sehat_procedure ssp
                    INNER JOIN reg_periksa rp ON ssp.no_rawat = rp.no_rawat
                    WHERE {$timeFilter}
                ");
                $stmtSynced->execute($params);
                $synced = (int)$stmtSynced->fetchColumn();

                $stats = [
                    'total' => $total,
                    'blocked' => $blocked,
                    'synced' => $synced,
                    'pending' => max(0, $total - $blocked - $synced)
                ];
                break;

            case 'allergyintolerance':
                $stmtTotal = $pdo->prepare("
                    SELECT COUNT(*) FROM alergi_pasien ap 
                    INNER JOIN reg_periksa rp ON ap.no_rawat = rp.no_rawat 
                    WHERE {$timeFilter}
                ");
                $stmtTotal->execute($params);
                $total = (int)$stmtTotal->fetchColumn();

                $stmtBlocked = $pdo->prepare("
                    SELECT COUNT(*) FROM alergi_pasien ap
                    INNER JOIN reg_periksa rp ON ap.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    WHERE {$timeFilter} AND sse.id_encounter IS NULL
                ");
                $stmtBlocked->execute($params);
                $blocked = (int)$stmtBlocked->fetchColumn();

                $stmtSynced = $pdo->prepare("
                    SELECT COUNT(*) FROM satu_sehat_allergy_intolerance ssai
                    INNER JOIN reg_periksa rp ON ssai.no_rawat = rp.no_rawat
                    WHERE {$timeFilter}
                ");
                $stmtSynced->execute($params);
                $synced = (int)$stmtSynced->fetchColumn();

                $stats = [
                    'total' => $total,
                    'blocked' => $blocked,
                    'synced' => $synced,
                    'pending' => max(0, $total - $blocked - $synced)
                ];
                break;

            case 'immunization':
                $stmtTotal = $pdo->prepare("
                    SELECT COUNT(*) FROM detail_pemberian_obat dpo 
                    INNER JOIN reg_periksa rp ON dpo.no_rawat = rp.no_rawat 
                    WHERE {$timeFilter}
                ");
                $stmtTotal->execute($params);
                $total = (int)$stmtTotal->fetchColumn();

                $stmtUnmapped = $pdo->prepare("
                    SELECT COUNT(*) FROM detail_pemberian_obat dpo
                    INNER JOIN reg_periksa rp ON dpo.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_mapping_vaksin smv ON smv.kode_brng = dpo.kode_brng
                    WHERE {$timeFilter} AND smv.kode_brng IS NULL
                ");
                $stmtUnmapped->execute($params);
                $unmapped = (int)$stmtUnmapped->fetchColumn();

                $stmtBlocked = $pdo->prepare("
                    SELECT COUNT(*) FROM detail_pemberian_obat dpo
                    INNER JOIN reg_periksa rp ON dpo.no_rawat = rp.no_rawat
                    INNER JOIN satu_sehat_mapping_vaksin smv ON smv.kode_brng = dpo.kode_brng
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    WHERE {$timeFilter} AND sse.id_encounter IS NULL
                ");
                $stmtBlocked->execute($params);
                $blocked = (int)$stmtBlocked->fetchColumn();

                $stmtSynced = $pdo->prepare("
                    SELECT COUNT(*) FROM satu_sehat_immunization ssi
                    INNER JOIN reg_periksa rp ON ssi.no_rawat = rp.no_rawat
                    WHERE {$timeFilter}
                ");
                $stmtSynced->execute($params);
                $synced = (int)$stmtSynced->fetchColumn();

                $stats = [
                    'total' => $total,
                    'unmapped' => $unmapped,
                    'blocked' => $blocked,
                    'synced' => $synced,
                    'pending' => max(0, $total - $unmapped - $blocked - $synced)
                ];
                break;

            case 'medication':
                $total = (int)$pdo->query("SELECT COUNT(*) FROM databarang")->fetchColumn();
                $unmapped = (int)$pdo->query("
                    SELECT COUNT(*) FROM databarang db
                    LEFT JOIN satu_sehat_mapping_obat ssmo ON db.kode_brng = ssmo.kode_brng
                    WHERE ssmo.kode_brng IS NULL
                ")->fetchColumn();
                $synced = (int)$pdo->query("SELECT COUNT(*) FROM satu_sehat_medication")->fetchColumn();

                $stats = [
                    'total' => $total,
                    'unmapped' => $unmapped,
                    'synced' => $synced,
                    'pending' => max(0, $total - $unmapped - $synced)
                ];
                break;

            case 'medicationrequest':
                $stmtTotal = $pdo->prepare("
                    SELECT COUNT(*) FROM resep_dokter rd
                    INNER JOIN resep_obat ro ON rd.no_resep = ro.no_resep
                    INNER JOIN reg_periksa rp ON ro.no_rawat = rp.no_rawat
                    WHERE {$timeFilter}
                ");
                $stmtTotal->execute($params);
                $total = (int)$stmtTotal->fetchColumn();

                $stmtBlocked = $pdo->prepare("
                    SELECT COUNT(*) FROM resep_dokter rd
                    INNER JOIN resep_obat ro ON rd.no_resep = ro.no_resep
                    INNER JOIN reg_periksa rp ON ro.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    WHERE {$timeFilter} AND sse.id_encounter IS NULL
                ");
                $stmtBlocked->execute($params);
                $blocked = (int)$stmtBlocked->fetchColumn();

                if ($noRawat) {
                    $stmtSynced = $pdo->prepare("
                        SELECT 
                            (SELECT COUNT(*) FROM satu_sehat_medicationrequest ssmr INNER JOIN resep_obat ro ON ssmr.no_resep = ro.no_resep INNER JOIN reg_periksa rp ON ro.no_rawat = rp.no_rawat WHERE rp.no_rawat = :nr) +
                            (SELECT COUNT(*) FROM satu_sehat_medicationrequest_racikan ssmrr INNER JOIN resep_obat ro ON ssmrr.no_resep = ro.no_resep INNER JOIN reg_periksa rp ON ro.no_rawat = rp.no_rawat WHERE rp.no_rawat = :nr2)
                    ");
                    $stmtSynced->execute(['nr' => $noRawat, 'nr2' => $noRawat]);
                } else {
                    $stmtSynced = $pdo->prepare("
                        SELECT 
                            (SELECT COUNT(*) FROM satu_sehat_medicationrequest ssmr INNER JOIN resep_obat ro ON ssmr.no_resep = ro.no_resep INNER JOIN reg_periksa rp ON ro.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt) +
                            (SELECT COUNT(*) FROM satu_sehat_medicationrequest_racikan ssmrr INNER JOIN resep_obat ro ON ssmrr.no_resep = ro.no_resep INNER JOIN reg_periksa rp ON ro.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df2 AND :dt2)
                    ");
                    $stmtSynced->execute(['df' => $dateFrom, 'dt' => $dateTo, 'df2' => $dateFrom, 'dt2' => $dateTo]);
                }
                $synced = (int)$stmtSynced->fetchColumn();

                $stats = [
                    'total' => $total,
                    'blocked' => $blocked,
                    'synced' => $synced,
                    'pending' => max(0, $total - $blocked - $synced)
                ];
                break;

            case 'medicationdispense':
                $stmtTotal = $pdo->prepare("
                    SELECT COUNT(*) FROM detail_pemberian_obat dpo
                    INNER JOIN reg_periksa rp ON dpo.no_rawat = rp.no_rawat
                    WHERE {$timeFilter}
                ");
                $stmtTotal->execute($params);
                $total = (int)$stmtTotal->fetchColumn();

                $stmtBlocked = $pdo->prepare("
                    SELECT COUNT(*) FROM detail_pemberian_obat dpo
                    INNER JOIN reg_periksa rp ON dpo.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    WHERE {$timeFilter} AND sse.id_encounter IS NULL
                ");
                $stmtBlocked->execute($params);
                $blocked = (int)$stmtBlocked->fetchColumn();

                $stmtSynced = $pdo->prepare("
                    SELECT COUNT(*) FROM satu_sehat_medicationdispense ssm
                    INNER JOIN reg_periksa rp ON ssm.no_rawat = rp.no_rawat
                    WHERE {$timeFilter}
                ");
                $stmtSynced->execute($params);
                $synced = (int)$stmtSynced->fetchColumn();

                $stats = [
                    'total' => $total,
                    'blocked' => $blocked,
                    'synced' => $synced,
                    'pending' => max(0, $total - $blocked - $synced)
                ];
                break;

            case 'medicationstatement':
                $stmtTotal = $pdo->prepare("
                    SELECT COUNT(*) FROM resep_dokter rd
                    INNER JOIN resep_obat ro ON rd.no_resep = ro.no_resep
                    INNER JOIN reg_periksa rp ON ro.no_rawat = rp.no_rawat
                    WHERE {$timeFilter}
                ");
                $stmtTotal->execute($params);
                $total = (int)$stmtTotal->fetchColumn();

                $stmtBlocked = $pdo->prepare("
                    SELECT COUNT(*) FROM resep_dokter rd
                    INNER JOIN resep_obat ro ON rd.no_resep = ro.no_resep
                    INNER JOIN reg_periksa rp ON ro.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    WHERE {$timeFilter} AND sse.id_encounter IS NULL
                ");
                $stmtBlocked->execute($params);
                $blocked = (int)$stmtBlocked->fetchColumn();

                if ($noRawat) {
                    $stmtSynced = $pdo->prepare("
                        SELECT 
                            (SELECT COUNT(*) FROM satu_sehat_medicationstatement ssms INNER JOIN resep_obat ro ON ssms.no_resep = ro.no_resep INNER JOIN reg_periksa rp ON ro.no_rawat = rp.no_rawat WHERE rp.no_rawat = :nr) +
                            (SELECT COUNT(*) FROM satu_sehat_medicationstatement_racikan ssmsr INNER JOIN resep_obat ro ON ssmsr.no_resep = ro.no_resep INNER JOIN reg_periksa rp ON ro.no_rawat = rp.no_rawat WHERE rp.no_rawat = :nr2)
                    ");
                    $stmtSynced->execute(['nr' => $noRawat, 'nr2' => $noRawat]);
                } else {
                    $stmtSynced = $pdo->prepare("
                        SELECT 
                            (SELECT COUNT(*) FROM satu_sehat_medicationstatement ssms INNER JOIN resep_obat ro ON ssms.no_resep = ro.no_resep INNER JOIN reg_periksa rp ON ro.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt) +
                            (SELECT COUNT(*) FROM satu_sehat_medicationstatement_racikan ssmsr INNER JOIN resep_obat ro ON ssmsr.no_resep = ro.no_resep INNER JOIN reg_periksa rp ON ro.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df2 AND :dt2)
                    ");
                    $stmtSynced->execute(['df' => $dateFrom, 'dt' => $dateTo, 'df2' => $dateFrom, 'dt2' => $dateTo]);
                }
                $synced = (int)$stmtSynced->fetchColumn();

                $stats = [
                    'total' => $total,
                    'blocked' => $blocked,
                    'synced' => $synced,
                    'pending' => max(0, $total - $blocked - $synced)
                ];
                break;
        }

        jsonResponse([
            'success' => true,
            'resource' => $resource,
            'stats' => $stats
        ]);

    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

if ($action === 'triggerBatchSync' && $method === 'POST') {
    if ($userRole !== 'admin') {
        jsonResponse(['success' => false, 'message' => 'Forbidden. Admin privileges required.'], 403);
    }

    $resource = $_GET['resource'] ?? 'patient';
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    if ($limit <= 0 || $limit > 50) $limit = 10;
    $noRawat = $_GET['no_rawat'] ?? null;

    // Resolve dates
    $dateFrom = $_GET['dateFrom'] ?? null;
    $dateTo = $_GET['dateTo'] ?? null;
    if (!$dateFrom || !$dateTo) {
        if ($config->lookbackDays > 0) {
            $dateTo = date('Y-m-d', strtotime('-1 day'));
            $dateFrom = date('Y-m-d', strtotime('-' . $config->lookbackDays . ' days', strtotime(date('Y-m-d'))));
        } else {
            $dateFrom = $config->dateFrom;
            $dateTo = $config->dateTo;
        }
    }

    try {
        require_once BASE_DIR . '/lib/satusehat/Database.php';
        require_once BASE_DIR . '/lib/satusehat/PayloadBuilder.php';

        $db = new SatuSehatDatabase($config, $log, $client);

        if (strtolower($resource) === 'patient') {
            if ($noRawat) {
                $stmt = $pdo->prepare("
                    SELECT p.no_ktp as nik, p.nm_pasien, p.no_rkm_medis 
                    FROM reg_periksa rp
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    WHERE rp.no_rawat = :nr
                ");
                $stmt->execute(['nr' => $noRawat]);
                $patient = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$patient) {
                    $stmt = $pdo->prepare("
                        SELECT p.no_ktp as nik, p.nm_pasien, p.no_rkm_medis 
                        FROM pasien p
                        WHERE p.no_ktp = :nik OR p.no_rkm_medis = :rm
                        LIMIT 1
                    ");
                    $stmt->execute(['nik' => $noRawat, 'rm' => $noRawat]);
                    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
                }

                if (!$patient) {
                    jsonResponse(['success' => false, 'message' => 'No patient found for identifier.'], 404);
                }
                
                $nik = $patient['nik'];
                $endpoint = "/Patient?identifier=https://fhir.kemkes.go.id/id/nik|{$nik}";
                $res = $client->get($endpoint);
                
                if ($res['success'] && !empty($res['data']['entry'])) {
                    $resourceData = $res['data']['entry'][0]['resource'];
                    $ihsNumber = $resourceData['id'];
                    $insertStmt = $pdo->prepare("REPLACE INTO satu_sehat_ihs_patient (nikpasien, ihspasien) VALUES (:nik, :ihs)");
                    $insertStmt->execute(['nik' => $nik, 'ihs' => $ihsNumber]);
                    jsonResponse(['success' => true, 'synced' => [['rm' => $patient['no_rkm_medis'], 'name' => $patient['nm_pasien'], 'nik' => $nik, 'status' => 'success', 'ihs' => $ihsNumber]]]);
                } else {
                    jsonResponse(['success' => false, 'message' => 'Not found in Satu Sehat', 'synced' => [['rm' => $patient['no_rkm_medis'], 'name' => $patient['nm_pasien'], 'nik' => $nik, 'status' => 'not_found']]]);
                }
            } else {
                $stmt = $pdo->prepare("
                    SELECT p.no_ktp as nik, p.nm_pasien, p.no_rkm_medis 
                    FROM pasien p 
                    LEFT JOIN satu_sehat_ihs_patient i ON p.no_ktp = i.nikpasien 
                    WHERE i.ihspasien IS NULL 
                      AND p.no_ktp REGEXP '^[0-9]{16}$'
                    LIMIT :limit
                ");
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();
                $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $results = [];
                foreach ($patients as $patient) {
                    $nik = $patient['nik'];
                    $rm = $patient['no_rkm_medis'];
                    $name = $patient['nm_pasien'];
                    try {
                        $endpoint = "/Patient?identifier=https://fhir.kemkes.go.id/id/nik|{$nik}";
                        $res = $client->get($endpoint);
                        if ($res['success'] && !empty($res['data']['entry'])) {
                            $resourceData = $res['data']['entry'][0]['resource'];
                            $ihsNumber = $resourceData['id'];
                            $insertStmt = $pdo->prepare("REPLACE INTO satu_sehat_ihs_patient (nikpasien, ihspasien) VALUES (:nik, :ihs)");
                            $insertStmt->execute(['nik' => $nik, 'ihs' => $ihsNumber]);
                            $results[] = ['rm' => $rm, 'name' => $name, 'nik' => $nik, 'status' => 'success', 'ihs' => $ihsNumber];
                        } else {
                            $results[] = ['rm' => $rm, 'name' => $name, 'nik' => $nik, 'status' => 'not_found', 'message' => 'Not found in Satu Sehat'];
                        }
                        usleep(100000); // 100ms throttle
                    } catch (Exception $e) {
                        $results[] = ['rm' => $rm, 'name' => $name, 'nik' => $nik, 'status' => 'error', 'message' => $e->getMessage()];
                    }
                }
                jsonResponse(['success' => true, 'synced' => $results]);
            }
        }

        if (strtolower($resource) === 'workflow') {
            if (!$noRawat) {
                jsonResponse(['success' => false, 'message' => 'no_rawat parameter is required for sequential workflow sync.'], 400);
            }
            
            $workflowResults = executeWorkflowSync($pdo, $db, $client, $config, $log, $noRawat);
            jsonResponse([
                'success' => true,
                'resource' => 'workflow',
                'no_rawat' => $noRawat,
                'workflow' => $workflowResults
            ]);
        }

        $procMap = [
            'encounter' => ['class' => 'SatuSehatEncounterProcessor', 'file' => 'EncounterProcessor.php', 'method_type' => 'encounter'],
            'episodeofcare' => ['class' => 'SatuSehatEpisodeOfCareProcessor', 'file' => 'EpisodeOfCareProcessor.php', 'method_type' => 'eoc'],
            'condition' => ['class' => 'SatuSehatConditionProcessor', 'file' => 'ConditionProcessor.php', 'method_type' => 'condition'],
            'observationttv' => ['class' => 'SatuSehatObservationTTVProcessor', 'file' => 'ObservationTTVProcessor.php', 'method_type' => 'observationttv'],
            'procedure' => ['class' => 'SatuSehatProcedureProcessor', 'file' => 'ProcedureProcessor.php', 'method_type' => 'procedure'],
            'allergyintolerance' => ['class' => 'SatuSehatAllergyIntoleranceProcessor', 'file' => 'AllergyIntoleranceProcessor.php', 'method_type' => 'allergy'],
            'immunization' => ['class' => 'SatuSehatImmunizationProcessor', 'file' => 'ImmunizationProcessor.php', 'method_type' => 'immunization'],
            'medication' => ['class' => 'SatuSehatMedicationProcessor', 'file' => 'MedicationProcessor.php', 'method_type' => 'medication'],
            'medicationrequest' => ['class' => 'SatuSehatMedicationRequestProcessor', 'file' => 'MedicationRequestProcessor.php', 'method_type' => 'medicationrequest'],
            'medicationdispense' => ['class' => 'SatuSehatMedicationDispenseProcessor', 'file' => 'MedicationDispenseProcessor.php', 'method_type' => 'medicationdispense'],
            'medicationstatement' => ['class' => 'SatuSehatMedicationStatementProcessor', 'file' => 'MedicationStatementProcessor.php', 'method_type' => 'medicationstatement'],
        ];

        $resKey = strtolower($resource);
        if (!isset($procMap[$resKey])) {
            jsonResponse(['success' => false, 'message' => "Invalid resource sync key: {$resource}"], 400);
        }

        $map = $procMap[$resKey];
        require_once BASE_DIR . "/lib/satusehat/{$map['file']}";

        $procClass = $map['class'];
        $processor = new $procClass($db, $client, $config, $log);

        $stats = [];
        if ($map['method_type'] === 'observationttv') {
            require_once BASE_DIR . '/lib/satusehat/ObservationTTVDictionary.php';
            $preFetched = [];
            $definitions = ObservationTTVDictionary::getDefinitions();
            foreach ($definitions as $ttvType => $def) {
                $records = $db->fetchPendingObservations($ttvType, $def, $dateFrom, $dateTo);
                if ($noRawat) {
                    $records = array_filter($records, function($r) use ($noRawat) {
                        return $r['no_rawat'] === $noRawat;
                    });
                }
                $preFetched[$ttvType] = array_slice($records, 0, $limit);
            }
            $stats = $processor->run($preFetched);
        } elseif ($map['method_type'] === 'medication') {
            $active = $db->fetchPendingMedicationActive();
            $update = $db->fetchPendingMedicationUpdate();
            if ($noRawat) {
                $active = array_values(array_filter($active, function($r) use ($noRawat) { return $r['kode_brng'] === $noRawat; }));
                $update = array_values(array_filter($update, function($r) use ($noRawat) { return $r['kode_brng'] === $noRawat; }));
            }
            $stats = $processor->run(array_slice($active, 0, $limit), array_slice($update, 0, $limit));
        } else {
            $type = $map['method_type'];
            $activeFetchMethod = 'fetchPending' . ucfirst($type) . 'Active';
            $updateFetchMethod = 'fetchPending' . ucfirst($type) . 'Update';
            
            if ($type === 'eoc') {
                $activeFetchMethod = 'fetchPendingEocActive';
                $updateFetchMethod = 'fetchPendingEocFinished';
            } elseif ($type === 'allergy') {
                $activeFetchMethod = 'fetchPendingAllergyActive';
                $updateFetchMethod = 'fetchPendingAllergyUpdate';
            } elseif ($type === 'encounter') {
                $activeFetchMethod = 'fetchPendingArrived';
                $updateFetchMethod = 'fetchPendingInProgress';
                $finishFetchMethod = 'fetchPendingFinished';
            }

            if ($type === 'encounter') {
                $arrived = $db->$activeFetchMethod($dateFrom, $dateTo);
                $inProgress = $db->$updateFetchMethod($dateFrom, $dateTo);
                $finished = $db->$finishFetchMethod($dateFrom, $dateTo);

                if ($noRawat) {
                    $arrived = array_values(array_filter($arrived, function($r) use ($noRawat) { return $r['no_rawat'] === $noRawat; }));
                    $inProgress = array_values(array_filter($inProgress, function($r) use ($noRawat) { return $r['no_rawat'] === $noRawat; }));
                    $finished = array_values(array_filter($finished, function($r) use ($noRawat) { return $r['no_rawat'] === $noRawat; }));
                }

                $stats = $processor->run(
                    array_slice($arrived, 0, $limit),
                    array_slice($inProgress, 0, $limit),
                    array_slice($finished, 0, $limit)
                );
            } else {
                $active = $db->$activeFetchMethod($dateFrom, $dateTo);
                $update = $db->$updateFetchMethod($dateFrom, $dateTo);

                if ($noRawat) {
                    $active = array_values(array_filter($active, function($r) use ($noRawat) { return $r['no_rawat'] === $noRawat; }));
                    $update = array_values(array_filter($update, function($r) use ($noRawat) { return $r['no_rawat'] === $noRawat; }));
                }

                $stats = $processor->run(array_slice($active, 0, $limit), array_slice($update, 0, $limit));
            }
        }

        jsonResponse([
            'success' => true,
            'resource' => $resource,
            'summary' => $stats
        ]);

    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

if ($action === 'getPendingRecords' && $method === 'GET') {
    if ($userRole !== 'admin') {
        jsonResponse(['success' => false, 'message' => 'Forbidden. Admin privileges required.'], 403);
    }

    $resource = $_GET['resource'] ?? 'patient';
    $statusFilter = $_GET['status'] ?? 'all';
    $search = $_GET['search'] ?? '';
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    if ($page <= 0) $page = 1;
    if ($limit <= 0 || $limit > 100) $limit = 20;
    $offset = ($page - 1) * $limit;

    // Resolve dates
    $dateFrom = $_GET['dateFrom'] ?? null;
    $dateTo = $_GET['dateTo'] ?? null;
    if (!$dateFrom || !$dateTo) {
        if ($config->lookbackDays > 0) {
            $dateTo = date('Y-m-d', strtotime('-1 day'));
            $dateFrom = date('Y-m-d', strtotime('-' . $config->lookbackDays . ' days', strtotime(date('Y-m-d'))));
        } else {
            $dateFrom = $config->dateFrom;
            $dateTo = $config->dateTo;
        }
    }

    try {
        $sql = "";
        $params = [];

        switch (strtolower($resource)) {
            case 'patient':
                $sql = "
                    SELECT 
                        p.no_ktp as id,
                        NULL as no_rawat,
                        p.no_rkm_medis as rm,
                        p.nm_pasien as patient_name,
                        p.no_ktp as nik,
                        p.tgl_lahir as date,
                        p.alamat as details,
                        IF(ssp.ihspasien IS NOT NULL, 'synced', 'pending') as status,
                        ssp.ihspasien as ihs_id
                    FROM pasien p
                    LEFT JOIN satu_sehat_ihs_patient ssp ON ssp.nikpasien = p.no_ktp
                    WHERE p.no_ktp REGEXP '^[0-9]{16}$'
                ";
                if ($search) {
                    $sql .= " AND (p.nm_pasien LIKE :search OR p.no_ktp LIKE :search OR p.no_rkm_medis LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            case 'encounter':
                $sql = "
                    SELECT 
                        rp.no_rawat as id,
                        rp.no_rawat,
                        rp.no_rkm_medis as rm,
                        p.nm_pasien as patient_name,
                        p.no_ktp as nik,
                        rp.tgl_registrasi as date,
                        pol.nm_poli as details,
                        (CASE 
                            WHEN sse.id_encounter IS NOT NULL THEN 'synced'
                            WHEN rp.status_bayar = 'Belum Bayar' THEN 'blocked'
                            WHEN smlr.id_lokasi_satusehat IS NULL THEN 'blocked'
                            ELSE 'pending'
                         END) as status,
                         sse.id_encounter as ihs_id
                    FROM reg_periksa rp
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    INNER JOIN poliklinik pol ON rp.kd_poli = pol.kd_poli
                    LEFT JOIN satu_sehat_mapping_lokasi_ralan smlr ON smlr.kd_poli = pol.kd_poli
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                ";
                $params['df'] = $dateFrom;
                $params['dt'] = $dateTo;
                if ($search) {
                    $sql .= " AND (p.nm_pasien LIKE :search OR rp.no_rawat LIKE :search OR p.no_rkm_medis LIKE :search OR p.no_ktp LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            case 'episodeofcare':
            case 'episode_of_care':
                $sql = "
                    SELECT 
                        CONCAT(dp.no_rawat, '-', dp.kd_penyakit, '-', dp.status) as id,
                        dp.no_rawat,
                        rp.no_rkm_medis as rm,
                        p.nm_pasien as patient_name,
                        p.no_ktp as nik,
                        rp.tgl_registrasi as date,
                        py.nm_penyakit as details,
                        (CASE 
                            WHEN sseoc.id_episode_of_care IS NOT NULL THEN 'synced'
                            WHEN sse.id_encounter IS NULL THEN 'blocked'
                            ELSE 'pending'
                         END) as status,
                         sseoc.id_episode_of_care as ihs_id
                    FROM diagnosa_pasien dp
                    INNER JOIN reg_periksa rp ON dp.no_rawat = rp.no_rawat
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    INNER JOIN penyakit py ON dp.kd_penyakit = py.kd_penyakit
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_episode_of_care sseoc ON sseoc.no_rawat = rp.no_rawat
                    WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                ";
                $params['df'] = $dateFrom;
                $params['dt'] = $dateTo;
                if ($search) {
                    $sql .= " AND (p.nm_pasien LIKE :search OR dp.no_rawat LIKE :search OR rp.no_rkm_medis LIKE :search OR py.nm_penyakit LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            case 'condition':
                $sql = "
                    SELECT 
                        CONCAT(dp.no_rawat, '-', dp.kd_penyakit, '-', dp.status) as id,
                        dp.no_rawat,
                        rp.no_rkm_medis as rm,
                        p.nm_pasien as patient_name,
                        p.no_ktp as nik,
                        rp.tgl_registrasi as date,
                        py.nm_penyakit as details,
                        (CASE 
                            WHEN ssc.id_condition IS NOT NULL THEN 'synced'
                            WHEN sse.id_encounter IS NULL THEN 'blocked'
                            ELSE 'pending'
                         END) as status,
                         ssc.id_condition as ihs_id
                    FROM diagnosa_pasien dp
                    INNER JOIN reg_periksa rp ON dp.no_rawat = rp.no_rawat
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    INNER JOIN penyakit py ON dp.kd_penyakit = py.kd_penyakit
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_condition ssc ON ssc.no_rawat = dp.no_rawat AND ssc.kd_penyakit = dp.kd_penyakit AND ssc.status = dp.status
                    WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                ";
                $params['df'] = $dateFrom;
                $params['dt'] = $dateTo;
                if ($search) {
                    $sql .= " AND (p.nm_pasien LIKE :search OR dp.no_rawat LIKE :search OR rp.no_rkm_medis LIKE :search OR py.nm_penyakit LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            case 'observationttv':
                $sql = "
                    SELECT 
                        CONCAT(pr.no_rawat, '-', pr.tgl_perawatan, '-', pr.jam_rawat) as id,
                        pr.no_rawat,
                        rp.no_rkm_medis as rm,
                        p.nm_pasien as patient_name,
                        p.no_ktp as nik,
                        pr.tgl_perawatan as date,
                        CONCAT('Suhu: ', pr.suhu_tubuh, ', Tensi: ', pr.tensi) as details,
                        (CASE 
                            WHEN sso.id_observation IS NOT NULL THEN 'synced'
                            WHEN sse.id_encounter IS NULL THEN 'blocked'
                            ELSE 'pending'
                         END) as status,
                         sso.id_observation as ihs_id
                    FROM pemeriksaan_ralan pr
                    INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_observationttvsuhu sso ON sso.no_rawat = pr.no_rawat AND sso.tgl_perawatan = pr.tgl_perawatan AND sso.jam_rawat = pr.jam_rawat
                    WHERE pr.tgl_perawatan BETWEEN :df AND :dt
                ";
                $params['df'] = $dateFrom;
                $params['dt'] = $dateTo;
                if ($search) {
                    $sql .= " AND (p.nm_pasien LIKE :search OR pr.no_rawat LIKE :search OR rp.no_rkm_medis LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            case 'procedure':
                $sql = "
                    SELECT 
                        CONCAT(pp.no_rawat, '-', pp.kode) as id,
                        pp.no_rawat,
                        rp.no_rkm_medis as rm,
                        p.nm_pasien as patient_name,
                        p.no_ktp as nik,
                        rp.tgl_registrasi as date,
                        CONCAT(pp.kode, ' - ', icd.deskripsi_panjang) as details,
                        (CASE 
                            WHEN ssp.id_procedure IS NOT NULL THEN 'synced'
                            WHEN sse.id_encounter IS NULL THEN 'blocked'
                            ELSE 'pending'
                         END) as status,
                         ssp.id_procedure as ihs_id
                    FROM prosedur_pasien pp
                    INNER JOIN reg_periksa rp ON pp.no_rawat = rp.no_rawat
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    INNER JOIN icd9 icd ON pp.kode = icd.kode
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_procedure ssp ON ssp.no_rawat = pp.no_rawat AND ssp.kode = pp.kode
                    WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                ";
                $params['df'] = $dateFrom;
                $params['dt'] = $dateTo;
                if ($search) {
                    $sql .= " AND (p.nm_pasien LIKE :search OR pp.no_rawat LIKE :search OR rp.no_rkm_medis LIKE :search OR icd.deskripsi_panjang LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            case 'allergyintolerance':
                $sql = "
                    SELECT 
                        CONCAT(pr.no_rawat, '-', pr.tgl_perawatan, '-', pr.jam_rawat) as id,
                        pr.no_rawat,
                        rp.no_rkm_medis as rm,
                        p.nm_pasien as patient_name,
                        p.no_ktp as nik,
                        pr.tgl_perawatan as date,
                        pr.alergi as details,
                        (CASE 
                            WHEN ssai.id_allergy_intolerance IS NOT NULL THEN 'synced'
                            WHEN sse.id_encounter IS NULL THEN 'blocked'
                            ELSE 'pending'
                         END) as status,
                         ssai.id_allergy_intolerance as ihs_id
                    FROM pemeriksaan_ralan pr
                    INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_allergy_intolerance ssai ON ssai.no_rawat = pr.no_rawat AND ssai.tgl_perawatan = pr.tgl_perawatan AND ssai.jam_rawat = pr.jam_rawat
                    WHERE pr.alergi <> '' AND pr.tgl_perawatan BETWEEN :df AND :dt
                ";
                $params['df'] = $dateFrom;
                $params['dt'] = $dateTo;
                if ($search) {
                    $sql .= " AND (p.nm_pasien LIKE :search OR pr.no_rawat LIKE :search OR rp.no_rkm_medis LIKE :search OR pr.alergi LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            case 'immunization':
                $sql = "
                    SELECT 
                        CONCAT(dpo.no_rawat, '-', dpo.kode_brng, '-', dpo.tgl_perawatan) as id,
                        dpo.no_rawat,
                        rp.no_rkm_medis as rm,
                        p.nm_pasien as patient_name,
                        p.no_ktp as nik,
                        dpo.tgl_perawatan as date,
                        db.nama_brng as details,
                        (CASE 
                            WHEN ssi.id_immunization IS NOT NULL THEN 'synced'
                            WHEN sse.id_encounter IS NULL THEN 'blocked'
                            ELSE 'pending'
                         END) as status,
                         ssi.id_immunization as ihs_id
                    FROM detail_pemberian_obat dpo
                    INNER JOIN reg_periksa rp ON dpo.no_rawat = rp.no_rawat
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    INNER JOIN databarang db ON dpo.kode_brng = db.kode_brng
                    INNER JOIN satu_sehat_mapping_vaksin smv ON smv.kode_brng = dpo.kode_brng
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_immunization ssi ON ssi.no_rawat = dpo.no_rawat AND ssi.kode_brng = dpo.kode_brng AND ssi.tgl_perawatan = dpo.tgl_perawatan
                    WHERE dpo.tgl_perawatan BETWEEN :df AND :dt
                ";
                $params['df'] = $dateFrom;
                $params['dt'] = $dateTo;
                if ($search) {
                    $sql .= " AND (p.nm_pasien LIKE :search OR dpo.no_rawat LIKE :search OR rp.no_rkm_medis LIKE :search OR db.nama_brng LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            case 'medication':
                $sql = "
                    SELECT 
                        ssmo.kode_brng as id,
                        NULL as no_rawat,
                        NULL as rm,
                        ssmo.obat_display as patient_name,
                        ssmo.obat_code as nik,
                        NULL as date,
                        ssmo.form_display as details,
                        IF(ssm.id_medication IS NOT NULL AND ssm.id_medication <> '', 'synced', 'pending') as status,
                        ssm.id_medication as ihs_id
                    FROM satu_sehat_mapping_obat ssmo
                    INNER JOIN databarang db ON ssmo.kode_brng = db.kode_brng
                    LEFT JOIN satu_sehat_medication ssm ON ssm.kode_brng = ssmo.kode_brng
                    WHERE 1 = 1
                ";
                if ($search) {
                    $sql .= " AND (ssmo.obat_display LIKE :search OR ssmo.kode_brng LIKE :search OR ssmo.obat_code LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            case 'medicationrequest':
            case 'medication_request':
                $sql = "
                    SELECT 
                        CONCAT(dpo.no_rawat, '-', dpo.kode_brng, '-', dpo.tgl_perawatan) as id,
                        dpo.no_rawat,
                        rp.no_rkm_medis as rm,
                        p.nm_pasien as patient_name,
                        p.no_ktp as nik,
                        dpo.tgl_perawatan as date,
                        db.nama_brng as details,
                        (CASE 
                            WHEN ssmr.id_medication_request IS NOT NULL THEN 'synced'
                            WHEN sse.id_encounter IS NULL THEN 'blocked'
                            ELSE 'pending'
                         END) as status,
                         ssmr.id_medication_request as ihs_id
                    FROM detail_pemberian_obat dpo
                    INNER JOIN reg_periksa rp ON dpo.no_rawat = rp.no_rawat
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    INNER JOIN databarang db ON dpo.kode_brng = db.kode_brng
                    INNER JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = dpo.kode_brng
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_medication_request ssmr ON ssmr.no_rawat = dpo.no_rawat AND ssmr.kode_brng = dpo.kode_brng AND ssmr.tgl_perawatan = dpo.tgl_perawatan
                    WHERE dpo.tgl_perawatan BETWEEN :df AND :dt
                ";
                $params['df'] = $dateFrom;
                $params['dt'] = $dateTo;
                if ($search) {
                    $sql .= " AND (p.nm_pasien LIKE :search OR dpo.no_rawat LIKE :search OR rp.no_rkm_medis LIKE :search OR db.nama_brng LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            case 'medicationdispense':
            case 'medication_dispense':
                $sql = "
                    SELECT 
                        CONCAT(dpo.no_rawat, '-', dpo.kode_brng, '-', dpo.tgl_perawatan) as id,
                        dpo.no_rawat,
                        rp.no_rkm_medis as rm,
                        p.nm_pasien as patient_name,
                        p.no_ktp as nik,
                        dpo.tgl_perawatan as date,
                        db.nama_brng as details,
                        (CASE 
                            WHEN ssmd.id_medication_dispense IS NOT NULL THEN 'synced'
                            WHEN sse.id_encounter IS NULL THEN 'blocked'
                            ELSE 'pending'
                         END) as status,
                         ssmd.id_medication_dispense as ihs_id
                    FROM detail_pemberian_obat dpo
                    INNER JOIN reg_periksa rp ON dpo.no_rawat = rp.no_rawat
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    INNER JOIN databarang db ON dpo.kode_brng = db.kode_brng
                    INNER JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = dpo.kode_brng
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_medication_dispense ssmd ON ssmd.no_rawat = dpo.no_rawat AND ssmd.kode_brng = dpo.kode_brng AND ssmd.tgl_perawatan = dpo.tgl_perawatan
                    WHERE dpo.tgl_perawatan BETWEEN :df AND :dt
                ";
                $params['df'] = $dateFrom;
                $params['dt'] = $dateTo;
                if ($search) {
                    $sql .= " AND (p.nm_pasien LIKE :search OR dpo.no_rawat LIKE :search OR rp.no_rkm_medis LIKE :search OR db.nama_brng LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            case 'medicationstatement':
            case 'medication_statement':
                $sql = "
                    SELECT 
                        CONCAT(dpo.no_rawat, '-', dpo.kode_brng, '-', dpo.tgl_perawatan) as id,
                        dpo.no_rawat,
                        rp.no_rkm_medis as rm,
                        p.nm_pasien as patient_name,
                        p.no_ktp as nik,
                        dpo.tgl_perawatan as date,
                        db.nama_brng as details,
                        (CASE 
                            WHEN ssms.id_medication_statement IS NOT NULL THEN 'synced'
                            WHEN sse.id_encounter IS NULL THEN 'blocked'
                            ELSE 'pending'
                         END) as status,
                         ssms.id_medication_statement as ihs_id
                    FROM detail_pemberian_obat dpo
                    INNER JOIN reg_periksa rp ON dpo.no_rawat = rp.no_rawat
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    INNER JOIN databarang db ON dpo.kode_brng = db.kode_brng
                    INNER JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = dpo.kode_brng
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_medication_statement ssms ON ssms.no_rawat = dpo.no_rawat AND ssms.kode_brng = dpo.kode_brng AND ssms.tgl_perawatan = dpo.tgl_perawatan
                    WHERE dpo.tgl_perawatan BETWEEN :df AND :dt
                ";
                $params['df'] = $dateFrom;
                $params['dt'] = $dateTo;
                if ($search) {
                    $sql .= " AND (p.nm_pasien LIKE :search OR dpo.no_rawat LIKE :search OR rp.no_rkm_medis LIKE :search OR db.nama_brng LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            default:
                jsonResponse(['success' => false, 'message' => 'Invalid resource type'], 400);
        }

        // Apply status filter via wrapped subquery
        if ($statusFilter && $statusFilter !== 'all') {
            $sql = "SELECT * FROM ({$sql}) AS sub WHERE sub.status = :status_filter";
            $params['status_filter'] = $statusFilter;
        }

        // Get total count
        $countSql = "SELECT COUNT(*) FROM ({$sql}) AS total_cnt";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalCount = (int)$countStmt->fetchColumn();

        // Get paginated list
        $sql .= " LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        
        // Bind values
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        
        $stmt->execute();
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse([
            'success' => true,
            'resource' => $resource,
            'total_count' => $totalCount,
            'page' => $page,
            'limit' => $limit,
            'records' => $records
        ]);

    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

function executeWorkflowSync($pdo, SatuSehatDatabase $db, SatuSehatClient $client, SatuSehatConfig $config, Logger $log, string $noRawat): array {
    $results = [];

    // Step 1: Patient mapping check
    $stmt = $pdo->prepare("
        SELECT p.no_ktp as nik, p.nm_pasien, p.no_rkm_medis 
        FROM reg_periksa rp
        INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
        WHERE rp.no_rawat = :nr
    ");
    $stmt->execute(['nr' => $noRawat]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$patient) {
        return ['patient' => ['status' => 'failed', 'message' => 'No patient record found.']];
    }
    
    $nik = $patient['nik'];
    $ihsPatient = $db->getIhsPatient($nik);
    
    if (!$ihsPatient) {
        $endpoint = "/Patient?identifier=https://fhir.kemkes.go.id/id/nik|{$nik}";
        $res = $client->get($endpoint);
        if ($res['success'] && !empty($res['data']['entry'])) {
            $ihsPatient = $res['data']['entry'][0]['resource']['id'];
            $insertStmt = $pdo->prepare("REPLACE INTO satu_sehat_ihs_patient (nikpasien, ihspasien) VALUES (:nik, :ihs)");
            $insertStmt->execute(['nik' => $nik, 'ihs' => $ihsPatient]);
            $results['patient'] = ['status' => 'success', 'ihs' => $ihsPatient];
        } else {
            return ['patient' => ['status' => 'failed', 'message' => 'Patient has no valid NIK or is not registered in Satu Sehat. Sync aborted.']];
        }
    } else {
        $results['patient'] = ['status' => 'already_mapped', 'ihs' => $ihsPatient];
    }

    // Define workflow steps
    $steps = [
        'encounter' => ['class' => 'SatuSehatEncounterProcessor', 'file' => 'EncounterProcessor.php', 'type' => 'encounter'],
        'episodeofcare' => ['class' => 'SatuSehatEpisodeOfCareProcessor', 'file' => 'EpisodeOfCareProcessor.php', 'type' => 'eoc'],
        'condition' => ['class' => 'SatuSehatConditionProcessor', 'file' => 'ConditionProcessor.php', 'type' => 'condition'],
        'observationttv' => ['class' => 'SatuSehatObservationTTVProcessor', 'file' => 'ObservationTTVProcessor.php', 'type' => 'observationttv'],
        'procedure' => ['class' => 'SatuSehatProcedureProcessor', 'file' => 'ProcedureProcessor.php', 'type' => 'procedure'],
        'allergyintolerance' => ['class' => 'SatuSehatAllergyIntoleranceProcessor', 'file' => 'AllergyIntoleranceProcessor.php', 'type' => 'allergy'],
        'immunization' => ['class' => 'SatuSehatImmunizationProcessor', 'file' => 'ImmunizationProcessor.php', 'type' => 'immunization'],
        'medicationrequest' => ['class' => 'SatuSehatMedicationRequestProcessor', 'file' => 'MedicationRequestProcessor.php', 'type' => 'medicationrequest'],
        'medicationdispense' => ['class' => 'SatuSehatMedicationDispenseProcessor', 'file' => 'MedicationDispenseProcessor.php', 'type' => 'medicationdispense'],
        'medicationstatement' => ['class' => 'SatuSehatMedicationStatementProcessor', 'file' => 'MedicationStatementProcessor.php', 'type' => 'medicationstatement'],
    ];

    $dateFrom = '2000-01-01';
    $dateTo = date('Y-m-d', strtotime('+1 day'));

    foreach ($steps as $name => $step) {
        try {
            require_once BASE_DIR . "/lib/satusehat/{$step['file']}";
            $procClass = $step['class'];
            $processor = new $procClass($db, $client, $config, $log);

            $stats = [];
            if ($step['type'] === 'observationttv') {
                require_once BASE_DIR . '/lib/satusehat/ObservationTTVDictionary.php';
                $preFetched = [];
                $definitions = ObservationTTVDictionary::getDefinitions();
                foreach ($definitions as $ttvType => $def) {
                    $records = $db->fetchPendingObservations($ttvType, $def, $dateFrom, $dateTo);
                    $records = array_filter($records, function($r) use ($noRawat) {
                        return $r['no_rawat'] === $noRawat;
                    });
                    $preFetched[$ttvType] = array_values($records);
                }
                $stats = $processor->run($preFetched);
            } else {
                $type = $step['type'];
                $activeFetchMethod = 'fetchPending' . ucfirst($type) . 'Active';
                $updateFetchMethod = 'fetchPending' . ucfirst($type) . 'Update';
                
                if ($type === 'eoc') {
                    $activeFetchMethod = 'fetchPendingEocActive';
                    $updateFetchMethod = 'fetchPendingEocFinished';
                } elseif ($type === 'allergy') {
                    $activeFetchMethod = 'fetchPendingAllergyActive';
                    $updateFetchMethod = 'fetchPendingAllergyUpdate';
                } elseif ($type === 'encounter') {
                    $activeFetchMethod = 'fetchPendingArrived';
                    $updateFetchMethod = 'fetchPendingInProgress';
                    $finishFetchMethod = 'fetchPendingFinished';
                }

                if ($type === 'encounter') {
                    $arrived = $db->$activeFetchMethod($dateFrom, $dateTo);
                    $inProgress = $db->$updateFetchMethod($dateFrom, $dateTo);
                    $finished = $db->$finishFetchMethod($dateFrom, $dateTo);

                    $arrived = array_values(array_filter($arrived, function($r) use ($noRawat) { return $r['no_rawat'] === $noRawat; }));
                    $inProgress = array_values(array_filter($inProgress, function($r) use ($noRawat) { return $r['no_rawat'] === $noRawat; }));
                    $finished = array_values(array_filter($finished, function($r) use ($noRawat) { return $r['no_rawat'] === $noRawat; }));

                    $stats = $processor->run($arrived, $inProgress, $finished);
                } else {
                    $active = $db->$activeFetchMethod($dateFrom, $dateTo);
                    $update = $db->$updateFetchMethod($dateFrom, $dateTo);

                    $active = array_values(array_filter($active, function($r) use ($noRawat) { return $r['no_rawat'] === $noRawat; }));
                    $update = array_values(array_filter($update, function($r) use ($noRawat) { return $r['no_rawat'] === $noRawat; }));

                    $stats = $processor->run($active, $update);
                }
            }
            $results[$name] = ['status' => 'processed', 'summary' => $stats];

        } catch (Exception $e) {
            $results[$name] = ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    return $results;
}

jsonResponse(['error' => 'Unknown action'], 404);
