<?php
// actions/decide_leave.php - Process Managing Partner approval or rejection
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../services/mailer.php';
requireLogin();

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

if ($decision === 'Approved' && $req['status'] !== 'Approved') {
    // Deduct from dynamic user_leave_allocations table
    $pdo->prepare("
        UPDATE user_leave_allocations
        SET remaining_days = MAX(0, remaining_days - ?), updated_at = CURRENT_TIMESTAMP
        WHERE user_id = ? AND leave_type_code = ?
    ")->execute([$req['days_count'], $req['user_id'], $req['leave_type']]);

    // Deduct leave credits from legacy balance table if applicable
    if ($balanceCol) {
        $deductStmt = $pdo->prepare("UPDATE leave_balances SET {$balanceCol} = MAX(0, {$balanceCol} - ?) WHERE user_id = ?");
        $deductStmt->execute([$req['days_count'], $req['user_id']]);
    }

    // Send email notification to employee
    sendLeaveNotification($pdo, 'leave_approved', [
        'ref_no' => $req['ref_no'],
        'employee_name' => $req['employee_name'],
        'employee_email' => $req['employee_email'],
        'leave_type_label' => $req['leave_type_label'],
        'start_date' => $req['start_date'],
        'end_date' => $req['end_date'],
        'days_count' => $req['days_count'],
        'approver_name' => $user['name']
    ]);
} elseif ($decision === 'Rejected') {
    // If request was previously Approved and is now being Reversed to Rejected, restore balance
    if ($req['status'] === 'Approved' && $balanceCol) {
        $restoreStmt = $pdo->prepare("UPDATE leave_balances SET {$balanceCol} = {$balanceCol} + ? WHERE user_id = ?");
        $restoreStmt->execute([$req['days_count'], $req['user_id']]);
    }

    // Send email notification to employee with notes
    sendLeaveNotification($pdo, 'leave_rejected', [
        'ref_no' => $req['ref_no'],
        'employee_name' => $req['employee_name'],
        'employee_email' => $req['employee_email'],
        'leave_type_label' => $req['leave_type_label'],
        'start_date' => $req['start_date'],
        'end_date' => $req['end_date'],
        'days_count' => $req['days_count'],
        'rejection_reason' => !empty($reason) ? $reason : 'Please coordinate with Atty. Jonathan Yeo regarding client commitments.'
    ]);
}

// Update request status
$updateStmt = $pdo->prepare("
    UPDATE leave_requests 
    SET status = ?, approver_name = ?, rejection_reason = ?, decided_at = CURRENT_TIMESTAMP
    WHERE ref_no = ?
");
$updateStmt->execute([$decision, $user['name'], $reason, $refNo]);

echo json_encode([
    'success' => true,
    'message' => "Application #{$refNo} marked as {$decision} successfully.",
    'status' => $decision
]);
