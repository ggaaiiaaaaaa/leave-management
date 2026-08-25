<?php
// index.php - Smart Gateway & Session Router for JTYeo CPA Leave Portal
require_once __DIR__ . '/auth.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$user = getCurrentUser();
if (!$user) {
    header('Location: login.php');
    exit;
}

$role = $user['role'] ?? 'staff';

if ($role === 'admin') {
    header('Location: admin_dashboard.php');
    exit;
} elseif ($role === 'supervisor') {
    header('Location: supervisor_dashboard.php');
    exit;
} else {
    header('Location: staff_dashboard.php');
    exit;
}
