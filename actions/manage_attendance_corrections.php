<?php
// actions/manage_attendance_corrections.php - Missed punch adjustment requests
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../services/attendance_calculator.php';
requireLogin();

header('Content-Type: application/json');

$currentUser = getCurrentUser();
$isAdmin = ($currentUser['role'] === 'admin');

$action = $_POST['action'] ?? ($_GET['action'] ?? 'get_corrections');

// 1. GET CORRECTIONS
if ($action === 'get_corrections') {
    if ($isAdmin) {
        $stmt = $pdo->query("
            SELECT ac.*, u.name as user_name, u.title as user_title, u.biometric_pin,
                   admin_u.name as approver_name
            FROM attendance_corrections ac
            JOIN users u ON ac.user_id = u.id
            LEFT JOIN users admin_u ON ac.approved_by = admin_u.id
            ORDER BY ac.created_at DESC
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT ac.*, u.name as user_name, u.title as user_title, u.biometric_pin,
                   admin_u.name as approver_name
            FROM attendance_corrections ac
            JOIN users u ON ac.user_id = u.id
            LEFT JOIN users admin_u ON ac.approved_by = admin_u.id
            WHERE ac.user_id = ?
            ORDER BY ac.created_at DESC
        ");
        $stmt->execute([$currentUser['id']]);
    }

    $corrections = $stmt->fetchAll();
    
    // Format times for display
    foreach ($corrections as &$c) {
        $c['time_in_12'] = formatTimeTo12Hour($c['time_in']);
        $c['break_out_12'] = formatTimeTo12Hour($c['break_out']);
        $c['break_in_12'] = formatTimeTo12Hour($c['break_in']);
        $c['time_out_12'] = formatTimeTo12Hour($c['time_out']);
        $c['created_at_formatted'] = date('M j, Y g:i A', strtotime($c['created_at']));
        $c['target_date_formatted'] = date('M j, Y (D)', strtotime($c['target_date']));
    }

    echo json_encode([
        'success' => true,
        'corrections' => $corrections
    ]);
    exit;
}

// 2. SUBMIT CORRECTION
if ($action === 'submit_correction') {
    $targetUserId = $currentUser['id'];
    if ($isAdmin && !empty($_POST['user_id'])) {
        $targetUserId = intval($_POST['user_id']);
    }

    $targetDate = trim($_POST['target_date'] ?? '');
    $timeIn = trim($_POST['time_in'] ?? '') ?: null;
    $breakOut = trim($_POST['break_out'] ?? '') ?: null;
    $breakIn = trim($_POST['break_in'] ?? '') ?: null;
    $timeOut = trim($_POST['time_out'] ?? '') ?: null;
    $reason = trim($_POST['reason'] ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
        echo json_encode(['success' => false, 'message' => 'Please provide a valid date.']);
        exit;
    }

    if (empty($reason)) {
        echo json_encode(['success' => false, 'message' => 'Please state the reason for attendance correction.']);
        exit;
    }

    if (!$timeIn && !$breakOut && !$breakIn && !$timeOut) {
        echo json_encode(['success' => false, 'message' => 'Please provide at least one punch timestamp to adjust.']);
        exit;
    }

    // Direct auto-approval if submitted by Admin
    $initialStatus = $isAdmin ? 'Approved' : 'Pending';
    $approvedBy = $isAdmin ? $currentUser['id'] : null;
    $decidedAt = $isAdmin ? date('Y-m-d H:i:s') : null;

    $stmt = $pdo->prepare("
        INSERT INTO attendance_corrections 
        (user_id, target_date, time_in, break_out, break_in, time_out, reason, status, approved_by, decided_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $targetUserId, $targetDate, $timeIn, $breakOut, $breakIn, $timeOut, 
        $reason, $initialStatus, $approvedBy, $decidedAt
    ]);
    $correctionId = $pdo->lastInsertId();

    // If auto-approved by Admin, apply immediately to biometric_logs
    if ($isAdmin) {
        applyCorrectionToBiometrics($pdo, $targetUserId, $targetDate, $timeIn, $breakOut, $breakIn, $timeOut);
    }

    echo json_encode([
        'success' => true,
        'message' => $isAdmin 
            ? 'Attendance record corrected and updated in DTR immediately.' 
            : 'Attendance correction request submitted to Admin for approval.',
        'correction_id' => $correctionId
    ]);
    exit;
}

// 3. APPROVE CORRECTION (Admin only)
if ($action === 'approve_correction') {
    if (!$isAdmin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Admin access required.']);
        exit;
    }

    $id = intval($_POST['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM attendance_corrections WHERE id = ?");
    $stmt->execute([$id]);
    $corr = $stmt->fetch();

    if (!$corr) {
        echo json_encode(['success' => false, 'message' => 'Correction request not found.']);
        exit;
    }

    // Update correction status
    $up = $pdo->prepare("
        UPDATE attendance_corrections 
        SET status = 'Approved', approved_by = ?, decided_at = CURRENT_TIMESTAMP 
        WHERE id = ?
    ");
    $up->execute([$currentUser['id'], $id]);

    // Apply corrected punch data directly to biometric_logs
    applyCorrectionToBiometrics($pdo, $corr['user_id'], $corr['target_date'], $corr['time_in'], $corr['break_out'], $corr['break_in'], $corr['time_out']);

    echo json_encode([
        'success' => true,
        'message' => 'Attendance correction approved and DTR updated successfully.'
    ]);
    exit;
}

// 4. REJECT CORRECTION (Admin only)
if ($action === 'reject_correction') {
    if (!$isAdmin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Admin access required.']);
        exit;
    }

    $id = intval($_POST['id'] ?? 0);
    $up = $pdo->prepare("
        UPDATE attendance_corrections 
        SET status = 'Rejected', approved_by = ?, decided_at = CURRENT_TIMESTAMP 
        WHERE id = ?
    ");
    $up->execute([$currentUser['id'], $id]);

    echo json_encode([
        'success' => true,
        'message' => 'Attendance correction request rejected.'
    ]);
    exit;
}

/**
 * Helper: Upsert corrected punches into biometric_logs and recalculate rendered hours
 */
function applyCorrectionToBiometrics($pdo, $userId, $logDate, $timeIn, $breakOut, $breakIn, $timeOut) {
    $uStmt = $pdo->prepare("SELECT biometric_pin FROM users WHERE id = ?");
    $uStmt->execute([$userId]);
    $pin = $uStmt->fetchColumn() ?: strval($userId);

    $chk = $pdo->prepare("SELECT * FROM biometric_logs WHERE user_id = ? AND log_date = ?");
    $chk->execute([$userId, $logDate]);
    $existing = $chk->fetch();

    if ($existing) {
        $finalIn = $timeIn ?: $existing['time_in'];
        $finalBOut = $breakOut ?: $existing['break_out'];
        $finalBIn = $breakIn ?: $existing['break_in'];
        $finalOut = $timeOut ?: $existing['time_out'];

        $metrics = calculateAttendanceMetrics($finalIn, $finalBOut, $finalBIn, $finalOut, $existing['overtime_hours'] ?? 0);

        $up = $pdo->prepare("
            UPDATE biometric_logs 
            SET time_in = ?, break_out = ?, break_in = ?, time_out = ?,
                rendered_hours = ?, status = ?, verification_method = 'Admin Adjustment'
            WHERE id = ?
        ");
        $up->execute([$finalIn, $finalBOut, $finalBIn, $finalOut, $metrics['rendered_hours'], $metrics['status'], $existing['id']]);
    } else {
        $metrics = calculateAttendanceMetrics($timeIn, $breakOut, $breakIn, $timeOut, 0);

        $ins = $pdo->prepare("
            INSERT INTO biometric_logs 
            (user_id, biometric_pin, log_date, time_in, break_out, break_in, time_out, rendered_hours, status, verification_method, device_model)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Admin Adjustment', 'System Record')
        ");
        $ins->execute([$userId, $pin, $logDate, $timeIn, $breakOut, $breakIn, $timeOut, $metrics['rendered_hours'], $metrics['status']]);
    }
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
