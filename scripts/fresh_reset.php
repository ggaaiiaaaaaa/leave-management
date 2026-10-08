<?php
// scripts/fresh_reset.php - Clean Operational / Test Data for Production Handover (Preserves Users)
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script must be run from the command line for security.\n";
    exit;
}

require_once __DIR__ . '/../config/db.php';

$wipeUsers = in_array('--include-users', $argv, true);

echo "=========================================================\n";
echo "  J.T. YEO CPA LEAVE MANAGEMENT - SYSTEM DATA CLEANUP   \n";
echo "=========================================================\n";
if ($wipeUsers) {
    echo "[MODE: FULL PURGE INCLUDING USERS]\n";
    echo "This will wipe ALL transactional data AND user accounts.\n\n";
} else {
    echo "[MODE: PRODUCTION CLEAN - USERS PRESERVED]\n";
    echo "This will wipe transactional/test activity data:\n";
    echo " - Leave applications & approvals (leave_requests)\n";
    echo " - Overtime requests (overtime_requests)\n";
    echo " - Missed punch corrections (attendance_corrections)\n";
    echo " - Biometric logs & punch records (biometric_logs)\n";
    echo " - Email notification history (email_notifications)\n";
    echo " - System audit trail (audit_logs)\n";
    echo " - Uploaded test attachments\n\n";
    echo "IT WILL PRESERVE:\n";
    echo " + All user accounts & passwords (users)\n";
    echo " + User leave allocations & balances (reset to full quota)\n";
    echo " + Official Philippine holidays (holidays)\n";
    echo " + Firm leave policies & categories (leave_types)\n";
    echo " + Office hours & schedule settings (system_settings)\n\n";
}

$confirmed = in_array('--confirm', $argv, true);
if (!$confirmed) {
    echo "Type 'YES' to proceed with the data clean: ";
    $input = trim(fgets(STDIN) ?: '');
    if ($input !== 'YES') {
        echo "Cleanup cancelled. No changes made.\n";
        exit(0);
    }
}

try {
    // 1. Create a safe backup before wiping
    $dbPath = $pdo->query("PRAGMA database_list")->fetchAll();
    $mainDb = null;
    foreach ($dbPath as $d) {
        if ($d['name'] === 'main' && !empty($d['file'])) {
            $mainDb = $d['file'];
            break;
        }
    }
    if ($mainDb && file_exists($mainDb)) {
        $backupPath = dirname($mainDb) . '/leave_system_pre_reset_backup_' . date('Ymd_His') . '.sqlite';
        copy($mainDb, $backupPath);
        echo "[+] Automatic database backup created: {$backupPath}\n";
    }

    $pdo->beginTransaction();

    // 2. Wipe transactional data tables
    $pdo->exec("DELETE FROM leave_requests");
    $pdo->exec("DELETE FROM overtime_requests");
    $pdo->exec("DELETE FROM attendance_corrections");
    $pdo->exec("DELETE FROM biometric_logs");
    $pdo->exec("DELETE FROM email_notifications");

    $hasAuditLogs = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='audit_logs'")->fetchColumn();
    if ($hasAuditLogs) {
        $pdo->exec("DELETE FROM audit_logs");
    }

    $resetSeq = ['leave_requests', 'overtime_requests', 'attendance_corrections', 'biometric_logs', 'email_notifications'];
    if ($hasAuditLogs) $resetSeq[] = 'audit_logs';

    if ($wipeUsers) {
        $pdo->exec("DELETE FROM users");
        $pdo->exec("DELETE FROM leave_balances");
        $pdo->exec("DELETE FROM user_leave_allocations");
        $resetSeq[] = 'users';
        $resetSeq[] = 'leave_balances';
        $resetSeq[] = 'user_leave_allocations';
    } else {
        // Reset users' leave credits back to their full allocated days for a clean production start
        $pdo->exec("UPDATE user_leave_allocations SET remaining_days = allocated_days, updated_at = CURRENT_TIMESTAMP");

        // Align leave_balances with allocated amounts
        $users = $pdo->query("SELECT id FROM users")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($users as $uid) {
            $userAllocs = $pdo->query("SELECT leave_type_code, allocated_days FROM user_leave_allocations WHERE user_id = {$uid}")->fetchAll(PDO::FETCH_KEY_PAIR);
            if (!empty($userAllocs)) {
                $vl = (float)($userAllocs['VL'] ?? 15.0);
                $sl = (float)($userAllocs['SL'] ?? 10.0);
                $em = (float)($userAllocs['Emergency'] ?? 5.0);
                $ber = (float)($userAllocs['Bereavement'] ?? 3.0);
                $sp = (float)($userAllocs['SoloParent'] ?? 7.0);
                $mat = (float)($userAllocs['Maternity'] ?? 105.0);
                $pat = (float)($userAllocs['Paternity'] ?? 7.0);
                $sw = (float)($userAllocs['SpecialWomen'] ?? 60.0);
                $sil = (float)($userAllocs['SIL'] ?? 5.0);

                $stmt = $pdo->prepare("
                    UPDATE leave_balances SET
                        vl_balance = ?, sl_balance = ?, emergency_balance = ?,
                        bereavement_balance = ?, solo_parent_balance = ?,
                        maternity_balance = ?, paternity_balance = ?,
                        special_women_balance = ?, sil_balance = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE user_id = ?
                ");
                $stmt->execute([$vl, $sl, $em, $ber, $sp, $mat, $pat, $sw, $sil, $uid]);
            }
        }
    }

    // Reset autoincrement sequence counters back to 1 for wiped tables
    $seqList = "'" . implode("', '", $resetSeq) . "'";
    $pdo->exec("DELETE FROM sqlite_sequence WHERE name IN ({$seqList})");

    $pdo->commit();

    // 3. Clean test attachments (preserve avatars if keeping users)
    if ($wipeUsers) {
        $avatarDir = __DIR__ . '/../uploads/avatars';
        if (is_dir($avatarDir)) {
            foreach (glob($avatarDir . '/*') as $file) {
                if (is_file($file)) @unlink($file);
            }
        }
    }

    $attachDirs = [
        __DIR__ . '/../uploads/attachments',
        ($mainDb ? dirname($mainDb) . '/attachments' : null)
    ];
    foreach ($attachDirs as $ad) {
        if ($ad && is_dir($ad)) {
            foreach (glob($ad . '/*') as $file) {
                if (is_file($file) && basename($file) !== '.htaccess') @unlink($file);
            }
        }
    }

    // 4. Clean pending biometric command file
    $pendingCmd = __DIR__ . '/../iclock/pending_cmd.txt';
    if (file_exists($pendingCmd)) {
        @unlink($pendingCmd);
    }

    // Free all result sets before vacuuming
    unset($users, $userAllocs, $dbPath);
    try {
        $pdo->exec("VACUUM");
    } catch (Throwable $ve) {
        // VACUUM is optional maintenance; ignore if cursor is locked
    }

    $activeUserCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

    echo "\n=========================================================\n";
    echo "  [SUCCESS] DATA CLEANUP COMPLETED!                      \n";
    echo "=========================================================\n";
    echo "Total user accounts preserved in database: {$activeUserCount}\n";
    if ($activeUserCount > 0) {
        $userList = $pdo->query("SELECT id, name, email, role FROM users")->fetchAll();
        foreach ($userList as $u) {
            echo " - [ID {$u['id']}] {$u['role']}: {$u['name']} ({$u['email']})\n";
        }
        echo "\nAll test leave applications, overtime, punches, and notifications have been cleared.\n";
        echo "User balances have been restored to full statutory/allocated credits.\n";
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "\n[ERROR] Reset failed: " . $e->getMessage() . "\n";
    exit(1);
}
