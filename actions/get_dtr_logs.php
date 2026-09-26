<?php
// actions/get_dtr_logs.php - Fetch Daily Time Record (DTR) reconciled with approved leaves & rendered hours
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../services/attendance_calculator.php';
requireLogin();

header('Content-Type: application/json');

$startDate = $_GET['start_date'] ?? ($_GET['date'] ?? date('Y-m-d'));
$endDate = $_GET['end_date'] ?? $startDate;
$filterUserId = !empty($_GET['user_id']) ? intval($_GET['user_id']) : null;
if (!hasRole('admin')) {
    $filterUserId = (int)getCurrentUser()['id'];
}

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

// Fetch leave types map for paid/unpaid determination
$leaveTypesMap = $pdo->query("SELECT code, is_paid FROM leave_types")->fetchAll(PDO::FETCH_KEY_PAIR);

// Fetch approved leaves covering any day in the range
$leaveStmt = $pdo->prepare("
    SELECT user_id, leave_type, leave_type_label, ref_no, start_date, end_date 
    FROM leave_requests 
    WHERE status = 'Approved' AND NOT (end_date < ? OR start_date > ?)
");
$leaveStmt->execute([$startDate, $endDate]);
$approvedLeaves = $leaveStmt->fetchAll();
$holidayStmt = $pdo->prepare('SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN ? AND ?');
$holidayStmt->execute([$startDate, $endDate]);
$holidayDates = array_fill_keys($holidayStmt->fetchAll(PDO::FETCH_COLUMN), true);

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
        if ($singleDate >= $lv['start_date'] && $singleDate <= $lv['end_date'] && (int)date('N', strtotime($singleDate)) <= 5 && !isset($holidayDates[$singleDate])) {
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
            $bio['overtime_hours'] ?? 0,
            $singleDate
        );

        $status = 'Expected';
        $statusType = 'neutral';
        $statusText = 'Not Yet Clocked In';

        if ($isOnLeave) {
            $isPaidLeave = !empty($leaveTypesMap[$leave['leave_type']] ?? 1) && ($leave['leave_type'] !== 'LWOP');
            $leaveHours = $isPaidLeave ? 8.0 : 0.0;
            $status = 'On Leave';
            $statusType = 'leave';
            $statusText = "On Approved Leave ({$leave['leave_type_label']})";
            $onLeaveCount++;
            if (!$hasPunched) {
                $totalRenderedHours += $leaveHours;
                $metrics['rendered_hours'] = $leaveHours;
                $metrics['rendered_formatted'] = $isPaidLeave ? '8.00 hrs (Paid Leave)' : '0 hrs (Unpaid)';
            } else {
                $totalRenderedHours += $metrics['rendered_hours'];
            }
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
            'verification_method' => $bio['verification_method'] ?? ($isOnLeave ? 'Leave Reconciled' : '—'),
            'status' => $status,
            'status_type' => $statusType,
            'status_text' => $statusText,
            'is_on_leave' => $isOnLeave,
            'leave_info' => $leave,
            'tardy_minutes' => $metrics['tardy_minutes'] ?? 0,
            'is_tardy' => $metrics['is_tardy'] ?? false,
            'tardy_formatted' => $metrics['tardy_formatted'] ?? 'On Time',
            'is_incomplete' => $metrics['is_incomplete'] ?? false,
            'exception_type' => $metrics['exception_type'] ?? null,
            'exception_label' => $metrics['exception_label'] ?? null
        ];
    }
} else {
    // Multi-day range view: list all records that have punches or approved leaves in the range
    $totalExpected = count($users);
    $userDateLogsMap = [];
    
    // Group logs by user and date
    foreach ($rawLogs as $bio) {
        $uid = $bio['user_id'];
        $logDate = $bio['log_date'];
        $userDateLogsMap[$uid . '_' . $logDate] = true;

        $u = $userMap[$uid] ?? [
            'id' => $uid,
            'name' => 'Unknown Associate',
            'title' => 'Associate',
            'avatar_path' => null,
            'avatar_initials' => 'UA',
            'biometric_pin' => strval($uid)
        ];

        $leave = null;
        foreach ($approvedLeaves as $lv) {
            if ($lv['user_id'] == $uid && $logDate >= $lv['start_date'] && $logDate <= $lv['end_date']) {
                $leave = $lv;
                break;
            }
        }
        $isOnLeave = !empty($leave) && (int)date('N', strtotime($logDate)) <= 5 && !isset($holidayDates[$logDate]);

        $metrics = calculateAttendanceMetrics(
            $bio['time_in'] ?? null,
            $bio['break_out'] ?? null,
            $bio['break_in'] ?? null,
            $bio['time_out'] ?? null,
            $bio['overtime_hours'] ?? 0,
            $logDate
        );

        if ($isOnLeave) {
            $isPaidLeave = !empty($leaveTypesMap[$leave['leave_type']] ?? 1) && ($leave['leave_type'] !== 'LWOP');
            $status = 'On Leave';
            $statusType = 'leave';
            $statusText = "On Approved Leave ({$leave['leave_type_label']})";
            $onLeaveCount++;
            if ($metrics['rendered_hours'] == 0 && $isPaidLeave) {
                $metrics['rendered_hours'] = 8.0;
                $metrics['rendered_formatted'] = '8.00 hrs (Paid Leave)';
            }
        } else {
            $status = $metrics['status'];
            $statusType = ($status === 'On Break') ? 'warning' : 'success';
            $statusText = ($status === 'On Break') ? 'Currently on Break' : 'Present';
            if ($status === 'On Break') $onBreakCount++;
            else $presentCount++;
        }
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
            'verification_method' => $bio['verification_method'] ?? ($isOnLeave ? 'Leave Reconciled' : '—'),
            'status' => $status,
            'status_type' => $statusType,
            'status_text' => $statusText,
            'is_on_leave' => $isOnLeave,
            'leave_info' => $leave,
            'tardy_minutes' => $metrics['tardy_minutes'] ?? 0,
            'is_tardy' => $metrics['is_tardy'] ?? false,
            'tardy_formatted' => $metrics['tardy_formatted'] ?? 'On Time',
            'is_incomplete' => $metrics['is_incomplete'] ?? false,
            'exception_type' => $metrics['exception_type'] ?? null,
            'exception_label' => $metrics['exception_label'] ?? null
        ];
    }

    // Synthesize entries for approved leaves on days with NO biometric punches
    foreach ($approvedLeaves as $lv) {
        $uid = $lv['user_id'];
        if ($filterUserId && $uid != $filterUserId) continue;
        $u = $userMap[$uid] ?? null;
        if (!$u) continue;

        $isPaidLeave = !empty($leaveTypesMap[$lv['leave_type']] ?? 1) && ($lv['leave_type'] !== 'LWOP');
        $curTs = strtotime(max($startDate, $lv['start_date']));
        $endTs = strtotime(min($endDate, $lv['end_date']));

        while ($curTs <= $endTs) {
            $dayStr = date('Y-m-d', $curTs);
            $dow = intval(date('N', $curTs)); // 1-7 (7=Sun)
            if ($dow <= 5 && !isset($holidayDates[$dayStr]) && empty($userDateLogsMap[$uid . '_' . $dayStr])) {
                $userDateLogsMap[$uid . '_' . $dayStr] = true;
                $onLeaveCount++;
                $hours = $isPaidLeave ? 8.0 : 0.0;
                $totalRenderedHours += $hours;

                $dtrRecords[] = [
                    'id' => null,
                    'user_id' => $u['id'],
                    'log_date' => $dayStr,
                    'log_date_formatted' => date('M j, Y (D)', $curTs),
                    'name' => $u['name'],
                    'title' => $u['title'],
                    'avatar_path' => $u['avatar_path'],
                    'avatar_initials' => $u['avatar_initials'],
                    'biometric_pin' => $u['biometric_pin'] ?: strval($u['id']),
                    'time_in' => null,
                    'break_out' => null,
                    'break_in' => null,
                    'time_out' => null,
                    'raw_time_in' => null,
                    'raw_break_out' => null,
                    'raw_break_in' => null,
                    'raw_time_out' => null,
                    'rendered_hours' => $hours,
                    'rendered_formatted' => $isPaidLeave ? '8.00 hrs (Paid Leave)' : '0 hrs (Unpaid)',
                    'break_formatted' => '0 mins',
                    'overtime_hours' => 0.0,
                    'verification_method' => 'Leave Reconciled',
                    'status' => 'On Leave',
                    'status_type' => 'leave',
                    'status_text' => "On Approved Leave ({$lv['leave_type_label']})",
                    'is_on_leave' => true,
                    'leave_info' => $lv,
                    'tardy_minutes' => 0,
                    'is_tardy' => false,
                    'tardy_formatted' => 'On Time',
                    'is_incomplete' => false,
                    'exception_type' => null,
                    'exception_label' => null
                ];
            }
            $curTs = strtotime('+1 day', $curTs);
        }
    }

    // Sort descending by date, then by associate name
    usort($dtrRecords, function($a, $b) {
        $c = strcmp($b['log_date'], $a['log_date']);
        if ($c !== 0) return $c;
        return strcmp($a['name'], $b['name']);
    });
}

// Calculate summary exception and tardiness stats for payroll preparation
$totalTardyMinutes = 0;
$totalExceptionsCount = 0;
foreach ($dtrRecords as $rec) {
    if (!empty($rec['tardy_minutes'])) {
        $totalTardyMinutes += $rec['tardy_minutes'];
    }
    if (!empty($rec['is_incomplete'])) {
        $totalExceptionsCount++;
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
    'total_tardy_minutes' => $totalTardyMinutes,
    'total_tardy_formatted' => ($totalTardyMinutes >= 60) ? floor($totalTardyMinutes / 60) . 'h ' . ($totalTardyMinutes % 60) . 'm' : "{$totalTardyMinutes}m",
    'total_exceptions_count' => $totalExceptionsCount,
    'records' => $dtrRecords
]);
