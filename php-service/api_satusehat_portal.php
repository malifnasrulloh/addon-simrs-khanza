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

if ($action === 'getUnmappedEntities' && $method === 'GET') {
    if ($userRole !== 'admin') {
        jsonResponse(['success' => false, 'message' => 'Forbidden. Admin privileges required.'], 403);
    }

    $type = $_GET['type'] ?? 'location';
    $search = $_GET['search'] ?? '';
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    if ($page <= 0) $page = 1;
    if ($limit <= 0 || $limit > 100) $limit = 20;
    $offset = ($page - 1) * $limit;

    try {
        $sql = "";
        $countSql = "";
        $params = [];

        switch ($type) {
            case 'location':
                $sql = "
                    SELECT pol.kd_poli as `key`, pol.nm_poli as `name`, NULL as extra
                    FROM poliklinik pol
                    LEFT JOIN satu_sehat_mapping_lokasi_ralan smlr ON smlr.kd_poli = pol.kd_poli
                    WHERE smlr.id_lokasi_satusehat IS NULL OR smlr.id_lokasi_satusehat = ''
                ";
                $countSql = "
                    SELECT COUNT(*) as cnt
                    FROM poliklinik pol
                    LEFT JOIN satu_sehat_mapping_lokasi_ralan smlr ON smlr.kd_poli = pol.kd_poli
                    WHERE smlr.id_lokasi_satusehat IS NULL OR smlr.id_lokasi_satusehat = ''
                ";
                if ($search) {
                    $sql .= " AND (pol.kd_poli LIKE :search OR pol.nm_poli LIKE :search)";
                    $countSql .= " AND (pol.kd_poli LIKE :search OR pol.nm_poli LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            case 'practitioner':
                $sql = "
                    SELECT peg.nik as `key`, peg.nama as `name`, peg.no_ktp as extra
                    FROM pegawai peg
                    LEFT JOIN satu_sehat_ihs_practitioner ssip ON ssip.nikpegawai = peg.nik
                    WHERE (ssip.ihspegawai IS NULL OR ssip.ihspegawai = '') AND peg.no_ktp REGEXP '^[0-9]{16}$'
                ";
                $countSql = "
                    SELECT COUNT(*) as cnt
                    FROM pegawai peg
                    LEFT JOIN satu_sehat_ihs_practitioner ssip ON ssip.nikpegawai = peg.nik
                    WHERE (ssip.ihspegawai IS NULL OR ssip.ihspegawai = '') AND peg.no_ktp REGEXP '^[0-9]{16}$'
                ";
                if ($search) {
                    $sql .= " AND (peg.nik LIKE :search OR peg.nama LIKE :search OR peg.no_ktp LIKE :search)";
                    $countSql .= " AND (peg.nik LIKE :search OR peg.nama LIKE :search OR peg.no_ktp LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            case 'medication':
                $sql = "
                    SELECT db.kode_brng as `key`, db.nama_brng as `name`, k.nm_kategori as extra
                    FROM databarang db
                    INNER JOIN kategori_barang k ON db.kode_kategori = k.kode_kategori
                    LEFT JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = db.kode_brng
                    WHERE ssmo.obat_code IS NULL OR ssmo.obat_code = ''
                ";
                $countSql = "
                    SELECT COUNT(*) as cnt
                    FROM databarang db
                    LEFT JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = db.kode_brng
                    WHERE ssmo.obat_code IS NULL OR ssmo.obat_code = ''
                ";
                if ($search) {
                    $sql .= " AND (db.kode_brng LIKE :search OR db.nama_brng LIKE :search)";
                    $countSql .= " AND (db.kode_brng LIKE :search OR db.nama_brng LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            case 'vaccine':
                $sql = "
                    SELECT db.kode_brng as `key`, db.nama_brng as `name`, NULL as extra
                    FROM databarang db
                    LEFT JOIN satu_sehat_mapping_vaksin smv ON smv.kode_brng = db.kode_brng
                    WHERE smv.vaksin_code IS NULL OR smv.vaksin_code = ''
                ";
                $countSql = "
                    SELECT COUNT(*) as cnt
                    FROM databarang db
                    LEFT JOIN satu_sehat_mapping_vaksin smv ON smv.kode_brng = db.kode_brng
                    WHERE smv.vaksin_code IS NULL OR smv.vaksin_code = ''
                ";
                if ($search) {
                    $sql .= " AND (db.kode_brng LIKE :search OR db.nama_brng LIKE :search)";
                    $countSql .= " AND (db.kode_brng LIKE :search OR db.nama_brng LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            default:
                jsonResponse(['success' => false, 'message' => 'Invalid mapping type'], 400);
        }

        // Get total count
        $countStmt = $pdo->prepare($countSql);
        foreach ($params as $k => $val) {
            $countStmt->bindValue($k, $val);
        }
        $countStmt->execute();
        $totalCount = (int)$countStmt->fetchColumn();

        // Get records
        $sql .= " LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $val) {
            $stmt->bindValue($k, $val);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse([
            'success' => true,
            'type' => $type,
            'total_count' => $totalCount,
            'page' => $page,
            'limit' => $limit,
            'records' => $records
        ]);

    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

if ($action === 'saveMapping' && $method === 'POST') {
    if ($userRole !== 'admin') {
        jsonResponse(['success' => false, 'message' => 'Forbidden. Admin privileges required.'], 403);
    }

    // Read POST payload
    $input = json_decode(file_get_contents('php://input'), true);
    $type = $input['type'] ?? '';
    $key = $input['key'] ?? '';
    $value = $input['value'] ?? '';

    if (!$type || !$key || !$value) {
        jsonResponse(['success' => false, 'message' => 'Missing required fields: type, key, value'], 400);
    }

    try {
        switch ($type) {
            case 'location':
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM satu_sehat_mapping_lokasi_ralan WHERE kd_poli = ?");
                $checkStmt->execute([$key]);
                if ((int)$checkStmt->fetchColumn() > 0) {
                    $stmt = $pdo->prepare("UPDATE satu_sehat_mapping_lokasi_ralan SET id_lokasi_satusehat = ? WHERE kd_poli = ?");
                    $stmt->execute([$value, $key]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO satu_sehat_mapping_lokasi_ralan (kd_poli, id_lokasi_satusehat) VALUES (?, ?)");
                    $stmt->execute([$key, $value]);
                }
                break;

            case 'practitioner':
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM satu_sehat_ihs_practitioner WHERE nikpegawai = ?");
                $checkStmt->execute([$key]);
                if ((int)$checkStmt->fetchColumn() > 0) {
                    $stmt = $pdo->prepare("UPDATE satu_sehat_ihs_practitioner SET ihspegawai = ? WHERE nikpegawai = ?");
                    $stmt->execute([$value, $key]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO satu_sehat_ihs_practitioner (nikpegawai, ihspegawai) VALUES (?, ?)");
                    $stmt->execute([$key, $value]);
                }
                break;

            case 'medication':
                $nameStmt = $pdo->prepare("SELECT nama_brng FROM databarang WHERE kode_brng = ?");
                $nameStmt->execute([$key]);
                $localName = $nameStmt->fetchColumn() ?: 'Obat';

                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM satu_sehat_mapping_obat WHERE kode_brng = ?");
                $checkStmt->execute([$key]);
                if ((int)$checkStmt->fetchColumn() > 0) {
                    $stmt = $pdo->prepare("UPDATE satu_sehat_mapping_obat SET obat_code = ?, obat_display = ? WHERE kode_brng = ?");
                    $stmt->execute([$value, $localName, $key]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO satu_sehat_mapping_obat (kode_brng, obat_code, obat_display, obat_system, form_code, form_system, form_display) VALUES (?, ?, ?, 'http://sys-ids.kemkes.go.id/medication/', '00000000', 'http://terminology.kemkes.go.id/CodeSystem/medication-form', 'Tablet/Kapsul')");
                    $stmt->execute([$key, $value, $localName]);
                }
                break;

            case 'vaccine':
                $nameStmt = $pdo->prepare("SELECT nama_brng FROM databarang WHERE kode_brng = ?");
                $nameStmt->execute([$key]);
                $brngName = $nameStmt->fetchColumn() ?: 'Vaksin';

                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM satu_sehat_mapping_vaksin WHERE kode_brng = ?");
                $checkStmt->execute([$key]);
                if ((int)$checkStmt->fetchColumn() > 0) {
                    $stmt = $pdo->prepare("UPDATE satu_sehat_mapping_vaksin SET vaksin_code = ?, vaksin_system = 'http://sys-ids.kemkes.go.id/kfa' WHERE kode_brng = ?");
                    $stmt->execute([$value, $key]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO satu_sehat_mapping_vaksin (kode_brng, vaksin_code, vaksin_display, vaksin_system) VALUES (?, ?, ?, 'http://sys-ids.kemkes.go.id/kfa')");
                    $stmt->execute([$key, $value, $brngName]);
                }
                break;

            default:
                jsonResponse(['success' => false, 'message' => 'Invalid mapping type'], 400);
        }

        jsonResponse(['success' => true, 'message' => 'Mapping successfully persisted']);

    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
    }
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

            case 'clinicalimpression':
                $stmtTotal = $pdo->prepare("
                    SELECT 
                        (SELECT COUNT(*) FROM pemeriksaan_ralan pem INNER JOIN reg_periksa rp ON pem.no_rawat = rp.no_rawat WHERE {$timeFilter} AND pem.penilaian <> '') +
                        (SELECT COUNT(*) FROM pemeriksaan_ranap pem INNER JOIN reg_periksa rp ON pem.no_rawat = rp.no_rawat WHERE {$timeFilter} AND pem.penilaian <> '') as cnt
                ");
                $stmtTotal->execute($params);
                $total = (int)$stmtTotal->fetchColumn();

                $stmtBlocked = $pdo->prepare("
                    SELECT 
                        (SELECT COUNT(*) FROM pemeriksaan_ralan pem INNER JOIN reg_periksa rp ON pem.no_rawat = rp.no_rawat LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat WHERE {$timeFilter} AND pem.penilaian <> '' AND sse.id_encounter IS NULL) +
                        (SELECT COUNT(*) FROM pemeriksaan_ranap pem INNER JOIN reg_periksa rp ON pem.no_rawat = rp.no_rawat LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat WHERE {$timeFilter} AND pem.penilaian <> '' AND sse.id_encounter IS NULL) as cnt
                ");
                $stmtBlocked->execute($params);
                $blocked = (int)$stmtBlocked->fetchColumn();

                $stmtSynced = $pdo->prepare("
                    SELECT COUNT(*) FROM satu_sehat_clinicalimpression ssci
                    INNER JOIN reg_periksa rp ON ssci.no_rawat = rp.no_rawat
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

            case 'servicerequest_rad':
                $stmtTotal = $pdo->prepare("
                    SELECT COUNT(*) FROM permintaan_pemeriksaan_radiologi ppr
                    INNER JOIN permintaan_radiologi pr ON ppr.noorder = pr.noorder
                    INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
                    WHERE {$timeFilter}
                ");
                $stmtTotal->execute($params);
                $total = (int)$stmtTotal->fetchColumn();

                $stmtBlocked = $pdo->prepare("
                    SELECT COUNT(*) FROM permintaan_pemeriksaan_radiologi ppr
                    INNER JOIN permintaan_radiologi pr ON ppr.noorder = pr.noorder
                    INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_mapping_radiologi smr ON smr.kd_jenis_prw = ppr.kd_jenis_prw
                    WHERE {$timeFilter} AND (sse.id_encounter IS NULL OR smr.code IS NULL OR smr.code = '')
                ");
                $stmtBlocked->execute($params);
                $blocked = (int)$stmtBlocked->fetchColumn();

                $stmtSynced = $pdo->prepare("
                    SELECT COUNT(*) FROM satu_sehat_servicerequest_radiologi ssr
                    INNER JOIN permintaan_radiologi pr ON ssr.noorder = pr.noorder
                    INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
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

            case 'specimen_rad':
                $stmtTotal = $pdo->prepare("
                    SELECT COUNT(*) FROM permintaan_pemeriksaan_radiologi ppr
                    INNER JOIN permintaan_radiologi pr ON ppr.noorder = pr.noorder
                    INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
                    WHERE {$timeFilter}
                ");
                $stmtTotal->execute($params);
                $total = (int)$stmtTotal->fetchColumn();

                $stmtBlocked = $pdo->prepare("
                    SELECT COUNT(*) FROM permintaan_pemeriksaan_radiologi ppr
                    INNER JOIN permintaan_radiologi pr ON ppr.noorder = pr.noorder
                    INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_servicerequest_radiologi ssr ON ssr.noorder = ppr.noorder AND ssr.kd_jenis_prw = ppr.kd_jenis_prw
                    WHERE {$timeFilter} AND (ssr.id_servicerequest IS NULL OR ssr.id_servicerequest = '')
                ");
                $stmtBlocked->execute($params);
                $blocked = (int)$stmtBlocked->fetchColumn();

                $stmtSynced = $pdo->prepare("
                    SELECT COUNT(*) FROM satu_sehat_specimen_radiologi sssp
                    INNER JOIN permintaan_radiologi pr ON sssp.noorder = pr.noorder
                    INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
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

            case 'observation_rad':
                $stmtTotal = $pdo->prepare("
                    SELECT COUNT(*) FROM periksa_radiologi prad
                    INNER JOIN reg_periksa rp ON prad.no_rawat = rp.no_rawat
                    INNER JOIN hasil_radiologi hr ON prad.no_rawat = hr.no_rawat AND prad.tgl_periksa = hr.tgl_periksa AND prad.jam = hr.jam
                    WHERE {$timeFilter}
                ");
                $stmtTotal->execute($params);
                $total = (int)$stmtTotal->fetchColumn();

                $stmtBlocked = $pdo->prepare("
                    SELECT COUNT(*) FROM periksa_radiologi prad
                    INNER JOIN reg_periksa rp ON prad.no_rawat = rp.no_rawat
                    INNER JOIN hasil_radiologi hr ON prad.no_rawat = hr.no_rawat AND prad.tgl_periksa = hr.tgl_periksa AND prad.jam = hr.jam
                    INNER JOIN permintaan_radiologi pr ON pr.no_rawat = rp.no_rawat AND pr.tgl_hasil = prad.tgl_periksa AND pr.jam_hasil = prad.jam
                    INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
                    LEFT JOIN satu_sehat_specimen_radiologi sssp ON sssp.noorder = ppr.noorder AND sssp.kd_jenis_prw = ppr.kd_jenis_prw
                    LEFT JOIN satu_sehat_imagingstudy_radiologi ssi ON ssi.noorder = ppr.noorder AND ssi.kd_jenis_prw = ppr.kd_jenis_prw
                    WHERE {$timeFilter} AND (sssp.id_specimen IS NULL OR ssi.id_imaging IS NULL)
                ");
                $stmtBlocked->execute($params);
                $blocked = (int)$stmtBlocked->fetchColumn();

                $stmtSynced = $pdo->prepare("
                    SELECT COUNT(*) FROM satu_sehat_observation_radiologi sso
                    INNER JOIN permintaan_radiologi pr ON sso.noorder = pr.noorder
                    INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
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

            case 'diagnosticreport_rad':
                $stmtTotal = $pdo->prepare("
                    SELECT COUNT(*) FROM periksa_radiologi prad
                    INNER JOIN reg_periksa rp ON prad.no_rawat = rp.no_rawat
                    INNER JOIN hasil_radiologi hr ON prad.no_rawat = hr.no_rawat AND prad.tgl_periksa = hr.tgl_periksa AND prad.jam = hr.jam
                    WHERE {$timeFilter}
                ");
                $stmtTotal->execute($params);
                $total = (int)$stmtTotal->fetchColumn();

                $stmtBlocked = $pdo->prepare("
                    SELECT COUNT(*) FROM periksa_radiologi prad
                    INNER JOIN reg_periksa rp ON prad.no_rawat = rp.no_rawat
                    INNER JOIN hasil_radiologi hr ON prad.no_rawat = hr.no_rawat AND prad.tgl_periksa = hr.tgl_periksa AND prad.jam = hr.jam
                    INNER JOIN permintaan_radiologi pr ON pr.no_rawat = rp.no_rawat AND pr.tgl_hasil = prad.tgl_periksa AND pr.jam_hasil = prad.jam
                    INNER JOIN permintaan_pemeriksaan_radiologi ppr ON ppr.noorder = pr.noorder
                    LEFT JOIN satu_sehat_observation_radiologi sso ON sso.noorder = ppr.noorder AND sso.kd_jenis_prw = ppr.kd_jenis_prw
                    WHERE {$timeFilter} AND sso.id_observation IS NULL
                ");
                $stmtBlocked->execute($params);
                $blocked = (int)$stmtBlocked->fetchColumn();

                $stmtSynced = $pdo->prepare("
                    SELECT COUNT(*) FROM satu_sehat_diagnosticreport_radiologi ssdr
                    INNER JOIN permintaan_radiologi pr ON ssdr.noorder = ssdr.noorder AND ssdr.kd_jenis_prw = ssdr.kd_jenis_prw
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

            case 'servicerequest_lab_pk':
                $stmtTotal = $pdo->prepare("
                    SELECT COUNT(*) FROM permintaan_detail_permintaan_lab pdpl
                    INNER JOIN permintaan_lab pl ON pdpl.noorder = pl.noorder
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                    WHERE {$timeFilter}
                ");
                $stmtTotal->execute($params);
                $total = (int)$stmtTotal->fetchColumn();

                $stmtBlocked = $pdo->prepare("
                    SELECT COUNT(*) FROM permintaan_detail_permintaan_lab pdpl
                    INNER JOIN permintaan_lab pl ON pdpl.noorder = pl.noorder
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_mapping_lab sml ON sml.id_template = pdpl.id_template
                    WHERE {$timeFilter} AND (sse.id_encounter IS NULL OR sml.code IS NULL OR sml.code = '')
                ");
                $stmtBlocked->execute($params);
                $blocked = (int)$stmtBlocked->fetchColumn();

                $stmtSynced = $pdo->prepare("
                    SELECT COUNT(*) FROM satu_sehat_servicerequest_lab ssr
                    INNER JOIN permintaan_lab pl ON ssr.noorder = pl.noorder
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
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

            case 'specimen_lab_pk':
                $stmtTotal = $pdo->prepare("
                    SELECT COUNT(*) FROM permintaan_detail_permintaan_lab pdpl
                    INNER JOIN permintaan_lab pl ON pdpl.noorder = pl.noorder
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                    WHERE {$timeFilter}
                ");
                $stmtTotal->execute($params);
                $total = (int)$stmtTotal->fetchColumn();

                $stmtBlocked = $pdo->prepare("
                    SELECT COUNT(*) FROM permintaan_detail_permintaan_lab pdpl
                    INNER JOIN permintaan_lab pl ON pdpl.noorder = pl.noorder
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_servicerequest_lab ssr ON ssr.noorder = pdpl.noorder AND ssr.kd_jenis_prw = pdpl.kd_jenis_prw AND ssr.id_template = pdpl.id_template
                    WHERE {$timeFilter} AND (ssr.id_servicerequest IS NULL OR ssr.id_servicerequest = '')
                ");
                $stmtBlocked->execute($params);
                $blocked = (int)$stmtBlocked->fetchColumn();

                $stmtSynced = $pdo->prepare("
                    SELECT COUNT(*) FROM satu_sehat_specimen_lab sssp
                    INNER JOIN permintaan_lab pl ON sssp.noorder = pl.noorder
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
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

            case 'observation_lab_pk':
                $stmtTotal = $pdo->prepare("
                    SELECT COUNT(*) FROM detail_periksa_lab dpl
                    INNER JOIN periksa_lab pl ON dpl.no_rawat = pl.no_rawat AND dpl.tgl_periksa = pl.tgl_periksa AND dpl.jam = pl.jam
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                    WHERE {$timeFilter}
                ");
                $stmtTotal->execute($params);
                $total = (int)$stmtTotal->fetchColumn();

                $stmtBlocked = $pdo->prepare("
                    SELECT COUNT(*) FROM detail_periksa_lab dpl
                    INNER JOIN periksa_lab pl ON dpl.no_rawat = pl.no_rawat AND dpl.tgl_periksa = pl.tgl_periksa AND dpl.jam = pl.jam
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                    INNER JOIN permintaan_lab pr ON pr.no_rawat = rp.no_rawat AND pr.tgl_hasil = pl.tgl_periksa AND pr.jam_hasil = pl.jam
                    INNER JOIN permintaan_detail_permintaan_lab pdpl ON pdpl.noorder = pr.noorder AND pdpl.kd_jenis_prw = dpl.kd_jenis_prw AND pdpl.id_template = dpl.id_template
                    LEFT JOIN satu_sehat_specimen_lab sssp ON sssp.noorder = pdpl.noorder AND sssp.kd_jenis_prw = pdpl.kd_jenis_prw AND sssp.id_template = pdpl.id_template
                    WHERE {$timeFilter} AND sssp.id_specimen IS NULL
                ");
                $stmtBlocked->execute($params);
                $blocked = (int)$stmtBlocked->fetchColumn();

                $stmtSynced = $pdo->prepare("
                    SELECT COUNT(*) FROM satu_sehat_observation_lab sso
                    INNER JOIN permintaan_lab pl ON sso.noorder = pl.noorder
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
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

            case 'diagnosticreport_lab_pk':
                $stmtTotal = $pdo->prepare("
                    SELECT COUNT(*) FROM detail_periksa_lab dpl
                    INNER JOIN periksa_lab pl ON dpl.no_rawat = pl.no_rawat AND dpl.tgl_periksa = pl.tgl_periksa AND dpl.jam = pl.jam
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                    WHERE {$timeFilter}
                ");
                $stmtTotal->execute($params);
                $total = (int)$stmtTotal->fetchColumn();

                $stmtBlocked = $pdo->prepare("
                    SELECT COUNT(*) FROM detail_periksa_lab dpl
                    INNER JOIN periksa_lab pl ON dpl.no_rawat = pl.no_rawat AND dpl.tgl_periksa = pl.tgl_periksa AND dpl.jam = pl.jam
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                    INNER JOIN permintaan_lab pr ON pr.no_rawat = rp.no_rawat AND pr.tgl_hasil = pl.tgl_periksa AND pr.jam_hasil = pl.jam
                    INNER JOIN permintaan_detail_permintaan_lab pdpl ON pdpl.noorder = pr.noorder AND pdpl.kd_jenis_prw = dpl.kd_jenis_prw AND pdpl.id_template = dpl.id_template
                    LEFT JOIN satu_sehat_observation_lab sso ON sso.noorder = pdpl.noorder AND sso.kd_jenis_prw = pdpl.kd_jenis_prw AND sso.id_template = pdpl.id_template
                    WHERE {$timeFilter} AND sso.id_observation IS NULL
                ");
                $stmtBlocked->execute($params);
                $blocked = (int)$stmtBlocked->fetchColumn();

                $stmtSynced = $pdo->prepare("
                    SELECT COUNT(*) FROM satu_sehat_diagnosticreport_lab ssdr
                    INNER JOIN permintaan_lab pl ON ssdr.noorder = pl.noorder
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
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

            case 'servicerequest_lab_mb':
                $stmtTotal = $pdo->prepare("
                    SELECT COUNT(*) FROM permintaan_detail_permintaan_labmb pdpl
                    INNER JOIN permintaan_labmb pl ON pdpl.noorder = pl.noorder
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                    WHERE {$timeFilter}
                ");
                $stmtTotal->execute($params);
                $total = (int)$stmtTotal->fetchColumn();

                $stmtBlocked = $pdo->prepare("
                    SELECT COUNT(*) FROM permintaan_detail_permintaan_labmb pdpl
                    INNER JOIN permintaan_labmb pl ON pdpl.noorder = pl.noorder
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_mapping_lab sml ON sml.id_template = pdpl.id_template
                    WHERE {$timeFilter} AND (sse.id_encounter IS NULL OR sml.code IS NULL OR sml.code = '')
                ");
                $stmtBlocked->execute($params);
                $blocked = (int)$stmtBlocked->fetchColumn();

                $stmtSynced = $pdo->prepare("
                    SELECT COUNT(*) FROM satu_sehat_servicerequest_lab_mb ssr
                    INNER JOIN permintaan_labmb pl ON ssr.noorder = pl.noorder
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
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

            case 'specimen_lab_mb':
                $stmtTotal = $pdo->prepare("
                    SELECT COUNT(*) FROM permintaan_detail_permintaan_labmb pdpl
                    INNER JOIN permintaan_labmb pl ON pdpl.noorder = pl.noorder
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                    WHERE {$timeFilter}
                ");
                $stmtTotal->execute($params);
                $total = (int)$stmtTotal->fetchColumn();

                $stmtBlocked = $pdo->prepare("
                    SELECT COUNT(*) FROM permintaan_detail_permintaan_labmb pdpl
                    INNER JOIN permintaan_labmb pl ON pdpl.noorder = pl.noorder
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_servicerequest_lab_mb ssr ON ssr.noorder = pdpl.noorder AND ssr.kd_jenis_prw = pdpl.kd_jenis_prw AND ssr.id_template = pdpl.id_template
                    WHERE {$timeFilter} AND (ssr.id_servicerequest IS NULL OR ssr.id_servicerequest = '')
                ");
                $stmtBlocked->execute($params);
                $blocked = (int)$stmtBlocked->fetchColumn();

                $stmtSynced = $pdo->prepare("
                    SELECT COUNT(*) FROM satu_sehat_specimen_lab_mb sssp
                    INNER JOIN permintaan_labmb pl ON sssp.noorder = pl.noorder
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
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

            case 'observation_lab_mb':
                $stmtTotal = $pdo->prepare("
                    SELECT COUNT(*) FROM detail_periksa_lab dpl
                    INNER JOIN periksa_lab pl ON dpl.no_rawat = pl.no_rawat AND dpl.tgl_periksa = pl.tgl_periksa AND dpl.jam = pl.jam
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                    WHERE {$timeFilter}
                ");
                $stmtTotal->execute($params);
                $total = (int)$stmtTotal->fetchColumn();

                $stmtBlocked = $pdo->prepare("
                    SELECT COUNT(*) FROM detail_periksa_lab dpl
                    INNER JOIN periksa_lab pl ON dpl.no_rawat = pl.no_rawat AND dpl.tgl_periksa = pl.tgl_periksa AND dpl.jam = pl.jam
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                    INNER JOIN permintaan_labmb pr ON pr.no_rawat = rp.no_rawat AND pr.tgl_hasil = pl.tgl_periksa AND pr.jam_hasil = pl.jam
                    INNER JOIN permintaan_detail_permintaan_labmb pdpl ON pdpl.noorder = pr.noorder AND pdpl.kd_jenis_prw = dpl.kd_jenis_prw AND pdpl.id_template = dpl.id_template
                    LEFT JOIN satu_sehat_specimen_lab_mb sssp ON sssp.noorder = pdpl.noorder AND sssp.kd_jenis_prw = pdpl.kd_jenis_prw AND sssp.id_template = pdpl.id_template
                    WHERE {$timeFilter} AND sssp.id_specimen IS NULL
                ");
                $stmtBlocked->execute($params);
                $blocked = (int)$stmtBlocked->fetchColumn();

                $stmtSynced = $pdo->prepare("
                    SELECT COUNT(*) FROM satu_sehat_observation_lab_mb sso
                    INNER JOIN permintaan_labmb pl ON sso.noorder = pl.noorder
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
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

            case 'diagnosticreport_lab_mb':
                $stmtTotal = $pdo->prepare("
                    SELECT COUNT(*) FROM detail_periksa_lab dpl
                    INNER JOIN periksa_lab pl ON dpl.no_rawat = pl.no_rawat AND dpl.tgl_periksa = pl.tgl_periksa AND dpl.jam = pl.jam
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                    WHERE {$timeFilter}
                ");
                $stmtTotal->execute($params);
                $total = (int)$stmtTotal->fetchColumn();

                $stmtBlocked = $pdo->prepare("
                    SELECT COUNT(*) FROM detail_periksa_lab dpl
                    INNER JOIN periksa_lab pl ON dpl.no_rawat = pl.no_rawat AND dpl.tgl_periksa = pl.tgl_periksa AND dpl.jam = pl.jam
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                    INNER JOIN permintaan_labmb pr ON pr.no_rawat = rp.no_rawat AND pr.tgl_hasil = pl.tgl_periksa AND pr.jam_hasil = pl.jam
                    INNER JOIN permintaan_detail_permintaan_labmb pdpl ON pdpl.noorder = pr.noorder AND pdpl.kd_jenis_prw = dpl.kd_jenis_prw AND pdpl.id_template = dpl.id_template
                    LEFT JOIN satu_sehat_observation_lab_mb sso ON sso.noorder = pdpl.noorder AND sso.kd_jenis_prw = pdpl.kd_jenis_prw AND sso.id_template = pdpl.id_template
                    WHERE {$timeFilter} AND sso.id_observation IS NULL
                ");
                $stmtBlocked->execute($params);
                $blocked = (int)$stmtBlocked->fetchColumn();

                $stmtSynced = $pdo->prepare("
                    SELECT COUNT(*) FROM satu_sehat_diagnosticreport_lab_mb ssdr
                    INNER JOIN permintaan_labmb pl ON ssdr.noorder = pl.noorder
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
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

if ($action === 'lookupPractitionerSatuSehat' && $method === 'GET') {
    if ($userRole !== 'admin') {
        jsonResponse(['success' => false, 'message' => 'Forbidden. Admin privileges required.'], 403);
    }
    $nik = $_GET['nik'] ?? '';
    if (empty($nik)) {
        jsonResponse(['success' => false, 'message' => 'Parameter NIK is required.'], 400);
    }
    $res = $client->get("/Practitioner?identifier=https://fhir.kemkes.go.id/id/nik|{$nik}");
    if ($res['success'] && !empty($res['data']['entry'])) {
        $entry = $res['data']['entry'][0]['resource'];
        jsonResponse([
            'success' => true,
            'id' => $entry['id'],
            'name' => $entry['name'][0]['text'] ?? ''
        ]);
    } else {
        jsonResponse([
            'success' => false,
            'message' => 'Practitioner NIK ' . $nik . ' not found in SatuSehat.'
        ]);
    }
}

if ($action === 'querySatuSehatResource' && $method === 'GET') {
    if ($userRole !== 'admin') {
        jsonResponse(['success' => false, 'message' => 'Forbidden. Admin privileges required.'], 403);
    }
    $endpoint = $_GET['endpoint'] ?? '';
    if (empty($endpoint)) {
        jsonResponse(['success' => false, 'message' => 'Parameter endpoint is required.'], 400);
    }
    if ($endpoint[0] !== '/') {
        $endpoint = '/' . $endpoint;
    }
    $res = $client->get($endpoint);
    if ($res['success']) {
        jsonResponse([
            'success' => true,
            'code' => $res['code'],
            'data' => $res['data']
        ]);
    } else {
        jsonResponse([
            'success' => false,
            'code' => $res['code'],
            'message' => $res['message'] ?? 'API query failed',
            'data' => $res['data'] ?? null
        ]);
    }
}

if ($action === 'previewFHIRPayload' && $method === 'GET') {
    if ($userRole !== 'admin') {
        jsonResponse(['success' => false, 'message' => 'Forbidden. Admin privileges required.'], 403);
    }
    $resource = strtolower($_GET['resource'] ?? '');
    $key = $_GET['key'] ?? '';
    if (empty($resource) || empty($key)) {
        jsonResponse(['success' => false, 'message' => 'Parameters resource and key are required.'], 400);
    }

    try {
        require_once BASE_DIR . '/lib/satusehat/Database.php';
        require_once BASE_DIR . '/lib/satusehat/PayloadBuilder.php';
        $db = new SatuSehatDatabase($config, $log, $client);
        $payload = null;

        if ($resource === 'encounter') {
            $sql = "
                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, 
                    p.no_ktp, rp.kd_dokter, pg.nama, pg.no_ktp as ktpdokter, 
                    rp.kd_poli, pol.nm_poli, smlr.id_lokasi_satusehat, rp.stts, rp.status_lanjut,
                    sse.id_encounter
                FROM reg_periksa rp
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                INNER JOIN pegawai pg ON pg.nik = rp.kd_dokter
                INNER JOIN poliklinik pol ON rp.kd_poli = pol.kd_poli
                LEFT JOIN satu_sehat_mapping_lokasi_ralan smlr ON smlr.kd_poli = pol.kd_poli
                LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                WHERE rp.no_rawat = :nr
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['nr' => $key]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$p) jsonResponse(['success' => false, 'message' => 'Encounter record not found.'], 404);

            $idPasien = $db->getIhsPatient($p['no_ktp']);
            $idDokter = $db->getIhsPractitioner($p['ktpdokter']);

            $status = 'arrived';
            if ($p['id_encounter']) {
                $status = 'in-progress';
                $diagnoses = $db->fetchDiagnoses($key);
                if (!empty($diagnoses)) {
                    $status = 'finished';
                }
            }

            if ($status === 'finished') {
                $payload = SatuSehatPayloadBuilder::encounter($config->orgId, $p, $idPasien, $idDokter, 'finished', $diagnoses, $p['id_encounter']);
            } else if ($status === 'in-progress') {
                $payload = SatuSehatPayloadBuilder::encounter($config->orgId, $p, $idPasien, $idDokter, 'in-progress', [], $p['id_encounter']);
            } else {
                $payload = SatuSehatPayloadBuilder::encounter($config->orgId, $p, $idPasien, $idDokter, 'arrived');
            }
        } else if ($resource === 'condition') {
            $parts = explode('-', $key);
            $noRawat = $parts[0];
            $kdPenyakit = $parts[1] ?? '';
            
            $sql = "
                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, 
                    p.nm_pasien, p.no_ktp, rp.stts, rp.status_lanjut, 
                    CONCAT(rp.tgl_registrasi, ' ', rp.jam_reg) as pulang, 
                    sse.id_encounter, dp.kd_penyakit, py.nm_penyakit, dp.status,
                    ssc.id_condition
                FROM reg_periksa rp
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                INNER JOIN diagnosa_pasien dp ON dp.no_rawat = rp.no_rawat
                INNER JOIN penyakit py ON dp.kd_penyakit = py.kd_penyakit
                LEFT JOIN satu_sehat_condition ssc ON ssc.no_rawat = dp.no_rawat 
                    AND ssc.kd_penyakit = dp.kd_penyakit 
                    AND ssc.status = dp.status
                WHERE rp.no_rawat = :nr AND dp.kd_penyakit = :kd
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['nr' => $noRawat, 'kd' => $kdPenyakit]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$p) jsonResponse(['success' => false, 'message' => 'Condition record not found.'], 404);

            $idPasien = $db->getIhsPatient($p['no_ktp']);
            $payload = SatuSehatPayloadBuilder::condition($p, $idPasien, $p['id_condition'] ?? '');
        } else if ($resource === 'procedure') {
            $parts = explode('-', $key);
            $noRawat = $parts[0];
            $kode = $parts[1] ?? '';

            $sql = "
                SELECT 
                    rp.tgl_registrasi, rp.jam_reg, rp.no_rawat, rp.no_rkm_medis, 
                    p.nm_pasien, p.no_ktp, rp.stts, rp.status_lanjut, 
                    CONCAT(rp.tgl_registrasi, 'T', rp.jam_reg, '+07:00') as waktu_registrasi, 
                    sse.id_encounter, pp.kode, py.deskripsi_panjang, pp.status,
                    CASE 
                        WHEN rp.status_lanjut = 'Ralan' THEN (SELECT CONCAT(tanggal, 'T', jam, '+07:00') FROM nota_jalan WHERE no_rawat = rp.no_rawat LIMIT 1)
                        WHEN rp.status_lanjut = 'Ranap' THEN (SELECT CONCAT(tanggal, 'T', jam, '+07:00') FROM nota_inap WHERE no_rawat = rp.no_rawat LIMIT 1)
                    END as waktu_pulang,
                    ssp.id_procedure
                FROM reg_periksa rp
                INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                INNER JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                INNER JOIN prosedur_pasien pp ON pp.no_rawat = rp.no_rawat
                INNER JOIN icd9 py ON pp.kode = py.kode
                LEFT JOIN satu_sehat_procedure ssp ON ssp.no_rawat = pp.no_rawat 
                    AND ssp.kode = pp.kode 
                    AND ssp.status = pp.status
                WHERE rp.no_rawat = :nr AND pp.kode = :kd
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['nr' => $noRawat, 'kd' => $kode]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$p) jsonResponse(['success' => false, 'message' => 'Procedure record not found.'], 404);

            $idPasien = $db->getIhsPatient($p['no_ktp']);
            $payload = SatuSehatPayloadBuilder::procedure($p, $idPasien, $p['id_procedure'] ?? '');
        } else {
            jsonResponse(['success' => false, 'message' => "Preview payload for resource '{$resource}' is not supported yet. You can still query this resource using the FHIR Explorer."], 400);
        }

        jsonResponse([
            'success' => true,
            'payload' => $payload
        ]);

    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

if ($action === 'syncFHIRPayloadOverride' && $method === 'POST') {
    if ($userRole !== 'admin') {
        jsonResponse(['success' => false, 'message' => 'Forbidden. Admin privileges required.'], 403);
    }

    $rawInput = file_get_contents('php://input');
    $inputData = json_decode($rawInput, true);
    
    $resource = strtolower($inputData['resource'] ?? '');
    $key = $inputData['key'] ?? '';
    $customPayload = $inputData['payload'] ?? null;

    if (empty($resource) || empty($key) || !$customPayload) {
        jsonResponse(['success' => false, 'message' => 'Parameters resource, key and payload JSON are required.'], 400);
    }

    try {
        require_once BASE_DIR . '/lib/satusehat/Database.php';
        $db = new SatuSehatDatabase($config, $log, $client);

        if (is_string($customPayload)) {
            $payloadObj = json_decode($customPayload, true);
        } else {
            $payloadObj = $customPayload;
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            jsonResponse(['success' => false, 'message' => 'Invalid JSON payload format: ' . json_last_error_msg()], 400);
        }

        $endpoint = '';
        $methodType = 'POST';
        $ihsId = $payloadObj['id'] ?? '';

        if (!empty($ihsId)) {
            $endpoint = '/' . ucfirst($resource) . '/' . $ihsId;
            $methodType = 'PUT';
            $res = $client->put($endpoint, $payloadObj);
        } else {
            $endpoint = '/' . ucfirst($resource);
            $res = $client->post($endpoint, $payloadObj);
        }

        if ($res['success'] && (isset($res['data']['id']) || $methodType === 'PUT')) {
            $savedId = $res['data']['id'] ?? $ihsId;

            if ($resource === 'encounter') {
                $db->saveEncounter($key, $savedId);
                $db->updateLocalState($key, 'finished');
            } else if ($resource === 'condition') {
                $parts = explode('-', $key);
                $noRawat = $parts[0];
                $kdPenyakit = $parts[1] ?? '';
                $status = $payloadObj['clinicalStatus']['coding'][0]['code'] ?? 'active';
                $db->saveCondition($noRawat, $kdPenyakit, $status, $savedId);
            } else if ($resource === 'procedure') {
                $parts = explode('-', $key);
                $noRawat = $parts[0];
                $kode = $parts[1] ?? '';
                $status = $payloadObj['status'] ?? 'completed';
                $db->saveProcedure($noRawat, $kode, $status, $savedId);
            }

            jsonResponse([
                'success' => true,
                'message' => "Successfully synchronized manual payload override.",
                'ihs_id' => $savedId,
                'data' => $res['data']
            ]);
        } else {
            $errorMsg = $res['data']['issue'][0]['diagnostics'] ?? $res['message'] ?? 'API sync failed';
            jsonResponse([
                'success' => false,
                'message' => 'SatuSehat API Error: ' . $errorMsg,
                'data' => $res['data'] ?? null
            ]);
        }

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
            'clinicalimpression' => ['class' => 'SatuSehatClinicalImpressionProcessor', 'file' => 'ClinicalImpressionProcessor.php', 'method_type' => 'clinicalimpression'],
            'servicerequest_rad' => ['class' => 'SatuSehatServiceRequestRadiologiProcessor', 'file' => 'ServiceRequestRadiologiProcessor.php', 'method_type' => 'servicerequest_rad'],
            'specimen_rad' => ['class' => 'SatuSehatSpecimenRadiologiProcessor', 'file' => 'SpecimenRadiologiProcessor.php', 'method_type' => 'specimen_rad'],
            'observation_rad' => ['class' => 'SatuSehatObservationRadiologiProcessor', 'file' => 'ObservationRadiologiProcessor.php', 'method_type' => 'observation_rad'],
            'diagnosticreport_rad' => ['class' => 'SatuSehatDiagnosticReportRadiologiProcessor', 'file' => 'DiagnosticReportRadiologiProcessor.php', 'method_type' => 'diagnosticreport_rad'],
            'servicerequest_lab_pk' => ['class' => 'SatuSehatServiceRequestLabPKProcessor', 'file' => 'ServiceRequestLabPKProcessor.php', 'method_type' => 'servicerequest_lab_pk'],
            'specimen_lab_pk' => ['class' => 'SatuSehatSpecimenLabPKProcessor', 'file' => 'SpecimenLabPKProcessor.php', 'method_type' => 'specimen_lab_pk'],
            'observation_lab_pk' => ['class' => 'SatuSehatObservationLabPKProcessor', 'file' => 'ObservationLabPKProcessor.php', 'method_type' => 'observation_lab_pk'],
            'diagnosticreport_lab_pk' => ['class' => 'SatuSehatDiagnosticReportLabPKProcessor', 'file' => 'DiagnosticReportLabPKProcessor.php', 'method_type' => 'diagnosticreport_lab_pk'],
            'servicerequest_lab_mb' => ['class' => 'SatuSehatServiceRequestLabMBProcessor', 'file' => 'ServiceRequestLabMBProcessor.php', 'method_type' => 'servicerequest_lab_mb'],
            'specimen_lab_mb' => ['class' => 'SatuSehatSpecimenLabMBProcessor', 'file' => 'SpecimenLabMBProcessor.php', 'method_type' => 'specimen_lab_mb'],
            'observation_lab_mb' => ['class' => 'SatuSehatObservationLabMBProcessor', 'file' => 'ObservationLabMBProcessor.php', 'method_type' => 'observation_lab_mb'],
            'diagnosticreport_lab_mb' => ['class' => 'SatuSehatDiagnosticReportLabMBProcessor', 'file' => 'DiagnosticReportLabMBProcessor.php', 'method_type' => 'diagnosticreport_lab_mb'],
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
            
            $customFetchMethods = [
                'clinicalimpression' => ['fetchPendingClinicalImpressionActive', 'fetchPendingClinicalImpressionUpdate'],
                'servicerequest_rad' => ['fetchPendingServiceRequestRadiologiActive', 'fetchPendingServiceRequestRadiologiUpdate'],
                'specimen_rad' => ['fetchPendingSpecimenRadiologiActive', 'fetchPendingSpecimenRadiologiUpdate'],
                'observation_rad' => ['fetchPendingObservationRadiologiActive', 'fetchPendingObservationRadiologiUpdate'],
                'diagnosticreport_rad' => ['fetchPendingDiagnosticReportRadiologiActive', 'fetchPendingDiagnosticReportRadiologiUpdate'],
                'servicerequest_lab_pk' => ['fetchPendingServiceRequestLabPKActive', 'fetchPendingServiceRequestLabPKUpdate'],
                'specimen_lab_pk' => ['fetchPendingSpecimenLabPKActive', 'fetchPendingSpecimenLabPKUpdate'],
                'observation_lab_pk' => ['fetchPendingObservationLabPKActive', 'fetchPendingObservationLabPKUpdate'],
                'diagnosticreport_lab_pk' => ['fetchPendingDiagnosticReportLabPKActive', 'fetchPendingDiagnosticReportLabPKUpdate'],
                'servicerequest_lab_mb' => ['fetchPendingServiceRequestLabMBActive', 'fetchPendingServiceRequestLabMBUpdate'],
                'specimen_lab_mb' => ['fetchPendingSpecimenLabMBActive', 'fetchPendingSpecimenLabMBUpdate'],
                'observation_lab_mb' => ['fetchPendingObservationLabMBActive', 'fetchPendingObservationLabMBUpdate'],
                'diagnosticreport_lab_mb' => ['fetchPendingDiagnosticReportLabMBActive', 'fetchPendingDiagnosticReportLabMBUpdate'],
            ];

            if (isset($customFetchMethods[$type])) {
                $activeFetchMethod = $customFetchMethods[$type][0];
                $updateFetchMethod = $customFetchMethods[$type][1];
            } else {
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
                            WHEN smlr.id_lokasi_satusehat IS NULL OR smlr.id_lokasi_satusehat = '' THEN 'blocked'
                            ELSE 'pending'
                          END) as status,
                          (CASE 
                            WHEN sse.id_encounter IS NOT NULL THEN NULL
                            WHEN rp.status_bayar = 'Belum Bayar' THEN 'Unpaid Registration'
                            WHEN smlr.id_lokasi_satusehat IS NULL OR smlr.id_lokasi_satusehat = '' THEN 'Unmapped Clinic Location'
                            ELSE NULL
                          END) as blocked_reason,
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
                        (CASE 
                            WHEN sseoc.id_episode_of_care IS NOT NULL THEN NULL
                            WHEN sse.id_encounter IS NULL THEN 'Encounter Not Synced Yet'
                            ELSE NULL
                         END) as blocked_reason,
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
                        (CASE 
                            WHEN ssc.id_condition IS NOT NULL THEN NULL
                            WHEN sse.id_encounter IS NULL THEN 'Encounter Not Synced Yet'
                            ELSE NULL
                         END) as blocked_reason,
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
                        (CASE 
                            WHEN sso.id_observation IS NOT NULL THEN NULL
                            WHEN sse.id_encounter IS NULL THEN 'Encounter Not Synced Yet'
                            ELSE NULL
                         END) as blocked_reason,
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
                        (CASE 
                            WHEN ssp.id_procedure IS NOT NULL THEN NULL
                            WHEN sse.id_encounter IS NULL THEN 'Encounter Not Synced Yet'
                            ELSE NULL
                         END) as blocked_reason,
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
                        (CASE 
                            WHEN ssai.id_allergy_intolerance IS NOT NULL THEN NULL
                            WHEN sse.id_encounter IS NULL THEN 'Encounter Not Synced Yet'
                            ELSE NULL
                         END) as blocked_reason,
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
                            WHEN smv.vaksin_code IS NULL OR smv.vaksin_code = '' THEN 'blocked'
                            ELSE 'pending'
                         END) as status,
                        (CASE 
                            WHEN ssi.id_immunization IS NOT NULL THEN NULL
                            WHEN sse.id_encounter IS NULL THEN 'Encounter Not Synced Yet'
                            WHEN smv.vaksin_code IS NULL OR smv.vaksin_code = '' THEN 'Unmapped Vaccine Code'
                            ELSE NULL
                         END) as blocked_reason,
                          ssi.id_immunization as ihs_id
                    FROM detail_pemberian_obat dpo
                    INNER JOIN reg_periksa rp ON dpo.no_rawat = rp.no_rawat
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    INNER JOIN databarang db ON dpo.kode_brng = db.kode_brng
                    LEFT JOIN satu_sehat_mapping_vaksin smv ON smv.kode_brng = dpo.kode_brng
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
                            WHEN ssmr.id_medicationrequest IS NOT NULL THEN 'synced'
                            WHEN sse.id_encounter IS NULL THEN 'blocked'
                            WHEN ssmo.obat_code IS NULL OR ssmo.obat_code = '' THEN 'blocked'
                            ELSE 'pending'
                         END) as status,
                        (CASE 
                            WHEN ssmr.id_medicationrequest IS NOT NULL THEN NULL
                            WHEN sse.id_encounter IS NULL THEN 'Encounter Not Synced Yet'
                            WHEN ssmo.obat_code IS NULL OR ssmo.obat_code = '' THEN 'Unmapped Medication Code'
                            ELSE NULL
                         END) as blocked_reason,
                          ssmr.id_medicationrequest as ihs_id
                    FROM detail_pemberian_obat dpo
                    INNER JOIN reg_periksa rp ON dpo.no_rawat = rp.no_rawat
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    INNER JOIN databarang db ON dpo.kode_brng = db.kode_brng
                    LEFT JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = dpo.kode_brng
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    LEFT JOIN resep_obat ro ON ro.no_rawat = dpo.no_rawat
                    LEFT JOIN satu_sehat_medicationrequest ssmr ON ssmr.no_resep = ro.no_resep AND ssmr.kode_brng = dpo.kode_brng
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
                            WHEN ssmd.id_medicationdispanse IS NOT NULL THEN 'synced'
                            WHEN sse.id_encounter IS NULL THEN 'blocked'
                            WHEN ssmo.obat_code IS NULL OR ssmo.obat_code = '' THEN 'blocked'
                            ELSE 'pending'
                         END) as status,
                        (CASE 
                            WHEN ssmd.id_medicationdispanse IS NOT NULL THEN NULL
                            WHEN sse.id_encounter IS NULL THEN 'Encounter Not Synced Yet'
                            WHEN ssmo.obat_code IS NULL OR ssmo.obat_code = '' THEN 'Unmapped Medication Code'
                            ELSE NULL
                         END) as blocked_reason,
                          ssmd.id_medicationdispanse as ihs_id
                    FROM detail_pemberian_obat dpo
                    INNER JOIN reg_periksa rp ON dpo.no_rawat = rp.no_rawat
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    INNER JOIN databarang db ON dpo.kode_brng = db.kode_brng
                    LEFT JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = dpo.kode_brng
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_medicationdispense ssmd ON ssmd.no_rawat = dpo.no_rawat 
                        AND ssmd.kode_brng = dpo.kode_brng 
                        AND ssmd.tgl_perawatan = dpo.tgl_perawatan
                        AND ssmd.jam = dpo.jam
                        AND ssmd.no_batch = dpo.no_batch
                        AND ssmd.no_faktur = dpo.no_faktur
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
                            WHEN ssms.id_medicationstatement IS NOT NULL THEN 'synced'
                            WHEN sse.id_encounter IS NULL THEN 'blocked'
                            WHEN ssmo.obat_code IS NULL OR ssmo.obat_code = '' THEN 'blocked'
                            ELSE 'pending'
                         END) as status,
                        (CASE 
                            WHEN ssms.id_medicationstatement IS NOT NULL THEN NULL
                            WHEN sse.id_encounter IS NULL THEN 'Encounter Not Synced Yet'
                            WHEN ssmo.obat_code IS NULL OR ssmo.obat_code = '' THEN 'Unmapped Medication Code'
                            ELSE NULL
                         END) as blocked_reason,
                          ssms.id_medicationstatement as ihs_id
                    FROM detail_pemberian_obat dpo
                    INNER JOIN reg_periksa rp ON dpo.no_rawat = rp.no_rawat
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    INNER JOIN databarang db ON dpo.kode_brng = db.kode_brng
                    LEFT JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = dpo.kode_brng
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    LEFT JOIN resep_obat ro ON ro.no_rawat = dpo.no_rawat
                    LEFT JOIN satu_sehat_medicationstatement ssms ON ssms.no_resep = ro.no_resep AND ssms.kode_brng = dpo.kode_brng
                    WHERE dpo.tgl_perawatan BETWEEN :df AND :dt
                ";
                $params['df'] = $dateFrom;
                $params['dt'] = $dateTo;
                if ($search) {
                    $sql .= " AND (p.nm_pasien LIKE :search OR dpo.no_rawat LIKE :search OR rp.no_rkm_medis LIKE :search OR db.nama_brng LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            case 'clinicalimpression':
                $sql = "
                    SELECT 
                        CONCAT(pem.no_rawat, '-', pem.tgl_perawatan, '-', pem.jam_rawat, '-', pem.status_lanjut) as id,
                        pem.no_rawat,
                        rp.no_rkm_medis as rm,
                        p.nm_pasien as patient_name,
                        p.no_ktp as nik,
                        pem.tgl_perawatan as date,
                        pem.penilaian as details,
                        (CASE 
                            WHEN ssci.id_clinicalimpression IS NOT NULL THEN 'synced'
                            WHEN sse.id_encounter IS NULL THEN 'blocked'
                            ELSE 'pending'
                         END) as status,
                        (CASE 
                            WHEN ssci.id_clinicalimpression IS NOT NULL THEN NULL
                            WHEN sse.id_encounter IS NULL THEN 'Encounter Not Synced Yet'
                            ELSE NULL
                         END) as blocked_reason,
                         ssci.id_clinicalimpression as ihs_id
                    FROM (
                        SELECT no_rawat, tgl_perawatan, jam_rawat, penilaian, 'Ralan' as status_lanjut FROM pemeriksaan_ralan WHERE penilaian <> ''
                        UNION ALL
                        SELECT no_rawat, tgl_perawatan, jam_rawat, penilaian, 'Ranap' as status_lanjut FROM pemeriksaan_ranap WHERE penilaian <> ''
                    ) pem
                    INNER JOIN reg_periksa rp ON pem.no_rawat = rp.no_rawat
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_clinicalimpression ssci ON ssci.no_rawat = pem.no_rawat 
                        AND ssci.tgl_perawatan = pem.tgl_perawatan 
                        AND ssci.jam_rawat = pem.jam_rawat 
                        AND ssci.status = pem.status_lanjut
                    WHERE rp.tgl_registrasi BETWEEN :df AND :dt
                ";
                $params['df'] = $dateFrom;
                $params['dt'] = $dateTo;
                if ($search) {
                    $sql .= " AND (p.nm_pasien LIKE :search OR pem.no_rawat LIKE :search OR rp.no_rkm_medis LIKE :search OR pem.penilaian LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            case 'servicerequest_rad':
                $sql = "
                    SELECT 
                        CONCAT(ppr.noorder, '-', ppr.kd_jenis_prw) as id,
                        pr.no_rawat,
                        rp.no_rkm_medis as rm,
                        p.nm_pasien as patient_name,
                        p.no_ktp as nik,
                        pr.tgl_permintaan as date,
                        jpr.nm_perawatan as details,
                        (CASE 
                            WHEN ssr.id_servicerequest IS NOT NULL THEN 'synced'
                            WHEN sse.id_encounter IS NULL THEN 'blocked'
                            WHEN smr.code IS NULL OR smr.code = '' THEN 'blocked'
                            ELSE 'pending'
                         END) as status,
                        (CASE 
                            WHEN ssr.id_servicerequest IS NOT NULL THEN NULL
                            WHEN sse.id_encounter IS NULL THEN 'Encounter Not Synced Yet'
                            WHEN smr.code IS NULL OR smr.code = '' THEN 'Unmapped Radiology Code'
                            ELSE NULL
                         END) as blocked_reason,
                         ssr.id_servicerequest as ihs_id
                    FROM permintaan_pemeriksaan_radiologi ppr
                    INNER JOIN permintaan_radiologi pr ON ppr.noorder = pr.noorder
                    INNER JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = ppr.kd_jenis_prw
                    INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_mapping_radiologi smr ON smr.kd_jenis_prw = ppr.kd_jenis_prw
                    LEFT JOIN satu_sehat_servicerequest_radiologi ssr ON ssr.noorder = ppr.noorder AND ssr.kd_jenis_prw = ppr.kd_jenis_prw
                    WHERE pr.tgl_permintaan BETWEEN :df AND :dt
                ";
                $params['df'] = $dateFrom;
                $params['dt'] = $dateTo;
                if ($search) {
                    $sql .= " AND (p.nm_pasien LIKE :search OR pr.no_rawat LIKE :search OR rp.no_rkm_medis LIKE :search OR jpr.nm_perawatan LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            case 'specimen_rad':
                $sql = "
                    SELECT 
                        CONCAT(ppr.noorder, '-', ppr.kd_jenis_prw) as id,
                        pr.no_rawat,
                        rp.no_rkm_medis as rm,
                        p.nm_pasien as patient_name,
                        p.no_ktp as nik,
                        pr.tgl_permintaan as date,
                        jpr.nm_perawatan as details,
                        (CASE 
                            WHEN sssp.id_specimen IS NOT NULL THEN 'synced'
                            WHEN ssr.id_servicerequest IS NULL OR ssr.id_servicerequest = '' THEN 'blocked'
                            ELSE 'pending'
                         END) as status,
                        (CASE 
                            WHEN sssp.id_specimen IS NOT NULL THEN NULL
                            WHEN ssr.id_servicerequest IS NULL OR ssr.id_servicerequest = '' THEN 'Service Request Not Synced Yet'
                            ELSE NULL
                         END) as blocked_reason,
                         sssp.id_specimen as ihs_id
                    FROM permintaan_pemeriksaan_radiologi ppr
                    INNER JOIN permintaan_radiologi pr ON ppr.noorder = pr.noorder
                    INNER JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = ppr.kd_jenis_prw
                    INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    LEFT JOIN satu_sehat_servicerequest_radiologi ssr ON ssr.noorder = ppr.noorder AND ssr.kd_jenis_prw = ppr.kd_jenis_prw
                    LEFT JOIN satu_sehat_specimen_radiologi sssp ON sssp.noorder = ppr.noorder AND sssp.kd_jenis_prw = ppr.kd_jenis_prw
                    WHERE pr.tgl_permintaan BETWEEN :df AND :dt
                ";
                $params['df'] = $dateFrom;
                $params['dt'] = $dateTo;
                if ($search) {
                    $sql .= " AND (p.nm_pasien LIKE :search OR pr.no_rawat LIKE :search OR rp.no_rkm_medis LIKE :search OR jpr.nm_perawatan LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            case 'observation_rad':
                $sql = "
                    SELECT 
                        CONCAT(ppr.noorder, '-', ppr.kd_jenis_prw) as id,
                        pr.no_rawat,
                        rp.no_rkm_medis as rm,
                        p.nm_pasien as patient_name,
                        p.no_ktp as nik,
                        pr.tgl_hasil as date,
                        jpr.nm_perawatan as details,
                        (CASE 
                            WHEN sso.id_observation IS NOT NULL THEN 'synced'
                            WHEN sssp.id_specimen IS NULL OR sssp.id_specimen = '' THEN 'blocked'
                            WHEN ssi.id_imaging IS NULL OR ssi.id_imaging = '' THEN 'blocked'
                            ELSE 'pending'
                         END) as status,
                        (CASE 
                            WHEN sso.id_observation IS NOT NULL THEN NULL
                            WHEN sssp.id_specimen IS NULL OR sssp.id_specimen = '' THEN 'Specimen Not Synced Yet'
                            WHEN ssi.id_imaging IS NULL OR ssi.id_imaging = '' THEN 'ImagingStudy Not Synced Yet'
                            ELSE NULL
                         END) as blocked_reason,
                         sso.id_observation as ihs_id
                    FROM permintaan_pemeriksaan_radiologi ppr
                    INNER JOIN permintaan_radiologi pr ON ppr.noorder = pr.noorder
                    INNER JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = ppr.kd_jenis_prw
                    INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    LEFT JOIN satu_sehat_specimen_radiologi sssp ON sssp.noorder = ppr.noorder AND sssp.kd_jenis_prw = ppr.kd_jenis_prw
                    LEFT JOIN satu_sehat_imagingstudy_radiologi ssi ON ssi.noorder = ppr.noorder AND ssi.kd_jenis_prw = ppr.kd_jenis_prw
                    LEFT JOIN satu_sehat_observation_radiologi sso ON sso.noorder = ppr.noorder AND sso.kd_jenis_prw = ppr.kd_jenis_prw
                    WHERE pr.tgl_hasil IS NOT NULL AND pr.tgl_hasil <> '0000-00-00' AND pr.tgl_hasil BETWEEN :df AND :dt
                ";
                $params['df'] = $dateFrom;
                $params['dt'] = $dateTo;
                if ($search) {
                    $sql .= " AND (p.nm_pasien LIKE :search OR pr.no_rawat LIKE :search OR rp.no_rkm_medis LIKE :search OR jpr.nm_perawatan LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            case 'diagnosticreport_rad':
                $sql = "
                    SELECT 
                        CONCAT(ppr.noorder, '-', ppr.kd_jenis_prw) as id,
                        pr.no_rawat,
                        rp.no_rkm_medis as rm,
                        p.nm_pasien as patient_name,
                        p.no_ktp as nik,
                        pr.tgl_hasil as date,
                        jpr.nm_perawatan as details,
                        (CASE 
                            WHEN ssdr.id_diagnosticreport IS NOT NULL THEN 'synced'
                            WHEN sso.id_observation IS NULL OR sso.id_observation = '' THEN 'blocked'
                            ELSE 'pending'
                         END) as status,
                        (CASE 
                            WHEN ssdr.id_diagnosticreport IS NOT NULL THEN NULL
                            WHEN sso.id_observation IS NULL OR sso.id_observation = '' THEN 'Observation Not Synced Yet'
                            ELSE NULL
                         END) as blocked_reason,
                         ssdr.id_diagnosticreport as ihs_id
                    FROM permintaan_pemeriksaan_radiologi ppr
                    INNER JOIN permintaan_radiologi pr ON ppr.noorder = pr.noorder
                    INNER JOIN jns_perawatan_radiologi jpr ON jpr.kd_jenis_prw = ppr.kd_jenis_prw
                    INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    LEFT JOIN satu_sehat_observation_radiologi sso ON sso.noorder = ppr.noorder AND sso.kd_jenis_prw = ppr.kd_jenis_prw
                    LEFT JOIN satu_sehat_diagnosticreport_radiologi ssdr ON ssdr.noorder = ppr.noorder AND ssdr.kd_jenis_prw = ssdr.kd_jenis_prw
                    WHERE pr.tgl_hasil IS NOT NULL AND pr.tgl_hasil <> '0000-00-00' AND pr.tgl_hasil BETWEEN :df AND :dt
                ";
                $params['df'] = $dateFrom;
                $params['dt'] = $dateTo;
                if ($search) {
                    $sql .= " AND (p.nm_pasien LIKE :search OR pr.no_rawat LIKE :search OR rp.no_rkm_medis LIKE :search OR jpr.nm_perawatan LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            case 'servicerequest_lab_pk':
                $sql = "
                    SELECT 
                        CONCAT(pdpl.noorder, '-', pdpl.kd_jenis_prw, '-', pdpl.id_template) as id,
                        pl.no_rawat,
                        rp.no_rkm_medis as rm,
                        p.nm_pasien as patient_name,
                        p.no_ktp as nik,
                        pl.tgl_permintaan as date,
                        tl.pemeriksaan as details,
                        (CASE 
                            WHEN ssr.id_servicerequest IS NOT NULL THEN 'synced'
                            WHEN sse.id_encounter IS NULL THEN 'blocked'
                            WHEN sml.code IS NULL OR sml.code = '' THEN 'blocked'
                            ELSE 'pending'
                         END) as status,
                        (CASE 
                            WHEN ssr.id_servicerequest IS NOT NULL THEN NULL
                            WHEN sse.id_encounter IS NULL THEN 'Encounter Not Synced Yet'
                            WHEN sml.code IS NULL OR sml.code = '' THEN 'Unmapped Lab Code'
                            ELSE NULL
                         END) as blocked_reason,
                         ssr.id_servicerequest as ihs_id
                    FROM permintaan_detail_permintaan_lab pdpl
                    INNER JOIN permintaan_lab pl ON pdpl.noorder = pl.noorder
                    INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_mapping_lab sml ON sml.id_template = pdpl.id_template
                    LEFT JOIN satu_sehat_servicerequest_lab ssr ON ssr.noorder = pdpl.noorder AND ssr.kd_jenis_prw = pdpl.kd_jenis_prw AND ssr.id_template = pdpl.id_template
                    WHERE pl.tgl_permintaan BETWEEN :df AND :dt
                ";
                $params['df'] = $dateFrom;
                $params['dt'] = $dateTo;
                if ($search) {
                    $sql .= " AND (p.nm_pasien LIKE :search OR pl.no_rawat LIKE :search OR rp.no_rkm_medis LIKE :search OR tl.pemeriksaan LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            case 'specimen_lab_pk':
                $sql = "
                    SELECT 
                        CONCAT(pdpl.noorder, '-', pdpl.kd_jenis_prw, '-', pdpl.id_template) as id,
                        pl.no_rawat,
                        rp.no_rkm_medis as rm,
                        p.nm_pasien as patient_name,
                        p.no_ktp as nik,
                        pl.tgl_permintaan as date,
                        tl.pemeriksaan as details,
                        (CASE 
                            WHEN sssp.id_specimen IS NOT NULL THEN 'synced'
                            WHEN ssr.id_servicerequest IS NULL OR ssr.id_servicerequest = '' THEN 'blocked'
                            ELSE 'pending'
                         END) as status,
                        (CASE 
                            WHEN sssp.id_specimen IS NOT NULL THEN NULL
                            WHEN ssr.id_servicerequest IS NULL OR ssr.id_servicerequest = '' THEN 'Service Request Not Synced Yet'
                            ELSE NULL
                         END) as blocked_reason,
                         sssp.id_specimen as ihs_id
                    FROM permintaan_detail_permintaan_lab pdpl
                    INNER JOIN permintaan_lab pl ON pdpl.noorder = pl.noorder
                    INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    LEFT JOIN satu_sehat_servicerequest_lab ssr ON ssr.noorder = pdpl.noorder AND ssr.kd_jenis_prw = pdpl.kd_jenis_prw AND ssr.id_template = pdpl.id_template
                    LEFT JOIN satu_sehat_specimen_lab sssp ON sssp.noorder = pdpl.noorder AND sssp.kd_jenis_prw = pdpl.kd_jenis_prw AND sssp.id_template = pdpl.id_template
                    WHERE pl.tgl_permintaan BETWEEN :df AND :dt
                ";
                $params['df'] = $dateFrom;
                $params['dt'] = $dateTo;
                if ($search) {
                    $sql .= " AND (p.nm_pasien LIKE :search OR pl.no_rawat LIKE :search OR rp.no_rkm_medis LIKE :search OR tl.pemeriksaan LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            case 'observation_lab_pk':
                $sql = "
                    SELECT 
                        CONCAT(pdpl.noorder, '-', pdpl.kd_jenis_prw, '-', pdpl.id_template) as id,
                        pl.no_rawat,
                        rp.no_rkm_medis as rm,
                        p.nm_pasien as patient_name,
                        p.no_ktp as nik,
                        pl.tgl_hasil as date,
                        tl.pemeriksaan as details,
                        (CASE 
                            WHEN sso.id_observation IS NOT NULL THEN 'synced'
                            WHEN sssp.id_specimen IS NULL OR sssp.id_specimen = '' THEN 'blocked'
                            ELSE 'pending'
                         END) as status,
                        (CASE 
                            WHEN sso.id_observation IS NOT NULL THEN NULL
                            WHEN sssp.id_specimen IS NULL OR sssp.id_specimen = '' THEN 'Specimen Not Synced Yet'
                            ELSE NULL
                         END) as blocked_reason,
                         sso.id_observation as ihs_id
                    FROM permintaan_detail_permintaan_lab pdpl
                    INNER JOIN permintaan_lab pl ON pdpl.noorder = pl.noorder
                    INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    LEFT JOIN satu_sehat_specimen_lab sssp ON sssp.noorder = pdpl.noorder AND sssp.kd_jenis_prw = pdpl.kd_jenis_prw AND sssp.id_template = pdpl.id_template
                    LEFT JOIN satu_sehat_observation_lab sso ON sso.noorder = pdpl.noorder AND sso.kd_jenis_prw = pdpl.kd_jenis_prw AND sso.id_template = pdpl.id_template
                    WHERE pl.tgl_hasil IS NOT NULL AND pl.tgl_hasil <> '0000-00-00' AND pl.tgl_hasil BETWEEN :df AND :dt
                ";
                $params['df'] = $dateFrom;
                $params['dt'] = $dateTo;
                if ($search) {
                    $sql .= " AND (p.nm_pasien LIKE :search OR pl.no_rawat LIKE :search OR rp.no_rkm_medis LIKE :search OR tl.pemeriksaan LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            case 'diagnosticreport_lab_pk':
                $sql = "
                    SELECT 
                        CONCAT(pdpl.noorder, '-', pdpl.kd_jenis_prw, '-', pdpl.id_template) as id,
                        pl.no_rawat,
                        rp.no_rkm_medis as rm,
                        p.nm_pasien as patient_name,
                        p.no_ktp as nik,
                        pl.tgl_hasil as date,
                        tl.pemeriksaan as details,
                        (CASE 
                            WHEN ssdr.id_diagnosticreport IS NOT NULL THEN 'synced'
                            WHEN sso.id_observation IS NULL OR sso.id_observation = '' THEN 'blocked'
                            ELSE 'pending'
                         END) as status,
                        (CASE 
                            WHEN ssdr.id_diagnosticreport IS NOT NULL THEN NULL
                            WHEN sso.id_observation IS NULL OR sso.id_observation = '' THEN 'Observation Not Synced Yet'
                            ELSE NULL
                         END) as blocked_reason,
                         ssdr.id_diagnosticreport as ihs_id
                    FROM permintaan_detail_permintaan_lab pdpl
                    INNER JOIN permintaan_lab pl ON pdpl.noorder = pl.noorder
                    INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    LEFT JOIN satu_sehat_observation_lab sso ON sso.noorder = pdpl.noorder AND sso.kd_jenis_prw = pdpl.kd_jenis_prw AND sso.id_template = pdpl.id_template
                    LEFT JOIN satu_sehat_diagnosticreport_lab ssdr ON ssdr.noorder = pdpl.noorder AND ssdr.kd_jenis_prw = pdpl.kd_jenis_prw AND ssdr.id_template = pdpl.id_template
                    WHERE pl.tgl_hasil IS NOT NULL AND pl.tgl_hasil <> '0000-00-00' AND pl.tgl_hasil BETWEEN :df AND :dt
                ";
                $params['df'] = $dateFrom;
                $params['dt'] = $dateTo;
                if ($search) {
                    $sql .= " AND (p.nm_pasien LIKE :search OR pl.no_rawat LIKE :search OR rp.no_rkm_medis LIKE :search OR tl.pemeriksaan LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            case 'servicerequest_lab_mb':
                $sql = "
                    SELECT 
                        CONCAT(pdpl.noorder, '-', pdpl.kd_jenis_prw, '-', pdpl.id_template) as id,
                        pl.no_rawat,
                        rp.no_rkm_medis as rm,
                        p.nm_pasien as patient_name,
                        p.no_ktp as nik,
                        pl.tgl_permintaan as date,
                        tl.pemeriksaan as details,
                        (CASE 
                            WHEN ssr.id_servicerequest IS NOT NULL THEN 'synced'
                            WHEN sse.id_encounter IS NULL THEN 'blocked'
                            WHEN sml.code IS NULL OR sml.code = '' THEN 'blocked'
                            ELSE 'pending'
                         END) as status,
                        (CASE 
                            WHEN ssr.id_servicerequest IS NOT NULL THEN NULL
                            WHEN sse.id_encounter IS NULL THEN 'Encounter Not Synced Yet'
                            WHEN sml.code IS NULL OR sml.code = '' THEN 'Unmapped Lab Code'
                            ELSE NULL
                         END) as blocked_reason,
                         ssr.id_servicerequest as ihs_id
                    FROM permintaan_detail_permintaan_labmb pdpl
                    INNER JOIN permintaan_labmb pl ON pdpl.noorder = pl.noorder
                    INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
                    LEFT JOIN satu_sehat_mapping_lab sml ON sml.id_template = pdpl.id_template
                    LEFT JOIN satu_sehat_servicerequest_lab_mb ssr ON ssr.noorder = pdpl.noorder AND ssr.kd_jenis_prw = pdpl.kd_jenis_prw AND ssr.id_template = pdpl.id_template
                    WHERE pl.tgl_permintaan BETWEEN :df AND :dt
                ";
                $params['df'] = $dateFrom;
                $params['dt'] = $dateTo;
                if ($search) {
                    $sql .= " AND (p.nm_pasien LIKE :search OR pl.no_rawat LIKE :search OR rp.no_rkm_medis LIKE :search OR tl.pemeriksaan LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            case 'specimen_lab_mb':
                $sql = "
                    SELECT 
                        CONCAT(pdpl.noorder, '-', pdpl.kd_jenis_prw, '-', pdpl.id_template) as id,
                        pl.no_rawat,
                        rp.no_rkm_medis as rm,
                        p.nm_pasien as patient_name,
                        p.no_ktp as nik,
                        pl.tgl_permintaan as date,
                        tl.pemeriksaan as details,
                        (CASE 
                            WHEN sssp.id_specimen IS NOT NULL THEN 'synced'
                            WHEN ssr.id_servicerequest IS NULL OR ssr.id_servicerequest = '' THEN 'blocked'
                            ELSE 'pending'
                         END) as status,
                        (CASE 
                            WHEN sssp.id_specimen IS NOT NULL THEN NULL
                            WHEN ssr.id_servicerequest IS NULL OR ssr.id_servicerequest = '' THEN 'Service Request Not Synced Yet'
                            ELSE NULL
                         END) as blocked_reason,
                         sssp.id_specimen as ihs_id
                    FROM permintaan_detail_permintaan_labmb pdpl
                    INNER JOIN permintaan_labmb pl ON pdpl.noorder = pl.noorder
                    INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    LEFT JOIN satu_sehat_servicerequest_lab_mb ssr ON ssr.noorder = pdpl.noorder AND ssr.kd_jenis_prw = pdpl.kd_jenis_prw AND ssr.id_template = pdpl.id_template
                    LEFT JOIN satu_sehat_specimen_lab_mb sssp ON sssp.noorder = pdpl.noorder AND sssp.kd_jenis_prw = pdpl.kd_jenis_prw AND sssp.id_template = pdpl.id_template
                    WHERE pl.tgl_permintaan BETWEEN :df AND :dt
                ";
                $params['df'] = $dateFrom;
                $params['dt'] = $dateTo;
                if ($search) {
                    $sql .= " AND (p.nm_pasien LIKE :search OR pl.no_rawat LIKE :search OR rp.no_rkm_medis LIKE :search OR tl.pemeriksaan LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            case 'observation_lab_mb':
                $sql = "
                    SELECT 
                        CONCAT(pdpl.noorder, '-', pdpl.kd_jenis_prw, '-', pdpl.id_template) as id,
                        pl.no_rawat,
                        rp.no_rkm_medis as rm,
                        p.nm_pasien as patient_name,
                        p.no_ktp as nik,
                        pl.tgl_hasil as date,
                        tl.pemeriksaan as details,
                        (CASE 
                            WHEN sso.id_observation IS NOT NULL THEN 'synced'
                            WHEN sssp.id_specimen IS NULL OR sssp.id_specimen = '' THEN 'blocked'
                            ELSE 'pending'
                         END) as status,
                        (CASE 
                            WHEN sso.id_observation IS NOT NULL THEN NULL
                            WHEN sssp.id_specimen IS NULL OR sssp.id_specimen = '' THEN 'Specimen Not Synced Yet'
                            ELSE NULL
                         END) as blocked_reason,
                         sso.id_observation as ihs_id
                    FROM permintaan_detail_permintaan_labmb pdpl
                    INNER JOIN permintaan_labmb pl ON pdpl.noorder = pl.noorder
                    INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    LEFT JOIN satu_sehat_specimen_lab_mb sssp ON sssp.noorder = pdpl.noorder AND sssp.kd_jenis_prw = pdpl.kd_jenis_prw AND sssp.id_template = pdpl.id_template
                    LEFT JOIN satu_sehat_observation_lab_mb sso ON sso.noorder = pdpl.noorder AND sso.kd_jenis_prw = pdpl.kd_jenis_prw AND sso.id_template = pdpl.id_template
                    WHERE pl.tgl_hasil IS NOT NULL AND pl.tgl_hasil <> '0000-00-00' AND pl.tgl_hasil BETWEEN :df AND :dt
                ";
                $params['df'] = $dateFrom;
                $params['dt'] = $dateTo;
                if ($search) {
                    $sql .= " AND (p.nm_pasien LIKE :search OR pl.no_rawat LIKE :search OR rp.no_rkm_medis LIKE :search OR tl.pemeriksaan LIKE :search)";
                    $params['search'] = "%{$search}%";
                }
                break;

            case 'diagnosticreport_lab_mb':
                $sql = "
                    SELECT 
                        CONCAT(pdpl.noorder, '-', pdpl.kd_jenis_prw, '-', pdpl.id_template) as id,
                        pl.no_rawat,
                        rp.no_rkm_medis as rm,
                        p.nm_pasien as patient_name,
                        p.no_ktp as nik,
                        pl.tgl_hasil as date,
                        tl.pemeriksaan as details,
                        (CASE 
                            WHEN ssdr.id_diagnosticreport IS NOT NULL THEN 'synced'
                            WHEN sso.id_observation IS NULL OR sso.id_observation = '' THEN 'blocked'
                            ELSE 'pending'
                         END) as status,
                        (CASE 
                            WHEN ssdr.id_diagnosticreport IS NOT NULL THEN NULL
                            WHEN sso.id_observation IS NULL OR sso.id_observation = '' THEN 'Observation Not Synced Yet'
                            ELSE NULL
                         END) as blocked_reason,
                         ssdr.id_diagnosticreport as ihs_id
                    FROM permintaan_detail_permintaan_labmb pdpl
                    INNER JOIN permintaan_labmb pl ON pdpl.noorder = pl.noorder
                    INNER JOIN template_laboratorium tl ON tl.id_template = pdpl.id_template
                    INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat
                    INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis
                    LEFT JOIN satu_sehat_observation_lab_mb sso ON sso.noorder = pdpl.noorder AND sso.kd_jenis_prw = pdpl.kd_jenis_prw AND sso.id_template = pdpl.id_template
                    LEFT JOIN satu_sehat_diagnosticreport_lab_mb ssdr ON ssdr.noorder = pdpl.noorder AND ssdr.kd_jenis_prw = pdpl.kd_jenis_prw AND ssdr.id_template = pdpl.id_template
                    WHERE pl.tgl_hasil IS NOT NULL AND pl.tgl_hasil <> '0000-00-00' AND pl.tgl_hasil BETWEEN :df AND :dt
                ";
                $params['df'] = $dateFrom;
                $params['dt'] = $dateTo;
                if ($search) {
                    $sql .= " AND (p.nm_pasien LIKE :search OR pl.no_rawat LIKE :search OR rp.no_rkm_medis LIKE :search OR tl.pemeriksaan LIKE :search)";
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

if ($action === 'getLogs' && $method === 'GET') {
    if ($userRole !== 'admin') {
        jsonResponse(['success' => false, 'message' => 'Forbidden. Admin privileges required.'], 403);
    }

    $dateInput = $_GET['date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateInput)) {
        jsonResponse(['success' => false, 'message' => 'Invalid date format. Use YYYY-MM-DD.'], 400);
    }

    $targetLevel = $_GET['level'] ?? 'all';
    $searchKeyword = $_GET['search'] ?? '';
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    if ($page <= 0) $page = 1;
    if ($limit <= 0 || $limit > 500) $limit = 100;

    $logDir = $config->logDir;
    if (!str_starts_with($logDir, '/')) {
        $logDir = BASE_DIR . '/' . $logDir;
    }
    $pattern = rtrim($logDir, '/') . '/satusehat_portal/satusehat_portal_' . $dateInput . '*.log';
    $files = glob($pattern);

    $allLines = [];
    if (!empty($files)) {
        foreach ($files as $file) {
            if (is_file($file) && is_readable($file)) {
                $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines !== false) {
                    $allLines = array_merge($allLines, $lines);
                }
            }
        }
    }

    $parsedLogs = [];
    foreach ($allLines as $line) {
        if (preg_match('/^\[(.*?)\]\s+\[(.*?)\]\s+(.*)$/', $line, $matches)) {
            $ts = $matches[1];
            $lvl = strtoupper($matches[2]);
            $msg = $matches[3];

            if ($targetLevel !== 'all' && $lvl !== strtoupper($targetLevel)) {
                continue;
            }

            if ($searchKeyword !== '' && stripos($msg, $searchKeyword) === false && stripos($ts, $searchKeyword) === false) {
                continue;
            }

            $payload = null;
            if (preg_match('/(\{.*\})/', $msg, $jsonMatch)) {
                $decoded = json_decode($jsonMatch[1], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $payload = $decoded;
                    $msg = str_replace($jsonMatch[1], '[JSON Payload]', $msg);
                }
            }

            $parsedLogs[] = [
                'timestamp' => $ts,
                'level' => $lvl,
                'message' => $msg,
                'payload' => $payload
            ];
        }
    }

    usort($parsedLogs, function($a, $b) {
        return strcmp($b['timestamp'], $a['timestamp']);
    });

    $totalLogs = count($parsedLogs);
    $offset = ($page - 1) * $limit;
    $slicedLogs = array_slice($parsedLogs, $offset, $limit);

    jsonResponse([
        'success' => true,
        'date' => $dateInput,
        'total_count' => $totalLogs,
        'page' => $page,
        'limit' => $limit,
        'logs' => $slicedLogs
    ]);
}

if ($action === 'getAnalyticsStats' && $method === 'GET') {
    if ($userRole !== 'admin') {
        jsonResponse(['success' => false, 'message' => 'Forbidden. Admin privileges required.'], 403);
    }

    try {
        $dates = [];
        for ($i = 6; $i >= 0; $i--) {
            $dates[] = date('Y-m-d', strtotime("-$i days"));
        }
        $df = $dates[0];
        $dt = $dates[6];

        $trends = [];
        foreach ($dates as $d) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM reg_periksa WHERE tgl_registrasi = ?");
            $stmt->execute([$d]);
            $total = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM reg_periksa rp
                INNER JOIN satu_sehat_encounter sse ON rp.no_rawat = sse.no_rawat
                WHERE rp.tgl_registrasi = ?
            ");
            $stmt->execute([$d]);
            $synced = (int)$stmt->fetchColumn();

            $trends[] = [
                'date' => $d,
                'total' => $total,
                'synced' => $synced,
                'pending' => max(0, $total - $synced)
            ];
        }

        $coverage = [];
        $resourceTypes = [
            'patient' => [
                'total_sql' => "SELECT COUNT(DISTINCT rp.no_rkm_medis) FROM reg_periksa rp WHERE rp.tgl_registrasi BETWEEN :df AND :dt",
                'synced_sql' => "SELECT COUNT(DISTINCT rp.no_rkm_medis) FROM reg_periksa rp INNER JOIN pasien p ON rp.no_rkm_medis = p.no_rkm_medis INNER JOIN satu_sehat_ihs_patient ssp ON ssp.nikpasien = p.no_ktp WHERE rp.tgl_registrasi BETWEEN :df AND :dt"
            ],
            'encounter' => [
                'total_sql' => "SELECT COUNT(*) FROM reg_periksa rp WHERE rp.tgl_registrasi BETWEEN :df AND :dt",
                'synced_sql' => "SELECT COUNT(*) FROM reg_periksa rp INNER JOIN satu_sehat_encounter sse ON rp.no_rawat = sse.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt"
            ],
            'episodeofcare' => [
                'total_sql' => "SELECT COUNT(DISTINCT dp.no_rawat) FROM diagnosa_pasien dp INNER JOIN reg_periksa rp ON dp.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt",
                'synced_sql' => "SELECT COUNT(*) FROM satu_sehat_episode_of_care eoc INNER JOIN reg_periksa rp ON eoc.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt"
            ],
            'condition' => [
                'total_sql' => "SELECT COUNT(*) FROM diagnosa_pasien dp INNER JOIN reg_periksa rp ON dp.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt",
                'synced_sql' => "SELECT COUNT(*) FROM satu_sehat_condition ssc INNER JOIN reg_periksa rp ON ssc.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt"
            ],
            'observationttv' => [
                'total_sql' => "SELECT COUNT(*) FROM pemeriksaan_ralan pem INNER JOIN reg_periksa rp ON pem.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt",
                'synced_sql' => "SELECT COUNT(*) FROM satu_sehat_observationttvsuhu sso INNER JOIN reg_periksa rp ON sso.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt"
            ],
            'procedure' => [
                'total_sql' => "SELECT COUNT(*) FROM prosedur_pasien pp INNER JOIN reg_periksa rp ON pp.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt",
                'synced_sql' => "SELECT COUNT(*) FROM satu_sehat_procedure ssp INNER JOIN reg_periksa rp ON ssp.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt"
            ],
            'allergyintolerance' => [
                'total_sql' => "SELECT COUNT(*) FROM pemeriksaan_ralan pr INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat WHERE pr.alergi <> '' AND rp.tgl_registrasi BETWEEN :df AND :dt",
                'synced_sql' => "SELECT COUNT(*) FROM satu_sehat_allergy_intolerance ssai INNER JOIN reg_periksa rp ON ssai.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt"
            ],
            'immunization' => [
                'total_sql' => "SELECT COUNT(*) FROM detail_pemberian_obat dpo INNER JOIN reg_periksa rp ON dpo.no_rawat = rp.no_rawat INNER JOIN satu_sehat_mapping_vaksin smv ON smv.kode_brng = dpo.kode_brng WHERE rp.tgl_registrasi BETWEEN :df AND :dt",
                'synced_sql' => "SELECT COUNT(*) FROM satu_sehat_immunization ssi INNER JOIN reg_periksa rp ON ssi.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt"
            ],
            'medication' => [
                'total_sql' => "SELECT COUNT(DISTINCT dpo.kode_brng) FROM detail_pemberian_obat dpo INNER JOIN reg_periksa rp ON dpo.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt",
                'synced_sql' => "SELECT COUNT(DISTINCT dpo.kode_brng) FROM detail_pemberian_obat dpo INNER JOIN reg_periksa rp ON dpo.no_rawat = rp.no_rawat INNER JOIN satu_sehat_medication ssm ON ssm.kode_brng = dpo.kode_brng WHERE rp.tgl_registrasi BETWEEN :df AND :dt"
            ],
            'medicationrequest' => [
                'total_sql' => "SELECT COUNT(*) FROM resep_obat ro INNER JOIN reg_periksa rp ON ro.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt",
                'synced_sql' => "SELECT COUNT(*) FROM satu_sehat_medicationrequest ssmr INNER JOIN resep_obat ro ON ssmr.no_resep = ro.no_resep INNER JOIN reg_periksa rp ON ro.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt"
            ],
            'medicationdispense' => [
                'total_sql' => "SELECT COUNT(*) FROM detail_pemberian_obat dpo INNER JOIN reg_periksa rp ON dpo.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt",
                'synced_sql' => "SELECT COUNT(*) FROM satu_sehat_medicationdispense ssmd INNER JOIN reg_periksa rp ON ssmd.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt"
            ],
            'medicationstatement' => [
                'total_sql' => "SELECT COUNT(*) FROM detail_pemberian_obat dpo INNER JOIN reg_periksa rp ON dpo.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt",
                'synced_sql' => "SELECT COUNT(*) FROM satu_sehat_medicationstatement ssms INNER JOIN resep_obat ro ON ssms.no_resep = ro.no_resep INNER JOIN reg_periksa rp ON ro.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt"
            ],
            'clinicalimpression' => [
                'total_sql' => "SELECT COUNT(*) FROM pemeriksaan_ralan pem INNER JOIN reg_periksa rp ON pem.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt",
                'synced_sql' => "SELECT COUNT(*) FROM satu_sehat_clinicalimpression ssci INNER JOIN reg_periksa rp ON ssci.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt"
            ],
            'servicerequest_rad' => [
                'total_sql' => "SELECT COUNT(*) FROM permintaan_pemeriksaan_radiologi ppr INNER JOIN permintaan_radiologi pr ON ppr.noorder = pr.noorder INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt",
                'synced_sql' => "SELECT COUNT(*) FROM satu_sehat_servicerequest_radiologi ssr INNER JOIN permintaan_radiologi pr ON ssr.noorder = pr.noorder INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt"
            ],
            'specimen_rad' => [
                'total_sql' => "SELECT COUNT(*) FROM permintaan_pemeriksaan_radiologi ppr INNER JOIN permintaan_radiologi pr ON ppr.noorder = pr.noorder INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt",
                'synced_sql' => "SELECT COUNT(*) FROM satu_sehat_specimen_radiologi sssp INNER JOIN permintaan_radiologi pr ON sssp.noorder = pr.noorder INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt"
            ],
            'observation_rad' => [
                'total_sql' => "SELECT COUNT(*) FROM periksa_radiologi prad INNER JOIN reg_periksa rp ON prad.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt",
                'synced_sql' => "SELECT COUNT(*) FROM satu_sehat_observation_radiologi sso INNER JOIN permintaan_radiologi pr ON sso.noorder = pr.noorder INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt"
            ],
            'diagnosticreport_rad' => [
                'total_sql' => "SELECT COUNT(*) FROM periksa_radiologi prad INNER JOIN reg_periksa rp ON prad.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt",
                'synced_sql' => "SELECT COUNT(*) FROM satu_sehat_diagnosticreport_radiologi ssdr INNER JOIN permintaan_radiologi pr ON ssdr.noorder = pr.noorder INNER JOIN reg_periksa rp ON pr.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt"
            ],
            'servicerequest_lab_pk' => [
                'total_sql' => "SELECT COUNT(*) FROM permintaan_pemeriksaan_lab ppl INNER JOIN permintaan_lab pl ON ppl.noorder = pl.noorder INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt",
                'synced_sql' => "SELECT COUNT(*) FROM satu_sehat_servicerequest_lab ssrq INNER JOIN permintaan_lab pl ON ssrq.noorder = pl.noorder INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt"
            ],
            'specimen_lab_pk' => [
                'total_sql' => "SELECT COUNT(*) FROM permintaan_pemeriksaan_lab ppl INNER JOIN permintaan_lab pl ON ppl.noorder = pl.noorder INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt",
                'synced_sql' => "SELECT COUNT(*) FROM satu_sehat_specimen_lab sss INNER JOIN permintaan_lab pl ON sss.noorder = pl.noorder INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt"
            ],
            'observation_lab_pk' => [
                'total_sql' => "SELECT COUNT(*) FROM detail_periksa_lab dpl INNER JOIN reg_periksa rp ON dpl.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt",
                'synced_sql' => "SELECT COUNT(*) FROM satu_sehat_observation_lab sso INNER JOIN permintaan_lab pl ON sso.noorder = pl.noorder INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt"
            ],
            'diagnosticreport_lab_pk' => [
                'total_sql' => "SELECT COUNT(*) FROM detail_periksa_lab dpl INNER JOIN reg_periksa rp ON dpl.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt",
                'synced_sql' => "SELECT COUNT(*) FROM satu_sehat_diagnosticreport_lab ssdr INNER JOIN permintaan_lab pl ON ssdr.noorder = pl.noorder INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt"
            ],
            'servicerequest_lab_mb' => [
                'total_sql' => "SELECT COUNT(*) FROM permintaan_pemeriksaan_lab ppl INNER JOIN permintaan_lab pl ON ppl.noorder = pl.noorder INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat WHERE pl.status = 'mikrobiologi' AND rp.tgl_registrasi BETWEEN :df AND :dt",
                'synced_sql' => "SELECT COUNT(*) FROM satu_sehat_servicerequest_lab_mb ssrq INNER JOIN permintaan_lab pl ON ssrq.noorder = pl.noorder INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt"
            ],
            'specimen_lab_mb' => [
                'total_sql' => "SELECT COUNT(*) FROM permintaan_pemeriksaan_lab ppl INNER JOIN permintaan_lab pl ON ppl.noorder = pl.noorder INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat WHERE pl.status = 'mikrobiologi' AND rp.tgl_registrasi BETWEEN :df AND :dt",
                'synced_sql' => "SELECT COUNT(*) FROM satu_sehat_specimen_lab_mb sss INNER JOIN permintaan_lab pl ON sss.noorder = pl.noorder INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt"
            ],
            'observation_lab_mb' => [
                'total_sql' => "SELECT COUNT(*) FROM detail_periksa_lab dpl INNER JOIN reg_periksa rp ON dpl.no_rawat = rp.no_rawat INNER JOIN permintaan_lab pl ON dpl.no_rawat = pl.no_rawat WHERE pl.status = 'mikrobiologi' AND rp.tgl_registrasi BETWEEN :df AND :dt",
                'synced_sql' => "SELECT COUNT(*) FROM satu_sehat_observation_lab_mb sso INNER JOIN permintaan_lab pl ON sso.noorder = pl.noorder INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt"
            ],
            'diagnosticreport_lab_mb' => [
                'total_sql' => "SELECT COUNT(*) FROM detail_periksa_lab dpl INNER JOIN reg_periksa rp ON dpl.no_rawat = rp.no_rawat INNER JOIN permintaan_lab pl ON dpl.no_rawat = pl.no_rawat WHERE pl.status = 'mikrobiologi' AND rp.tgl_registrasi BETWEEN :df AND :dt",
                'synced_sql' => "SELECT COUNT(*) FROM satu_sehat_diagnosticreport_lab_mb ssdr INNER JOIN permintaan_lab pl ON ssdr.noorder = pl.noorder INNER JOIN reg_periksa rp ON pl.no_rawat = rp.no_rawat WHERE rp.tgl_registrasi BETWEEN :df AND :dt"
            ]
        ];

        foreach ($resourceTypes as $name => $sqls) {
            $stmt = $pdo->prepare($sqls['total_sql']);
            $stmt->execute(['df' => $df, 'dt' => $dt]);
            $total = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare($sqls['synced_sql']);
            $stmt->execute(['df' => $df, 'dt' => $dt]);
            $synced = (int)$stmt->fetchColumn();

            $coverage[$name] = [
                'total' => $total,
                'synced' => $synced,
                'percent' => $total > 0 ? round(($synced / $total) * 100) : 100
            ];
        }

        $errors = [];
        $logDir = $config->logDir;
        if (!str_starts_with($logDir, '/')) {
            $logDir = BASE_DIR . '/' . $logDir;
        }

        for ($i = 0; $i < 3; $i++) {
            $dateStr = date('Y-m-d', strtotime("-$i days"));
            $pattern = rtrim($logDir, '/') . '/satusehat_portal/satusehat_portal_' . $dateStr . '*.log';
            $files = glob($pattern);
            if (!empty($files)) {
                foreach ($files as $file) {
                    if (is_file($file) && is_readable($file)) {
                        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                        if ($lines !== false) {
                            foreach ($lines as $line) {
                                if (stripos($line, '[ERROR]') !== false || stripos($line, '[WARNING]') !== false) {
                                    if (preg_match('/\]\s+\[(?:ERROR|WARNING)\]\s+(.*)$/i', $line, $match)) {
                                        $msg = trim($match[1]);
                                        if (preg_match('/^[a-zA-Z\s,:\'"\(\)\{\}\[\]\.\_\-\!\@\#\$\%\^\&\*\+\=]+/i', $msg, $g)) {
                                            $msg = $g[0];
                                        }
                                        $msg = preg_replace('/[0-9]{4}-[0-9]{2}-[0-9]{2}/', 'DATE', $msg);
                                        $msg = preg_replace('/[0-9\/\:\-]+/', 'X', $msg);
                                        $msg = preg_replace('/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}/i', 'UUID', $msg);
                                        $msg = substr($msg, 0, 80);
                                        $errors[$msg] = ($errors[$msg] ?? 0) + 1;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        arsort($errors);
        $topErrors = [];
        $count = 0;
        foreach ($errors as $reason => $qty) {
            $topErrors[] = ['reason' => $reason, 'count' => $qty];
            $count++;
            if ($count >= 5) break;
        }

        // Detailed blocking reasons breakdown within lookback period
        $blockingReasons = [];

        // 1. Unpaid Registration (Encounter)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM reg_periksa rp
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            WHERE sse.id_encounter IS NULL
              AND rp.status_bayar = 'Belum Bayar'
              AND rp.tgl_registrasi BETWEEN :df AND :dt
        ");
        $stmt->execute(['df' => $df, 'dt' => $dt]);
        $unpaid = (int)$stmt->fetchColumn();
        if ($unpaid > 0) {
            $blockingReasons['Unpaid Registration (Encounter)'] = $unpaid;
        }

        // 2. Unmapped Clinic Location (Encounter)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM reg_periksa rp
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            LEFT JOIN satu_sehat_mapping_lokasi_ralan smlr ON smlr.kd_poli = rp.kd_poli
            WHERE sse.id_encounter IS NULL
              AND (smlr.id_lokasi_satusehat IS NULL OR smlr.id_lokasi_satusehat = '')
              AND rp.tgl_registrasi BETWEEN :df AND :dt
        ");
        $stmt->execute(['df' => $df, 'dt' => $dt]);
        $unmappedLoc = (int)$stmt->fetchColumn();
        if ($unmappedLoc > 0) {
            $blockingReasons['Unmapped Clinic Location (Encounter)'] = $unmappedLoc;
        }

        // 3. Encounter Not Synced Yet (Condition)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM diagnosa_pasien dp
            INNER JOIN reg_periksa rp ON dp.no_rawat = rp.no_rawat
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            LEFT JOIN satu_sehat_condition ssc ON ssc.no_rawat = dp.no_rawat AND ssc.kd_penyakit = dp.kd_penyakit AND ssc.status = dp.status
            WHERE sse.id_encounter IS NULL
              AND ssc.id_condition IS NULL
              AND rp.tgl_registrasi BETWEEN :df AND :dt
        ");
        $stmt->execute(['df' => $df, 'dt' => $dt]);
        $nosyncCond = (int)$stmt->fetchColumn();
        if ($nosyncCond > 0) {
            $blockingReasons['Encounter Not Synced (Condition)'] = $nosyncCond;
        }

        // 4. Encounter Not Synced Yet (Procedure)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM prosedur_pasien pp
            INNER JOIN reg_periksa rp ON pp.no_rawat = rp.no_rawat
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            LEFT JOIN satu_sehat_procedure ssp ON ssp.no_rawat = pp.no_rawat AND ssp.kode = pp.kode
            WHERE sse.id_encounter IS NULL
              AND ssp.id_procedure IS NULL
              AND rp.tgl_registrasi BETWEEN :df AND :dt
        ");
        $stmt->execute(['df' => $df, 'dt' => $dt]);
        $nosyncProc = (int)$stmt->fetchColumn();
        if ($nosyncProc > 0) {
            $blockingReasons['Encounter Not Synced (Procedure)'] = $nosyncProc;
        }

        // 5. Unmapped Medication Code (Prescription)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM detail_pemberian_obat dpo
            INNER JOIN reg_periksa rp ON dpo.no_rawat = rp.no_rawat
            LEFT JOIN satu_sehat_encounter sse ON sse.no_rawat = rp.no_rawat
            LEFT JOIN satu_sehat_mapping_obat ssmo ON ssmo.kode_brng = dpo.kode_brng
            LEFT JOIN resep_obat ro ON ro.no_rawat = dpo.no_rawat
            LEFT JOIN satu_sehat_medicationrequest ssmr ON ssmr.no_resep = ro.no_resep AND ssmr.kode_brng = dpo.kode_brng
            WHERE ssmr.id_medicationrequest IS NULL
              AND (ssmo.obat_code IS NULL OR ssmo.obat_code = '')
              AND dpo.tgl_perawatan BETWEEN :df AND :dt
        ");
        $stmt->execute(['df' => $df, 'dt' => $dt]);
        $unmappedMed = (int)$stmt->fetchColumn();
        if ($unmappedMed > 0) {
            $blockingReasons['Unmapped Medication Code (Prescription)'] = $unmappedMed;
        }

        // 6. Unmapped Vaccine Code (Immunization)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM detail_pemberian_obat dpo
            INNER JOIN reg_periksa rp ON dpo.no_rawat = rp.no_rawat
            LEFT JOIN satu_sehat_mapping_vaksin smv ON smv.kode_brng = dpo.kode_brng
            LEFT JOIN satu_sehat_immunization ssi ON ssi.no_rawat = dpo.no_rawat AND ssi.kode_brng = dpo.kode_brng AND ssi.tgl_perawatan = dpo.tgl_perawatan
            WHERE ssi.id_immunization IS NULL
              AND (smv.vaksin_code IS NULL OR smv.vaksin_code = '')
              AND dpo.tgl_perawatan BETWEEN :df AND :dt
        ");
        $stmt->execute(['df' => $df, 'dt' => $dt]);
        $unmappedVac = (int)$stmt->fetchColumn();
        if ($unmappedVac > 0) {
            $blockingReasons['Unmapped Vaccine Code (Immunization)'] = $unmappedVac;
        }

        // 7. Unmapped Practitioner NIK (Master Data)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM pegawai peg
            LEFT JOIN satu_sehat_ihs_practitioner ssip ON ssip.nikpegawai = peg.nik
            WHERE (ssip.ihspegawai IS NULL OR ssip.ihspegawai = '') AND peg.no_ktp REGEXP '^[0-9]{16}$'
        ");
        $stmt->execute();
        $unmappedDoc = (int)$stmt->fetchColumn();
        if ($unmappedDoc > 0) {
            $blockingReasons['Unmapped Practitioner NIK (Master Data)'] = $unmappedDoc;
        }

        jsonResponse([
            'success' => true,
            'trends' => $trends,
            'coverage' => $coverage,
            'top_errors' => $topErrors,
            'blocking_reasons' => $blockingReasons
        ]);

    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

if ($action === 'getDiagnostics' && $method === 'GET') {
    if ($userRole !== 'admin') {
        jsonResponse(['success' => false, 'message' => 'Forbidden. Admin privileges required.'], 403);
    }

    $diagnostics = [];

    // 1. MySQL Connectivity
    try {
        $stmt = $pdo->query("SELECT 1");
        $stmt->closeCursor();
        $diagnostics['database'] = ['status' => 'healthy', 'message' => 'Connected to local MySQL database'];
    } catch (Exception $e) {
        $diagnostics['database'] = ['status' => 'error', 'message' => 'MySQL Error: ' . $e->getMessage()];
    }

    // 2. SQLite local state check
    try {
        $sqlitePath = rtrim($config->logDir, '/') . '/satusehat_state.sqlite';
        if (!file_exists($sqlitePath)) {
            $diagnostics['sqlite'] = ['status' => 'warning', 'message' => 'SQLite file not initialized yet (will be created automatically on sync)'];
        } else {
            $sqlitePdo = new PDO("sqlite:{$sqlitePath}");
            $sqlitePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $stmt = $sqlitePdo->query("SELECT name FROM sqlite_master WHERE type='table' LIMIT 1");
            $stmt->closeCursor();
            $diagnostics['sqlite'] = ['status' => 'healthy', 'message' => 'Connected to SQLite local state'];
        }
    } catch (Exception $e) {
        $diagnostics['sqlite'] = ['status' => 'error', 'message' => 'SQLite Error: ' . $e->getMessage()];
    }

    // 3. SatuSehat API connection check
    try {
        $baseUrl = rtrim($config->authUrl, '/');
        if (empty($baseUrl)) {
            $diagnostics['satusehat'] = ['status' => 'error', 'message' => 'SatuSehat auth url is not configured'];
        } else {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $baseUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode > 0) {
                $diagnostics['satusehat'] = ['status' => 'healthy', 'message' => "SatuSehat auth endpoint reachable (HTTP {$httpCode})"];
            } else {
                $diagnostics['satusehat'] = ['status' => 'error', 'message' => 'SatuSehat auth endpoint unreachable (Connection timeout)'];
            }
        }
    } catch (Exception $e) {
        $diagnostics['satusehat'] = ['status' => 'error', 'message' => 'SatuSehat Check Error: ' . $e->getMessage()];
    }

    // 4. Orthanc PACS connection check
    try {
        $orthancHost = rtrim($config->orthancUrl, '/');
        if (empty($orthancHost)) {
            $diagnostics['orthanc'] = ['status' => 'error', 'message' => 'Orthanc URL is not configured'];
        } else {
            $orthancUrl = $orthancHost . ':' . $config->orthancPort . '/system';
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $orthancUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);

            $user = $config->orthancUser;
            $pass = $config->orthancPass;
            curl_setopt($ch, CURLOPT_USERPWD, "{$user}:{$pass}");

            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $diagnostics['orthanc'] = ['status' => 'healthy', 'message' => 'Connected to Orthanc PACS'];
            } else {
                $diagnostics['orthanc'] = ['status' => 'error', 'message' => "Orthanc returned HTTP {$httpCode} (Authentication/Endpoint error)"];
            }
        }
    } catch (Exception $e) {
        $diagnostics['orthanc'] = ['status' => 'error', 'message' => 'Orthanc Check Error: ' . $e->getMessage()];
    }

    // 5. DB Structure Analyzer
    $requiredTables = [
        'reg_periksa' => ['no_rawat', 'no_rkm_medis', 'tgl_registrasi', 'kd_dokter', 'kd_poli', 'status_bayar'],
        'pasien' => ['no_rkm_medis', 'nm_pasien', 'no_ktp'],
        'pegawai' => ['nik', 'nama', 'no_ktp'],
        'poliklinik' => ['kd_poli', 'nm_poli'],
        'satu_sehat_mapping_lokasi_ralan' => ['kd_poli', 'id_lokasi_satusehat'],
        'satu_sehat_encounter' => ['no_rawat', 'id_encounter'],
        'diagnosa_pasien' => ['no_rawat', 'kd_penyakit', 'status'],
        'penyakit' => ['kd_penyakit', 'nm_penyakit'],
        'satu_sehat_condition' => ['no_rawat', 'kd_penyakit', 'id_condition'],
        'satu_sehat_ihs_patient' => ['nikpasien', 'ihspasien'],
        'satu_sehat_ihs_practitioner' => ['nikpegawai', 'ihspegawai'],
        'satu_sehat_episode_of_care' => ['no_rawat', 'kd_penyakit', 'id_episode_of_care']
    ];

    $analyzerResults = [];
    foreach ($requiredTables as $tableName => $columns) {
        try {
            // Check if table exists
            $stmt = $pdo->prepare("SELECT 1 FROM {$tableName} LIMIT 1");
            $stmt->execute();
            $stmt->closeCursor();

            // Check if columns exist
            $missingColumns = [];
            foreach ($columns as $col) {
                try {
                    $checkCol = $pdo->prepare("SELECT {$col} FROM {$tableName} LIMIT 1");
                    $checkCol->execute();
                    $checkCol->closeCursor();
                } catch (Exception $colEx) {
                    $missingColumns[] = $col;
                }
            }

            if (empty($missingColumns)) {
                $analyzerResults[$tableName] = ['status' => 'healthy', 'message' => 'Table exists with all required columns'];
            } else {
                $analyzerResults[$tableName] = ['status' => 'warning', 'message' => 'Missing columns: ' . implode(', ', $missingColumns), 'details' => $missingColumns];
            }
        } catch (Exception $tableEx) {
            $analyzerResults[$tableName] = ['status' => 'error', 'message' => 'Table is missing or inaccessible', 'details' => $columns];
        }
    }

    jsonResponse([
        'success' => true,
        'diagnostics' => $diagnostics,
        'db_analyzer' => $analyzerResults
    ]);
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
