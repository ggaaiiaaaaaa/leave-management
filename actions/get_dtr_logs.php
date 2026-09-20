<?php
// actions/get_dtr_logs.php - Fetch Daily Time Record (DTR) reconciled with approved leaves & rendered hours
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../services/attendance_calculator.php';
requireLogin();

header('Content-Type: application/json');

$startDate = $_GET['start_date'] ?? ($_GET['date'] ?? date('Y-m-d'));
$endDate = $_GET['end_date'] ?? $startDate;
$filterUserId = !empty($_GET['user_id']) ? intval($_GET['user_id']) : null;

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    $startDate = date('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    $endDate = $startDate;
}
if ($endDate < $startDate) {
    $endDate = $startDate;
}

$isSingleDay = ($startDate === $endDate);

// Fetch users
if ($filterUserId) {
    $usersStmt = $pdo->prepare("SELECT id, name, title, gender, biometric_pin, avatar_path, avatar_initials FROM users WHERE id = ?");
    $usersStmt->execute([$filterUserId]);
} else {
    $usersStmt = $pdo->query("SELECT id, name, title, gender, biometric_pin, avatar_path, avatar_initials FROM users ORDER BY id ASC");
}
$users = $usersStmt->fetchAll();
$userMap = [];
foreach ($users as $u) {
    $userMap[$u['id']] = $u;
}

// Fetch biometric logs for the range
if ($filterUserId) {
    $bioStmt = $pdo->prepare("SELECT * FROM biometric_logs WHERE user_id = ? AND log_date BETWEEN ? AND ? ORDER BY log_date DESC, id DESC");
    $bioStmt->execute([$filterUserId, $startDate, $endDate]);
} else {
    $bioStmt = $pdo->prepare("SELECT * FROM biometric_logs WHERE log_date BETWEEN ? AND ? ORDER BY log_date DESC, id DESC");
    $bioStmt->execute([$startDate, $endDate]);
}
$rawLogs = $bioStmt->fetchAll();

// Fetch approved leaves covering any day in the range
$leaveStmt = $pdo->prepare("
    SELECT user_id, leave_type_label, ref_no, start_date, end_date 
    FROM leave_requests 
    WHERE status = 'Approved' AND NOT (end_date < ? OR start_date > ?)
");
$leaveStmt->execute([$startDate, $endDate]);
$approvedLeaves = $leaveStmt->fetchAll();

$format12h = function($t) {
    if (empty($t) || $t === '--:--' || $t === '-') return null;
    $ts = strtotime("2000-01-01 " . $t);
    return $ts ? date('g:i:s A', $ts) : $t;
};

$dtrRecords = [];
$presentCount = 0;
$onBreakCount = 0;
$onLeaveCount = 0;
$expectedCount = 0;
$totalRenderedHours = 0;

if ($isSingleDay) {
    // Single-day view: show all matching users (including those who haven't punched yet)
    $singleDate = $startDate;

    // Index logs by user_id
    $dayLogs = [];
    foreach ($rawLogs as $l) {
        if ($l['log_date'] === $singleDate) {
            $dayLogs[$l['user_id']] = $l;
        }
    }

    // Index leaves by user_id for today
    $dayLeaves = [];
    foreach ($approvedLeaves as $lv) {
        if ($singleDate >= $lv['start_date'] && $singleDate <= $lv['end_date']) {
            $dayLeaves[$lv['user_id']] = $lv;
        }
    }

    foreach ($users as $u) {
        $uid = $u['id'];
        $bio = $dayLogs[$uid] ?? null;
        $leave = $dayLeaves[$uid] ?? null;

        $isOnLeave = !empty($leave);
        $hasPunched = !empty($bio) && (!empty($bio['time_in']) || !empty($bio['time_out']) || !empty($bio['break_out']));

        // Calculate dynamic metrics
        $metrics = calculateAttendanceMetrics(
            $bio['time_in'] ?? null,
            $bio['break_out'] ?? null,
            $bio['break_in'] ?? null,
            $bio['time_out'] ?? null,
            $bio['overtime_hours'] ?? 0
        );

        $status = 'Expected';
        $statusType = 'neutral';
        $statusText = 'Not Yet Clocked In';

        if ($isOnLeave) {
            $status = 'On Leave';
            $statusType = 'leave';
            $statusText = "On Approved Leave ({$leave['leave_type_label']})";
            $onLeaveCount++;
        } elseif ($hasPunched) {
            $status = $metrics['status']; // 'Present' or 'On Break'
            if ($status === 'On Break') {
                $statusType = 'warning';
                $statusText = 'Currently on Break';
                $onBreakCount++;
                $presentCount++;
            } else {
                $statusType = 'success';
                $statusText = 'Present';
                $presentCount++;
            }
            $totalRenderedHours += $metrics['rendered_hours'];
        } else {
            $expectedCount++;
        }

        $dtrRecords[] = [
            'id' => $bio['id'] ?? null,
            'user_id' => $u['id'],
            'log_date' => $singleDate,
            'log_date_formatted' => date('M j, Y (D)', strtotime($singleDate)),
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
            'rendered_hours' => $metrics['rendered_hours'],
            'rendered_formatted' => $metrics['rendered_formatted'],
            'break_formatted' => $metrics['break_formatted'],
            'overtime_hours' => floatval($bio['overtime_hours'] ?? 0),
            'verification_method' => $bio['verification_method'] ?? ($isOnLeave ? 'System Record' : '—'),
            'status' => $status,
            'status_type' => $statusType,
            'status_text' => $statusText,
            'is_on_leave' => $isOnLeave,
            'leave_info' => $leave
        ];
    }
} else {
    // Multi-day range view: list all records that have punches or leaves in the range
    $totalExpected = count($users);
    
    // Group logs by user and date
    foreach ($rawLogs as $bio) {
        $uid = $bio['user_id'];
        $u = $userMap[$uid] ?? [
            'id' => $uid,
            'name' => 'Unknown Associate',
            'title' => 'Associate',
            'avatar_path' => null,
            'avatar_initials' => 'UA',
            'biometric_pin' => strval($uid)
        ];

        $logDate = $bio['log_date'];
        $leave = null;
        foreach ($approvedLeaves as $lv) {
            if ($lv['user_id'] == $uid && $logDate >= $lv['start_date'] && $logDate <= $lv['end_date']) {
                $leave = $lv;
                break;
            }
        }
        $isOnLeave = !empty($leave);

        $metrics = calculateAttendanceMetrics(
            $bio['time_in'] ?? null,
            $bio['break_out'] ?? null,
            $bio['break_in'] ?? null,
            $bio['time_out'] ?? null,
            $bio['overtime_hours'] ?? 0
        );

        $status = $metrics['status'];
        $statusType = ($status === 'On Break') ? 'warning' : 'success';
        $statusText = ($status === 'On Break') ? 'Currently on Break' : 'Present';
        $totalRenderedHours += $metrics['rendered_hours'];

        $dtrRecords[] = [
            'id' => $bio['id'],
            'user_id' => $u['id'],
            'log_date' => $logDate,
            'log_date_formatted' => date('M j, Y (D)', strtotime($logDate)),
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
            'rendered_hours' => $metrics['rendered_hours'],
            'rendered_formatted' => $metrics['rendered_formatted'],
            'break_formatted' => $metrics['break_formatted'],
            'overtime_hours' => floatval($bio['overtime_hours'] ?? 0),
            'verification_method' => $bio['verification_method'] ?? '—',
            'status' => $status,
            'status_type' => $statusType,
            'status_text' => $statusText,
            'is_on_leave' => $isOnLeave,
            'leave_info' => $leave
        ];
    }
}

echo json_encode([
    'success' => true,
    'start_date' => $startDate,
    'end_date' => $endDate,
    'is_single_day' => $isSingleDay,
    'total_staff' => count($users),
    'present_count' => $presentCount,
    'on_break_count' => $onBreakCount,
    'on_leave_count' => $onLeaveCount,
    'expected_count' => $expectedCount,
    'total_rendered_hours' => round($totalRenderedHours, 2),
    'total_rendered_formatted' => formatHoursToReadable($totalRenderedHours),
    'records' => $dtrRecords
]);
