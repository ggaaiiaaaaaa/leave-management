# JTYeo CPA Leave and Attendance System

PHP 8.2, SQLite, vanilla JavaScript/CSS. The app provides staff and administrator views for leave requests, approvals, attendance corrections, overtime, DTR, and ZKTeco ADMS integration.

## Local setup

1. Place the project at `C:\xampp\htdocs\leave-jtyeo` and start Apache in XAMPP.
2. Ensure PHP has the `pdo_sqlite` and `fileinfo` extensions. The application stores its SQLite file outside the web folder at `C:\xampp\leave-jtyeo-data\leave_system.sqlite` by default. Set `LEAVE_DATA_DIR` to another private directory if needed. Back up that directory regularly.
3. On a new empty database, run `php scripts/create_admin.php admin@example.com "Administrator Name" Female` (or `Male`) in a terminal and enter a unique password when prompted. Then open `http://localhost/leave-jtyeo/` and add staff through the administrator interface. Demo accounts are created only when `LEAVE_SEED_DEMO=1` is set for local testing. Never enable that setting with real data.
4. Set a unique initial password of at least 12 characters when adding an associate.

## Optional integrations

- SMTP: configure `LEAVE_SMTP_HOST`, `LEAVE_SMTP_PORT`, `LEAVE_SMTP_USERNAME`, `LEAVE_SMTP_PASSWORD`, `LEAVE_SMTP_FROM_EMAIL`, and optionally `LEAVE_SMTP_SECURE` and `LEAVE_SMTP_FROM_NAME` in the server environment. The app will log notifications without sending them until SMTP is configured. PHP mail fallback is disabled unless `LEAVE_ALLOW_PHP_MAIL=1`.
- ZKTeco ADMS: set `LEAVE_DEVICE_IPS` to a comma-separated list of trusted device source IP addresses. The push receiver rejects all requests when this setting is empty. Configure the device to push to `/leave-jtyeo/iclock/cdata.php` through a trusted local network.

## Security and deployment

Apache must honor the included `.htaccess` files. XAMPP's default `htdocs` configuration uses `AllowOverride All`. Keep the data directory outside every web document root. For PHP's built-in server, run `php -S 127.0.0.1:8000 router.php` from this directory; the router blocks private files.

The login page no longer offers a demo role switch. Existing passwords and any previously exposed SMTP credential must be rotated. A secret removed from current source can still exist in older Git history; credential rotation is essential.

The interface uses Google Fonts and FullCalendar from external CDNs, so those elements need network access. The local Lucide icon bundle is included.
