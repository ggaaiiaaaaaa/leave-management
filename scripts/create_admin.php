<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config/db.php';

$email = trim($argv[1] ?? '');
$name = trim($argv[2] ?? '');
$gender = trim($argv[3] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || !in_array($gender, ['Female', 'Male'], true)) {
    fwrite(STDERR, "Usage: php scripts/create_admin.php email@example.com \"Full Name\" Female|Male\n");
    exit(1);
}
if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) {
    fwrite(STDERR, "Accounts already exist. Use the administrator interface.\n");
    exit(1);
}
fwrite(STDOUT, "Enter a unique password of at least 8 characters: ");
$password = rtrim(fgets(STDIN) ?: '', "\r\n");
if (strlen($password) < 8) {
    fwrite(STDERR, "Password is too short.\n");
    exit(1);
}
$parts = preg_split('/\s+/', $name);
$initials = strtoupper(substr($parts[0] ?? 'A', 0, 1) . substr($parts[1] ?? 'D', 0, 1));
$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO users (name, email, password, role, title, gender, avatar_initials) VALUES (?, ?, ?, 'admin', 'Managing Partner', ?, ?)")
        ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $gender, $initials]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO leave_balances (user_id) VALUES (?)')->execute([$id]);
    $types = $pdo->query('SELECT code, default_days FROM leave_types WHERE is_active = 1')->fetchAll();
    $alloc = $pdo->prepare('INSERT INTO user_leave_allocations (user_id, leave_type_code, allocated_days, remaining_days) VALUES (?, ?, ?, ?)');
    foreach ($types as $type) {
        $days = (float)$type['default_days'];
        if (in_array($type['code'], ['Maternity', 'SpecialWomen'], true) && $gender !== 'Female') $days = 0;
        if ($type['code'] === 'Paternity' && $gender !== 'Male') $days = 0;
        $alloc->execute([$id, $type['code'], $days, $days]);
    }
    $pdo->commit();
    fwrite(STDOUT, "Administrator created.\n");
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Could not create administrator.\n");
    exit(1);
}
