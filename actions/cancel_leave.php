<?php
// actions/cancel_leave.php - Cancel / Withdraw a leave application (Staff or Admin)
require_once __DIR__ . '/../auth.php';
requireLogin();
requirePostWithCsrf();

header('Content-Type: application/json');

$user = getCurrentUser();
$data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$refNo = trim($data['ref_no'] ?? '');
$cancelReason = trim($data['reason'] ?? 'Cancelled by applicant');

if (empty($refNo)) {
    echo json_encode(['success' => false, 'message' => 'Reference number is required.']);
    exit;
}

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

// Permission check: Must be the applicant or an Admin
$isOwner = ((int)$req['user_id'] === (int)$user['id']);
$isAdmin = ($user['role'] === 'admin');

if (!$isOwner && !$isAdmin) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized: You can only cancel your own leave applications.']);
    exit;
}

// Status check
if ($req['status'] === 'Cancelled') {
    echo json_encode(['success' => false, 'message' => 'This application is already cancelled.']);
    exit;
}

if ($req['status'] === 'Rejected') {
    echo json_encode(['success' => false, 'message' => 'This application has already been rejected.']);
    exit;
}

$today = date('Y-m-d');
$canCancel = false;

if ($req['status'] === 'Pending') {
    $canCancel = true;
} elseif ($req['status'] === 'Approved') {
    // Approved leave can be cancelled if it hasn't ended yet
    if ($req['end_date'] >= $today || $isAdmin) {
        $canCancel = true;
    } else {
        echo json_encode(['success' => false, 'message' => 'Cannot cancel a leave schedule that has already finished.']);
        exit;
    }
}

if (!$canCancel) {
    echo json_encode(['success' => false, 'message' => 'This leave application cannot be cancelled.']);
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

try {
    $pdo->beginTransaction();
    $days = (float)$req['days_count'];

    // If it was already Approved and paid, refund the deducted balance
    if ($isPaid && $req['status'] === 'Approved') {
        $pdo->prepare('
            UPDATE user_leave_allocations 
            SET remaining_days = remaining_days + ?, updated_at = CURRENT_TIMESTAMP 
            WHERE user_id = ? AND leave_type_code = ?
        ')->execute([$days, $req['user_id'], $req['leave_type']]);

        if ($balanceCol) {
            $pdo->prepare("
                UPDATE leave_balances 
                SET {$balanceCol} = {$balanceCol} + ?, updated_at = CURRENT_TIMESTAMP 
                WHERE user_id = ?
            ")->execute([$days, $req['user_id']]);
        }
    }

    $actor = $isOwner ? 'applicant' : 'Managing Partner';
    $finalReason = $cancelReason ? "Cancelled by {$actor}: {$cancelReason}" : "Cancelled by {$actor}";

    $pdo->prepare('
        UPDATE leave_requests 
        SET status = \'Cancelled\', rejection_reason = ?, decided_at = CURRENT_TIMESTAMP 
        WHERE ref_no = ?
    ')->execute([$finalReason, $refNo]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Leave application {$refNo} was cancelled successfully. The scheduled dates and credits have been freed.",
        'ref_no' => $refNo,
        'status' => 'Cancelled'
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Cancellation failed: ' . $e->getMessage()]);
}
