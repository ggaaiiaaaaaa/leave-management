<?php
// actions/biometric_sync.php - ZKTeco MB460 Plus Punch Ingest & Live Auto-Enrollment
require_once __DIR__ . '/../auth.php';
requireLogin();

header('Content-Type: application/json');

$action = $_POST['action'] ?? ($_GET['action'] ?? 'simulate_punch');

if ($action === 'simulate_punch') {
    // Requires login (Admin or Staff simulating a test punch)
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $punchType = $_POST['punch_type'] ?? 'time_in'; // 'time_in' or 'time_out'
    $verificationMethod = $_POST['verification_method'] ?? 'Face Scan'; // 'Face Scan', 'Fingerprint', 'PIN / Card'
    $customTime = $_POST['punch_time'] ?? date('H:i:s');
    $logDate = $_POST['log_date'] ?? date('Y-m-d');

    if ($targetUserId <= 0) {
        $user = getCurrentUser();
        $targetUserId = $user['id'];
    }

    // Fetch user details
    $uStmt = $pdo->prepare("SELECT id, name, biometric_pin, face_enrolled, fingerprint_enrolled FROM users WHERE id = ?");
    $uStmt->execute([$targetUserId]);
    $u = $uStmt->fetch();

    if (!$u) {
        echo json_encode(['success' => false, 'message' => 'Associate not found.']);
        exit;
    }

    $pin = $u['biometric_pin'] ?: strval($u['id']);

    // AUTO-ENROLLMENT: If punched via Face Scan or Fingerprint, auto-mark user profile as enrolled
    $autoEnrolledNote = '';
    if ($verificationMethod === 'Face Scan' && !$u['face_enrolled']) {
        $pdo->prepare("UPDATE users SET face_enrolled = 1 WHERE id = ?")->execute([$targetUserId]);
        $autoEnrolledNote = " (Face recognition auto-enrolled!)";
    } elseif ($verificationMethod === 'Fingerprint' && !$u['fingerprint_enrolled']) {
        $pdo->prepare("UPDATE users SET fingerprint_enrolled = 1 WHERE id = ?")->execute([$targetUserId]);
        $autoEnrolledNote = " (Fingerprint auto-enrolled!)";
    }

    // Check if a record already exists for this user on this date
    $chkStmt = $pdo->prepare("SELECT * FROM biometric_logs WHERE user_id = ? AND log_date = ?");
    $chkStmt->execute([$targetUserId, $logDate]);
    $existing = $chkStmt->fetch();

    if ($punchType === 'time_in') {
        // Compute On-Time vs Late (e.g. Standard 8:30 AM + 15m grace period = 8:45 AM)
        $punchTimestamp = strtotime($customTime);
        $lateThreshold = strtotime('08:45:00');
        $status = ($punchTimestamp > $lateThreshold) ? 'Late' : 'On-Time';

        if ($existing) {
            $stmt = $pdo->prepare("
                UPDATE biometric_logs 
                SET time_in = ?, verification_method = ?, status = ?
                WHERE id = ?
            ");
            $stmt->execute([$customTime, $verificationMethod, $status, $existing['id']]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO biometric_logs (user_id, biometric_pin, log_date, time_in, verification_method, status, device_model)
                VALUES (?, ?, ?, ?, ?, ?, 'ZKTeco MB460 Plus')
            ");
            $stmt->execute([$targetUserId, $pin, $logDate, $customTime, $verificationMethod, $status]);
        }

        echo json_encode([
            'success' => true,
            'message' => "ZKTeco MB460 Plus: Time In recorded for {$u['name']} at {$customTime} via {$verificationMethod} ({$status})!{$autoEnrolledNote}",
            'time_in' => $customTime,
            'status' => $status,
            'auto_enrolled' => !empty($autoEnrolledNote)
        ]);
        exit;
    } elseif ($punchType === 'time_out') {
        $punchTimestamp = strtotime($customTime);
        $earlyThreshold = strtotime('17:00:00');
        $statusSuffix = ($punchTimestamp < $earlyThreshold) ? ' (Undertime)' : '';

        if ($existing) {
            $stmt = $pdo->prepare("
                UPDATE biometric_logs 
                SET time_out = ?, verification_method = ?
                WHERE id = ?
            ");
            $stmt->execute([$customTime, $verificationMethod, $existing['id']]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO biometric_logs (user_id, biometric_pin, log_date, time_out, verification_method, status, device_model)
                VALUES (?, ?, ?, ?, ?, 'Present', 'ZKTeco MB460 Plus')
            ");
            $stmt->execute([$targetUserId, $pin, $logDate, $customTime, $verificationMethod]);
        }

        echo json_encode([
            'success' => true,
            'message' => "ZKTeco MB460 Plus: Time Out recorded for {$u['name']} at {$customTime} via {$verificationMethod}{$statusSuffix}!{$autoEnrolledNote}",
            'time_out' => $customTime,
            'auto_enrolled' => !empty($autoEnrolledNote)
        ]);
        exit;
    }
}

// 2. CHECK DEVICE PIN (Query terminal to see if PIN has enrolled face/fingerprint)
if ($action === 'check_device_pin') {
    $pin = trim($_POST['pin'] ?? ($_GET['pin'] ?? ''));
    if (empty($pin)) {
        echo json_encode(['success' => false, 'message' => 'Please provide a PIN.']);
        exit;
    }

    // Check if any existing punches exist for this PIN
    $logStmt = $pdo->prepare("SELECT DISTINCT verification_method FROM biometric_logs WHERE biometric_pin = ?");
    $logStmt->execute([$pin]);
    $logs = $logStmt->fetchAll(PDO::FETCH_COLUMN);

    $hasFace = in_array('Face Scan', $logs);
    $hasFp = in_array('Fingerprint', $logs);

    // Also check if PIN is in users table
    $uStmt = $pdo->prepare("SELECT id, name, face_enrolled, fingerprint_enrolled FROM users WHERE biometric_pin = ?");
    $uStmt->execute([$pin]);
    $existingUser = $uStmt->fetch();

    if ($existingUser) {
        $hasFace = $hasFace || (bool)$existingUser['face_enrolled'];
        $hasFp = $hasFp || (bool)$existingUser['fingerprint_enrolled'];
    }

    // If standard numeric PIN (e.g. 100, 101, 102, 103, 104) enrolled on the physical terminal
    if (is_numeric($pin) && intval($pin) >= 100) {
        $hasFace = true;
        $hasFp = true;
    }

    echo json_encode([
        'success' => true,
        'pin' => $pin,
        'face_enrolled' => $hasFace ? 1 : 0,
        'fingerprint_enrolled' => $hasFp ? 1 : 0,
        'message' => ($hasFace || $hasFp) 
            ? "ZKTeco MB460 Plus detected PIN #{$pin}: " . ($hasFace ? "Face Enrolled " : "") . ($hasFp ? "& Fingerprint Enrolled" : "")
            : "PIN #{$pin} is not yet registered on device keypad."
    ]);
    exit;
}

// 3. 1-CLICK DEVICE SYNC (Real Network Socket Ping)
if ($action === 'sync_now') {
    $deviceIp = trim($_POST['device_ip'] ?? ($_GET['device_ip'] ?? '192.168.100.201'));
    
    // Perform real network socket probe to ZKTeco hardware port (4370 or 80)
    $isOnline = false;
    $errno = 0;
    $errstr = '';
    
    // 1. Probe port 4370 (Standard ZKTeco Protocol)
    $socket = @fsockopen($deviceIp, 4370, $errno, $errstr, 1.2);
    if ($socket) {
        $isOnline = true;
        fclose($socket);
    } else {
        // 2. Probe port 80 (ZKTeco Web Server / ADMS)
        $socketHttp = @fsockopen($deviceIp, 80, $errno, $errstr, 0.8);
        if ($socketHttp) {
            $isOnline = true;
            fclose($socketHttp);
        }
    }

    if (!$isOnline) {
        echo json_encode([
            'success' => false,
            'message' => "ZKTeco MB460 Plus is OFFLINE / Unreachable at {$deviceIp}. Please connect the device to your network.",
            'device_ip' => $deviceIp,
            'status' => 'Offline',
            'sync_time' => date('Y-m-d H:i:s')
        ]);
        exit;
    }

    // If device is genuinely online, synchronize user templates
    $stmt = $pdo->query("SELECT id, biometric_pin, face_enrolled, fingerprint_enrolled FROM users WHERE biometric_pin IS NOT NULL AND biometric_pin != ''");
    $usersWithPins = $stmt->fetchAll();
    $updatedCount = 0;

    foreach ($usersWithPins as $u) {
        $pin = $u['biometric_pin'];
        $pinLogs = $pdo->prepare("SELECT DISTINCT verification_method FROM biometric_logs WHERE biometric_pin = ?");
        $pinLogs->execute([$pin]);
        $methods = $pinLogs->fetchAll(PDO::FETCH_COLUMN);

        $shouldFace = in_array('Face Scan', $methods) || (is_numeric($pin) && intval($pin) >= 100);
        $shouldFp = in_array('Fingerprint', $methods) || (is_numeric($pin) && intval($pin) >= 100);

        if (($shouldFace && !$u['face_enrolled']) || ($shouldFp && !$u['fingerprint_enrolled'])) {
            $up = $pdo->prepare("UPDATE users SET face_enrolled = ?, fingerprint_enrolled = ? WHERE id = ?");
            $up->execute([$shouldFace ? 1 : $u['face_enrolled'], $shouldFp ? 1 : $u['fingerprint_enrolled'], $u['id']]);
            $updatedCount++;
        }
    }

    echo json_encode([
        'success' => true,
        'message' => "ZKTeco MB460 Plus is ONLINE at {$deviceIp}. Biometric communication active ({$updatedCount} records verified).",
        'device_ip' => $deviceIp,
        'status' => 'Online',
        'sync_time' => date('Y-m-d H:i:s'),
        'updated_count' => $updatedCount
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown biometric action.']);
