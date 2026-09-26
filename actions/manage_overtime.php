<?php
// actions/manage_overtime.php - Overtime pre-authorization and approval
require_once __DIR__ . '/../auth.php';
requireLogin();

header('Content-Type: application/json');

$currentUser = getCurrentUser();
$isAdmin = ($currentUser['role'] === 'admin');

$action = $_POST['action'] ?? ($_GET['action'] ?? 'get_ot_requests');
if ($action !== 'get_ot_requests') requirePostWithCsrf();

// 1. GET OT REQUESTS
if ($action === 'get_ot_requests') {
    if ($isAdmin) {
        $stmt = $pdo->query("
            SELECT ot.*, u.name as user_name, u.title as user_title, u.biometric_pin,
                   admin_u.name as approver_name
            FROM overtime_requests ot
            JOIN users u ON ot.user_id = u.id
            LEFT JOIN users admin_u ON ot.approved_by = admin_u.id
            ORDER BY ot.created_at DESC
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT ot.*, u.name as user_name, u.title as user_title, u.biometric_pin,
                   admin_u.name as approver_name
            FROM overtime_requests ot
            JOIN users u ON ot.user_id = u.id
            LEFT JOIN users admin_u ON ot.approved_by = admin_u.id
            WHERE ot.user_id = ?
            ORDER BY ot.created_at DESC
        ");
        $stmt->execute([$currentUser['id']]);
    }

    $requests = $stmt->fetchAll();
    foreach ($requests as &$r) {
        $r['ot_date_formatted'] = date('M j, Y (D)', strtotime($r['ot_date']));
        $r['created_at_formatted'] = date('M j, Y g:i A', strtotime($r['created_at']));
    }

    echo json_encode([
        'success' => true,
        'requests' => $requests
    ]);
    exit;
}

// 2. SUBMIT OT REQUEST
if ($action === 'submit_ot') {
    $targetUserId = $currentUser['id'];
    if ($isAdmin && !empty($_POST['user_id'])) {
        $targetUserId = intval($_POST['user_id']);
    }

    $otDate = trim($_POST['ot_date'] ?? '');
    $estimatedHours = floatval($_POST['estimated_hours'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    $parsedDate = DateTime::createFromFormat('!Y-m-d', $otDate);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $otDate) {
        echo json_encode(['success' => false, 'message' => 'Please select a valid date.']);
        exit;
    }

    if ($estimatedHours < 0.5 || $estimatedHours > 16) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid estimated overtime duration (0.5 to 16 hrs).']);
        exit;
    }

    if (empty($reason)) {
        echo json_encode(['success' => false, 'message' => 'Please provide the project / audit reason for overtime.']);
        exit;
    }

    $initialStatus = $isAdmin ? 'Approved' : 'Pending';
    $approvedBy = $isAdmin ? $currentUser['id'] : null;
    $decidedAt = $isAdmin ? date('Y-m-d H:i:s') : null;

    $stmt = $pdo->prepare("
        INSERT INTO overtime_requests (user_id, ot_date, estimated_hours, reason, status, approved_by, decided_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$targetUserId, $otDate, $estimatedHours, $reason, $initialStatus, $approvedBy, $decidedAt]);
    $otId = $pdo->lastInsertId();

    if ($isAdmin) {
        // Apply OT hours to biometric_logs
        $up = $pdo->prepare("UPDATE biometric_logs SET overtime_hours = ? WHERE user_id = ? AND log_date = ?");
        $up->execute([$estimatedHours, $targetUserId, $otDate]);
    } else {
        // Notify Admin of new overtime request
        require_once __DIR__ . '/../services/mailer.php';
        sendLeaveNotification($pdo, 'ot_filed', [
            'employee_name' => $currentUser['name'],
            'employee_email' => $currentUser['email'],
            'ot_date' => $otDate,
            'estimated_hours' => $estimatedHours,
            'reason' => $reason
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => $isAdmin 
            ? "Overtime of {$estimatedHours} hrs pre-approved and logged." 
            : 'Overtime pre-authorization request submitted to Admin for approval.',
        'ot_id' => $otId
    ]);
    exit;
}

// 3. APPROVE OT REQUEST (Admin only)
if ($action === 'approve_ot') {
    if (!$isAdmin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Admin access required.']);
        exit;
    }

    $id = intval($_POST['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM overtime_requests WHERE id = ?");
    $stmt->execute([$id]);
    $ot = $stmt->fetch();

    if (!$ot) {
        echo json_encode(['success' => false, 'message' => 'Overtime request not found.']);
        exit;
    }
    if ($ot['status'] !== 'Pending') {
        echo json_encode(['success' => false, 'message' => 'This overtime request has already been decided.']);
        exit;
    }

    $up = $pdo->prepare("
        UPDATE overtime_requests 
        SET status = 'Approved', approved_by = ?, decided_at = CURRENT_TIMESTAMP 
        WHERE id = ?
    ");
    $up->execute([$currentUser['id'], $id]);

    // Apply overtime hours to biometric_logs if a record exists for that date
    $bioUp = $pdo->prepare("
        UPDATE biometric_logs 
        SET overtime_hours = ? 
        WHERE user_id = ? AND log_date = ?
    ");
    $bioUp->execute([$ot['estimated_hours'], $ot['user_id'], $ot['ot_date']]);

    // Notify employee of approval
    $empStmt = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
    $empStmt->execute([$ot['user_id']]);
    $emp = $empStmt->fetch();
    if ($emp && !empty($emp['email'])) {
        require_once __DIR__ . '/../services/mailer.php';
        sendLeaveNotification($pdo, 'ot_approved', [
            'employee_name' => $emp['name'],
            'employee_email' => $emp['email'],
            'ot_date' => $ot['ot_date'],
            'estimated_hours' => $ot['estimated_hours'],
            'approver_name' => $currentUser['name']
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => "Overtime request for {$ot['estimated_hours']} hrs approved."
    ]);
    exit;
}

// 4. REJECT OT REQUEST (Admin only)
if ($action === 'reject_ot') {
    if (!$isAdmin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Admin access required.']);
        exit;
    }

    $id = intval($_POST['id'] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM overtime_requests WHERE id = ?");
    $stmt->execute([$id]);
    $ot = $stmt->fetch();
    if (!$ot || $ot['status'] !== 'Pending') {
        echo json_encode(['success' => false, 'message' => 'Overtime request not found or already decided.']);
        exit;
    }

    $up = $pdo->prepare("
        UPDATE overtime_requests 
        SET status = 'Rejected', approved_by = ?, decided_at = CURRENT_TIMESTAMP 
        WHERE id = ?
    ");
    $up->execute([$currentUser['id'], $id]);

    // Notify employee of rejection
    if ($ot) {
        $empStmt = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
        $empStmt->execute([$ot['user_id']]);
        $emp = $empStmt->fetch();
        if ($emp && !empty($emp['email'])) {
            require_once __DIR__ . '/../services/mailer.php';
            sendLeaveNotification($pdo, 'ot_rejected', [
                'employee_name' => $emp['name'],
                'employee_email' => $emp['email'],
                'ot_date' => $ot['ot_date'],
                'estimated_hours' => $ot['estimated_hours'],
                'approver_name' => $currentUser['name']
            ]);
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Overtime request rejected.'
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
