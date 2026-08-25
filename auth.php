<?php
// auth.php - Authentication middleware & helper functions

require_once __DIR__ . '/config/db.php';

function getCurrentUser() {
    global $pdo;
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    
    $stmt = $pdo->prepare("
        SELECT u.*, b.sil_balance, b.vl_balance, b.sl_balance, b.solo_parent_balance
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
    if (!isset($_SESSION['role'])) return false;
    if (is_array($roles)) {
        return in_array($_SESSION['role'], $roles);
    }
    return $_SESSION['role'] === $roles;
}

