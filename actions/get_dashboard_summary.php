<?php
// actions/get_dashboard_summary.php - Unified Dashboard Summary for Admin and Staff
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../services/attendance_calculator.php';
requireLogin();

header('Content-Type: application/json');

$currentUser = getCurrentUser();
$isAdmin = ($currentUser['role'] === 'admin');
$today = date('Y-m-d');
$currentMonthStart = date('Y-m-01');
$currentMonthEnd = date('Y-m-t');

// 1. ZKTeco Hardware Status
$zkStatusFile = LEAVE_PRIVATE_DIR . '/zkteco_status.json';
$zkIsOnline = false;
$zkData = [];
if (file_exists($zkStatusFile)) {
    $zkData = json_decode(file_get_contents($zkStatusFile), true) ?: [];
    if (!empty($zkData['last_seen']) && (time() - $zkData['last_seen']) < 60) {
        $zkIsOnline = true;
    }
}

// 2. Upcoming events (next 7 days: leaves & holidays)
$nextWeek = date('Y-m-d', strtotime('+7 days'));
$holidaysStmt = $pdo->prepare("SELECT title, holiday_date, holiday_type FROM holidays WHERE holiday_date BETWEEN ? AND ? ORDER BY holiday_date ASC");
$holidaysStmt->execute([$today, $nextWeek]);
$upcomingHolidays = $holidaysStmt->fetchAll();

$upcomingLeavesStmt = $pdo->prepare("
    SELECT r.id, r.user_id, r.leave_type_label, r.start_date, r.end_date, u.name as employee_name, u.avatar_path, u.avatar_initials
    FROM leave_requests r
    JOIN users u ON r.user_id = u.id
    WHERE r.status = 'Approved' AND (r.start_date BETWEEN ? AND ? OR ? BETWEEN r.start_date AND r.end_date)
    ORDER BY r.start_date ASC
");
$upcomingLeavesStmt->execute([$today, $nextWeek, $today]);
$upcomingLeaves = $upcomingLeavesStmt->fetchAll();

if ($isAdmin) {
    // ADMIN DASHBOARD DATA
    $allUsers = $pdo->query("SELECT id, name, title, biometric_pin, avatar_path, avatar_initials FROM users ORDER BY name ASC")->fetchAll();
    $totalStaff = count($allUsers);

    // Today's punches
    $bioStmt = $pdo->prepare("
        SELECT b.*, u.name, u.title, u.avatar_path, u.avatar_initials
        FROM biometric_logs b
        JOIN users u ON b.user_id = u.id
        WHERE b.log_date = ?
        ORDER BY b.id DESC
    ");
    $bioStmt->execute([$today]);
    $todayPunches = $bioStmt->fetchAll();

    $presentCount = 0;
    $onBreakCount = 0;
    foreach ($todayPunches as $p) {
        if (!empty($p['break_out']) && empty($p['break_in']) && empty($p['time_out'])) {
            $onBreakCount++;
        }
        if (!empty($p['time_in'])) {
            $presentCount++;
        }
    }

    // Today's approved leaves
    $activeLeavesStmt = $pdo->prepare("
        SELECT user_id, leave_type_label 
        FROM leave_requests 
        WHERE status = 'Approved' AND ? BETWEEN start_date AND end_date
    ");
    $activeLeavesStmt->execute([$today]);
    $activeLeaves = $activeLeavesStmt->fetchAll();
    $onLeaveCount = count($activeLeaves);

    // Pending Action Items
    // A. Pending Leaves
    $pLeaves = $pdo->query("
        SELECT 'leave' as item_type, r.id, r.user_id, r.ref_no, r.leave_type_label as title,
               r.days_count, r.start_date, r.end_date, r.reason, r.created_at,
               u.name as employee_name, u.title as employee_title, u.avatar_path, u.avatar_initials
        FROM leave_requests r
        JOIN users u ON r.user_id = u.id
        WHERE r.status = 'Pending'
        ORDER BY r.created_at ASC
    ")->fetchAll();

    // B. Pending Punch Corrections
    $pCorrections = $pdo->query("
        SELECT 'correction' as item_type, c.id, c.user_id, 'Missed Punch Adjustment' as title,
               c.target_date, c.time_in, c.break_out, c.break_in, c.time_out, c.reason, c.created_at,
               u.name as employee_name, u.title as employee_title, u.avatar_path, u.avatar_initials
        FROM attendance_corrections c
        JOIN users u ON c.user_id = u.id
        WHERE c.status = 'Pending'
        ORDER BY c.created_at ASC
    ")->fetchAll();

    // C. Pending Overtime
    $pOt = $pdo->query("
        SELECT 'ot' as item_type, ot.id, ot.user_id, 'Overtime Pre-Authorization' as title,
               ot.ot_date, ot.estimated_hours, ot.reason, ot.created_at,
               u.name as employee_name, u.title as employee_title, u.avatar_path, u.avatar_initials
        FROM overtime_requests ot
        JOIN users u ON ot.user_id = u.id
        WHERE ot.status = 'Pending'
        ORDER BY ot.created_at ASC
    ")->fetchAll();

    $combinedActions = array_merge($pLeaves, $pCorrections, $pOt);
    usort($combinedActions, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });

    // Format times for today's punches
    foreach ($todayPunches as &$tp) {
        $tp['time_in_12'] = formatTimeTo12Hour($tp['time_in']);
        $tp['break_out_12'] = formatTimeTo12Hour($tp['break_out']);
        $tp['break_in_12'] = formatTimeTo12Hour($tp['break_in']);
        $tp['time_out_12'] = formatTimeTo12Hour($tp['time_out']);
        $tp['rendered_formatted'] = formatHoursToReadable($tp['rendered_hours']);
    }

    echo json_encode([
        'success' => true,
        'role' => 'admin',
        'today' => $today,
        'today_formatted' => date('l, F j, Y'),
        'counts' => [
            'total_staff' => $totalStaff,
            'present_count' => $presentCount,
            'on_break_count' => $onBreakCount,
            'on_leave_count' => $onLeaveCount,
            'pending_leaves' => count($pLeaves),
            'pending_corrections' => count($pCorrections),
            'pending_ot' => count($pOt),
            'total_pending_actions' => count($combinedActions)
        ],
        'pending_actions' => array_slice($combinedActions, 0, 10),
        'today_punches' => array_slice($todayPunches, 0, 8),
        'upcoming_holidays' => $upcomingHolidays,
        'upcoming_leaves' => $upcomingLeaves,
        'zk_status' => [
            'online' => $zkIsOnline,
            'last_seen' => $zkData['last_seen_formatted'] ?? null,
            'ip' => (!empty($zkData['ip']) && $zkData['ip'] !== 'unknown') ? $zkData['ip'] : 'Auto-detecting...',
            'sn' => (!empty($zkData['sn']) && $zkData['sn'] !== 'UNKNOWN') ? $zkData['sn'] : 'Auto-detecting...'
        ]
    ]);
    exit;
} else {
    // STAFF DASHBOARD DATA
    $userId = $currentUser['id'];

    // Today's punch record
    $todayBioStmt = $pdo->prepare("SELECT * FROM biometric_logs WHERE user_id = ? AND log_date = ?");
    $todayBioStmt->execute([$userId, $today]);
    $todayPunch = $todayBioStmt->fetch() ?: null;

    if ($todayPunch) {
        $todayPunch['time_in_12'] = formatTimeTo12Hour($todayPunch['time_in']);
        $todayPunch['break_out_12'] = formatTimeTo12Hour($todayPunch['break_out']);
        $todayPunch['break_in_12'] = formatTimeTo12Hour($todayPunch['break_in']);
        $todayPunch['time_out_12'] = formatTimeTo12Hour($todayPunch['time_out']);
        $todayPunch['rendered_formatted'] = formatHoursToReadable($todayPunch['rendered_hours']);
    }

    // Month rendered hours
    $monthHoursStmt = $pdo->prepare("
        SELECT SUM(rendered_hours) as total_rendered, SUM(overtime_hours) as total_ot
        FROM biometric_logs 
        WHERE user_id = ? AND log_date BETWEEN ? AND ?
    ");
    $monthHoursStmt->execute([$userId, $currentMonthStart, $currentMonthEnd]);
    $monthHours = $monthHoursStmt->fetch();
    $renderedMonthHours = floatval($monthHours['total_rendered'] ?? 0);
    $renderedMonthOt = floatval($monthHours['total_ot'] ?? 0);

    // Leave Balances
    $balStmt = $pdo->prepare("SELECT * FROM leave_balances WHERE user_id = ?");
    $balStmt->execute([$userId]);
    $balances = $balStmt->fetch() ?: [];

    // Pending counts for this user
    $pLeavesCount = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE user_id = ? AND status = 'Pending'");
    $pLeavesCount->execute([$userId]);
    $pLeavesNum = intval($pLeavesCount->fetchColumn());

    $pCorrCount = $pdo->prepare("SELECT COUNT(*) FROM attendance_corrections WHERE user_id = ? AND status = 'Pending'");
    $pCorrCount->execute([$userId]);
    $pCorrNum = intval($pCorrCount->fetchColumn());

    $pOtCount = $pdo->prepare("SELECT COUNT(*) FROM overtime_requests WHERE user_id = ? AND status = 'Pending'");
    $pOtCount->execute([$userId]);
    $pOtNum = intval($pOtCount->fetchColumn());

    // Recent requests filed by this user
    $recentLeaves = $pdo->prepare("
        SELECT 'leave' as type, ref_no as id_label, leave_type_label as title, 
               start_date as date_from, end_date as date_to, status, created_at
        FROM leave_requests 
        WHERE user_id = ? 
        ORDER BY created_at DESC LIMIT 3
    ");
    $recentLeaves->execute([$userId]);

    $recentCorr = $pdo->prepare("
        SELECT 'correction' as type, 'Punch Adj' as id_label, 'Attendance Correction' as title, 
               target_date as date_from, target_date as date_to, status, created_at
        FROM attendance_corrections 
        WHERE user_id = ? 
        ORDER BY created_at DESC LIMIT 3
    ");
    $recentCorr->execute([$userId]);

    $recentOt = $pdo->prepare("
        SELECT 'ot' as type, 'Overtime' as id_label, 'Overtime Request' as title, 
               ot_date as date_from, ot_date as date_to, status, created_at
        FROM overtime_requests 
        WHERE user_id = ? 
        ORDER BY created_at DESC LIMIT 3
    ");
    $recentOt->execute([$userId]);

    $myRecent = array_merge($recentLeaves->fetchAll(), $recentCorr->fetchAll(), $recentOt->fetchAll());
    usort($myRecent, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });

    echo json_encode([
        'success' => true,
        'role' => 'staff',
        'today' => $today,
        'today_formatted' => date('l, F j, Y'),
        'user' => [
            'id' => $currentUser['id'],
            'name' => $currentUser['name'],
            'title' => $currentUser['title'],
            'biometric_pin' => $currentUser['biometric_pin'] ?: strval($currentUser['id']),
            'face_enrolled' => (bool)($currentUser['face_enrolled'] ?? true),
            'fingerprint_enrolled' => (bool)($currentUser['fingerprint_enrolled'] ?? true)
        ],
        'today_punch' => $todayPunch,
        'month_stats' => [
            'rendered_hours' => round($renderedMonthHours, 2),
            'rendered_formatted' => formatHoursToReadable($renderedMonthHours),
            'overtime_hours' => round($renderedMonthOt, 2)
        ],
        'balances' => [
            'vl' => floatval($balances['vl_balance'] ?? 12.0),
            'sl' => floatval($balances['sl_balance'] ?? 10.0),
            'emergency' => floatval($balances['emergency_balance'] ?? 5.0)
        ],
        'pending_counts' => [
            'total' => $pLeavesNum + $pCorrNum + $pOtNum,
            'leaves' => $pLeavesNum,
            'corrections' => $pCorrNum,
            'ot' => $pOtNum
        ],
        'recent_requests' => array_slice($myRecent, 0, 5),
        'upcoming_holidays' => $upcomingHolidays,
        'upcoming_leaves' => $upcomingLeaves
    ]);
    exit;
}
