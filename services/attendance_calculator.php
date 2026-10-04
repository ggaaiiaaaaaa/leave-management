<?php
// services/attendance_calculator.php - Actual Rendered Hours & Attendance Engine

/**
 * Retrieves the current office schedule settings from system_settings or defaults.
 * 
 * @param PDO|null $pdo
 * @return array
 */
function getOfficeScheduleSettings($pdo = null) {
    static $cachedSettings = null;
    if ($cachedSettings !== null && $pdo === null) {
        return $cachedSettings;
    }

    $defaults = [
        'work_start_time' => '08:30:00',
        'work_end_time' => '17:30:00',
        'grace_period_mins' => 0,
        'break_start_time' => '12:00:00',
        'break_end_time' => '13:00:00',
        'required_daily_hours' => 8.0,
        'work_days' => 'Mon,Tue,Wed,Thu,Fri'
    ];

    if (!$pdo) {
        global $pdo;
    }

    if ($pdo) {
        try {
            $rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
            if (!empty($rows)) {
                foreach ($defaults as $k => $defVal) {
                    if (isset($rows[$k])) {
                        if ($k === 'grace_period_mins') {
                            $defaults[$k] = intval($rows[$k]);
                        } elseif ($k === 'required_daily_hours') {
                            $defaults[$k] = floatval($rows[$k]);
                        } else {
                            $defaults[$k] = trim($rows[$k]);
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // Fallback to defaults
        }
    }

    $defaults['work_start_12'] = formatTimeTo12Hour($defaults['work_start_time']);
    $defaults['work_end_12'] = formatTimeTo12Hour($defaults['work_end_time']);
    $defaults['break_start_12'] = formatTimeTo12Hour($defaults['break_start_time']);
    $defaults['break_end_12'] = formatTimeTo12Hour($defaults['break_end_time']);

    $cachedSettings = $defaults;
    return $defaults;
}

/**
 * Calculates rendered hours, break duration, and real-time punch status.
 * Evaluates tardiness and undertime against dynamic firm office schedule.
 * Status is dynamically derived: Present, On Break, Completed, or Absent.
 * 
 * @param string|null $timeIn     HH:MM:SS or HH:MM format
 * @param string|null $breakOut   HH:MM:SS or HH:MM format
 * @param string|null $breakIn    HH:MM:SS or HH:MM format
 * @param string|null $timeOut    HH:MM:SS or HH:MM format
 * @param float $approvedOtHours  Approved overtime hours
 * @param string|null $logDate    YYYY-MM-DD
 * @param array|null $schedule    Custom schedule settings array
 * @return array
 */
function calculateAttendanceMetrics($timeIn, $breakOut, $breakIn, $timeOut, $approvedOtHours = 0, $logDate = null, $schedule = null) {
    if ($schedule === null) {
        $schedule = getOfficeScheduleSettings();
    }

    $metrics = [
        'rendered_hours' => 0.0,
        'rendered_formatted' => '0 hrs',
        'break_duration_mins' => 0,
        'break_formatted' => '0 mins',
        'status' => 'Absent',
        'overtime_hours' => floatval($approvedOtHours),
        'is_completed' => false,
        'tardy_minutes' => 0,
        'is_tardy' => false,
        'tardy_formatted' => 'On Time',
        'undertime_minutes' => 0,
        'is_undertime' => false,
        'undertime_formatted' => 'Standard',
        'shift_start' => $schedule['work_start_12'] ?? '8:30 AM',
        'shift_end' => $schedule['work_end_12'] ?? '5:30 PM',
        'tardy_tooltip' => "Tardy: Arrival past " . ($schedule['work_start_12'] ?? '8:30 AM') . " official schedule",
        'undertime_tooltip' => "Undertime: Departure before " . ($schedule['work_end_12'] ?? '5:30 PM') . " official schedule",
        'is_incomplete' => false,
        'exception_type' => null,
        'exception_label' => null
    ];

    // Clean inputs
    $timeIn = !empty($timeIn) ? trim($timeIn) : null;
    $breakOut = !empty($breakOut) ? trim($breakOut) : null;
    $breakIn = !empty($breakIn) ? trim($breakIn) : null;
    $timeOut = !empty($timeOut) ? trim($timeOut) : null;

    if (!$timeIn) {
        return $metrics;
    }

    // Dynamic Firm Start Time & Grace Period
    $shiftStartTime = $schedule['work_start_time'] ?? '08:30:00';
    $graceMins = intval($schedule['grace_period_mins'] ?? 0);
    $shiftStartTs = strtotime("2000-01-01 " . $shiftStartTime);
    $graceLimitTs = $shiftStartTs + ($graceMins * 60);
    $inTs = strtotime("2000-01-01 " . $timeIn);

    if ($inTs > $graceLimitTs) {
        $tardyMins = round(($inTs - $shiftStartTs) / 60);
        $metrics['tardy_minutes'] = $tardyMins;
        $metrics['is_tardy'] = true;
        $metrics['tardy_formatted'] = ($tardyMins >= 60) 
            ? floor($tardyMins / 60) . 'h ' . ($tardyMins % 60) . 'm late' 
            : "{$tardyMins}m late";
    }

    // Determine current status
    if ($breakOut && !$breakIn && !$timeOut) {
        $metrics['status'] = 'On Break';
    } else {
        $metrics['status'] = 'Present';
    }
    
    // Calculate break duration if both punches exist
    $breakSeconds = 0;
    if ($breakOut && $breakIn) {
        $bOutTs = strtotime("2000-01-01 " . $breakOut);
        $bInTs = strtotime("2000-01-01 " . $breakIn);
        if ($bInTs > $bOutTs) {
            $breakSeconds = $bInTs - $bOutTs;
            $metrics['break_duration_mins'] = round($breakSeconds / 60);
            $bMins = $metrics['break_duration_mins'];
            $metrics['break_formatted'] = ($bMins >= 60) 
                ? floor($bMins / 60) . ' hr ' . ($bMins % 60) . ' mins' 
                : $bMins . ' mins';
        }
    }

    // If associate timed out
    if ($timeOut) {
        $outTs = strtotime("2000-01-01 " . $timeOut);
        if ($outTs > $inTs) {
            $totalGrossSeconds = $outTs - $inTs;
            $netSeconds = max(0, $totalGrossSeconds - $breakSeconds);
            $hours = round($netSeconds / 3600, 2);
            $metrics['rendered_hours'] = $hours;
            $metrics['rendered_formatted'] = formatHoursToReadable($hours);
            $metrics['is_completed'] = true;

            // Check undertime against official work_end_time
            $shiftEndTime = $schedule['work_end_time'] ?? '17:30:00';
            $shiftEndTs = strtotime("2000-01-01 " . $shiftEndTime);
            if ($outTs < $shiftEndTs) {
                $undertimeMins = round(($shiftEndTs - $outTs) / 60);
                if ($undertimeMins > 0) {
                    $metrics['undertime_minutes'] = $undertimeMins;
                    $metrics['is_undertime'] = true;
                    $metrics['undertime_formatted'] = ($undertimeMins >= 60) 
                        ? floor($undertimeMins / 60) . 'h ' . ($undertimeMins % 60) . 'm early' 
                        : "{$undertimeMins}m early";
                }
            }
        }
    } else {
        // Check for Missing Out exception on past days or late in the evening
        $todayStr = date('Y-m-d');
        $isPastDay = !empty($logDate) && ($logDate < $todayStr);
        $isLateToday = (!empty($logDate) && $logDate === $todayStr && intval(date('H')) >= 19);

        if ($isPastDay || $isLateToday) {
            $metrics['is_incomplete'] = true;
            $metrics['exception_type'] = 'missing_out';
            $metrics['exception_label'] = 'Missing Out';
        }
    }

    // Also check for incomplete break on past days
    if ($breakOut && !$breakIn) {
        $todayStr = date('Y-m-d');
        $isPastDay = !empty($logDate) && ($logDate < $todayStr);
        if ($isPastDay) {
            $metrics['is_incomplete'] = true;
            $metrics['exception_type'] = 'missing_break_in';
            $metrics['exception_label'] = 'Missing Break In';
        }
    }

    return $metrics;
}

/**
 * Format decimal hours to friendly human readable string
 * e.g. 8.25 -> "8 hrs 15 mins", 8.0 -> "8 hrs", 0.5 -> "30 mins"
 */
function formatHoursToReadable($hours) {
    if ($hours <= 0) return '0 hrs';
    $totalMinutes = round($hours * 60);
    $hrs = floor($totalMinutes / 60);
    $mins = $totalMinutes % 60;

    if ($hrs > 0 && $mins > 0) {
        return "{$hrs} hrs {$mins} mins";
    } elseif ($hrs > 0) {
        return "{$hrs} hrs";
    } else {
        return "{$mins} mins";
    }
}

/**
 * Converts 24-hour time or timestamp to 12-hour AM/PM format
 * e.g. "13:45:00" -> "1:45 PM", "08:30" -> "8:30 AM"
 */
function formatTimeTo12Hour($timeStr) {
    if (empty($timeStr) || $timeStr === '--:--' || $timeStr === '-') {
        return '-';
    }
    $ts = strtotime("2000-01-01 " . $timeStr);
    return $ts ? date('g:i A', $ts) : $timeStr;
}
