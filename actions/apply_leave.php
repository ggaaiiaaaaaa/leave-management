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
$durationMode = $_POST['duration_mode'] ?? 'full';
$startDate = $_POST['start_date'] ?? '';
$endDate = $_POST['end_date'] ?? '';
$reason = trim($_POST['reason'] ?? '');

if (empty($startDate) || empty($endDate) || empty($reason)) {
    echo json_encode(['success' => false, 'message' => 'All required fields must be completed.']);
    exit;
}

// Leave type display mapping
$typeLabels = [
    'SIL' => 'Service Incentive Leave (DOLE)',
    'VL' => 'Vacation Leave',
    'SL' => 'Sick Leave',
    'Bereavement' => 'Bereavement Leave (3-5 Days)',
    'Emergency' => 'Emergency / Calamity Leave',
    'Study' => 'CPA Board Exam / CPD Study Leave',
    'SoloParent' => 'Solo Parent Leave (RA 8972)',
    'Paternity' => 'Paternity Leave (RA 8187 - 7 Days)',
    'Maternity' => 'Maternity Leave (RA 11210 - 105 Days)',
    'MagnaCarta' => 'Magna Carta for Women (RA 9710)',
    'VAWC' => 'VAWC Leave (RA 9262)',
    'Unpaid' => 'Leave Without Pay (LWOP)'
];
$leaveTypeLabel = $typeLabels[$leaveType] ?? $leaveType;

// Calculate working days (excluding Saturdays, Sundays, and Holidays)
$start = new DateTime($startDate);
$end = new DateTime($endDate);

if ($start > $end) {
    echo json_encode(['success' => false, 'message' => 'End date cannot be earlier than start date.']);
    exit;
}

// Fetch holidays from DB
$holidays = $pdo->query("SELECT holiday_date FROM holidays")->fetchAll(PDO::FETCH_COLUMN);

$workingDays = 0;
$curr = clone $start;
while ($curr <= $end) {
    $dayOfWeek = (int)$curr->format('N'); // 1 (Mon) - 7 (Sun)
    $ymd = $curr->format('Y-m-d');
    
    if ($dayOfWeek < 6 && !in_array($ymd, $holidays)) {
        $workingDays += 1.0;
    }
    $curr->modify('+1 day');
}

if ($durationMode === 'half-am' || $durationMode === 'half-pm') {
    $workingDays = min($workingDays, 1.0) * 0.5;
}

if ($workingDays <= 0) {
    echo json_encode(['success' => false, 'message' => 'Selected date range contains 0 working days (falls entirely on weekends or holidays).']);
    exit;
}

// Check balance for SIL, VL, SL
$balanceCol = null;
if ($leaveType === 'SIL') $balanceCol = 'sil_balance';
if ($leaveType === 'VL') $balanceCol = 'vl_balance';
if ($leaveType === 'SL') $balanceCol = 'sl_balance';
if ($leaveType === 'SoloParent') $balanceCol = 'solo_parent_balance';

if ($balanceCol && isset($user[$balanceCol])) {
    $currentBal = (float)$user[$balanceCol];
    if ($currentBal < $workingDays) {
        echo json_encode([
            'success' => false, 
            'message' => "Insufficient {$leaveTypeLabel} balance. Available: {$currentBal} day(s), Requested: {$workingDays} day(s)."
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

// Insert into DB
$stmt = $pdo->prepare("
    INSERT INTO leave_requests (ref_no, user_id, leave_type, leave_type_label, start_date, end_date, days_count, duration_mode, reason, attachment_path, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')
");
$stmt->execute([$refNo, $userId, $leaveType, $leaveTypeLabel, $startDate, $endDate, $workingDays, $durationMode, $reason, $attachmentPath]);

// Deduct balance
if ($balanceCol) {
    $deductStmt = $pdo->prepare("UPDATE leave_balances SET {$balanceCol} = {$balanceCol} - ? WHERE user_id = ?");
    $deductStmt->execute([$workingDays, $userId]);
}

// Audit log
$auditStmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
$auditStmt->execute([$userId, 'APPLY_LEAVE', "Filed {$workingDays} day(s) {$leaveTypeLabel} [{$refNo}]", $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);

echo json_encode([
    'success' => true,
    'message' => "Leave application ({$workingDays} working days) submitted successfully!",
    'ref_no' => $refNo,
    'days' => $workingDays
]);
