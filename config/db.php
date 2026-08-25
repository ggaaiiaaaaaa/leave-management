<?php
// config/db.php - Database connection & auto-initialization

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$dbDir = __DIR__ . '/../database';
if (!file_exists($dbDir)) {
    mkdir($dbDir, 0777, true);
}

$dbPath = $dbDir . '/leave_system.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Initialize tables if not exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            role TEXT NOT NULL, -- 'staff', 'supervisor', 'admin'
            department TEXT NOT NULL,
            title TEXT NOT NULL,
            avatar_initials TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS leave_balances (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            sil_balance REAL DEFAULT 5.0, -- DOLE 5-day Service Incentive Leave
            vl_balance REAL DEFAULT 12.0, -- Vacation Leave
            sl_balance REAL DEFAULT 10.0, -- Sick Leave
            solo_parent_balance REAL DEFAULT 7.0,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS leave_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ref_no TEXT UNIQUE NOT NULL,
            user_id INTEGER NOT NULL,
            leave_type TEXT NOT NULL, -- 'SIL', 'VL', 'SL', 'SoloParent', 'MagnaCarta', 'VAWC', 'Emergency'
            leave_type_label TEXT NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            days_count REAL NOT NULL,
            duration_mode TEXT DEFAULT 'full',
            reason TEXT NOT NULL,
            attachment_path TEXT,
            status TEXT DEFAULT 'Pending', -- 'Pending', 'Approved', 'Rejected'
            approver_name TEXT,
            rejection_reason TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            decided_at DATETIME,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS holidays (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            holiday_date DATE NOT NULL,
            holiday_type TEXT NOT NULL, -- 'Regular', 'Special'
            description TEXT
        );

        CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT NOT NULL,
            details TEXT,
            ip_address TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Check if users exist; if not, seed realistic CPA firm demo accounts
    $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($userCount == 0) {
        $defaultPassword = password_hash('password123', PASSWORD_DEFAULT);

        // 1. Staff CPA
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, department, title, avatar_initials) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute(['Jessica Alcantara, CPA', 'jessica@jtyeocpa.ph', $defaultPassword, 'staff', 'Taxation & Compliance', 'Senior Tax Associate', 'JA']);
        $jessicaId = $pdo->lastInsertId();

        // 2. Managing Partner & HR Admin
        $stmt->execute(['Atty. Jonathan Yeo, CPA', 'admin@jtyeocpa.ph', $defaultPassword, 'admin', 'Management & HR', 'Managing Partner & HR Head', 'JY']);
        $adminId = $pdo->lastInsertId();

        // Seed Default Leave Balances (Full annual entitlements)
        $balStmt = $pdo->prepare("INSERT INTO leave_balances (user_id, sil_balance, vl_balance, sl_balance, solo_parent_balance) VALUES (?, ?, ?, ?, ?)");
        $balStmt->execute([$jessicaId, 5.0, 12.0, 10.0, 7.0]);
        $balStmt->execute([$adminId, 5.0, 12.0, 10.0, 0.0]);

        // Seed Philippine Holidays
        $holStmt = $pdo->prepare("INSERT INTO holidays (title, holiday_date, holiday_type, description) VALUES (?, ?, ?, ?)");
        $holidays = [
            ['New Year\'s Day', '2026-01-01', 'Regular', 'National Holiday'],
            ['EDSA People Power Revolution', '2026-02-25', 'Special', 'Special Non-Working Day'],
            ['Maundy Thursday', '2026-04-02', 'Regular', 'Holy Week Observance'],
            ['Good Friday', '2026-04-03', 'Regular', 'Holy Week Observance'],
            ['Araw ng Kagitingan', '2026-04-09', 'Regular', 'Day of Valor'],
            ['Labor Day', '2026-05-01', 'Regular', 'Labor Day'],
            ['Independence Day', '2026-06-12', 'Regular', 'Araw ng Kalayaan'],
            ['National Heroes Day', '2026-08-31', 'Regular', 'National Regular Holiday'],
            ['All Saints\' Day', '2026-11-01', 'Special', 'Special Non-Working Day'],
            ['All Souls\' Day', '2026-11-02', 'Special', 'Special Non-Working Day'],
            ['Bonifacio Day', '2026-11-30', 'Regular', 'National Regular Holiday'],
            ['Christmas Day', '2026-12-25', 'Regular', 'National Holiday'],
            ['Rizal Day', '2026-12-30', 'Regular', 'National Holiday'],
            ['Last Day of the Year', '2026-12-31', 'Special', 'Special Non-Working Day']
        ];
        foreach ($holidays as $h) {
            $holStmt->execute($h);
        }
    }
} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}
