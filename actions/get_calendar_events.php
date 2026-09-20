<?php
// actions/get_calendar_events.php - Feeds leave & Philippine holiday events to FullCalendar
require_once __DIR__ . '/../auth.php';
requireLogin();

header('Content-Type: application/json');

$filterUserId = (int)($_GET['user_id'] ?? 0);
$filterType = $_GET['leave_type'] ?? '';
$showHolidays = isset($_GET['show_holidays']) ? (int)$_GET['show_holidays'] : 1;

$events = [];

// 1. Fetch Approved & Pending Leave Requests
$sql = "
    SELECT r.id, r.ref_no, r.leave_type, r.leave_type_label, r.start_date, r.end_date,
           r.days_count, r.reason, r.status, r.approver_name, u.name as employee_name, u.title, u.avatar_path, u.avatar_initials
    FROM leave_requests r
    JOIN users u ON r.user_id = u.id
    WHERE r.status IN ('Approved', 'Pending')
";
$params = [];

if ($filterUserId > 0) {
    $sql .= " AND r.user_id = ?";
    $params[] = $filterUserId;
}

if (!empty($filterType) && $filterType !== 'ALL') {
    $sql .= " AND r.leave_type = ?";
    $params[] = $filterType;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$leaves = $stmt->fetchAll();

// Fetch dynamic colors from leave_types table
$dbColors = $pdo->query("SELECT code, color FROM leave_types")->fetchAll(PDO::FETCH_KEY_PAIR);

foreach ($leaves as $l) {
    // FullCalendar end date is exclusive for all-day events, so add 1 day
    $end = new DateTime($l['end_date']);
    $end->modify('+1 day');

    $typeKey = $l['leave_type'];
    $eventColor = $dbColors[$typeKey] ?? '#dc0000';
    $colorInfo = ['bg' => $eventColor, 'border' => $eventColor];

    $isPending = ($l['status'] === 'Pending');
    $title = ($isPending ? '[Pending] ' : '') . $l['employee_name'] . ' - ' . $l['leave_type_label'] . ' (' . $l['days_count'] . 'd)';

    $events[] = [
        'id' => 'leave_' . $l['id'],
        'title' => $title,
        'start' => $l['start_date'],
        'end' => $end->format('Y-m-d'),
        'backgroundColor' => $isPending ? '#f59e0b' : $colorInfo['bg'],
        'borderColor' => $isPending ? '#d97706' : $colorInfo['border'],
        'textColor' => '#ffffff',
        'allDay' => true,
        'extendedProps' => [
            'is_holiday' => false,
            'ref_no' => $l['ref_no'],
            'employee' => $l['employee_name'],
            'title' => $l['title'],
            'leave_type' => $l['leave_type_label'],
            'days' => $l['days_count'],
            'start_date' => $l['start_date'],
            'end_date' => $l['end_date'],
            'reason' => $l['reason'],
            'status' => $l['status'],
            'approver' => $l['approver_name'] ?? 'Pending Signoff'
        ]
    ];
}

// 2. Fetch Philippine Holidays
if ($showHolidays) {
    $holStmt = $pdo->query("SELECT * FROM holidays ORDER BY holiday_date ASC");
    $holidays = $holStmt->fetchAll();

    foreach ($holidays as $h) {
        $isRegular = ($h['holiday_type'] === 'Regular');
        $events[] = [
            'id' => 'holiday_' . $h['id'],
            'title' => $h['title'] . ' (' . $h['holiday_type'] . ')',
            'start' => $h['holiday_date'],
            'backgroundColor' => $isRegular ? '#dc2626' : '#7c2d12',
            'borderColor' => $isRegular ? '#b91c1c' : '#9a3412',
            'textColor' => '#ffffff',
            'allDay' => true,
            'display' => 'block',
            'extendedProps' => [
                'is_holiday' => true,
                'holiday_type' => $h['holiday_type'],
                'description' => $h['description'] ?? 'Philippine Public Holiday'
            ]
        ];
    }
}

echo json_encode($events);
