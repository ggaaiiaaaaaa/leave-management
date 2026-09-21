<?php
// services/mailer.php - Email notification engine for JTYeo CPA Leave System

function sendLeaveNotification($pdo, $type, $data) {
    // $data includes: ref_no, employee_name, employee_email, leave_type_label, start_date, end_date, days_count, approver_name, rejection_reason

    $subject = '';
    $recipientEmail = '';
    $recipientName = '';
    $htmlBody = '';

    if ($type === 'leave_filed') {
        // Sent to Managing Partner / Admin
        $adminStmt = $pdo->query("SELECT email, name FROM users WHERE role = 'admin' LIMIT 1");
        $admin = $adminStmt->fetch();
        $recipientEmail = $admin['email'] ?? 'admin@jtyeocpa.ph';
        $recipientName = $admin['name'] ?? 'Atty. Jonathan Yeo, CPA';

        // Determine base URL dynamically
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
        $basePath = preg_replace('#/(actions|services)/.*#', '', $scriptPath);
        if (empty($basePath) || $basePath === '/') $basePath = '/leave-jtyeo';
        $baseUrl = $protocol . $host . rtrim($basePath, '/');

        $reviewLink = "{$baseUrl}/admin_dashboard.php?tab=approvals&ref=" . urlencode($data['ref_no']);

        $subject = "New Leave Application Filed: {$data['employee_name']} ({$data['leave_type_label']})";
        $htmlBody = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; background-color: #ffffff;'>
                <div style='border-bottom: 2px solid #0f172a; padding-bottom: 12px; margin-bottom: 16px;'>
                    <h2 style='color: #0f172a; margin: 0; font-size: 20px;'>JTYeo CPA Accounting Office</h2>
                    <p style='color: #64748b; margin: 4px 0 0 0; font-size: 13px;'>Leave Management System Notification</p>
                </div>
                <p style='color: #334155; font-size: 15px;'>Dear <strong>{$recipientName}</strong>,</p>
                <p style='color: #334155; font-size: 14px;'>An associate has submitted a new leave application awaiting your executive review:</p>
                <table style='width: 100%; border-collapse: collapse; margin: 16px 0;'>
                    <tr><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 13px; width: 140px;'>Reference No:</td><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; font-weight: bold; color: #0f172a;'>{$data['ref_no']}</td></tr>
                    <tr><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 13px;'>Associate Name:</td><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; font-weight: bold; color: #0f172a;'>{$data['employee_name']}</td></tr>
                    <tr><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 13px;'>Leave Category:</td><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; color: #0f172a;'>{$data['leave_type_label']}</td></tr>
                    <tr><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 13px;'>Inclusive Dates:</td><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; color: #0f172a;'>{$data['start_date']} to {$data['end_date']} ({$data['days_count']} Working Days)</td></tr>
                    <tr><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 13px;'>Reason:</td><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; color: #0f172a;'>{$data['reason']}</td></tr>
                </table>
                <div style='margin: 22px 0; text-align: center;'>
                    <a href='{$reviewLink}' style='display: inline-block; background-color: #dc0000; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 14px;'>
                        Review Application in Approvals Queue &rarr;
                    </a>
                </div>
                <p style='color: #64748b; font-size: 12px; margin-top: 20px; border-top: 1px solid #e2e8f0; padding-top: 12px;'>
                    You can also access this request directly from the Approvals Queue on your Executive Dashboard.
                </p>
            </div>
        ";
    } elseif ($type === 'leave_approved') {
        $recipientEmail = $data['employee_email'];
        $recipientName = $data['employee_name'];

        // Determine base URL dynamically
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
        $basePath = preg_replace('#/(actions|services)/.*#', '', $scriptPath);
        if (empty($basePath) || $basePath === '/') $basePath = '/leave-jtyeo';
        $baseUrl = $protocol . $host . rtrim($basePath, '/');
        $slipLink = "{$baseUrl}/staff_dashboard.php?tab=my-portal&print_ref=" . urlencode($data['ref_no']);

        $subject = "Leave Application Approved: {$data['leave_type_label']} ({$data['ref_no']})";
        $htmlBody = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; background-color: #ffffff;'>
                <div style='border-bottom: 2px solid #10b981; padding-bottom: 12px; margin-bottom: 16px;'>
                    <h2 style='color: #10b981; margin: 0; font-size: 20px;'>Leave Approved</h2>
                    <p style='color: #64748b; margin: 4px 0 0 0; font-size: 13px;'>JTYeo CPA Accounting Office</p>
                </div>
                <p style='color: #334155; font-size: 15px;'>Dear <strong>{$recipientName}</strong>,</p>
                <p style='color: #334155; font-size: 14px;'>Good news! Your leave application has been reviewed and <strong style='color: #10b981;'>APPROVED</strong> by <strong>{$data['approver_name']}</strong>.</p>
                <table style='width: 100%; border-collapse: collapse; margin: 16px 0;'>
                    <tr><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 13px; width: 140px;'>Reference No:</td><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; font-weight: bold; color: #0f172a;'>{$data['ref_no']}</td></tr>
                    <tr><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 13px;'>Category:</td><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; color: #0f172a;'>{$data['leave_type_label']}</td></tr>
                    <tr><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 13px;'>Dates:</td><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; color: #0f172a;'>{$data['start_date']} to {$data['end_date']} ({$data['days_count']} Working Days)</td></tr>
                    <tr><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 13px;'>Approved By:</td><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; font-weight: bold; color: #0f172a;'>{$data['approver_name']}</td></tr>
                </table>
                <div style='margin: 22px 0; text-align: center;'>
                    <a href='{$slipLink}' style='display: inline-block; background-color: #047857; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 14px;'>
                        Print Official Signed Leave Slip &rarr;
                    </a>
                </div>
                <p style='color: #64748b; font-size: 12px; margin-top: 20px; border-top: 1px solid #e2e8f0; padding-top: 12px;'>
                    Your leave balance and team calendar have been updated accordingly. Authorized leave hours (8.0 hrs/day) are automatically credited to your Form 48 DTR.
                </p>
            </div>
        ";
    } elseif ($type === 'leave_rejected') {
        $recipientEmail = $data['employee_email'];
        $recipientName = $data['employee_name'];

        $subject = "Leave Application Update: {$data['leave_type_label']} ({$data['ref_no']})";
        $htmlBody = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; background-color: #ffffff;'>
                <div style='border-bottom: 2px solid #ef4444; padding-bottom: 12px; margin-bottom: 16px;'>
                    <h2 style='color: #ef4444; margin: 0; font-size: 20px;'>Leave Application Not Approved</h2>
                    <p style='color: #64748b; margin: 4px 0 0 0; font-size: 13px;'>JTYeo CPA Accounting Office</p>
                </div>
                <p style='color: #334155; font-size: 15px;'>Dear <strong>{$recipientName}</strong>,</p>
                <p style='color: #334155; font-size: 14px;'>Your leave application for <strong>{$data['leave_type_label']}</strong> ({$data['start_date']} to {$data['end_date']}) could not be approved at this time.</p>
                <div style='background-color: #fef2f2; border-left: 4px solid #ef4444; padding: 12px; margin: 16px 0; border-radius: 4px;'>
                    <strong style='color: #991b1b; font-size: 13px;'>Managing Partner Note / Reason:</strong>
                    <p style='color: #7f1d1d; font-size: 14px; margin: 4px 0 0 0;'>{$data['rejection_reason']}</p>
                </div>
                <p style='color: #64748b; font-size: 12px; margin-top: 24px; border-top: 1px solid #e2e8f0; padding-top: 12px;'>
                    No leave days were deducted from your balance. Please coordinate directly with Atty. Jonathan Yeo if you have questions.
                </p>
            </div>
        ";
    } elseif ($type === 'email_changed') {
        // Sent to Managing Partner / Admin when an associate changes their email
        $adminStmt = $pdo->query("SELECT email, name FROM users WHERE role = 'admin' LIMIT 1");
        $admin = $adminStmt->fetch();
        $recipientEmail = $admin['email'] ?? 'admin@jtyeocpa.ph';
        $recipientName = $admin['name'] ?? 'Managing Partner';

        $subject = "Security Notice: Associate Email Changed ({$data['employee_name']})";
        $htmlBody = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; background-color: #ffffff;'>
                <div style='border-bottom: 2px solid #0f172a; padding-bottom: 12px; margin-bottom: 16px;'>
                    <h2 style='color: #0f172a; margin: 0; font-size: 20px;'>JTYeo CPA Accounting Office</h2>
                    <p style='color: #64748b; margin: 4px 0 0 0; font-size: 13px;'>Account Security &amp; Profile Notice</p>
                </div>
                <p style='color: #334155; font-size: 15px;'>Dear <strong>{$recipientName}</strong>,</p>
                <p style='color: #334155; font-size: 14px;'>This is to notify you that an associate has updated their official email address:</p>
                <table style='width: 100%; border-collapse: collapse; margin: 16px 0;'>
                    <tr><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 13px; width: 140px;'>Associate Name:</td><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; font-weight: bold; color: #0f172a;'>{$data['employee_name']}</td></tr>
                    <tr><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 13px;'>Previous Email:</td><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; color: #dc2626; font-family: monospace;'>{$data['old_email']}</td></tr>
                    <tr><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 13px;'>New Email:</td><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; color: #15803d; font-weight: bold; font-family: monospace;'>{$data['new_email']}</td></tr>
                    <tr><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 13px;'>Updated At:</td><td style='padding: 8px; border-bottom: 1px solid #f1f5f9; color: #0f172a;'>{$data['updated_at']}</td></tr>
                </table>
                <p style='color: #64748b; font-size: 12px; margin-top: 24px; border-top: 1px solid #e2e8f0; padding-top: 12px;'>
                    If this update was unexpected, you can review or modify their credentials from the Admin Associates tab.
                </p>
            </div>
        ";
    }

    // Record notification in database
    $stmt = $pdo->prepare("
        INSERT INTO email_notifications (recipient_email, recipient_name, subject, body, notification_type, status)
        VALUES (?, ?, ?, ?, ?, 'Sent')
    ");
    $stmt->execute([$recipientEmail, $recipientName, $subject, $htmlBody, $type]);

    // Attempt PHP mail() silently if configured
    @mail($recipientEmail, $subject, $htmlBody, "MIME-Version: 1.0\r\nContent-type:text/html;charset=UTF-8\r\nFrom: no-reply@jtyeocpa.ph");

    return true;
}
