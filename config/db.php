<?php
// config/db.php - Database connection, auto-initialization & migration

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

$dbDir = __DIR__ . '/../database';
if (!file_exists($dbDir)) {
    mkdir($dbDir, 0777, true);
}

// Ensure upload directories exist
$uploadDirs = [
    __DIR__ . '/../uploads',
    __DIR__ . '/../uploads/avatars',
    __DIR__ . '/../uploads/attachments'
];
foreach ($uploadDirs as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
}

$dbPath = $dbDir . '/leave_system.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Initialize core tables
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'staff', -- 'staff', 'admin'
            title TEXT NOT NULL,
            gender TEXT NOT NULL DEFAULT 'Female', -- 'Female', 'Male'
            biometric_pin TEXT,
            face_enrolled INTEGER DEFAULT 1,
            fingerprint_enrolled INTEGER DEFAULT 1,
            avatar_path TEXT,
            avatar_initials TEXT NOT NULL,
            department TEXT DEFAULT 'General Practice',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS leave_balances (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            vl_balance REAL DEFAULT 12.0, -- Vacation Leave
            sl_balance REAL DEFAULT 10.0, -- Sick Leave
            emergency_balance REAL DEFAULT 5.0, -- Emergency Leave
            bereavement_balance REAL DEFAULT 3.0, -- Bereavement Leave
            solo_parent_balance REAL DEFAULT 7.0, -- Solo Parent Leave
            maternity_balance REAL DEFAULT 105.0, -- Maternity Leave (Female)
            paternity_balance REAL DEFAULT 7.0, -- Paternity Leave (Male)
            special_women_balance REAL DEFAULT 60.0, -- Special Leave for Women (Female)
            sil_balance REAL DEFAULT 5.0,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS leave_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ref_no TEXT UNIQUE NOT NULL,
            user_id INTEGER NOT NULL,
            leave_type TEXT NOT NULL,
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

        CREATE TABLE IF NOT EXISTS biometric_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            biometric_pin TEXT,
            log_date DATE NOT NULL,
            time_in TIME,
            break_out TIME,
            break_in TIME,
            time_out TIME,
            verification_method TEXT DEFAULT 'Face Scan', -- 'Face Scan', 'Fingerprint', 'PIN / Card'
            status TEXT DEFAULT 'On-Time', -- 'On-Time', 'Late', 'Undertime', 'Present'
            device_model TEXT DEFAULT 'ZKTeco MB460 Plus',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE UNIQUE INDEX IF NOT EXISTS idx_bio_user_date ON biometric_logs(user_id, log_date);

        CREATE TABLE IF NOT EXISTS email_notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recipient_email TEXT NOT NULL,
            recipient_name TEXT,
            subject TEXT NOT NULL,
            body TEXT NOT NULL,
            notification_type TEXT, -- 'leave_filed', 'leave_approved', 'leave_rejected'
            status TEXT DEFAULT 'Sent',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS leave_types (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            description TEXT,
            default_days REAL DEFAULT 0,
            is_paid INTEGER DEFAULT 1,
            gender_restriction TEXT DEFAULT 'All', -- 'All', 'Female', 'Male'
            requires_attachment INTEGER DEFAULT 0,
            color TEXT DEFAULT '#dc0000',
            is_active INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS user_leave_allocations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            leave_type_code TEXT NOT NULL,
            allocated_days REAL NOT NULL DEFAULT 0,
            remaining_days REAL NOT NULL DEFAULT 0,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE(user_id, leave_type_code)
        );
    ");

    // Dynamic Column Migration Helper for existing databases
    $userCols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('gender', $userCols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN gender TEXT NOT NULL DEFAULT 'Female'");
    }
    if (!in_array('biometric_pin', $userCols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN biometric_pin TEXT");
    }
    if (!in_array('face_enrolled', $userCols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN face_enrolled INTEGER DEFAULT 1");
    }
    if (!in_array('fingerprint_enrolled', $userCols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN fingerprint_enrolled INTEGER DEFAULT 1");
    }
    if (!in_array('avatar_path', $userCols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN avatar_path TEXT");
    }

    $bioCols = $pdo->query("PRAGMA table_info(biometric_logs)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('break_out', $bioCols)) {
        $pdo->exec("ALTER TABLE biometric_logs ADD COLUMN break_out TIME");
    }
    if (!in_array('break_in', $bioCols)) {
        $pdo->exec("ALTER TABLE biometric_logs ADD COLUMN break_in TIME");
    }

    $balanceCols = $pdo->query("PRAGMA table_info(leave_balances)")->fetchAll(PDO::FETCH_COLUMN, 1);
    $requiredBalanceCols = [
        'vl_balance' => 'REAL DEFAULT 12.0',
        'sl_balance' => 'REAL DEFAULT 10.0',
        'emergency_balance' => 'REAL DEFAULT 5.0',
        'bereavement_balance' => 'REAL DEFAULT 3.0',
        'solo_parent_balance' => 'REAL DEFAULT 7.0',
        'maternity_balance' => 'REAL DEFAULT 105.0',
        'paternity_balance' => 'REAL DEFAULT 7.0',
        'special_women_balance' => 'REAL DEFAULT 60.0'
    ];
    foreach ($requiredBalanceCols as $col => $def) {
        if (!in_array($col, $balanceCols)) {
            $pdo->exec("ALTER TABLE leave_balances ADD COLUMN {$col} {$def}");
        }
    }

    // Seed default leave types if empty
    $typeCount = $pdo->query("SELECT COUNT(*) FROM leave_types")->fetchColumn();
    if ($typeCount == 0) {
        $defaultTypes = [
            ['VL', 'Vacation Leave', 'Standard annual paid vacation time', 12.0, 1, 'All', 0, '#3b82f6'],
            ['SL', 'Sick Leave', 'Medical care or recuperation leave', 10.0, 1, 'All', 1, '#10b981'],
            ['Emergency', 'Emergency Leave', 'Unforeseen domestic or family emergencies', 5.0, 1, 'All', 1, '#f59e0b'],
            ['Bereavement', 'Bereavement Leave', 'Immediate family death/grief leave', 3.0, 1, 'All', 1, '#6b7280'],
            ['LWOP', 'Leave Without Pay', 'Authorized leave duration without salary compensation', 0.0, 0, 'All', 0, '#94a3b8'],
            ['SoloParent', 'Solo Parent Leave', 'Parental leave under Solo Parents Welfare Act', 7.0, 1, 'All', 1, '#8b5cf6'],
            ['Maternity', 'Maternity Leave', '105-day statutory maternity benefit for female staff', 105.0, 1, 'Female', 1, '#ec4899'],
            ['Paternity', 'Paternity Leave', '7-day statutory paternity benefit for male staff', 7.0, 1, 'Male', 1, '#0284c7'],
            ['SpecialWomen', 'Special Leave for Women', 'Gynecological surgery/condition recovery leave', 60.0, 1, 'Female', 1, '#f43f5e'],
            ['Study', 'Study Leave', 'Dedicated study time for professional or academic examinations', 10.0, 1, 'All', 1, '#d97706'],
            ['Wellness', 'Mental Health & Wellness Day', 'Self-care, rest and burnout prevention day', 2.0, 1, 'All', 0, '#06b6d4']
        ];

        $insType = $pdo->prepare("
            INSERT INTO leave_types (code, name, description, default_days, is_paid, gender_restriction, requires_attachment, color, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        foreach ($defaultTypes as $dt) {
            $insType->execute($dt);
        }
    }

    // Auto-seed user_leave_allocations for any user missing records
    $allUsers = $pdo->query("SELECT id FROM users")->fetchAll(PDO::FETCH_COLUMN);
    $allTypes = $pdo->query("SELECT code, default_days FROM leave_types WHERE is_active = 1")->fetchAll();

    $insAlloc = $pdo->prepare("
        INSERT OR IGNORE INTO user_leave_allocations (user_id, leave_type_code, allocated_days, remaining_days)
        VALUES (?, ?, ?, ?)
    ");

    $codeToLegacy = [
        'VL' => 'vl_balance',
        'SL' => 'sl_balance',
        'Emergency' => 'emergency_balance',
        'Bereavement' => 'bereavement_balance',
        'SoloParent' => 'solo_parent_balance',
        'Maternity' => 'maternity_balance',
        'Paternity' => 'paternity_balance',
        'SpecialWomen' => 'special_women_balance'
    ];

    foreach ($allUsers as $uid) {
        $bStmt = $pdo->prepare("SELECT * FROM leave_balances WHERE user_id = ?");
        $bStmt->execute([$uid]);
        $legacy = $bStmt->fetch();

        foreach ($allTypes as $t) {
            $code = $t['code'];
            $defaultDays = (float)$t['default_days'];
            $rem = $defaultDays;
            if ($legacy && isset($codeToLegacy[$code]) && isset($legacy[$codeToLegacy[$code]])) {
                $rem = (float)$legacy[$codeToLegacy[$code]];
            }
            $insAlloc->execute([$uid, $code, $defaultDays, $rem]);
        }
    }

    // Seed users if empty
    $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($userCount == 0) {
        $defaultPassword = password_hash('password123', PASSWORD_DEFAULT);

        // 1. Jessica Alcantara, CPA (Female Senior Associate)
        $stmt = $pdo->prepare("
            INSERT INTO users (name, email, password, role, title, gender, biometric_pin, face_enrolled, fingerprint_enrolled, avatar_initials)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1, ?)
        ");
        $stmt->execute([
            'Jessica Alcantara, CPA',
            'jessica@jtyeocpa.ph',
            $defaultPassword,
            'staff',
            'Senior Tax Associate',
            'Female',
            '101',
            'JA'
        ]);
        $jessicaId = $pdo->lastInsertId();

        // 2. Atty. Jonathan Yeo, CPA (Managing Partner)
        $stmt->execute([
            'Atty. Jonathan Yeo, CPA',
            'admin@jtyeocpa.ph',
            $defaultPassword,
            'admin',
            'Managing Partner & HR Head',
            'Male',
            '100',
            'JY'
        ]);
        $adminId = $pdo->lastInsertId();

        // 3. Mark Santos, CPA (Male Senior Associate)
        $stmt->execute([
            'Mark Santos, CPA',
            'mark@jtyeocpa.ph',
            $defaultPassword,
            'staff',
            'Audit & Assurance Senior Associate',
            'Male',
            '102',
            'MS'
        ]);
        $markId = $pdo->lastInsertId();

        // 4. Rochelle Perez (Female Accounting Associate)
        $stmt->execute([
            'Rochelle Perez',
            'rochelle@jtyeocpa.ph',
            $defaultPassword,
            'staff',
            'Junior Audit Associate',
            'Female',
            '103',
            'RP'
        ]);
        $rochelleId = $pdo->lastInsertId();

        // Seed Default Leave Balances
        $balStmt = $pdo->prepare("
            INSERT INTO leave_balances (user_id, vl_balance, sl_balance, emergency_balance, bereavement_balance, solo_parent_balance, maternity_balance, paternity_balance, special_women_balance)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $balStmt->execute([$jessicaId, 12.0, 10.0, 5.0, 3.0, 7.0, 105.0, 0.0, 60.0]);
        $balStmt->execute([$adminId, 15.0, 10.0, 5.0, 3.0, 0.0, 0.0, 7.0, 0.0]);
        $balStmt->execute([$markId, 12.0, 10.0, 5.0, 3.0, 0.0, 0.0, 7.0, 0.0]);
        $balStmt->execute([$rochelleId, 12.0, 10.0, 5.0, 3.0, 0.0, 105.0, 0.0, 60.0]);

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

        // Seed Today's realistic DTR biometric logs (ZKTeco MB460 Plus)
        $today = date('Y-m-d');
        $bioStmt = $pdo->prepare("
            INSERT INTO biometric_logs (user_id, biometric_pin, log_date, time_in, time_out, verification_method, status, device_model)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'ZKTeco MB460 Plus')
        ");
        $bioStmt->execute([$jessicaId, '101', $today, '08:24:12', '17:31:05', 'Face Scan', 'On-Time']);
        $bioStmt->execute([$markId, '102', $today, '08:29:40', '17:35:10', 'Fingerprint', 'On-Time']);
        $bioStmt->execute([$rochelleId, '103', $today, '08:44:18', '17:30:00', 'Face Scan', 'On-Time']);
    } else {
    // Ensure existing users have gender and biometric pins set accurately
    $pdo->exec("UPDATE users SET gender = 'Male' WHERE name LIKE '%Jonathan%' OR name LIKE '%Mark%'");
    $pdo->exec("UPDATE users SET gender = 'Female' WHERE name LIKE '%Jessica%' OR name LIKE '%Rochelle%'");
    $pdo->exec("UPDATE users SET biometric_pin = '101' WHERE email = 'jessica@jtyeocpa.ph' AND (biometric_pin IS NULL OR biometric_pin = '')");
    $pdo->exec("UPDATE users SET biometric_pin = '100' WHERE email = 'admin@jtyeocpa.ph' AND (biometric_pin IS NULL OR biometric_pin = '')");
        
        // Seed today's sample biometric logs if empty
        $logCount = $pdo->query("SELECT COUNT(*) FROM biometric_logs")->fetchColumn();
        if ($logCount == 0) {
            $today = date('Y-m-d');
            $jId = $pdo->query("SELECT id FROM users WHERE email = 'jessica@jtyeocpa.ph'")->fetchColumn() ?: 1;
            $aId = $pdo->query("SELECT id FROM users WHERE email = 'admin@jtyeocpa.ph'")->fetchColumn() ?: 2;
            $bioStmt = $pdo->prepare("
                INSERT INTO biometric_logs (user_id, biometric_pin, log_date, time_in, time_out, verification_method, status, device_model)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'ZKTeco MB460 Plus')
            ");
            $bioStmt->execute([$jId, '101', $today, '08:24:12', '17:31:05', 'Face Scan', 'On-Time']);
            $bioStmt->execute([$aId, '100', $today, '08:15:30', null, 'Face Scan', 'On-Time']);
        }
    }
} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}
