<?php
// actions/get_presence.php - Real-time Office Presence Roster
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../services/attendance_calculator.php';
requireLogin();

header('Content-Type: application/json');

$today = date('Y-m-d');

// Fetch all users
$users = $pdo->query("SELECT id, name, title, biometric_pin, avatar_path, avatar_initials FROM users ORDER BY name ASC")->fetchAll();

// Fetch today's biometric logs
$bioStmt = $pdo->prepare("SELECT * FROM biometric_logs WHERE log_date = ?");
$bioStmt->execute([$today]);
$bioMap = [];
foreach ($bioStmt->fetchAll() as $b) {
    $bioMap[$b['user_id']] = $b;
}

// Fetch approved leaves today
$leaveStmt = $pdo->prepare("
    SELECT user_id, leave_type_label, ref_no 
    FROM leave_requests 
    WHERE status = 'Approved' AND ? BETWEEN start_date AND end_date
");
$leaveStmt->execute([$today]);
$leaveMap = [];
foreach ($leaveStmt->fetchAll() as $lv) {
    $leaveMap[$lv['user_id']] = $lv;
}

$roster = [];
$counts = [
    'present' => 0,
    'on_break' => 0,
    'on_leave' => 0,
    'not_in' => 0
];

foreach ($users as $u) {
    $uid = $u['id'];
    $bio = $bioMap[$uid] ?? null;
    $leave = $leaveMap[$uid] ?? null;

    $state = 'not_in';
    $stateLabel = 'Not in Office';
    $stateColor = 'slate';
    $lastAction = 'No punches yet today';

    if (!empty($leave)) {
        $state = 'on_leave';
        $stateLabel = 'On Leave';
        $stateColor = 'blue';
        $lastAction = $leave['leave_type_label'];
        $counts['on_leave']++;
    } elseif (!empty($bio)) {
        if (!empty($bio['break_out']) && empty($bio['break_in']) && empty($bio['time_out'])) {
            $state = 'on_break';
            $stateLabel = 'On Lunch / Break';
            $stateColor = 'amber';
            $lastAction = 'Break Out at ' . formatTimeTo12Hour($bio['break_out']);
            $counts['on_break']++;
        } elseif (!empty($bio['time_out'])) {
            $state = 'completed';
            $stateLabel = 'Timed Out';
            $stateColor = 'emerald';
            $lastAction = 'Departed at ' . formatTimeTo12Hour($bio['time_out']);
            $counts['present']++;
        } elseif (!empty($bio['time_in'])) {
            $state = 'present';
            $stateLabel = 'In Office';
            $stateColor = 'emerald';
            $lastAction = 'Arrived at ' . formatTimeTo12Hour($bio['time_in']);
            $counts['present']++;
        } else {
            $counts['not_in']++;
        }
    } else {
        $counts['not_in']++;
    }

    $roster[] = [
        'id' => $u['id'],
        'name' => $u['name'],
        'title' => $u['title'],
        'avatar_path' => $u['avatar_path'],
        'avatar_initials' => $u['avatar_initials'],
        'state' => $state,
        'state_label' => $stateLabel,
        'state_color' => $stateColor,
        'last_action' => $lastAction
    ];
}

echo json_encode([
    'success' => true,
    'date' => $today,
    'date_formatted' => date('l, F j, Y'),
    'counts' => $counts,
    'roster' => $roster
]);
