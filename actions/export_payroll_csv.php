<?php
// actions/export_payroll_csv.php - Server-side DOLE & Payroll CSV Export
require_once __DIR__ . '/../auth.php';
requireLogin();

$stmt = $pdo->query("
    SELECT r.ref_no, u.name as employee_name, u.department, r.leave_type_label, r.days_count, r.start_date, r.end_date, r.status, r.approver_name, r.created_at
    FROM leave_requests r
    JOIN users u ON r.user_id = u.id
    ORDER BY r.created_at DESC
");
$rows = $stmt->fetchAll();

$filename = 'JTYeo_CPA_Accounting_Office_Leave_Ledger_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');

// Header row
fputcsv($output, ['Reference ID', 'Employee Name', 'Department', 'Leave Category', 'Working Days', 'Start Date', 'End Date', 'Status', 'Approved By / Signoff', 'Filed At']);

foreach ($rows as $row) {
    fputcsv($output, [
        $row['ref_no'],
        $row['employee_name'],
        $row['department'],
        $row['leave_type_label'],
        $row['days_count'],
        $row['start_date'],
        $row['end_date'],
        $row['status'],
        $row['approver_name'] ?? 'Pending',
        $row['created_at']
    ]);
}

fclose($output);
exit;
