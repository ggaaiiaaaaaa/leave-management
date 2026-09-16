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

// Fetch Today's Biometric Attendance Logs for Morning Widget
$todayBioStmt = $pdo->prepare("SELECT * FROM biometric_logs WHERE log_date = ?");
$todayBioStmt->execute([$today]);
$todayPunches = $todayBioStmt->fetchAll();
$punchedUserIds = array_column($todayPunches, 'user_id');

$presentCount = count($punchedUserIds);
$onLeaveCount = count($activeLeaves);
$totalStaff = count($allUsers);
$expectedCount = max(0, $totalStaff - $presentCount - $onLeaveCount);

// Upcoming Philippine Holidays
$holidaysStmt = $pdo->query("SELECT * FROM holidays WHERE holiday_date >= date('now') ORDER BY holiday_date ASC LIMIT 5");
$upcomingHolidays = $holidaysStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Managing Partner & HR Portal | JTYeo CPA Accounting Office</title>
  <link rel="stylesheet" href="style.css">
  <script src="lucide.js"></script>
  <!-- FullCalendar 5 -->
  <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
  <style>
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
        <div class="brand-logo">JT</div>
        <div class="brand-info">
          <h2>JTYeo CPA</h2>
          <span>Accounting Office</span>
          <div class="firm-badge">Managing Partner & HR</div>
        </div>
      </div>

      <nav class="sidebar-nav">
        <div class="nav-category">Management Navigation</div>
        <a class="nav-item active" data-tab="overview" onclick="switchTab('overview')">
          <i data-lucide="layout-dashboard"></i>
          <span>Leave Overview</span>
        </a>
        <a class="nav-item" data-tab="approvals" onclick="switchTab('approvals')">
          <i data-lucide="check-circle-2"></i>
          <span>Approvals Queue</span>
          <?php if ($pendingCount > 0): ?>
            <span class="nav-badge" id="pendingApprovalsBadge"><?= $pendingCount ?></span>
          <?php endif; ?>
        </a>
        <a class="nav-item" data-tab="biometrics" onclick="switchTab('biometrics'); loadDtrLogs();">
          <i data-lucide="scan-face"></i>
          <span>Biometric Attendance</span>
        </a>
        <a class="nav-item" data-tab="calendar" onclick="switchTab('calendar'); initCalendar();">
          <i data-lucide="calendar"></i>
          <span>Team Calendar</span>
        </a>
        <a class="nav-item" data-tab="users" onclick="switchTab('users')">
          <i data-lucide="users"></i>
          <span>Associate Management</span>
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
             TAB 1: LEAVE OVERVIEW
             ============================================== -->
        <div id="tab-overview" class="tab-pane">
          <!-- "Who's in the Office Today?" Morning Executive Widget -->
          <div class="morning-executive-banner">
            <div class="morning-header">
              <h3><i data-lucide="sun" style="color:#fbbf24;"></i> Who's in the Office Today?</h3>
              <div class="morning-date-badge"><?= date('l, F j, Y') ?> &bull; ZKTeco Live LAN</div>
            </div>
            <div class="morning-stats-grid">
              <div class="morning-stat-box">
                <div class="morning-stat-label"><i data-lucide="users" style="width:13px;height:13px;"></i> Total Associates</div>
                <div class="morning-stat-num purple"><?= $totalStaff ?></div>
              </div>
              <div class="morning-stat-box">
                <div class="morning-stat-label"><i data-lucide="scan-face" style="width:13px;height:13px;"></i> Present in Office</div>
                <div class="morning-stat-num green"><?= $presentCount ?></div>
              </div>
              <div class="morning-stat-box">
                <div class="morning-stat-label"><i data-lucide="palmtree" style="width:13px;height:13px;"></i> On Approved Leave</div>
                <div class="morning-stat-num blue"><?= $onLeaveCount ?></div>
              </div>
              <div class="morning-stat-box">
                <div class="morning-stat-label"><i data-lucide="clock" style="width:13px;height:13px;"></i> Expected / In Transit</div>
                <div class="morning-stat-num amber"><?= $expectedCount ?></div>
              </div>
            </div>
          </div>

          <div class="page-header">
            <div class="page-title">
              <h1>Firm Master Leave Ledger</h1>
              <p>Complete historical record of all associate leave applications and approvals.</p>
            </div>
            <div class="header-actions">
              <button class="btn-secondary" onclick="openAdjustmentModal()">
                <i data-lucide="scale"></i>
                <span>Adjust Balances</span>
              </button>
              <button class="btn-primary" onclick="openModal('applyModal')">
                <i data-lucide="plus-circle"></i>
                <span>File Leave Request</span>
              </button>
            </div>
          </div>

          <!-- Master Table Card -->
          <div class="dashboard-card">
            <div class="card-head">
              <h3><i data-lucide="layers" style="color:var(--accent);"></i> Associate Leave History</h3>
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
                            <a href="<?= htmlspecialchars($req['attachment_path']) ?>" target="_blank" class="btn-icon" title="View Medical Certificate / Document" style="color:var(--accent);">
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
                              <button class="btn-icon approve" title="Review & Decide" onclick="openDecisionModal('<?= $req['ref_no'] ?>', '<?= addslashes($req['employee_name']) ?>', '<?= addslashes($req['leave_type_label']) ?>', '<?= $req['days_count'] ?>')">
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
              <h3><i data-lucide="inbox" style="color:var(--accent);"></i> Pending Review Queue</h3>
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
                      <tr>
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
                            <a href="<?= htmlspecialchars($p['attachment_path']) ?>" target="_blank" class="btn-icon" title="Inspect Attached Medical Cert">
                              <i data-lucide="paperclip" style="width:14px;height:14px;color:var(--accent);"></i>
                            </a>
                          <?php else: ?>
                            <span style="color:var(--text-light); font-size:12px;">None</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <button class="btn-primary" style="padding:6px 12px; font-size:12px;" onclick="openDecisionModal('<?= $p['ref_no'] ?>', '<?= addslashes($p['employee_name']) ?>', '<?= addslashes($p['leave_type_label']) ?>', '<?= $p['days_count'] ?>')">
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
                  <span class="zkteco-badge-online"><i data-lucide="check" style="width:12px;height:12px;"></i> Online (LAN: 192.168.1.201)</span>
                </div>
              </div>
            </div>
            <div class="zkteco-actions">
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

          <div class="dashboard-card">
            <div class="card-head" style="flex-wrap:wrap; gap:12px;">
              <div>
                <h3><i data-lucide="clock" style="color:var(--accent);"></i> Daily Time Record (DTR) &amp; Attendance</h3>
                <p style="font-size:12px; color:var(--text-muted); margin-top:2px;">Reconciled real-time biometric punches and approved leave records.</p>
              </div>
              <div style="display:flex; align-items:center; gap:10px;">
                <label style="font-size:12px; font-weight:700; color:var(--text-muted);">Select Date:</label>
                <input type="date" id="dtrDatePicker" class="form-input" style="padding:6px 10px; width:160px;" value="<?= $today ?>" onchange="loadDtrLogs()">
              </div>
            </div>

            <div class="table-responsive">
              <table class="custom-table" id="dtrTable">
                <thead>
                  <tr>
                    <th>Associate</th>
                    <th>Device PIN</th>
                    <th>Time In</th>
                    <th>Time Out</th>
                    <th>Verification Method</th>
                    <th>Daily Attendance Status</th>
                  </tr>
                </thead>
                <tbody id="dtrTableBody">
                  <tr><td colspan="6" style="text-align:center; padding:24px;">Loading daily time records...</td></tr>
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
                        </div>
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
          <div style="font-size:11px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Reference</div>
          <div style="font-size:16px; font-weight:800; color:var(--primary); font-family:monospace;" id="decModalRef">LR-2026-XXX</div>
          <div style="font-size:13px; margin-top:8px;"><strong>Associate:</strong> <span id="decModalStaff"></span></div>
          <div style="font-size:13px;"><strong>Category:</strong> <span id="decModalType"></span> (<span id="decModalDays"></span> working day/s)</div>
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
              <option value="VL" selected>Vacation Leave</option>
              <option value="SL">Sick Leave</option>
              <option value="Emergency">Emergency Leave</option>
              <option value="Bereavement">Bereavement Leave</option>
              <option value="SoloParent">Solo Parent Leave</option>
              <option value="Maternity" id="optMaternity">Maternity Leave (Female Only)</option>
              <option value="SpecialWomen" id="optSpecialWomen">Special Leave for Women (Female Only)</option>
              <option value="Paternity" id="optPaternity">Paternity Leave (Male Only)</option>
              <option value="LWOP">Leave Without Pay</option>
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

          <!-- Medical Certificate Attachment (Only visible for Emergency & Sick Leave) -->
          <div class="form-group" id="attachmentGroup" style="margin-top:14px; display:none;">
            <label class="form-label"><i data-lucide="paperclip" style="width:13px;height:13px;"></i> Attach Medical Certificate / Proof (Optional):</label>
            <input type="file" name="attachment" id="applyAttachment" class="form-input" accept=".pdf,.jpg,.jpeg,.png">
            <div style="font-size:11px; color:var(--text-muted); margin-top:3px;">Supported: PDF, JPG, PNG (Max 10MB)</div>
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
              <option value="VL">Vacation Leave</option>
              <option value="SL">Sick Leave</option>
              <option value="Emergency">Emergency Leave</option>
              <option value="Bereavement">Bereavement Leave</option>
              <option value="SoloParent">Solo Parent Leave</option>
              <option value="Maternity">Maternity Leave</option>
              <option value="Paternity">Paternity Leave</option>
              <option value="SpecialWomen">Special Leave for Women</option>
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
                <option value="time_in">Time In (Arrival)</option>
                <option value="time_out">Time Out (Departure)</option>
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
        <div class="modal-footer">
          <button type="button" class="btn-secondary" onclick="closeModal('editUserModal')">Cancel</button>
          <button type="submit" class="btn-primary" id="btnEditUserBtn">Save Profile Changes</button>
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

    function switchTab(tabId) {
      document.querySelectorAll('.sidebar-nav .nav-item').forEach(item => {
        item.classList.remove('active');
        if (item.getAttribute('data-tab') === tabId) item.classList.add('active');
      });
      document.querySelectorAll('.tab-pane').forEach(pane => pane.style.display = 'none');
      const activePane = document.getElementById(`tab-${tabId}`);
      if (activePane) activePane.style.display = 'block';
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
      const selectedOption = userSelect.options[userSelect.selectedIndex];
      const gender = (selectedOption.getAttribute('data-gender') || 'Female').toLowerCase();

      const optMat = document.getElementById('optMaternity');
      const optSpec = document.getElementById('optSpecialWomen');
      const optPat = document.getElementById('optPaternity');

      if (gender === 'male') {
        optMat.disabled = true;
        optSpec.disabled = true;
        optPat.disabled = false;
        if (['Maternity', 'SpecialWomen'].includes(document.getElementById('applyLeaveType').value)) {
          document.getElementById('applyLeaveType').value = 'VL';
        }
      } else {
        optMat.disabled = false;
        optSpec.disabled = false;
        optPat.disabled = true;
        if (document.getElementById('applyLeaveType').value === 'Paternity') {
          document.getElementById('applyLeaveType').value = 'VL';
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

      // Only visible for Emergency Leave and Sick Leave
      const attGroup = document.getElementById('attachmentGroup');
      if (attGroup) {
        if (type === 'SL' || type === 'Emergency') {
          attGroup.style.display = 'block';
        } else {
          attGroup.style.display = 'none';
          const attInput = document.getElementById('applyAttachment');
          if (attInput) attInput.value = '';
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
    function openDecisionModal(refNo, empName, leaveType, days) {
      currentDecRef = refNo;
      document.getElementById('decModalRef').innerText = refNo;
      document.getElementById('decModalStaff').innerText = empName;
      document.getElementById('decModalType').innerText = leaveType;
      document.getElementById('decModalDays').innerText = days;
      document.getElementById('decModalNote').value = '';
      openModal('decisionModal');
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

    // DTR / Biometric Attendance Loader
    async function loadDtrLogs() {
      const dateVal = document.getElementById('dtrDatePicker').value || todayStr;
      const tbody = document.getElementById('dtrTableBody');
      tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:20px;"><div style="font-size:12px; color:var(--text-muted);">Fetching ZKTeco MB460 Plus records for ${dateVal}...</div></td></tr>`;
      try {
        const res = await fetch(`actions/get_dtr_logs.php?date=${dateVal}`);
        const data = await res.json();
        if (data.success && data.records) {
          if (data.records.length === 0) {
            tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:20px; color:var(--text-muted);">No records found for ${dateVal}.</td></tr>`;
            return;
          }
          let rowsHtml = '';
          data.records.forEach(r => {
            let statusBadge = '';
            if (r.status === 'On Leave') {
              statusBadge = `<span class="badge-leave"><i data-lucide="palmtree" style="width:12px;height:12px;"></i> ${r.status_text}</span>`;
            } else if (r.status === 'On-Time') {
              statusBadge = `<span class="badge-ontime"><i data-lucide="check" style="width:12px;height:12px;"></i> On-Time</span>`;
            } else if (r.status === 'Late') {
              statusBadge = `<span class="badge-late"><i data-lucide="alert-circle" style="width:12px;height:12px;"></i> Late</span>`;
            } else {
              statusBadge = `<span style="color:var(--text-light); font-size:12px; font-weight:600;">Expected / In Transit</span>`;
            }

            let methodBadge = '—';
            if (r.verification_method === 'Face Scan') {
              methodBadge = `<span class="badge-face"><i data-lucide="scan-face" style="width:12px;height:12px;"></i> Face Scan</span>`;
            } else if (r.verification_method === 'Fingerprint') {
              methodBadge = `<span class="badge-fingerprint"><i data-lucide="fingerprint" style="width:12px;height:12px;"></i> Fingerprint</span>`;
            } else if (r.verification_method === 'PIN / Card') {
              methodBadge = `<span class="badge-pin">PIN / RFID</span>`;
            } else if (r.is_on_leave) {
              methodBadge = `<span style="color:var(--text-muted); font-size:11px;">Leave Reconciled</span>`;
            }

            const avatarContent = r.avatar_path 
              ? `<img src="${r.avatar_path}" alt="">` 
              : `${r.avatar_initials}`;

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
                <td>${r.time_in ? `<span style="font-weight:700; font-family:monospace; color:var(--primary);">${r.time_in}</span>` : '<span style="color:var(--text-light);">--:--:--</span>'}</td>
                <td>${r.time_out ? `<span style="font-weight:700; font-family:monospace; color:var(--primary);">${r.time_out}</span>` : '<span style="color:var(--text-light);">--:--:--</span>'}</td>
                <td>${methodBadge}</td>
                <td>${statusBadge}</td>
              </tr>
            `;
          });
          tbody.innerHTML = rowsHtml;
          if (window.lucide) lucide.createIcons();
        }
      } catch (err) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:20px; color:var(--danger);">Error loading DTR records.</td></tr>`;
      }
    }

    // Sync Biometrics
    async function syncBiometrics() {
      showToast('Connecting to ZKTeco MB460 Plus over LAN...', 'info');
      try {
        const res = await fetch('actions/biometric_sync.php?action=sync_now');
        const data = await res.json();
        showToast(data.message, 'success');
        loadDtrLogs();
        if (data.updated_count > 0) {
          setTimeout(() => window.location.reload(), 1200);
        }
      } catch (err) {
        showToast('ZKTeco device responded with network timeout.', 'error');
      }
    }

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

    if (window.lucide) lucide.createIcons();
    document.addEventListener('DOMContentLoaded', () => { if (window.lucide) lucide.createIcons(); });
  </script>
</body>
</html>
