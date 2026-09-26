<?php
// actions/manage_holidays.php - Manage official Philippine holidays & auto-seeding
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../services/holiday_service.php';

requireLogin();

header('Content-Type: application/json');

$isAdmin = hasRole('admin');
$action = $_POST['action'] ?? ($_GET['action'] ?? 'get_holidays');

if ($action !== 'get_holidays') {
    requirePostWithCsrf();
    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Admin access required.']);
        exit;
    }
}

try {
    switch ($action) {
        // 1. GET HOLIDAYS
        case 'get_holidays':
            $year = isset($_GET['year']) && is_numeric($_GET['year']) ? (int)$_GET['year'] : 0;
            if ($year > 0) {
                $stmt = $pdo->prepare("SELECT * FROM holidays WHERE strftime('%Y', holiday_date) = ? ORDER BY holiday_date ASC");
                $stmt->execute([(string)$year]);
            } else {
                $stmt = $pdo->query("SELECT * FROM holidays ORDER BY holiday_date ASC");
            }
            $holidays = $stmt->fetchAll();

            // Format dates
            foreach ($holidays as &$h) {
                $ts = strtotime($h['holiday_date']);
                $h['formatted_date'] = date('M j, Y', $ts);
                $h['day_of_week'] = date('l', $ts);
                $h['year'] = date('Y', $ts);
            }

            // Get list of distinct years in database
            $years = $pdo->query("SELECT DISTINCT strftime('%Y', holiday_date) as yr FROM holidays WHERE holiday_date IS NOT NULL ORDER BY yr ASC")->fetchAll(PDO::FETCH_COLUMN);

            echo json_encode([
                'success' => true,
                'holidays' => $holidays,
                'years' => array_values(array_filter($years))
            ]);
            exit;

        // 2. ADD HOLIDAY
        case 'add_holiday':
            $title = trim($_POST['title'] ?? '');
            $holidayDate = trim($_POST['holiday_date'] ?? '');
            $holidayType = trim($_POST['holiday_type'] ?? 'Regular');
            $description = trim($_POST['description'] ?? '');

            if (empty($title) || empty($holidayDate)) {
                echo json_encode(['success' => false, 'message' => 'Please provide a holiday title and date.']);
                exit;
            }

            $dt = DateTime::createFromFormat('!Y-m-d', $holidayDate);
            if (!$dt || $dt->format('Y-m-d') !== $holidayDate) {
                echo json_encode(['success' => false, 'message' => 'Invalid date format. Expected YYYY-MM-DD.']);
                exit;
            }

            if (!in_array($holidayType, ['Regular', 'Special'], true)) {
                $holidayType = 'Regular';
            }

            // Check if holiday already exists on this date
            $chk = $pdo->prepare("SELECT id, title FROM holidays WHERE holiday_date = ?");
            $chk->execute([$holidayDate]);
            $existing = $chk->fetch();
            if ($existing) {
                echo json_encode(['success' => false, 'message' => "A holiday ('{$existing['title']}') already exists on {$holidayDate}."]);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO holidays (title, holiday_date, holiday_type, description) VALUES (?, ?, ?, ?)");
            $stmt->execute([$title, $holidayDate, $holidayType, $description]);

            echo json_encode([
                'success' => true,
                'message' => "Holiday '{$title}' added successfully for {$holidayDate}."
            ]);
            exit;

        // 3. DELETE HOLIDAY
        case 'delete_holiday':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid holiday ID.']);
                exit;
            }

            $delStmt = $pdo->prepare("DELETE FROM holidays WHERE id = ?");
            $delStmt->execute([$id]);

            echo json_encode([
                'success' => true,
                'message' => 'Holiday removed successfully.'
            ]);
            exit;

        // 4. AUTO-SEED SPECIFIC YEAR
        case 'seed_year':
            $targetYear = (int)($_POST['year'] ?? date('Y'));
            if ($targetYear < 2024 || $targetYear > 2040) {
                echo json_encode(['success' => false, 'message' => 'Year must be between 2024 and 2040.']);
                exit;
            }

            $added = seedPhilippineHolidays($pdo, $targetYear);

            echo json_encode([
                'success' => true,
                'message' => "Populated {$added} Philippine holidays for {$targetYear}." . ($added === 0 ? " (All standard holidays already exist)." : ""),
                'added_count' => $added
            ]);
            exit;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
            exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
}
