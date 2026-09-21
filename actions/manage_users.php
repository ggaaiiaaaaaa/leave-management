<?php
// actions/manage_users.php - User account management, profile photo, credentials & notifications
require_once __DIR__ . '/../auth.php';
requireLogin();

header('Content-Type: application/json');

$user = getCurrentUser();
$action = $_POST['action'] ?? ($_GET['action'] ?? '');

// Helper to generate initials
function generateInitials($name) {
    $parts = explode(' ', trim($name));
    $initials = '';
    foreach ($parts as $p) {
        if (!empty($p) && ctype_alpha($p[0])) {
            $initials .= strtoupper($p[0]);
        }
        if (strlen($initials) >= 2) break;
    }
    return $initials ?: 'CP';
}

// 1. UPDATE OWN PROFILE (Staff or Admin: Name, Email, Role, Photo, Password)
if ($action === 'update_profile') {
    $userId = $user['id'];
    $name = trim($_POST['name'] ?? $user['name']);
    $email = trim($_POST['email'] ?? $user['email']);
    $role = trim($_POST['role'] ?? $user['role']);
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($name) || empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Name and email are required.']);
        exit;
    }

    // Fetch existing user record to check changes
    $curStmt = $pdo->prepare("SELECT id, name, email, role, avatar_path, avatar_initials, password FROM users WHERE id = ?");
    $curStmt->execute([$userId]);
    $curr = $curStmt->fetch();

    if (!$curr) {
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }

    // Check email uniqueness if email changed
    $emailChanged = (strtolower($email) !== strtolower($curr['email']));
    if ($emailChanged) {
        $chk = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id != ?");
        $chk->execute([$email, $userId]);
        if ($chk->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Another associate is already registered with this email address.']);
            exit;
        }
    }

    // Handle Password Change if requested
    $newHash = null;
    if (!empty($newPassword)) {
        if (strlen($newPassword) < 6) {
            echo json_encode(['success' => false, 'message' => 'New password must be at least 6 characters.']);
            exit;
        }
        if ($newPassword !== $confirmPassword) {
            echo json_encode(['success' => false, 'message' => 'New password and confirmation do not match.']);
            exit;
        }
        if (!password_verify($currentPassword, $curr['password'])) {
            echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
            exit;
        }
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    }

    // Handle Profile Photo Upload
    $avatarPath = $curr['avatar_path'];
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../uploads/avatars';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $fileInfo = pathinfo($_FILES['avatar']['name']);
        $ext = strtolower($fileInfo['extension'] ?? '');
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];

        if (in_array($ext, $allowedExts)) {
            $filename = 'avatar_' . $userId . '_' . time() . '.' . $ext;
            $targetPath = $uploadDir . '/' . $filename;
            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $targetPath)) {
                $avatarPath = 'uploads/avatars/' . $filename;
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid photo format. Please upload JPG, PNG, or WebP.']);
            exit;
        }
    }

    $initials = generateInitials($name);

    // Update user record
    $sql = "UPDATE users SET name = ?, email = ?, role = ?, avatar_initials = ?, avatar_path = ?";
    $params = [$name, $email, $role, $initials, $avatarPath];

    if ($newHash) {
        $sql .= ", password = ?";
        $params[] = $newHash;
    }
    $sql .= " WHERE id = ?";
    $params[] = $userId;

    $pdo->prepare($sql)->execute($params);

    // Update session
    $_SESSION['user']['name'] = $name;
    $_SESSION['user']['email'] = $email;
    $_SESSION['user']['role'] = $role;
    $_SESSION['user']['avatar_initials'] = $initials;
    $_SESSION['user']['avatar_path'] = $avatarPath;

    // NOTIFY ADMIN IF EMAIL WAS CHANGED
    if ($emailChanged) {
        require_once __DIR__ . '/../services/mailer.php';
        sendLeaveNotification($pdo, 'email_changed', [
            'employee_name' => $name,
            'old_email' => $curr['email'],
            'new_email' => $email,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    $msg = 'Profile updated successfully!';
    if ($emailChanged) {
        $msg .= ' Managing Partner was notified of your email change.';
    }

    echo json_encode([
        'success' => true,
        'message' => $msg,
        'name' => $name,
        'email' => $email,
        'role' => $role,
        'avatar_path' => $avatarPath
    ]);
    exit;
}

// Below actions require ADMIN role
if (!hasRole('admin')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Only Managing Partner can manage associate accounts.']);
    exit;
}

// 2. ADD NEW ASSOCIATE
if ($action === 'add_user' || $action === 'create_user') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? 'password123';
    $title = trim($_POST['title'] ?? 'Associate');
    $gender = trim($_POST['gender'] ?? 'Female');
    $role = trim($_POST['role'] ?? 'staff');
    $biometricPin = trim($_POST['biometric_pin'] ?? '');
    $faceEnrolled = isset($_POST['face_enrolled']) ? 1 : 0;
    $fingerprintEnrolled = isset($_POST['fingerprint_enrolled']) ? 1 : 0;

    if (empty($name) || empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Associate Name and Email are required.']);
        exit;
    }

    // Check duplicate email
    $chk = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?)");
    $chk->execute([$email]);
    if ($chk->fetch()) {
        echo json_encode(['success' => false, 'message' => 'An associate with this email address already exists.']);
        exit;
    }

    $initials = generateInitials($name);
    $pwdHash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        INSERT INTO users (name, email, password, role, title, gender, department, biometric_pin, face_enrolled, fingerprint_enrolled, avatar_initials)
        VALUES (?, ?, ?, ?, ?, ?, 'General Practice', ?, ?, ?, ?)
    ");
    $stmt->execute([$name, $email, $pwdHash, $role, $title, $gender, $biometricPin, $faceEnrolled, $fingerprintEnrolled, $initials]);
    $newUserId = $pdo->lastInsertId();

    // 1. Initialize Legacy Default Leave Balances
    $maternityBal = ($gender === 'Female') ? 105.0 : 0.0;
    $specialWomenBal = ($gender === 'Female') ? 60.0 : 0.0;
    $paternityBal = ($gender === 'Male') ? 7.0 : 0.0;

    $balStmt = $pdo->prepare("
        INSERT INTO leave_balances (
            user_id, vl_balance, sl_balance, emergency_balance, bereavement_balance,
            solo_parent_balance, maternity_balance, paternity_balance, special_women_balance
        ) VALUES (?, 15.0, 15.0, 5.0, 3.0, 7.0, ?, ?, ?)
    ");
    $balStmt->execute([$newUserId, $maternityBal, $paternityBal, $specialWomenBal]);

    // 2. Initialize Dynamic Policy Allocations (user_leave_allocations)
    $activeTypes = $pdo->query("SELECT code, default_days FROM leave_types WHERE is_active = 1")->fetchAll();
    $insAlloc = $pdo->prepare("
        INSERT OR IGNORE INTO user_leave_allocations (user_id, leave_type_code, allocated_days, remaining_days)
        VALUES (?, ?, ?, ?)
    ");
    foreach ($activeTypes as $at) {
        $days = (float)$at['default_days'];
        if ($at['code'] === 'ML' && $gender !== 'Female') $days = 0;
        if ($at['code'] === 'VAWC' && $gender !== 'Female') $days = 0;
        if ($at['code'] === 'PL' && $gender !== 'Male') $days = 0;
        $insAlloc->execute([$newUserId, $at['code'], $days, $days]);
    }

    echo json_encode([
        'success' => true,
        'message' => "Associate account for {$name} created successfully with default leave credits!",
        'user_id' => $newUserId
    ]);
    exit;
}

// 3. EDIT EXISTING ASSOCIATE ACCOUNT (With Photo Upload & Email Alert)
if ($action === 'edit_user') {
    $targetId = (int)($_POST['user_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $gender = trim($_POST['gender'] ?? 'Female');
    $role = trim($_POST['role'] ?? 'staff');
    $biometricPin = trim($_POST['biometric_pin'] ?? '');
    $faceEnrolled = isset($_POST['face_enrolled']) ? 1 : 0;
    $fingerprintEnrolled = isset($_POST['fingerprint_enrolled']) ? 1 : 0;
    $resetPassword = $_POST['reset_password'] ?? '';

    if ($targetId <= 0 || empty($name) || empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters. Name and Email are required.']);
        exit;
    }

    // Fetch existing user record to compare email & avatar
    $curStmt = $pdo->prepare("SELECT id, name, email, avatar_path FROM users WHERE id = ?");
    $curStmt->execute([$targetId]);
    $existing = $curStmt->fetch();

    if (!$existing) {
        echo json_encode(['success' => false, 'message' => 'Associate not found.']);
        exit;
    }

    // Check duplicate email with other users
    $chk = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id != ?");
    $chk->execute([$email, $targetId]);
    if ($chk->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Another associate is already using this email.']);
        exit;
    }

    // Handle Profile Photo Upload in Edit Associate modal
    $avatarPath = $existing['avatar_path'];
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../uploads/avatars';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $fileInfo = pathinfo($_FILES['avatar']['name']);
        $ext = strtolower($fileInfo['extension'] ?? '');
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];

        if (in_array($ext, $allowedExts)) {
            $filename = 'avatar_' . $targetId . '_' . time() . '.' . $ext;
            $targetPath = $uploadDir . '/' . $filename;
            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $targetPath)) {
                $avatarPath = 'uploads/avatars/' . $filename;
            }
        }
    }

    $emailChanged = (strtolower($email) !== strtolower($existing['email']));
    $initials = generateInitials($name);

    $sql = "UPDATE users SET name = ?, email = ?, title = ?, gender = ?, role = ?, biometric_pin = ?, face_enrolled = ?, fingerprint_enrolled = ?, avatar_initials = ?, avatar_path = ?";
    $params = [$name, $email, $title, $gender, $role, $biometricPin, $faceEnrolled, $fingerprintEnrolled, $initials, $avatarPath];

    if (!empty($resetPassword)) {
        $pwdHash = password_hash($resetPassword, PASSWORD_DEFAULT);
        $sql .= ", password = ?";
        $params[] = $pwdHash;
    }
    $sql .= " WHERE id = ?";
    $params[] = $targetId;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // NOTIFY ADMIN IF EMAIL WAS CHANGED
    if ($emailChanged) {
        require_once __DIR__ . '/../services/mailer.php';
        sendLeaveNotification($pdo, 'email_changed', [
            'employee_name' => $name,
            'old_email' => $existing['email'],
            'new_email' => $email,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => "Associate profile for {$name} updated successfully!" . ($emailChanged ? " (Admin email notification dispatched)" : ""),
        'avatar_path' => $avatarPath
    ]);
    exit;
}

// 4. FETCH USER DETAILS FOR EDIT MODAL
if ($action === 'get_user') {
    $targetId = (int)($_GET['user_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT id, name, email, role, title, gender, biometric_pin, face_enrolled, fingerprint_enrolled, avatar_path, avatar_initials FROM users WHERE id = ?");
    $stmt->execute([$targetId]);
    $u = $stmt->fetch();

    if (!$u) {
        echo json_encode(['success' => false, 'message' => 'Associate not found.']);
        exit;
    }

    echo json_encode(['success' => true, 'user' => $u]);
    exit;
}

// 5. DELETE ASSOCIATE ACCOUNT (Admin Only)
if ($action === 'delete_user') {
    if ($user['role'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Only administrators can delete associate accounts.']);
        exit;
    }

    $targetId = (int)($_POST['user_id'] ?? ($_GET['user_id'] ?? 0));
    if ($targetId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid associate ID specified.']);
        exit;
    }

    if ($targetId === (int)$user['id']) {
        echo json_encode(['success' => false, 'message' => 'You cannot delete your own logged-in administrator account.']);
        exit;
    }

    // Check if target user exists
    $uStmt = $pdo->prepare("SELECT id, name, avatar_path FROM users WHERE id = ?");
    $uStmt->execute([$targetId]);
    $targetUser = $uStmt->fetch();

    if (!$targetUser) {
        echo json_encode(['success' => false, 'message' => 'Associate not found or already deleted.']);
        exit;
    }

    $targetName = $targetUser['name'];

    try {
        $pdo->beginTransaction();

        // 1. Delete associated leave applications and physical attachments
        $leaveStmt = $pdo->prepare("SELECT attachment_path FROM leave_applications WHERE user_id = ?");
        $leaveStmt->execute([$targetId]);
        $attachments = $leaveStmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($attachments as $att) {
            if ($att && file_exists(__DIR__ . '/../' . $att)) {
                @unlink(__DIR__ . '/../' . $att);
            }
        }
        $pdo->prepare("DELETE FROM leave_applications WHERE user_id = ?")->execute([$targetId]);

        // 2. Delete user leave allocations
        $pdo->prepare("DELETE FROM user_leave_allocations WHERE user_id = ?")->execute([$targetId]);

        // 3. Delete attendance corrections
        $pdo->prepare("DELETE FROM attendance_corrections WHERE user_id = ?")->execute([$targetId]);

        // 4. Delete overtime requests
        $pdo->prepare("DELETE FROM overtime_requests WHERE user_id = ?")->execute([$targetId]);

        // 5. Delete biometric punch logs
        $pdo->prepare("DELETE FROM biometric_logs WHERE user_id = ?")->execute([$targetId]);

        // 6. Delete profile avatar file if uploaded
        if (!empty($targetUser['avatar_path']) && file_exists(__DIR__ . '/../' . $targetUser['avatar_path'])) {
            @unlink(__DIR__ . '/../' . $targetUser['avatar_path']);
        }

        // 7. Delete user account
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$targetId]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => "Associate \"{$targetName}\" has been permanently removed."
        ]);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Failed to delete associate: ' . $e->getMessage()]);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Unknown management action.']);
