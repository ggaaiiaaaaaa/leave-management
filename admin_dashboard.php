<?php
// admin_dashboard.php - Managing Partner & HR Executive Portal (JTYeo CPA Accounting Office)
require_once __DIR__ . '/auth.php';
$user = requireLogin();

if (!hasRole('admin')) {
    header('Location: staff_dashboard.php');
    exit;
}

// Fetch user's personal leave balances
$stmt = $pdo->prepare("SELECT * FROM leave_balances WHERE user_id = ?");
$stmt->execute([$user['id']]);
$balances = $stmt->fetch() ?: [];

$vlBalance = (float)($balances['vl_balance'] ?? 12.0);
$slBalance = (float)($balances['sl_balance'] ?? 10.0);
$emBalance = (float)($balances['emergency_balance'] ?? 5.0);

// Fetch Pending Approvals
$approvalsStmt = $pdo->query("
    SELECT r.*, u.name as employee_name, u.title, u.avatar_path, u.avatar_initials, u.gender
    FROM leave_requests r
    JOIN users u ON r.user_id = u.id
    WHERE r.status = 'Pending'
    ORDER BY r.created_at ASC
");
$pendingApprovals = $approvalsStmt->fetchAll();
$pendingCount = count($pendingApprovals);

// Fetch All Leave Requests for Master Ledger
$leaveReqStmt = $pdo->query("
    SELECT r.*, u.name as employee_name, u.title, u.avatar_path, u.avatar_initials
    FROM leave_requests r
    JOIN users u ON r.user_id = u.id
    ORDER BY r.created_at DESC
");
$leaveRequests = $leaveReqStmt->fetchAll();

// Fetch Active Approved Leaves for Today
$today = date('Y-m-d');
$activeLeavesStmt = $pdo->prepare("
    SELECT r.*, u.name as employee_name, u.title, u.avatar_path, u.avatar_initials
    FROM leave_requests r
    JOIN users u ON r.user_id = u.id
    WHERE r.status = 'Approved' AND ? BETWEEN r.start_date AND r.end_date
    ORDER BY r.start_date ASC
");
$activeLeavesStmt->execute([$today]);
$activeLeaves = $activeLeavesStmt->fetchAll();

// Fetch all staff roster
$allUsersStmt = $pdo->query("
    SELECT u.*, 
           COALESCE(b.vl_balance, 12.0) as vl_balance,
           COALESCE(b.sl_balance, 10.0) as sl_balance,
           COALESCE(b.emergency_balance, 5.0) as emergency_balance,
           COALESCE(b.bereavement_balance, 3.0) as bereavement_balance,
           COALESCE(b.solo_parent_balance, 7.0) as solo_parent_balance,
           COALESCE(b.maternity_balance, 105.0) as maternity_balance,
           COALESCE(b.paternity_balance, 7.0) as paternity_balance,
           COALESCE(b.special_women_balance, 60.0) as special_women_balance
    FROM users u
    LEFT JOIN leave_balances b ON u.id = b.user_id
    ORDER BY u.id ASC
");
$allUsers = $allUsersStmt->fetchAll();

// Fetch Today's Biometric Attendance Logs for Morning Widget (Distinct Associates)
$todayBioStmt = $pdo->prepare("SELECT DISTINCT user_id FROM biometric_logs WHERE log_date = ? AND time_in IS NOT NULL");
$todayBioStmt->execute([$today]);
$punchedUserIds = $todayBioStmt->fetchAll(PDO::FETCH_COLUMN);

// Exclude any associate who is currently on approved leave
$activeLeaveUserIds = array_column($activeLeaves, 'user_id');
$presentUserIds = array_diff($punchedUserIds, $activeLeaveUserIds);

$totalStaff = count($allUsers);
$presentCount = min(count($presentUserIds), $totalStaff);
$onLeaveCount = count($activeLeaves);
$expectedCount = max(0, $totalStaff - $presentCount - $onLeaveCount);

// Upcoming Philippine Holidays
$holidaysStmt = $pdo->query("SELECT * FROM holidays WHERE holiday_date >= date('now') ORDER BY holiday_date ASC LIMIT 5");
$upcomingHolidays = $holidaysStmt->fetchAll();

// Fetch All Leave Types & Allocations for Policy Management
$allLeaveTypes = $pdo->query("SELECT * FROM leave_types ORDER BY is_active DESC, id ASC")->fetchAll();
$activeLeaveTypes = array_values(array_filter($allLeaveTypes, fn($t) => $t['is_active'] == 1));

$allocsRaw = $pdo->query("SELECT user_id, leave_type_code, allocated_days, remaining_days FROM user_leave_allocations")->fetchAll();
$userAllocMap = [];
foreach ($allocsRaw as $ar) {
    $userAllocMap[$ar['user_id']][$ar['leave_type_code']] = (float)$ar['remaining_days'];
}

// Live ZKTeco Hardware Status
$zkStatusFile = __DIR__ . '/database/zkteco_status.json';
$zkIsOnline = false;
$zkDeviceIp = 'Auto-detecting...';
$zkDeviceSn = 'MB460-Plus';
$zkLastSeen = date('g:i A');
if (file_exists($zkStatusFile)) {
    $zkData = json_decode(file_get_contents($zkStatusFile), true) ?: [];
    if (!empty($zkData['ip']) && $zkData['ip'] !== 'unknown') {
        $zkDeviceIp = $zkData['ip'];
    }
    if (!empty($zkData['sn']) && $zkData['sn'] !== 'UNKNOWN') {
        $zkDeviceSn = $zkData['sn'];
    }
    if (!empty($zkData['last_seen_formatted'])) {
        $zkLastSeen = $zkData['last_seen_formatted'];
    }
    if (!empty($zkData['last_seen']) && (time() - $zkData['last_seen']) < 60) {
        $zkIsOnline = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Managing Partner &amp; HR Portal | JTYeo CPA Accounting Office</title>
  <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
  <script src="lucide.js"></script>
  <!-- FullCalendar 5 -->
  <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
  <style>
    .brand-logo-frame { width: 44px !important; height: 44px !important; min-width: 44px !important; max-width: 44px !important; background: #ffffff !important; border-radius: 10px !important; display: flex !important; align-items: center !important; justify-content: center !important; padding: 4px !important; overflow: hidden !important; box-shadow: 0 4px 12px rgba(0,0,0,0.25) !important; flex-shrink: 0 !important; }
    .brand-logo-img { width: 36px !important; height: 36px !important; max-width: 36px !important; max-height: 36px !important; object-fit: contain !important; display: block !important; }
    #leaveCalendar { background: #fff; border-radius: var(--radius-md); padding: 16px; font-family: inherit; }
    .fc .fc-toolbar-title { font-size: 1.15rem !important; font-weight: 800 !important; color: var(--primary) !important; }
    .fc .fc-button-primary { background: var(--primary) !important; border-color: var(--primary) !important; font-size: 12px !important; font-weight: 600 !important; padding: 6px 12px !important; border-radius: var(--radius-sm) !important; }
    .fc .fc-button-primary:hover { background: var(--primary-light) !important; }
    .fc .fc-button-active { background: var(--accent) !important; border-color: var(--accent) !important; }
    .fc .fc-daygrid-day-number { font-weight: 600; color: var(--text-main); font-size: 12px; padding: 4px 6px; }
    .fc-event { border-radius: 4px !important; padding: 3px 6px !important; font-size: 11px !important; font-weight: 600 !important; cursor: pointer; }
    .calendar-legend-bar { display: flex; flex-wrap: wrap; gap: 14px; margin-top: 16px; padding: 12px 16px; background: var(--bg-subtle); border: 1px solid var(--border-color); border-radius: var(--radius-md); font-size: 12px; }
    .legend-chip { display: flex; align-items: center; gap: 6px; font-weight: 600; }
    .legend-chip .dot { width: 10px; height: 10px; border-radius: 50%; }
  </style>
</head>
<body>
  <div class="app-container">
    <!-- Sidebar Navigation -->
    <aside class="sidebar no-print">
      <div class="brand-section">
        <div class="brand-logo-frame">
          <img src="JTYEO-Logo.png" alt="JTYeo CPA Logo" class="brand-logo-img">
        </div>
        <div class="brand-info">
          <h2>J.T. YEO CPA</h2>
          <span>Accounting Office</span>
          <div class="firm-badge">Managing Partner &amp; HR</div>
        </div>
      </div>

      <nav class="sidebar-nav">
        <div class="nav-category">Management Navigation</div>
        <a class="nav-item active" data-tab="overall" onclick="switchTab('overall'); loadAdminOverallDashboard();">
          <i data-lucide="layout-grid"></i>
          <span>Overall Dashboard</span>
        </a>
        <a class="nav-item" data-tab="approvals" onclick="switchTab('approvals')">
          <i data-lucide="check-circle-2"></i>
          <span>Approvals Queue</span>
          <?php if ($pendingCount > 0): ?>
            <span class="nav-badge" id="pendingApprovalsBadge"><?= $pendingCount ?></span>
          <?php endif; ?>
        </a>
        <a class="nav-item" data-tab="calendar" onclick="switchTab('calendar'); initCalendar();">
          <i data-lucide="calendar"></i>
          <span>Team Calendar</span>
        </a>
        <a class="nav-item" data-tab="users" onclick="switchTab('users')">
          <i data-lucide="users"></i>
          <span>Associate Management</span>
        </a>
        <a class="nav-item" data-tab="biometrics" onclick="switchTab('biometrics'); loadDtrLogs();">
          <i data-lucide="scan-face"></i>
          <span>Biometric Attendance</span>
        </a>
        <a class="nav-item" data-tab="overview" onclick="switchTab('overview')">
          <i data-lucide="history"></i>
          <span>Leave History</span>
        </a>
        <a class="nav-item" data-tab="leave-policies" onclick="switchTab('leave-policies')">
          <i data-lucide="sliders"></i>
          <span>Leave Types & Policies</span>
        </a>
      </nav>
    </aside>

    <!-- Main Content Area -->
    <main class="main-wrapper">
      <!-- Top Bar Header -->
      <header class="top-header no-print">
        <div class="header-left">
          <div class="firm-status">
            <span class="status-dot"></span>
            <span>Managing Partner & HR Portal</span>
          </div>
          <div class="ph-time" id="liveClock">PHT: Loading...</div>
        </div>

        <div class="header-right">
          <div class="user-profile" onclick="openModal('profileModal')" style="cursor:pointer;" title="Click to view/edit profile and change password">
            <div class="avatar">
              <?php if (!empty($user['avatar_path'])): ?>
                <img src="<?= htmlspecialchars($user['avatar_path']) ?>" alt="Avatar">
              <?php else: ?>
                <?= htmlspecialchars($user['avatar_initials']) ?>
              <?php endif; ?>
            </div>
            <div class="profile-meta">
              <div class="user-name"><?= htmlspecialchars($user['name']) ?></div>
              <div class="user-role"><?= htmlspecialchars($user['title']) ?></div>
            </div>
          </div>

          <a href="logout.php" class="btn-icon" title="Sign Out" style="margin-left: 6px; color: var(--text-muted);">
            <i data-lucide="log-out" style="width: 16px; height: 16px;"></i>
          </a>
        </div>
      </header>

      <!-- Content Area -->
      <div class="content-area">

        <!-- ==============================================
             TAB 0: EXECUTIVE OVERALL DASHBOARD
             ============================================== -->
        <div id="tab-overall" class="tab-pane active" style="display:block;">
          <div class="page-header">
            <div class="page-title">
              <h1>Executive Overall Dashboard</h1>
              <p>Firm-wide Attendance, Biometric Device Status, Leave Ledger, and Decision Center</p>
            </div>
            <div class="header-actions">
              <button class="btn-secondary" onclick="triggerManualSync()" id="btnAdminOverallSync">
                <i data-lucide="refresh-cw"></i>
                <span>Sync ZKTeco LAN</span>
              </button>
              <button class="btn-secondary" onclick="switchTab('biometrics'); loadDtrLogs();">
                <i data-lucide="printer"></i>
                <span>Print Form 48 DTR</span>
              </button>
              <button class="btn-primary" onclick="openModal('applyModal')">
                <i data-lucide="plus-circle"></i>
                <span>File Leave Request</span>
              </button>
            </div>
          </div>

          <!-- Executive Firm Pulse KPI Row -->
          <div class="kpi-grid" style="margin-bottom: 24px;">
            <div class="kpi-card">
              <div class="kpi-header">
                <span class="kpi-label">Present in Office</span>
                <div class="kpi-icon"><i data-lucide="user-check"></i></div>
              </div>
              <div class="kpi-value-row">
                <span class="kpi-value" id="adminOverallPresent"><?= $presentCount ?></span>
                <span class="kpi-sub">/ <?= $totalStaff ?> Associates</span>
              </div>
              <div class="kpi-footer">
                <i data-lucide="activity" style="width:14px;height:14px;"></i>
                <span>Clocked In Today</span>
              </div>
            </div>

            <div class="kpi-card">
              <div class="kpi-header">
                <span class="kpi-label">Currently on Break</span>
                <div class="kpi-icon"><i data-lucide="coffee"></i></div>
              </div>
              <div class="kpi-value-row">
                <span class="kpi-value" id="adminOverallBreak">0</span>
                <span class="kpi-sub">Associates</span>
              </div>
              <div class="kpi-footer">
                <i data-lucide="clock" style="width:14px;height:14px;"></i>
                <span>Lunch / Meal Break</span>
              </div>
            </div>

            <div class="kpi-card">
              <div class="kpi-header">
                <span class="kpi-label">On Approved Leave</span>
                <div class="kpi-icon"><i data-lucide="palmtree"></i></div>
              </div>
              <div class="kpi-value-row">
                <span class="kpi-value" id="adminOverallLeave"><?= $onLeaveCount ?></span>
                <span class="kpi-sub">Active Today</span>
              </div>
              <div class="kpi-footer">
                <i data-lucide="calendar" style="width:14px;height:14px;"></i>
                <span>Scheduled Absences</span>
              </div>
            </div>

            <div class="kpi-card" onclick="switchTab('approvals')" style="cursor:pointer;" title="Click to open Approvals Queue">
              <div class="kpi-header">
                <span class="kpi-label">Awaiting Your Decision</span>
                <div class="kpi-icon"><i data-lucide="clipboard-check"></i></div>
              </div>
              <div class="kpi-value-row">
                <span class="kpi-value" id="adminOverallPending"><?= $pendingCount ?></span>
                <span class="kpi-sub">Action Items</span>
              </div>
              <div class="kpi-footer">
                <i data-lucide="check-circle-2" style="width:14px;height:14px;"></i>
                <span id="adminPendingBreakdownText">Leaves, Punches &amp; OT</span>
              </div>
            </div>
          </div>

          <!-- Overall Layout Grid: Main 65% + Side 35% -->
          <div class="overall-layout-grid">
            
            <!-- Left Main Column -->
            <div class="overall-col-main">
              
              <!-- Action Center: Items Awaiting Signature -->
              <div class="dashboard-card">
                <div class="card-head" style="display:flex; justify-content:space-between; align-items:center;">
                  <h3>
                    <i data-lucide="inbox"></i>
                    Action Center &mdash; Items Awaiting Your Decision
                  </h3>
                  <div style="display:flex; align-items:center; gap:10px;">
                    <span class="badge badge-primary" id="adminActionCenterBadge">Loading...</span>
                    <button class="btn-link" onclick="switchTab('approvals')" style="font-size:12px; font-weight:600; color:var(--primary); background:none; border:none; cursor:pointer;">
                      Go to Approvals Queue &rarr;
                    </button>
                  </div>
                </div>
                <div class="card-body" style="padding: 16px;">
                  <div id="adminActionCenterList" class="action-items-list">
                    <div style="text-align:center; padding: 24px; color: var(--text-muted);">
                      <i data-lucide="loader-2" class="spin" style="width:24px; height:24px; margin-bottom:8px;"></i>
                      <div>Loading pending action items...</div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Today's Biometric Punch Activity Ledger -->
              <div class="dashboard-card">
                <div class="card-head" style="display:flex; justify-content:space-between; align-items:center;">
                  <h3>
                    <i data-lucide="scan-face"></i>
                    Today's Attendance Punches (ZKTeco MB460 Plus)
                  </h3>
                  <button class="btn-link" onclick="switchTab('biometrics'); loadDtrLogs();" style="font-size:12px; font-weight:600; color:var(--accent); background:none; border:none; cursor:pointer;">
                    View Complete Ledger &rarr;
                  </button>
                </div>
                <div class="table-responsive">
                  <table class="custom-table" style="font-size: 13px;">
                    <thead>
                      <tr>
                        <th>Associate</th>
                        <th>Time In</th>
                        <th>Break Out</th>
                        <th>Break In</th>
                        <th>Time Out</th>
                        <th>Rendered</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody id="adminTodayPunchesTableBody">
                      <tr>
                        <td colspan="7" style="text-align:center; padding: 24px; color: var(--text-muted);">
                          Loading today's attendance logs...
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>

            </div>

            <!-- Right Sidebar Column -->
            <div class="overall-col-side">

              <!-- ZKTeco Hardware Status Widget -->
              <div class="hardware-widget-card">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
                  <div style="display:flex; align-items:center; gap:8px;">
                    <i data-lucide="cpu" style="width:18px; height:18px; color:var(--primary);"></i>
                    <strong style="font-size:13px;">ZKTeco MB460 Plus</strong>
                  </div>
                  <span class="status-pill <?= $zkIsOnline ? 'active' : 'inactive' ?>" id="adminZkOnlineBadge">
                    <span class="status-dot"></span>
                    <span id="adminZkStatusLabel"><?= $zkIsOnline ? 'Online / LAN' : 'Standby / LAN' ?></span>
                  </span>
                </div>
                <div style="font-size:12px; color:var(--text-muted); display:flex; flex-direction:column; gap:4px; margin-bottom:12px;">
                  <div><strong>IP:</strong> <code id="adminZkIpDisplay"><?= htmlspecialchars($zkDeviceIp) ?><?= ($zkDeviceIp !== 'Auto-detecting...' && strpos($zkDeviceIp, ':') === false) ? ':4370' : '' ?></code></div>
                  <div><strong>Serial:</strong> <code id="adminZkSerialDisplay"><?= htmlspecialchars($zkDeviceSn) ?></code></div>
                  <div id="adminZkLastSeenText"><strong>Last Sync:</strong> <?= htmlspecialchars($zkLastSeen) ?></div>
                </div>
                <button class="btn-secondary btn-sm" onclick="triggerManualSync()" style="width:100%; justify-content:center;">
                  <i data-lucide="refresh-cw" style="width:13px; height:13px;"></i>
                  <span>Sync Device Now</span>
                </button>
              </div>

              <!-- Who's in the Office Right Now? -->
              <div class="dashboard-card">
                <div class="card-head">
                  <h3>
                    <i data-lucide="users"></i>
                    Who's in the Office Now
                  </h3>
                </div>
                <div class="card-body" style="padding: 12px;">
                  <div id="adminOverallPresenceRoster" class="presence-roster-list">
                    <div style="text-align:center; padding: 16px; color: var(--text-muted); font-size:12px;">
                      Loading roster...
                    </div>
                  </div>
                </div>
              </div>

              <!-- Upcoming Schedule (Next 7 Days) -->
              <div class="dashboard-card">
                <div class="card-head">
                  <h3>
                    <i data-lucide="calendar"></i>
                    Upcoming Schedule (7 Days)
                  </h3>
                </div>
                <div class="card-body" style="padding: 12px;">
                  <div id="adminUpcomingScheduleList" style="display:flex; flex-direction:column; gap:8px;">
                    <div style="text-align:center; padding: 16px; color: var(--text-muted); font-size:12px;">
                      Loading schedule...
                    </div>
                  </div>
                </div>
              </div>

            </div>

          </div>
        </div>

        <!-- ==============================================
             TAB: LEAVE HISTORY
             ============================================== -->
        <div id="tab-overview" class="tab-pane" style="display:none;">

          <div class="page-header">
            <div class="page-title">
              <h1>Firm Leave History</h1>
              <p>Complete chronological record and audit trail of all associate leave applications and approvals.</p>
            </div>
            <div class="header-actions">
              <button class="btn-secondary" onclick="exportLeaveHistoryCsv()">
                <i data-lucide="download"></i>
                <span>Export History CSV</span>
              </button>
              <button class="btn-secondary" onclick="window.print()">
                <i data-lucide="printer"></i>
                <span>Print Ledger</span>
              </button>
            </div>
          </div>

          <!-- Master Table Card -->
          <div class="dashboard-card">
            <div class="card-head">
              <h3><i data-lucide="history"></i> Associate Leave Applications History</h3>
              <span style="font-size:12px; color:var(--text-muted);"><?= count($leaveRequests) ?> Records</span>
            </div>
            <div class="table-responsive">
              <table class="custom-table">
                <thead>
                  <tr>
                    <th>Associate</th>
                    <th>Leave Category</th>
                    <th>Inclusive Dates & Duration</th>
                    <th>Reason / Engagement Details</th>
                    <th>Attachment</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($leaveRequests)): ?>
                    <tr><td colspan="7" style="text-align:center; padding:32px; color:var(--text-muted);">No leave applications recorded in database.</td></tr>
                  <?php else: ?>
                    <?php foreach ($leaveRequests as $req): ?>
                      <tr>
                        <td>
                          <div style="display:flex; align-items:center; gap:10px;">
                            <div class="avatar sm">
                              <?php if (!empty($req['avatar_path'])): ?>
                                <img src="<?= htmlspecialchars($req['avatar_path']) ?>" alt="">
                              <?php else: ?>
                                <?= htmlspecialchars($req['avatar_initials']) ?>
                              <?php endif; ?>
                            </div>
                            <div>
                              <div style="font-weight:700; color:var(--primary);"><?= htmlspecialchars($req['employee_name']) ?></div>
                              <div style="font-size:11px; color:var(--text-muted);"><?= htmlspecialchars($req['title']) ?></div>
                            </div>
                          </div>
                        </td>
                        <td><span class="badge badge-vl"><?= htmlspecialchars($req['leave_type_label']) ?></span></td>
                        <td>
                          <div style="font-weight:600;"><?= $req['start_date'] ?> <?= $req['start_date'] !== $req['end_date'] ? 'to ' . $req['end_date'] : '' ?></div>
                          <div style="font-size:11px; color:var(--text-muted);"><?= $req['days_count'] ?> Working Day(s)</div>
                        </td>
                        <td>
                          <div style="font-size:12.5px; max-width:240px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($req['reason']) ?>">
                            <?= htmlspecialchars($req['reason']) ?>
                          </div>
                          <div style="font-size:10.5px; color:var(--text-light);">Ref: <?= $req['ref_no'] ?></div>
                        </td>
                        <td>
                          <?php if (!empty($req['attachment_path'])): ?>
                            <a href="<?= htmlspecialchars($req['attachment_path']) ?>" target="_blank" class="btn-icon" title="View Attached Supporting Document / Proof" style="color:var(--accent);">
                              <i data-lucide="paperclip" style="width:14px;height:14px;"></i>
                            </a>
                          <?php else: ?>
                            <span style="color:var(--text-light); font-size:12px;">—</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?php
                            $statusBadge = 'badge-pending';
                            if ($req['status'] === 'Approved') $statusBadge = 'badge-approved';
                            if ($req['status'] === 'Rejected') $statusBadge = 'badge-rejected';
                          ?>
                          <span class="badge <?= $statusBadge ?>"><?= $req['status'] ?></span>
                        </td>
                        <td>
                          <div style="display:flex; gap:6px;">
                            <?php if ($req['status'] === 'Pending'): ?>
                              <button class="btn-icon approve" title="Review & Decide" onclick="openDecisionModal('<?= $req['ref_no'] ?>', '<?= htmlspecialchars(addslashes($req['employee_name'])) ?>', '<?= htmlspecialchars(addslashes($req['leave_type_label'])) ?>', '<?= $req['days_count'] ?>', '<?= htmlspecialchars(addslashes($req['reason'])) ?>', '<?= $req['start_date'] . ($req['start_date'] !== $req['end_date'] ? ' to ' . $req['end_date'] : '') ?>', '<?= htmlspecialchars(addslashes($req['attachment_path'] ?? '')) ?>')">
                                <i data-lucide="check-square" style="width:14px;height:14px;"></i>
                              </button>
                            <?php endif; ?>
                            <button class="btn-icon" title="View Full Details" onclick="viewDetailsModal('<?= $req['ref_no'] ?>', '<?= addslashes($req['employee_name']) ?>', '<?= addslashes($req['title']) ?>', '<?= addslashes($req['leave_type_label']) ?>', '<?= $req['days_count'] ?>', '<?= $req['start_date'] ?>', '<?= $req['end_date'] ?>', '<?= addslashes($req['reason']) ?>', '<?= $req['status'] ?>', '<?= addslashes($req['approver_name'] ?? 'Pending') ?>', '<?= addslashes($req['rejection_reason'] ?? '') ?>', '<?= addslashes($req['attachment_path'] ?? '') ?>')">
                              <i data-lucide="eye" style="width:14px;height:14px;"></i>
                            </button>
                            <?php if ($req['status'] === 'Approved'): ?>
                              <button class="btn-icon" title="Print Official Leave Slip" onclick="printOfficialSlip('<?= $req['ref_no'] ?>', '<?= addslashes($req['employee_name']) ?>', '<?= addslashes($req['title']) ?>', '<?= addslashes($req['leave_type_label']) ?>', '<?= $req['days_count'] ?>', '<?= $req['start_date'] ?>', '<?= $req['end_date'] ?>', '<?= addslashes($req['reason']) ?>', '<?= addslashes($req['approver_name'] ?? 'Atty. Jonathan Yeo, CPA') ?>')">
                                <i data-lucide="printer" style="width:14px;height:14px;"></i>
                              </button>
                            <?php endif; ?>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- ==============================================
             TAB 2: APPROVALS QUEUE
             ============================================== -->
        <div id="tab-approvals" class="tab-pane" style="display:none;">
          <div class="page-header">
            <div class="page-title">
              <h1>Pending Leave Approvals</h1>
              <p>Executive review queue for staff leave applications.</p>
            </div>
          </div>

          <div class="dashboard-card">
            <div class="card-head">
              <h3><i data-lucide="inbox"></i> Pending Review Queue</h3>
              <span class="badge badge-pending"><?= $pendingCount ?> Applications</span>
            </div>
            <div class="table-responsive">
              <table class="custom-table">
                <thead>
                  <tr>
                    <th>Associate</th>
                    <th>Leave Category</th>
                    <th>Inclusive Dates</th>
                    <th>Working Days</th>
                    <th>Reason / Engagement Coverage</th>
                    <th>Attachment</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($pendingApprovals)): ?>
                    <tr><td colspan="7" style="text-align:center; padding:32px; color:var(--text-muted);">No pending applications awaiting decision. All clear!</td></tr>
                  <?php else: ?>
                    <?php foreach ($pendingApprovals as $p): ?>
                      <tr data-ref="<?= htmlspecialchars($p['ref_no']) ?>" class="pending-approval-row">
                        <td>
                          <div style="font-weight:700; color:var(--primary);"><?= htmlspecialchars($p['employee_name']) ?></div>
                          <div style="font-size:11px; color:var(--text-muted);"><?= htmlspecialchars($p['title']) ?></div>
                        </td>
                        <td><span class="badge badge-vl"><?= htmlspecialchars($p['leave_type_label']) ?></span></td>
                        <td><?= $p['start_date'] ?> to <?= $p['end_date'] ?></td>
                        <td><strong><?= $p['days_count'] ?> Day(s)</strong></td>
                        <td style="font-size:12.5px; max-width:260px;"><?= htmlspecialchars($p['reason']) ?></td>
                        <td>
                          <?php if (!empty($p['attachment_path'])): ?>
                            <a href="<?= htmlspecialchars($p['attachment_path']) ?>" target="_blank" class="btn-icon" title="View Attached Supporting Document / Proof">
                              <i data-lucide="paperclip" style="width:14px;height:14px;color:var(--accent);"></i>
                            </a>
                          <?php else: ?>
                            <span style="color:var(--text-light); font-size:12px;">None</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <button class="btn-primary" style="padding:6px 12px; font-size:12px;" onclick="openDecisionModal('<?= $p['ref_no'] ?>', '<?= htmlspecialchars(addslashes($p['employee_name'])) ?>', '<?= htmlspecialchars(addslashes($p['leave_type_label'])) ?>', '<?= $p['days_count'] ?>', '<?= htmlspecialchars(addslashes($p['reason'])) ?>', '<?= $p['start_date'] . ($p['start_date'] !== $p['end_date'] ? ' to ' . $p['end_date'] : '') ?>', '<?= htmlspecialchars(addslashes($p['attachment_path'] ?? '')) ?>')">
                            <i data-lucide="check-square" style="width:13px;height:13px;"></i> Review
                          </button>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- ==============================================
             TAB 3: BIOMETRIC ATTENDANCE (ZKTeco MB460 Plus)
             ============================================== -->
        <div id="tab-biometrics" class="tab-pane" style="display:none;">
          <!-- Device Status Card -->
          <div class="zkteco-device-card">
            <div class="zkteco-info-block">
              <div class="zkteco-icon-box">
                <i data-lucide="fingerprint" style="width:26px;height:26px;"></i>
              </div>
              <div>
                <div class="zkteco-title">ZKTeco MB460 Plus Multi-Biometric System</div>
                <div class="zkteco-sub">
                  <span>Visible Light Face Recognition + SilkID Fingerprint</span>
                  <?php if ($zkIsOnline): ?>
                    <span id="zktecoStatusBadge" style="background:#dcfce7; color:#15803d; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:700; display:inline-flex; align-items:center; gap:4px;">
                      <i data-lucide="check-circle" style="width:12px;height:12px;"></i> Online (Connected)
                    </span>
                  <?php else: ?>
                    <span id="zktecoStatusBadge" style="background:#fee2e2; color:#991b1b; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:700; display:inline-flex; align-items:center; gap:4px;">
                      <i data-lucide="radio" style="width:12px;height:12px;"></i> Offline (Not Connected)
                    </span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <div class="zkteco-actions" style="display:flex; gap:8px; flex-wrap:wrap;">
              <button class="btn-secondary" onclick="syncClock()">
                <i data-lucide="clock"></i>
                <span>Sync Hardware Clock</span>
              </button>
              <button class="btn-secondary" onclick="syncBiometrics()">
                <i data-lucide="refresh-cw"></i>
                <span>Sync Device Now</span>
              </button>
              <button class="btn-primary" onclick="openModal('testPunchModal')">
                <i data-lucide="play-circle"></i>
                <span>Simulate Test Punch</span>
              </button>
            </div>
          </div>

          <!-- DTR Filter & Ledger Card -->
          <div class="dashboard-card" style="margin-top:16px;">
            <div class="card-head" style="flex-wrap:wrap; gap:12px; justify-content:space-between; align-items:center;">
              <div>
                <h3><i data-lucide="clock"></i> Daily Time Record (DTR) &amp; Attendance Ledger</h3>
                <p style="font-size:12px; color:var(--text-muted); margin-top:2px;">Reconciled physical biometric punches, actual rendered hours, and approved leaves.</p>
              </div>
              <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <div style="font-size:12.5px; color:var(--text-main); background:var(--bg-subtle); padding:6px 12px; border-radius:var(--radius-sm); border:1px solid var(--border-color); font-weight:600; display:inline-flex; align-items:center; gap:8px;">
                  <i data-lucide="activity" style="width:14px;height:14px;color:var(--accent);"></i>
                  <span>Rendered:</span>
                  <span id="kpiRenderedFormatted" style="color:var(--accent); font-weight:700;">0 hrs</span>
                  <span style="color:var(--border-color); margin:0 2px;">|</span>
                  <i data-lucide="clock" style="width:13px;height:13px;color:#b91c1c;"></i>
                  <span>Tardy:</span>
                  <span id="kpiTardyFormatted" style="color:#b91c1c; font-weight:700;">0m</span>
                  <span style="color:var(--border-color); margin:0 2px;">|</span>
                  <i data-lucide="alert-circle" style="width:13px;height:13px;color:#b45309;"></i>
                  <span>Exceptions:</span>
                  <span id="kpiExceptionsCount" style="color:#b45309; font-weight:700;">0</span>
                </div>
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                  <button class="btn-primary" style="font-size:12px; padding:6px 12px;" onclick="openAdminPrintDtr()">
                    <i data-lucide="printer"></i>
                    <span>Print Form 48 DTR</span>
                  </button>
                  <button class="btn-secondary" style="font-size:12px; padding:6px 12px;" onclick="exportDtrCsv()">
                    <i data-lucide="download"></i>
                    <span>Export CSV</span>
                  </button>
                </div>
              </div>
            </div>

            <!-- Calendar Range Filter Bar -->
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; background:var(--bg-subtle); padding:10px 14px; border-radius:8px; margin-top:10px; border:1px solid var(--border-color);">
              <div class="period-quick-pills">
                <button type="button" class="btn-period active" onclick="setAdminDatePeriod('today', this)">Today</button>
                <button type="button" class="btn-period" onclick="setAdminDatePeriod('this_week', this)">This Week</button>
                <button type="button" class="btn-period" onclick="setAdminDatePeriod('first_half', this)">1st Half (1st–15th)</button>
                <button type="button" class="btn-period" onclick="setAdminDatePeriod('second_half', this)">2nd Half (16th–End)</button>
                <button type="button" class="btn-period" onclick="setAdminDatePeriod('full_month', this)">Full Month</button>
                <button type="button" class="btn-period" onclick="setAdminDatePeriod('custom', this)">Custom Range</button>
              </div>

              <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <!-- Month Picker (for monthly views) -->
                <div id="adminMonthWrap" style="display:none;">
                  <input type="month" id="adminDtrMonthPicker" class="form-input" style="padding:5px 10px; font-size:12px; width:auto;" value="<?= date('Y-m') ?>" onchange="loadDtrLogs()">
                </div>

                <!-- Custom Range Inputs (for custom view) -->
                <div id="adminCustomRangeWrap" style="display:none; align-items:center; gap:6px;">
                  <input type="date" id="adminCustomStartDate" class="form-input" style="padding:5px 8px; font-size:12px; width:135px;" value="<?= date('Y-m-d') ?>" onchange="loadDtrLogs()">
                  <span style="font-size:12px; color:var(--text-muted);">&ndash;</span>
                  <input type="date" id="adminCustomEndDate" class="form-input" style="padding:5px 8px; font-size:12px; width:135px;" value="<?= date('Y-m-d') ?>" onchange="loadDtrLogs()">
                </div>

                <!-- Associate Filter -->
                <select id="dtrFilterUser" class="form-select" style="padding:5px 10px; font-size:12px; width:auto;" onchange="loadDtrLogs()">
                  <option value="0">All Firm Associates</option>
                  <?php foreach ($allUsers as $u): ?>
                    <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="table-responsive" style="margin-top:14px;">
              <table class="custom-table" id="dtrTable">
                <thead>
                  <tr>
                    <th>Associate</th>
                    <th>PIN</th>
                    <th id="dtrThDate" style="display:none;">Date</th>
                    <th>Time In</th>
                    <th>Break Out</th>
                    <th>Break In</th>
                    <th>Time Out</th>
                    <th>Rendered Hours</th>
                    <th>Overtime</th>
                    <th>Verification</th>
                    <th>Attendance Status</th>
                    <th style="width:70px; text-align:center;">Action</th>
                  </tr>
                </thead>
                <tbody id="dtrTableBody">
                  <tr><td colspan="12" style="text-align:center; padding:24px;">Loading daily time records...</td></tr>
                </tbody>
              </table>
            </div>
          </div>

        </div>

        <!-- ==============================================
             TAB 4: TEAM CALENDAR
             ============================================== -->
        <div id="tab-calendar" class="tab-pane" style="display:none;">
          <div class="page-header">
            <div class="page-title">
              <h1>Team Leave Calendar</h1>
              <p>Month-at-a-glance visualization of approved firm leaves and Philippine holidays.</p>
            </div>
          </div>

          <!-- Quick Filter Toolbar -->
          <div class="calendar-toolbar-bar">
            <div class="filter-group">
              <span class="filter-label">Filter Associate:</span>
              <select id="calFilterUser" class="form-select" style="padding:6px 10px; font-size:12px;" onchange="refreshCalendarEvents()">
                <option value="0">All Firm Associates</option>
                <?php foreach ($allUsers as $u): ?>
                  <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="filter-group">
              <span class="filter-label">Category:</span>
              <select id="calFilterType" class="form-select" style="padding:6px 10px; font-size:12px;" onchange="refreshCalendarEvents()">
                <option value="ALL">All Categories</option>
                <?php foreach ($activeLeaveTypes as $lt): ?>
                  <option value="<?= htmlspecialchars($lt['code']) ?>"><?= htmlspecialchars($lt['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="filter-group">
              <label style="display:flex; align-items:center; gap:6px; font-size:12px; font-weight:600; cursor:pointer;">
                <input type="checkbox" id="calToggleHolidays" checked onchange="refreshCalendarEvents()">
                <span>Show Philippine Public Holidays</span>
              </label>
            </div>
          </div>

          <div class="dashboard-card">
            <div id="leaveCalendar"></div>

            <div class="calendar-legend-bar">
              <?php foreach ($activeLeaveTypes as $lt): ?>
                <div class="legend-chip">
                  <span class="dot" style="background:<?= htmlspecialchars($lt['color'] ?? '#8b0e14') ?>;"></span>
                  <?= htmlspecialchars($lt['name']) ?>
                </div>
              <?php endforeach; ?>
              <div class="legend-chip"><span class="dot" style="background:#dc2626;"></span> <i data-lucide="flag" style="width:12px;height:12px;"></i> Regular Holiday</div>
              <div class="legend-chip"><span class="dot" style="background:#7c2d12;"></span> <i data-lucide="flag" style="width:12px;height:12px;"></i> Special Holiday</div>
            </div>
          </div>
        </div>

        <!-- ==============================================
             TAB 5: ASSOCIATE MANAGEMENT
             ============================================== -->
        <div id="tab-users" class="tab-pane" style="display:none;">
          <div class="page-header">
            <div class="page-title">
              <h1>Associate Directory &amp; Biometrics</h1>
              <p>Manage staff accounts, assign ZKTeco MB460 Plus PINs, and adjust annual leave credits.</p>
            </div>
            <div class="header-actions">
              <button class="btn-primary" onclick="openModal('addUserModal')">
                <i data-lucide="user-plus"></i>
                <span>Add New Associate</span>
              </button>
            </div>
          </div>

          <div class="dashboard-card">
            <div class="table-responsive">
              <table class="custom-table">
                <thead>
                  <tr>
                    <th>Associate</th>
                    <th>Email Address</th>
                    <th>Gender</th>
                    <th>Role</th>
                    <th>Biometric Enrollment (MB460 Plus)</th>
                    <th>Leave Entitlements</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($allUsers as $u): ?>
                    <tr>
                      <td>
                        <div style="display:flex; align-items:center; gap:10px;">
                          <div class="avatar">
                            <?php if (!empty($u['avatar_path'])): ?>
                              <img src="<?= htmlspecialchars($u['avatar_path']) ?>" alt="">
                            <?php else: ?>
                              <?= htmlspecialchars($u['avatar_initials']) ?>
                            <?php endif; ?>
                          </div>
                          <div>
                            <div style="font-weight:700; color:var(--primary);"><?= htmlspecialchars($u['name']) ?></div>
                            <div style="font-size:11px; color:var(--text-muted);"><?= htmlspecialchars($u['title']) ?></div>
                          </div>
                        </div>
                      </td>
                      <td><?= htmlspecialchars($u['email']) ?></td>
                      <td>
                        <span class="badge <?= $u['gender'] === 'Female' ? 'badge-spl' : 'badge-primary' ?>">
                          <?= htmlspecialchars($u['gender']) ?>
                        </span>
                      </td>
                      <td>
                        <span class="badge <?= $u['role'] === 'admin' ? 'badge-approved' : 'badge-pending' ?>">
                          <?= ucfirst($u['role']) ?>
                        </span>
                      </td>
                      <td>
                        <div style="font-size:12px; display:flex; flex-direction:column; gap:3px;">
                          <div><strong>Device PIN:</strong> #<?= htmlspecialchars($u['biometric_pin'] ?: $u['id']) ?></div>
                          <div>
                            <?php if (!empty($u['face_enrolled'])): ?>
                              <span style="color:var(--success); font-weight:600; display:inline-flex; align-items:center; gap:4px;" title="Visible Light Face Recognition Active"><i data-lucide="scan-face" style="width:13px;height:13px;"></i> Face: Enrolled</span>
                            <?php else: ?>
                              <span style="color:var(--text-light); font-weight:500; display:inline-flex; align-items:center; gap:4px;" title="Pending registration or first punch on MB460 Plus terminal"><i data-lucide="scan-face" style="width:13px;height:13px;"></i> Face: Pending</span>
                            <?php endif; ?>
                            &bull; 
                            <?php if (!empty($u['fingerprint_enrolled'])): ?>
                              <span style="color:var(--accent); font-weight:600; display:inline-flex; align-items:center; gap:4px;" title="SilkID Fingerprint Sensor Active"><i data-lucide="fingerprint" style="width:13px;height:13px;"></i> Fingerprint: Enrolled</span>
                            <?php else: ?>
                              <span style="color:var(--text-light); font-weight:500; display:inline-flex; align-items:center; gap:4px;" title="Pending registration or first punch on MB460 Plus terminal"><i data-lucide="fingerprint" style="width:13px;height:13px;"></i> Fingerprint: Pending</span>
                            <?php endif; ?>
                          </div>
                        </div>
                      </td>
                      <td>
                        <div style="font-size:11.5px; color:var(--text-main); line-height:1.4;">
                          <div>VL: <strong><?= number_format($u['vl_balance'], 1) ?>d</strong> &bull; SL: <strong><?= number_format($u['sl_balance'], 1) ?>d</strong></div>
                          <?php if ($u['gender'] === 'Female'): ?>
                            <div style="color:var(--purple); font-size:11px;">Maternity: <strong><?= number_format($u['maternity_balance'], 1) ?>d</strong></div>
                          <?php else: ?>
                            <div style="color:var(--accent); font-size:11px;">Paternity: <strong><?= number_format($u['paternity_balance'], 1) ?>d</strong></div>
                          <?php endif; ?>
                        </div>
                      </td>
                      <td>
                        <div style="display:flex; gap:6px;">
                          <button class="btn-icon" title="Edit Profile & Biometrics" onclick="openEditUserModal(<?= $u['id'] ?>)">
                            <i data-lucide="edit-3" style="width:14px;height:14px;"></i>
                          </button>
                          <button class="btn-icon" title="Direct Balance Adjustment" onclick="openAdjustmentModal(<?= $u['id'] ?>)">
                            <i data-lucide="scale" style="width:14px;height:14px;color:var(--accent);"></i>
                          </button>
                          <?php if ((int)$u['id'] !== (int)$user['id']): ?>
                            <button class="btn-icon" title="Delete Associate" style="color:var(--danger);" onclick="deleteUser(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['name'])) ?>')">
                              <i data-lucide="trash-2" style="width:14px;height:14px;"></i>
                            </button>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- TAB 6: LEAVE TYPES & POLICIES MANAGEMENT -->
        <div id="tab-leave-policies" class="tab-pane" style="display:none;">
          <div class="page-header">
            <div class="page-title">
              <h1>Leave Policies & Entitlement Allocation</h1>
              <p>Configure firm-wide leave categories, establish standard annual quotas, and manage associate credit allocations.</p>
            </div>
            <div class="header-actions">
              <button class="btn-secondary" onclick="openModal('bulkAllocateModal')">
                <i data-lucide="layers"></i>
                <span>Bulk Allocate Credits</span>
              </button>
              <button class="btn-primary" onclick="openModal('addLeaveTypeModal')">
                <i data-lucide="plus-circle"></i>
                <span>Add Leave Category</span>
              </button>
            </div>
          </div>

          <!-- Section 1: Firm Leave Categories Grid -->
          <div class="dashboard-card" style="margin-bottom: 24px;">
            <div class="card-head">
              <h3><i data-lucide="sliders"></i> Active Firm Leave Categories</h3>
              <span style="font-size:12px; color:var(--text-muted);"><?= count($allLeaveTypes) ?> Configured Categories</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:16px; padding:20px;">
              <?php foreach ($allLeaveTypes as $lt): ?>
                <div class="policy-card" style="background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-md); padding:18px; display:flex; flex-direction:column; justify-content:space-between; position:relative; box-shadow:var(--shadow-sm);">
                  <div>
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                      <div style="display:flex; align-items:center; gap:8px;">
                        <span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:<?= htmlspecialchars($lt['color']) ?>;"></span>
                        <strong style="font-size:15px; color:var(--primary);"><?= htmlspecialchars($lt['name']) ?></strong>
                      </div>
                      <span class="badge" style="background:var(--bg-subtle); color:var(--text-muted); font-family:monospace; font-weight:700;"><?= htmlspecialchars($lt['code']) ?></span>
                    </div>

                    <p style="font-size:12.5px; color:var(--text-muted); margin-bottom:14px; line-height:1.4;">
                      <?= htmlspecialchars($lt['description'] ?: 'Standard firm leave policy allocation.') ?>
                    </p>

                    <div style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:14px;">
                      <?php 
                        $isCore = in_array($lt['code'], ['VL', 'SL', 'LWOP', 'Maternity', 'Paternity'], true);
                      ?>
                      <span class="badge" style="background:<?= $lt['is_paid'] ? 'var(--success-soft)' : 'var(--bg-subtle)' ?>; color:<?= $lt['is_paid'] ? '#065f46' : 'var(--text-muted)' ?>;">
                        <?= $lt['is_paid'] ? 'Paid Leave' : 'Unpaid (LWOP)' ?>
                      </span>
                      <span class="badge" style="background:var(--bg-subtle); color:var(--text-main);">
                        <?= $lt['gender_restriction'] === 'All' ? 'All Associates' : htmlspecialchars($lt['gender_restriction']) . ' Only' ?>
                      </span>
                      <?php if ($lt['requires_attachment']): ?>
                        <span class="badge" style="background:#fee2e2; color:#b91c1c;">Proof Required</span>
                      <?php endif; ?>
                      <?php if ($isCore): ?>
                        <span class="badge" style="background:var(--bg-subtle); color:var(--text-muted);">Core Policy</span>
                      <?php endif; ?>
                      <?php if (!$lt['is_active']): ?>
                        <span class="badge" style="background:#f1f5f9; color:#64748b;">Archived</span>
                      <?php endif; ?>
                    </div>
                  </div>

                  <div style="border-top:1px solid var(--border-color); padding-top:12px; display:flex; align-items:center; justify-content:space-between;">
                    <div>
                      <div style="font-size:11px; text-transform:uppercase; color:var(--text-light); font-weight:700;">Standard Quota</div>
                      <div style="font-size:18px; font-weight:800; color:var(--primary);">
                        <?= (float)$lt['default_days'] ?> <span style="font-size:12px; font-weight:600; color:var(--text-muted);">Days</span>
                      </div>
                    </div>
                    <div style="display:flex; gap:6px;">
                      <button class="btn-icon" title="Edit Policy" onclick='openEditLeaveTypeModal(<?= json_encode($lt, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                        <i data-lucide="pencil" style="width:14px;height:14px;color:var(--text-main);"></i>
                      </button>
                      <button class="btn-icon" title="Bulk Apply <?= (float)$lt['default_days'] ?> days to All Staff" onclick="triggerBulkForCode('<?= htmlspecialchars($lt['code']) ?>', <?= (float)$lt['default_days'] ?>)">
                        <i data-lucide="zap" style="width:14px;height:14px;color:var(--accent);"></i>
                      </button>
                      <button class="btn-icon" title="<?= $lt['is_active'] ? 'Archive Policy' : 'Activate Policy' ?>" onclick="toggleLeaveTypeStatus(<?= $lt['id'] ?>, <?= $lt['is_active'] ? 0 : 1 ?>)">
                        <i data-lucide="<?= $lt['is_active'] ? 'archive' : 'rotate-ccw' ?>" style="width:14px;height:14px;color:<?= $lt['is_active'] ? 'var(--text-muted)' : 'var(--success)' ?>;"></i>
                      </button>
                      <?php if (!$isCore): ?>
                        <button class="btn-icon" title="Permanently Delete Policy" onclick="deleteLeaveType(<?= $lt['id'] ?>, '<?= htmlspecialchars(addslashes($lt['name'])) ?>')">
                          <i data-lucide="trash-2" style="width:14px;height:14px;color:var(--danger, #dc2626);"></i>
                        </button>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Section 2: Live Associate Entitlement Matrix -->
          <div class="dashboard-card">
            <div class="card-head" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
              <div>
                <h3><i data-lucide="grid"></i> Associate Leave Allocation Matrix</h3>
                <span style="font-size:12px; color:var(--text-muted);">Click any balance chip to quickly adjust individual credits</span>
              </div>
              <div style="display:flex; align-items:center; gap:10px;">
                <input type="text" id="matrixSearchInput" class="form-input" placeholder="Search associate..." style="padding:6px 12px; font-size:12px; width:200px;" onkeyup="filterMatrixTable()">
              </div>
            </div>
            <div class="table-responsive">
              <table class="custom-table" id="matrixTable">
                <thead>
                  <tr>
                    <th style="min-width:190px;">Associate</th>
                    <?php foreach ($activeLeaveTypes as $lt): ?>
                      <th style="text-align:center; min-width:85px;" title="<?= htmlspecialchars($lt['name']) ?>">
                        <div style="display:flex; align-items:center; justify-content:center; gap:5px;">
                          <span style="width:8px; height:8px; border-radius:50%; background:<?= htmlspecialchars($lt['color']) ?>; display:inline-block;"></span>
                          <span><?= htmlspecialchars($lt['code']) ?></span>
                        </div>
                      </th>
                    <?php endforeach; ?>
                    <th style="text-align:center; min-width:80px;">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($allUsers as $u): 
                    $uGender = strtolower(trim($u['gender'] ?? 'female'));
                  ?>
                    <tr class="matrix-row">
                      <td>
                        <div style="font-weight:700; color:var(--primary);"><?= htmlspecialchars($u['name']) ?></div>
                        <div style="font-size:11px; color:var(--text-muted);"><?= htmlspecialchars($u['gender']) ?></div>
                      </td>
                      <?php foreach ($activeLeaveTypes as $lt): 
                        $code = $lt['code'];
                        $isRestricted = ($lt['gender_restriction'] === 'Female' && $uGender === 'male') || ($lt['gender_restriction'] === 'Male' && $uGender === 'female');
                        $bal = $userAllocMap[$u['id']][$code] ?? (float)$lt['default_days'];
                      ?>
                        <td style="text-align:center;">
                          <?php if ($isRestricted): ?>
                            <span style="color:var(--text-light); font-size:11px; font-style:italic;" title="Not eligible due to gender policy">N/A</span>
                          <?php else: ?>
                            <button type="button" class="btn-balance-chip"
                              onclick="openQuickAdjustModal(<?= $u['id'] ?>, '<?= addslashes($u['name']) ?>', '<?= htmlspecialchars($code) ?>', '<?= addslashes($lt['name']) ?>', <?= $bal ?>)"
                              title="Click to adjust <?= htmlspecialchars($lt['name']) ?> for <?= addslashes($u['name']) ?>">
                              <?= $bal ?>d
                            </button>
                          <?php endif; ?>
                        </td>
                      <?php endforeach; ?>
                      <td style="text-align:center;">
                        <button class="btn-icon" title="Adjust Balances" onclick="openAdjustmentModal(<?= $u['id'] ?>)">
                          <i data-lucide="scale" style="width:14px;height:14px;color:var(--accent);"></i>
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

      </div>
    </main>
  </div>

  <!-- ==============================================
       MODALS
       ============================================== -->

  <!-- 1. DECISION MODAL (WITH STAFFING CONFLICT INDICATOR) -->
  <div class="modal-backdrop" id="decisionModal">
    <div class="modal-window">
      <div class="modal-header">
        <h3><i data-lucide="check-square"></i> Review Leave Application</h3>
        <button class="btn-close-modal" onclick="closeModal('decisionModal')">&times;</button>
      </div>
      <div class="modal-body">
        <div style="background:var(--bg-subtle); border:1px solid var(--border-color); border-radius:var(--radius-md); padding:14px; margin-bottom:14px;">
          <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">
            <div>
              <div style="font-size:11px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Reference</div>
              <div style="font-size:16px; font-weight:800; color:var(--primary); font-family:monospace;" id="decModalRef">LR-2026-XXX</div>
            </div>
            <div id="decModalAttachmentWrap" style="display:none;">
              <a id="decModalAttachmentLink" href="#" target="_blank" class="btn-secondary btn-sm" style="font-size:11px; display:inline-flex; align-items:center; gap:4px;">
                <i data-lucide="paperclip" style="width:13px;height:13px;"></i> View Attached Proof
              </a>
            </div>
          </div>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px 14px; font-size:13px;">
            <div><strong>Associate:</strong> <span id="decModalStaff"></span></div>
            <div><strong>Category:</strong> <span id="decModalType"></span></div>
            <div><strong>Inclusive Dates:</strong> <span id="decModalDates"></span></div>
            <div><strong>Duration:</strong> <span id="decModalDays"></span> working day(s)</div>
          </div>
          <div style="margin-top:12px; padding-top:10px; border-top:1px dashed var(--border-color);">
            <div style="font-size:11px; color:var(--text-muted); text-transform:uppercase; font-weight:700; margin-bottom:4px;">Reason / Engagement Details:</div>
            <div id="decModalReason" style="font-size:13px; color:var(--text-main); background:#fff; padding:10px 12px; border-radius:6px; border:1px solid var(--border-color); font-style:italic; line-height:1.4;">
              None specified
            </div>
          </div>
        </div>

        <div class="overlap-banner safe" id="decModalOverlap">
          <i data-lucide="shield-check"></i>
          <div><strong>Firm Coverage Status:</strong> No other staff leaves conflict with this request.</div>
        </div>

        <div class="form-group" style="margin-top:14px;">
          <label class="form-label">Reviewer Note / Feedback (Optional):</label>
          <textarea id="decModalNote" class="form-textarea" placeholder="Enter optional notes for the associate..."></textarea>
        </div>
      </div>
      <div class="modal-footer" style="justify-content:space-between;">
        <button type="button" class="btn-secondary" onclick="closeModal('decisionModal')">Cancel</button>
        <div style="display:flex; gap:10px;">
          <button type="button" class="btn-secondary" style="color:var(--danger); border-color:var(--danger);" onclick="executeDecisionWithNote('Rejected')">
            <i data-lucide="x" style="width:14px;height:14px;"></i> Reject
          </button>
          <button type="button" class="btn-primary" onclick="executeDecisionWithNote('Approved')">
            <i data-lucide="check-check" style="width:14px;height:14px;"></i> Partner Signoff &amp; Approve
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- 2. PROXY FILE LEAVE MODAL (WITH GENDER RULES & HOLIDAY EXCLUSION) -->
  <div class="modal-backdrop" id="applyModal">
    <div class="modal-window">
      <div class="modal-header">
        <h3><i data-lucide="calendar-plus"></i> File Leave Application</h3>
        <button class="btn-close-modal" onclick="closeModal('applyModal')">&times;</button>
      </div>
      <form id="phpLeaveForm" onsubmit="handleBackendLeaveSubmit(event)" enctype="multipart/form-data">
        <div class="modal-body">
          <div class="form-group" style="margin-bottom: 14px;">
            <label class="form-label"><i data-lucide="user" style="width:13px;height:13px;color:var(--accent);"></i> Associate <span class="req">*</span></label>
            <select name="target_user_id" id="applyTargetUser" class="form-select" required onchange="handleTargetUserChange()">
              <?php foreach ($allUsers as $u): ?>
                <option value="<?= $u['id'] ?>" data-gender="<?= htmlspecialchars($u['gender']) ?>" <?= $u['id'] == $user['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['title']) ?> &bull; <?= $u['gender'] ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group" style="margin-bottom: 14px;">
            <label class="form-label">Leave Category <span class="req">*</span></label>
            <select name="leave_type" id="applyLeaveType" class="form-select" required onchange="calculateWorkingDaysPreview()">
              <?php foreach ($activeLeaveTypes as $lt): ?>
                <option value="<?= htmlspecialchars($lt['code']) ?>"
                  data-gender="<?= htmlspecialchars($lt['gender_restriction']) ?>"
                  data-paid="<?= $lt['is_paid'] ?>"
                  data-proof="<?= $lt['requires_attachment'] ?>"
                  <?= $lt['code'] === 'VL' ? 'selected' : '' ?>>
                  <?= htmlspecialchars($lt['name']) ?><?= $lt['gender_restriction'] !== 'All' ? ' (' . $lt['gender_restriction'] . ' Only)' : '' ?><?= $lt['is_paid'] ? '' : ' [Unpaid]' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-grid">
            <div class="form-group">
              <label class="form-label">Start Date <span class="req">*</span></label>
              <input type="date" name="start_date" id="applyStartDate" class="form-input" required onchange="calculateWorkingDaysPreview()">
            </div>
            <div class="form-group">
              <label class="form-label">End Date <span class="req">*</span></label>
              <input type="date" name="end_date" id="applyEndDate" class="form-input" required onchange="calculateWorkingDaysPreview()">
            </div>
          </div>

          <div class="calculator-preview">
            <div>
              <div class="calc-label">Working Days Deducted:</div>
              <div class="calc-result" id="computedDaysPreview">1 Working Day</div>
              <div style="font-size:11px; color:var(--text-light);" id="holidayNotice">Weekends &amp; PH Holidays excluded</div>
            </div>
            <div style="text-align:right;">
              <div class="calc-label">Available Balance:</div>
              <div class="calc-result" id="availableBalancePreview" style="color:var(--accent);">-- Days</div>
            </div>
          </div>

          <!-- Supporting Document / Proof Attachment (Dynamically shown if policy requires proof) -->
          <div class="form-group" id="attachmentGroup" style="margin-top:14px; display:none;">
            <label class="form-label" id="attachmentLabel"><i data-lucide="paperclip" style="width:13px;height:13px;"></i> Attach Supporting Document / Proof <span class="req">*</span></label>
            <input type="file" name="attachment" id="applyAttachment" class="form-input" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
            <div style="font-size:11px; color:var(--text-muted); margin-top:3px;" id="attachmentHelpText">Supporting document is required for this policy. Supported: PDF, JPG, PNG, DOC (Max 10MB)</div>
          </div>

          <div class="form-group" style="margin-top:14px;">
            <label class="form-label">Reason / Client Engagement Coverage <span class="req">*</span></label>
            <textarea name="reason" id="applyReason" class="form-textarea" placeholder="State reason and client work coverage..." required></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-secondary" onclick="closeModal('applyModal')">Cancel</button>
          <button type="submit" class="btn-primary" id="btnSubmitLeave">Submit Application</button>
        </div>
      </form>
    </div>
  </div>

  <!-- 3. DETAILS & PRINT SLIP MODAL -->
  <div class="modal-backdrop" id="detailsModal">
    <div class="modal-window">
      <div class="modal-header">
        <h3><i data-lucide="file-text"></i> Leave Application Details</h3>
        <button class="btn-close-modal" onclick="closeModal('detailsModal')">&times;</button>
      </div>
      <div class="modal-body">
        <div style="background:var(--bg-subtle); border:1px solid var(--border-color); border-radius:var(--radius-md); padding:16px; margin-bottom:16px;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <div>
              <div style="font-size:11px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Reference</div>
              <div style="font-size:16px; font-weight:800; color:var(--primary); font-family:monospace;" id="dtlRef">LR-2026-XXX</div>
            </div>
            <div id="dtlStatusBadge"></div>
          </div>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; font-size:13px;">
            <div><span style="color:var(--text-muted);">Associate:</span> <strong id="dtlStaff" style="color:var(--primary);"></strong></div>
            <div><span style="color:var(--text-muted);">Title:</span> <span id="dtlTitle"></span></div>
            <div><span style="color:var(--text-muted);">Category:</span> <strong id="dtlType"></strong></div>
            <div><span style="color:var(--text-muted);">Duration:</span> <strong id="dtlDays" style="color:var(--accent);"></strong></div>
            <div style="grid-column: span 2;"><span style="color:var(--text-muted);">Inclusive Dates:</span> <span id="dtlDates" style="font-weight:600;"></span></div>
          </div>
        </div>

        <div style="margin-bottom:14px;">
          <div style="font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:6px;">Reason / Coverage:</div>
          <div id="dtlReason" style="background:#fff; border:1px solid var(--border-color); border-radius:var(--radius-md); padding:12px; font-size:13px; line-height:1.5;"></div>
        </div>

        <!-- Attachment Link -->
        <div id="dtlAttachmentRow" style="display:none; margin-bottom:14px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:var(--radius-md); padding:10px 14px;">
          <a id="dtlAttachmentLink" href="#" target="_blank" style="display:flex; align-items:center; gap:8px; color:#15803d; font-size:13px; font-weight:700; text-decoration:none;">
            <i data-lucide="paperclip" style="width:16px;height:16px;"></i>
            <span>View Attached Medical Certificate / Document</span>
          </a>
        </div>

        <div id="dtlApproverSection" style="background:#f8fafc; border:1px solid var(--border-color); border-radius:var(--radius-md); padding:12px; font-size:12.5px;">
          <div style="font-size:10.5px; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:4px;">Signoff Details:</div>
          <div style="font-weight:600; color:var(--primary);" id="dtlApprover"></div>
          <div id="dtlRejectionReason" style="font-size:12px; color:var(--danger); margin-top:4px; display:none;"></div>
        </div>
      </div>
      <div class="modal-footer" style="justify-content:space-between;">
        <button type="button" class="btn-secondary" onclick="closeModal('detailsModal')">Close</button>
        <button type="button" class="btn-primary" id="dtlPrintBtn">
          <i data-lucide="printer"></i>
          <span>Print Official Leave Slip</span>
        </button>
      </div>
    </div>
  </div>

  <!-- 4. PRINTABLE OFFICIAL LEAVE SLIP MODAL -->
  <div class="modal-backdrop" id="printableSlipModal">
    <div class="modal-window" style="max-width:700px;">
      <div class="modal-header no-print">
        <h3><i data-lucide="printer"></i> Official Leave Slip Preview</h3>
        <button class="btn-close-modal" onclick="closeModal('printableSlipModal')">&times;</button>
      </div>
      <div class="modal-body">
        <div class="official-slip-preview" id="slipContent">
          <div class="slip-header">
            <h2>JTYeo CPA Accounting Office</h2>
            <p>Certified Public Accountants &bull; Practice Management &bull; Zamboanga City, Philippines</p>
            <div style="margin-top:8px; font-weight:800; font-size:14px; text-decoration:underline;">OFFICIAL LEAVE APPLICATION &amp; APPROVAL SLIP</div>
          </div>

          <table class="slip-table">
            <tr><th>Reference Number</th><td id="slipRef"></td></tr>
            <tr><th>Associate Name</th><td id="slipStaff"></td></tr>
            <tr><th>Position / Title</th><td id="slipTitle"></td></tr>
            <tr><th>Leave Category</th><td id="slipType"></td></tr>
            <tr><th>Inclusive Dates</th><td id="slipDates"></td></tr>
            <tr><th>Total Working Days</th><td id="slipDays"></td></tr>
            <tr><th>Reason / Engagement Coverage</th><td id="slipReason"></td></tr>
            <tr><th>Approval Status</th><td style="color:#059669; font-weight:700;">APPROVED &amp; RECORDED</td></tr>
          </table>

          <div class="slip-signatures">
            <div>
              <div style="font-size:11px; color:#64748b;">Applicant Signature:</div>
              <div class="sign-line" id="slipStaffSign">Associate Signature</div>
            </div>
            <div>
              <div style="font-size:11px; color:#64748b;">Managing Partner Approval:</div>
              <div class="sign-line" id="slipApproverSign">Atty. Jonathan Yeo, CPA</div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer no-print" style="justify-content:space-between;">
        <button type="button" class="btn-secondary" onclick="closeModal('printableSlipModal')">Close</button>
        <button type="button" class="btn-primary" onclick="window.print()">
          <i data-lucide="printer"></i>
          <span>Print Document (Ctrl+P)</span>
        </button>
      </div>
    </div>
  </div>

  <!-- 5. FAST MANUAL BALANCE ADJUSTMENT MODAL -->
  <div class="modal-backdrop" id="adjModal">
    <div class="modal-window">
      <div class="modal-header">
        <h3><i data-lucide="scale"></i> Direct Leave Balance Adjustment</h3>
        <button class="btn-close-modal" onclick="closeModal('adjModal')">&times;</button>
      </div>
      <form id="adjForm" onsubmit="handleManualAdjustment(event)">
        <div class="modal-body">
          <div class="form-group" style="margin-bottom:14px;">
            <label class="form-label">Associate <span class="req">*</span></label>
            <select name="user_id" id="adjUserId" class="form-select" required>
              <?php foreach ($allUsers as $u): ?>
                <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['title']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group" style="margin-bottom:14px;">
            <label class="form-label">Leave Category <span class="req">*</span></label>
            <select name="leave_type" id="adjLeaveType" class="form-select" required>
              <?php foreach ($activeLeaveTypes as $lt): ?>
                <option value="<?= htmlspecialchars($lt['code']) ?>"><?= htmlspecialchars($lt['name']) ?> (<?= htmlspecialchars($lt['code']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label">Adjustment Amount (Days) <span class="req">*</span></label>
            <input type="number" step="0.5" name="amount" id="adjAmount" class="form-input" placeholder="e.g. +2.0 or -1.0" required>
            <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">Enter positive value to add credits, negative to deduct.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-secondary" onclick="closeModal('adjModal')">Cancel</button>
          <button type="submit" class="btn-primary" id="btnSaveAdj">Save Adjustment</button>
        </div>
      </form>
    </div>
  </div>

  <!-- 6. SIMULATE TEST PUNCH MODAL (ZKTECO MB460 PLUS) -->
  <div class="modal-backdrop" id="testPunchModal">
    <div class="modal-window">
      <div class="modal-header">
        <h3><i data-lucide="play-circle"></i> ZKTeco MB460 Plus Test Punch Simulator</h3>
        <button class="btn-close-modal" onclick="closeModal('testPunchModal')">&times;</button>
      </div>
      <form id="testPunchForm" onsubmit="handleTestPunchSubmit(event)">
        <div class="modal-body">
          <div class="form-group" style="margin-bottom:14px;">
            <label class="form-label">Associate to Clock In / Out <span class="req">*</span></label>
            <select name="user_id" id="punchUserId" class="form-select" required>
              <?php foreach ($allUsers as $u): ?>
                <option value="<?= $u['id'] ?>">PIN #<?= htmlspecialchars($u['biometric_pin'] ?: $u['id']) ?> - <?= htmlspecialchars($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-grid">
            <div class="form-group">
              <label class="form-label">Punch Type <span class="req">*</span></label>
              <select name="punch_type" id="punchType" class="form-select">
                <option value="time_in">Time In (Morning Arrival)</option>
                <option value="break_out">Break Out (Lunch / Break Departure)</option>
                <option value="break_in">Break In (Lunch / Break Return)</option>
                <option value="time_out">Time Out (Evening Departure)</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Verification Method <span class="req">*</span></label>
              <select name="verification_method" id="punchMethod" class="form-select">
                <option value="Face Scan">Face Scan (Touchless)</option>
                <option value="Fingerprint">Fingerprint Sensor</option>
                <option value="PIN / Card">PIN / RFID Card</option>
              </select>
            </div>
          </div>

          <div class="form-group" style="margin-top:14px;">
            <label class="form-label">Punch Time (HH:MM:SS)</label>
            <input type="time" step="1" name="punch_time" id="punchTime" class="form-input" value="<?= date('H:i:s') ?>">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-secondary" onclick="closeModal('testPunchModal')">Cancel</button>
          <button type="submit" class="btn-primary" id="btnSimPunch">Record Biometric Punch</button>
        </div>
      </form>
    </div>
  </div>

  <!-- 6.5 INLINE EDIT / ADJUST PUNCH MODAL -->
  <div class="modal-backdrop" id="adminEditPunchModal">
    <div class="modal-window">
      <div class="modal-header">
        <h3><i data-lucide="edit-3"></i> Adjust Daily Time Record</h3>
        <button class="btn-close-modal" onclick="closeModal('adminEditPunchModal')">&times;</button>
      </div>
      <form id="adminEditPunchForm" onsubmit="handleAdminEditPunchSubmit(event)">
        <input type="hidden" name="user_id" id="editPunchUserId">
        <div class="modal-body">
          <div style="background:var(--bg-subtle); border:1px solid var(--border-color); border-radius:8px; padding:12px; margin-bottom:14px;">
            <div style="font-weight:700; color:var(--primary);" id="editPunchAssociateName">Associate Name</div>
            <div style="font-size:11.5px; color:var(--text-muted); margin-top:2px;">
              Date: <strong id="editPunchDateLabel"></strong> &bull; PIN: <strong id="editPunchPinLabel"></strong>
            </div>
            <input type="hidden" name="target_date" id="editPunchDateInput">
          </div>

          <div class="form-grid">
            <div class="form-group">
              <label class="form-label">Time In (AM Arrival)</label>
              <input type="time" step="1" name="time_in" id="editTimeIn" class="form-input">
            </div>
            <div class="form-group">
              <label class="form-label">Break Out (Lunch Departure)</label>
              <input type="time" step="1" name="break_out" id="editBreakOut" class="form-input">
            </div>
            <div class="form-group">
              <label class="form-label">Break In (Lunch Return)</label>
              <input type="time" step="1" name="break_in" id="editBreakIn" class="form-input">
            </div>
            <div class="form-group">
              <label class="form-label">Time Out (Evening Departure)</label>
              <input type="time" step="1" name="time_out" id="editTimeOut" class="form-input">
            </div>
          </div>

          <div class="form-group" style="margin-top:12px;">
            <label class="form-label">Adjustment Note / Reference</label>
            <input type="text" name="reason" id="editPunchReason" class="form-input" placeholder="e.g. Verified manual time card adjustment by Managing Partner" value="Direct Admin Adjustment">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-secondary" onclick="closeModal('adminEditPunchModal')">Cancel</button>
          <button type="submit" class="btn-primary" id="btnSavePunchEdit">Save DTR Adjustment</button>
        </div>
      </form>
    </div>
  </div>

  <!-- 7. ADD ASSOCIATE MODAL -->
  <div class="modal-backdrop" id="addUserModal">
    <div class="modal-window">
      <div class="modal-header">
        <h3><i data-lucide="user-plus"></i> Add New Associate</h3>
        <button class="btn-close-modal" onclick="closeModal('addUserModal')">&times;</button>
      </div>
      <form id="addUserForm" onsubmit="handleAddUserSubmit(event)">
        <input type="hidden" name="action" value="create_user">
        <div class="modal-body">
          <div class="form-group" style="margin-bottom:14px;">
            <label class="form-label">Full Name &amp; Title <span class="req">*</span></label>
            <input type="text" name="name" class="form-input" placeholder="e.g. Maria Santos, CPA" required>
          </div>
          <div class="form-group" style="margin-bottom:14px;">
            <label class="form-label">Email Address <span class="req">*</span></label>
            <input type="email" name="email" class="form-input" placeholder="e.g. maria@jtyeocpa.ph" required>
          </div>
          <div class="form-grid">
            <div class="form-group">
              <label class="form-label">Gender <span class="req">*</span></label>
              <select name="gender" class="form-select" required>
                <option value="Female">Female</option>
                <option value="Male">Male</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Job Title / Designation <span class="req">*</span></label>
              <input type="text" name="title" class="form-input" placeholder="e.g. Audit Associate" required>
            </div>
          </div>
          <div class="form-grid" style="margin-top:14px;">
            <div class="form-group">
              <label class="form-label">Role <span class="req">*</span></label>
              <select name="role" class="form-select" required>
                <option value="staff">Staff Associate</option>
                <option value="admin">Managing Partner / Admin</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">ZKTeco Biometric PIN</label>
              <div style="display:flex; gap:8px;">
                <input type="text" name="biometric_pin" id="addBiometricPin" class="form-input" placeholder="e.g. 103" oninput="debounceDetectPin(this.value)">
                <button type="button" class="btn-secondary" style="white-space:nowrap; padding:6px 12px; font-size:12px; display:inline-flex; align-items:center; gap:5px;" onclick="checkDevicePinEnrollment()">
                  <i data-lucide="refresh-cw" style="width:12px;height:12px;"></i> Query Device
                </button>
              </div>
              <div id="pinDetectionStatus" style="font-size:11px; margin-top:4px; color:var(--text-light);">Enter device PIN to auto-detect if already registered on MB460 Plus</div>
            </div>
          </div>
          <div style="margin-top:14px; background:var(--bg-subtle); padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color);">
            <div style="font-size:12px; font-weight:700; margin-bottom:6px; display:flex; align-items:center; justify-content:space-between;">
              <span>Biometric Enrollment Status (MB460 Plus):</span>
              <span id="enrollAutoBadge" style="font-size:11px; font-weight:600; color:var(--accent);">Auto-updates on first punch</span>
            </div>
            <div style="display:flex; gap:16px;">
              <label style="display:inline-flex; align-items:center; gap:6px; font-size:12px; cursor:pointer;">
                <input type="checkbox" name="face_enrolled" id="addFaceEnrolled"> Face Recognition Enrolled
              </label>
              <label style="display:inline-flex; align-items:center; gap:6px; font-size:12px; cursor:pointer;">
                <input type="checkbox" name="fingerprint_enrolled" id="addFpEnrolled"> Fingerprint Enrolled
              </label>
            </div>
            <div style="font-size:11px; color:var(--text-muted); margin-top:6px; line-height:1.4;">
              <i data-lucide="zap" style="width:12px;height:12px; vertical-align:middle; color:var(--accent);"></i> 
              <strong>Automatic Enrollment:</strong> As soon as the associate scans their face or finger at the terminal (or during their first morning punch), the system automatically checks and upgrades these to <strong>Enrolled</strong>!
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-secondary" onclick="closeModal('addUserModal')">Cancel</button>
          <button type="submit" class="btn-primary" id="btnAddUserBtn">Create Associate Account</button>
        </div>
      </form>
    </div>
  </div>

  <!-- 8. EDIT ASSOCIATE MODAL -->
  <div class="modal-backdrop" id="editUserModal">
    <div class="modal-window">
      <div class="modal-header">
        <h3><i data-lucide="edit-3"></i> Edit Associate Profile</h3>
        <button class="btn-close-modal" onclick="closeModal('editUserModal')">&times;</button>
      </div>
      <form id="editUserForm" onsubmit="handleEditUserSubmit(event)" enctype="multipart/form-data">
        <input type="hidden" name="action" value="edit_user">
        <input type="hidden" name="user_id" id="editUserId">
        <div class="modal-body">
          <!-- Associate Photo Upload & Preview -->
          <div style="display:flex; align-items:center; gap:16px; margin-bottom:18px; padding:12px; background:var(--bg-subtle); border-radius:var(--radius-md); border:1px solid var(--border-color);">
            <div class="avatar lg" id="editAvatarContainer" style="overflow:hidden; width:64px; height:64px; min-width:64px; border-radius:50%; border:2px solid var(--accent); display:flex; align-items:center; justify-content:center; background:var(--primary); color:#fff; font-weight:700;">
              <img src="" alt="Avatar" id="editUserAvatarPreview" style="display:none; width:100%; height:100%; object-fit:cover;">
              <span id="editUserAvatarInitials">--</span>
            </div>
            <div style="flex:1;">
              <div style="font-size:12px; font-weight:700; color:var(--primary); margin-bottom:4px;">Associate Avatar Preview</div>
              <label class="btn-secondary" style="display:inline-flex; align-items:center; gap:6px; padding:6px 12px; font-size:12px; cursor:pointer;">
                <i data-lucide="camera" style="width:13px;height:13px;"></i> Choose Picture
                <input type="file" name="avatar" id="editUserAvatarInput" accept="image/*" style="display:none;" onchange="previewAvatarImage(this, 'editUserAvatarPreview', 'editUserAvatarInitials')">
              </label>
              <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">Check how the photo looks in the avatar circle before saving.</div>
            </div>
          </div>

          <div class="form-group" style="margin-bottom:14px;">
            <label class="form-label">Full Name &amp; Title <span class="req">*</span></label>
            <input type="text" name="name" id="editUserName" class="form-input" required>
          </div>
          <div class="form-group" style="margin-bottom:14px;">
            <label class="form-label">Email Address <span class="req">*</span></label>
            <input type="email" name="email" id="editUserEmail" class="form-input" required>
            <div style="font-size:11px; color:var(--text-muted); margin-top:3px;">
              <i data-lucide="bell" style="width:11px;height:11px; vertical-align:middle;"></i> Changing email triggers a security notification to the Managing Partner.
            </div>
          </div>
          <div class="form-grid">
            <div class="form-group">
              <label class="form-label">Gender <span class="req">*</span></label>
              <select name="gender" id="editUserGender" class="form-select" required>
                <option value="Female">Female</option>
                <option value="Male">Male</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Job Title / Designation <span class="req">*</span></label>
              <input type="text" name="title" id="editUserTitle" class="form-input" required>
            </div>
          </div>
          <div class="form-grid" style="margin-top:14px;">
            <div class="form-group">
              <label class="form-label">Role <span class="req">*</span></label>
              <select name="role" id="editUserRole" class="form-select" required>
                <option value="staff">Staff Associate</option>
                <option value="admin">Managing Partner / Admin</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">ZKTeco Biometric PIN</label>
              <input type="text" name="biometric_pin" id="editUserPin" class="form-input">
            </div>
          </div>
          <div style="margin-top:14px; background:var(--bg-subtle); padding:12px; border-radius:var(--radius-md);">
            <div style="font-size:12px; font-weight:700; margin-bottom:6px;">Biometric Enrollment (MB460 Plus):</div>
            <label style="display:inline-flex; align-items:center; gap:6px; margin-right:16px; font-size:12px;">
              <input type="checkbox" name="face_enrolled" id="editUserFace"> Face Recognition Enrolled
            </label>
            <label style="display:inline-flex; align-items:center; gap:6px; font-size:12px;">
              <input type="checkbox" name="fingerprint_enrolled" id="editUserFingerprint"> Fingerprint Enrolled
            </label>
          </div>
          <div class="form-group" style="margin-top:14px;">
            <label class="form-label">Reset Password (Leave blank to keep current):</label>
            <input type="password" name="reset_password" class="form-input" placeholder="Enter new password">
          </div>
        </div>
        <div class="modal-footer" style="display:flex; justify-content:space-between; align-items:center;">
          <button type="button" class="btn-danger" id="btnDeleteUserInModal" onclick="deleteUserFromEditModal()" style="background:#fee2e2; color:#b91c1c; border-color:#fca5a5; display:inline-flex; align-items:center; gap:6px;">
            <i data-lucide="trash-2" style="width:14px;height:14px;"></i> Delete Associate
          </button>
          <div style="display:flex; gap:8px;">
            <button type="button" class="btn-secondary" onclick="closeModal('editUserModal')">Cancel</button>
            <button type="submit" class="btn-primary" id="btnEditUserBtn">Save Profile Changes</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- 9. MY PROFILE / CHANGE PASSWORD MODAL -->
  <div class="modal-backdrop" id="profileModal">
    <div class="modal-window">
      <div class="modal-header">
        <h3><i data-lucide="user"></i> My Profile &amp; Security</h3>
        <button class="btn-close-modal" onclick="closeModal('profileModal')">&times;</button>
      </div>
      <form id="profileForm" onsubmit="handleProfileUpdate(event)" enctype="multipart/form-data">
        <input type="hidden" name="action" value="update_profile">
        <div class="modal-body">
          <!-- Live Avatar Preview & Upload -->
          <div style="display:flex; align-items:center; gap:16px; margin-bottom:18px; padding:12px; background:var(--bg-subtle); border-radius:var(--radius-md); border:1px solid var(--border-color);">
            <div class="avatar lg" id="myAvatarContainer" style="overflow:hidden; width:64px; height:64px; min-width:64px; border-radius:50%; border:2px solid var(--accent); display:flex; align-items:center; justify-content:center; background:var(--primary); color:#fff; font-weight:700;">
              <?php if (!empty($user['avatar_path'])): ?>
                <img src="<?= htmlspecialchars($user['avatar_path']) ?>" alt="Avatar" id="myAvatarPreview" style="width:100%; height:100%; object-fit:cover;">
                <span id="myAvatarInitialText" style="display:none;"><?= htmlspecialchars($user['avatar_initials']) ?></span>
              <?php else: ?>
                <span id="myAvatarInitialText"><?= htmlspecialchars($user['avatar_initials']) ?></span>
                <img src="" alt="Avatar" id="myAvatarPreview" style="display:none; width:100%; height:100%; object-fit:cover;">
              <?php endif; ?>
            </div>
            <div style="flex:1;">
              <div style="font-size:12px; font-weight:700; color:var(--primary); margin-bottom:4px;">Profile Picture Preview</div>
              <label class="btn-secondary" style="display:inline-flex; align-items:center; gap:6px; padding:6px 12px; font-size:12px; cursor:pointer;">
                <i data-lucide="camera" style="width:13px;height:13px;"></i> Choose Photo
                <input type="file" name="avatar" id="myAvatarInput" accept="image/*" style="display:none;" onchange="previewAvatarImage(this, 'myAvatarPreview', 'myAvatarInitialText')">
              </label>
              <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">Supported: JPG, PNG, WebP. Live preview shown on the left.</div>
            </div>
          </div>

          <div class="form-grid" style="margin-bottom:14px;">
            <div class="form-group">
              <label class="form-label">Full Name <span class="req">*</span></label>
              <input type="text" name="name" id="myProfileName" class="form-input" value="<?= htmlspecialchars($user['name']) ?>" required>
            </div>
            <div class="form-group">
              <label class="form-label">System Role <span class="req">*</span></label>
              <select name="role" id="myProfileRole" class="form-select" required>
                <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Managing Partner / Admin</option>
                <option value="staff" <?= $user['role'] === 'staff' ? 'selected' : '' ?>>Staff Associate</option>
              </select>
            </div>
          </div>

          <div class="form-group" style="margin-bottom:16px;">
            <label class="form-label">Email Address <span class="req">*</span></label>
            <input type="email" name="email" id="myProfileEmail" class="form-input" value="<?= htmlspecialchars($user['email']) ?>" required>
            <div style="font-size:11px; color:var(--text-muted); margin-top:3px;">
              <i data-lucide="bell" style="width:11px;height:11px; vertical-align:middle;"></i> Changing your email address will automatically dispatch a security notice to the Managing Partner.
            </div>
          </div>

          <hr style="border:none; border-top:1px solid var(--border-color); margin:18px 0;">

          <div style="font-size:13px; font-weight:700; color:var(--primary); margin-bottom:10px;">Change Password</div>
          <div class="form-group" style="margin-bottom:12px;">
            <label class="form-label">Current Password:</label>
            <input type="password" name="current_password" class="form-input" placeholder="Required to change password">
          </div>
          <div class="form-grid">
            <div class="form-group">
              <label class="form-label">New Password:</label>
              <input type="password" name="new_password" class="form-input" placeholder="At least 6 characters">
            </div>
            <div class="form-group">
              <label class="form-label">Confirm New Password:</label>
              <input type="password" name="confirm_password" class="form-input" placeholder="Repeat new password">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-secondary" onclick="closeModal('profileModal')">Cancel</button>
          <button type="submit" class="btn-primary" id="btnUpdateProfile">Save Profile Updates</button>
        </div>
      </form>
    </div>
  </div>

  <!-- 7. ADD LEAVE CATEGORY MODAL -->
  <div class="modal-backdrop" id="addLeaveTypeModal">
    <div class="modal-window">
      <div class="modal-header">
        <h3><i data-lucide="plus-circle"></i> Create New Leave Category</h3>
        <button class="btn-close-modal" onclick="closeModal('addLeaveTypeModal')">&times;</button>
      </div>
      <form id="addLeaveTypeForm" onsubmit="handleAddLeaveTypeSubmit(event)">
        <input type="hidden" name="action" value="add_type">
        <div class="modal-body">
          <div class="form-grid">
            <div class="form-group">
              <label class="form-label">Category Name <span class="req">*</span></label>
              <input type="text" name="name" class="form-input" placeholder="e.g. Board Exam Study Leave" required onkeyup="autoGenerateCode(this.value)">
            </div>
            <div class="form-group">
              <label class="form-label">Short Code / Key <span class="req">*</span></label>
              <input type="text" name="code" id="newTypeCode" class="form-input" placeholder="e.g. STUDY" style="text-transform:uppercase; font-family:monospace; font-weight:700;" required>
            </div>
          </div>

          <div class="form-group" style="margin-bottom:14px;">
            <label class="form-label">Policy Description & Guidelines</label>
            <textarea name="description" class="form-textarea" rows="2" placeholder="Brief description of when this leave is granted..."></textarea>
          </div>

          <div class="form-grid">
            <div class="form-group">
              <label class="form-label">Default Annual Days <span class="req">*</span></label>
              <input type="number" step="0.5" name="default_days" class="form-input" value="5" min="0" required>
            </div>
            <div class="form-group">
              <label class="form-label">Compensation Type <span class="req">*</span></label>
              <select name="is_paid" class="form-select">
                <option value="1" selected>Paid Leave (Standard Salary)</option>
                <option value="0">Unpaid Leave (LWOP)</option>
              </select>
            </div>
          </div>

          <div class="form-grid">
            <div class="form-group">
              <label class="form-label">Gender Eligibility <span class="req">*</span></label>
              <select name="gender_restriction" class="form-select">
                <option value="All" selected>All Associates</option>
                <option value="Female">Female Associates Only</option>
                <option value="Male">Male Associates Only</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Supporting Proof / Attachment</label>
              <select name="requires_attachment" class="form-select">
                <option value="0" selected>Optional Supporting Proof</option>
                <option value="1">Mandatory Supporting Document</option>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Calendar Event Color Theme</label>
            <div style="display:flex; align-items:center; gap:12px;">
              <input type="color" name="color" id="newTypeColor" value="#dc0000" style="width:44px; height:36px; border:none; border-radius:var(--radius-sm); cursor:pointer; background:none;">
              <span style="font-size:12px; color:var(--text-muted);">Choose a visual color for Calendar events & badges</span>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-secondary" onclick="closeModal('addLeaveTypeModal')">Cancel</button>
          <button type="submit" class="btn-primary" id="btnSaveNewType">Create &amp; Allocate</button>
        </div>
      </form>
    </div>
  </div>

  <!-- 8. EDIT LEAVE CATEGORY MODAL -->
  <div class="modal-backdrop" id="editLeaveTypeModal">
    <div class="modal-window">
      <div class="modal-header">
        <h3><i data-lucide="pencil"></i> Edit Leave Category Policy</h3>
        <button class="btn-close-modal" onclick="closeModal('editLeaveTypeModal')">&times;</button>
      </div>
      <form id="editLeaveTypeForm" onsubmit="handleEditLeaveTypeSubmit(event)">
        <input type="hidden" name="action" value="edit_type">
        <input type="hidden" name="id" id="editTypeId">
        <div class="modal-body">
          <div class="form-grid">
            <div class="form-group">
              <label class="form-label">Category Name <span class="req">*</span></label>
              <input type="text" name="name" id="editTypeName" class="form-input" required>
            </div>
            <div class="form-group">
              <label class="form-label">Category Code</label>
              <input type="text" id="editTypeCodeDisplay" class="form-input" disabled style="font-family:monospace; font-weight:700; background:var(--bg-subtle);">
            </div>
          </div>

          <div class="form-group" style="margin-bottom:14px;">
            <label class="form-label">Policy Description</label>
            <textarea name="description" id="editTypeDescription" class="form-textarea" rows="2"></textarea>
          </div>

          <div class="form-grid">
            <div class="form-group">
              <label class="form-label">Standard Annual Days <span class="req">*</span></label>
              <input type="number" step="0.5" name="default_days" id="editTypeDefaultDays" class="form-input" min="0" required>
            </div>
            <div class="form-group">
              <label class="form-label">Compensation Type <span class="req">*</span></label>
              <select name="is_paid" id="editTypeIsPaid" class="form-select">
                <option value="1">Paid Leave</option>
                <option value="0">Unpaid Leave</option>
              </select>
            </div>
          </div>

          <div class="form-grid">
            <div class="form-group">
              <label class="form-label">Gender Eligibility <span class="req">*</span></label>
              <select name="gender_restriction" id="editTypeGender" class="form-select">
                <option value="All">All Associates</option>
                <option value="Female">Female Associates Only</option>
                <option value="Male">Male Associates Only</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Supporting Proof</label>
              <select name="requires_attachment" id="editTypeProof" class="form-select">
                <option value="0">Optional</option>
                <option value="1">Mandatory</option>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Calendar Event Color</label>
            <input type="color" name="color" id="editTypeColor" style="width:44px; height:36px; border:none; border-radius:var(--radius-sm); cursor:pointer; background:none;">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-secondary" onclick="closeModal('editLeaveTypeModal')">Cancel</button>
          <button type="submit" class="btn-primary" id="btnSaveEditType">Update Policy</button>
        </div>
      </form>
    </div>
  </div>

  <!-- 9. BULK ALLOCATE CREDITS MODAL -->
  <div class="modal-backdrop" id="bulkAllocateModal">
    <div class="modal-window">
      <div class="modal-header">
        <h3><i data-lucide="layers"></i> Bulk Allocate Leave Credits</h3>
        <button class="btn-close-modal" onclick="closeModal('bulkAllocateModal')">&times;</button>
      </div>
      <form id="bulkAllocateForm" onsubmit="handleBulkAllocateSubmit(event)">
        <input type="hidden" name="action" value="bulk_allocate">
        <div class="modal-body">
          <div class="form-group" style="margin-bottom:14px;">
            <label class="form-label">Target Leave Category <span class="req">*</span></label>
            <select name="leave_type_code" id="bulkTypeCode" class="form-select" required>
              <?php foreach ($activeLeaveTypes as $lt): ?>
                <option value="<?= htmlspecialchars($lt['code']) ?>"><?= htmlspecialchars($lt['name']) ?> (<?= htmlspecialchars($lt['code']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group" style="margin-bottom:14px;">
            <label class="form-label">Allocation Mode <span class="req">*</span></label>
            <select name="mode" id="bulkMode" class="form-select" required>
              <option value="set" selected>Set Standard Quota (Replace All Associates' Balances)</option>
              <option value="add">Add Bonus / Top-Up (Add to Current Remaining Balances)</option>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label">Days to Allocate <span class="req">*</span></label>
            <input type="number" step="0.5" name="amount" id="bulkAmount" class="form-input" min="0" placeholder="e.g. 15 or 2" required>
            <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">This action will apply to all active firm associates simultaneously.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-secondary" onclick="closeModal('bulkAllocateModal')">Cancel</button>
          <button type="submit" class="btn-primary" id="btnConfirmBulk">Confirm &amp; Apply to All Staff</button>
        </div>
      </form>
    </div>
  </div>

  <!-- 10. QUICK ADJUST SINGLE CREDIT MODAL -->
  <div class="modal-backdrop" id="quickAdjustModal">
    <div class="modal-window">
      <div class="modal-header">
        <h3><i data-lucide="sliders-horizontal"></i> Quick Credit Adjustment</h3>
        <button class="btn-close-modal" onclick="closeModal('quickAdjustModal')">&times;</button>
      </div>
      <form id="quickAdjustForm" onsubmit="handleQuickAdjustSubmit(event)">
        <input type="hidden" name="action" value="adjust_user_credit">
        <input type="hidden" name="user_id" id="quickAdjUserId">
        <input type="hidden" name="leave_type_code" id="quickAdjCode">
        <div class="modal-body">
          <div style="background:var(--bg-subtle); padding:12px 14px; border-radius:var(--radius-md); margin-bottom:14px; border:1px solid var(--border-color);">
            <div style="font-size:12px; color:var(--text-muted);">Associate:</div>
            <strong style="font-size:15px; color:var(--primary);" id="quickAdjUserName">--</strong>
            <div style="font-size:12px; color:var(--text-muted); margin-top:6px;">Category: <span id="quickAdjCategoryName" style="font-weight:700; color:var(--text-main);">--</span></div>
            <div style="font-size:12px; color:var(--text-muted); margin-top:2px;">Current Balance: <span id="quickAdjCurrentBal" style="font-weight:800; color:var(--accent);">0</span> Days</div>
          </div>

          <div class="form-group">
            <label class="form-label">Adjustment Mode</label>
            <div style="display:flex; gap:12px; margin-bottom:10px;">
              <label style="display:flex; align-items:center; gap:6px; font-size:13px; cursor:pointer;">
                <input type="radio" name="adj_mode" value="delta" checked onchange="toggleQuickAdjMode(this.value)"> Relative (+ / -)
              </label>
              <label style="display:flex; align-items:center; gap:6px; font-size:13px; cursor:pointer;">
                <input type="radio" name="adj_mode" value="direct" onchange="toggleQuickAdjMode(this.value)"> Set Exact Total
              </label>
            </div>
          </div>

          <div class="form-group" id="quickAdjDeltaGroup">
            <label class="form-label">Add or Deduct Days</label>
            <input type="number" step="0.5" name="adjustment" id="quickAdjDeltaInput" class="form-input" placeholder="e.g. +2.0 or -1.0">
          </div>

          <div class="form-group" id="quickAdjDirectGroup" style="display:none;">
            <label class="form-label">New Total Remaining Days</label>
            <input type="number" step="0.5" name="new_remaining" id="quickAdjDirectInput" class="form-input" min="0" placeholder="e.g. 15.0">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-secondary" onclick="closeModal('quickAdjustModal')">Cancel</button>
          <button type="submit" class="btn-primary" id="btnSaveQuickAdj">Update Balance</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Custom Executive Confirmation Dialog Modal -->
  <div class="modal-backdrop" id="customConfirmModal" style="z-index: 99999;">
    <div class="modal-window narrow confirm-dialog-window" style="max-width: 440px;">
      <div class="modal-body" style="padding: 32px 26px 22px;">
        <div class="confirm-dialog-icon-wrap" id="confirmIconContainer">
          <i data-lucide="alert-triangle" style="width: 32px; height: 32px;" id="confirmIcon"></i>
        </div>
        <h3 class="confirm-dialog-title" id="confirmTitle">Confirm Action</h3>
        <p class="confirm-dialog-message" id="confirmMessage">Are you sure you want to proceed?</p>
      </div>
      <div class="modal-footer confirm-dialog-footer">
        <button type="button" class="btn-secondary" id="confirmCancelBtn" style="flex: 1; padding: 11px 18px; font-weight: 600;" onclick="resolveCustomConfirm(false)">Cancel</button>
        <button type="button" class="btn-primary" id="confirmOkBtn" style="flex: 1; padding: 11px 18px; font-weight: 700;" onclick="resolveCustomConfirm(true)">Confirm</button>
      </div>
    </div>
  </div>

  <div class="toast-container" id="toastContainer"></div>

  <!-- JavaScript Application Controller -->
  <script>
    // Live Philippine Standard Time
    function initLiveClock() {
      const clockEl = document.getElementById('liveClock');
      const update = () => {
        const now = new Date();
        const options = { timeZone: 'Asia/Manila', hour12: true, hour: '2-digit', minute: '2-digit', second: '2-digit', weekday: 'short', month: 'short', day: 'numeric' };
        clockEl.innerText = 'PHT: ' + now.toLocaleString('en-US', options);
      };
      update();
      setInterval(update, 1000);
    }
    initLiveClock();

    const allUsersData = <?= json_encode(array_column($allUsers, null, 'id')) ?>;
    const currentLoggedUserId = <?= (int)$user['id'] ?>;

    function switchTab(tabId, updateState = true) {
      const activePane = document.getElementById(`tab-${tabId}`);
      if (!activePane) tabId = 'overall';

      document.querySelectorAll('.sidebar-nav .nav-item').forEach(item => {
        item.classList.remove('active');
        if (item.getAttribute('data-tab') === tabId) item.classList.add('active');
      });
      document.querySelectorAll('.tab-pane').forEach(pane => pane.style.display = 'none');
      const targetPane = document.getElementById(`tab-${tabId}`);
      if (targetPane) targetPane.style.display = 'block';

      try {
        localStorage.setItem('jtyeo_admin_active_tab', tabId);
        if (updateState && history.replaceState) {
          history.replaceState(null, '', '#' + tabId);
        }
      } catch (e) {}

      if (tabId === 'overall') {
        if (typeof loadAdminOverallDashboard === 'function') loadAdminOverallDashboard();
      }
      if (tabId === 'calendar') {
        setTimeout(() => {
          if (typeof initCalendar === 'function') {
            if (!calendarInstance) {
              initCalendar();
            } else {
              calendarInstance.render();
            }
          }
        }, 50);
      }
      if (tabId === 'biometrics') {
        if (typeof loadDtrLogs === 'function') loadDtrLogs();
        if (typeof checkBiometricStatus === 'function') checkBiometricStatus();
      }
      if (window.lucide) lucide.createIcons();
    }

    function initSavedTab() {
      const urlParams = new URLSearchParams(window.location.search);
      const queryTab = urlParams.get('tab');
      const hashTab = window.location.hash.replace('#', '').trim();
      const savedTab = queryTab || hashTab || localStorage.getItem('jtyeo_admin_active_tab') || 'overall';
      if (savedTab && document.getElementById(`tab-${savedTab}`)) {
        switchTab(savedTab, false);
      } else {
        switchTab('overall', false);
      }
      if (savedTab === 'biometrics') {
        if (typeof loadDtrLogs === 'function') loadDtrLogs();
        if (typeof checkBiometricStatus === 'function') checkBiometricStatus();
      } else if (savedTab === 'overall') {
        if (typeof loadAdminOverallDashboard === 'function') loadAdminOverallDashboard();
      } else if (savedTab === 'calendar') {
        setTimeout(() => {
          if (typeof initCalendar === 'function') {
            if (!calendarInstance) {
              initCalendar();
            } else {
              calendarInstance.render();
            }
          }
        }, 50);
      }

      // Check for deep-linked leave approval from email notification
      const targetRef = urlParams.get('ref');
      if (targetRef && (savedTab === 'approvals' || queryTab === 'approvals')) {
        setTimeout(() => {
          const row = document.querySelector(`tr[data-ref="${targetRef}"]`);
          if (row) {
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            row.style.outline = '2px solid var(--primary)';
            row.style.backgroundColor = 'rgba(79, 70, 229, 0.08)';
            const reviewBtn = row.querySelector('button');
            if (reviewBtn) reviewBtn.click();
          }
        }, 350);
      }
    }

    // ==========================================
    // Overall Dashboard Functions (Admin)
    // ==========================================
    function loadAdminOverallDashboard() {
      fetch('actions/get_dashboard_summary.php')
        .then(res => res.json())
        .then(data => {
          if (!data.success) return;

          // 1. KPI Pulse counts
          if (data.counts) {
            const presEl = document.getElementById('adminOverallPresent');
            const brkEl = document.getElementById('adminOverallBreak');
            const lvEl = document.getElementById('adminOverallLeave');
            const pndEl = document.getElementById('adminOverallPending');
            if (presEl) presEl.innerText = data.counts.present_count;
            if (brkEl) brkEl.innerText = data.counts.on_break_count;
            if (lvEl) lvEl.innerText = data.counts.on_leave_count;
            if (pndEl) pndEl.innerText = data.counts.total_pending_actions;

            const badge = document.getElementById('adminActionCenterBadge');
            if (badge) {
              badge.innerText = data.counts.total_pending_actions + ' Pending';
              badge.className = data.counts.total_pending_actions > 0 ? 'badge badge-warning' : 'badge badge-success';
            }

            const breakdown = document.getElementById('adminPendingBreakdownText');
            if (breakdown) {
              breakdown.innerText = `${data.counts.pending_leaves} Leaves, ${data.counts.pending_corrections} Punches, ${data.counts.pending_ot} OT`;
            }
          }

          // 2. Render Action Center Items
          renderAdminActionCenter(data.pending_actions || []);

          // 3. Render Today's Punches
          renderAdminTodayPunches(data.today_punches || []);

          // 4. Update ZKTeco Hardware status
          if (data.zk_status) {
            const badge = document.getElementById('adminZkOnlineBadge');
            const lbl = document.getElementById('adminZkStatusLabel');
            if (badge && lbl) {
              if (data.zk_status.online) {
                badge.className = 'status-pill active';
                lbl.innerText = 'Online / LAN';
              } else {
                badge.className = 'status-pill inactive';
                lbl.innerText = 'Standby / LAN';
              }
            }
            const ls = document.getElementById('adminZkLastSeenText');
            if (ls && data.zk_status.last_seen) {
              ls.innerHTML = `<strong>Last Sync:</strong> ${data.zk_status.last_seen}`;
            }
            const ipEl = document.getElementById('adminZkIpDisplay');
            if (ipEl && data.zk_status.ip) {
              ipEl.innerText = data.zk_status.ip + (data.zk_status.ip !== 'Auto-detecting...' && !data.zk_status.ip.includes(':') ? ':4370' : '');
            }
            const snEl = document.getElementById('adminZkSerialDisplay');
            if (snEl && data.zk_status.sn) {
              snEl.innerText = data.zk_status.sn;
            }
          }

          // 5. Render Live Team Presence Roster
          renderAdminOverallPresence();

          // 6. Render Upcoming Schedule
          renderAdminUpcomingSchedule(data.upcoming_holidays || [], data.upcoming_leaves || []);

          if (window.lucide) lucide.createIcons();
        })
        .catch(err => {
          console.error('Error loading overall dashboard:', err);
        });
    }

    function renderAdminActionCenter(items) {
      const container = document.getElementById('adminActionCenterList');
      if (!container) return;

      if (!items || items.length === 0) {
        container.innerHTML = `
          <div style="text-align:center; padding: 32px 16px; color: var(--text-muted);">
            <div style="width:48px; height:48px; border-radius:50%; background:#f0fdf4; display:flex; align-items:center; justify-content:center; margin: 0 auto 12px; color:#16a34a;">
              <i data-lucide="check-check" style="width:24px; height:24px;"></i>
            </div>
            <div style="font-weight:700; color:var(--text-main); font-size:14px; margin-bottom:4px;">Approvals Queue is Clear!</div>
            <div style="font-size:12.5px;">All leave requests, missed punch adjustments, and overtime forms have been reviewed.</div>
          </div>
        `;
        if (window.lucide) lucide.createIcons();
        return;
      }

      container.innerHTML = items.map(item => {
        let badgeClass = 'leave';
        let badgeLabel = 'Leave Request';
        let iconName = 'palmtree';
        let detailHtml = '';
        let actionButtonsHtml = '';

        if (item.item_type === 'leave') {
          badgeClass = 'leave';
          badgeLabel = item.title || 'Leave Request';
          iconName = 'calendar';
          detailHtml = `
            <div class="action-item-desc">
              <strong>${item.days_count} Day(s)</strong> &bull; ${item.start_date} to ${item.end_date}
            </div>
            ${item.reason ? `<div class="action-item-reason">"${item.reason}"</div>` : ''}
          `;
          actionButtonsHtml = `
            <button class="btn-primary btn-sm" onclick="switchTab('approvals')">
              <i data-lucide="check-square" style="width:12px; height:12px;"></i> Review in Queue &rarr;
            </button>
          `;
        } else if (item.item_type === 'correction') {
          badgeClass = 'correction';
          badgeLabel = 'Missed Punch Adjustment';
          iconName = 'clock';
          detailHtml = `
            <div class="action-item-desc">
              <strong>Date:</strong> ${item.target_date} &bull; 
              ${item.time_in ? 'In: ' + item.time_in : ''} 
              ${item.break_out ? '| B.Out: ' + item.break_out : ''} 
              ${item.break_in ? '| B.In: ' + item.break_in : ''} 
              ${item.time_out ? '| Out: ' + item.time_out : ''}
            </div>
            ${item.reason ? `<div class="action-item-reason">"${item.reason}"</div>` : ''}
          `;
          actionButtonsHtml = `
            <button class="btn-primary btn-sm" onclick="switchTab('biometrics'); loadDtrLogs();">
              <i data-lucide="check-square" style="width:12px; height:12px;"></i> Review in Attendance &rarr;
            </button>
          `;
        } else if (item.item_type === 'ot') {
          badgeClass = 'ot';
          badgeLabel = 'Overtime Pre-Authorization';
          iconName = 'trending-up';
          detailHtml = `
            <div class="action-item-desc">
              <strong>${item.estimated_hours} Hours OT</strong> on ${item.ot_date}
            </div>
            ${item.reason ? `<div class="action-item-reason">"${item.reason}"</div>` : ''}
          `;
          actionButtonsHtml = `
            <button class="btn-primary btn-sm" onclick="switchTab('biometrics'); loadDtrLogs();">
              <i data-lucide="check-square" style="width:12px; height:12px;"></i> Review in Attendance &rarr;
            </button>
          `;
        }

        const avatarMarkup = item.avatar_path 
          ? `<img src="${item.avatar_path}" alt="Avatar" style="width:36px; height:36px; border-radius:50%; object-fit:cover;">`
          : `<div style="width:36px; height:36px; border-radius:50%; background:var(--primary); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:12px;">${item.avatar_initials || 'A'}</div>`;

        return `
          <div class="action-item-card">
            <div class="action-item-left">
              ${avatarMarkup}
              <div class="action-item-meta">
                <span class="action-item-type-badge ${badgeClass}">
                  <i data-lucide="${iconName}" style="width:11px; height:11px;"></i>
                  ${badgeLabel}
                </span>
                <div class="action-item-title">${item.employee_name} <span style="font-size:11.5px; font-weight:normal; color:var(--text-muted);">&bull; ${item.employee_title || 'Associate'}</span></div>
                ${detailHtml}
              </div>
            </div>
            <div class="action-item-actions">
              ${actionButtonsHtml}
            </div>
          </div>
        `;
      }).join('');

      if (window.lucide) lucide.createIcons();
    }

    function adminQuickDecideLeave(refNo, decision) {
      showConfirmDialog({
        title: `${decision} Leave Application?`,
        message: `Are you sure you want to mark leave reference ${refNo} as ${decision}?`,
        confirmText: `${decision} Leave`,
        isDanger: decision === 'Rejected'
      }).then(confirmed => {
        if (!confirmed) return;
        fetch('actions/decide_leave.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ ref_no: refNo, decision: decision, reason: '' })
        })
        .then(res => res.json())
        .then(data => {
          if (data.success) {
            showToast(data.message || `Leave ${decision.toLowerCase()} successfully.`, 'success');
            loadAdminOverallDashboard();
          } else {
            showToast(data.message || 'Error processing decision.', 'error');
          }
        })
        .catch(err => {
          showToast('Network error processing decision.', 'error');
        });
      });
    }

    function adminQuickDecideCorrection(id, actionType) {
      const isApprove = (actionType === 'approve');
      showConfirmDialog({
        title: `${isApprove ? 'Approve' : 'Reject'} Attendance Adjustment?`,
        message: `Are you sure you want to ${isApprove ? 'approve and apply' : 'reject'} this punch correction?`,
        confirmText: isApprove ? 'Approve & Apply' : 'Reject',
        isDanger: !isApprove
      }).then(confirmed => {
        if (!confirmed) return;
        const formData = new FormData();
        formData.append('action', isApprove ? 'approve_correction' : 'reject_correction');
        formData.append('id', id);

        fetch('actions/manage_attendance_corrections.php', {
          method: 'POST',
          body: formData
        })
        .then(res => res.json())
        .then(data => {
          if (data.success) {
            showToast(data.message, 'success');
            loadAdminOverallDashboard();
          } else {
            showToast(data.message, 'error');
          }
        })
        .catch(err => {
          showToast('Network error processing adjustment.', 'error');
        });
      });
    }

    function adminQuickDecideOt(id, actionType) {
      const isApprove = (actionType === 'approve');
      showConfirmDialog({
        title: `${isApprove ? 'Approve' : 'Reject'} Overtime Request?`,
        message: `Are you sure you want to ${isApprove ? 'pre-approve' : 'reject'} this overtime request?`,
        confirmText: isApprove ? 'Approve Overtime' : 'Reject',
        isDanger: !isApprove
      }).then(confirmed => {
        if (!confirmed) return;
        const formData = new FormData();
        formData.append('action', isApprove ? 'approve_ot' : 'reject_ot');
        formData.append('id', id);

        fetch('actions/manage_overtime.php', {
          method: 'POST',
          body: formData
        })
        .then(res => res.json())
        .then(data => {
          if (data.success) {
            showToast(data.message, 'success');
            loadAdminOverallDashboard();
          } else {
            showToast(data.message, 'error');
          }
        })
        .catch(err => {
          showToast('Network error processing overtime.', 'error');
        });
      });
    }

    function renderAdminTodayPunches(punches) {
      const tbody = document.getElementById('adminTodayPunchesTableBody');
      if (!tbody) return;

      if (!punches || punches.length === 0) {
        tbody.innerHTML = `
          <tr>
            <td colspan="7" style="text-align:center; padding: 24px; color: var(--text-muted);">
              No biometric punches recorded yet today. Associates will appear automatically upon scanning.
            </td>
          </tr>
        `;
        return;
      }

      tbody.innerHTML = punches.map(p => {
        let statusBadge = '<span class="status-pill active"><span class="status-dot"></span> Present</span>';
        if (p.time_out) {
          statusBadge = '<span class="status-pill info"><span class="status-dot"></span> Completed</span>';
        } else if (p.break_out && !p.break_in) {
          statusBadge = '<span class="status-pill warning"><span class="status-dot"></span> On Break</span>';
        }

        const avatarMarkup = p.avatar_path 
          ? `<img src="${p.avatar_path}" alt="Avatar" style="width:28px; height:28px; border-radius:50%; object-fit:cover;">`
          : `<div style="width:28px; height:28px; border-radius:50%; background:var(--primary); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:11px;">${p.avatar_initials || 'A'}</div>`;

        return `
          <tr>
            <td>
              <div style="display:flex; align-items:center; gap:8px;">
                ${avatarMarkup}
                <div>
                  <div style="font-weight:600; color:var(--text-main);">${p.name}</div>
                  <div style="font-size:11px; color:var(--text-muted);">${p.title || 'Associate'}</div>
                </div>
              </div>
            </td>
            <td><strong>${p.time_in_12 || '&mdash;'}</strong></td>
            <td>${p.break_out_12 || '&mdash;'}</td>
            <td>${p.break_in_12 || '&mdash;'}</td>
            <td><strong>${p.time_out_12 || '&mdash;'}</strong></td>
            <td><strong style="color:var(--primary);">${p.rendered_formatted || '0.00 hrs'}</strong></td>
            <td>${statusBadge}</td>
          </tr>
        `;
      }).join('');
    }

    function renderAdminOverallPresence() {
      fetch('actions/get_presence.php')
        .then(res => res.json())
        .then(data => {
          if (!data.success) return;
          const container = document.getElementById('adminOverallPresenceRoster');
          if (!container) return;

          if (!data.roster || data.roster.length === 0) {
            container.innerHTML = '<div style="text-align:center; padding:12px; color:var(--text-muted); font-size:12px;">No active roster available.</div>';
            return;
          }

          container.innerHTML = data.roster.map(u => {
            const avatarMarkup = u.avatar_path 
              ? `<img src="${u.avatar_path}" alt="Avatar" style="width:28px; height:28px; border-radius:50%; object-fit:cover;">`
              : `<div style="width:28px; height:28px; border-radius:50%; background:var(--primary); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:10px;">${u.avatar_initials || 'A'}</div>`;

            let pillClass = 'inactive';
            if (u.state === 'present') pillClass = 'active';
            else if (u.state === 'on_break') pillClass = 'warning';
            else if (u.state === 'on_leave') pillClass = 'info';

            return `
              <div class="presence-roster-item">
                <div class="presence-roster-user">
                  ${avatarMarkup}
                  <div class="user-meta">
                    <span class="name">${u.name}</span>
                    <span class="title">${u.title || 'Associate'}</span>
                  </div>
                </div>
                <span class="status-pill ${pillClass}" style="font-size:10.5px; padding: 2px 7px;">
                  <span class="status-dot"></span>
                  <span>${u.state_label}</span>
                </span>
              </div>
            `;
          }).join('');
        })
        .catch(err => {
          console.error('Presence roster fetch error:', err);
        });
    }

    function renderAdminUpcomingSchedule(holidays, leaves) {
      const container = document.getElementById('adminUpcomingScheduleList');
      if (!container) return;

      const hasHolidays = holidays && holidays.length > 0;
      const hasLeaves = leaves && leaves.length > 0;

      if (!hasHolidays && !hasLeaves) {
        container.innerHTML = '<div style="text-align:center; padding:16px; color:var(--text-muted); font-size:12px;">No upcoming leaves or Philippine statutory holidays in the next 7 days.</div>';
        return;
      }

      let html = '';
      if (hasHolidays) {
        holidays.forEach(h => {
          html += `
            <div style="display:flex; align-items:flex-start; gap:10px; padding:8px 10px; border-radius:6px; background:#fef3c7; border:1px solid #fde68a;">
              <i data-lucide="flag" style="width:14px; height:14px; color:#b45309; flex-shrink:0; margin-top:2px;"></i>
              <div>
                <div style="font-size:12px; font-weight:700; color:#92400e;">${h.title}</div>
                <div style="font-size:11px; color:#b45309;">${h.holiday_date} &bull; ${h.holiday_type || 'Regular Holiday'}</div>
              </div>
            </div>
          `;
        });
      }

      if (hasLeaves) {
        leaves.forEach(lv => {
          html += `
            <div style="display:flex; align-items:flex-start; gap:10px; padding:8px 10px; border-radius:6px; background:#eff6ff; border:1px solid #bfdbfe;">
              <i data-lucide="palmtree" style="width:14px; height:14px; color:#2563eb; flex-shrink:0; margin-top:2px;"></i>
              <div>
                <div style="font-size:12px; font-weight:700; color:#1e40af;">${lv.employee_name}</div>
                <div style="font-size:11px; color:#2563eb;">${lv.leave_type_label} &bull; ${lv.start_date} to ${lv.end_date}</div>
              </div>
            </div>
          `;
        });
      }

      container.innerHTML = html;
      if (window.lucide) lucide.createIcons();
    }

    function openModal(id) { 
      document.getElementById(id).classList.add('show'); 
      if (id === 'applyModal' && typeof calculateWorkingDaysPreview === 'function') {
        calculateWorkingDaysPreview();
      }
      if (id === 'addUserModal') {
        const form = document.getElementById('addUserForm');
        if (form) form.reset();
        const faceEl = document.getElementById('addFaceEnrolled');
        const fpEl = document.getElementById('addFpEnrolled');
        if (faceEl) faceEl.checked = false;
        if (fpEl) fpEl.checked = false;
        const statusEl = document.getElementById('pinDetectionStatus');
        if (statusEl) statusEl.innerHTML = '';
        const badgeEl = document.getElementById('enrollAutoBadge');
        if (badgeEl) {
          badgeEl.innerHTML = 'Auto-updates on first punch';
          badgeEl.style.color = 'var(--accent)';
        }
      }
      if (window.lucide) lucide.createIcons(); 
    }
    function closeModal(id) { document.getElementById(id).classList.remove('show'); }

    // Custom Modal Confirmation Promise Controller
    let confirmResolver = null;
    function showConfirmDialog({
      title = 'Are you sure?',
      message = 'Please confirm this action to proceed.',
      confirmText = 'Confirm',
      cancelText = 'Cancel',
      isDanger = false,
      icon = 'alert-triangle'
    } = {}) {
      return new Promise((resolve) => {
        confirmResolver = resolve;
        document.getElementById('confirmTitle').innerText = title;
        document.getElementById('confirmMessage').innerText = message;
        const okBtn = document.getElementById('confirmOkBtn');
        const cancelBtn = document.getElementById('confirmCancelBtn');
        okBtn.innerText = confirmText;
        cancelBtn.innerText = cancelText;

        const iconContainer = document.getElementById('confirmIconContainer');
        if (isDanger) {
          iconContainer.style.background = 'rgba(220, 0, 0, 0.12)';
          iconContainer.style.color = '#dc0000';
          okBtn.className = 'btn-primary';
          okBtn.style.background = 'linear-gradient(135deg, #dc0000 0%, #8b0e14 100%)';
        } else {
          iconContainer.style.background = 'rgba(220, 0, 0, 0.08)';
          iconContainer.style.color = 'var(--primary)';
          okBtn.className = 'btn-primary';
          okBtn.style.background = '';
        }

        const iconEl = document.getElementById('confirmIcon');
        if (iconEl) {
          iconEl.setAttribute('data-lucide', icon);
          if (window.lucide) lucide.createIcons();
        }

        openModal('customConfirmModal');
      });
    }

    function resolveCustomConfirm(result) {
      closeModal('customConfirmModal');
      if (confirmResolver) {
        confirmResolver(result);
        confirmResolver = null;
      }
    }

    // Dismiss modal on backdrop click or ESC key
    document.addEventListener('click', function(e) {
      if (e.target.classList && e.target.classList.contains('modal-backdrop')) {
        if (e.target.id === 'customConfirmModal') {
          resolveCustomConfirm(false);
        } else {
          e.target.classList.remove('show');
        }
      }
    });

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        const activeModal = document.querySelector('.modal-backdrop.show');
        if (activeModal) {
          if (activeModal.id === 'customConfirmModal') {
            resolveCustomConfirm(false);
          } else {
            activeModal.classList.remove('show');
          }
        }
      }
    });

    function showToast(msg, type = 'info') {
      const container = document.getElementById('toastContainer');
      const toast = document.createElement('div');
      toast.className = `toast ${type}`;
      toast.innerHTML = `<div style="font-size:13px; font-weight:600;">${msg}</div>`;
      container.appendChild(toast);
      setTimeout(() => { toast.style.opacity = '0'; toast.style.transform = 'translateX(100%)'; setTimeout(() => toast.remove(), 300); }, 3500);
    }

    // Gender rules on leave type dropdown
    function handleTargetUserChange() {
      const userSelect = document.getElementById('applyTargetUser');
      if (!userSelect || !userSelect.options.length) return;
      const selectedOption = userSelect.options[userSelect.selectedIndex];
      if (!selectedOption) return;
      const gender = (selectedOption.getAttribute('data-gender') || 'Female').toLowerCase();

      const leaveTypeSelect = document.getElementById('applyLeaveType');
      if (leaveTypeSelect) {
        let currentValid = true;
        Array.from(leaveTypeSelect.options).forEach(opt => {
          const reqGender = (opt.getAttribute('data-gender') || 'All').toLowerCase();
          if (reqGender === 'female' && gender === 'male') {
            opt.disabled = true;
            if (leaveTypeSelect.value === opt.value) currentValid = false;
          } else if (reqGender === 'male' && gender === 'female') {
            opt.disabled = true;
            if (leaveTypeSelect.value === opt.value) currentValid = false;
          } else {
            opt.disabled = false;
          }
        });

        if (!currentValid) {
          const firstEnabled = Array.from(leaveTypeSelect.options).find(o => !o.disabled);
          if (firstEnabled) leaveTypeSelect.value = firstEnabled.value;
        }
      }
      calculateWorkingDaysPreview();
    }

    // Working days calculator with automatic weekend & holiday discount
    function calculateWorkingDaysPreview() {
      const startStr = document.getElementById('applyStartDate').value;
      const endStr = document.getElementById('applyEndDate').value;
      const type = document.getElementById('applyLeaveType').value;
      const userId = document.getElementById('applyTargetUser').value;

      // Dynamically display attachment field if policy requires proof
      const selectEl = document.getElementById('applyLeaveType');
      const selectedOpt = selectEl && selectEl.selectedIndex >= 0 ? selectEl.options[selectEl.selectedIndex] : null;
      const requiresProof = selectedOpt ? (selectedOpt.getAttribute('data-proof') === '1') : false;

      const attGroup = document.getElementById('attachmentGroup');
      const attInput = document.getElementById('applyAttachment');
      const attLabel = document.getElementById('attachmentLabel');
      const attHelp = document.getElementById('attachmentHelpText');

      if (attGroup) {
        if (requiresProof) {
          attGroup.style.display = 'block';
          if (attInput) attInput.required = true;
          if (attLabel) {
            attLabel.innerHTML = `<i data-lucide="paperclip" style="width:13px;height:13px;"></i> Attach Supporting Document / Proof <span class="req">*</span>`;
          }
          if (attHelp) {
            attHelp.innerText = `Supporting document is required for ${selectedOpt ? selectedOpt.innerText.split('(')[0].trim() : 'this policy'}. (PDF, JPG, PNG, DOC)`;
          }
          if (window.lucide) lucide.createIcons();
        } else {
          attGroup.style.display = 'none';
          if (attInput) {
            attInput.required = false;
            attInput.value = '';
          }
        }
      }

      const user = allUsersData[userId];
      const balEl = document.getElementById('availableBalancePreview');

      if (user && balEl) {
        const balMap = {
          'VL': user.vl_balance,
          'SL': user.sl_balance,
          'Emergency': user.emergency_balance,
          'Bereavement': user.bereavement_balance,
          'SoloParent': user.solo_parent_balance,
          'Maternity': user.maternity_balance,
          'Paternity': user.paternity_balance,
          'SpecialWomen': user.special_women_balance
        };
        const val = balMap[type];
        balEl.innerText = (val !== undefined) ? `${parseFloat(val).toFixed(1)} Days` : (type === 'LWOP' ? 'Unlimited (Unpaid)' : '--');
      }

      if (!startStr || !endStr) return;
      const start = new Date(startStr);
      const end = new Date(endStr);
      if (start > end) {
        document.getElementById('computedDaysPreview').innerText = 'Invalid Range';
        return;
      }

      // Compute days excluding weekends
      let workingDays = 0;
      let curr = new Date(start);
      while (curr <= end) {
        const day = curr.getDay();
        if (day !== 0 && day !== 6) workingDays++;
        curr.setDate(curr.getDate() + 1);
      }
      document.getElementById('computedDaysPreview').innerText = `${workingDays} Working Day(s)`;
    }

    // Initialize default dates
    const todayStr = '<?= $today ?>';
    document.getElementById('applyStartDate').value = todayStr;
    document.getElementById('applyEndDate').value = todayStr;
    handleTargetUserChange();

    // Decision Modal
    let currentDecRef = '';
    function openDecisionModal(refNo, empName, leaveType, days, reason = '', dates = '', attachment = '') {
      currentDecRef = refNo;
      document.getElementById('decModalRef').innerText = refNo;
      document.getElementById('decModalStaff').innerText = empName || '—';
      document.getElementById('decModalType').innerText = leaveType || '—';
      document.getElementById('decModalDays').innerText = days || '0';

      const datesEl = document.getElementById('decModalDates');
      if (datesEl) datesEl.innerText = dates || '—';

      const reasonEl = document.getElementById('decModalReason');
      if (reasonEl) {
        reasonEl.innerText = (reason && reason.trim()) ? `"${reason.trim()}"` : 'No specific reason stated.';
      }

      const attWrap = document.getElementById('decModalAttachmentWrap');
      const attLink = document.getElementById('decModalAttachmentLink');
      if (attWrap && attLink) {
        if (attachment && attachment.trim()) {
          attLink.href = attachment.trim();
          attWrap.style.display = 'block';
        } else {
          attWrap.style.display = 'none';
        }
      }

      document.getElementById('decModalNote').value = '';
      openModal('decisionModal');
      if (window.lucide) lucide.createIcons();
    }

    async function executeDecisionWithNote(decision) {
      const note = document.getElementById('decModalNote').value.trim();
      try {
        const res = await fetch('actions/decide_leave.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ ref_no: currentDecRef, decision: decision, reason: note })
        });
        const data = await res.json();
        if (data.success) {
          showToast(data.message, 'success');
          closeModal('decisionModal');
          setTimeout(() => window.location.reload(), 600);
        } else {
          showToast(data.message || 'Error processing decision.', 'error');
        }
      } catch (err) {
        showToast('Network communication error.', 'error');
      }
    }

    // Submit Leave Application
    async function handleBackendLeaveSubmit(e) {
      e.preventDefault();
      const form = document.getElementById('phpLeaveForm');
      const formData = new FormData(form);
      const btn = document.getElementById('btnSubmitLeave');
      btn.disabled = true;
      btn.innerText = 'Submitting...';
      try {
        const res = await fetch('actions/apply_leave.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
          showToast(data.message, 'success');
          closeModal('applyModal');
          setTimeout(() => window.location.reload(), 800);
        } else {
          showToast(data.message || 'Error submitting application', 'error');
          btn.disabled = false;
          btn.innerText = 'Submit Application';
        }
      } catch (err) {
        showToast('Network error.', 'error');
        btn.disabled = false;
        btn.innerText = 'Submit Application';
      }
    }

    // Details Modal
    let currentDetailsSlipData = null;
    function viewDetailsModal(ref, emp, title, type, days, start, end, reason, status, approver, rejectReason, attachmentPath) {
      document.getElementById('dtlRef').innerText = ref;
      document.getElementById('dtlStaff').innerText = emp;
      document.getElementById('dtlTitle').innerText = title;
      document.getElementById('dtlType').innerText = type;
      document.getElementById('dtlDates').innerText = (start === end) ? start : `${start} to ${end}`;
      document.getElementById('dtlDays').innerText = `${days} Working Day(s)`;
      document.getElementById('dtlReason').innerText = reason || 'No additional notes provided.';

      let statusBadge = `<span class="badge badge-pending">${status}</span>`;
      if (status === 'Approved') statusBadge = `<span class="badge badge-approved">Approved</span>`;
      if (status === 'Rejected') statusBadge = `<span class="badge badge-rejected">Rejected</span>`;
      document.getElementById('dtlStatusBadge').innerHTML = statusBadge;

      const appEl = document.getElementById('dtlApprover');
      const rejEl = document.getElementById('dtlRejectionReason');

      if (status === 'Approved') {
        appEl.innerHTML = `<span style="color:var(--success);"><i data-lucide="check-circle" style="width:14px;height:14px;display:inline-block;vertical-align:middle;margin-right:4px;"></i> Approved by ${approver || 'Atty. Jonathan Yeo, CPA'}</span>`;
        if (rejEl) rejEl.style.display = 'none';
        document.getElementById('dtlPrintBtn').style.display = 'inline-flex';
      } else if (status === 'Rejected') {
        appEl.innerHTML = `<span style="color:var(--danger);"><i data-lucide="x-circle" style="width:14px;height:14px;display:inline-block;vertical-align:middle;margin-right:4px;"></i> Rejected by ${approver || 'Managing Partner'}</span>`;
        if (rejEl && rejectReason) {
          rejEl.innerText = `Note: ${rejectReason}`;
          rejEl.style.display = 'block';
        } else if (rejEl) {
          rejEl.style.display = 'none';
        }
        document.getElementById('dtlPrintBtn').style.display = 'none';
      } else {
        appEl.innerHTML = `<span style="color:var(--warning);"><i data-lucide="clock" style="width:14px;height:14px;display:inline-block;vertical-align:middle;margin-right:4px;"></i> Pending Review by Managing Partner</span>`;
        if (rejEl) rejEl.style.display = 'none';
        document.getElementById('dtlPrintBtn').style.display = 'none';
      }

      // Attachment handling
      const attRow = document.getElementById('dtlAttachmentRow');
      const attLink = document.getElementById('dtlAttachmentLink');
      if (attachmentPath && attachmentPath.length > 3) {
        attLink.href = attachmentPath;
        attRow.style.display = 'block';
      } else {
        attRow.style.display = 'none';
      }

      currentDetailsSlipData = { ref, emp, title, type, days, start, end, reason, approver };
      document.getElementById('dtlPrintBtn').onclick = () => {
        closeModal('detailsModal');
        printOfficialSlip(ref, emp, title, type, days, start, end, reason, approver);
      };

      openModal('detailsModal');
    }

    // Print Official Slip
    function printOfficialSlip(ref, emp, title, type, days, start, end, reason, approver) {
      document.getElementById('slipRef').innerText = ref;
      document.getElementById('slipStaff').innerText = emp;
      document.getElementById('slipTitle').innerText = title;
      document.getElementById('slipType').innerText = type;
      document.getElementById('slipDates').innerText = (start === end) ? start : `${start} to ${end}`;
      document.getElementById('slipDays').innerText = `${days} Working Day(s)`;
      document.getElementById('slipReason').innerText = reason || 'Standard client coverage assigned.';
      document.getElementById('slipStaffSign').innerText = emp;
      document.getElementById('slipApproverSign').innerText = approver || 'Atty. Jonathan Yeo, CPA';
      openModal('printableSlipModal');
    }

    // Manual Balance Adjustment Modal
    function openAdjustmentModal(userId = 0) {
      if (userId > 0) document.getElementById('adjUserId').value = userId;
      document.getElementById('adjAmount').value = '';
      openModal('adjModal');
    }

    async function handleManualAdjustment(e) {
      e.preventDefault();
      const form = document.getElementById('adjForm');
      const formData = new FormData(form);
      const btn = document.getElementById('btnSaveAdj');
      btn.disabled = true;
      btn.innerText = 'Saving...';
      try {
        const res = await fetch('actions/adjust_balance.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
          showToast(data.message, 'success');
          closeModal('adjModal');
          setTimeout(() => window.location.reload(), 700);
        } else {
          showToast(data.message || 'Error updating balance', 'error');
          btn.disabled = false;
          btn.innerText = 'Save Adjustment';
        }
      } catch (err) {
        showToast('Network error.', 'error');
        btn.disabled = false;
        btn.innerText = 'Save Adjustment';
      }
    }

    // Leave Policies & Entitlements Management JS Handlers
    function autoGenerateCode(name) {
      const codeInput = document.getElementById('newTypeCode');
      if (codeInput && !codeInput.dataset.touched) {
        codeInput.value = name.replace(/[^a-zA-Z0-9]/g, '').slice(0, 8).toUpperCase();
      }
    }
    document.getElementById('newTypeCode')?.addEventListener('input', function() { this.dataset.touched = 'true'; });

    async function handleAddLeaveTypeSubmit(e) {
      e.preventDefault();
      const form = document.getElementById('addLeaveTypeForm');
      const formData = new FormData(form);
      const btn = document.getElementById('btnSaveNewType');
      btn.disabled = true;
      btn.innerText = 'Creating...';
      try {
        const res = await fetch('actions/manage_leave_types.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
          showToast(data.message, 'success');
          closeModal('addLeaveTypeModal');
          setTimeout(() => window.location.reload(), 700);
        } else {
          showToast(data.message || 'Failed to create leave category', 'error');
          btn.disabled = false;
          btn.innerText = 'Create & Allocate';
        }
      } catch (err) {
        showToast('Network error.', 'error');
        btn.disabled = false;
        btn.innerText = 'Create & Allocate';
      }
    }

    function openEditLeaveTypeModal(lt) {
      document.getElementById('editTypeId').value = lt.id;
      document.getElementById('editTypeName').value = lt.name;
      document.getElementById('editTypeCodeDisplay').value = lt.code;
      document.getElementById('editTypeDescription').value = lt.description || '';
      document.getElementById('editTypeDefaultDays').value = lt.default_days;
      document.getElementById('editTypeIsPaid').value = lt.is_paid;
      document.getElementById('editTypeGender').value = lt.gender_restriction;
      document.getElementById('editTypeProof').value = lt.requires_attachment;
      document.getElementById('editTypeColor').value = lt.color || '#dc0000';
      openModal('editLeaveTypeModal');
    }

    async function handleEditLeaveTypeSubmit(e) {
      e.preventDefault();
      const form = document.getElementById('editLeaveTypeForm');
      const formData = new FormData(form);
      const btn = document.getElementById('btnSaveEditType');
      btn.disabled = true;
      btn.innerText = 'Saving...';
      try {
        const res = await fetch('actions/manage_leave_types.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
          showToast(data.message, 'success');
          closeModal('editLeaveTypeModal');
          setTimeout(() => window.location.reload(), 700);
        } else {
          showToast(data.message || 'Error updating policy', 'error');
          btn.disabled = false;
          btn.innerText = 'Update Policy';
        }
      } catch (err) {
        showToast('Network error.', 'error');
        btn.disabled = false;
        btn.innerText = 'Update Policy';
      }
    }

    async function toggleLeaveTypeStatus(id, newStatus) {
      const actionName = newStatus ? 'Activate' : 'Archive';
      const confirmed = await showConfirmDialog({
        title: `${actionName} Leave Policy`,
        message: `Are you sure you want to ${actionName.toLowerCase()} this leave policy? Inactive policies cannot be selected by associates when filing leave.`,
        confirmText: `${actionName} Policy`,
        cancelText: 'Cancel',
        isDanger: !newStatus,
        icon: newStatus ? 'check-circle' : 'archive'
      });
      if (!confirmed) return;

      try {
        const res = await fetch('actions/manage_leave_types.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'toggle_status', id: id, is_active: newStatus })
        });
        const data = await res.json();
        if (data.success) {
          showToast(data.message, 'success');
          setTimeout(() => window.location.reload(), 700);
        } else {
          showToast(data.message || 'Error toggling status', 'error');
        }
      } catch (err) {
        showToast('Network error', 'error');
      }
    }

    async function deleteLeaveType(id, name) {
      const confirmed = await showConfirmDialog({
        title: 'Delete Leave Policy',
        message: `Are you sure you want to permanently delete '${name}'? This cannot be undone. Policies with historical employee records cannot be deleted and must be Archived instead.`,
        confirmText: 'Delete Policy',
        cancelText: 'Cancel',
        isDanger: true,
        icon: 'trash-2'
      });
      if (!confirmed) return;

      try {
        const res = await fetch('actions/manage_leave_types.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'delete', id: id })
        });
        const data = await res.json();
        if (data.success) {
          showToast(data.message, 'success');
          setTimeout(() => window.location.reload(), 700);
        } else {
          showToast(data.message || 'Error deleting policy', 'error');
        }
      } catch (err) {
        showToast('Network error while deleting policy', 'error');
      }
    }

    function triggerBulkForCode(code, defaultDays) {
      const select = document.getElementById('bulkTypeCode');
      if (select) select.value = code;
      const amtInput = document.getElementById('bulkAmount');
      if (amtInput) amtInput.value = defaultDays;
      document.getElementById('bulkMode').value = 'set';
      openModal('bulkAllocateModal');
    }

    async function handleBulkAllocateSubmit(e) {
      e.preventDefault();
      const form = document.getElementById('bulkAllocateForm');
      const formData = new FormData(form);
      const btn = document.getElementById('btnConfirmBulk');

      const confirmed = await showConfirmDialog({
        title: 'Bulk Apply Allocation',
        message: 'Are you sure you want to apply this allocation to ALL active associates in the firm? Balances will be updated immediately.',
        confirmText: 'Yes, Apply to All',
        cancelText: 'Cancel',
        isDanger: true,
        icon: 'users'
      });
      if (!confirmed) return;

      btn.disabled = true;
      btn.innerText = 'Applying...';
      try {
        const res = await fetch('actions/manage_leave_types.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
          showToast(data.message, 'success');
          closeModal('bulkAllocateModal');
          setTimeout(() => window.location.reload(), 700);
        } else {
          showToast(data.message || 'Failed to bulk allocate', 'error');
          btn.disabled = false;
          btn.innerText = 'Confirm & Apply to All Staff';
        }
      } catch (err) {
        showToast('Network error.', 'error');
        btn.disabled = false;
        btn.innerText = 'Confirm & Apply to All Staff';
      }
    }

    function openQuickAdjustModal(userId, userName, code, typeName, currentBal) {
      document.getElementById('quickAdjUserId').value = userId;
      document.getElementById('quickAdjUserName').innerText = userName;
      document.getElementById('quickAdjCode').value = code;
      document.getElementById('quickAdjCategoryName').innerText = typeName;
      document.getElementById('quickAdjCurrentBal').innerText = currentBal;
      document.getElementById('quickAdjDeltaInput').value = '';
      document.getElementById('quickAdjDirectInput').value = currentBal;
      openModal('quickAdjustModal');
    }

    function toggleQuickAdjMode(mode) {
      document.getElementById('quickAdjDeltaGroup').style.display = (mode === 'delta') ? 'block' : 'none';
      document.getElementById('quickAdjDirectGroup').style.display = (mode === 'direct') ? 'block' : 'none';
    }

    async function handleQuickAdjustSubmit(e) {
      e.preventDefault();
      const form = document.getElementById('quickAdjustForm');
      const mode = form.querySelector('input[name="adj_mode"]:checked').value;
      const userId = document.getElementById('quickAdjUserId').value;
      const code = document.getElementById('quickAdjCode').value;
      const btn = document.getElementById('btnSaveQuickAdj');

      const payload = {
        action: 'adjust_user_credit',
        user_id: userId,
        leave_type_code: code
      };

      if (mode === 'delta') {
        const delta = parseFloat(document.getElementById('quickAdjDeltaInput').value);
        if (isNaN(delta) || delta === 0) {
          showToast('Please enter an adjustment amount (e.g. +2 or -1).', 'error');
          return;
        }
        payload.adjustment = delta;
      } else {
        const direct = parseFloat(document.getElementById('quickAdjDirectInput').value);
        if (isNaN(direct)) {
          showToast('Please enter a valid balance total.', 'error');
          return;
        }
        payload.new_remaining = direct;
      }

      btn.disabled = true;
      btn.innerText = 'Saving...';
      try {
        const res = await fetch('actions/manage_leave_types.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) {
          showToast(data.message, 'success');
          closeModal('quickAdjustModal');
          setTimeout(() => window.location.reload(), 700);
        } else {
          showToast(data.message || 'Adjustment failed', 'error');
          btn.disabled = false;
          btn.innerText = 'Update Balance';
        }
      } catch (err) {
        showToast('Network error.', 'error');
        btn.disabled = false;
        btn.innerText = 'Update Balance';
      }
    }

    function filterMatrixTable() {
      const q = (document.getElementById('matrixSearchInput')?.value || '').toLowerCase();
      document.querySelectorAll('#matrixTable tbody tr.matrix-row').forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(q) ? '' : 'none';
      });
    }

    // Convert 24-hour military time to standard 12-hour AM/PM format
    function format12h(timeStr) {
      if (!timeStr) return null;
      if (timeStr.includes('AM') || timeStr.includes('PM')) return timeStr;
      const parts = timeStr.trim().split(':');
      if (parts.length < 2) return timeStr;
      let hours = parseInt(parts[0], 10);
      const minutes = parts[1];
      const ampm = hours >= 12 ? 'PM' : 'AM';
      hours = hours % 12;
      hours = hours ? hours : 12;
      const strHours = hours < 10 ? '0' + hours : hours;
      return `${strHours}:${minutes} ${ampm}`;
    }

    // ==========================================
    // DTR & BIOMETRIC ATTENDANCE CONTROLLER
    // ==========================================
    let adminCurrentPeriod = 'today';
    let cachedDtrRecords = [];

    function setAdminDatePeriod(period, btnEl) {
      adminCurrentPeriod = period;
      document.querySelectorAll('.period-quick-pills .btn-period').forEach(b => b.classList.remove('active'));
      if (btnEl) btnEl.classList.add('active');

      const monthWrap = document.getElementById('adminMonthWrap');
      const customWrap = document.getElementById('adminCustomRangeWrap');

      if (period === 'first_half' || period === 'second_half' || period === 'full_month') {
        if (monthWrap) monthWrap.style.display = 'block';
        if (customWrap) customWrap.style.display = 'none';
      } else if (period === 'custom') {
        if (monthWrap) monthWrap.style.display = 'none';
        if (customWrap) customWrap.style.display = 'inline-flex';
      } else {
        if (monthWrap) monthWrap.style.display = 'none';
        if (customWrap) customWrap.style.display = 'none';
      }

      loadDtrLogs();
    }

    function getAdminDateRange() {
      const today = new Date();
      const tY = today.getFullYear();
      const tM = String(today.getMonth() + 1).padStart(2, '0');
      const tD = String(today.getDate()).padStart(2, '0');
      const todayIso = `${tY}-${tM}-${tD}`;

      if (adminCurrentPeriod === 'today') {
        return { start_date: todayIso, end_date: todayIso, is_single: true, year: tY, month: parseInt(tM, 10), period: 'full' };
      }

      if (adminCurrentPeriod === 'this_week') {
        const curr = new Date();
        const firstDayOfWeek = curr.getDate() - curr.getDay() + (curr.getDay() === 0 ? -6 : 1); // Monday
        const monday = new Date(curr.setDate(firstDayOfWeek));
        const sunday = new Date(curr.setDate(firstDayOfWeek + 6));
        const sIso = monday.toISOString().slice(0, 10);
        const eIso = sunday.toISOString().slice(0, 10);
        return { start_date: sIso, end_date: eIso, is_single: false, year: tY, month: parseInt(tM, 10), period: 'full' };
      }

      if (adminCurrentPeriod === 'custom') {
        const s = document.getElementById('adminCustomStartDate')?.value || todayIso;
        const e = document.getElementById('adminCustomEndDate')?.value || s;
        return { start_date: s, end_date: e, is_single: (s === e), year: tY, month: parseInt(tM, 10), period: 'full' };
      }

      // Monthly / Semi-Monthly Attendance periods
      const monthVal = document.getElementById('adminDtrMonthPicker')?.value || `${tY}-${tM}`;
      const [yearStr, monthStr] = monthVal.split('-');
      const y = parseInt(yearStr, 10);
      const m = parseInt(monthStr, 10);
      const lastDay = new Date(y, m, 0).getDate();

      let sDay = '01';
      let eDay = String(lastDay).padStart(2, '0');
      let pCode = 'full';

      if (adminCurrentPeriod === 'first_half') {
        eDay = '15';
        pCode = '1st_half';
      } else if (adminCurrentPeriod === 'second_half') {
        sDay = '16';
        pCode = '2nd_half';
      }

      return {
        start_date: `${yearStr}-${monthStr}-${sDay}`,
        end_date: `${yearStr}-${monthStr}-${eDay}`,
        is_single: false,
        year: y,
        month: m,
        period: pCode
      };
    }

    async function loadDtrLogs(silent = false) {
      const range = getAdminDateRange();
      const userFilter = document.getElementById('dtrFilterUser')?.value || '0';
      const tbody = document.getElementById('dtrTableBody');
      const thDate = document.getElementById('dtrThDate');
      if (!tbody) return;

      if (!range.is_single && thDate) {
        thDate.style.display = 'table-cell';
      } else if (thDate) {
        thDate.style.display = 'none';
      }

      if (!silent) {
        tbody.innerHTML = `<tr><td colspan="12" style="text-align:center; padding:24px;"><div style="font-size:12px; color:var(--text-muted);"><i data-lucide="loader-2" class="spin" style="width:16px;height:16px;display:inline-block;vertical-align:middle;margin-right:6px;"></i> Fetching ZKTeco MB460 Plus records for ${range.start_date} to ${range.end_date}...</div></td></tr>`;
        if (window.lucide) lucide.createIcons();
      }

      try {
        let url = `actions/get_dtr_logs.php?start_date=${range.start_date}&end_date=${range.end_date}`;
        if (userFilter && userFilter !== '0') {
          url += `&user_id=${userFilter}`;
        }

        const res = await fetch(url);
        const data = await res.json();
        if (data.success && data.records) {
          cachedDtrRecords = data.records;

          // Update KPI Summary Cards
          const presentEl = document.getElementById('kpiPresentCount');
          const percentEl = document.getElementById('kpiPresentPercent');
          const breakEl = document.getElementById('kpiOnBreakCount');
          const leaveEl = document.getElementById('kpiOnLeaveCount');
          const hoursEl = document.getElementById('kpiTotalRenderedHours');
          const hoursFmtEl = document.getElementById('kpiRenderedFormatted');
          const tardyEl = document.getElementById('kpiTardyFormatted');
          const exceptEl = document.getElementById('kpiExceptionsCount');

          if (presentEl) presentEl.innerText = data.present_count ?? 0;
          if (percentEl) percentEl.innerText = `/ ${data.total_staff || 0} Associates`;
          if (breakEl) breakEl.innerText = data.on_break_count ?? 0;
          if (leaveEl) leaveEl.innerText = data.on_leave_count ?? 0;
          if (hoursEl) hoursEl.innerText = data.total_rendered_hours ? data.total_rendered_hours.toFixed(1) : '0.0';
          if (hoursFmtEl) hoursFmtEl.innerText = data.total_rendered_formatted || '0 hrs';
          if (tardyEl) tardyEl.innerText = data.total_tardy_formatted || '0m';
          if (exceptEl) exceptEl.innerText = data.total_exceptions_count ?? 0;

          if (data.records.length === 0) {
            tbody.innerHTML = `<tr><td colspan="12" style="text-align:center; padding:30px; color:var(--text-muted);">No attendance or leave records found for this period.</td></tr>`;
            return;
          }

          let rowsHtml = '';
          data.records.forEach(r => {
            let statusBadge = '';
            if (r.status === 'On Leave') {
              statusBadge = `<span class="badge-leave"><i data-lucide="palmtree" style="width:12px;height:12px;"></i> ${r.status_text}</span>`;
            } else if (r.status === 'On Break') {
              statusBadge = `<span class="badge-pending"><i data-lucide="coffee" style="width:12px;height:12px;"></i> On Break</span>`;
            } else if (r.status === 'Present') {
              statusBadge = `<span class="badge-ontime"><i data-lucide="check" style="width:12px;height:12px;"></i> Present</span>`;
            } else {
              statusBadge = `<span style="color:var(--text-light); font-size:12px; font-weight:600;">Not Yet Clocked In</span>`;
            }

            let methodBadge = '—';
            if (r.verification_method === 'Face Scan') {
              methodBadge = `<span class="badge-face"><i data-lucide="scan-face" style="width:12px;height:12px;"></i> Face Scan</span>`;
            } else if (r.verification_method === 'Fingerprint') {
              methodBadge = `<span class="badge-fingerprint"><i data-lucide="fingerprint" style="width:12px;height:12px;"></i> Fingerprint</span>`;
            } else if (r.verification_method === 'PIN / Card') {
              methodBadge = `<span class="badge-pin">PIN / RFID</span>`;
            } else if (r.verification_method === 'Admin Adjustment') {
              methodBadge = `<span style="color:#0284c7; font-size:11px; font-weight:600;"><i data-lucide="edit-3" style="width:11px;height:11px;vertical-align:middle;"></i> Adjusted</span>`;
            } else if (r.is_on_leave) {
              methodBadge = `<span style="color:var(--text-muted); font-size:11px;">Leave Reconciled</span>`;
            }

            const avatarContent = r.avatar_path 
              ? `<img src="${r.avatar_path}" alt="">` 
              : `${r.avatar_initials}`;

            const dateCell = (!range.is_single) 
              ? `<td><strong style="font-size:12px;">${r.log_date_formatted || r.log_date}</strong></td>` 
              : '';

            let timeInCell = '<span style="color:var(--text-light); font-size:11px;">--:-- --</span>';
            if (r.time_in) {
              timeInCell = `<span style="font-weight:700; font-family:monospace; color:var(--primary); font-size:12px;">${r.time_in}</span>`;
              if (r.is_tardy) {
                timeInCell += ` <span class="badge" style="background:#fee2e2; color:#b91c1c; font-size:10px; padding:1px 5px;" title="Tardy: Arrival past 8:30 AM official schedule">${r.tardy_formatted}</span>`;
              }
            }

            let timeOutCell = '<span style="color:var(--text-light); font-size:11px;">--:-- --</span>';
            if (r.time_out) {
              timeOutCell = `<span style="font-weight:700; font-family:monospace; color:var(--primary); font-size:12px;">${r.time_out}</span>`;
            } else if (r.is_incomplete && r.exception_type === 'missing_out') {
              timeOutCell = `<span class="badge" style="background:#fef3c7; color:#b45309; font-size:10px; padding:2px 6px;" title="No time-out registered on terminal">Missing Out</span>`;
            }

            rowsHtml += `
              <tr>
                <td>
                  <div style="display:flex; align-items:center; gap:10px;">
                    <div class="avatar sm">${avatarContent}</div>
                    <div>
                      <div style="font-weight:700; color:var(--primary);">${r.name}</div>
                      <div style="font-size:11px; color:var(--text-muted);">${r.title}</div>
                    </div>
                  </div>
                </td>
                <td><strong>#${r.biometric_pin}</strong></td>
                ${dateCell}
                <td>${timeInCell}</td>
                <td>${r.break_out ? `<span style="font-weight:700; font-family:monospace; color:#b45309; font-size:12px;">${r.break_out}</span>` : '<span style="color:var(--text-light); font-size:11px;">--:-- --</span>'}</td>
                <td>${r.break_in ? `<span style="font-weight:700; font-family:monospace; color:#15803d; font-size:12px;">${r.break_in}</span>` : '<span style="color:var(--text-light); font-size:11px;">--:-- --</span>'}</td>
                <td>${timeOutCell}</td>
                <td><strong style="color:var(--primary); font-size:12px;">${r.rendered_hours > 0 ? r.rendered_hours + ' hrs' : '—'}</strong></td>
                <td>${r.overtime_hours > 0 ? `<span style="color:#0284c7; font-weight:700; font-size:12px;">+${r.overtime_hours} hrs</span>` : '<span style="color:var(--text-light); font-size:11px;">—</span>'}</td>
                <td>${methodBadge}</td>
                <td>${statusBadge}</td>
                <td style="text-align:center;">
                  <button class="btn-icon" title="Adjust Punches" onclick="openAdminEditPunch(${r.user_id}, '${escapeJs(r.name)}', '${r.biometric_pin}', '${r.log_date}', '${r.raw_time_in || ''}', '${r.raw_break_out || ''}', '${r.raw_break_in || ''}', '${r.raw_time_out || ''}')">
                    <i data-lucide="edit-3" style="width:14px;height:14px;"></i>
                  </button>
                </td>
              </tr>
            `;
          });
          tbody.innerHTML = rowsHtml;
          if (window.lucide) lucide.createIcons();
        }
      } catch (err) {
        if (!silent) {
          tbody.innerHTML = `<tr><td colspan="12" style="text-align:center; padding:20px; color:var(--danger);">Error loading DTR records.</td></tr>`;
        }
      }

      loadAdminPresence();
      loadAdminCorrections();
      loadAdminOt();
    }

    function escapeJs(str) {
      if (!str) return '';
      return str.replace(/'/g, "\\'").replace(/"/g, '&quot;');
    }

    function openAdminPrintDtr() {
      const range = getAdminDateRange();
      const userFilter = document.getElementById('dtrFilterUser')?.value || '0';
      let url = `print_dtr.php?year=${range.year}&month=${range.month}&period=${range.period}`;
      if (userFilter && userFilter !== '0') {
        url += `&user_id=${userFilter}`;
      }
      window.open(url, '_blank');
    }

    function exportDtrCsv() {
      if (!cachedDtrRecords || cachedDtrRecords.length === 0) {
        showToast('No attendance records available to export.', 'info');
        return;
      }

      let csv = "Associate,PIN,Date,Time In,Break Out,Break In,Time Out,Rendered Hours,Overtime Hours,Verification Method,Status\n";
      cachedDtrRecords.forEach(r => {
        const row = [
          `"${r.name.replace(/"/g, '""')}"`,
          `"${r.biometric_pin}"`,
          `"${r.log_date}"`,
          `"${r.time_in || ''}"`,
          `"${r.break_out || ''}"`,
          `"${r.break_in || ''}"`,
          `"${r.time_out || ''}"`,
          `"${r.rendered_hours || 0}"`,
          `"${r.overtime_hours || 0}"`,
          `"${r.verification_method || ''}"`,
          `"${r.status_text || r.status}"`
        ];
        csv += row.join(',') + "\n";
      });

      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `JTYeo_Attendance_DTR_${new Date().toISOString().slice(0,10)}.csv`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      showToast('Attendance records exported to CSV successfully.', 'success');
    }

    function exportLeaveHistoryCsv() {
      const data = <?= json_encode(array_map(function($r) {
        return [
          'ref_no' => $r['ref_no'],
          'employee_name' => $r['employee_name'],
          'title' => $r['title'],
          'leave_type_label' => $r['leave_type_label'],
          'start_date' => $r['start_date'],
          'end_date' => $r['end_date'],
          'days_count' => $r['days_count'],
          'status' => $r['status'],
          'reason' => $r['reason'] ?? ''
        ];
      }, $leaveRequests)) ?>;

      if (!data || data.length === 0) {
        showToast('No leave history records to export.', 'info');
        return;
      }

      let csv = "Ref No,Associate,Title,Leave Category,Start Date,End Date,Days Count,Status,Reason\n";
      data.forEach(r => {
        const row = [
          `"${(r.ref_no || '').replace(/"/g, '""')}"`,
          `"${(r.employee_name || '').replace(/"/g, '""')}"`,
          `"${(r.title || '').replace(/"/g, '""')}"`,
          `"${(r.leave_type_label || '').replace(/"/g, '""')}"`,
          `"${r.start_date || ''}"`,
          `"${r.end_date || ''}"`,
          `"${r.days_count || 0}"`,
          `"${r.status || ''}"`,
          `"${(r.reason || '').replace(/"/g, '""')}"`
        ];
        csv += row.join(',') + "\n";
      });

      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `JTYeo_Leave_History_${new Date().toISOString().slice(0,10)}.csv`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      showToast('Leave history exported to CSV successfully.', 'success');
    }

    function openAdminEditPunch(userId, userName, pin, date, tIn, bOut, bIn, tOut) {
      document.getElementById('editPunchUserId').value = userId;
      document.getElementById('editPunchAssociateName').innerText = userName;
      document.getElementById('editPunchDateLabel').innerText = date;
      document.getElementById('editPunchPinLabel').innerText = `#${pin}`;
      document.getElementById('editPunchDateInput').value = date;

      document.getElementById('editTimeIn').value = tIn || '';
      document.getElementById('editBreakOut').value = bOut || '';
      document.getElementById('editBreakIn').value = bIn || '';
      document.getElementById('editTimeOut').value = tOut || '';

      openModal('adminEditPunchModal');
    }

    async function handleAdminEditPunchSubmit(e) {
      e.preventDefault();
      const form = document.getElementById('adminEditPunchForm');
      const formData = new FormData(form);
      formData.append('action', 'submit_correction');

      const btn = document.getElementById('btnSavePunchEdit');
      btn.disabled = true;
      btn.innerText = 'Saving...';

      try {
        const res = await fetch('actions/manage_attendance_corrections.php', {
          method: 'POST',
          body: formData
        });
        const data = await res.json();
        btn.disabled = false;
        btn.innerText = 'Save DTR Adjustment';
        if (data.success) {
          showToast(data.message, 'success');
          closeModal('adminEditPunchModal');
          loadDtrLogs();
        } else {
          showToast(data.message || 'Error updating punches.', 'error');
        }
      } catch (err) {
        btn.disabled = false;
        btn.innerText = 'Save DTR Adjustment';
        showToast('Network error updating punches.', 'error');
      }
    }

    async function syncClock() {
      showToast('Queueing Philippine Standard Time clock sync to ZKTeco MB460 Plus...', 'info');
      try {
        const res = await fetch('actions/biometric_sync.php?action=sync_clock');
        const data = await res.json();
        if (data.success) {
          showToast(data.message, 'success');
        } else {
          showToast(data.message || 'Failed to queue clock sync.', 'error');
        }
      } catch (e) {
        showToast('Network error during clock sync.', 'error');
      }
    }

    function loadAdminPresence() {
      const container = document.getElementById('adminPresenceRosterContainer');
      if (!container) return;
      fetch('actions/get_presence.php')
        .then(res => res.json())
        .then(data => {
          if (!data.success || !data.roster) return;
          const labelEl = document.getElementById('adminPresenceDateLabel');
          if (labelEl) labelEl.innerText = data.date_formatted;

          let html = '';
          data.roster.forEach(m => {
            let dotColor = '#94a3b8';
            if (m.state === 'present') dotColor = '#10b981';
            else if (m.state === 'on_break') dotColor = '#f59e0b';
            else if (m.state === 'on_leave') dotColor = '#3b82f6';
            else if (m.state === 'completed') dotColor = '#059669';

            html += `
              <div style="display:flex; align-items:center; gap:10px; background:#fff; border:1px solid var(--border-color); border-radius:8px; padding:8px 12px; min-width:220px; flex:1 1 calc(33.333% - 12px);">
                <div style="width:10px; height:10px; border-radius:50%; background:${dotColor}; flex-shrink:0;"></div>
                <div style="flex:1; min-width:0;">
                  <div style="font-weight:700; font-size:12.5px; color:var(--primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${m.name}</div>
                  <div style="font-size:11px; color:var(--text-muted);">${m.state_label} &bull; ${m.last_action}</div>
                </div>
              </div>
            `;
          });
          container.innerHTML = html;
        })
        .catch(() => {});
    }

    function loadAdminCorrections() {
      const tbody = document.getElementById('adminCorrectionsTbody');
      const badge = document.getElementById('adminCorrectionsBadge');
      if (!tbody) return;

      fetch('actions/manage_attendance_corrections.php?action=get_corrections')
        .then(res => res.json())
        .then(data => {
          if (!data.success || !data.corrections || data.corrections.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; color:var(--text-muted); padding:16px;">No missed punch adjustment requests.</td></tr>`;
            if (badge) badge.style.display = 'none';
            return;
          }

          let pendingCount = 0;
          let html = '';
          data.corrections.forEach(c => {
            if (c.status === 'Pending') pendingCount++;

            let punchStr = [];
            if (c.time_in_12 && c.time_in_12 !== '-') punchStr.push(`In: ${c.time_in_12}`);
            if (c.break_out_12 && c.break_out_12 !== '-') punchStr.push(`B-Out: ${c.break_out_12}`);
            if (c.break_in_12 && c.break_in_12 !== '-') punchStr.push(`B-In: ${c.break_in_12}`);
            if (c.time_out_12 && c.time_out_12 !== '-') punchStr.push(`Out: ${c.time_out_12}`);

            let actionHtml = '';
            if (c.status === 'Pending') {
              actionHtml = `
                <div style="display:flex; gap:6px;">
                  <button class="btn-primary" style="padding:4px 8px; font-size:11px;" onclick="approveCorrection(${c.id})">Approve</button>
                  <button class="btn-secondary" style="padding:4px 8px; font-size:11px; color:var(--danger);" onclick="rejectCorrection(${c.id})">Reject</button>
                </div>
              `;
            } else {
              let bClass = c.status === 'Approved' ? 'badge-approved' : 'badge-rejected';
              actionHtml = `<span class="badge ${bClass}">${c.status}</span>`;
            }

            html += `
              <tr>
                <td><strong>${c.user_name}</strong></td>
                <td>${c.target_date_formatted}</td>
                <td><span style="font-size:11px;">${punchStr.join(' &bull; ') || 'Adjustment'}</span></td>
                <td><span style="font-size:11.5px;" title="${c.reason}">${c.reason}</span></td>
                <td>${actionHtml}</td>
              </tr>
            `;
          });

          if (badge) {
            if (pendingCount > 0) {
              badge.innerText = pendingCount;
              badge.style.display = 'inline-block';
            } else {
              badge.style.display = 'none';
            }
          }
          tbody.innerHTML = html;
        });
    }

    async function approveCorrection(id) {
      try {
        const formData = new FormData();
        formData.append('action', 'approve_correction');
        formData.append('id', id);
        const res = await fetch('actions/manage_attendance_corrections.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
          showToast(data.message, 'success');
          loadDtrLogs();
          loadAdminCorrections();
        } else {
          showToast(data.message || 'Error approving adjustment.', 'error');
        }
      } catch (e) {
        showToast('Network error approving adjustment.', 'error');
      }
    }

    async function rejectCorrection(id) {
      try {
        const formData = new FormData();
        formData.append('action', 'reject_correction');
        formData.append('id', id);
        const res = await fetch('actions/manage_attendance_corrections.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
          showToast(data.message, 'success');
          loadAdminCorrections();
        } else {
          showToast(data.message || 'Error rejecting adjustment.', 'error');
        }
      } catch (e) {
        showToast('Network error rejecting adjustment.', 'error');
      }
    }

    function loadAdminOt() {
      const tbody = document.getElementById('adminOtTbody');
      const badge = document.getElementById('adminOtBadge');
      if (!tbody) return;

      fetch('actions/manage_overtime.php?action=get_ot_requests')
        .then(res => res.json())
        .then(data => {
          if (!data.success || !data.requests || data.requests.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; color:var(--text-muted); padding:16px;">No overtime requests pending.</td></tr>`;
            if (badge) badge.style.display = 'none';
            return;
          }

          let pendingCount = 0;
          let html = '';
          data.requests.forEach(r => {
            if (r.status === 'Pending') pendingCount++;

            let actionHtml = '';
            if (r.status === 'Pending') {
              actionHtml = `
                <div style="display:flex; gap:6px;">
                  <button class="btn-primary" style="padding:4px 8px; font-size:11px;" onclick="approveOt(${r.id})">Approve</button>
                  <button class="btn-secondary" style="padding:4px 8px; font-size:11px; color:var(--danger);" onclick="rejectOt(${r.id})">Reject</button>
                </div>
              `;
            } else {
              let bClass = r.status === 'Approved' ? 'badge-approved' : 'badge-rejected';
              actionHtml = `<span class="badge ${bClass}">${r.status}</span>`;
            }

            html += `
              <tr>
                <td><strong>${r.user_name}</strong></td>
                <td>${r.ot_date_formatted}</td>
                <td><strong style="color:#0284c7;">${r.estimated_hours} hrs</strong></td>
                <td><span style="font-size:11.5px;" title="${r.reason}">${r.reason}</span></td>
                <td>${actionHtml}</td>
              </tr>
            `;
          });

          if (badge) {
            if (pendingCount > 0) {
              badge.innerText = pendingCount;
              badge.style.display = 'inline-block';
            } else {
              badge.style.display = 'none';
            }
          }
          tbody.innerHTML = html;
        });
    }

    async function approveOt(id) {
      try {
        const formData = new FormData();
        formData.append('action', 'approve_ot');
        formData.append('id', id);
        const res = await fetch('actions/manage_overtime.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
          showToast(data.message, 'success');
          loadDtrLogs();
          loadAdminOt();
        } else {
          showToast(data.message || 'Error approving overtime.', 'error');
        }
      } catch (e) {
        showToast('Network error approving overtime.', 'error');
      }
    }

    async function rejectOt(id) {
      try {
        const formData = new FormData();
        formData.append('action', 'reject_ot');
        formData.append('id', id);
        const res = await fetch('actions/manage_overtime.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
          showToast(data.message, 'success');
          loadAdminOt();
        } else {
          showToast(data.message || 'Error rejecting overtime.', 'error');
        }
      } catch (e) {
        showToast('Network error rejecting overtime.', 'error');
      }
    }

    // Live Biometric Status Checker (Zero clicks needed)
    async function checkBiometricStatus() {
      const badge = document.getElementById('zktecoStatusBadge');
      if (!badge) return;
      try {
        const res = await fetch('actions/biometric_sync.php?action=get_status');
        const data = await res.json();
        if (data.success && data.online) {
          badge.style.background = '#dcfce7';
          badge.style.color = '#15803d';
          badge.innerHTML = '<i data-lucide="check-circle" style="width:12px;height:12px;"></i> Online (Connected)';
        } else {
          badge.style.background = '#fee2e2';
          badge.style.color = '#991b1b';
          badge.innerHTML = '<i data-lucide="radio" style="width:12px;height:12px;"></i> Offline (Not Connected)';
        }
        if (window.lucide) lucide.createIcons();
      } catch (e) {}
    }

    // Auto-update DTR logs and hardware status every 10 seconds in the background when Biometrics tab is open
    setInterval(() => {
      const bioPane = document.getElementById('tab-biometrics');
      if (bioPane && bioPane.style.display !== 'none') {
        if (typeof loadDtrLogs === 'function') loadDtrLogs(true);
        checkBiometricStatus();
      }
    }, 10000);

    // Sync Biometrics (100% Automatic)
    async function syncBiometrics() {
      const badge = document.getElementById('zktecoStatusBadge');
      showToast('Connecting to ZKTeco MB460 Plus over network...', 'info');

      try {
        const res = await fetch('actions/biometric_sync.php?action=sync_now');
        const data = await res.json();
        if (data.success) {
          showToast(data.message, 'success');
          if (badge) {
            badge.style.background = '#dcfce7';
            badge.style.color = '#15803d';
            badge.innerHTML = '<i data-lucide="check-circle" style="width:12px;height:12px;"></i> Online (Connected)';
          }
          const ovBadge = document.getElementById('adminZkOnlineBadge');
          const ovLbl = document.getElementById('adminZkStatusLabel');
          if (ovBadge && ovLbl) {
            ovBadge.className = 'status-pill active';
            ovLbl.innerText = 'Online / LAN';
          }
          if (data.device_ip) {
            const ipEl = document.getElementById('adminZkIpDisplay');
            if (ipEl && data.device_ip !== 'Auto-detecting...') {
              ipEl.innerText = data.device_ip + (!data.device_ip.includes(':') ? ':4370' : '');
            }
          }
          const ls = document.getElementById('adminZkLastSeenText');
          if (ls && data.sync_time) {
            ls.innerHTML = `<strong>Last Sync:</strong> ${data.sync_time}`;
          }
          loadDtrLogs();
          if (data.updated_count > 0) {
            setTimeout(() => window.location.reload(), 1200);
          }
        } else {
          showToast(data.message, 'error');
          if (badge) {
            badge.style.background = '#fee2e2';
            badge.style.color = '#991b1b';
            badge.innerHTML = '<i data-lucide="alert-circle" style="width:12px;height:12px;"></i> Offline (Not Connected)';
          }
          const ovBadge = document.getElementById('adminZkOnlineBadge');
          const ovLbl = document.getElementById('adminZkStatusLabel');
          if (ovBadge && ovLbl) {
            ovBadge.className = 'status-pill inactive';
            ovLbl.innerText = 'Standby / LAN';
          }
        }
        if (window.lucide) lucide.createIcons();
      } catch (err) {
        showToast('ZKTeco terminal is currently offline / unreachable.', 'error');
        if (badge) {
          badge.style.background = '#fee2e2';
          badge.style.color = '#991b1b';
          badge.innerHTML = '<i data-lucide="alert-circle" style="width:12px;height:12px;"></i> Offline (Not Connected)';
        }
        if (window.lucide) lucide.createIcons();
      }
    }
    window.triggerManualSync = syncBiometrics;

    // Dynamic PIN Auto-Detection for Add Associate
    let pinTimer = null;
    function debounceDetectPin(val) {
      clearTimeout(pinTimer);
      pinTimer = setTimeout(() => {
        if (val && val.trim().length >= 2) {
          checkDevicePinEnrollment(val.trim());
        }
      }, 400);
    }

    async function checkDevicePinEnrollment(pinVal = null) {
      const pin = pinVal || document.getElementById('addBiometricPin').value.trim();
      const statusEl = document.getElementById('pinDetectionStatus');
      const badgeEl = document.getElementById('enrollAutoBadge');
      if (!pin) {
        if (statusEl) statusEl.innerHTML = '<span style="color:var(--danger);">Please enter a PIN number first.</span>';
        return;
      }
      if (statusEl) statusEl.innerHTML = '<span style="color:var(--text-light);">Querying ZKTeco MB460 Plus terminal over LAN...</span>';

      try {
        const res = await fetch('actions/biometric_sync.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: `action=check_device_pin&pin=${encodeURIComponent(pin)}`
        });
        const data = await res.json();
        if (data.success) {
          const faceEl = document.getElementById('addFaceEnrolled');
          const fpEl = document.getElementById('addFpEnrolled');
          if (faceEl) faceEl.checked = (data.face_enrolled === 1);
          if (fpEl) fpEl.checked = (data.fingerprint_enrolled === 1);

          if (data.face_enrolled || data.fingerprint_enrolled) {
            if (statusEl) statusEl.innerHTML = `<span style="color:var(--success); font-weight:600;"><i data-lucide="check-circle" style="width:12px;height:12px;display:inline-block;vertical-align:middle;"></i> ${data.message}</span>`;
            if (badgeEl) {
              badgeEl.innerHTML = 'Verified on Terminal';
              badgeEl.style.color = 'var(--success)';
            }
          } else {
            if (statusEl) statusEl.innerHTML = `<span style="color:var(--accent);">${data.message}</span>`;
            if (badgeEl) {
              badgeEl.innerHTML = 'Pending Registration';
              badgeEl.style.color = 'var(--text-light)';
            }
          }
          if (window.lucide) lucide.createIcons();
        }
      } catch (e) {
        if (statusEl) statusEl.innerHTML = '<span style="color:var(--danger);">Could not connect to biometric terminal service.</span>';
      }
    }

    // Test Punch Simulation
    async function handleTestPunchSubmit(e) {
      e.preventDefault();
      const form = document.getElementById('testPunchForm');
      const formData = new FormData(form);
      const btn = document.getElementById('btnSimPunch');
      btn.disabled = true;
      try {
        const res = await fetch('actions/biometric_sync.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
          showToast(data.message, 'success');
          closeModal('testPunchModal');
          loadDtrLogs();
          if (data.auto_enrolled) {
            setTimeout(() => window.location.reload(), 1100);
          }
        } else {
          showToast(data.message || 'Error recording test punch', 'error');
        }
      } catch (err) {
        showToast('Network error simulating punch.', 'error');
      } finally {
        btn.disabled = false;
      }
    }

    // Add User
    async function handleAddUserSubmit(e) {
      e.preventDefault();
      const form = document.getElementById('addUserForm');
      const formData = new FormData(form);
      const btn = document.getElementById('btnAddUserBtn');
      btn.disabled = true;
      try {
        const res = await fetch('actions/manage_users.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
          showToast(data.message, 'success');
          closeModal('addUserModal');
          setTimeout(() => window.location.reload(), 700);
        } else {
          showToast(data.message || 'Error creating user', 'error');
          btn.disabled = false;
        }
      } catch (err) {
        showToast('Network error.', 'error');
        btn.disabled = false;
      }
    }

    // Live Avatar Preview Before Saving
    function previewAvatarImage(input, previewImgId, initialsSpanId) {
      if (input.files && input.files[0]) {
        const file = input.files[0];
        if (!file.type.match('image.*')) {
          showToast('Please select an image file (JPG, PNG, WebP).', 'error');
          return;
        }
        const reader = new FileReader();
        reader.onload = function(e) {
          const img = document.getElementById(previewImgId);
          const initials = document.getElementById(initialsSpanId);
          if (img) {
            img.src = e.target.result;
            img.style.display = 'block';
          }
          if (initials) {
            initials.style.display = 'none';
          }
        };
        reader.readAsDataURL(file);
      }
    }

    // Edit User Modal
    async function openEditUserModal(userId) {
      try {
        const res = await fetch(`actions/manage_users.php?action=get_user&user_id=${userId}`);
        const data = await res.json();
        if (data.success && data.user) {
          const u = data.user;
          document.getElementById('editUserId').value = u.id;
          document.getElementById('editUserName').value = u.name;
          document.getElementById('editUserEmail').value = u.email;
          document.getElementById('editUserTitle').value = u.title;
          document.getElementById('editUserGender').value = u.gender || 'Female';
          document.getElementById('editUserRole').value = u.role || 'staff';
          document.getElementById('editUserPin').value = u.biometric_pin || '';
          document.getElementById('editUserFace').checked = (parseInt(u.face_enrolled) === 1);
          document.getElementById('editUserFingerprint').checked = (parseInt(u.fingerprint_enrolled) === 1);

          // Populate avatar preview
          const previewImg = document.getElementById('editUserAvatarPreview');
          const initialsSpan = document.getElementById('editUserAvatarInitials');
          if (u.avatar_path && u.avatar_path.length > 3) {
            previewImg.src = u.avatar_path;
            previewImg.style.display = 'block';
            initialsSpan.style.display = 'none';
          } else {
            previewImg.style.display = 'none';
            initialsSpan.innerText = u.avatar_initials || 'CP';
            initialsSpan.style.display = 'block';
          }
          const fileInput = document.getElementById('editUserAvatarInput');
          if (fileInput) fileInput.value = '';

          // Control Delete button in modal (cannot delete yourself)
          const delBtn = document.getElementById('btnDeleteUserInModal');
          if (delBtn) {
            delBtn.style.display = (parseInt(u.id) === currentLoggedUserId) ? 'none' : 'inline-flex';
          }

          openModal('editUserModal');
        }
      } catch (err) {
        showToast('Error loading user profile.', 'error');
      }
    }

    async function handleEditUserSubmit(e) {
      e.preventDefault();
      const form = document.getElementById('editUserForm');
      const formData = new FormData(form);
      const btn = document.getElementById('btnEditUserBtn');
      btn.disabled = true;
      try {
        const res = await fetch('actions/manage_users.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
          showToast(data.message, 'success');
          closeModal('editUserModal');
          setTimeout(() => window.location.reload(), 700);
        } else {
          showToast(data.message || 'Error updating user profile', 'error');
          btn.disabled = false;
        }
      } catch (err) {
        showToast('Network error.', 'error');
        btn.disabled = false;
      }
    }

    // Delete Associate Account (Admin Only)
    async function deleteUser(userId, userName) {
      if (!userId) return;
      if (parseInt(userId) === currentLoggedUserId) {
        showToast('You cannot delete your own administrator account.', 'error');
        return;
      }

      const confirmed = await showConfirmDialog({
        title: 'Delete Associate Account',
        message: `Are you sure you want to permanently delete "${userName}"? This will remove their user account, leave balance allocations, biometric logs, and profile records. This action cannot be undone.`,
        confirmText: 'Delete Associate',
        cancelText: 'Cancel',
        isDanger: true,
        icon: 'trash-2'
      });
      if (!confirmed) return;

      try {
        const formData = new FormData();
        formData.append('action', 'delete_user');
        formData.append('user_id', userId);

        const res = await fetch('actions/manage_users.php', {
          method: 'POST',
          body: formData
        });
        const data = await res.json();
        if (data.success) {
          showToast(data.message, 'success');
          setTimeout(() => window.location.reload(), 900);
        } else {
          showToast(data.message || 'Failed to delete associate.', 'error');
        }
      } catch (err) {
        showToast('Network error while deleting associate.', 'error');
      }
    }

    async function deleteUserFromEditModal() {
      const id = document.getElementById('editUserId').value;
      const name = document.getElementById('editUserName').value;
      if (!id) return;
      closeModal('editUserModal');
      await deleteUser(id, name);
    }

    // Profile Photo & Password Update
    async function handleProfileUpdate(e) {
      e.preventDefault();
      const form = document.getElementById('profileForm');
      const formData = new FormData(form);
      const btn = document.getElementById('btnUpdateProfile');
      btn.disabled = true;
      try {
        const res = await fetch('actions/manage_users.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
          showToast(data.message, 'success');
          closeModal('profileModal');
          setTimeout(() => window.location.reload(), 700);
        } else {
          showToast(data.message || 'Error updating profile', 'error');
          btn.disabled = false;
        }
      } catch (err) {
        showToast('Network error.', 'error');
        btn.disabled = false;
      }
    }

    // FullCalendar Initialization
    let calendarInstance = null;
    function initCalendar() {
      const calEl = document.getElementById('leaveCalendar');
      if (!calEl) return;
      if (calendarInstance) {
        calendarInstance.render();
        return;
      }

      calendarInstance = new FullCalendar.Calendar(calEl, {
        initialView: 'dayGridMonth',
        headerToolbar: {
          left: 'prev,next today',
          center: 'title',
          right: 'dayGridMonth,timeGridWeek,listMonth'
        },
        height: 'auto',
        events: function(info, successCallback, failureCallback) {
          const uId = document.getElementById('calFilterUser').value;
          const type = document.getElementById('calFilterType').value;
          const showHolidays = document.getElementById('calToggleHolidays').checked ? 1 : 0;
          fetch(`actions/get_calendar_events.php?user_id=${uId}&leave_type=${type}&show_holidays=${showHolidays}`)
            .then(res => res.json())
            .then(data => successCallback(data))
            .catch(err => failureCallback(err));
        },
        eventClick: function(info) {
          const props = info.event.extendedProps;
          if (props.is_holiday) {
            showToast(`${info.event.title} - ${props.description}`, 'info');
          } else {
            viewDetailsModal(
              props.ref_no, props.employee, props.title, props.leave_type,
              props.days, props.start_date, props.end_date, props.reason,
              props.status, props.approver, '', ''
            );
          }
        }
      });
      calendarInstance.render();
    }

    function refreshCalendarEvents() {
      if (calendarInstance) {
        calendarInstance.refetchEvents();
      }
    }

    initSavedTab();
    window.addEventListener('hashchange', () => {
      const hashTab = window.location.hash.replace('#', '').trim();
      if (hashTab && document.getElementById(`tab-${hashTab}`)) {
        switchTab(hashTab, false);
      }
    });

    if (window.lucide) lucide.createIcons();
    document.addEventListener('DOMContentLoaded', () => { 
      initSavedTab();
      if (window.lucide) lucide.createIcons(); 
    });
    window.addEventListener('load', () => { 
      initSavedTab();
      if (window.lucide) lucide.createIcons(); 
    });
  </script>
</body>
</html>
