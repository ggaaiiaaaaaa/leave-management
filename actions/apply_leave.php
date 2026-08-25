<?php
// actions/apply_leave.php - Process leave application in PHP/SQLite
require_once __DIR__ . '/../auth.php';
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
    echo json_encode(['success' => false, 'message' => 'All required fields must be completed.']);
    exit;
}

// Leave type display mapping
$typeLabels = [
    'VL' => 'Vacation Leave (VL)',
    'SL' => 'Sick Leave (SL)',
    'Emergency' => 'Emergency Leave',
    'Bereavement' => 'Bereavement Leave',
    'Unpaid' => 'Leave Without Pay (LWOP)',
    'SIL' => 'Service Incentive Leave (SIL)'
];
$leaveTypeLabel = $typeLabels[$leaveType] ?? $leaveType;

// Calculate requested leave days
$start = new DateTime($startDate);
$end = new DateTime($endDate);

if ($start > $end) {
    echo json_encode(['success' => false, 'message' => 'End date cannot be earlier than start date.']);
    exit;
}

$workingDays = (float)($start->diff($end)->days + 1);

// Determine target employee (Admin can file for any employee)
$targetUserId = $userId;
$isAdminFiling = false;

if (hasRole('admin') && !empty($_POST['target_user_id'])) {
    $targetUserId = (int)$_POST['target_user_id'];
    $isAdminFiling = true;
}

// Fetch target user and their balance
$targetStmt = $pdo->prepare("
    SELECT u.*, b.sil_balance, b.vl_balance, b.sl_balance, b.solo_parent_balance
    FROM users u
    LEFT JOIN leave_balances b ON u.id = b.user_id
    WHERE u.id = ?
");
$targetStmt->execute([$targetUserId]);
$targetUser = $targetStmt->fetch();

if (!$targetUser) {
    echo json_encode(['success' => false, 'message' => 'Target employee not found.']);
    exit;
}

// Check balance for SIL, VL, SL, Solo Parent
$balanceCol = null;
if ($leaveType === 'SIL') $balanceCol = 'sil_balance';
if ($leaveType === 'VL') $balanceCol = 'vl_balance';
if ($leaveType === 'SL') $balanceCol = 'sl_balance';
if ($leaveType === 'SoloParent') $balanceCol = 'solo_parent_balance';

if ($balanceCol && isset($targetUser[$balanceCol])) {
    $currentBal = (float)$targetUser[$balanceCol];
    if ($currentBal < $workingDays) {
        $empName = htmlspecialchars($targetUser['name']);
        echo json_encode([
            'success' => false, 
            'message' => "Insufficient {$leaveTypeLabel} balance for {$empName}. Available: {$currentBal} day(s), Requested: {$workingDays} day(s)."
        ]);
        exit;
    }
}

// Generate Reference No
$refNo = 'LR-2026-' . str_pad(mt_rand(100, 999), 3, '0', STR_PAD_LEFT);

// Handle optional file attachment
$attachmentPath = null;
if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = __DIR__ . '/../uploads';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['attachment']['name']);
    $targetPath = $uploadDir . '/' . $filename;
    if (move_uploaded_file($_FILES['attachment']['tmp_name'], $targetPath)) {
        $attachmentPath = 'uploads/' . $filename;
    }
}

// Status: If admin files it, it is immediately Approved by the Managing Partner
$initialStatus = hasRole('admin') ? 'Approved' : 'Pending';
$approverName = hasRole('admin') ? $user['name'] : null;
$decidedAt = hasRole('admin') ? date('Y-m-d H:i:s') : null;

// Insert into DB
$stmt = $pdo->prepare("
    INSERT INTO leave_requests (ref_no, user_id, leave_type, leave_type_label, start_date, end_date, days_count, duration_mode, reason, attachment_path, status, approver_name, decided_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([$refNo, $targetUserId, $leaveType, $leaveTypeLabel, $startDate, $endDate, $workingDays, $durationMode, $reason, $attachmentPath, $initialStatus, $approverName, $decidedAt]);

// Deduct balance from the target employee
if ($balanceCol) {
    $deductStmt = $pdo->prepare("UPDATE leave_balances SET {$balanceCol} = {$balanceCol} - ? WHERE user_id = ?");
    $deductStmt->execute([$workingDays, $targetUserId]);
}

// Audit log
$logDesc = hasRole('admin') && $targetUserId !== $userId
    ? "Partner filed & approved {$workingDays}d {$leaveTypeLabel} for {$targetUser['name']} [{$refNo}]"
    : "Filed {$workingDays}d {$leaveTypeLabel} [{$refNo}]";

$auditStmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
$auditStmt->execute([$userId, 'APPLY_LEAVE', $logDesc, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);

$msg = hasRole('admin') && $targetUserId !== $userId
    ? "Leave for {$targetUser['name']} ({$workingDays} working days) filed and approved successfully!"
    : "Leave application ({$workingDays} working days) submitted successfully!";

echo json_encode([
    'success' => true,
    'message' => $msg,
    'ref_no' => $refNo,
    'days' => $workingDays
]);

