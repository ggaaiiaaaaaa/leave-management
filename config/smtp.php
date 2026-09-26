<?php
// Configure SMTP through server environment variables, never tracked credentials.
return [
    'enabled' => getenv('LEAVE_SMTP_HOST') !== false && getenv('LEAVE_SMTP_PASSWORD') !== false,
    'host' => getenv('LEAVE_SMTP_HOST') ?: '',
    'port' => (int)(getenv('LEAVE_SMTP_PORT') ?: 587),
    'secure' => getenv('LEAVE_SMTP_SECURE') ?: 'tls',
    'username' => getenv('LEAVE_SMTP_USERNAME') ?: '',
    'password' => getenv('LEAVE_SMTP_PASSWORD') ?: '',
    'from_email' => getenv('LEAVE_SMTP_FROM_EMAIL') ?: '',
    'from_name' => getenv('LEAVE_SMTP_FROM_NAME') ?: 'J.T. Yeo CPA Accounting Office'
];
