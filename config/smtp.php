<?php
// config/smtp.php - SMTP Mailer Configuration

// Helper to check getenv, $_ENV, and $_SERVER
$getVal = function($key, $default = '') {
    $val = getenv($key);
    if ($val !== false && $val !== '') return $val;
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];
    return $default;
};

// Check for optional local configuration override (convenient for shared hosting environments)
$localConfig = [];
$localConfigFile = __DIR__ . '/local_smtp.php';
if (file_exists($localConfigFile)) {
    $localConfig = require $localConfigFile;
}

$host = $localConfig['host'] ?? $getVal('LEAVE_SMTP_HOST', '');
$password = $localConfig['password'] ?? $getVal('LEAVE_SMTP_PASSWORD', '');
$username = $localConfig['username'] ?? $getVal('LEAVE_SMTP_USERNAME', '');
$port = (int)($localConfig['port'] ?? $getVal('LEAVE_SMTP_PORT', 587));
$secure = $localConfig['secure'] ?? $getVal('LEAVE_SMTP_SECURE', 'tls');
$fromEmail = $localConfig['from_email'] ?? $getVal('LEAVE_SMTP_FROM_EMAIL', $username ?: 'notifications@jtyeoaccounting.com');
$fromName = $localConfig['from_name'] ?? $getVal('LEAVE_SMTP_FROM_NAME', 'J.T. Yeo CPA Accounting Office');

return [
    'enabled' => !empty($host) && !empty($password),
    'host' => $host,
    'port' => $port,
    'secure' => $secure,
    'username' => $username,
    'password' => $password,
    'from_email' => $fromEmail,
    'from_name' => $fromName
];
