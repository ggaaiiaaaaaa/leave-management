<?php
// actions/adjust_balance.php - HR & Partner balance adjustments and accrual actions
require_once __DIR__ . '/../auth.php';
requireLogin();

header('Content-Type: application/json');

if (!hasRole('admin')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Only Managing Partners and HR Admins can adjust balances.']);
    exit;
}

$user = getCurrentUser();
$data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$action = $data['action'] ?? 'adjust'; // 'adjust' | 'run_accrual' | 'dole_reset'

if ($action === 'adjust') {
    $targetUserId = (int)($data['user_id'] ?? 0);
    $leaveType = $data['leave_type'] ?? 'VL'; // 'SIL', 'VL', 'SL', 'SoloParent'
    $amount = (float)($data['amount'] ?? 0);
    $note = trim($data['note'] ?? 'HR administrative adjustment');

    if ($targetUserId <= 0 || $amount == 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid user ID or adjustment amount.']);
        exit;
    }

    $col = 'vl_balance';
    if ($leaveType === 'SIL') $col = 'sil_balance';
    if ($leaveType === 'SL') $col = 'sl_balance';
    if ($leaveType === 'SoloParent') $col = 'solo_parent_balance';

    $stmt = $pdo->prepare("UPDATE leave_balances SET {$col} = MAX(0, {$col} + ?) WHERE user_id = ?");
    $stmt->execute([$amount, $targetUserId]);

    // Fetch target user name
    $uStmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $uStmt->execute([$targetUserId]);
    $targetName = $uStmt->fetchColumn();

    $sign = $amount > 0 ? "+{$amount}" : "{$amount}";
    $auditStmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    $auditStmt->execute([
        $user['id'], 
        'BALANCE_ADJUSTMENT', 
        "Adjusted {$leaveType} by {$sign} day(s) for {$targetName}. Reason: {$note}", 
        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
    ]);

    echo json_encode([
        'success' => true,
        'message' => "Successfully adjusted {$leaveType} for {$targetName} by {$sign} day(s)."
    ]);
    exit;
}

if ($action === 'run_accrual') {
    // Standard monthly accrual (+1.25 Vacation Leave days across all active employees)
    $pdo->exec("UPDATE leave_balances SET vl_balance = vl_balance + 1.25");

    $auditStmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    $auditStmt->execute([
        $user['id'], 
        'MONTHLY_ACCRUAL', 
        'Ran monthly leave accrual (+1.25 VL days granted to all active staff).', 
        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Monthly accrual (+1.25 Vacation Leave days) successfully credited to all staff!'
    ]);
    exit;
}

if ($action === 'dole_reset') {
    // Annual DOLE SIL reset: Reset SIL to 5.0 and compute monetization
    $pdo->exec("UPDATE leave_balances SET sil_balance = 5.0");

    $auditStmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    $auditStmt->execute([
        $user['id'], 
        'DOLE_SIL_ROLLOVER', 
        'Executed Annual DOLE SIL Reset (Art. 95) — Fresh 5.0 days granted for fiscal year.', 
        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Annual DOLE SIL Rollover executed! All staff balances refreshed to 5.0 SIL days.'
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
