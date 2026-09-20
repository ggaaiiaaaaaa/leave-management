<?php
// actions/adjust_balance.php - Admin direct 1-click manual balance adjustment
require_once __DIR__ . '/../auth.php';
requireLogin();

header('Content-Type: application/json');

if (!hasRole('admin')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Only Managing Partner can adjust leave balances.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$targetUserId = (int)($data['user_id'] ?? 0);
$leaveType = trim($data['leave_type'] ?? 'VL');
$amount = (float)($data['amount'] ?? 0); // e.g. +2.0 or -1.5

if ($targetUserId <= 0 || $amount == 0) {
    echo json_encode(['success' => false, 'message' => 'Please select an associate and enter a valid adjustment amount.']);
    exit;
}

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

// Verify leave type exists in leave_types
$tStmt = $pdo->prepare("SELECT name, default_days FROM leave_types WHERE code = ?");
$tStmt->execute([$leaveType]);
$typeRow = $tStmt->fetch();

if (!$typeRow) {
    echo json_encode(['success' => false, 'message' => 'Invalid or unrecognized leave category selected.']);
    exit;
}

// Fetch target user
$uStmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
$uStmt->execute([$targetUserId]);
$targetName = $uStmt->fetchColumn();

if (!$targetName) {
    echo json_encode(['success' => false, 'message' => 'Associate profile not found.']);
    exit;
}

// Ensure row exists in user_leave_allocations
$chkAlloc = $pdo->prepare("SELECT id, remaining_days FROM user_leave_allocations WHERE user_id = ? AND leave_type_code = ?");
$chkAlloc->execute([$targetUserId, $leaveType]);
$allocRow = $chkAlloc->fetch();

if (!$allocRow) {
    $def = (float)$typeRow['default_days'];
    $pdo->prepare("INSERT INTO user_leave_allocations (user_id, leave_type_code, allocated_days, remaining_days) VALUES (?, ?, ?, ?)")
        ->execute([$targetUserId, $leaveType, $def, $def]);
}

// Update balance in user_leave_allocations (cannot drop below 0)
$stmt = $pdo->prepare("
    UPDATE user_leave_allocations
    SET remaining_days = MAX(0, remaining_days + ?), updated_at = CURRENT_TIMESTAMP
    WHERE user_id = ? AND leave_type_code = ?
");
$stmt->execute([$amount, $targetUserId, $leaveType]);

// Fetch updated balance from user_leave_allocations
$newBalStmt = $pdo->prepare("SELECT remaining_days FROM user_leave_allocations WHERE user_id = ? AND leave_type_code = ?");
$newBalStmt->execute([$targetUserId, $leaveType]);
$newBalance = (float)$newBalStmt->fetchColumn();

// Sync legacy leave_balances if mapped column exists
if (isset($legacyColMap[$leaveType])) {
    $col = $legacyColMap[$leaveType];
    $chkStmt = $pdo->prepare("SELECT id FROM leave_balances WHERE user_id = ?");
    $chkStmt->execute([$targetUserId]);
    if (!$chkStmt->fetch()) {
        $pdo->prepare("INSERT INTO leave_balances (user_id) VALUES (?)")->execute([$targetUserId]);
    }
    $pdo->prepare("UPDATE leave_balances SET {$col} = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?")->execute([$newBalance, $targetUserId]);
}

$sign = $amount > 0 ? "+{$amount}" : "{$amount}";
echo json_encode([
    'success' => true,
    'message' => "Successfully adjusted {$typeRow['name']} balance by {$sign} day(s) for {$targetName}. New balance: {$newBalance} day(s).",
    'new_balance' => $newBalance
]);
