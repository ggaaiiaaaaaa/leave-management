<?php
// actions/apply_leave.php - Process leave application with clean categories, gender validation & holiday discount
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../services/mailer.php';
requireLogin();
requirePostWithCsrf();

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

// Dynamically fetch active leave category policy
$typeStmt = $pdo->prepare("SELECT * FROM leave_types WHERE code = ? AND is_active = 1");
$typeStmt->execute([$leaveType]);
$typeRow = $typeStmt->fetch();

if (!$typeRow) {
    echo json_encode(['success' => false, 'message' => 'Invalid or inactive leave category selected.']);
    exit;
}
$leaveTypeLabel = $typeRow['name'];

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
if ($typeRow['gender_restriction'] === 'Female' && $gender === 'male') {
    echo json_encode([
        'success' => false,
        'message' => "{$leaveTypeLabel} is restricted to female associates."
    ]);
    exit;
}
if ($typeRow['gender_restriction'] === 'Male' && $gender === 'female') {
    echo json_encode([
        'success' => false,
        'message' => "{$leaveTypeLabel} is restricted to male associates."
    ]);
    exit;
}

// 2. DATE VALIDATION & AUTOMATIC HOLIDAY EXCLUSION
$start = DateTime::createFromFormat('!Y-m-d', $startDate);
$end = DateTime::createFromFormat('!Y-m-d', $endDate);
if (!$start || !$end || $start->format('Y-m-d') !== $startDate || $end->format('Y-m-d') !== $endDate) {
    echo json_encode(['success' => false, 'message' => 'Enter valid start and end dates.']);
    exit;
}

if ($start > $end) {
    echo json_encode(['success' => false, 'message' => 'End date cannot be earlier than start date.']);
    exit;
}
if ($start->diff($end)->days > 366) {
    echo json_encode(['success' => false, 'message' => 'A leave request cannot span more than one year.']);
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
if ($typeRow['is_paid'] == 1 && $leaveType !== 'LWOP') {
    $balStmt = $pdo->prepare("SELECT remaining_days FROM user_leave_allocations WHERE user_id = ? AND leave_type_code = ?");
    $balStmt->execute([$targetUserId, $leaveType]);
    $allocRow = $balStmt->fetch();

    $legacyColMap = [
        'VL' => 'vl_balance',
        'SL' => 'sl_balance',
        'Emergency' => 'emergency_balance',
        'Bereavement' => 'bereavement_balance',
        'SoloParent' => 'solo_parent_balance',
        'Maternity' => 'maternity_balance',
        'Paternity' => 'paternity_balance',
        'SpecialWomen' => 'special_women_balance'
    ];
    $legacyCol = $legacyColMap[$leaveType] ?? null;

    if ($allocRow !== false) {
        $currentBal = (float)$allocRow['remaining_days'];
    } elseif ($legacyCol && isset($targetUser[$legacyCol])) {
        $currentBal = (float)$targetUser[$legacyCol];
    } else {
        $currentBal = 0.0;
    }

    if ($currentBal < $workingDays) {
        echo json_encode([
            'success' => false,
            'message' => "Insufficient {$leaveTypeLabel} balance. Available: {$currentBal} day(s), Requested: {$workingDays} working day(s)."
        ]);
        exit;
    }
}

// 5. ATTACHMENT UPLOAD (Supporting Document / Proof)
$attachmentPath = null;
$hasAttachment = isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK;

if ((int)$typeRow['requires_attachment'] === 1 && !$hasAttachment && !hasRole('admin')) {
    echo json_encode([
        'success' => false,
        'message' => "Supporting document / proof is required for {$leaveTypeLabel}. Please attach a valid file (PDF, JPG, PNG, DOC)."
    ]);
    exit;
}

if ($hasAttachment) {
    $allowedMime = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip']
    ];
    $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['attachment']['tmp_name']);
    if ($_FILES['attachment']['size'] <= 0 || $_FILES['attachment']['size'] > 10 * 1024 * 1024 || !isset($allowedMime[$ext]) || !in_array($mime, $allowedMime[$ext], true)) {
        echo json_encode(['success' => false, 'message' => 'Attachment must be a PDF, image, or Word document up to 10 MB.']);
        exit;
    }
    $uploadDir = LEAVE_PRIVATE_DIR . '/attachments';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $cleanFileName = 'att_' . bin2hex(random_bytes(16)) . '.' . $ext;
    $targetFile = $uploadDir . '/' . $cleanFileName;
    if (move_uploaded_file($_FILES['attachment']['tmp_name'], $targetFile)) {
        $attachmentPath = 'attachments/' . $cleanFileName;
    } else {
        echo json_encode(['success' => false, 'message' => 'Attachment could not be saved.']);
        exit;
    }
}

// 6. SUBMISSION & DECISION STATUS
// If Admin files for staff or self, it is automatically 'Approved'; otherwise 'Pending'
$initialStatus = hasRole('admin') ? 'Approved' : 'Pending';
$approverName = hasRole('admin') ? $user['name'] : null;
$decidedAt = hasRole('admin') ? date('Y-m-d H:i:s') : null;
// Generate sequential Reference Number (e.g., LR-2026-001, LR-2026-002)
$currentYear = date('Y');
$prefix = 'LR-' . $currentYear . '-';

$seqStmt = $pdo->prepare("
    SELECT MAX(CAST(SUBSTR(ref_no, 9) AS INTEGER)) 
    FROM leave_requests 
    WHERE ref_no LIKE ?
");
$seqStmt->execute([$prefix . '%']);
$maxSeq = (int)$seqStmt->fetchColumn();
$nextSeq = ($maxSeq > 0) ? ($maxSeq + 1) : 1;

// Ensure collision-free sequential ref_no
do {
    $refNo = $prefix . str_pad($nextSeq, 3, '0', STR_PAD_LEFT);
    $checkStmt = $pdo->prepare("SELECT 1 FROM leave_requests WHERE ref_no = ?");
    $checkStmt->execute([$refNo]);
    if ($checkStmt->fetch()) {
        $nextSeq++;
    } else {
        break;
    }
} while (true);

$insertStmt = $pdo->prepare("
    INSERT INTO leave_requests (
        ref_no, user_id, leave_type, leave_type_label, start_date, end_date,
        days_count, duration_mode, reason, attachment_path, status, approver_name, decided_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
try {
$pdo->beginTransaction();
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
if ($initialStatus === 'Approved' && (int)$typeRow['is_paid'] === 1) {
    $allocUpdate = $pdo->prepare('UPDATE user_leave_allocations SET remaining_days = remaining_days - ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND leave_type_code = ? AND remaining_days >= ?');
    $allocUpdate->execute([$workingDays, $targetUserId, $leaveType, $workingDays]);
    if ($allocUpdate->rowCount() !== 1) throw new RuntimeException('Insufficient balance at approval time.');
    if ($legacyCol) {
        $pdo->prepare("UPDATE leave_balances SET {$legacyCol} = MAX(0, {$legacyCol} - ?), updated_at = CURRENT_TIMESTAMP WHERE user_id = ?")
            ->execute([$workingDays, $targetUserId]);
    }
}
$pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($attachmentPath) @unlink(LEAVE_PRIVATE_DIR . '/attachments/' . basename($attachmentPath));
    echo json_encode(['success' => false, 'message' => 'Leave could not be filed: ' . $e->getMessage()]);
    exit;
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
