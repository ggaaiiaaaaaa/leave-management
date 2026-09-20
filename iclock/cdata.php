<?php
// iclock/cdata.php - Standard ZKTeco ADMS Cloud / LAN Push Receiver
require_once __DIR__ . '/../leave-jtyeo/config/db.php';

// Log incoming request for diagnostics
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '';
$rawBody = file_get_contents('php://input');
@file_put_contents(__DIR__ . '/adms_debug.log', date('Y-m-d H:i:s') . " [{$clientIp}] {$method} {$uri} | BODY: " . substr($rawBody, 0, 200) . "\n", FILE_APPEND);

$sn = $_GET['SN'] ?? ($_GET['sn'] ?? 'UNKNOWN');
$table = $_GET['table'] ?? '';

// Automatically record live heartbeat for zero-click status detection
if ($clientIp !== 'unknown' && $clientIp !== '127.0.0.1' && $clientIp !== '::1') {
    $statusFile = __DIR__ . '/../leave-jtyeo/database/zkteco_status.json';
    @file_put_contents($statusFile, json_encode([
        'online' => true,
        'last_seen' => time(),
        'last_seen_formatted' => date('Y-m-d H:i:s'),
        'ip' => $clientIp,
        'sn' => $sn
    ]));
}

if (strpos($uri, 'getrequest') !== false) {
    header('Content-Type: text/plain');
    $cmdFile = __DIR__ . '/pending_cmd.txt';
    if (file_exists($cmdFile) && filesize($cmdFile) > 0) {
        $cmd = trim(file_get_contents($cmdFile));
        @unlink($cmdFile);
        echo $cmd . "\n";
        exit;
    }
    echo "OK\n";
    exit;
}

if (strpos($uri, 'devicecmd') !== false) {
    header('Content-Type: text/plain');
    echo "OK\n";
    exit;
}

if ($method === 'GET') {
    // Initial handshake / configuration request from ZKTeco device
    header('Content-Type: text/plain');
    echo "GET OPTION FROM: {$sn}\n";
    echo "Stamp=0\n";
    echo "OpStamp=0\n";
    echo "PhotoStamp=0\n";
    echo "ErrorDelay=30\n";
    echo "Delay=10\n";
    echo "TransTimes=00:00;14:05\n";
    echo "TransInterval=1\n";
    echo "TransFlag=1111111111\n";
    echo "TimeZone=8\n";
    echo "Realtime=1\n";
    echo "Encrypt=0\n";
    exit;
}

if ($method === 'POST') {
    $rawPost = file_get_contents('php://input');
    $lines = explode("\n", trim($rawPost));
    $processedCount = 0;

    // 1. Handle Operation Log (OPERLOG) - User / Face / Fingerprint Enrollments
    if ($table === 'OPERLOG' || strpos($rawPost, 'OPLOG') !== false) {
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 6 && $parts[0] === 'OPLOG') {
                $opCode = intval($parts[1]); // 103 = Enroll Face, 101/102 = Fingerprint
                $pin = trim($parts[5]);
                $uStmt = $pdo->prepare("SELECT id FROM users WHERE biometric_pin = ? OR id = ?");
                $uStmt->execute([$pin, $pin]);
                $targetUser = $uStmt->fetch();
                if ($targetUser) {
                    if ($opCode == 103) {
                        $pdo->prepare("UPDATE users SET face_enrolled = 1 WHERE id = ?")->execute([$targetUser['id']]);
                    } elseif ($opCode == 101 || $opCode == 102) {
                        $pdo->prepare("UPDATE users SET fingerprint_enrolled = 1 WHERE id = ?")->execute([$targetUser['id']]);
                    }
                }
            }
        }
        header('Content-Type: text/plain');
        echo "OK\n";
        exit;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        // ZKTeco ATTLOG format: PIN \t Timestamp \t Status \t VerifyMethod
        // Example: 101 \t 2026-09-20 22:45:00 \t 0 \t 15
        $parts = preg_split('/\s+/', $line);
        if (count($parts) >= 2) {
            $pin = trim($parts[0]);
            $punchDate = trim($parts[1]);
            $punchTime = trim($parts[2] ?? date('H:i:s'));
            $fullDatetime = "{$punchDate} {$punchTime}";
            
            // Verification Method mapping: 15 = Face, 1 = Fingerprint, 0 or 3 = PIN/Card
            $verifyCode = intval($parts[4] ?? ($parts[3] ?? 15));
            $methodLabel = 'PIN / Card';
            if ($verifyCode == 15 || $verifyCode == 20) {
                $methodLabel = 'Face Scan';
            } elseif ($verifyCode == 1) {
                $methodLabel = 'Fingerprint';
            }

            // Find user in database by biometric_pin or id
            $uStmt = $pdo->prepare("SELECT id, name, face_enrolled, fingerprint_enrolled FROM users WHERE biometric_pin = ? OR id = ?");
            $uStmt->execute([$pin, $pin]);
            $targetUser = $uStmt->fetch();

            if ($targetUser) {
                $userId = $targetUser['id'];

                // Auto-Enrollment trigger
                if ($methodLabel === 'Face Scan' && !$targetUser['face_enrolled']) {
                    $pdo->prepare("UPDATE users SET face_enrolled = 1 WHERE id = ?")->execute([$userId]);
                } elseif ($methodLabel === 'Fingerprint' && !$targetUser['fingerprint_enrolled']) {
                    $pdo->prepare("UPDATE users SET fingerprint_enrolled = 1 WHERE id = ?")->execute([$userId]);
                }

                // Check existing log for today
                $chk = $pdo->prepare("SELECT id, time_in, break_out, break_in, time_out FROM biometric_logs WHERE user_id = ? AND log_date = ?");
                $chk->execute([$userId, $punchDate]);
                $existing = $chk->fetch();

                $timeFormatted = date('H:i:s', strtotime($fullDatetime));
                $lateThreshold = strtotime('08:45:00');
                $punchTimestamp = strtotime($timeFormatted);
                $punchStatus = ($punchTimestamp > $lateThreshold) ? 'Late' : 'On-Time';

                $stateCode = intval($parts[3] ?? 255); // 0: Check In, 1: Check Out, 2: Break Out, 3: Break In

                if (!$existing) {
                    // First punch of the day
                    $targetCol = 'time_in';
                    if ($stateCode === 2) $targetCol = 'break_out';
                    elseif ($stateCode === 3) $targetCol = 'break_in';
                    elseif ($stateCode === 1) $targetCol = 'time_out';

                    $ins = $pdo->prepare("
                        INSERT INTO biometric_logs (user_id, biometric_pin, log_date, {$targetCol}, verification_method, status, device_model)
                        VALUES (?, ?, ?, ?, ?, ?, 'ZKTeco MB460 Plus')
                    ");
                    $ins->execute([$userId, $pin, $punchDate, $timeFormatted, $methodLabel, $punchStatus]);
                } else {
                    // Determine which slot to fill
                    $targetCol = 'time_out';
                    if ($stateCode === 0) {
                        $targetCol = 'time_in';
                    } elseif ($stateCode === 2) {
                        $targetCol = 'break_out';
                    } elseif ($stateCode === 3) {
                        $targetCol = 'break_in';
                    } elseif ($stateCode === 1) {
                        $targetCol = 'time_out';
                    } else {
                        // Smart automated progression if state key wasn't explicitly pressed on device:
                        // 1. If break_out is empty and punch is around lunch hours (11:00 AM - 2:00 PM) -> break_out
                        // 2. If break_out exists but break_in is empty and punch <= 3:30 PM -> break_in
                        // 3. Otherwise -> time_out (updated to latest departure)
                        $punchHour = (int)date('H', $punchTimestamp);
                        $punchMin = (int)date('i', $punchTimestamp);
                        $totalMinutes = $punchHour * 60 + $punchMin;

                        if (empty($existing['break_out']) && $totalMinutes >= 660 && $totalMinutes <= 840) {
                            $targetCol = 'break_out';
                        } elseif (!empty($existing['break_out']) && empty($existing['break_in']) && $totalMinutes <= 930) {
                            $targetCol = 'break_in';
                        } else {
                            $targetCol = 'time_out';
                        }
                    }

                    $up = $pdo->prepare("
                        UPDATE biometric_logs 
                        SET {$targetCol} = ?, verification_method = ?
                        WHERE id = ?
                    ");
                    $up->execute([$timeFormatted, $methodLabel, $existing['id']]);
                }
                $processedCount++;
            }
        }
    }

    header('Content-Type: text/plain');
    echo "OK: {$processedCount}\n";
    exit;
}

echo "OK\n";
