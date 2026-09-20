<?php
// staff_dashboard.php - Dedicated Staff CPA Self-Service Portal (JTYeo CPA Accounting Office)
require_once __DIR__ . '/auth.php';
requireLogin();

$user = getCurrentUser();
$userRole = $user['role'];
$userGender = $user['gender'] ?? 'Female';

// Fetch user's personal leave balances
$stmt = $pdo->prepare("SELECT * FROM leave_balances WHERE user_id = ?");
$stmt->execute([$user['id']]);
$balances = $stmt->fetch() ?: [];

$vlBalance = (float)($balances['vl_balance'] ?? 12.0);
$slBalance = (float)($balances['sl_balance'] ?? 10.0);
$emBalance = (float)($balances['emergency_balance'] ?? 5.0);
$splBalance = (float)($balances['solo_parent_balance'] ?? 7.0);
$matBalance = (float)($balances['maternity_balance'] ?? 105.0);
$patBalance = (float)($balances['paternity_balance'] ?? 7.0);
$specBalance = (float)($balances['special_women_balance'] ?? 60.0);

// Fetch Personal Leave History
$leaveReqStmt = $pdo->prepare("
    SELECT r.*, u.name as employee_name, u.title, u.avatar_path, u.avatar_initials
    FROM leave_requests r
    JOIN users u ON r.user_id = u.id
    WHERE r.user_id = ?
    ORDER BY r.created_at DESC
");
$leaveReqStmt->execute([$user['id']]);
$leaveRequests = $leaveReqStmt->fetchAll();

// All staff for calendar filtering
$allUsersStmt = $pdo->query("SELECT id, name FROM users ORDER BY id ASC");
$allUsers = $allUsersStmt->fetchAll();

// Fetch Active Leave Types & User's Dynamic Allocations
$activeLeaveTypes = $pdo->query("SELECT * FROM leave_types WHERE is_active = 1 ORDER BY id ASC")->fetchAll();

$userAllocStmt = $pdo->prepare("SELECT leave_type_code, remaining_days FROM user_leave_allocations WHERE user_id = ?");
$userAllocStmt->execute([$user['id']]);
$userAllocMap = $userAllocStmt->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Staff Portal | JTYeo CPA Accounting Office</title>
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
          <div class="firm-badge">Staff Associate Portal</div>
        </div>
      </div>

      <nav class="sidebar-nav">
        <div class="nav-category">Self-Service</div>
        <a class="nav-item active" data-tab="overall" onclick="switchTab('overall'); loadStaffOverallDashboard();">
          <i data-lucide="layout-grid"></i>
          <span>Overall Dashboard</span>
        </a>
        <a class="nav-item" data-tab="my-portal" onclick="switchTab('my-portal')">
          <i data-lucide="layers"></i>
          <span>My Balances &amp; History</span>
        </a>
        <a class="nav-item" data-tab="attendance" onclick="switchTab('attendance'); loadStaffDtr();">
          <i data-lucide="clock"></i>
          <span>My Attendance &amp; DTR</span>
        </a>
        <a class="nav-item" data-tab="calendar" onclick="switchTab('calendar'); initCalendar();">
          <i data-lucide="calendar"></i>
          <span>Team Leave Calendar</span>
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
            <span>Current Role: <strong><?= htmlspecialchars($user['title']) ?></strong></span>
          </div>
          <div class="ph-time" id="liveClock">PHT: Loading...</div>
        </div>

        <div class="header-right">
          <!-- User Profile & Edit Modal Trigger -->
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
             TAB 0: ASSOCIATE OVERALL DASHBOARD
             ============================================== -->
        <div id="tab-overall" class="tab-pane active" style="display:block;">
          <div class="page-header">
            <div class="page-title">
              <h1>Welcome, <?= htmlspecialchars(explode(' ', $user['name'])[0]) ?></h1>
              <p>Associate Overall Dashboard &bull; Attendance Today, Balances, Quick Requests &amp; Team Status</p>
            </div>
            <div class="header-actions">
              <button class="btn-primary" onclick="openModal('applyModal')">
                <i data-lucide="plus-circle"></i>
                <span>File Leave Request</span>
              </button>
            </div>
          </div>

          <!-- 1. Today's Personal Attendance Banner -->
          <div class="dashboard-card" style="margin-bottom: 24px; border-left: 4px solid var(--primary);">
            <div class="card-head" style="display:flex; justify-content:space-between; align-items:center;">
              <div>
                <h3 style="display:flex; align-items:center; gap:8px;">
                  <i data-lucide="scan-face" style="color:var(--accent);"></i>
                  Today's Attendance Punches &bull; <span style="font-weight:normal; font-size:13px; color:var(--text-muted);"><?= date('l, F j, Y') ?></span>
                </h3>
              </div>
              <div style="display:flex; align-items:center; gap:8px;">
                <span class="status-pill active" style="font-size:11px;">
                  <span class="status-dot"></span>
                  <span>Biometric PIN: #<?= htmlspecialchars($user['biometric_pin'] ?: $user['id']) ?></span>
                </span>
                <span class="status-pill info" style="font-size:11px;">
                  <span class="status-dot"></span>
                  <span>Face &amp; Fingerprint Enrolled</span>
                </span>
              </div>
            </div>
            <div class="card-body" style="padding: 16px;">
              <div class="punch-sequence-strip" style="margin-bottom: 12px;">
                <div class="punch-slot-box" id="staffPunchInBox">
                  <div class="punch-slot-label"><i data-lucide="log-in" style="width:11px;height:11px;"></i> 1. Time In</div>
                  <div class="punch-slot-time" id="staffSlotTimeIn">&mdash;</div>
                </div>
                <div class="punch-slot-box" id="staffPunchBreakOutBox">
                  <div class="punch-slot-label"><i data-lucide="coffee" style="width:11px;height:11px;"></i> 2. Break Out</div>
                  <div class="punch-slot-time" id="staffSlotBreakOut">&mdash;</div>
                </div>
                <div class="punch-slot-box" id="staffPunchBreakInBox">
                  <div class="punch-slot-label"><i data-lucide="utensils" style="width:11px;height:11px;"></i> 3. Break In</div>
                  <div class="punch-slot-time" id="staffSlotBreakIn">&mdash;</div>
                </div>
                <div class="punch-slot-box" id="staffPunchOutBox">
                  <div class="punch-slot-label"><i data-lucide="log-out" style="width:11px;height:11px;"></i> 4. Time Out</div>
                  <div class="punch-slot-time" id="staffSlotTimeOut">&mdash;</div>
                </div>
              </div>
              <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <div style="font-size:13px; color:var(--text-muted);">
                  Today's Rendered Work Hours: <strong id="staffTodayRenderedText" style="color:var(--primary); font-size:14px;">0.00 hrs</strong>
                  <span id="staffPunchStatusHelp" style="margin-left:8px; font-size:11.5px; color:var(--text-muted);">&bull; Punches automatically sync from the office ZKTeco device</span>
                </div>
                <button class="btn-secondary btn-sm" onclick="openModal('staffCorrectionModal')">
                  <i data-lucide="edit-3" style="width:12px; height:12px;"></i>
                  <span>Missed a punch today? File Correction</span>
                </button>
              </div>
            </div>
          </div>

          <!-- 2. Personal KPI Summary Cards -->
          <div class="kpi-grid" style="margin-bottom: 24px;">
            <div class="kpi-card green">
              <div class="kpi-header">
                <span class="kpi-label">Vacation Leave (VL)</span>
                <div class="kpi-icon"><i data-lucide="palmtree"></i></div>
              </div>
              <div class="kpi-value-row">
                <span class="kpi-value"><?= number_format($vlBalance, 1) ?></span>
                <span class="kpi-sub">/ 12.0 Days</span>
              </div>
              <div class="kpi-footer positive">
                <i data-lucide="check" style="width:14px;height:14px;"></i>
                <span>Available Balance</span>
              </div>
            </div>

            <div class="kpi-card purple">
              <div class="kpi-header">
                <span class="kpi-label">Sick Leave (SL)</span>
                <div class="kpi-icon"><i data-lucide="heart-pulse"></i></div>
              </div>
              <div class="kpi-value-row">
                <span class="kpi-value"><?= number_format($slBalance, 1) ?></span>
                <span class="kpi-sub">/ 10.0 Days</span>
              </div>
              <div class="kpi-footer positive">
                <i data-lucide="shield-check" style="width:14px;height:14px;"></i>
                <span>Available Balance</span>
              </div>
            </div>

            <div class="kpi-card blue">
              <div class="kpi-header">
                <span class="kpi-label">Month Rendered Hours</span>
                <div class="kpi-icon"><i data-lucide="clock"></i></div>
              </div>
              <div class="kpi-value-row">
                <span class="kpi-value" id="staffOverallMonthHours">0.0</span>
                <span class="kpi-sub">Total Hours</span>
              </div>
              <div class="kpi-footer positive">
                <i data-lucide="trending-up" style="width:14px;height:14px;"></i>
                <span id="staffOverallMonthOt">0.0 hrs approved OT</span>
              </div>
            </div>

            <div class="kpi-card amber">
              <div class="kpi-header">
                <span class="kpi-label">Pending Requests</span>
                <div class="kpi-icon"><i data-lucide="hourglass"></i></div>
              </div>
              <div class="kpi-value-row">
                <span class="kpi-value" id="staffOverallPendingCount">0</span>
                <span class="kpi-sub">Applications</span>
              </div>
              <div class="kpi-footer" style="color:var(--warning);">
                <i data-lucide="activity" style="width:14px;height:14px;"></i>
                <span id="staffOverallPendingBreakdown">Under HR Review</span>
              </div>
            </div>
          </div>

          <!-- 3. Quick Action Launcher Tiles -->
          <div style="margin-bottom: 24px;">
            <div style="font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:12px;">
              Quick Actions Launcher
            </div>
            <div class="quick-launcher-grid">
              <div class="quick-launcher-card" onclick="openModal('applyModal')">
                <div class="quick-launcher-icon" style="background:#eff6ff; color:#2563eb;">
                  <i data-lucide="calendar-plus"></i>
                </div>
                <div>
                  <div class="quick-launcher-title">File Leave Request</div>
                  <div class="quick-launcher-sub">VL, SL &amp; Special Leaves</div>
                </div>
              </div>

              <div class="quick-launcher-card" onclick="openModal('staffCorrectionModal')">
                <div class="quick-launcher-icon" style="background:#fef3c7; color:#b45309;">
                  <i data-lucide="edit-3"></i>
                </div>
                <div>
                  <div class="quick-launcher-title">Missed Punch Adj.</div>
                  <div class="quick-launcher-sub">Correct DTR timestamps</div>
                </div>
              </div>

              <div class="quick-launcher-card" onclick="openModal('staffOtModal')">
                <div class="quick-launcher-icon" style="background:#f3e8ff; color:#7e22ce;">
                  <i data-lucide="trending-up"></i>
                </div>
                <div>
                  <div class="quick-launcher-title">Request Overtime</div>
                  <div class="quick-launcher-sub">Pre-authorization form</div>
                </div>
              </div>

              <div class="quick-launcher-card" onclick="switchTab('attendance'); loadStaffDtr();">
                <div class="quick-launcher-icon" style="background:#f0fdf4; color:#15803d;">
                  <i data-lucide="printer"></i>
                </div>
                <div>
                  <div class="quick-launcher-title">Form 48 DTR Sheet</div>
                  <div class="quick-launcher-sub">Print monthly record</div>
                </div>
              </div>
            </div>
          </div>

          <!-- 4. Two-Column Lower Layout -->
          <div class="overall-layout-grid">
            
            <!-- Left: My Recent Requests -->
            <div class="overall-col-main">
              <div class="dashboard-card">
                <div class="card-head" style="display:flex; justify-content:space-between; align-items:center;">
                  <h3>
                    <i data-lucide="history" style="color:var(--accent);"></i>
                    My Recent Applications &amp; Status
                  </h3>
                  <button class="btn-link" onclick="switchTab('my-portal')" style="font-size:12px; font-weight:600; color:var(--accent); background:none; border:none; cursor:pointer;">
                    View All &rarr;
                  </button>
                </div>
                <div class="card-body" style="padding: 16px;">
                  <div id="staffOverallRecentList" style="display:flex; flex-direction:column; gap:10px;">
                    <div style="text-align:center; padding: 20px; color: var(--text-muted); font-size:12px;">
                      Loading recent applications...
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Right: Office Team Presence & Upcoming Schedule -->
            <div class="overall-col-side">
              
              <!-- Team Presence Widget -->
              <div class="dashboard-card">
                <div class="card-head">
                  <h3>
                    <i data-lucide="users" style="color:var(--accent);"></i>
                    Office Team Presence
                  </h3>
                </div>
                <div class="card-body" style="padding: 12px;">
                  <div id="staffOverallPresenceRoster" class="presence-roster-list" style="max-height: 250px;">
                    <div style="text-align:center; padding: 16px; color: var(--text-muted); font-size:12px;">
                      Loading office presence...
                    </div>
                  </div>
                </div>
              </div>

              <!-- Upcoming Schedule (Next 7 Days) -->
              <div class="dashboard-card">
                <div class="card-head">
                  <h3>
                    <i data-lucide="calendar" style="color:var(--accent);"></i>
                    Upcoming Holidays &amp; Leaves
                  </h3>
                </div>
                <div class="card-body" style="padding: 12px;">
                  <div id="staffOverallScheduleList" style="display:flex; flex-direction:column; gap:8px;">
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
             TAB 1: MY PORTAL & BALANCES
             ============================================== -->
        <div id="tab-my-portal" class="tab-pane">
          <div class="page-header">
            <div class="page-title">
              <h1>Welcome, <?= htmlspecialchars(explode(' ', $user['name'])[0]) ?></h1>
              <p>Self-Service Portal &bull; Track your leave credits, file applications, and view history.</p>
            </div>
            <div class="header-actions">
              <button class="btn-primary" onclick="openModal('applyModal')">
                <i data-lucide="plus-circle"></i>
                <span>File Leave Request</span>
              </button>
            </div>
          </div>

          <!-- Personal Balance Cards -->
          <div class="kpi-grid">
            <div class="kpi-card green">
              <div class="kpi-header">
                <span class="kpi-label">Vacation Leave (VL)</span>
                <div class="kpi-icon"><i data-lucide="palmtree"></i></div>
              </div>
              <div class="kpi-value-row">
                <span class="kpi-value"><?= number_format($vlBalance, 1) ?></span>
                <span class="kpi-sub">/ 12.0 Days</span>
              </div>
              <div class="kpi-footer positive">
                <i data-lucide="check" style="width:14px;height:14px;"></i>
                <span>Annual Vacation Credit</span>
              </div>
            </div>

            <div class="kpi-card purple">
              <div class="kpi-header">
                <span class="kpi-label">Sick Leave (SL)</span>
                <div class="kpi-icon"><i data-lucide="heart-pulse"></i></div>
              </div>
              <div class="kpi-value-row">
                <span class="kpi-value"><?= number_format($slBalance, 1) ?></span>
                <span class="kpi-sub">/ 10.0 Days</span>
              </div>
              <div class="kpi-footer neutral">
                <i data-lucide="file-text" style="width:14px;height:14px;"></i>
                <span>Medical Allocation</span>
              </div>
            </div>

            <div class="kpi-card amber">
              <div class="kpi-header">
                <span class="kpi-label">Emergency Leave</span>
                <div class="kpi-icon"><i data-lucide="alert-triangle"></i></div>
              </div>
              <div class="kpi-value-row">
                <span class="kpi-value"><?= number_format($emBalance, 1) ?></span>
                <span class="kpi-sub">/ 5.0 Days</span>
              </div>
              <div class="kpi-footer neutral">
                <i data-lucide="shield" style="width:14px;height:14px;"></i>
                <span>Urgent Personal Leave</span>
              </div>
            </div>

            <?php if ($userGender === 'Female'): ?>
              <div class="kpi-card blue">
                <div class="kpi-header">
                  <span class="kpi-label">Maternity Leave</span>
                  <div class="kpi-icon"><i data-lucide="baby"></i></div>
                </div>
                <div class="kpi-value-row">
                  <span class="kpi-value"><?= number_format($matBalance, 0) ?></span>
                  <span class="kpi-sub">Days Entitlement</span>
                </div>
                <div class="kpi-footer positive">
                  <i data-lucide="check" style="width:14px;height:14px;"></i>
                  <span>Statutory Benefit</span>
                </div>
              </div>
            <?php else: ?>
              <div class="kpi-card blue">
                <div class="kpi-header">
                  <span class="kpi-label">Paternity Leave</span>
                  <div class="kpi-icon"><i data-lucide="user-check"></i></div>
                </div>
                <div class="kpi-value-row">
                  <span class="kpi-value"><?= number_format($patBalance, 1) ?></span>
                  <span class="kpi-sub">/ 7.0 Days</span>
                </div>
                <div class="kpi-footer positive">
                  <i data-lucide="check" style="width:14px;height:14px;"></i>
                  <span>Statutory Benefit</span>
                </div>
              </div>
            <?php endif; ?>
          </div>

          <!-- Leave History Table Card -->
          <div class="dashboard-card">
            <div class="card-head">
              <h3><i data-lucide="clock" style="color:var(--accent);"></i> My Personal Leave History</h3>
              <span style="font-size:12px; color:var(--text-muted);"><?= count($leaveRequests) ?> Applications</span>
            </div>
            <div class="table-responsive">
              <table class="custom-table">
                <thead>
                  <tr>
                    <th>Leave Category</th>
                    <th>Inclusive Dates</th>
                    <th>Working Days</th>
                    <th>Reason / Details</th>
                    <th>Attachment</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($leaveRequests)): ?>
                    <tr>
                      <td colspan="7" style="text-align:center; padding:36px; color:var(--text-muted);">
                        <i data-lucide="calendar" style="width:28px;height:28px;margin:0 auto 8px;display:block;opacity:0.4;"></i>
                        No leave applications filed yet. Click <strong>"File Leave Request"</strong> to submit an application.
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($leaveRequests as $req): ?>
                      <tr>
                        <td>
                          <span class="badge badge-vl"><?= htmlspecialchars($req['leave_type_label']) ?></span>
                          <div style="font-size:10.5px; color:var(--text-light); margin-top:2px;">Ref: <?= $req['ref_no'] ?></div>
                        </td>
                        <td>
                          <div style="font-weight:600;"><?= $req['start_date'] ?> <?= $req['start_date'] !== $req['end_date'] ? 'to ' . $req['end_date'] : '' ?></div>
                        </td>
                        <td><strong><?= $req['days_count'] ?> Day(s)</strong></td>
                        <td>
                          <div style="font-size:12.5px; max-width:240px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($req['reason']) ?>">
                            <?= htmlspecialchars($req['reason']) ?>
                          </div>
                        </td>
                        <td>
                          <?php if (!empty($req['attachment_path'])): ?>
                            <a href="<?= htmlspecialchars($req['attachment_path']) ?>" target="_blank" class="btn-icon" title="View Uploaded Document" style="color:var(--accent);">
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
             TAB 2: TEAM CALENDAR
             ============================================== -->
        <div id="tab-calendar" class="tab-pane" style="display:none;">
          <div class="page-header">
            <div class="page-title">
              <h1>Team Leave Calendar</h1>
              <p>View upcoming firm leave schedules and official Philippine public holidays.</p>
            </div>
          </div>

          <!-- Quick Filter Toolbar -->
          <div class="calendar-toolbar-bar">
            <div class="filter-group">
              <span class="filter-label">Filter Associate:</span>
              <select id="calFilterUser" class="form-select" style="padding:6px 10px; font-size:12px;" onchange="refreshCalendarEvents()">
                <option value="0">All Firm Associates</option>
                <?php foreach ($allUsers as $u): ?>
                  <option value="<?= $u['id'] ?>" <?= $u['id'] == $user['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="filter-group">
              <span class="filter-label">Category:</span>
              <select id="calFilterType" class="form-select" style="padding:6px 10px; font-size:12px;" onchange="refreshCalendarEvents()">
                <option value="ALL">All Categories</option>
                <option value="VL">Vacation Leave</option>
                <option value="SL">Sick Leave</option>
                <option value="Emergency">Emergency Leave</option>
                <option value="Bereavement">Bereavement Leave</option>
                <option value="Maternity">Maternity Leave</option>
                <option value="Paternity">Paternity Leave</option>
                <option value="SoloParent">Solo Parent Leave</option>
                <option value="SpecialWomen">Special Leave for Women</option>
                <option value="LWOP">Leave Without Pay</option>
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
              <div class="legend-chip"><span class="dot" style="background:#059669;"></span> Vacation Leave</div>
              <div class="legend-chip"><span class="dot" style="background:#e11d48;"></span> Sick Leave</div>
              <div class="legend-chip"><span class="dot" style="background:#ea580c;"></span> Emergency Leave</div>
              <div class="legend-chip"><span class="dot" style="background:#475569;"></span> Bereavement Leave</div>
              <div class="legend-chip"><span class="dot" style="background:#7c3aed;"></span> Maternity Leave</div>
              <div class="legend-chip"><span class="dot" style="background:#0284c7;"></span> Paternity Leave</div>
              <div class="legend-chip"><span class="dot" style="background:#d97706;"></span> Solo Parent Leave</div>
              <div class="legend-chip"><span class="dot" style="background:#9333ea;"></span> Special Leave for Women</div>
              <div class="legend-chip"><span class="dot" style="background:#dc2626;"></span> <i data-lucide="flag" style="width:12px;height:12px;"></i> Regular Holiday</div>
              <div class="legend-chip"><span class="dot" style="background:#7c2d12;"></span> <i data-lucide="flag" style="width:12px;height:12px;"></i> Special Holiday</div>
            </div>
          </div>
        </div>

        <!-- ==============================================
             TAB 3: MY ATTENDANCE & DTR
             ============================================== -->
        <div id="tab-attendance" class="tab-pane" style="display:none;">
          <div class="page-header">
            <div class="page-title">
              <h1>My Attendance &amp; Daily Time Record</h1>
              <p>Physical ZKTeco MB460 Plus records &bull; Rendered work hours &bull; Official Form 48 DTR export.</p>
            </div>
            <div class="header-actions" style="display:flex; gap:8px; flex-wrap:wrap;">
              <button class="btn-primary" onclick="openStaffPrintDtr()">
                <i data-lucide="printer"></i>
                <span>Print Form 48 DTR</span>
              </button>
              <button class="btn-secondary" onclick="openModal('staffOtModal')">
                <i data-lucide="clock"></i>
                <span>Request Overtime</span>
              </button>
              <button class="btn-secondary" onclick="openModal('staffCorrectionModal')">
                <i data-lucide="edit-3"></i>
                <span>Missed Punch Adjustment</span>
              </button>
            </div>
          </div>

          <!-- Associate Attendance KPIs -->
          <div class="kpi-grid">
            <div class="kpi-card purple">
              <div class="kpi-header">
                <span class="kpi-label">Biometric Terminal Profile</span>
                <div class="kpi-icon"><i data-lucide="fingerprint"></i></div>
              </div>
              <div class="kpi-value-row">
                <span class="kpi-value" style="font-size:20px;">PIN #<?= htmlspecialchars($user['biometric_pin'] ?: $user['id']) ?></span>
              </div>
              <div class="kpi-footer positive">
                <i data-lucide="check-circle" style="width:14px;height:14px;"></i>
                <span>Face &amp; Fingerprint Enrolled</span>
              </div>
            </div>

            <div class="kpi-card green">
              <div class="kpi-header">
                <span class="kpi-label">Rendered Work Hours</span>
                <div class="kpi-icon"><i data-lucide="activity"></i></div>
              </div>
              <div class="kpi-value-row">
                <span class="kpi-value" id="staffRenderedHours">0.0</span>
                <span class="kpi-sub">Total Hours</span>
              </div>
              <div class="kpi-footer neutral">
                <i data-lucide="clock" style="width:14px;height:14px;"></i>
                <span id="staffRenderedFormatted">0 hrs</span>
              </div>
            </div>

            <div class="kpi-card blue">
              <div class="kpi-header">
                <span class="kpi-label">Approved Overtime</span>
                <div class="kpi-icon"><i data-lucide="award"></i></div>
              </div>
              <div class="kpi-value-row">
                <span class="kpi-value" id="staffOtHours">0.0</span>
                <span class="kpi-sub">Hours Rendered</span>
              </div>
              <div class="kpi-footer positive">
                <i data-lucide="shield-check" style="width:14px;height:14px;"></i>
                <span>Pre-Approved Overtime</span>
              </div>
            </div>

            <div class="kpi-card amber">
              <div class="kpi-header">
                <span class="kpi-label">Today's Office Status</span>
                <div class="kpi-icon"><i data-lucide="user-check"></i></div>
              </div>
              <div class="kpi-value-row">
                <span class="kpi-value" style="font-size:18px;" id="staffTodayPresence">Checking...</span>
              </div>
              <div class="kpi-footer neutral">
                <i data-lucide="map-pin" style="width:14px;height:14px;"></i>
                <span id="staffTodayPunchDesc">Real-Time Presence</span>
              </div>
            </div>
          </div>

          <!-- Attendance Calendar Filter Toolbar -->
          <div class="dashboard-card" style="margin-bottom: 20px;">
            <div class="card-head" style="flex-wrap:wrap; gap:12px;">
              <div style="display:flex; align-items:center; gap:8px;">
                <i data-lucide="calendar" style="color:var(--accent);"></i>
                <h3 style="margin:0;">Attendance Calendar Period</h3>
              </div>
              <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <div class="period-quick-pills">
                  <button type="button" class="btn-period active" onclick="setStaffDatePeriod('full_month', this)">Full Month</button>
                  <button type="button" class="btn-period" onclick="setStaffDatePeriod('first_half', this)">1st Half (1st–15th)</button>
                  <button type="button" class="btn-period" onclick="setStaffDatePeriod('second_half', this)">2nd Half (16th–End)</button>
                  <button type="button" class="btn-period" onclick="setStaffDatePeriod('today', this)">Today</button>
                </div>
                <input type="month" id="staffMonthPicker" class="form-input" style="width:auto; padding:6px 12px; font-size:12.5px;" value="<?= date('Y-m') ?>" onchange="loadStaffDtr()">
              </div>
            </div>

            <!-- Attendance Ledger Table -->
            <div class="table-responsive" style="margin-top:12px;">
              <table class="custom-table" id="staffDtrTable">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Time In</th>
                    <th>Break Out</th>
                    <th>Break In</th>
                    <th>Time Out</th>
                    <th>Rendered Hours</th>
                    <th>Overtime</th>
                    <th>Verification</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody id="staffDtrTbody">
                  <tr>
                    <td colspan="9" style="text-align:center; padding:30px; color:var(--text-muted);">
                      <i data-lucide="loader-2" class="spin" style="width:20px; height:20px; display:inline-block; vertical-align:middle; margin-right:8px;"></i>
                      Loading attendance records...
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Real-Time Team Presence Roster Widget -->
          <div class="dashboard-card" style="margin-bottom: 20px;">
            <div class="card-head">
              <h3><i data-lucide="users" style="color:var(--accent);"></i> Live Office Presence Board (Who's In / Who's Out)</h3>
              <span style="font-size:12px; color:var(--text-muted);" id="presenceDateLabel">Today</span>
            </div>
            <div style="display:flex; flex-wrap:wrap; gap:12px; padding:16px;" id="presenceRosterContainer">
              <div style="color:var(--text-muted); font-size:13px;">Loading team presence...</div>
            </div>
          </div>

          <!-- Requests History: Corrections & Overtime -->
          <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap:16px;">
            <div class="dashboard-card">
              <div class="card-head">
                <h4><i data-lucide="edit-3" style="color:var(--accent);"></i> My Missed Punch Adjustments</h4>
                <button class="btn-secondary" style="font-size:11.5px; padding:4px 8px;" onclick="openModal('staffCorrectionModal')">New Adjustment</button>
              </div>
              <div class="table-responsive">
                <table class="custom-table" style="font-size:12px;">
                  <thead>
                    <tr>
                      <th>Date</th>
                      <th>Adjusted Times</th>
                      <th>Reason</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody id="staffCorrectionsTbody">
                    <tr><td colspan="4" style="text-align:center; color:var(--text-muted);">Loading requests...</td></tr>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="dashboard-card">
              <div class="card-head">
                <h4><i data-lucide="clock" style="color:var(--accent);"></i> My Overtime Pre-Approvals</h4>
                <button class="btn-secondary" style="font-size:11.5px; padding:4px 8px;" onclick="openModal('staffOtModal')">Request OT</button>
              </div>
              <div class="table-responsive">
                <table class="custom-table" style="font-size:12px;">
                  <thead>
                    <tr>
                      <th>OT Date</th>
                      <th>Hours</th>
                      <th>Reason / Project</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody id="staffOtTbody">
                    <tr><td colspan="4" style="text-align:center; color:var(--text-muted);">Loading requests...</td></tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

      </div>
    </main>
  </div>

  <!-- ==============================================
       MODALS
       ============================================== -->

  <!-- 1. FILE LEAVE MODAL (GENDER RESTRICTED & HOLIDAY EXCLUSION) -->
  <div class="modal-backdrop" id="applyModal">
    <div class="modal-window">
      <div class="modal-header">
        <h3><i data-lucide="calendar-plus"></i> File Leave Application</h3>
        <button class="btn-close-modal" onclick="closeModal('applyModal')">&times;</button>
      </div>
      <form id="phpLeaveForm" onsubmit="handleBackendLeaveSubmit(event)" enctype="multipart/form-data">
        <div class="modal-body">
          <div class="form-group" style="margin-bottom: 14px;">
            <label class="form-label">Leave Category <span class="req">*</span></label>
            <select name="leave_type" id="applyLeaveType" class="form-select" required onchange="calculateWorkingDaysPreview()">
              <?php foreach ($activeLeaveTypes as $lt): 
                $isGenderRestricted = ($lt['gender_restriction'] === 'Female' && $userGender === 'Male') || ($lt['gender_restriction'] === 'Male' && $userGender === 'Female');
                if ($isGenderRestricted) continue;
                $remDays = $userAllocMap[$lt['code']] ?? (float)$lt['default_days'];
              ?>
                <option value="<?= htmlspecialchars($lt['code']) ?>"
                  data-gender="<?= htmlspecialchars($lt['gender_restriction']) ?>"
                  data-paid="<?= $lt['is_paid'] ?>"
                  data-proof="<?= $lt['requires_attachment'] ?>"
                  <?= $lt['code'] === 'VL' ? 'selected' : '' ?>>
                  <?= htmlspecialchars($lt['name']) ?> (<?= $remDays ?>d available)<?= $lt['is_paid'] ? '' : ' [Unpaid]' ?>
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
              <div style="font-size:11px; color:var(--text-light);">Weekends &amp; PH Holidays excluded</div>
            </div>
            <div style="text-align:right;">
              <div class="calc-label">Available Balance:</div>
              <div class="calc-result" id="availableBalancePreview" style="color:var(--accent);"><?= number_format($vlBalance, 1) ?> Days</div>
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
            <textarea name="reason" id="applyReason" class="form-textarea" placeholder="Enter reason and client engagement coverage details..." required></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-secondary" onclick="closeModal('applyModal')">Cancel</button>
          <button type="submit" class="btn-primary" id="btnSubmitLeave">Submit Application</button>
        </div>
      </form>
    </div>
  </div>

  <!-- 2. DETAILS & PRINT SLIP MODAL -->
  <div class="modal-backdrop" id="detailsModal">
    <div class="modal-window">
      <div class="modal-header">
        <h3><i data-lucide="file-text"></i> Application Details</h3>
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
            <div><span style="color:var(--text-muted);">Applicant:</span> <strong id="dtlStaff" style="color:var(--primary);"></strong></div>
            <div><span style="color:var(--text-muted);">Title:</span> <span id="dtlTitle"></span></div>
            <div><span style="color:var(--text-muted);">Category:</span> <strong id="dtlType"></strong></div>
            <div><span style="color:var(--text-muted);">Duration:</span> <strong id="dtlDays" style="color:var(--accent);"></strong></div>
            <div style="grid-column: span 2;"><span style="color:var(--text-muted);">Dates:</span> <span id="dtlDates" style="font-weight:600;"></span></div>
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
          <div style="font-size:10.5px; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:4px;">Partner Review Status:</div>
          <div style="font-weight:600; color:var(--primary);" id="dtlApprover"></div>
          <div id="dtlRejectionReason" style="font-size:12px; color:var(--danger); margin-top:4px; display:none;"></div>
        </div>
      </div>
      <div class="modal-footer" style="justify-content:space-between;">
        <button type="button" class="btn-secondary" onclick="closeModal('detailsModal')">Close</button>
        <button type="button" class="btn-primary" id="dtlPrintBtn" style="display:none;">
          <i data-lucide="printer"></i>
          <span>Print Official Leave Slip</span>
        </button>
      </div>
    </div>
  </div>

  <!-- 3. PRINTABLE OFFICIAL LEAVE SLIP MODAL -->
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
              <div class="sign-line" id="slipStaffSign"><?= htmlspecialchars($user['name']) ?></div>
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

  <!-- 4. MY PROFILE / CHANGE PASSWORD MODAL -->
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
              <label class="form-label">System Role</label>
              <input type="text" class="form-input" value="<?= ucfirst($user['role']) ?> Associate" readonly style="background:var(--bg-subtle); color:var(--text-muted); cursor:not-allowed;" title="Role is designated by Managing Partner">
              <input type="hidden" name="role" value="<?= htmlspecialchars($user['role']) ?>">
            </div>
          </div>

          <div class="form-group" style="margin-bottom:16px;">
            <label class="form-label">Email Address <span class="req">*</span></label>
            <input type="email" name="email" id="myProfileEmail" class="form-input" value="<?= htmlspecialchars($user['email']) ?>" required>
            <div style="font-size:11px; color:var(--text-muted); margin-top:3px;">
              <i data-lucide="bell" style="width:11px;height:11px; vertical-align:middle;"></i> Note: Changing your email address will immediately notify the Managing Partner.
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

  <!-- 4. MISSED PUNCH CORRECTION MODAL -->
  <div class="modal-backdrop" id="staffCorrectionModal">
    <div class="modal-window">
      <div class="modal-header">
        <h3><i data-lucide="edit-3"></i> File Missed Punch Adjustment</h3>
        <button class="btn-close-modal" onclick="closeModal('staffCorrectionModal')">&times;</button>
      </div>
      <form id="staffCorrectionForm" onsubmit="handleStaffCorrectionSubmit(event)">
        <div class="modal-body">
          <p style="font-size:12.5px; color:var(--text-muted); margin-bottom:14px;">
            Submit an attendance correction if you missed scanning at the ZKTeco MB460 Plus terminal or if your scan failed to register.
          </p>

          <div class="form-group" style="margin-bottom:12px;">
            <label class="form-label">Target Date <span class="req">*</span></label>
            <input type="date" name="target_date" id="corrTargetDate" class="form-input" required value="<?= date('Y-m-d') ?>">
          </div>

          <div class="form-grid">
            <div class="form-group">
              <label class="form-label">Time In (AM Arrival)</label>
              <input type="time" name="time_in" id="corrTimeIn" class="form-input">
            </div>
            <div class="form-group">
              <label class="form-label">Break Out (Lunch)</label>
              <input type="time" name="break_out" id="corrBreakOut" class="form-input">
            </div>
            <div class="form-group">
              <label class="form-label">Break In (Return)</label>
              <input type="time" name="break_in" id="corrBreakIn" class="form-input">
            </div>
            <div class="form-group">
              <label class="form-label">Time Out (PM Departure)</label>
              <input type="time" name="time_out" id="corrTimeOut" class="form-input">
            </div>
          </div>

          <div class="form-group" style="margin-top:10px;">
            <label class="form-label">Reason for Adjustment <span class="req">*</span></label>
            <textarea name="reason" id="corrReason" class="form-input" rows="3" placeholder="e.g. Device face scan failed due to lighting, or forgot to scan upon arrival" required></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-secondary" onclick="closeModal('staffCorrectionModal')">Cancel</button>
          <button type="submit" class="btn-primary" id="btnSubmitCorr">Submit Adjustment</button>
        </div>
      </form>
    </div>
  </div>

  <!-- 5. REQUEST OVERTIME MODAL -->
  <div class="modal-backdrop" id="staffOtModal">
    <div class="modal-window">
      <div class="modal-header">
        <h3><i data-lucide="clock"></i> Request Overtime Pre-Approval</h3>
        <button class="btn-close-modal" onclick="closeModal('staffOtModal')">&times;</button>
      </div>
      <form id="staffOtForm" onsubmit="handleStaffOtSubmit(event)">
        <div class="modal-body">
          <p style="font-size:12.5px; color:var(--text-muted); margin-bottom:14px;">
            Request pre-authorization for extended work hours during tax filing, quarterly reporting, or audit engagements.
          </p>

          <div class="form-grid">
            <div class="form-group">
              <label class="form-label">Overtime Date <span class="req">*</span></label>
              <input type="date" name="ot_date" id="otDate" class="form-input" required value="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Estimated Hours <span class="req">*</span></label>
              <input type="number" step="0.5" min="0.5" max="12" name="estimated_hours" id="otHours" class="form-input" placeholder="e.g. 2.5" required>
            </div>
          </div>

          <div class="form-group" style="margin-top:12px;">
            <label class="form-label">Engagement / Audit Purpose <span class="req">*</span></label>
            <textarea name="reason" id="otReason" class="form-input" rows="3" placeholder="e.g. BIR Monthly VAT Return & Withholding Tax finalization for client ABC Corp." required></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-secondary" onclick="closeModal('staffOtModal')">Cancel</button>
          <button type="submit" class="btn-primary" id="btnSubmitOt">Submit Overtime Request</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Custom Confirmation Dialog Modal -->
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

  <!-- JavaScript Controller -->
  <script>
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

    const todayStr = '<?= date('Y-m-d') ?>';
    document.getElementById('applyStartDate').value = todayStr;
    document.getElementById('applyEndDate').value = todayStr;

    const myBalances = {
      'VL': <?= (float)$vlBalance ?>,
      'SL': <?= (float)$slBalance ?>,
      'Emergency': <?= (float)$emBalance ?>,
      'SoloParent': <?= (float)$splBalance ?>,
      'Maternity': <?= (float)$matBalance ?>,
      'Paternity': <?= (float)$patBalance ?>,
      'SpecialWomen': <?= (float)$specBalance ?>
    };

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
        localStorage.setItem('jtyeo_staff_active_tab', tabId);
        if (updateState && history.replaceState) {
          history.replaceState(null, '', '#' + tabId);
        }
      } catch (e) {}

      if (tabId === 'overall') {
        if (typeof loadStaffOverallDashboard === 'function') loadStaffOverallDashboard();
      }
      if (tabId === 'calendar' && typeof calendarInstance !== 'undefined' && calendarInstance) {
        setTimeout(() => calendarInstance.render(), 50);
      }
      if (tabId === 'attendance') {
        if (typeof loadStaffDtr === 'function') loadStaffDtr();
      }
      if (window.lucide) lucide.createIcons();
    }

    function initSavedTab() {
      const hashTab = window.location.hash.replace('#', '').trim();
      const savedTab = hashTab || localStorage.getItem('jtyeo_staff_active_tab') || 'overall';
      if (savedTab && document.getElementById(`tab-${savedTab}`)) {
        switchTab(savedTab, false);
      } else {
        switchTab('overall', false);
      }
      if (savedTab === 'attendance') {
        if (typeof loadStaffDtr === 'function') loadStaffDtr();
      } else if (savedTab === 'overall') {
        if (typeof loadStaffOverallDashboard === 'function') loadStaffOverallDashboard();
      }
    }

    // ==========================================
    // Overall Dashboard Functions (Staff Associate)
    // ==========================================
    function loadStaffOverallDashboard() {
      fetch('actions/get_dashboard_summary.php')
        .then(res => res.json())
        .then(data => {
          if (!data.success) return;

          // 1. Today's Punch Strip
          const p = data.today_punch;
          const inBox = document.getElementById('staffPunchInBox');
          const boBox = document.getElementById('staffPunchBreakOutBox');
          const biBox = document.getElementById('staffPunchBreakInBox');
          const outBox = document.getElementById('staffPunchOutBox');

          const inEl = document.getElementById('staffSlotTimeIn');
          const boEl = document.getElementById('staffSlotBreakOut');
          const biEl = document.getElementById('staffSlotBreakIn');
          const outEl = document.getElementById('staffSlotTimeOut');
          const rendEl = document.getElementById('staffTodayRenderedText');

          if (p) {
            if (p.time_in) {
              if (inEl) inEl.innerText = p.time_in_12 || p.time_in;
              if (inBox) inBox.classList.add('recorded');
            }
            if (p.break_out) {
              if (boEl) boEl.innerText = p.break_out_12 || p.break_out;
              if (boBox) boBox.classList.add('recorded');
            }
            if (p.break_in) {
              if (biEl) biEl.innerText = p.break_in_12 || p.break_in;
              if (biBox) biBox.classList.add('recorded');
            }
            if (p.time_out) {
              if (outEl) outEl.innerText = p.time_out_12 || p.time_out;
              if (outBox) outBox.classList.add('recorded');
            }
            if (rendEl) {
              rendEl.innerText = p.rendered_formatted || '0.00 hrs';
            }
          }

          // 2. Month Performance & Overtime
          if (data.month_stats) {
            const mEl = document.getElementById('staffOverallMonthHours');
            const otEl = document.getElementById('staffOverallMonthOt');
            if (mEl) mEl.innerText = data.month_stats.rendered_formatted || (data.month_stats.rendered_hours + ' hrs');
            if (otEl) otEl.innerText = (data.month_stats.overtime_hours || 0) + ' hrs approved OT';
          }

          // 3. Pending counts
          if (data.pending_counts) {
            const pcEl = document.getElementById('staffOverallPendingCount');
            const pbEl = document.getElementById('staffOverallPendingBreakdown');
            if (pcEl) pcEl.innerText = data.pending_counts.total;
            if (pbEl) {
              pbEl.innerText = `${data.pending_counts.leaves} Leaves, ${data.pending_counts.corrections} Punches, ${data.pending_counts.ot} OT`;
            }
          }

          // 4. Recent Requests
          renderStaffOverallRecent(data.recent_requests || []);

          // 5. Team Presence
          renderStaffOverallPresence();

          // 6. Upcoming schedule
          renderStaffOverallSchedule(data.upcoming_holidays || [], data.upcoming_leaves || []);

          if (window.lucide) lucide.createIcons();
        })
        .catch(err => {
          console.error('Error loading staff overall dashboard:', err);
        });
    }

    function renderStaffOverallRecent(requests) {
      const container = document.getElementById('staffOverallRecentList');
      if (!container) return;

      if (!requests || requests.length === 0) {
        container.innerHTML = '<div style="text-align:center; padding:20px; color:var(--text-muted); font-size:12.5px;">No applications filed recently. Use the Quick Actions launcher to file leaves, adjustments, or overtime!</div>';
        return;
      }

      container.innerHTML = requests.map(r => {
        let pillClass = 'warning';
        if (r.status === 'Approved') pillClass = 'active';
        else if (r.status === 'Rejected') pillClass = 'inactive';

        let typeBadge = 'Leave';
        let iconName = 'calendar';
        if (r.type === 'correction') {
          typeBadge = 'Punch Adjustment';
          iconName = 'clock';
        } else if (r.type === 'ot') {
          typeBadge = 'Overtime';
          iconName = 'trending-up';
        }

        return `
          <div style="display:flex; align-items:center; justify-content:space-between; padding:10px 14px; background:var(--bg-subtle, #f8fafc); border:1px solid var(--border-color, #e2e8f0); border-radius:8px;">
            <div style="display:flex; align-items:center; gap:12px;">
              <div style="width:32px; height:32px; border-radius:8px; background:#fff; border:1px solid var(--border-color); display:flex; align-items:center; justify-content:center; color:var(--primary);">
                <i data-lucide="${iconName}" style="width:16px; height:16px;"></i>
              </div>
              <div>
                <div style="font-size:13px; font-weight:700; color:var(--text-main);">${r.title}</div>
                <div style="font-size:11.5px; color:var(--text-muted);">${r.date_from}${r.date_to && r.date_to !== r.date_from ? ' to ' + r.date_to : ''} &bull; Ref: ${r.id_label}</div>
              </div>
            </div>
            <div>
              <span class="status-pill ${pillClass}">
                <span class="status-dot"></span>
                <span>${r.status}</span>
              </span>
            </div>
          </div>
        `;
      }).join('');

      if (window.lucide) lucide.createIcons();
    }

    function renderStaffOverallPresence() {
      fetch('actions/get_presence.php')
        .then(res => res.json())
        .then(data => {
          if (!data.success) return;
          const container = document.getElementById('staffOverallPresenceRoster');
          if (!container) return;

          if (!data.roster || data.roster.length === 0) {
            container.innerHTML = '<div style="text-align:center; padding:12px; color:var(--text-muted); font-size:12px;">No active roster available.</div>';
            return;
          }

          container.innerHTML = data.roster.map(u => {
            const avatarMarkup = u.avatar_path 
              ? `<img src="${u.avatar_path}" alt="Avatar" style="width:26px; height:26px; border-radius:50%; object-fit:cover;">`
              : `<div style="width:26px; height:26px; border-radius:50%; background:var(--primary); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:10px;">${u.avatar_initials || 'A'}</div>`;

            let pillClass = 'inactive';
            if (u.state === 'present') pillClass = 'active';
            else if (u.state === 'on_break') pillClass = 'warning';
            else if (u.state === 'on_leave') pillClass = 'info';

            return `
              <div class="presence-roster-item" style="padding:6px 8px;">
                <div class="presence-roster-user" style="gap:8px;">
                  ${avatarMarkup}
                  <div class="user-meta">
                    <span class="name" style="font-size:12px;">${u.name}</span>
                    <span class="title" style="font-size:10.5px;">${u.title || 'Associate'}</span>
                  </div>
                </div>
                <span class="status-pill ${pillClass}" style="font-size:10px; padding: 2px 6px;">
                  <span class="status-dot"></span>
                  <span>${u.state_label}</span>
                </span>
              </div>
            `;
          }).join('');
        })
        .catch(err => {
          console.error('Staff presence fetch error:', err);
        });
    }

    function renderStaffOverallSchedule(holidays, leaves) {
      const container = document.getElementById('staffOverallScheduleList');
      if (!container) return;

      const hasHolidays = holidays && holidays.length > 0;
      const hasLeaves = leaves && leaves.length > 0;

      if (!hasHolidays && !hasLeaves) {
        container.innerHTML = '<div style="text-align:center; padding:14px; color:var(--text-muted); font-size:12px;">No upcoming leaves or Philippine statutory holidays in the next 7 days.</div>';
        return;
      }

      let html = '';
      if (hasHolidays) {
        holidays.forEach(h => {
          html += `
            <div style="display:flex; align-items:flex-start; gap:8px; padding:6px 8px; border-radius:6px; background:#fef3c7; border:1px solid #fde68a;">
              <i data-lucide="flag" style="width:13px; height:13px; color:#b45309; flex-shrink:0; margin-top:2px;"></i>
              <div>
                <div style="font-size:11.5px; font-weight:700; color:#92400e;">${h.title}</div>
                <div style="font-size:10.5px; color:#b45309;">${h.holiday_date} &bull; ${h.holiday_type || 'Regular Holiday'}</div>
              </div>
            </div>
          `;
        });
      }

      if (hasLeaves) {
        leaves.forEach(lv => {
          html += `
            <div style="display:flex; align-items:flex-start; gap:8px; padding:6px 8px; border-radius:6px; background:#eff6ff; border:1px solid #bfdbfe;">
              <i data-lucide="palmtree" style="width:13px; height:13px; color:#2563eb; flex-shrink:0; margin-top:2px;"></i>
              <div>
                <div style="font-size:11.5px; font-weight:700; color:#1e40af;">${lv.employee_name}</div>
                <div style="font-size:10.5px; color:#2563eb;">${lv.leave_type_label} &bull; ${lv.start_date} to ${lv.end_date}</div>
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

    function calculateWorkingDaysPreview() {
      const startStr = document.getElementById('applyStartDate').value;
      const endStr = document.getElementById('applyEndDate').value;
      const type = document.getElementById('applyLeaveType').value;

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

      const balEl = document.getElementById('availableBalancePreview');
      if (balEl) {
        const val = myBalances[type];
        balEl.innerText = (val !== undefined) ? `${parseFloat(val).toFixed(1)} Days` : (type === 'LWOP' ? 'Unlimited (Unpaid)' : '--');
      }

      if (!startStr || !endStr) return;
      const start = new Date(startStr);
      const end = new Date(endStr);
      if (start > end) {
        document.getElementById('computedDaysPreview').innerText = 'Invalid Range';
        return;
      }

      let workingDays = 0;
      let curr = new Date(start);
      while (curr <= end) {
        const day = curr.getDay();
        if (day !== 0 && day !== 6) workingDays++;
        curr.setDate(curr.getDate() + 1);
      }
      document.getElementById('computedDaysPreview').innerText = `${workingDays} Working Day(s)`;
    }
    calculateWorkingDaysPreview();

    // Submit Leave Form
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

    // Staff Attendance & DTR Controller
    let currentStaffPeriod = 'full_month';
    const currentUserId = <?= intval($user['id']) ?>;

    function setStaffDatePeriod(period, btnEl) {
      currentStaffPeriod = period;
      document.querySelectorAll('.period-quick-pills .btn-period').forEach(b => b.classList.remove('active'));
      if (btnEl) btnEl.classList.add('active');
      loadStaffDtr();
    }

    function getStaffDateRange() {
      const monthVal = document.getElementById('staffMonthPicker').value || '<?= date('Y-m') ?>';
      const [yearStr, monthStr] = monthVal.split('-');
      const y = parseInt(yearStr, 10);
      const m = parseInt(monthStr, 10);
      const lastDay = new Date(y, m, 0).getDate();

      let sDay = '01';
      let eDay = String(lastDay).padStart(2, '0');

      if (currentStaffPeriod === 'first_half') {
        eDay = '15';
      } else if (currentStaffPeriod === 'second_half') {
        sDay = '16';
      } else if (currentStaffPeriod === 'today') {
        const today = new Date();
        const tY = today.getFullYear();
        const tM = String(today.getMonth() + 1).padStart(2, '0');
        const tD = String(today.getDate()).padStart(2, '0');
        return { start_date: `${tY}-${tM}-${tD}`, end_date: `${tY}-${tM}-${tD}`, year: y, month: m, period: currentStaffPeriod };
      }

      return {
        start_date: `${yearStr}-${monthStr}-${sDay}`,
        end_date: `${yearStr}-${monthStr}-${eDay}`,
        year: y,
        month: m,
        period: currentStaffPeriod
      };
    }

    function loadStaffDtr() {
      const range = getStaffDateRange();
      const tbody = document.getElementById('staffDtrTbody');
      if (!tbody) return;
      tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:24px; color:var(--text-muted);"><i data-lucide="loader-2" class="spin" style="width:18px;height:18px;display:inline-block;vertical-align:middle;margin-right:8px;"></i> Loading attendance records...</td></tr>`;
      if (window.lucide) lucide.createIcons();

      fetch(`actions/get_dtr_logs.php?user_id=${currentUserId}&start_date=${range.start_date}&end_date=${range.end_date}`)
        .then(res => res.json())
        .then(data => {
          if (!data.success) {
            tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; color:var(--danger); padding:20px;">Failed to load attendance logs.</td></tr>`;
            return;
          }

          document.getElementById('staffRenderedHours').innerText = data.total_rendered_hours ? data.total_rendered_hours.toFixed(1) : '0.0';
          document.getElementById('staffRenderedFormatted').innerText = data.total_rendered_formatted || '0 hrs';

          if (!data.records || data.records.length === 0) {
            tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:30px; color:var(--text-muted);">No attendance or leave entries found for this period.</td></tr>`;
            return;
          }

          let totalOt = 0;
          let todayStatus = 'Not Yet Clocked In';
          let todayPunchDesc = 'No punches registered today';
          const todayIso = new Date().toISOString().slice(0, 10);

          let html = '';
          data.records.forEach(r => {
            totalOt += (r.overtime_hours || 0);

            if (r.log_date === todayIso) {
              todayStatus = r.status_text || r.status;
              if (r.status === 'On Break') {
                todayPunchDesc = `Break Out at ${r.break_out || '-'}`;
              } else if (r.time_out) {
                todayPunchDesc = `Timed Out at ${r.time_out}`;
              } else if (r.time_in) {
                todayPunchDesc = `Time In at ${r.time_in}`;
              }
            }

            let badgeClass = 'badge-neutral';
            if (r.status_type === 'success') badgeClass = 'badge-approved';
            else if (r.status_type === 'warning') badgeClass = 'badge-pending';
            else if (r.status_type === 'leave') badgeClass = 'badge-secondary';

            html += `
              <tr>
                <td><strong>${r.log_date_formatted || r.log_date}</strong></td>
                <td>${r.time_in || '—'}</td>
                <td>${r.break_out || '—'}</td>
                <td>${r.break_in || '—'}</td>
                <td>${r.time_out || '—'}</td>
                <td><strong style="color:var(--primary);">${r.rendered_hours > 0 ? r.rendered_hours + ' hrs' : '—'}</strong></td>
                <td>${r.overtime_hours > 0 ? `<span style="color:#0284c7; font-weight:700;">+${r.overtime_hours} hrs</span>` : '—'}</td>
                <td><span style="font-size:11px; color:var(--text-muted);">${r.verification_method || '—'}</span></td>
                <td><span class="badge ${badgeClass}">${r.status_text || r.status}</span></td>
              </tr>
            `;
          });

          document.getElementById('staffOtHours').innerText = totalOt.toFixed(1);
          document.getElementById('staffTodayPresence').innerText = todayStatus;
          document.getElementById('staffTodayPunchDesc').innerText = todayPunchDesc;
          tbody.innerHTML = html;
          if (window.lucide) lucide.createIcons();
        })
        .catch(err => {
          tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; color:var(--danger); padding:20px;">Connection error loading attendance.</td></tr>`;
        });

      loadStaffPresence();
      loadStaffCorrections();
      loadStaffOt();
    }

    function openStaffPrintDtr() {
      const range = getStaffDateRange();
      const url = `print_dtr.php?user_id=${currentUserId}&year=${range.year}&month=${range.month}&period=${range.period}`;
      window.open(url, '_blank');
    }

    function loadStaffPresence() {
      const container = document.getElementById('presenceRosterContainer');
      if (!container) return;
      fetch('actions/get_presence.php')
        .then(res => res.json())
        .then(data => {
          if (!data.success || !data.roster) return;
          const labelEl = document.getElementById('presenceDateLabel');
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

    function loadStaffCorrections() {
      const tbody = document.getElementById('staffCorrectionsTbody');
      if (!tbody) return;
      fetch('actions/manage_attendance_corrections.php?action=get_corrections')
        .then(res => res.json())
        .then(data => {
          if (!data.success || !data.corrections || data.corrections.length === 0) {
            tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; color:var(--text-muted); padding:16px;">No adjustment requests filed.</td></tr>`;
            return;
          }
          let html = '';
          data.corrections.forEach(c => {
            let badge = 'badge-pending';
            if (c.status === 'Approved') badge = 'badge-approved';
            else if (c.status === 'Rejected') badge = 'badge-rejected';

            let punchStr = [];
            if (c.time_in_12 && c.time_in_12 !== '-') punchStr.push(`In: ${c.time_in_12}`);
            if (c.break_out_12 && c.break_out_12 !== '-') punchStr.push(`B-Out: ${c.break_out_12}`);
            if (c.break_in_12 && c.break_in_12 !== '-') punchStr.push(`B-In: ${c.break_in_12}`);
            if (c.time_out_12 && c.time_out_12 !== '-') punchStr.push(`Out: ${c.time_out_12}`);

            html += `
              <tr>
                <td><strong>${c.target_date_formatted}</strong></td>
                <td><span style="font-size:11px;">${punchStr.join(' &bull; ') || 'Adjustment'}</span></td>
                <td><span style="font-size:11.5px;" title="${c.reason}">${c.reason}</span></td>
                <td><span class="badge ${badge}">${c.status}</span></td>
              </tr>
            `;
          });
          tbody.innerHTML = html;
        });
    }

    function loadStaffOt() {
      const tbody = document.getElementById('staffOtTbody');
      if (!tbody) return;
      fetch('actions/manage_overtime.php?action=get_ot_requests')
        .then(res => res.json())
        .then(data => {
          if (!data.success || !data.requests || data.requests.length === 0) {
            tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; color:var(--text-muted); padding:16px;">No overtime requests filed.</td></tr>`;
            return;
          }
          let html = '';
          data.requests.forEach(r => {
            let badge = 'badge-pending';
            if (r.status === 'Approved') badge = 'badge-approved';
            else if (r.status === 'Rejected') badge = 'badge-rejected';

            html += `
              <tr>
                <td><strong>${r.ot_date_formatted}</strong></td>
                <td><strong style="color:#0284c7;">${r.estimated_hours} hrs</strong></td>
                <td><span style="font-size:11.5px;" title="${r.reason}">${r.reason}</span></td>
                <td><span class="badge ${badge}">${r.status}</span></td>
              </tr>
            `;
          });
          tbody.innerHTML = html;
        });
    }

    function handleStaffCorrectionSubmit(e) {
      e.preventDefault();
      const form = document.getElementById('staffCorrectionForm');
      const formData = new FormData(form);
      formData.append('action', 'submit_correction');

      const btn = document.getElementById('btnSubmitCorr');
      btn.disabled = true;
      btn.innerText = 'Submitting...';

      fetch('actions/manage_attendance_corrections.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(res => {
        btn.disabled = false;
        btn.innerText = 'Submit Adjustment';
        if (res.success) {
          showToast(res.message, 'success');
          closeModal('staffCorrectionModal');
          form.reset();
          loadStaffDtr();
        } else {
          showToast(res.message || 'Failed to submit adjustment.', 'error');
        }
      })
      .catch(err => {
        btn.disabled = false;
        btn.innerText = 'Submit Adjustment';
        showToast('Network error occurred.', 'error');
      });
    }

    function handleStaffOtSubmit(e) {
      e.preventDefault();
      const form = document.getElementById('staffOtForm');
      const formData = new FormData(form);
      formData.append('action', 'submit_ot');

      const btn = document.getElementById('btnSubmitOt');
      btn.disabled = true;
      btn.innerText = 'Submitting...';

      fetch('actions/manage_overtime.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(res => {
        btn.disabled = false;
        btn.innerText = 'Submit Overtime Request';
        if (res.success) {
          showToast(res.message, 'success');
          closeModal('staffOtModal');
          form.reset();
          loadStaffDtr();
        } else {
          showToast(res.message || 'Failed to request overtime.', 'error');
        }
      })
      .catch(err => {
        btn.disabled = false;
        btn.innerText = 'Submit Overtime Request';
        showToast('Network error occurred.', 'error');
      });
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