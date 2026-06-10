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
        $log->warning("Unauthorized webhook access attempt from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        exit;
    }

    // 2. Parse Payload
    $rawInput = file_get_contents('php://input');
    $log->info("Raw Webhook Payload received: " . $rawInput);

    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON payload']);
        $log->error("Invalid JSON payload received from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
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

    if (!$status) {
        $errors = $input['error'] ?? [];
        $errStr = '';
        if (is_array($errors)) {
            foreach ($errors as $err) {
                $errCode = $err['code'] ?? 'UNKNOWN';
                $errMsg = $err['message'] ?? '';
                $errStr .= "[{$errCode}: {$errMsg}] ";
            }
        }
        $log->error("DICOM Router reported failure for ACSN {$acsn}. Message: " . ($input['message'] ?? '') . " | Errors: {$errStr}");
        exit;
    }

    if (empty($imagingStudyId)) {
        $log->error("DICOM Router reported SUCCESS but imagingStudyId is empty for ACSN: {$acsn}");
        exit;
    }

    // Initialize Database Connection
    $pdo = new PDO(
        "mysql:host={$config->dbHost};port={$config->dbPort};dbname={$config->dbName}",
        $config->dbUser,
        $config->dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Check if ACSN exists in database
    $stmt = $pdo->prepare("SELECT noorder, kd_jenis_prw, id_imaging FROM satu_sehat_imagingstudy_radiologi WHERE acsn = :acsn");
    $stmt->execute(['acsn' => $acsn]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        $log->warning("No record found in satu_sehat_imagingstudy_radiologi for ACSN: {$acsn}");
        exit;
    }

    if (!empty($record['id_imaging']) && $record['id_imaging'] !== '-' && $record['id_imaging'] === $imagingStudyId) {
        $log->info("ACSN {$acsn} already has id_imaging mapped correctly: {$imagingStudyId}");
        exit;
    }

    // Update local DB directly with the imagingStudyId provided by the webhook
    $updateStmt = $pdo->prepare("UPDATE satu_sehat_imagingstudy_radiologi SET id_imaging = :id_imaging WHERE acsn = :acsn");
    $updateStmt->execute(['id_imaging' => $imagingStudyId, 'acsn' => $acsn]);
    $log->info("Successfully updated database for ACSN {$acsn} with id_imaging: {$imagingStudyId}");

} catch (Exception $e) {
    if (isset($log)) {
        $log->error("Unhandled exception in dicom_webhook.php: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    } else {
        error_log("Unhandled exception in dicom_webhook.php: " . $e->getMessage());
    }
}
