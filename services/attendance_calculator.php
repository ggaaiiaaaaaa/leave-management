<?php
// services/attendance_calculator.php - Actual Rendered Hours & Attendance Engine

/**
 * Calculates rendered hours, break duration, and real-time punch status.
 * Note: No fixed 8:30-5:30 shift constraints are applied per firm policy.
 * Status is dynamically derived: Present, On Break, Completed, or Absent.
 * 
 * @param string|null $timeIn     HH:MM:SS or HH:MM format
 * @param string|null $breakOut   HH:MM:SS or HH:MM format
 * @param string|null $breakIn    HH:MM:SS or HH:MM format
 * @param string|null $timeOut    HH:MM:SS or HH:MM format
 * @param float $approvedOtHours  Approved overtime hours
 * @return array
 */
function calculateAttendanceMetrics($timeIn, $breakOut, $breakIn, $timeOut, $approvedOtHours = 0) {
    $metrics = [
        'rendered_hours' => 0.0,
        'rendered_formatted' => '0 hrs',
        'break_duration_mins' => 0,
        'break_formatted' => '0 mins',
        'status' => 'Absent',
        'overtime_hours' => floatval($approvedOtHours),
        'is_completed' => false
    ];

    // Clean inputs
    $timeIn = !empty($timeIn) ? trim($timeIn) : null;
    $breakOut = !empty($breakOut) ? trim($breakOut) : null;
    $breakIn = !empty($breakIn) ? trim($breakIn) : null;
    $timeOut = !empty($timeOut) ? trim($timeOut) : null;

    if (!$timeIn) {
        return $metrics;
    }

    // Determine current status
    if ($breakOut && !$breakIn && !$timeOut) {
        $metrics['status'] = 'On Break';
    } else {
        $metrics['status'] = 'Present';
    }

    $inTs = strtotime("2000-01-01 " . $timeIn);
    
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
