<?php
// actions/manage_birthdays.php - Add, list and delete birthdays for the firm calendar
require_once __DIR__ . '/../auth.php';
$viewer = requireLogin();
$isAdmin = ($viewer['role'] === 'admin');

header('Content-Type: application/json');

$action = $_POST['action'] ?? ($_GET['action'] ?? 'get_birthdays');

if ($action !== 'get_birthdays') {
    requirePostWithCsrf();
    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized: Only administrators can add or manage firm birthdays.']);
        exit;
    }
}

try {
    switch ($action) {
        // 1. GET ALL BIRTHDAYS
        case 'get_birthdays':
            $stmt = $pdo->query("
                SELECT b.id, b.user_id, b.person_name, b.birth_date, b.notes, b.created_by, b.created_at,
                       u.name as linked_name, u.title as linked_title, u.avatar_path, u.avatar_initials,
                       creator.name as creator_name
                FROM birthdays b
                LEFT JOIN users u ON b.user_id = u.id
                LEFT JOIN users creator ON b.created_by = creator.id
                ORDER BY strftime('%m-%d', b.birth_date) ASC, b.person_name ASC
            ");
            $birthdays = $stmt->fetchAll();

            foreach ($birthdays as &$b) {
                $ts = strtotime($b['birth_date']);
                $b['month_day'] = date('F j', $ts);
                $b['day_number'] = (int)date('d', $ts);
                $b['month_number'] = (int)date('m', $ts);
                $b['can_delete'] = $isAdmin || ((int)$b['created_by'] === (int)$viewer['id']) || ((int)$b['user_id'] === (int)$viewer['id']);
            }

            echo json_encode([
                'success' => true,
                'birthdays' => $birthdays
            ]);
            exit;

        // 2. ADD BIRTHDAY TO CALENDAR
        case 'add_birthday':
            $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
            $personName = trim($_POST['person_name'] ?? '');
            $birthDate = trim($_POST['birth_date'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            if ($userId > 0) {
                $uStmt = $pdo->prepare("SELECT id, name, title, department FROM users WHERE id = ?");
                $uStmt->execute([$userId]);
                $linkedUser = $uStmt->fetch();
                if ($linkedUser) {
                    if (empty($personName)) {
                        $personName = $linkedUser['name'];
                    }
                    if (empty($notes) && !empty($linkedUser['title'])) {
                        $notes = $linkedUser['title'];
                    }
                } else {
                    $userId = 0;
                }
            }

            if (empty($personName)) {
                echo json_encode(['success' => false, 'message' => 'Please provide a person or colleague name.']);
                exit;
            }

            if (empty($birthDate)) {
                echo json_encode(['success' => false, 'message' => 'Please select a birth date.']);
                exit;
            }

            // Validate date format (YYYY-MM-DD)
            $dt = DateTime::createFromFormat('!Y-m-d', $birthDate);
            if (!$dt || $dt->format('Y-m-d') !== $birthDate) {
                echo json_encode(['success' => false, 'message' => 'Invalid date format. Expected YYYY-MM-DD.']);
                exit;
            }

            // Check if duplicate on the exact same month & day for this person
            $chk = $pdo->prepare("
                SELECT id FROM birthdays 
                WHERE LOWER(person_name) = LOWER(?) AND strftime('%m-%d', birth_date) = strftime('%m-%d', ?)
            ");
            $chk->execute([$personName, $birthDate]);
            if ($chk->fetch()) {
                echo json_encode(['success' => false, 'message' => "A birthday for {$personName} is already on this calendar date."]);
                exit;
            }

            $insStmt = $pdo->prepare("
                INSERT INTO birthdays (user_id, person_name, birth_date, notes, created_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $insStmt->execute([
                $userId > 0 ? $userId : null,
                $personName,
                $birthDate,
                $notes,
                $viewer['id']
            ]);
            $newId = $pdo->lastInsertId();

            // If linked to an associate, also sync user's birth_date
            if ($userId > 0) {
                $pdo->prepare("UPDATE users SET birth_date = ? WHERE id = ?")->execute([$birthDate, $userId]);
            }

            echo json_encode([
                'success' => true,
                'message' => "Birthday for {$personName} added to the calendar successfully!",
                'birthday_id' => $newId
            ]);
            exit;

        // 3. DELETE BIRTHDAY
        case 'delete_birthday':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid birthday ID.']);
                exit;
            }

            $bStmt = $pdo->prepare("SELECT * FROM birthdays WHERE id = ?");
            $bStmt->execute([$id]);
            $bday = $bStmt->fetch();

            if (!$bday) {
                echo json_encode(['success' => false, 'message' => 'Birthday entry not found or already deleted.']);
                exit;
            }

            $canDelete = $isAdmin || ((int)$bday['created_by'] === (int)$viewer['id']) || ((int)$bday['user_id'] === (int)$viewer['id']);
            if (!$canDelete) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Unauthorized: You do not have permission to delete this birthday.']);
                exit;
            }

            $delStmt = $pdo->prepare("DELETE FROM birthdays WHERE id = ?");
            $delStmt->execute([$id]);

            // If linked to a user and no other birthday exists for that user, clear users.birth_date
            if (!empty($bday['user_id'])) {
                $remCount = $pdo->prepare("SELECT COUNT(*) FROM birthdays WHERE user_id = ?");
                $remCount->execute([$bday['user_id']]);
                if ((int)$remCount->fetchColumn() === 0) {
                    $pdo->prepare("UPDATE users SET birth_date = NULL WHERE id = ?")->execute([$bday['user_id']]);
                }
            }

            echo json_encode([
                'success' => true,
                'message' => "Birthday for \"{$bday['person_name']}\" was removed from the calendar."
            ]);
            exit;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
            exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
}
