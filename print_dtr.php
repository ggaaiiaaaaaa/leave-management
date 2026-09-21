<?php
// print_dtr.php - Official Form 48 Daily Time Record Printable Sheet
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/services/attendance_calculator.php';
requireLogin();

$currentUser = getCurrentUser();
$isAdmin = ($currentUser['role'] === 'admin');

$userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : $currentUser['id'];

// Non-admins can only view/print their own DTR
if (!$isAdmin && $userId !== $currentUser['id']) {
    $userId = $currentUser['id'];
}

// Year and Month
$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
$month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
$period = $_GET['period'] ?? 'full'; // 'full', '1st_half', '2nd_half'

if ($month < 1 || $month > 12) $month = intval(date('m'));
if ($year < 2020 || $year > 2035) $year = intval(date('Y'));

$numDaysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);

$startDay = 1;
$endDay = $numDaysInMonth;
$periodLabel = date('F Y', strtotime("{$year}-{$month}-01"));

if ($period === '1st_half') {
    $endDay = 15;
    $periodLabel = date('F 1–15, Y', strtotime("{$year}-{$month}-01"));
} elseif ($period === '2nd_half') {
    $startDay = 16;
    $periodLabel = date('F 16–' . $numDaysInMonth . ', Y', strtotime("{$year}-{$month}-01"));
}

$startDateStr = sprintf("%04d-%02d-%02d", $year, $month, $startDay);
$endDateStr = sprintf("%04d-%02d-%02d", $year, $month, $endDay);

// Fetch target user
$uStmt = $pdo->prepare("SELECT id, name, title, biometric_pin FROM users WHERE id = ?");
$uStmt->execute([$userId]);
$targetUser = $uStmt->fetch();
if (!$targetUser) {
    die("Associate not found.");
}

// Fetch all logs for this user in this date range
$bioStmt = $pdo->prepare("
    SELECT * FROM biometric_logs 
    WHERE user_id = ? AND log_date BETWEEN ? AND ?
");
$bioStmt->execute([$userId, $startDateStr, $endDateStr]);
$logsMap = [];
foreach ($bioStmt->fetchAll() as $row) {
    $logsMap[$row['log_date']] = $row;
}

// Fetch approved leaves
$leaveStmt = $pdo->prepare("
    SELECT * FROM leave_requests 
    WHERE user_id = ? AND status = 'Approved' AND NOT (end_date < ? OR start_date > ?)
");
$leaveStmt->execute([$userId, $startDateStr, $endDateStr]);
$leaves = $leaveStmt->fetchAll();

// Fetch official holidays
$holidaysStmt = $pdo->prepare("SELECT * FROM holidays WHERE holiday_date BETWEEN ? AND ?");
$holidaysStmt->execute([$startDateStr, $endDateStr]);
$holidaysMap = [];
foreach ($holidaysStmt->fetchAll() as $h) {
    $holidaysMap[$h['holiday_date']] = $h['title'];
}

$formatTimeShort = function($t) {
    if (empty($t) || $t === '--:--' || $t === '-') return '';
    $ts = strtotime("2000-01-01 " . $t);
    return $ts ? date('g:i', $ts) : $t;
};

// All users list for admin switcher
$allAssociates = [];
if ($isAdmin) {
    $allAssociates = $pdo->query("SELECT id, name, title FROM users ORDER BY name ASC")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Civil Service Form 48 - <?= htmlspecialchars($targetUser['name']) ?> (<?= $periodLabel ?>)</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <script src="lucide.js"></script>
    <style>
        :root {
            --primary: #1e293b;
            --border-color: #000;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #f1f5f9;
            color: #0f172a;
            padding: 24px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .no-print-toolbar {
            width: 100%;
            max-width: 820px;
            background: #ffffff;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .toolbar-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .toolbar-select, .toolbar-btn {
            height: 38px;
            padding: 0 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #1e293b;
            outline: none;
        }

        .toolbar-btn-primary {
            background: #1e293b;
            color: #fff;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .toolbar-btn-primary:hover {
            background: #0f172a;
        }

        /* Printable Sheet Style */
        .dtr-sheet {
            width: 100%;
            max-width: 800px;
            background: #ffffff;
            padding: 36px 40px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.08);
            border-radius: 4px;
            color: #000000;
            font-size: 11px;
            line-height: 1.35;
        }

        .header-block {
            text-align: center;
            margin-bottom: 14px;
        }

        .form-num {
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
            text-transform: uppercase;
        }

        .main-title {
            font-size: 18px;
            font-weight: 800;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 2px;
        }

        .firm-title {
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 14px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            border-bottom: 1px solid #000;
            padding-bottom: 3px;
            margin-bottom: 8px;
        }

        .info-label {
            font-weight: 600;
        }

        .info-value {
            font-weight: 700;
            text-transform: uppercase;
        }

        .prescribed-text {
            font-size: 9.5px;
            text-align: center;
            font-style: italic;
            margin-bottom: 10px;
            color: #334155;
        }

        /* DTR Table */
        .dtr-table {
            width: 100%;
            border-collapse: collapse;
            border: 1.5px solid #000;
            margin-bottom: 14px;
            font-family: 'JetBrains Mono', monospace, sans-serif;
            font-size: 10px;
        }

        .dtr-table th, .dtr-table td {
            border: 1px solid #000;
            padding: 3px 4px;
            text-align: center;
        }

        .dtr-table th {
            background-color: #f8fafc;
            font-weight: 700;
            font-family: 'Inter', sans-serif;
            font-size: 9px;
            text-transform: uppercase;
        }

        .weekend-row {
            background-color: #f8fafc;
            font-style: italic;
        }

        .holiday-row {
            background-color: #fef2f2;
            font-style: italic;
        }

        .leave-row {
            background-color: #f0fdf4;
            font-style: italic;
        }

        .totals-row td {
            font-weight: 700;
            background-color: #f1f5f9;
            font-family: 'Inter', sans-serif;
        }

        /* Certification */
        .cert-block {
            margin-top: 14px;
            font-family: 'Inter', sans-serif;
            font-size: 10px;
            line-height: 1.45;
        }

        .cert-paragraph {
            text-align: justify;
            margin-bottom: 24px;
        }

        .sig-block {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 24px;
            padding: 0 10px;
        }

        .sig-col {
            width: 44%;
            text-align: center;
        }

        .sig-line {
            border-bottom: 1.2px solid #000;
            margin-bottom: 4px;
            height: 32px;
        }

        .sig-name {
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
        }

        .sig-title {
            font-size: 9.5px;
            color: #475569;
        }

        @media print {
            body {
                background: #ffffff;
                padding: 0;
                margin: 0;
            }

            .no-print-toolbar {
                display: none !important;
            }

            .dtr-sheet {
                box-shadow: none;
                max-width: 100%;
                padding: 15px 25px;
                border-radius: 0;
            }

            @page {
                size: A4 portrait;
                margin: 10mm;
            }
        }
    </style>
</head>
<body>

    <!-- Toolbar (Hidden on Print) -->
    <div class="no-print-toolbar">
        <form method="GET" class="toolbar-group">
            <?php if ($isAdmin): ?>
                <select name="user_id" class="toolbar-select" onchange="this.form.submit()">
                    <?php foreach ($allAssociates as $assoc): ?>
                        <option value="<?= $assoc['id'] ?>" <?= ($assoc['id'] == $userId) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($assoc['name']) ?> (<?= htmlspecialchars($assoc['title']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <select name="period" class="toolbar-select" onchange="this.form.submit()">
                <option value="full" <?= ($period === 'full') ? 'selected' : '' ?>>Full Month (1–End)</option>
                <option value="1st_half" <?= ($period === '1st_half') ? 'selected' : '' ?>>1st Half (1–15)</option>
                <option value="2nd_half" <?= ($period === '2nd_half') ? 'selected' : '' ?>>2nd Half (16–End)</option>
            </select>

            <select name="month" class="toolbar-select" onchange="this.form.submit()">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= ($m == $month) ? 'selected' : '' ?>>
                        <?= date('F', mktime(0, 0, 0, $m, 10)) ?>
                    </option>
                <?php endfor; ?>
            </select>

            <select name="year" class="toolbar-select" onchange="this.form.submit()">
                <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                    <option value="<?= $y ?>" <?= ($y == $year) ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </form>

        <div class="toolbar-group">
            <button onclick="window.print()" class="toolbar-btn toolbar-btn-primary">
                <i data-lucide="printer" style="width: 16px; height: 16px;"></i>
                Print Form 48 DTR
            </button>
            <a href="admin_dashboard.php" class="toolbar-btn" style="display: inline-flex; align-items: center; text-decoration: none;">
                Back to Dashboard
            </a>
        </div>
    </div>

    <!-- Official Civil Service Form No. 48 Sheet -->
    <div class="dtr-sheet">
        <div class="header-block">
            <div class="form-num">Civil Service Form No. 48</div>
            <div class="main-title">DAILY TIME RECORD</div>
            <div class="firm-title">J.T. YEO CPA ACCOUNTING OFFICE</div>
        </div>

        <div class="info-row">
            <span class="info-label">NAME:</span>
            <span class="info-value"><?= htmlspecialchars($targetUser['name']) ?></span>
            <span class="info-label">DESIGNATION:</span>
            <span class="info-value"><?= htmlspecialchars($targetUser['title']) ?></span>
        </div>

        <div class="info-row" style="margin-bottom: 6px;">
            <span class="info-label">FOR THE PERIOD:</span>
            <span class="info-value"><?= $periodLabel ?></span>
            <span class="info-label">PIN:</span>
            <span class="info-value"><?= htmlspecialchars($targetUser['biometric_pin'] ?: $targetUser['id']) ?></span>
        </div>

        <div class="prescribed-text">
            Official hours for arrival and departure: Regular Days (Productive Work Rendered)
        </div>

        <table class="dtr-table">
            <thead>
                <tr>
                    <th rowspan="2" style="width: 32px;">Day</th>
                    <th colspan="2">A. M.</th>
                    <th colspan="2">P. M.</th>
                    <th rowspan="2" style="width: 70px;">Rendered<br>Hours</th>
                    <th rowspan="2" style="width: 60px;">Overtime<br>(Hours)</th>
                </tr>
                <tr>
                    <th style="width: 65px;">Arrival</th>
                    <th style="width: 65px;">Departure</th>
                    <th style="width: 65px;">Arrival</th>
                    <th style="width: 65px;">Departure</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $totalRenderedHoursMonth = 0;
                $totalOtHoursMonth = 0;

                for ($d = $startDay; $d <= $endDay; $d++):
                    $currentDateStr = sprintf("%04d-%02d-%02d", $year, $month, $d);
                    $dayOfWeek = date('N', strtotime($currentDateStr)); // 1 (Mon) - 7 (Sun)
                    $isWeekend = ($dayOfWeek >= 6);
                    $log = $logsMap[$currentDateStr] ?? null;

                    // Check holiday
                    $holidayTitle = $holidaysMap[$currentDateStr] ?? null;

                    // Check approved leave
                    $leaveLabel = null;
                    $isPaidLeave = true;
                    foreach ($leaves as $lv) {
                        if ($currentDateStr >= $lv['start_date'] && $currentDateStr <= $lv['end_date']) {
                            $leaveLabel = $lv['leave_type_label'];
                            $isPaidLeave = ($lv['leave_type'] !== 'LWOP');
                            break;
                        }
                    }

                    $tIn = $formatTimeShort($log['time_in'] ?? null);
                    $bOut = $formatTimeShort($log['break_out'] ?? null);
                    $bIn = $formatTimeShort($log['break_in'] ?? null);
                    $tOut = $formatTimeShort($log['time_out'] ?? null);

                    $metrics = calculateAttendanceMetrics(
                        $log['time_in'] ?? null,
                        $log['break_out'] ?? null,
                        $log['break_in'] ?? null,
                        $log['time_out'] ?? null,
                        $log['overtime_hours'] ?? 0,
                        $currentDateStr
                    );

                    $renderedHours = $metrics['rendered_hours'];
                    $otHours = floatval($log['overtime_hours'] ?? 0);

                    $rowClass = '';
                    $rowNote = '';

                    if ($holidayTitle) {
                        $rowClass = 'holiday-row';
                        $rowNote = "HOLIDAY ({$holidayTitle})";
                    } elseif ($leaveLabel) {
                        $rowClass = 'leave-row';
                        $rowNote = "ON LEAVE ({$leaveLabel})";
                        // Automatically credit 8.00 hours for paid authorized leave days on working days
                        if (empty($tIn) && $isPaidLeave && !$isWeekend) {
                            $renderedHours = 8.00;
                        }
                    } elseif ($isWeekend && empty($tIn)) {
                        $rowClass = 'weekend-row';
                        $rowNote = ($dayOfWeek == 6) ? 'SATURDAY' : 'SUNDAY';
                    }

                    $totalRenderedHoursMonth += $renderedHours;
                    $totalOtHoursMonth += $otHours;
                ?>
                    <tr class="<?= $rowClass ?>">
                        <td><strong><?= $d ?></strong></td>
                        <?php if (!empty($rowNote) && empty($tIn)): ?>
                            <td colspan="4" style="text-align: center; font-size: 9.5px; font-weight: 600; letter-spacing: 0.5px; color: <?= $leaveLabel ? '#1e40af' : '#475569' ?>;"><?= $rowNote ?></td>
                            <td style="font-weight:<?= ($renderedHours > 0) ? '700' : 'normal' ?>;"><?= ($renderedHours > 0) ? number_format($renderedHours, 2) : '—' ?></td>
                            <td><?= ($otHours > 0) ? number_format($otHours, 2) : '—' ?></td>
                        <?php else: ?>
                            <td><?= $tIn ?: '' ?></td>
                            <td><?= $bOut ?: '' ?></td>
                            <td><?= $bIn ?: '' ?></td>
                            <td><?= $tOut ?: '' ?></td>
                            <td><?= ($renderedHours > 0) ? number_format($renderedHours, 2) : '' ?></td>
                            <td><?= ($otHours > 0) ? number_format($otHours, 2) : '' ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endfor; ?>

                <!-- Totals Row -->
                <tr class="totals-row">
                    <td colspan="5" style="text-align: right; padding-right: 12px;">TOTAL ACCUMULATED HOURS:</td>
                    <td><?= number_format($totalRenderedHoursMonth, 2) ?> hrs</td>
                    <td><?= ($totalOtHoursMonth > 0) ? number_format($totalOtHoursMonth, 2) . ' hrs' : '—' ?></td>
                </tr>
            </tbody>
        </table>

        <div class="cert-block">
            <p class="cert-paragraph">
                I certify on my honor that the above is a true and correct report of the hours of work performed, record of which was made daily at the time of arrival and at the time of departure from office.
            </p>

            <div class="sig-block">
                <div class="sig-col">
                    <div class="sig-line"></div>
                    <div class="sig-name"><?= htmlspecialchars($targetUser['name']) ?></div>
                    <div class="sig-title">Associate Signature</div>
                </div>

                <div class="sig-col">
                    <div class="sig-line"></div>
                    <div class="sig-name">Atty. Jonathan T. Yeo, CPA</div>
                    <div class="sig-title">Managing Partner</div>
                </div>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>
