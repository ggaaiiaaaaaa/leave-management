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

$balanceColMap = [
    'VL' => 'vl_balance',
    'SL' => 'sl_balance',
    'Emergency' => 'emergency_balance',
    'Bereavement' => 'bereavement_balance',
    'SoloParent' => 'solo_parent_balance',
    'Maternity' => 'maternity_balance',
    'Paternity' => 'paternity_balance',
    'SpecialWomen' => 'special_women_balance'
];

if (!isset($balanceColMap[$leaveType])) {
    echo json_encode(['success' => false, 'message' => 'Invalid leave category selected.']);
    exit;
}

$col = $balanceColMap[$leaveType];

// Fetch target user
$uStmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
$uStmt->execute([$targetUserId]);
$targetName = $uStmt->fetchColumn();

if (!$targetName) {
    echo json_encode(['success' => false, 'message' => 'Associate profile not found.']);
    exit;
}

// Ensure row exists in leave_balances
$chkStmt = $pdo->prepare("SELECT id FROM leave_balances WHERE user_id = ?");
$chkStmt->execute([$targetUserId]);
if (!$chkStmt->fetch()) {
    $pdo->prepare("INSERT INTO leave_balances (user_id) VALUES (?)")->execute([$targetUserId]);
}

// Update balance (cannot drop below 0)
$stmt = $pdo->prepare("UPDATE leave_balances SET {$col} = MAX(0, {$col} + ?), updated_at = CURRENT_TIMESTAMP WHERE user_id = ?");
$stmt->execute([$amount, $targetUserId]);

// Fetch updated balance
$newBalStmt = $pdo->prepare("SELECT {$col} FROM leave_balances WHERE user_id = ?");
$newBalStmt->execute([$targetUserId]);
$newBalance = (float)$newBalStmt->fetchColumn();

$sign = $amount > 0 ? "+{$amount}" : "{$amount}";

echo json_encode([
    'success' => true,
    'message' => "Adjusted {$leaveType} for {$targetName} by {$sign} day(s). New balance: {$newBalance} days.",
    'new_balance' => $newBalance
]);
