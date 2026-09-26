<?php
// auth.php - Authentication middleware & helper functions

require_once __DIR__ . '/config/db.php';

function getCurrentUser() {
    global $pdo;
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    
    $stmt = $pdo->prepare("
        SELECT u.*, b.vl_balance, b.sl_balance, b.emergency_balance, b.bereavement_balance,
               b.solo_parent_balance, b.maternity_balance, b.paternity_balance, b.special_women_balance
        FROM users u
        LEFT JOIN leave_balances b ON u.id = b.user_id
        WHERE u.id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        unset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['user_name']);
        return null;
    }
    return $user;
}

function isLoggedIn() {
    return getCurrentUser() !== null;
}

function requireLogin() {
    $user = getCurrentUser();
    if (!$user) {
        if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/actions/')) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthenticated']);
            exit;
        }
        $root = (str_contains($_SERVER['REQUEST_URI'] ?? '', '/leave-jtyeo/')) ? '/leave-jtyeo/login.php' : 'login.php';
        header("Location: {$root}");
        exit;
    }
    return $user;
}

function hasRole($roles) {
    $user = getCurrentUser();
    if (!$user) return false;
    if (is_array($roles)) {
        return in_array($user['role'], $roles, true);
    }
    return $user['role'] === $roles;
}

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function requirePostWithCsrf() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'POST required.']);
        exit;
    }
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (!is_string($provided) || !hash_equals(csrfToken(), $provided)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid request token. Refresh and try again.']);
        exit;
    }
}

function jsAttr($value) {
    return htmlspecialchars(json_encode((string)$value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
}
