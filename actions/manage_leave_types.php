<?php
// actions/manage_leave_types.php - Manage custom leave types and bulk/individual allocation matrix
require_once __DIR__ . '/../auth.php';
requireLogin();

header('Content-Type: application/json');

if (!hasRole('admin')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Admin privileges required.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = trim($input['action'] ?? '');

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

try {
    switch ($action) {
        case 'add_type':
            $name = trim($input['name'] ?? '');
            $code = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', trim($input['code'] ?? '')));
            if (empty($code) && !empty($name)) {
                $code = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $name), 0, 8));
            }
            $description = trim($input['description'] ?? '');
            $defaultDays = max(0, (float)($input['default_days'] ?? 0));
            $isPaid = (int)($input['is_paid'] ?? 1);
            $genderRestriction = trim($input['gender_restriction'] ?? 'All');
            if (!in_array($genderRestriction, ['All', 'Female', 'Male'])) {
                $genderRestriction = 'All';
            }
            $requiresAttachment = (int)($input['requires_attachment'] ?? 0);
            $color = trim($input['color'] ?? '#dc0000');
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                $color = '#dc0000';
            }

            if (empty($name) || empty($code)) {
                echo json_encode(['success' => false, 'message' => 'Please provide a valid leave type name and unique code.']);
                exit;
            }

            // Check duplicate code
            $chk = $pdo->prepare("SELECT id FROM leave_types WHERE code = ?");
            $chk->execute([$code]);
            if ($chk->fetch()) {
                echo json_encode(['success' => false, 'message' => "A leave type with code '{$code}' already exists."]);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO leave_types (code, name, description, default_days, is_paid, gender_restriction, requires_attachment, color, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$code, $name, $description, $defaultDays, $isPaid, $genderRestriction, $requiresAttachment, $color]);
            $newTypeId = $pdo->lastInsertId();

            // Auto-populate user_leave_allocations for all users
            $allUsers = $pdo->query("SELECT id FROM users")->fetchAll(PDO::FETCH_COLUMN);
            $insAlloc = $pdo->prepare("
                INSERT OR IGNORE INTO user_leave_allocations (user_id, leave_type_code, allocated_days, remaining_days)
                VALUES (?, ?, ?, ?)
            ");
            foreach ($allUsers as $uid) {
                $insAlloc->execute([$uid, $code, $defaultDays, $defaultDays]);
            }

            echo json_encode([
                'success' => true,
                'message' => "Leave category '{$name}' created successfully with {$defaultDays} default days allocated to all associates.",
                'type_id' => $newTypeId,
                'code' => $code
            ]);
            break;

        case 'edit_type':
            $id = (int)($input['id'] ?? 0);
            $name = trim($input['name'] ?? '');
            $description = trim($input['description'] ?? '');
            $defaultDays = max(0, (float)($input['default_days'] ?? 0));
            $isPaid = (int)($input['is_paid'] ?? 1);
            $genderRestriction = trim($input['gender_restriction'] ?? 'All');
            if (!in_array($genderRestriction, ['All', 'Female', 'Male'])) {
                $genderRestriction = 'All';
            }
            $requiresAttachment = (int)($input['requires_attachment'] ?? 0);
            $color = trim($input['color'] ?? '#dc0000');
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                $color = '#dc0000';
            }

            if ($id <= 0 || empty($name)) {
                echo json_encode(['success' => false, 'message' => 'Please provide valid leave category details.']);
                exit;
            }

            $stmt = $pdo->prepare("
                UPDATE leave_types
                SET name = ?, description = ?, default_days = ?, is_paid = ?,
                    gender_restriction = ?, requires_attachment = ?, color = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $description, $defaultDays, $isPaid, $genderRestriction, $requiresAttachment, $color, $id]);

            echo json_encode(['success' => true, 'message' => "Leave policy '{$name}' updated successfully."]);
            break;

        case 'toggle_status':
            $id = (int)($input['id'] ?? 0);
            $isActive = (int)($input['is_active'] ?? 1);

            $stmt = $pdo->prepare("UPDATE leave_types SET is_active = ? WHERE id = ?");
            $stmt->execute([$isActive, $id]);

            $statusText = $isActive ? 'activated' : 'archived';
            echo json_encode(['success' => true, 'message' => "Leave category has been {$statusText}."]);
            break;

        case 'bulk_allocate':
            $code = trim($input['leave_type_code'] ?? '');
            $amount = max(0, (float)($input['amount'] ?? 0));
            $mode = trim($input['mode'] ?? 'set'); // 'set' = set new standard quota, 'add' = add bonus days

            // Verify leave type exists
            $tStmt = $pdo->prepare("SELECT name FROM leave_types WHERE code = ?");
            $tStmt->execute([$code]);
            $typeName = $tStmt->fetchColumn();

            if (!$typeName) {
                echo json_encode(['success' => false, 'message' => 'Invalid leave category code.']);
                exit;
            }

            $allUsers = $pdo->query("SELECT id FROM users")->fetchAll(PDO::FETCH_COLUMN);
            $count = 0;

            foreach ($allUsers as $uid) {
                // Ensure row exists
                $chk = $pdo->prepare("SELECT id, remaining_days, allocated_days FROM user_leave_allocations WHERE user_id = ? AND leave_type_code = ?");
                $chk->execute([$uid, $code]);
                $alloc = $chk->fetch();

                if (!$alloc) {
                    $pdo->prepare("INSERT INTO user_leave_allocations (user_id, leave_type_code, allocated_days, remaining_days) VALUES (?, ?, ?, ?)")
                        ->execute([$uid, $code, $amount, $amount]);
                } else {
                    if ($mode === 'set') {
                        $pdo->prepare("UPDATE user_leave_allocations SET allocated_days = ?, remaining_days = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND leave_type_code = ?")
                            ->execute([$amount, $amount, $uid, $code]);
                    } else {
                        // Add mode
                        $pdo->prepare("UPDATE user_leave_allocations SET allocated_days = allocated_days + ?, remaining_days = remaining_days + ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND leave_type_code = ?")
                            ->execute([$amount, $amount, $uid, $code]);
                    }
                }

                // Sync legacy leave_balances if standard category
                if (isset($legacyColMap[$code])) {
                    $col = $legacyColMap[$code];
                    $chkLeg = $pdo->prepare("SELECT id FROM leave_balances WHERE user_id = ?");
                    $chkLeg->execute([$uid]);
                    if (!$chkLeg->fetch()) {
                        $pdo->prepare("INSERT INTO leave_balances (user_id) VALUES (?)")->execute([$uid]);
                    }
                    if ($mode === 'set') {
                        $pdo->prepare("UPDATE leave_balances SET {$col} = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?")->execute([$amount, $uid]);
                    } else {
                        $pdo->prepare("UPDATE leave_balances SET {$col} = {$col} + ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?")->execute([$amount, $uid]);
                    }
                }
                $count++;
            }

            $actionWord = ($mode === 'set') ? "reset to {$amount} days" : "increased by +{$amount} days";
            echo json_encode([
                'success' => true,
                'message' => "Successfully {$actionWord} for all {$count} associates in '{$typeName}'."
            ]);
            break;

        case 'adjust_user_credit':
            $userId = (int)($input['user_id'] ?? 0);
            $code = trim($input['leave_type_code'] ?? '');
            $adjustment = (float)($input['adjustment'] ?? 0); // e.g. +2 or -1
            $newRemaining = isset($input['new_remaining']) ? (float)$input['new_remaining'] : null;

            if ($userId <= 0 || empty($code)) {
                echo json_encode(['success' => false, 'message' => 'Invalid associate or leave category specified.']);
                exit;
            }

            // Ensure allocation row exists
            $chk = $pdo->prepare("SELECT remaining_days, allocated_days FROM user_leave_allocations WHERE user_id = ? AND leave_type_code = ?");
            $chk->execute([$userId, $code]);
            $alloc = $chk->fetch();

            if (!$alloc) {
                // Fetch default quota from leave_types
                $dVal = (float)$pdo->query("SELECT default_days FROM leave_types WHERE code = " . $pdo->quote($code))->fetchColumn();
                $pdo->prepare("INSERT INTO user_leave_allocations (user_id, leave_type_code, allocated_days, remaining_days) VALUES (?, ?, ?, ?)")
                    ->execute([$userId, $code, $dVal, $dVal]);
                $currentRem = $dVal;
            } else {
                $currentRem = (float)$alloc['remaining_days'];
            }

            if ($newRemaining !== null) {
                $finalRem = max(0, $newRemaining);
            } else {
                $finalRem = max(0, $currentRem + $adjustment);
            }

            $upd = $pdo->prepare("UPDATE user_leave_allocations SET remaining_days = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND leave_type_code = ?");
            $upd->execute([$finalRem, $userId, $code]);

            // Sync legacy column if exists
            if (isset($legacyColMap[$code])) {
                $col = $legacyColMap[$code];
                $pdo->prepare("UPDATE leave_balances SET {$col} = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?")->execute([$finalRem, $userId]);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Leave balance updated successfully.',
                'user_id' => $userId,
                'leave_type_code' => $code,
                'remaining_days' => $finalRem
            ]);
            break;

        case 'get_matrix':
            $types = $pdo->query("SELECT * FROM leave_types ORDER BY is_active DESC, id ASC")->fetchAll();
            $users = $pdo->query("SELECT id, name, email, role, department, title, gender FROM users ORDER BY name ASC")->fetchAll();

            $matrix = [];
            foreach ($users as $u) {
                $uid = $u['id'];
                $allocStmt = $pdo->prepare("SELECT leave_type_code, allocated_days, remaining_days FROM user_leave_allocations WHERE user_id = ?");
                $allocStmt->execute([$uid]);
                $allocs = $allocStmt->fetchAll(PDO::FETCH_KEY_PAIR | PDO::FETCH_GROUP); // or associative

                $userAllocs = [];
                foreach ($allocStmt->fetchAll() as $row) {
                    $userAllocs[$row['leave_type_code']] = (float)$row['remaining_days'];
                }

                $matrix[] = [
                    'user' => $u,
                    'balances' => $userAllocs
                ];
            }

            echo json_encode([
                'success' => true,
                'leave_types' => $types,
                'matrix' => $matrix
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action requested.']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
