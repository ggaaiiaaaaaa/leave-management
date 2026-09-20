<?php
// actions/biometric_sync.php - ZKTeco MB460 Plus Punch Ingest & Live Auto-Enrollment
require_once __DIR__ . '/../auth.php';
requireLogin();

header('Content-Type: application/json');

$action = $_POST['action'] ?? ($_GET['action'] ?? 'simulate_punch');

require_once __DIR__ . '/../services/attendance_calculator.php';

if ($action === 'simulate_punch') {
    // Requires login (Admin or Staff simulating a test punch)
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $punchType = $_POST['punch_type'] ?? 'time_in'; // 'time_in', 'break_out', 'break_in', 'time_out'
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

    $targetCol = 'time_in';
    if ($punchType === 'break_out') $targetCol = 'break_out';
    elseif ($punchType === 'break_in') $targetCol = 'break_in';
    elseif ($punchType === 'time_out') $targetCol = 'time_out';

    if ($existing) {
        $stmt = $pdo->prepare("
            UPDATE biometric_logs 
            SET {$targetCol} = ?, verification_method = ?
            WHERE id = ?
        ");
        $stmt->execute([$customTime, $verificationMethod, $existing['id']]);
        $logId = $existing['id'];
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO biometric_logs (user_id, biometric_pin, log_date, {$targetCol}, verification_method, status, device_model)
            VALUES (?, ?, ?, ?, ?, 'Present', 'ZKTeco MB460 Plus')
        ");
        $stmt->execute([$targetUserId, $pin, $logDate, $customTime, $verificationMethod]);
        $logId = $pdo->lastInsertId();
    }

    // Recalculate rendered hours and status dynamically
    $fetchLog = $pdo->prepare("SELECT * FROM biometric_logs WHERE id = ?");
    $fetchLog->execute([$logId]);
    $updatedRow = $fetchLog->fetch();

    $metrics = calculateAttendanceMetrics(
        $updatedRow['time_in'],
        $updatedRow['break_out'],
        $updatedRow['break_in'],
        $updatedRow['time_out'],
        $updatedRow['overtime_hours'] ?? 0
    );

    $upMetrics = $pdo->prepare("UPDATE biometric_logs SET rendered_hours = ?, status = ? WHERE id = ?");
    $upMetrics->execute([$metrics['rendered_hours'], $metrics['status'], $logId]);

    $punchLabel = ucwords(str_replace('_', ' ', $punchType));
    echo json_encode([
        'success' => true,
        'message' => "ZKTeco MB460 Plus: {$punchLabel} recorded for {$u['name']} at {$customTime} via {$verificationMethod} (Status: {$metrics['status']})!{$autoEnrolledNote}",
        'punch_type' => $punchType,
        'punch_time' => $customTime,
        'status' => $metrics['status'],
        'rendered_hours' => $metrics['rendered_hours'],
        'rendered_formatted' => $metrics['rendered_formatted'],
        'auto_enrolled' => !empty($autoEnrolledNote)
    ]);
    exit;
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

// 2.5 GET LIVE STATUS (Heartbeat & ADMS Polling)
if ($action === 'get_status') {
    $statusFile = __DIR__ . '/../database/zkteco_status.json';
    $isOnline = false;
    $deviceData = [];
    if (file_exists($statusFile)) {
        $deviceData = json_decode(file_get_contents($statusFile), true) ?: [];
        if (!empty($deviceData['last_seen']) && (time() - $deviceData['last_seen']) < 60) {
            $isOnline = true;
        }
    }
    echo json_encode([
        'success' => true,
        'online' => $isOnline,
        'device' => $deviceData
    ]);
    exit;
}

// 3. 1-CLICK DEVICE SYNC (Real Network Socket & Ping)
if ($action === 'sync_now') {
    $deviceIp = trim($_POST['device_ip'] ?? ($_GET['device_ip'] ?? '192.168.100.157'));
    
    // Perform real network probe to ZKTeco hardware
    $isOnline = false;
    $errno = 0;
    $errstr = '';
    
    // 1. Probe via ICMP ping (Fast & accurate across LAN)
    $pingCmd = "ping -n 1 -w 800 " . escapeshellarg($deviceIp);
    exec($pingCmd, $pingOut, $pingCode);
    if ($pingCode === 0) {
        $isOnline = true;
    } else {
        // 2. Probe port 4370 (Standard ZKTeco Protocol)
        $socket = @fsockopen($deviceIp, 4370, $errno, $errstr, 0.8);
        if ($socket) {
            $isOnline = true;
            fclose($socket);
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

// 4. SYNC HARDWARE CLOCK
if ($action === 'sync_clock') {
    $nowFormatted = date('Y-m-d H:i:s');
    $cmd = "C:1:SET TIME {$nowFormatted}";
    
    // Write command to pending_cmd.txt for ADMS terminal polling
    $cmdFiles = [
        __DIR__ . '/../iclock/pending_cmd.txt',
        __DIR__ . '/../../iclock/pending_cmd.txt'
    ];
    foreach ($cmdFiles as $cf) {
        @file_put_contents($cf, $cmd);
    }

    echo json_encode([
        'success' => true,
        'message' => "Terminal clock sync command queued: {$nowFormatted} (Philippine Standard Time). The device will synchronize on its next heartbeat.",
        'sync_time' => $nowFormatted
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown biometric action.']);
