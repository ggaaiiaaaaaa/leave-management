<?php
// actions/get_dtr_logs.php - Fetch Daily Time Record (DTR) reconciled with approved leaves
require_once __DIR__ . '/../auth.php';
requireLogin();

header('Content-Type: application/json');

$date = $_GET['date'] ?? date('Y-m-d');

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

// Fetch all users
$usersStmt = $pdo->query("SELECT id, name, title, gender, biometric_pin, avatar_path, avatar_initials FROM users ORDER BY id ASC");
$users = $usersStmt->fetchAll();

// Fetch all biometric logs for the date
$bioStmt = $pdo->prepare("SELECT * FROM biometric_logs WHERE log_date = ?");
$bioStmt->execute([$date]);
$bioLogs = [];
foreach ($bioStmt->fetchAll() as $log) {
    $bioLogs[$log['user_id']] = $log;
}

// Fetch all approved leaves covering this date
$leaveStmt = $pdo->prepare("
    SELECT user_id, leave_type_label, ref_no 
    FROM leave_requests 
    WHERE status = 'Approved' AND ? BETWEEN start_date AND end_date
");
$leaveStmt->execute([$date]);
$approvedLeaves = [];
foreach ($leaveStmt->fetchAll() as $lv) {
    $approvedLeaves[$lv['user_id']] = $lv;
}

$dtrRecords = [];
$presentCount = 0;
$onLeaveCount = 0;
$expectedCount = 0;

foreach ($users as $u) {
    $uid = $u['id'];
    $bio = $bioLogs[$uid] ?? null;
    $leave = $approvedLeaves[$uid] ?? null;

    $isOnLeave = !empty($leave);
    $hasPunched = !empty($bio) && (!empty($bio['time_in']) || !empty($bio['time_out']));

    $status = 'Expected';
    $statusType = 'neutral';
    $statusText = 'Not Yet Clocked In';

    if ($isOnLeave) {
        $status = 'On Leave';
        $statusType = 'leave';
        $statusText = "On Approved Leave ({$leave['leave_type_label']})";
        $onLeaveCount++;
    } elseif ($hasPunched) {
        $status = $bio['status'] ?? 'Present';
        $statusType = ($status === 'Late') ? 'warning' : 'success';
        $statusText = $status;
        $presentCount++;
    } else {
        $expectedCount++;
    }

    $format12h = function($t) {
        if (empty($t)) return null;
        $ts = strtotime($t);
        return $ts ? date('g:i:s A', $ts) : $t;
    };

    $dtrRecords[] = [
        'user_id' => $u['id'],
        'name' => $u['name'],
        'title' => $u['title'],
        'avatar_path' => $u['avatar_path'],
        'avatar_initials' => $u['avatar_initials'],
        'biometric_pin' => $u['biometric_pin'] ?: strval($u['id']),
        'time_in' => $format12h($bio['time_in'] ?? null),
        'break_out' => $format12h($bio['break_out'] ?? null),
        'break_in' => $format12h($bio['break_in'] ?? null),
        'time_out' => $format12h($bio['time_out'] ?? null),
        'raw_time_in' => $bio['time_in'] ?? null,
        'raw_break_out' => $bio['break_out'] ?? null,
        'raw_break_in' => $bio['break_in'] ?? null,
        'raw_time_out' => $bio['time_out'] ?? null,
        'verification_method' => $bio['verification_method'] ?? ($isOnLeave ? 'System Record' : '—'),
        'status' => $status,
        'status_type' => $statusType,
        'status_text' => $statusText,
        'is_on_leave' => $isOnLeave,
        'leave_info' => $leave
    ];
}

echo json_encode([
    'success' => true,
    'date' => $date,
    'total_staff' => count($users),
    'present_count' => $presentCount,
    'on_leave_count' => $onLeaveCount,
    'expected_count' => $expectedCount,
    'records' => $dtrRecords
]);
