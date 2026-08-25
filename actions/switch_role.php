<?php
// actions/switch_role.php - Instant demo role switcher for Saturday presentation
require_once __DIR__ . '/../config/db.php';

$role = $_GET['role'] ?? 'staff';

$email = 'jessica@jtyeocpa.ph';
if ($role === 'supervisor') $email = 'mark@jtyeocpa.ph';
if ($role === 'admin') $email = 'admin@jtyeocpa.ph';

$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if ($user) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['department'] = $user['department'];
    $_SESSION['title'] = $user['title'];
    $_SESSION['avatar'] = $user['avatar_initials'];
}

if ($role === 'admin') {
    header('Location: ../admin_dashboard.php');
} elseif ($role === 'supervisor') {
    header('Location: ../supervisor_dashboard.php');
} else {
    header('Location: ../staff_dashboard.php');
}
exit;
