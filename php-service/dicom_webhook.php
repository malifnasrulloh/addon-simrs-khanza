<?php
/**
 * SIMRS Khanza - Satu Sehat DICOM Router Webhook Callback Receiver
 *
 * Receives POST status updates from the Kemenkes DICOM Router,
 * processes them asynchronously (to ensure response < 5s),
 * and syncs the SatuSehat ImagingStudy resource ID back to the database.
 *
 * Supports the verified JSON payload schema:
 * {
 *   "status": true/false,
 *   "message": "...",
 *   "data": {
 *     "organizationId": "...",
 *     "imagingStudyId": "...",  // present on success
 *     "accessionNumber": "...",
 *     "studyInstanceUID": "..."
 *   },
 *   "error": [ {"code": "...", "message": "..."} ],
 *   "stage": "..."
 * }
 *
 * @author malifnasrulloh (by Antigravity)
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

define('BASE_DIR', __DIR__);
require_once BASE_DIR . '/lib/Logger.php';
require_once BASE_DIR . '/lib/satusehat/Config.php';

/**
 * Resolves the actual client IP address, supporting reverse proxies.
 */
function getClientIp(): string {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return $_SERVER['HTTP_X_REAL_IP'];
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

try {
    $config = new SatuSehatConfig(BASE_DIR . '/.env');
    $log = new Logger($config->logDir, 'satusehat_webhook', $config->logLevel, false);
    
    // 1. Authenticate Request (HTTP Basic Authentication)
    $authUser = $_SERVER['PHP_AUTH_USER'] ?? '';
    $authPass = $_SERVER['PHP_AUTH_PW'] ?? '';

    if ($authUser !== $config->webhookUser || $authPass !== $config->webhookPassword) {
        header('WWW-Authenticate: Basic realm="SatuSehat DICOM Webhook"');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        $suppliedUser = !empty($authUser) ? "'{$authUser}'" : "none";
        $log->warning("Unauthorized webhook access attempt from IP: " . getClientIp() . " | Supplied User: {$suppliedUser}");
        exit;
    }

    // 2. Parse Payload
    $rawInput = file_get_contents('php://input');
    $log->info("Raw Webhook Payload received: " . $rawInput);

    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON payload']);
        $log->error("Invalid JSON payload received from IP: " . getClientIp());
        exit;
    }

    // Check payload structure based on the schema
    $status = isset($input['status']) ? (bool)$input['status'] : null;
    $data = $input['data'] ?? null;
    $stage = $input['stage'] ?? '';

    if ($status === null || !is_array($data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing status or data object']);
        $log->error("Webhook payload missing required parameters: status or data object");
        exit;
    }

    $acsn = $data['accessionNumber'] ?? '';
    $imagingStudyId = $data['imagingStudyId'] ?? '';

    if (empty($acsn)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing accessionNumber']);
        $log->error("Webhook payload data missing accessionNumber");
        exit;
    }

    // 3. Close the HTTP connection immediately to fulfill the < 5 seconds requirement
    // This allows the client (DICOM Router) to receive 200 OK instantly (< 50ms)
    // while the script continues execution in the background.
    ignore_user_abort(true);
    set_time_limit(60);

    ob_start();
    echo json_encode(['success' => true, 'message' => 'Webhook received successfully']);
    $size = ob_get_length();
    header("Content-Length: $size");
    header("Connection: close");
    ob_end_flush();
    ob_flush();
    flush();

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    // ─── BACKGROUND PROCESSING ───
    $log->info("Starting background processing for ACSN: {$acsn} | Status: " . ($status ? "SUCCESS" : "FAILED") . " | Stage: {$stage}");

    // Initialize Database Connection with retries
    $dsn = "mysql:host={$config->dbHost};port={$config->dbPort};dbname={$config->dbName};charset=utf8mb4";
    $maxDbAttempts = 3;
    $dbDelayMs = 500;
    $pdo = null;
    
    for ($dbAttempt = 1; $dbAttempt <= $maxDbAttempts; $dbAttempt++) {
        try {
            $pdo = new PDO($dsn, $config->dbUser, $config->dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            break;
        } catch (PDOException $e) {
            if ($dbAttempt === $maxDbAttempts) {
                throw $e;
            }
            usleep($dbDelayMs * 1000);
        }
    }

    // Check if ACSN exists in database
    $stmt = $pdo->prepare("SELECT noorder, kd_jenis_prw, id_imaging, status_webhook FROM satu_sehat_imagingstudy_radiologi WHERE acsn = :acsn");
    $stmt->execute(['acsn' => $acsn]);
    $record = $stmt->fetch();

    if (!$record) {
        $log->warning("No record found in satu_sehat_imagingstudy_radiologi for ACSN: {$acsn}");
        exit;
    }

    if (!$status) {
        // Out-of-Order / Concurrency Guard: Do not overwrite SUCCESS status
        if ($record['status_webhook'] === 'SUCCESS' || (!empty($record['id_imaging']) && $record['id_imaging'] !== '-')) {
            $log->info("Received FAILED webhook callback for ACSN {$acsn} but it is already marked as SUCCESS/has valid ID. Ignoring callback.");
            exit;
        }

        $errors = $input['error'] ?? [];
        $errStr = '';
        if (is_array($errors)) {
            foreach ($errors as $err) {
                $errStr .= ($err['message'] ?? '') . ' ';
            }
        }
        $errStr = trim($errStr);
        $message = !empty($errStr) ? $errStr : ($input['message'] ?? 'Gagal');

        $updateStmt = $pdo->prepare("UPDATE satu_sehat_imagingstudy_radiologi SET status_webhook = 'FAILED', message_webhook = :message WHERE acsn = :acsn");
        $updateStmt->execute([
            'message' => substr($message, 0, 255),
            'acsn' => $acsn
        ]);
        $log->error("Successfully updated database status to FAILED for ACSN {$acsn}. Message: {$message}");
        exit;
    }

    if (empty($imagingStudyId)) {
        $log->error("DICOM Router reported SUCCESS but imagingStudyId is empty for ACSN: {$acsn}");
        // Only set to FAILED if not already SUCCESS
        if ($record['status_webhook'] !== 'SUCCESS' && (empty($record['id_imaging']) || $record['id_imaging'] === '-')) {
            $updateStmt = $pdo->prepare("UPDATE satu_sehat_imagingstudy_radiologi SET status_webhook = 'FAILED', message_webhook = 'imagingStudyId empty' WHERE acsn = :acsn");
            $updateStmt->execute(['acsn' => $acsn]);
        }
        exit;
    }

    // Update local DB directly with the imagingStudyId provided by the webhook on success
    $message = $input['message'] ?? 'DICOM berhasil dikirim';
    $updateStmt = $pdo->prepare("UPDATE satu_sehat_imagingstudy_radiologi SET id_imaging = :id_imaging, status_webhook = 'SUCCESS', message_webhook = :message WHERE acsn = :acsn");
    $updateStmt->execute([
        'id_imaging' => $imagingStudyId,
        'message' => substr($message, 0, 255),
        'acsn' => $acsn
    ]);
    $log->info("Successfully updated database for ACSN {$acsn} with id_imaging: {$imagingStudyId} and status SUCCESS");

} catch (Exception $e) {
    if (isset($log)) {
        $log->error("Unhandled exception in dicom_webhook.php: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    } else {
        error_log("Unhandled exception in dicom_webhook.php: " . $e->getMessage());
    }
}
