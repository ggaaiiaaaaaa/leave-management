<?php
// actions/apply_leave.php - Process leave application with clean categories, gender validation & holiday discount
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../services/mailer.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$user = getCurrentUser();
$userId = $user['id'];

$leaveType = $_POST['leave_type'] ?? 'VL';
$durationMode = 'full';
$startDate = $_POST['start_date'] ?? '';
$endDate = $_POST['end_date'] ?? '';
$reason = trim($_POST['reason'] ?? '');

if (empty($startDate) || empty($endDate) || empty($reason)) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields (dates and reason).']);
    exit;
}

// 9 Clean Leave Categories (No RA citations, no custom study/firm training)
$typeLabels = [
    'VL' => 'Vacation Leave',
    'SL' => 'Sick Leave',
    'Emergency' => 'Emergency Leave',
    'Bereavement' => 'Bereavement Leave',
    'LWOP' => 'Leave Without Pay',
    'SoloParent' => 'Solo Parent Leave',
    'Maternity' => 'Maternity Leave',
    'Paternity' => 'Paternity Leave',
    'SpecialWomen' => 'Special Leave for Women'
];

if (!array_key_exists($leaveType, $typeLabels)) {
    echo json_encode(['success' => false, 'message' => 'Invalid leave category selected.']);
    exit;
}
$leaveTypeLabel = $typeLabels[$leaveType];

// Determine target employee (Admin can proxy-file for any associate)
$targetUserId = $userId;
$isAdminFiling = false;

if (hasRole('admin') && !empty($_POST['target_user_id'])) {
    $targetUserId = (int)$_POST['target_user_id'];
    $isAdminFiling = true;
}

// Fetch target user details and balances
$targetStmt = $pdo->prepare("
    SELECT u.*, b.vl_balance, b.sl_balance, b.emergency_balance, b.bereavement_balance,
           b.solo_parent_balance, b.maternity_balance, b.paternity_balance, b.special_women_balance
    FROM users u
    LEFT JOIN leave_balances b ON u.id = b.user_id
    WHERE u.id = ?
");
$targetStmt->execute([$targetUserId]);
$targetUser = $targetStmt->fetch();

if (!$targetUser) {
    echo json_encode(['success' => false, 'message' => 'Associate profile not found.']);
    exit;
}

// 1. SMART GENDER VALIDATION
$gender = strtolower(trim($targetUser['gender'] ?? 'female'));
if ($gender === 'male' && in_array($leaveType, ['Maternity', 'SpecialWomen'])) {
    echo json_encode([
        'success' => false,
        'message' => "Maternity Leave and Special Leave for Women are restricted to female associates."
    ]);
    exit;
}

if ($gender === 'female' && $leaveType === 'Paternity') {
    echo json_encode([
        'success' => false,
        'message' => "Paternity Leave is restricted to male associates."
    ]);
    exit;
}

// 2. DATE VALIDATION & AUTOMATIC HOLIDAY EXCLUSION
$start = new DateTime($startDate);
$end = new DateTime($endDate);

if ($start > $end) {
    echo json_encode(['success' => false, 'message' => 'End date cannot be earlier than start date.']);
    exit;
}

// Fetch all official holidays in the date range
$holidayStmt = $pdo->prepare("SELECT holiday_date, title FROM holidays WHERE holiday_date BETWEEN ? AND ?");
$holidayStmt->execute([$startDate, $endDate]);
$holidaysInRange = $holidayStmt->fetchAll(PDO::FETCH_KEY_PAIR); // ['YYYY-MM-DD' => 'Holiday Title']

$workingDays = 0.0;
$holidayCount = 0;
$weekendCount = 0;

$curr = clone $start;
while ($curr <= $end) {
    $dayOfWeek = (int)$curr->format('N'); // 1 (Mon) to 7 (Sun)
    $currDateStr = $curr->format('Y-m-d');

    if ($dayOfWeek >= 6) {
        // Weekend (Saturday or Sunday)
        $weekendCount++;
    } elseif (isset($holidaysInRange[$currDateStr])) {
        // Philippine Official Public Holiday
        $holidayCount++;
    } else {
        $workingDays += 1.0;
    }
    $curr->modify('+1 day');
}

if ($workingDays <= 0) {
    echo json_encode([
        'success' => false,
        'message' => "The selected date range contains only weekends or official Philippine holidays. No leave days need to be deducted."
    ]);
    exit;
}

// 3. OVERLAPPING / DUPLICATE LEAVE PREVENTION
$overlapStmt = $pdo->prepare("
    SELECT ref_no, start_date, end_date, leave_type_label, status
    FROM leave_requests
    WHERE user_id = ? 
      AND status IN ('Pending', 'Approved')
      AND NOT (end_date < ? OR start_date > ?)
    LIMIT 1
");
$overlapStmt->execute([$targetUserId, $startDate, $endDate]);
$existingLeave = $overlapStmt->fetch();

if ($existingLeave) {
    echo json_encode([
        'success' => false,
        'message' => "Conflict detected: {$targetUser['name']} already has an active application ({$existingLeave['ref_no']} - {$existingLeave['leave_type_label']}) from {$existingLeave['start_date']} to {$existingLeave['end_date']}."
    ]);
    exit;
}

// 4. CHECK BALANCE SUFFICIENCY
$balanceColMap = [
    'VL' => 'vl_balance',
    'SL' => 'sl_balance',
    'Emergency' => 'emergency_balance',
    'Bereavement' => 'bereavement_balance',
    'SoloParent' => 'solo_parent_balance',
    'Maternity' => 'maternity_balance',
    'Paternity' => 'paternity_balance',
    'SpecialWomen' => 'special_women_balance',
    'LWOP' => null // Leave Without Pay has unlimited/zero deduction
];

$balanceCol = $balanceColMap[$leaveType] ?? null;
if ($balanceCol && isset($targetUser[$balanceCol])) {
    $currentBal = (float)$targetUser[$balanceCol];
    if ($currentBal < $workingDays) {
        echo json_encode([
            'success' => false,
            'message' => "Insufficient {$leaveTypeLabel} balance. Available: {$currentBal} day(s), Requested: {$workingDays} working day(s)."
        ]);
        exit;
    }
}

// 5. ATTACHMENT UPLOAD (Medical Certificate / Proof - Only applicable to Sick Leave & Emergency Leave)
$attachmentPath = null;
if (in_array($leaveType, ['SL', 'Emergency'])) {
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../uploads/attachments';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
    
    $fileInfo = pathinfo($_FILES['attachment']['name']);
    $ext = strtolower($fileInfo['extension'] ?? '');
    $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];

        if (in_array($ext, $allowedExts)) {
            $cleanFileName = 'att_' . time() . '_' . substr(md5(uniqid()), 0, 8) . '.' . $ext;
            $targetFile = $uploadDir . '/' . $cleanFileName;
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $targetFile)) {
                $attachmentPath = 'uploads/attachments/' . $cleanFileName;
            }
        }
    }
}

// 6. SUBMISSION & DECISION STATUS
// If Admin files for staff or self, it is automatically 'Approved'; otherwise 'Pending'
$initialStatus = hasRole('admin') ? 'Approved' : 'Pending';
$approverName = hasRole('admin') ? $user['name'] : null;
$decidedAt = hasRole('admin') ? date('Y-m-d H:i:s') : null;
$refNo = 'LR-' . date('Y') . '-' . str_pad(mt_rand(100, 999), 3, '0', STR_PAD_LEFT);

$insertStmt = $pdo->prepare("
    INSERT INTO leave_requests (
        ref_no, user_id, leave_type, leave_type_label, start_date, end_date,
        days_count, duration_mode, reason, attachment_path, status, approver_name, decided_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$insertStmt->execute([
    $refNo,
    $targetUserId,
    $leaveType,
    $leaveTypeLabel,
    $startDate,
    $endDate,
    $workingDays,
    $durationMode,
    $reason,
    $attachmentPath,
    $initialStatus,
    $approverName,
    $decidedAt
]);

// Deduct balance immediately only if approved right away (e.g. Admin filing)
if ($initialStatus === 'Approved' && $balanceCol) {
    $deductStmt = $pdo->prepare("UPDATE leave_balances SET {$balanceCol} = MAX(0, {$balanceCol} - ?) WHERE user_id = ?");
    $deductStmt->execute([$workingDays, $targetUserId]);
}

// 7. FIRM STAFFING NOTICE
// Check if other associates have approved leave in the same period
$otherStaffStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT user_id) as other_count
    FROM leave_requests
    WHERE status = 'Approved'
      AND user_id != ?
      AND NOT (end_date < ? OR start_date > ?)
");
$otherStaffStmt->execute([$targetUserId, $startDate, $endDate]);
$otherStaffCount = (int)$otherStaffStmt->fetchColumn();

// 8. SEND EMAIL NOTIFICATION
sendLeaveNotification($pdo, 'leave_filed', [
    'ref_no' => $refNo,
    'employee_name' => $targetUser['name'],
    'employee_email' => $targetUser['email'],
    'leave_type_label' => $leaveTypeLabel,
    'start_date' => $startDate,
    'end_date' => $endDate,
    'days_count' => $workingDays,
    'reason' => $reason
]);

$holidayNote = $holidayCount > 0 ? " ({$holidayCount} public holiday(s) excluded)" : "";
$staffNote = $otherStaffCount > 0 ? " Note: {$otherStaffCount} other colleague(s) also scheduled on leave during these dates." : "";

$successMsg = hasRole('admin') && $targetUserId !== $userId
    ? "Leave for {$targetUser['name']} ({$workingDays} working days{$holidayNote}) filed and approved!"
    : "Leave application ({$workingDays} working days{$holidayNote}) submitted successfully!{$staffNote}";

echo json_encode([
    'success' => true,
    'message' => $successMsg,
    'ref_no' => $refNo,
    'days' => $workingDays,
    'holidays_discounted' => $holidayCount,
    'other_staff_away' => $otherStaffCount
]);
