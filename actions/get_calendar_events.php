<?php
// actions/get_calendar_events.php - Feeds leave & Philippine holiday events to FullCalendar
require_once __DIR__ . '/../auth.php';
requireLogin();

header('Content-Type: application/json');

$filterUserId = (int)($_GET['user_id'] ?? 0);
$filterType = $_GET['leave_type'] ?? '';
$showHolidays = isset($_GET['show_holidays']) ? (int)$_GET['show_holidays'] : 1;
$showBirthdays = isset($_GET['show_birthdays']) ? (int)$_GET['show_birthdays'] : 1;
$viewer = getCurrentUser();
$isAdmin = $viewer['role'] === 'admin';
if (!$isAdmin) $filterUserId = 0;

$events = [];

// 1. Fetch Approved & Pending Leave Requests
$sql = "
    SELECT r.id, r.user_id, r.ref_no, r.leave_type, r.leave_type_label, r.start_date, r.end_date,
           r.days_count, r.reason, r.status, r.approver_name, u.name as employee_name, u.title, u.avatar_path, u.avatar_initials
    FROM leave_requests r
    JOIN users u ON r.user_id = u.id
    WHERE r.status IN ('Approved', 'Pending')
";
$params = [];
if (!$isAdmin) {
    $sql .= " AND (r.user_id = ? OR r.status = 'Approved')";
    $params[] = $viewer['id'];
}

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
            'reason' => ($isAdmin || (int)$l['user_id'] === (int)$viewer['id']) ? $l['reason'] : '',
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

// 3. Fetch Firm & Associate Birthdays (Annually Recurring)
if ($showBirthdays) {
    $bdaySql = "
        SELECT b.id, b.user_id, b.person_name, b.birth_date, b.notes, b.created_by,
               u.name as linked_name, u.avatar_path, u.avatar_initials, u.title as linked_title,
               creator.name as creator_name
        FROM birthdays b
        LEFT JOIN users u ON b.user_id = u.id
        LEFT JOIN users creator ON b.created_by = creator.id
    ";
    $bdayParams = [];
    if ($filterUserId > 0) {
        $bdaySql .= " WHERE b.user_id = ?";
        $bdayParams[] = $filterUserId;
    }
    $bdayStmt = $pdo->prepare($bdaySql);
    $bdayStmt->execute($bdayParams);
    $birthdays = $bdayStmt->fetchAll();

    $startYear = isset($_GET['start']) ? (int)substr($_GET['start'], 0, 4) : ((int)date('Y') - 1);
    $endYear = isset($_GET['end']) ? (int)substr($_GET['end'], 0, 4) : ((int)date('Y') + 1);
    if ($startYear < 2000) $startYear = (int)date('Y') - 1;
    if ($endYear < $startYear) $endYear = $startYear + 1;
    if ($endYear - $startYear > 5) $endYear = $startYear + 5;

    foreach ($birthdays as $b) {
        $bDateStr = $b['birth_date'];
        $parts = explode('-', $bDateStr);
        if (count($parts) < 3) continue;
        $origYear = (int)$parts[0];
        $month = (int)$parts[1];
        $day = (int)$parts[2];

        for ($y = $startYear; $y <= $endYear; $y++) {
            $targetDay = $day;
            if ($month === 2 && $day === 29 && !checkdate(2, 29, $y)) {
                $targetDay = 28;
            }
            $targetDate = sprintf('%04d-%02d-%02d', $y, $month, $targetDay);

            $turningAge = null;
            if ($origYear > 1900 && $origYear <= $y) {
                $turningAge = $y - $origYear;
            }

            $ageSuffix = "";
            if ($turningAge !== null && $turningAge > 0) {
                $rem100 = $turningAge % 100;
                $rem10 = $turningAge % 10;
                $ord = 'th';
                if (!in_array($rem100, [11, 12, 13], true)) {
                    if ($rem10 === 1) $ord = 'st';
                    elseif ($rem10 === 2) $ord = 'nd';
                    elseif ($rem10 === 3) $ord = 'rd';
                }
                $ageSuffix = " ({$turningAge}{$ord})";
            }
            $title = $b['person_name'] . "'s Birthday" . $ageSuffix;

            $canDelete = $isAdmin;

            $events[] = [
                'id' => 'bday_' . $b['id'] . '_' . $y,
                'title' => $title,
                'start' => $targetDate,
                'backgroundColor' => '#ec4899', // festive vibrant pink
                'borderColor' => '#db2777',
                'textColor' => '#ffffff',
                'allDay' => true,
                'display' => 'block',
                'className' => 'fc-birthday-event',
                'extendedProps' => [
                    'is_birthday' => true,
                    'is_holiday' => false,
                    'birthday_id' => (int)$b['id'],
                    'person_name' => $b['person_name'],
                    'birth_date' => $b['birth_date'],
                    'target_date' => $targetDate,
                    'month_day' => date('F j', strtotime($targetDate)),
                    'turning_age' => $turningAge,
                    'notes' => $b['notes'] ?? '',
                    'user_id' => $b['user_id'],
                    'created_by' => $b['created_by'],
                    'creator_name' => $b['creator_name'] ?? 'Managing Partner',
                    'can_delete' => $canDelete
                ]
            ];
        }
    }
}

echo json_encode($events);
