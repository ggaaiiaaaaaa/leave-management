<?php
// actions/decide_leave.php - Process Managing Partner approval or rejection
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../services/mailer.php';
requireLogin();
requirePostWithCsrf();

header('Content-Type: application/json');

if (!hasRole('admin')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Only the Managing Partner can review and decide on leaves.']);
    exit;
}

$user = getCurrentUser();
$data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$refNo = trim($data['ref_no'] ?? '');
$decision = trim($data['decision'] ?? ''); // 'Approved' or 'Rejected'
$reason = trim($data['reason'] ?? '');

if (!in_array($decision, ['Approved', 'Rejected']) || empty($refNo)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters. Please provide reference number and decision.']);
    exit;
}

// Fetch request with applicant details
$stmt = $pdo->prepare("
    SELECT r.*, u.name as employee_name, u.email as employee_email
    FROM leave_requests r
    JOIN users u ON r.user_id = u.id
    WHERE r.ref_no = ?
");
$stmt->execute([$refNo]);
$req = $stmt->fetch();

if (!$req) {
    echo json_encode(['success' => false, 'message' => 'Leave application not found.']);
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
$balanceCol = $balanceColMap[$req['leave_type']] ?? null;
$paidStmt = $pdo->prepare('SELECT is_paid FROM leave_types WHERE code = ?');
$paidStmt->execute([$req['leave_type']]);
$isPaid = (bool)$paidStmt->fetchColumn() && $req['leave_type'] !== 'LWOP';

if ($req['status'] === $decision) {
    echo json_encode(['success' => true, 'message' => 'Application already has this status.', 'status' => $decision]);
    exit;
}

try {
    $pdo->beginTransaction();
    $days = (float)$req['days_count'];
    if ($isPaid && $decision === 'Approved') {
        $deduct = $pdo->prepare('UPDATE user_leave_allocations SET remaining_days = remaining_days - ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND leave_type_code = ? AND remaining_days >= ?');
        $deduct->execute([$days, $req['user_id'], $req['leave_type'], $days]);
        if ($deduct->rowCount() !== 1) throw new RuntimeException('Insufficient available leave balance.');
        if ($balanceCol) {
            $pdo->prepare("UPDATE leave_balances SET {$balanceCol} = MAX(0, {$balanceCol} - ?), updated_at = CURRENT_TIMESTAMP WHERE user_id = ?")
                ->execute([$days, $req['user_id']]);
        }
    } elseif ($isPaid && $req['status'] === 'Approved' && $decision === 'Rejected') {
        $pdo->prepare('UPDATE user_leave_allocations SET remaining_days = remaining_days + ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND leave_type_code = ?')
            ->execute([$days, $req['user_id'], $req['leave_type']]);
        if ($balanceCol) {
            $pdo->prepare("UPDATE leave_balances SET {$balanceCol} = {$balanceCol} + ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?")
                ->execute([$days, $req['user_id']]);
        }
    }
    $pdo->prepare('UPDATE leave_requests SET status = ?, approver_name = ?, rejection_reason = ?, decided_at = CURRENT_TIMESTAMP WHERE ref_no = ?')
        ->execute([$decision, $user['name'], $reason, $refNo]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

sendLeaveNotification($pdo, $decision === 'Approved' ? 'leave_approved' : 'leave_rejected', [
    'ref_no' => $req['ref_no'],
    'employee_name' => $req['employee_name'],
    'employee_email' => $req['employee_email'],
    'leave_type_label' => $req['leave_type_label'],
    'start_date' => $req['start_date'],
    'end_date' => $req['end_date'],
    'days_count' => $req['days_count'],
    'approver_name' => $user['name'],
    'rejection_reason' => $reason ?: 'Please coordinate with the Managing Partner.'
]);

echo json_encode([
    'success' => true,
    'message' => "Application #{$refNo} marked as {$decision} successfully.",
    'status' => $decision
]);
