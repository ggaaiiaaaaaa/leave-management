<?php
// actions/decide_leave.php - Process Manager / Partner approval or rejection
require_once __DIR__ . '/../auth.php';
requireLogin();

header('Content-Type: application/json');

if (!hasRole('admin')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Only Managing Partner can decide on leave requests.']);
    exit;
}

$user = getCurrentUser();
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    $data = $_POST;
}

$refNo = $data['ref_no'] ?? '';
$decision = $data['decision'] ?? ''; // 'Approved' or 'Rejected'
$reason = $data['reason'] ?? '';

if (!in_array($decision, ['Approved', 'Rejected']) || empty($refNo)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
    exit;
}

// Fetch request
$stmt = $pdo->prepare("SELECT * FROM leave_requests WHERE ref_no = ?");
$stmt->execute([$refNo]);
$req = $stmt->fetch();

if (!$req) {
    echo json_encode(['success' => false, 'message' => 'Leave request not found.']);
    exit;
}

// If rejected, restore deducted balance
if ($decision === 'Rejected' && $req['status'] === 'Pending') {
    $balanceCol = null;
    if ($req['leave_type'] === 'SIL') $balanceCol = 'sil_balance';
    if ($req['leave_type'] === 'VL') $balanceCol = 'vl_balance';
    if ($req['leave_type'] === 'SL') $balanceCol = 'sl_balance';
    if ($req['leave_type'] === 'SoloParent') $balanceCol = 'solo_parent_balance';

    if ($balanceCol) {
        $restoreStmt = $pdo->prepare("UPDATE leave_balances SET {$balanceCol} = {$balanceCol} + ? WHERE user_id = ?");
        $restoreStmt->execute([$req['days_count'], $req['user_id']]);
    }
}

// Update leave request
$updateStmt = $pdo->prepare("
    UPDATE leave_requests 
    SET status = ?, approver_name = ?, rejection_reason = ?, decided_at = CURRENT_TIMESTAMP
    WHERE ref_no = ?
");
$updateStmt->execute([$decision, $user['name'], $reason, $refNo]);

// Audit log
$commentNote = !empty($reason) ? " (Note: {$reason})" : "";
$auditStmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
$auditStmt->execute([$user['id'], 'DECIDE_LEAVE', "{$decision} leave request {$refNo} for {$req['days_count']}d {$req['leave_type_label']}.{$commentNote}", $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);

echo json_encode([
    'success' => true,
    'message' => "Leave application #{$refNo} marked as {$decision}."
]);
