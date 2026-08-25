<?php
// staff_dashboard.php - Dedicated Staff CPA Self-Service Portal
require_once __DIR__ . '/auth.php';
requireLogin();

$user = getCurrentUser();
$userRole = $user['role'];

// Fetch user's personal leave balances
$stmt = $pdo->prepare("SELECT * FROM leave_balances WHERE user_id = ?");
$stmt->execute([$user['id']]);
$balances = $stmt->fetch() ?: ['sil_balance' => 5.0, 'vl_balance' => 12.0, 'sl_balance' => 10.0, 'solo_parent_balance' => 7.0];

$silBalance = (float)$balances['sil_balance'];
$vlBalance = (float)$balances['vl_balance'];
$slBalance = (float)$balances['sl_balance'];
$splBalance = (float)$balances['solo_parent_balance'];

// Fetch Personal Leave Requests
$leaveReqStmt = $pdo->prepare("
    SELECT r.*, u.name as employee_name, u.department, u.title, u.avatar_initials
    FROM leave_requests r
    JOIN users u ON r.user_id = u.id
    WHERE r.user_id = ?
    ORDER BY r.created_at DESC
    LIMIT 10
");
$leaveReqStmt->execute([$user['id']]);
$leaveRequests = $leaveReqStmt->fetchAll();

// Fetch upcoming holidays
$holidaysStmt = $pdo->query("SELECT * FROM holidays ORDER BY holiday_date ASC LIMIT 6");
$holidaysList = $holidaysStmt->fetchAll();

// Fetch users grouped by department for Team Roster
$allUsersStmt = $pdo->query("
    SELECT u.*, 
    (SELECT r.leave_type_label FROM leave_requests r WHERE r.user_id = u.id AND r.status = 'Approved' AND date('now') BETWEEN r.start_date AND r.end_date LIMIT 1) as active_leave
    FROM users u
    ORDER BY u.department ASC, u.name ASC
");
$allUsers = $allUsersStmt->fetchAll();
$departments = [];
foreach ($allUsers as $u) {
    $departments[$u['department']][] = $u;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Staff Portal | JTYeo CPA Accounting Office</title>
  <link rel="stylesheet" href="style.css">
  <script src="https://unpkg.com/lucide@latest"></script>
  <!-- FullCalendar 5 CDN -->
  <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
  <style>
    #leaveCalendar { background: #fff; border-radius: var(--radius-md); padding: 16px; font-family: inherit; }
    .fc .fc-toolbar-title { font-size: 1.15rem !important; font-weight: 800 !important; color: var(--primary) !important; }
    .fc .fc-button-primary { background: var(--primary) !important; border-color: var(--primary) !important; font-size: 12px !important; font-weight: 600 !important; padding: 6px 12px !important; border-radius: var(--radius-sm) !important; }
    .fc .fc-button-primary:hover { background: var(--primary-light) !important; }
    .fc .fc-button-active { background: var(--accent) !important; border-color: var(--accent) !important; }
    .fc .fc-daygrid-day-number { font-weight: 600; color: var(--text-main); font-size: 12px; padding: 4px 6px; }
    .fc-event { border-radius: 4px !important; padding: 3px 6px !important; font-size: 11px !important; font-weight: 600 !important; cursor: pointer; box-shadow: 0 1px 3px rgba(0,0,0,0.12); }
    .calendar-legend-bar { display: flex; flex-wrap: wrap; gap: 16px; margin-top: 16px; padding: 12px 16px; background: var(--bg-subtle); border: 1px solid var(--border-color); border-radius: var(--radius-md); font-size: 12px; }
    .legend-chip { display: flex; align-items: center; gap: 6px; font-weight: 600; }
    .legend-chip .dot { width: 10px; height: 10px; border-radius: 50%; }
  </style>
</head>
<body>
  <div class="app-container">
    <!-- Sidebar Navigation -->
    <aside class="sidebar">
      <div class="brand-section">
        <div class="brand-logo">JT</div>
        <div class="brand-info">
          <h2>JTYeo CPA</h2>
          <span>Accounting Office</span>
          <div class="firm-badge">Staff CPA Portal</div>
        </div>
      </div>

      <nav class="sidebar-nav">
        <div class="nav-category">My Workspace</div>
        <a class="nav-item active" data-tab="my-portal" onclick="switchTab('my-portal')">
          <i data-lucide="layout-dashboard"></i>
          <span>My Balances & Leaves</span>
        </a>
        <a class="nav-item" data-tab="team-calendar" onclick="switchTab('team-calendar')">
          <i data-lucide="calendar-days"></i>
          <span>Team Roster & Calendar</span>
        </a>
        <div class="nav-category">DOLE Standards</div>
        <a class="nav-item" data-tab="ph-holidays" onclick="switchTab('ph-holidays')">
          <i data-lucide="scale"></i>
          <span>DOLE Rules & Holidays</span>
        </a>
      </nav>
    </aside>

    <!-- Main Content Area -->
    <main class="main-wrapper">
      <!-- Top Bar Header -->
      <header class="top-header">
        <div class="header-left">
          <div class="firm-status">
            <span class="status-dot"></span>
            <span>Current Role: <strong>Staff CPA Associate</strong></span>
          </div>
          <div class="ph-time" id="liveClock">PHT: Loading...</div>
        </div>

        <div class="header-right">
          <!-- Backend Role Switcher for Live Demo -->
          <div class="role-switcher-container">
            <span class="role-switcher-label">Switch View:</span>
            <a href="actions/switch_role.php?role=staff" class="role-btn active">Staff CPA</a>
            <a href="actions/switch_role.php?role=supervisor" class="role-btn">Senior Lead</a>
            <a href="actions/switch_role.php?role=admin" class="role-btn">Partner / HR</a>
          </div>

          <!-- User Profile & Logout -->
          <div class="user-profile">
            <div class="avatar"><?= htmlspecialchars($user['avatar_initials']) ?></div>
            <div class="profile-meta">
              <div class="user-name"><?= htmlspecialchars($user['name']) ?></div>
              <div class="user-role"><?= htmlspecialchars($user['title']) ?></div>
            </div>
            <a href="logout.php" title="Sign Out" style="margin-left: 8px; color: var(--text-muted); display:flex; align-items:center;">
              <i data-lucide="log-out" style="width: 16px; height: 16px;"></i>
            </a>
          </div>
        </div>
      </header>

      <!-- Inner Content Container -->
      <div class="content-area">
        <!-- TAB 1: MY PORTAL & BALANCES -->
        <div id="tab-my-portal" class="tab-pane">
          <div class="page-header">
            <div class="page-title">
              <h1>Welcome, <?= htmlspecialchars(explode(' ', $user['name'])[0]) ?></h1>
              <p>Staff Associate Self-Service Portal &bull; Track statutory balances and submit leave requests.</p>
            </div>
            <div class="header-actions">
              <button class="btn-primary" onclick="openModal('applyModal')">
                <i data-lucide="plus-circle"></i>
                <span>File Leave Request</span>
              </button>
            </div>
          </div>

          <!-- KPI Summary Cards -->
          <div class="kpi-grid">
            <div class="kpi-card blue">
              <div class="kpi-header">
                <span class="kpi-label">DOLE SIL Balance</span>
                <div class="kpi-icon"><i data-lucide="shield-check"></i></div>
              </div>
              <div class="kpi-value-row">
                <span class="kpi-value"><?= number_format($silBalance, 1) ?></span>
                <span class="kpi-sub">/ 5.0 Days</span>
              </div>
              <div class="kpi-footer positive">
                <i data-lucide="info" style="width:14px;height:14px;"></i>
                <span>DOLE Art. 95 Monetizable</span>
              </div>
            </div>

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
                <span>Company Benefit</span>
              </div>
            </div>

            <div class="kpi-card amber">
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
                <span>Med Cert for >2 consecutive days</span>
              </div>
            </div>

            <div class="kpi-card purple">
              <div class="kpi-header">
                <span class="kpi-label">Special Statutory Leaves</span>
                <div class="kpi-icon"><i data-lucide="sparkle"></i></div>
              </div>
              <div class="kpi-value-row">
                <span class="kpi-value">Active</span>
                <span class="kpi-sub">On-Demand</span>
              </div>
              <div class="kpi-footer neutral">
                <i data-lucide="scale" style="width:14px;height:14px;"></i>
                <span>Solo Parent, Magna Carta, VAWC</span>
              </div>
            </div>
          </div>

          <!-- Main Layout Grid -->
          <div class="dashboard-grid">
            <!-- Left Column: My Applications -->
            <div class="left-col">
              <!-- Leave Credit Details Card -->
              <div class="dashboard-card">
                <div class="card-head">
                  <h3><i data-lucide="layers" style="color:var(--accent);"></i> Statutory & Firm Leave Breakdown</h3>
                  <span class="card-action" onclick="switchTab('ph-holidays')">View DOLE Rules</span>
                </div>
                <div class="card-body">
                  <div class="credits-grid">
                    <div class="credit-box">
                      <div class="credit-title">
                        <span>Service Incentive (SIL)</span>
                        <span class="dole-tag law">DOLE Law</span>
                      </div>
                      <div class="credit-numbers">
                        <span class="credit-big"><?= number_format($silBalance, 1) ?></span>
                        <span class="credit-total">5 Total (<?= 5.0 - $silBalance ?> Used)</span>
                      </div>
                      <div class="progress-bar-bg">
                        <div class="progress-bar-fill" style="width: <?= ($silBalance / 5.0) * 100 ?>%;"></div>
                      </div>
                    </div>

                    <div class="credit-box">
                      <div class="credit-title">
                        <span>Vacation Leave</span>
                        <span class="dole-tag">Company</span>
                      </div>
                      <div class="credit-numbers">
                        <span class="credit-big"><?= number_format($vlBalance, 1) ?></span>
                        <span class="credit-total">12 Total (<?= 12.0 - $vlBalance ?> Used)</span>
                      </div>
                      <div class="progress-bar-bg">
                        <div class="progress-bar-fill success" style="width: <?= ($vlBalance / 12.0) * 100 ?>%;"></div>
                      </div>
                    </div>

                    <div class="credit-box">
                      <div class="credit-title">
                        <span>Sick Leave</span>
                        <span class="dole-tag">Company</span>
                      </div>
                      <div class="credit-numbers">
                        <span class="credit-big"><?= number_format($slBalance, 1) ?></span>
                        <span class="credit-total">10 Total (<?= 10.0 - $slBalance ?> Used)</span>
                      </div>
                      <div class="progress-bar-bg">
                        <div class="progress-bar-fill warning" style="width: <?= ($slBalance / 10.0) * 100 ?>%;"></div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Leave Requests Table Card -->
              <div class="dashboard-card">
                <div class="card-head">
                  <h3><i data-lucide="clock" style="color:var(--accent);"></i> My Submitted Leave Applications</h3>
                </div>
                <div class="table-responsive">
                  <table class="custom-table">
                    <thead>
                      <tr>
                        <th>Category</th>
                        <th>Dates & Duration</th>
                        <th>Reason / Client Coverage</th>
                        <th>Status</th>
                        <th>Signoff Details</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (empty($leaveRequests)): ?>
                        <tr>
                          <td colspan="5" style="text-align:center; padding:36px; color:var(--text-muted);">
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
                              <div style="font-size:11px; color:var(--text-muted);"><?= $req['days_count'] ?> Working Day(s)</div>
                            </td>
                            <td>
                              <div style="font-size:12.5px; max-width:260px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($req['reason']) ?>">
                                <?= htmlspecialchars($req['reason']) ?>
                              </div>
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
                              <button class="btn-icon" title="View Signoff Details" onclick="viewDetailsModal('<?= $req['ref_no'] ?>', '<?= addslashes($req['employee_name']) ?>', '<?= addslashes($req['leave_type_label']) ?>', '<?= $req['days_count'] ?>', '<?= $req['start_date'] ?>', '<?= $req['end_date'] ?>', '<?= addslashes($req['reason']) ?>', '<?= $req['status'] ?>', '<?= addslashes($req['approver_name'] ?? 'Pending') ?>', '<?= addslashes($req['rejection_reason'] ?? '') ?>')">
                                <i data-lucide="eye" style="width:14px;height:14px;"></i>
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

            <!-- Right Column: Holidays -->
            <div class="right-col">
              <div class="dashboard-card">
                <div class="card-head">
                  <h3><i data-lucide="calendar" style="color:var(--accent);"></i> Upcoming PH Holidays</h3>
                  <span class="card-action" onclick="switchTab('ph-holidays')">All Holidays</span>
                </div>
                <div class="card-body">
                  <div class="holiday-list">
                    <?php foreach ($holidaysList as $h): 
                      $d = new DateTime($h['holiday_date']);
                    ?>
                      <div class="holiday-item">
                        <div class="holiday-date">
                          <div class="holiday-month"><?= strtoupper($d->format('M')) ?></div>
                          <div class="holiday-day"><?= $d->format('d') ?></div>
                        </div>
                        <div class="holiday-info">
                          <h5><?= htmlspecialchars($h['title']) ?></h5>
                          <span><?= htmlspecialchars($h['description'] ?? 'PH Holiday') ?></span>
                        </div>
                        <span class="holiday-tag <?= strtolower($h['holiday_type']) ?>"><?= $h['holiday_type'] ?></span>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- TAB 2: TEAM CALENDAR & ROSTER -->
        <div id="tab-team-calendar" class="tab-pane" style="display:none;">
          <div class="page-header">
            <div class="page-title">
              <h1>Firm Team Roster & Interactive Leave Calendar</h1>
              <p>Check team availability to avoid engagement scheduling conflicts.</p>
            </div>
            <button class="btn-primary" onclick="openModal('applyModal')">
              <i data-lucide="calendar-plus"></i> File Leave Request
            </button>
          </div>

          <!-- Calendar Card -->
          <div class="dashboard-card" style="margin-bottom: 24px;">
            <div class="card-head">
              <h3><i data-lucide="calendar-days" style="color:var(--accent);"></i> Firm Leave & Absence Calendar</h3>
              <span class="firm-badge" style="font-size:10.5px;">Live Database Sync</span>
            </div>
            <div class="card-body">
              <div id="leaveCalendar"></div>

              <div class="calendar-legend-bar">
                <div class="legend-chip"><span class="dot" style="background:#059669;"></span> Vacation Leave (VL)</div>
                <div class="legend-chip"><span class="dot" style="background:#e11d48;"></span> Sick Leave (SL)</div>
                <div class="legend-chip"><span class="dot" style="background:#4338ca;"></span> DOLE SIL (Art. 95)</div>
                <div class="legend-chip"><span class="dot" style="background:#d97706;"></span> Solo Parent / Special</div>
                <div class="legend-chip"><span class="dot" style="background:#dc2626;"></span> 🇵🇭 Philippine Public Holiday</div>
              </div>
            </div>
          </div>

          <!-- Team Matrix -->
          <div class="dashboard-card">
            <div class="card-head">
              <h3><i data-lucide="calendar-range" style="color:var(--accent);"></i> Department Engagement Matrix</h3>
            </div>
            <div class="card-body">
              <div style="display:grid; grid-template-columns: repeat(<?= max(1, min(3, count($departments))) ?>, 1fr); gap:16px;">
                <?php foreach ($departments as $deptName => $members): ?>
                  <div style="border:1px solid var(--border-color); border-radius:var(--radius-md); padding:16px; background:var(--bg-subtle);">
                    <h4 style="color:var(--primary); margin-bottom:8px; font-size:14px;"><?= htmlspecialchars($deptName) ?></h4>
                    <ul style="font-size:12px; list-style:none; display:flex; flex-direction:column; gap:6px;">
                      <?php foreach ($members as $mem): ?>
                        <li>
                          <?php if (!empty($mem['active_leave'])): ?>
                            🌴 <strong><?= htmlspecialchars($mem['name']) ?></strong> - <span style="color:var(--accent); font-weight:600;">On <?= htmlspecialchars($mem['active_leave']) ?></span>
                          <?php else: ?>
                            ✔️ <strong><?= htmlspecialchars($mem['name']) ?></strong> - <span style="color:var(--text-muted);"><?= htmlspecialchars($mem['title']) ?> (On-Duty)</span>
                          <?php endif; ?>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>

        <!-- TAB 3: DOLE RULES & HOLIDAYS -->
        <div id="tab-ph-holidays" class="tab-pane" style="display:none;">
          <div class="page-header">
            <div class="page-title">
              <h1>Philippine Labor Standards & DOLE Entitlements</h1>
              <p>Statutory leaves compliant with the Philippine Labor Code & Special Laws.</p>
            </div>
          </div>

          <div style="display:grid; grid-template-columns: 1fr 1fr; gap:24px;">
            <div class="dashboard-card">
              <div class="card-head"><h3><i data-lucide="book-open" style="color:var(--accent);"></i> Statutory Leave Types</h3></div>
              <div class="card-body">
                <div style="display:flex; flex-direction:column; gap:14px;">
                  <div style="padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-subtle);">
                    <h4 style="font-size:13.5px; color:var(--primary); font-weight:700;">Service Incentive Leave (SIL) — 5 Days</h4>
                    <p style="font-size:12px; color:var(--text-muted); margin:4px 0;">Art. 95 Labor Code: Mandatory for employees with ≥ 1 year service. Commutable to cash at year-end.</p>
                    <span class="badge badge-sil">DOLE Statutory</span>
                  </div>
                  <div style="padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-subtle);">
                    <h4 style="font-size:13.5px; color:var(--primary); font-weight:700;">Solo Parent Leave — 7 Days (RA 8972)</h4>
                    <p style="font-size:12px; color:var(--text-muted); margin:4px 0;">Paid parental leave for solo parents with valid Solo Parent ID.</p>
                    <span class="badge badge-spl">Statutory Paid</span>
                  </div>
                  <div style="padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-subtle);">
                    <h4 style="font-size:13.5px; color:var(--primary); font-weight:700;">Magna Carta for Women — Up to 60 Days (RA 9710)</h4>
                    <p style="font-size:12px; color:var(--text-muted); margin:4px 0;">Special paid leave for gynecological surgery recovery.</p>
                    <span class="badge badge-approved">Paid Leave</span>
                  </div>
                </div>
              </div>
            </div>

            <div class="dashboard-card">
              <div class="card-head"><h3><i data-lucide="calendar" style="color:var(--accent);"></i> Philippine Official Holidays</h3></div>
              <div class="card-body">
                <div class="holiday-list">
                  <?php foreach ($holidaysList as $h): ?>
                    <div class="holiday-item">
                      <div class="holiday-info">
                        <h5><?= htmlspecialchars($h['title']) ?></h5>
                        <span><?= $h['holiday_date'] ?> &bull; <?= htmlspecialchars($h['description'] ?? 'PH Holiday') ?></span>
                      </div>
                      <span class="holiday-tag <?= strtolower($h['holiday_type']) ?>"><?= $h['holiday_type'] ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </main>
  </div>

  <!-- APPLY MODAL -->
  <div class="modal-backdrop" id="applyModal">
    <div class="modal-window">
      <div class="modal-header">
        <h3><i data-lucide="calendar-plus"></i> File Leave Application</h3>
        <button class="btn-close-modal" onclick="closeModal('applyModal')">&times;</button>
      </div>
      <form id="phpLeaveForm" onsubmit="handleBackendLeaveSubmit(event)">
        <div class="modal-body">
          <div class="form-grid">
            <div class="form-group">
              <label class="form-label">Leave Category <span class="req">*</span></label>
              <select name="leave_type" id="applyLeaveType" class="form-select" required onchange="calculateWorkingDays()">
                <option value="SIL">DOLE Service Incentive Leave (5 days/yr)</option>
                <option value="VL" selected>Vacation Leave (VL)</option>
                <option value="SL">Sick Leave (SL)</option>
                <option value="SoloParent">Solo Parent Leave (RA 8972 - 7 days)</option>
                <option value="MagnaCarta">Magna Carta of Women (RA 9710)</option>
                <option value="VAWC">VAWC Leave (RA 9262 - 10 days)</option>
                <option value="Emergency">Emergency / Bereavement Leave</option>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">Duration Mode</label>
              <select name="duration_mode" id="applyDurationMode" class="form-select" onchange="calculateWorkingDays()">
                <option value="full">Full Day (1.0 Day)</option>
                <option value="half-am">Half Day - Morning (0.5 Day)</option>
                <option value="half-pm">Half Day - Afternoon (0.5 Day)</option>
              </select>
            </div>
          </div>

          <div class="form-grid">
            <div class="form-group">
              <label class="form-label">Start Date <span class="req">*</span></label>
              <input type="date" name="start_date" id="applyStartDate" class="form-input" required onchange="calculateWorkingDays()">
            </div>

            <div class="form-group">
              <label class="form-label">End Date <span class="req">*</span></label>
              <input type="date" name="end_date" id="applyEndDate" class="form-input" required onchange="calculateWorkingDays()">
            </div>
          </div>

          <div class="calculator-preview">
            <div>
              <div class="calc-label">Total Working Days:</div>
              <div class="calc-result" id="computedDaysPreview">1.0 Working Day</div>
            </div>
            <div style="text-align:right;">
              <div class="calc-label">Available Balance:</div>
              <div class="calc-result" style="color:var(--accent);"><?= number_format($vlBalance, 1) ?> Days</div>
            </div>
          </div>

          <div class="form-group" style="margin-top:16px;">
            <label class="form-label">Reason / Client Engagement Coverage <span class="req">*</span></label>
            <textarea name="reason" id="applyReason" class="form-textarea" placeholder="Enter reason and engagement coverage details..." required></textarea>
          </div>

          <div class="form-group">
            <label class="form-label">Attach Medical Slip / Supporting File (Optional)</label>
            <input type="file" name="attachment" id="applyAttachment" class="form-input" style="padding:6px;">
            <small style="color:var(--text-muted); font-size:11px;">*Required for Sick Leave exceeding 2 consecutive working days.</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-secondary" onclick="closeModal('applyModal')">Cancel</button>
          <button type="submit" class="btn-primary" id="btnSubmitLeave">Submit Application</button>
        </div>
      </form>
    </div>
  </div>

  <div class="toast-container" id="toastContainer"></div>

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

    const today = new Date().toISOString().split('T')[0];
    document.getElementById('applyStartDate').value = today;
    document.getElementById('applyEndDate').value = today;

    function calculateWorkingDays() {
      const startStr = document.getElementById('applyStartDate').value;
      const endStr = document.getElementById('applyEndDate').value;
      const duration = document.getElementById('applyDurationMode').value;
      if (!startStr || !endStr) return;
      const start = new Date(startStr);
      const end = new Date(endStr);
      if (start > end) { document.getElementById('computedDaysPreview').innerText = 'Invalid Date Range'; return; }
      let days = 0, cur = new Date(start);
      while (cur <= end) {
        const dow = cur.getDay();
        if (dow !== 0 && dow !== 6) days++;
        cur.setDate(cur.getDate() + 1);
      }
      if (duration.startsWith('half')) days = 0.5;
      document.getElementById('computedDaysPreview').innerText = `${days} Working Day(s)`;
    }
    calculateWorkingDays();

    let calendar = null;
    function initCalendar() {
      const calendarEl = document.getElementById('leaveCalendar');
      if (!calendarEl || calendar) return;
      calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek' },
        events: 'actions/get_calendar_events.php',
        height: 'auto',
        firstDay: 1,
        eventClick: function(info) {
          const props = info.event.extendedProps;
          if (props.is_holiday) { alert(`🇵🇭 PH Public Holiday: ${info.event.title}`); return; }
          alert(`🌴 Leave Application\nStaff: ${props.employee}\nType: ${props.leave_type}\nDays: ${props.days}d\nStatus: ${props.status}`);
        }
      });
      calendar.render();
    }

    function switchTab(tabId) {
      document.querySelectorAll('.sidebar-nav .nav-item').forEach(item => {
        item.classList.remove('active');
        if (item.getAttribute('data-tab') === tabId) item.classList.add('active');
      });
      document.querySelectorAll('.tab-pane').forEach(pane => pane.style.display = 'none');
      const activePane = document.getElementById(`tab-${tabId}`);
      if (activePane) activePane.style.display = 'block';
      if (window.lucide) lucide.createIcons();
      if (tabId === 'team-calendar') {
        if (!calendar) setTimeout(initCalendar, 50);
        else setTimeout(() => { calendar.updateSize(); calendar.refetchEvents(); }, 50);
      }
    }

    function openModal(id) { document.getElementById(id).classList.add('show'); }
    function closeModal(id) { document.getElementById(id).classList.remove('show'); }

    function showToast(msg, type = 'info') {
      const container = document.getElementById('toastContainer');
      const toast = document.createElement('div');
      toast.className = `toast ${type}`;
      toast.innerHTML = `<div style="font-size:13px; font-weight:600;">${msg}</div>`;
      container.appendChild(toast);
      setTimeout(() => { toast.style.opacity = '0'; toast.style.transform = 'translateX(100%)'; setTimeout(() => toast.remove(), 300); }, 3500);
    }

    async function handleBackendLeaveSubmit(e) {
      e.preventDefault();
      const form = document.getElementById('phpLeaveForm');
      const formData = new FormData(form);
      const btn = document.getElementById('btnSubmitLeave');
      btn.disabled = true;
      btn.innerText = 'Saving...';
      try {
        const res = await fetch('actions/apply_leave.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
          showToast(data.message, 'success');
          closeModal('applyModal');
          setTimeout(() => window.location.reload(), 800);
        } else {
          showToast(data.message || 'Error submitting leave', 'error');
          btn.disabled = false;
          btn.innerText = 'Submit Application';
        }
      } catch (err) {
        showToast('Network error while saving leave.', 'error');
        btn.disabled = false;
        btn.innerText = 'Submit Application';
      }
    }

    function viewDetailsModal(ref, emp, type, days, start, end, reason, status, approver, rejectReason) {
      let msg = `Leave Reference: ${ref}\nStaff: ${emp}\nType: ${type} (${days} days)\nDates: ${start} to ${end}\nReason: ${reason}\nStatus: ${status}\nSignoff: ${approver}`;
      if (rejectReason) msg += `\nDecision Note: ${rejectReason}`;
      alert(msg);
    }

    if (window.lucide) lucide.createIcons();
  </script>
</body>
</html>
