<?php
// actions/get_calendar_events.php - Feeds leave & holiday events to FullCalendar
require_once __DIR__ . '/../auth.php';
requireLogin();

header('Content-Type: application/json');

$events = [];

// 1. Fetch Approved & Pending Leave Requests
$stmt = $pdo->query("
    SELECT r.id, r.ref_no, r.leave_type, r.leave_type_label, r.start_date, r.end_date, r.days_count, r.reason, r.status, u.name as employee_name, u.department
    FROM leave_requests r
    JOIN users u ON r.user_id = u.id
    WHERE r.status IN ('Approved', 'Pending')
");
$leaves = $stmt->fetchAll();

$colors = [
    'SIL' => ['bg' => '#4338ca', 'border' => '#3730a3'],          // DOLE SIL - Indigo
    'VL' => ['bg' => '#059669', 'border' => '#047857'],           // Vacation Leave - Emerald
    'SL' => ['bg' => '#e11d48', 'border' => '#be123c'],           // Sick Leave - Rose
    'Bereavement' => ['bg' => '#334155', 'border' => '#1e293b'],  // Bereavement - Slate Gray
    'Emergency' => ['bg' => '#ea580c', 'border' => '#c2410c'],    // Emergency - Orange
    'Study' => ['bg' => '#0284c7', 'border' => '#0369a1'],        // CPA / Study - Sky Blue
    'SoloParent' => ['bg' => '#d97706', 'border' => '#b45309'],   // Solo Parent - Amber
    'Paternity' => ['bg' => '#0891b2', 'border' => '#0e7490'],    // Paternity - Cyan
    'Maternity' => ['bg' => '#db2777', 'border' => '#be185d'],    // Maternity - Pink
    'MagnaCarta' => ['bg' => '#7c3aed', 'border' => '#6d28d9'],   // Magna Carta - Purple
    'VAWC' => ['bg' => '#9333ea', 'border' => '#7e22ce'],         // VAWC - Violet
    'Unpaid' => ['bg' => '#64748b', 'border' => '#475569']        // LWOP - Slate
];

foreach ($leaves as $l) {
    // FullCalendar end date is exclusive for all-day events, so add 1 day
    $end = new DateTime($l['end_date']);
    $end->modify('+1 day');

    $typeKey = $l['leave_type'];
    $color = $colors[$typeKey] ?? ['bg' => '#0284c7', 'border' => '#0369a1'];

    $isPending = ($l['status'] === 'Pending');
    $title = ($isPending ? '[Pending] ' : '') . $l['employee_name'] . ' - ' . $l['leave_type_label'] . ' (' . $l['days_count'] . 'd)';

    $events[] = [
        'id' => 'leave_' . $l['id'],
        'title' => $title,
        'start' => $l['start_date'],
        'end' => $end->format('Y-m-d'),
        'backgroundColor' => $isPending ? '#f59e0b' : $color['bg'],
        'borderColor' => $isPending ? '#d97706' : $color['border'],
        'textColor' => '#ffffff',
        'allDay' => true,
        'extendedProps' => [
            'ref_no' => $l['ref_no'],
            'employee' => $l['employee_name'],
            'department' => $l['department'],
            'leave_type' => $l['leave_type_label'],
            'days' => $l['days_count'],
            'reason' => $l['reason'],
            'status' => $l['status']
        ]
    ];
}

// 2. Fetch Philippine Holidays
$holStmt = $pdo->query("SELECT * FROM holidays");
$holidays = $holStmt->fetchAll();

foreach ($holidays as $h) {
    $isRegular = ($h['holiday_type'] === 'Regular');
    $events[] = [
        'id' => 'holiday_' . $h['id'],
        'title' => '🇵🇭 ' . $h['title'] . ' (' . $h['holiday_type'] . ' Holiday)',
        'start' => $h['holiday_date'],
        'backgroundColor' => $isRegular ? '#dc2626' : '#d97706',
        'borderColor' => $isRegular ? '#b91c1c' : '#b45309',
        'textColor' => '#ffffff',
        'allDay' => true,
        'display' => 'block',
        'extendedProps' => [
            'is_holiday' => true,
            'description' => $h['description'] ?? 'Philippine Public Holiday'
        ]
    ];
}

echo json_encode($events);
exit;
