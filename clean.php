<?php
// clean.php - One-click Safe Production Data Cleanup for Hostinger
require_once __DIR__ . '/config/db.php';

$success = false;
$outputLog = [];
$errorMsg = null;

$action = $_POST['action'] ?? $_GET['action'] ?? null;

if ($action === 'run_clean') {
    try {
        $dbInfo = $pdo->query("PRAGMA database_list")->fetchAll();
        $activeDb = null;
        foreach ($dbInfo as $d) {
            if ($d['name'] === 'main') {
                $activeDb = $d['file'];
                break;
            }
        }
        $outputLog[] = "Active database in use: <strong>" . htmlspecialchars($activeDb) . "</strong>";

        // Backup
        if ($activeDb && file_exists($activeDb)) {
            $bPath = dirname($activeDb) . '/leave_system_backup_' . date('Ymd_His') . '.sqlite';
            copy($activeDb, $bPath);
            $outputLog[] = "Safety backup created: " . htmlspecialchars(basename($bPath));
        }

        $pdo->beginTransaction();

        // 1. Wipe all test transactional data
        $pdo->exec("DELETE FROM leave_requests");
        $pdo->exec("DELETE FROM overtime_requests");
        $pdo->exec("DELETE FROM attendance_corrections");
        $pdo->exec("DELETE FROM biometric_logs");
        $pdo->exec("DELETE FROM email_notifications");

        $hasAudit = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='audit_logs'")->fetchColumn();
        if ($hasAudit) {
            $pdo->exec("DELETE FROM audit_logs");
        }

        $resetTables = ['leave_requests', 'overtime_requests', 'attendance_corrections', 'biometric_logs', 'email_notifications'];
        if ($hasAudit) $resetTables[] = 'audit_logs';
        $seqIn = "'" . implode("', '", $resetTables) . "'";
        $pdo->exec("DELETE FROM sqlite_sequence WHERE name IN ({$seqIn})");

        // 2. Ensure the 4 real users exist and have full quotas
        $hostedUsers = [
            [
                'name' => 'Joseph Yeo, CPA',
                'title' => 'Managing Partner',
                'email' => 'josephtyeo@gmail.com',
                'gender' => 'Male',
                'role' => 'admin',
                'avatar_initials' => 'JY',
                'biometric_pin' => '100',
                'face_enrolled' => 1,
                'fingerprint_enrolled' => 1,
                'department' => 'Management & HR',
                'vl' => 20.0, 'sl' => 10.0, 'pat' => 7.0, 'mat' => 0.0
            ],
            [
                'name' => 'Joan Alfonso',
                'title' => 'Accounting Compliance Supervisor',
                'email' => 'iamjoanalfonso@gmail.com',
                'gender' => 'Female',
                'role' => 'staff',
                'avatar_initials' => 'JA',
                'biometric_pin' => '101',
                'face_enrolled' => 0,
                'fingerprint_enrolled' => 1,
                'department' => 'Accounting & Compliance',
                'vl' => 13.0, 'sl' => 2.0, 'pat' => 0.0, 'mat' => 0.0
            ],
            [
                'name' => 'Jessel Bacaling',
                'title' => 'Accounting Compliance Supervisor',
                'email' => 'jesselbacaling@gmail.com',
                'gender' => 'Female',
                'role' => 'staff',
                'avatar_initials' => 'JB',
                'biometric_pin' => '102',
                'face_enrolled' => 1,
                'fingerprint_enrolled' => 0,
                'department' => 'Accounting & Compliance',
                'vl' => 13.0, 'sl' => 10.0, 'pat' => 0.0, 'mat' => 105.0
            ],
            [
                'name' => 'itmonster-dev',
                'title' => 'IT',
                'email' => 'rhonjames95@gmail.com',
                'gender' => 'Male',
                'role' => 'admin',
                'avatar_initials' => 'I',
                'biometric_pin' => '7',
                'face_enrolled' => 0,
                'fingerprint_enrolled' => 0,
                'department' => 'Information Technology',
                'vl' => 13.0, 'sl' => 10.0, 'pat' => 7.0, 'mat' => 0.0
            ]
        ];

        // Check current users
        $currentEmails = $pdo->query("SELECT LOWER(email) FROM users")->fetchAll(PDO::FETCH_COLUMN);
        $needsSync = false;
        foreach ($hostedUsers as $hu) {
            if (!in_array(strtolower($hu['email']), $currentEmails, true)) {
                $needsSync = true;
                break;
            }
        }

        if ($needsSync) {
            $pdo->exec("DELETE FROM users");
            $pdo->exec("DELETE FROM leave_balances");
            $pdo->exec("DELETE FROM user_leave_allocations");
            $pdo->exec("DELETE FROM sqlite_sequence WHERE name IN ('users', 'leave_balances', 'user_leave_allocations')");

            $pwdHash = password_hash('password123', PASSWORD_DEFAULT);
            $insU = $pdo->prepare("
                INSERT INTO users (name, email, password, role, title, gender, department, biometric_pin, face_enrolled, fingerprint_enrolled, avatar_initials)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insB = $pdo->prepare("
                INSERT INTO leave_balances (user_id, vl_balance, sl_balance, emergency_balance, bereavement_balance, solo_parent_balance, maternity_balance, paternity_balance, special_women_balance, sil_balance)
                VALUES (?, ?, ?, 5.0, 3.0, ?, ?, ?, ?, 5.0)
            ");
            $insA = $pdo->prepare("
                INSERT INTO user_leave_allocations (user_id, leave_type_code, allocated_days, remaining_days)
                VALUES (?, ?, ?, ?)
            ");
            $activeTypes = $pdo->query("SELECT code, default_days, gender_restriction FROM leave_types WHERE is_active = 1")->fetchAll();

            foreach ($hostedUsers as $hu) {
                $insU->execute([$hu['name'], $hu['email'], $pwdHash, $hu['role'], $hu['title'], $hu['gender'], $hu['department'], $hu['biometric_pin'], $hu['face_enrolled'], $hu['fingerprint_enrolled'], $hu['avatar_initials']]);
                $uid = (int)$pdo->lastInsertId();

                $sp = ($hu['gender'] === 'Female') ? 7.0 : 0.0;
                $sw = ($hu['gender'] === 'Female') ? 60.0 : 0.0;
                $insB->execute([$uid, $hu['vl'], $hu['sl'], $sp, $hu['mat'], $hu['pat'], $sw]);

                foreach ($activeTypes as $at) {
                    $c = $at['code'];
                    $days = (float)$at['default_days'];
                    if ($c === 'VL') $days = $hu['vl'];
                    elseif ($c === 'SL') $days = $hu['sl'];
                    elseif ($c === 'Maternity') $days = $hu['mat'];
                    elseif ($c === 'Paternity') $days = $hu['pat'];
                    elseif ($at['gender_restriction'] !== 'All' && $at['gender_restriction'] !== $hu['gender']) $days = 0.0;
                    $insA->execute([$uid, $c, $days, $days]);
                }
            }
            $outputLog[] = "Synchronized all 4 real production users into the active database.";
        } else {
            // Reset leave allocations to full allocated quota
            $pdo->exec("UPDATE user_leave_allocations SET remaining_days = allocated_days, updated_at = CURRENT_TIMESTAMP");
            $outputLog[] = "Preserved existing users and reset leave credits back to full quota.";
        }

        $pdo->commit();

        // Clean physical attachments
        $cleanedFiles = 0;
        $attachDirs = [
            __DIR__ . '/uploads/attachments',
            dirname($activeDb) . '/attachments'
        ];
        foreach ($attachDirs as $dir) {
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') as $f) {
                    if (is_file($f) && basename($f) !== '.htaccess') {
                        @unlink($f);
                        $cleanedFiles++;
                    }
                }
            }
        }
        $outputLog[] = "Cleared {$cleanedFiles} test attachment file(s).";

        $success = true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errorMsg = $e->getMessage();
    }
}

if ($action === 'delete_script') {
    @unlink(__FILE__);
    echo "<h1>clean.php deleted successfully!</h1><p><a href='index.php'>Go to System</a></p>";
    exit;
}

$counts = [];
foreach (['users', 'leave_requests', 'biometric_logs', 'overtime_requests', 'attendance_corrections', 'email_notifications'] as $t) {
    try {
        $counts[$t] = $pdo->query("SELECT COUNT(*) FROM \"{$t}\"")->fetchColumn();
    } catch (Throwable $e) {
        $counts[$t] = 'N/A';
    }
}

$dbList = $pdo->query("PRAGMA database_list")->fetchAll();
$currentDbFile = $dbList[0]['file'] ?? 'Unknown';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>System Production Cleaner - J.T. Yeo CPA</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; padding: 30px 15px; margin: 0; }
    .card { max-width: 650px; margin: 0 auto; background: #1e293b; border-radius: 12px; padding: 28px; box-shadow: 0 10px 25px rgba(0,0,0,0.4); border: 1px solid #334155; }
    h1 { margin-top: 0; font-size: 22px; color: #fff; }
    .badge { display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: bold; background: #3b82f6; color: #fff; }
    .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin: 20px 0; }
    .stat { background: #0f172a; padding: 14px; border-radius: 8px; border: 1px solid #334155; }
    .stat-label { font-size: 12px; color: #94a3b8; text-transform: uppercase; }
    .stat-val { font-size: 22px; font-weight: bold; color: #38bdf8; margin-top: 4px; }
    .btn { display: inline-block; width: 100%; box-sizing: border-box; text-align: center; padding: 14px 20px; font-size: 15px; font-weight: bold; border-radius: 8px; border: none; cursor: pointer; transition: 0.2s; text-decoration: none; }
    .btn-clean { background: #ef4444; color: #fff; }
    .btn-clean:hover { background: #dc2626; }
    .btn-done { background: #10b981; color: #fff; margin-top: 10px; }
    .btn-done:hover { background: #059669; }
    .btn-delete { background: #64748b; color: #fff; margin-top: 10px; }
    .btn-delete:hover { background: #475569; }
    .log-box { background: #0f172a; padding: 14px; border-radius: 8px; margin: 18px 0; font-family: monospace; font-size: 13px; line-height: 1.6; border: 1px solid #334155; }
    .alert-success { background: #064e3b; border: 1px solid #059669; color: #6ee7b7; padding: 14px; border-radius: 8px; margin-bottom: 20px; }
    .alert-error { background: #7f1d1d; border: 1px solid #dc2626; color: #fca5a5; padding: 14px; border-radius: 8px; margin-bottom: 20px; }
    .path { word-break: break-all; color: #cbd5e1; font-size: 13px; background: #0f172a; padding: 8px 12px; border-radius: 6px; }
  </style>
</head>
<body>
  <div class="card">
    <h1>J.T. Yeo CPA Leave Management</h1>
    <p style="color:#94a3b8; font-size:14px; margin-bottom: 20px;">
      Live Production Cleaner &bull; Wipes test requests & keeps user profiles
    </p>

    <div style="margin-bottom: 18px;">
      <span class="stat-label">Active Database File</span>
      <div class="path"><?= htmlspecialchars($currentDbFile) ?></div>
    </div>

    <?php if ($success): ?>
      <div class="alert-success">
        <strong>Cleanup Completed!</strong> All test leave applications, punch records, and notifications have been cleaned. All 4 user accounts and leave credits are active.
      </div>
    <?php elseif ($errorMsg): ?>
      <div class="alert-error">
        <strong>Error:</strong> <?= htmlspecialchars($errorMsg) ?>
      </div>
    <?php endif; ?>

    <div class="grid">
      <div class="stat">
        <div class="stat-label">Active Users</div>
        <div class="stat-val"><?= htmlspecialchars($counts['users']) ?></div>
      </div>
      <div class="stat">
        <div class="stat-label">Leave Applications</div>
        <div class="stat-val" style="color: <?= (int)$counts['leave_requests'] > 0 ? '#f87171' : '#34d399' ?>;"><?= htmlspecialchars($counts['leave_requests']) ?></div>
      </div>
      <div class="stat">
        <div class="stat-label">Biometric Logs</div>
        <div class="stat-val" style="color: <?= (int)$counts['biometric_logs'] > 0 ? '#f87171' : '#34d399' ?>;"><?= htmlspecialchars($counts['biometric_logs']) ?></div>
      </div>
      <div class="stat">
        <div class="stat-label">Overtime Requests</div>
        <div class="stat-val" style="color: <?= (int)$counts['overtime_requests'] > 0 ? '#f87171' : '#34d399' ?>;"><?= htmlspecialchars($counts['overtime_requests']) ?></div>
      </div>
    </div>

    <?php if (!empty($outputLog)): ?>
      <div class="log-box">
        <?php foreach ($outputLog as $log): ?>
          <div>&bull; <?= $log ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!$success && ((int)$counts['leave_requests'] > 0 || (int)$counts['biometric_logs'] > 0 || (int)$counts['overtime_requests'] > 0)): ?>
      <form method="POST">
        <input type="hidden" name="action" value="run_clean">
        <button type="submit" class="btn btn-clean" onclick="return confirm('Clean all test leave requests, overtime, and biometric logs while keeping all 4 user accounts?')">
          Wipe Test Data Now (Preserve 4 Users)
        </button>
      </form>
    <?php else: ?>
      <a href="index.php" class="btn btn-done">Go to Live Dashboard</a>
      <form method="POST" style="margin-top: 10px;">
        <input type="hidden" name="action" value="delete_script">
        <button type="submit" class="btn btn-delete" onclick="return confirm('Delete this clean.php script for security?')">
          Delete clean.php (Security Recommendation)
        </button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>
