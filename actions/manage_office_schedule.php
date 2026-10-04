<?php
// actions/manage_office_schedule.php - Manage Firm Working Hours & Shift Policies
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/attendance_calculator.php';

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in.']);
    exit;
}

$currentUserId = $_SESSION['user_id'];
$currentUserRole = $_SESSION['role'] ?? 'staff';

// Verify Admin or Managing Partner permission
if ($currentUserRole !== 'admin' && $currentUserRole !== 'partner') {
    echo json_encode(['success' => false, 'message' => 'Access denied. Administrator privileges required.']);
    exit;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? 'get');

if ($action === 'get') {
    $schedule = getOfficeScheduleSettings($pdo);
    echo json_encode([
        'success' => true,
        'schedule' => $schedule
    ]);
    exit;
}

if ($action === 'save') {
    $workStart = trim($_POST['work_start_time'] ?? '');
    $workEnd = trim($_POST['work_end_time'] ?? '');
    $breakStart = trim($_POST['break_start_time'] ?? '');
    $breakEnd = trim($_POST['break_end_time'] ?? '');
    $graceMins = max(0, intval($_POST['grace_period_mins'] ?? 0));
    $reqHours = max(1.0, floatval($_POST['required_daily_hours'] ?? 8.0));
    $workDays = trim($_POST['work_days'] ?? 'Mon,Tue,Wed,Thu,Fri');
    $recalculate = !empty($_POST['recalculate_logs']) && ($_POST['recalculate_logs'] == '1' || $_POST['recalculate_logs'] === 'true');

    if (empty($workStart) || empty($workEnd)) {
        echo json_encode(['success' => false, 'message' => 'Official Start Time and End Time are required.']);
        exit;
    }

    // Standardize to HH:MM:00
    if (strlen($workStart) === 5) $workStart .= ':00';
    if (strlen($workEnd) === 5) $workEnd .= ':00';
    if (!empty($breakStart) && strlen($breakStart) === 5) $breakStart .= ':00';
    if (!empty($breakEnd) && strlen($breakEnd) === 5) $breakEnd .= ':00';

    $startTs = strtotime("2000-01-01 " . $workStart);
    $endTs = strtotime("2000-01-01 " . $workEnd);
    if (!$startTs || !$endTs || $startTs >= $endTs) {
        echo json_encode(['success' => false, 'message' => 'Office End Time must be later than Start Time.']);
        exit;
    }

    $updates = [
        'work_start_time' => $workStart,
        'work_end_time' => $workEnd,
        'grace_period_mins' => strval($graceMins),
        'break_start_time' => $breakStart ?: '12:00:00',
        'break_end_time' => $breakEnd ?: '13:00:00',
        'required_daily_hours' => strval($reqHours),
        'work_days' => $workDays
    ];

    $upsertStmt = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value, updated_at)
        VALUES (?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(setting_key) DO UPDATE SET
            setting_value = excluded.setting_value,
            updated_at = CURRENT_TIMESTAMP
    ");

    foreach ($updates as $k => $v) {
        $upsertStmt->execute([$k, $v]);
    }

    // Refresh schedule settings
    $newSchedule = getOfficeScheduleSettings($pdo);

    $recalculatedCount = 0;
    if ($recalculate) {
        $logsStmt = $pdo->query("SELECT id, time_in, break_out, break_in, time_out, overtime_hours, log_date FROM biometric_logs WHERE time_in IS NOT NULL");
        $logs = $logsStmt->fetchAll();
        $upLog = $pdo->prepare("UPDATE biometric_logs SET rendered_hours = ?, status = ? WHERE id = ?");

        foreach ($logs as $log) {
            $m = calculateAttendanceMetrics(
                $log['time_in'],
                $log['break_out'],
                $log['break_in'],
                $log['time_out'],
                $log['overtime_hours'] ?? 0,
                $log['log_date'],
                $newSchedule
            );
            $upLog->execute([$m['rendered_hours'], $m['status'], $log['id']]);
            $recalculatedCount++;
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Office hours and shift policy updated successfully!' . ($recalculate ? " Recalculated {$recalculatedCount} attendance record(s)." : ''),
        'schedule' => $newSchedule,
        'recalculated_count' => $recalculatedCount
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);
