<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

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
    
    // Secret for JWT (Fallback to hardcoded if not in env for simplicity)
    $jwtSecret = getenv('JWT_SECRET') ?: 'simrs-khanza-secret-super-secure-key';

} catch (Exception $e) {
    jsonResponse(['error' => 'Initialization failed', 'message' => $e->getMessage()], 500);
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

    // If not found, check 'admin' table
    if (!$user) {
        $stmtAdmin = $pdo->prepare("SELECT AES_DECRYPT(usere, 'nur') as id_user, AES_DECRYPT(passworde, 'windi') as pwd FROM admin WHERE AES_DECRYPT(usere, 'nur') = :username");
        $stmtAdmin->execute(['username' => $username]);
        $user = $stmtAdmin->fetch(PDO::FETCH_ASSOC);
    }

    if ($user && $user['pwd'] === $password) {
        $payload = [
            'iss' => 'simrs-khanza',
            'iat' => time(),
            'exp' => time() + (8 * 3600), // 8 hours expiration
            'user' => $username
        ];
        $token = JWT::encode($payload, $jwtSecret);
        
        jsonResponse([
            'success' => true,
            'token' => $token,
            'user' => ['username' => $username]
        ]);
    }
    jsonResponse(['success' => false, 'message' => 'Invalid credentials'], 401);
}

// Ensure Auth Token for other routes
$headers = apache_request_headers();
$authHeader = $headers['Authorization'] ?? '';
$bearerToken = str_replace('Bearer ', '', $authHeader);

if ($action !== 'login') {
    if (empty($bearerToken)) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized. Missing Token.'], 401);
    }
    $decoded = JWT::decode($bearerToken, $jwtSecret);
    if (!$decoded) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized. Invalid or Expired Token.'], 401);
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

jsonResponse(['error' => 'Unknown action'], 404);
