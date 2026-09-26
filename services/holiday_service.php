<?php
// services/holiday_service.php - Philippine Holiday calculation and seeding engine

/**
 * Returns standard Philippine holidays for a given year.
 * Computes movable Holy Week holidays (Maundy Thursday, Good Friday, Black Saturday)
 * and the last Monday of August for National Heroes Day.
 */
function getStandardPhilippineHolidays(int $year): array {
    // Holy week calculation (Easter Sunday minus 3 days = Maundy Thursday, minus 2 days = Good Friday)
    $easterDays = easter_days($year);
    $easterDate = new DateTime("{$year}-03-21");
    $easterDate->modify("+{$easterDays} days");

    $maundyThursday = (clone $easterDate)->modify('-3 days')->format('Y-m-d');
    $goodFriday = (clone $easterDate)->modify('-2 days')->format('Y-m-d');
    $blackSaturday = (clone $easterDate)->modify('-1 day')->format('Y-m-d');

    // National Heroes Day: Last Monday of August
    $lastMonAug = date('Y-m-d', strtotime("last monday of august {$year}"));

    return [
        ['title' => "New Year's Day", 'holiday_date' => "{$year}-01-01", 'holiday_type' => 'Regular', 'description' => 'National Regular Holiday'],
        ['title' => 'EDSA People Power Revolution', 'holiday_date' => "{$year}-02-25", 'holiday_type' => 'Special', 'description' => 'Special Non-Working Day'],
        ['title' => 'Maundy Thursday', 'holiday_date' => $maundyThursday, 'holiday_type' => 'Regular', 'description' => 'Holy Week Observance'],
        ['title' => 'Good Friday', 'holiday_date' => $goodFriday, 'holiday_type' => 'Regular', 'description' => 'Holy Week Observance'],
        ['title' => 'Black Saturday', 'holiday_date' => $blackSaturday, 'holiday_type' => 'Special', 'description' => 'Special Non-Working Day'],
        ['title' => 'Araw ng Kagitingan', 'holiday_date' => "{$year}-04-09", 'holiday_type' => 'Regular', 'description' => 'Day of Valor'],
        ['title' => 'Labor Day', 'holiday_date' => "{$year}-05-01", 'holiday_type' => 'Regular', 'description' => 'Labor Day'],
        ['title' => 'Independence Day', 'holiday_date' => "{$year}-06-12", 'holiday_type' => 'Regular', 'description' => 'Araw ng Kalayaan'],
        ['title' => 'Ninoy Aquino Day', 'holiday_date' => "{$year}-08-21", 'holiday_type' => 'Special', 'description' => 'Special Non-Working Day'],
        ['title' => 'National Heroes Day', 'holiday_date' => $lastMonAug, 'holiday_type' => 'Regular', 'description' => 'National Regular Holiday (Last Monday of August)'],
        ['title' => "All Saints' Day", 'holiday_date' => "{$year}-11-01", 'holiday_type' => 'Special', 'description' => 'Special Non-Working Day'],
        ['title' => "All Souls' Day", 'holiday_date' => "{$year}-11-02", 'holiday_type' => 'Special', 'description' => 'Special Non-Working Day'],
        ['title' => 'Bonifacio Day', 'holiday_date' => "{$year}-11-30", 'holiday_type' => 'Regular', 'description' => 'National Regular Holiday'],
        ['title' => 'Feast of the Immaculate Conception', 'holiday_date' => "{$year}-12-08", 'holiday_type' => 'Special', 'description' => 'Special Non-Working Day'],
        ['title' => 'Christmas Day', 'holiday_date' => "{$year}-12-25", 'holiday_type' => 'Regular', 'description' => 'National Regular Holiday'],
        ['title' => 'Rizal Day', 'holiday_date' => "{$year}-12-30", 'holiday_type' => 'Regular', 'description' => 'National Regular Holiday'],
        ['title' => 'Last Day of the Year', 'holiday_date' => "{$year}-12-31", 'holiday_type' => 'Special', 'description' => 'Special Non-Working Day']
    ];
}

/**
 * Seeds standard holidays for a specified year into the SQLite database.
 * Returns count of newly inserted holidays.
 */
function seedPhilippineHolidays(PDO $pdo, int $year): int {
    $standardHolidays = getStandardPhilippineHolidays($year);
    
    $existingDates = $pdo->prepare("SELECT holiday_date FROM holidays WHERE strftime('%Y', holiday_date) = ?");
    $existingDates->execute([(string)$year]);
    $existingMap = array_flip($existingDates->fetchAll(PDO::FETCH_COLUMN));

    $ins = $pdo->prepare("
        INSERT INTO holidays (title, holiday_date, holiday_type, description)
        VALUES (?, ?, ?, ?)
    ");

    $added = 0;
    foreach ($standardHolidays as $sh) {
        if (!isset($existingMap[$sh['holiday_date']])) {
            $ins->execute([$sh['title'], $sh['holiday_date'], $sh['holiday_type'], $sh['description']]);
            $added++;
        }
    }
    return $added;
}
