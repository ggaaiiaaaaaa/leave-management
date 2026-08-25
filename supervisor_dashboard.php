<?php
// supervisor_dashboard.php - Dedicated Lead Reviewer & Engagement Oversight Portal
require_once __DIR__ . '/auth.php';
requireLogin();

if (!hasRole(['supervisor', 'admin'])) {
    header('Location: staff_dashboard.php');
    exit;
}

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

// Fetch Pending Approvals
$approvalsStmt = $pdo->query("
    SELECT r.*, u.name as employee_name, u.department, u.title, u.avatar_initials
    FROM leave_requests r
    JOIN users u ON r.user_id = u.id
    WHERE r.status = 'Pending'
    ORDER BY r.created_at ASC
");
$pendingApprovals = $approvalsStmt->fetchAll();
$pendingCount = count($pendingApprovals);

// Fetch Team & Personal Leave Requests
$leaveReqStmt = $pdo->query("
    SELECT r.*, u.name as employee_name, u.department, u.title, u.avatar_initials
    FROM leave_requests r
    JOIN users u ON r.user_id = u.id
    ORDER BY r.created_at DESC
    LIMIT 15
");
$leaveRequests = $leaveReqStmt->fetchAll();

// Fetch upcoming holidays
$holidaysStmt = $pdo->query("SELECT * FROM holidays ORDER BY holiday_date ASC LIMIT 6");
$holidaysList = $holidaysStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Supervisor Portal | JTYeo CPA Accounting Office</title>
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
          <div class="firm-badge">Lead Reviewer</div>
        </div>
      </div>

      <nav class="sidebar-nav">
        <div class="nav-category">Lead Reviewer Portal</div>
        <a class="nav-item active" data-tab="approvals" onclick="switchTab('approvals')">
          <i data-lucide="check-circle-2"></i>
          <span>Review Approvals</span>
          <?php if ($pendingCount > 0): ?>
            <span class="nav-badge" id="pendingApprovalsBadge"><?= $pendingCount ?></span>
          <?php endif; ?>
        </a>
        <a class="nav-item" data-tab="my-portal" onclick="switchTab('my-portal')">
          <i data-lucide="layout-dashboard"></i>
          <span>Team Oversight & Balances</span>
        </a>
        <a class="nav-item" data-tab="team-calendar" onclick="switchTab('team-calendar')">
          <i data-lucide="calendar-days"></i>
          <span>Team Schedule & Calendar</span>
        </a>
        <div class="nav-category">Compliance</div>
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
            <span>Current Role: <strong>Senior Audit Lead / Reviewer</strong></span>
          </div>
          <div class="ph-time" id="liveClock">PHT: Loading...</div>
        </div>

        <div class="header-right">
          <div class="role-switcher-container">
            <span class="role-switcher-label">Switch View:</span>
            <a href="actions/switch_role.php?role=staff" class="role-btn">Staff CPA</a>
            <a href="actions/switch_role.php?role=supervisor" class="role-btn active">Senior Lead</a>
            <a href="actions/switch_role.php?role=admin" class="role-btn">Partner / HR</a>
          </div>

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

      <!-- Content Area -->
      <div class="content-area">
        <!-- TAB 1: REVIEW APPROVALS (DEFAULT FOR SUPERVISOR) -->
        <div id="tab-approvals" class="tab-pane">
          <div class="page-header">
            <div class="page-title">
              <h1>Leave Approval Management</h1>
              <p>Review team leave applications, check engagement overlaps, and provide signoff feedback.</p>
            </div>
            <button class="btn-primary" onclick="openModal('applyModal')">
              <i data-lucide="plus-circle"></i> File My Own Leave
            </button>
          </div>

          <!-- Pending Approvals Queue -->
          <div class="dashboard-card" style="margin-bottom:24px;">
            <div class="card-head">
              <h3><i data-lucide="inbox" style="color:var(--accent);"></i> Pending Approvals Queue</h3>
              <span class="badge badge-pending"><?= $pendingCount ?> Pending Requests</span>
            </div>
            <div class="table-responsive">
              <table class="custom-table">
                <thead>
                  <tr>
                    <th>Staff Name & Department</th>
                    <th>Leave Category</th>
                    <th>Dates Requested</th>
                    <th>Working Days</th>
                    <th>Reason / Client Coverage</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($pendingApprovals)): ?>
                    <tr><td colspan="6" style="text-align:center; padding:32px; color:var(--text-muted);">No pending approvals in queue. All applications have been reviewed.</td></tr>
                  <?php else: ?>
                    <?php foreach ($pendingApprovals as $p): ?>
                      <tr>
                        <td>
                          <div style="font-weight:700; color:var(--primary);"><?= htmlspecialchars($p['employee_name']) ?></div>
                          <div style="font-size:11px; color:var(--text-muted);"><?= htmlspecialchars($p['department']) ?></div>
                        </td>
                        <td><span class="badge badge-vl"><?= htmlspecialchars($p['leave_type_label']) ?></span></td>
                        <td><?= $p['start_date'] ?> to <?= $p['end_date'] ?></td>
                        <td><strong><?= $p['days_count'] ?> Day(s)</strong></td>
                        <td style="font-size:12.5px;"><?= htmlspecialchars($p['reason']) ?></td>
                        <td>
                          <div style="display:flex; gap:8px;">
                            <button class="btn-primary" style="padding:6px 12px; font-size:12px;" onclick="openDecisionModal('<?= $p['ref_no'] ?>', '<?= addslashes($p['employee_name']) ?>', '<?= addslashes($p['leave_type_label']) ?>', '<?= $p['days_count'] ?>', 'Approved')">
                              <i data-lucide="check" style="width:13px;height:13px;"></i> Approve
                            </button>
                            <button class="btn-secondary" style="padding:6px 12px; font-size:12px; color:var(--danger);" onclick="openDecisionModal('<?= $p['ref_no'] ?>', '<?= addslashes($p['employee_name']) ?>', '<?= addslashes($p['leave_type_label']) ?>', '<?= $p['days_count'] ?>', 'Rejected')">
                              <i data-lucide="x" style="width:13px;height:13px;"></i> Reject
                            </button>
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

        <!-- TAB 2: TEAM OVERSIGHT & MY BALANCES -->
        <div id="tab-my-portal" class="tab-pane" style="display:none;">
          <div class="page-header">
            <div class="page-title">
              <h1>Engagement Team Oversight & Balances</h1>
              <p>Monitor team availability, view firm leave applications, and track your personal credits.</p>
            </div>
            <button class="btn-primary" onclick="openModal('applyModal')">
              <i data-lucide="plus-circle"></i> File My Own Leave
            </button>
          </div>

          <!-- KPI Cards -->
          <div class="kpi-grid">
            <div class="kpi-card amber">
              <div class="kpi-header"><span class="kpi-label">Pending Team Approvals</span><div class="kpi-icon"><i data-lucide="inbox"></i></div></div>
              <div class="kpi-value-row"><span class="kpi-value"><?= $pendingCount ?></span><span class="kpi-sub">Requests</span></div>
              <div class="kpi-footer <?= $pendingCount > 0 ? 'warning' : 'positive' ?>">
                <i data-lucide="<?= $pendingCount > 0 ? 'alert-circle' : 'check' ?>" style="width:14px;height:14px;"></i>
                <span><?= $pendingCount > 0 ? 'Requires Review' : 'Queue Empty' ?></span>
              </div>
            </div>

            <div class="kpi-card blue">
              <div class="kpi-header"><span class="kpi-label">My DOLE SIL Balance</span><div class="kpi-icon"><i data-lucide="shield-check"></i></div></div>
              <div class="kpi-value-row"><span class="kpi-value"><?= number_format($silBalance, 1) ?></span><span class="kpi-sub">/ 5.0 Days</span></div>
              <div class="kpi-footer positive"><i data-lucide="info" style="width:14px;height:14px;"></i><span>Art. 95 Monetizable</span></div>
            </div>

            <div class="kpi-card green">
              <div class="kpi-header"><span class="kpi-label">My Vacation Leave (VL)</span><div class="kpi-icon"><i data-lucide="palmtree"></i></div></div>
              <div class="kpi-value-row"><span class="kpi-value"><?= number_format($vlBalance, 1) ?></span><span class="kpi-sub">/ 12.0 Days</span></div>
              <div class="kpi-footer positive"><i data-lucide="check" style="width:14px;height:14px;"></i><span>Company Benefit</span></div>
            </div>

            <div class="kpi-card purple">
              <div class="kpi-header"><span class="kpi-label">My Sick Leave (SL)</span><div class="kpi-icon"><i data-lucide="heart-pulse"></i></div></div>
              <div class="kpi-value-row"><span class="kpi-value"><?= number_format($slBalance, 1) ?></span><span class="kpi-sub">/ 10.0 Days</span></div>
              <div class="kpi-footer neutral"><i data-lucide="file-text" style="width:14px;height:14px;"></i><span>Medical Policy</span></div>
            </div>
          </div>

          <!-- Leave Applications History -->
          <div class="dashboard-card">
            <div class="card-head">
              <h3><i data-lucide="users" style="color:var(--accent);"></i> Team & Personal Leave Applications</h3>
            </div>
            <div class="table-responsive">
              <table class="custom-table">
                <thead>
                  <tr>
                    <th>Employee</th>
                    <th>Category</th>
                    <th>Dates / Duration</th>
                    <th>Reason / Engagement</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($leaveRequests)): ?>
                    <tr><td colspan="6" style="text-align:center; padding:32px; color:var(--text-muted);">No leave applications recorded yet.</td></tr>
                  <?php else: ?>
                    <?php foreach ($leaveRequests as $req): ?>
                      <tr>
                        <td>
                          <div style="font-weight:700; color:var(--primary);"><?= htmlspecialchars($req['employee_name']) ?></div>
                          <div style="font-size:11px; color:var(--text-muted);"><?= htmlspecialchars($req['department']) ?></div>
                        </td>
                        <td><span class="badge badge-vl"><?= htmlspecialchars($req['leave_type_label']) ?></span></td>
                        <td>
                          <div style="font-weight:600;"><?= $req['start_date'] ?> <?= $req['start_date'] !== $req['end_date'] ? 'to ' . $req['end_date'] : '' ?></div>
                          <div style="font-size:11px; color:var(--text-muted);"><?= $req['days_count'] ?> Working Day(s)</div>
                        </td>
                        <td>
                          <div style="font-size:12.5px; max-width:240px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($req['reason']) ?>"><?= htmlspecialchars($req['reason']) ?></div>
                          <div style="font-size:10.5px; color:var(--text-light);">Ref: <?= $req['ref_no'] ?></div>
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
                          <?php if ($req['status'] === 'Pending'): ?>
                            <button class="btn-icon approve" title="Approve with note" onclick="openDecisionModal('<?= $req['ref_no'] ?>', '<?= addslashes($req['employee_name']) ?>', '<?= addslashes($req['leave_type_label']) ?>', '<?= $req['days_count'] ?>', 'Approved')">
                              <i data-lucide="check" style="width:14px;height:14px;"></i>
                            </button>
                            <button class="btn-icon reject" title="Reject with reason" onclick="openDecisionModal('<?= $req['ref_no'] ?>', '<?= addslashes($req['employee_name']) ?>', '<?= addslashes($req['leave_type_label']) ?>', '<?= $req['days_count'] ?>', 'Rejected')">
                              <i data-lucide="x" style="width:14px;height:14px;"></i>
                            </button>
                          <?php else: ?>
                            <button class="btn-icon" title="View Details" onclick="viewDetailsModal('<?= $req['ref_no'] ?>', '<?= addslashes($req['employee_name']) ?>', '<?= addslashes($req['leave_type_label']) ?>', '<?= $req['days_count'] ?>', '<?= $req['start_date'] ?>', '<?= $req['end_date'] ?>', '<?= addslashes($req['reason']) ?>', '<?= $req['status'] ?>', '<?= addslashes($req['approver_name'] ?? 'Pending') ?>', '<?= addslashes($req['rejection_reason'] ?? '') ?>')">
                              <i data-lucide="eye" style="width:14px;height:14px;"></i>
                            </button>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- TAB 3: TEAM CALENDAR & SCHEDULE -->
        <div id="tab-team-calendar" class="tab-pane" style="display:none;">
          <div class="page-header">
            <div class="page-title">
              <h1>Firm Team Roster & Interactive Leave Calendar</h1>
              <p>Visual monthly schedule of staff leaves, departmental engagement coverage, and DOLE holidays.</p>
            </div>
            <button class="btn-primary" onclick="openModal('applyModal')">
              <i data-lucide="calendar-plus"></i> File Leave Request
            </button>
          </div>

          <div class="dashboard-card" style="margin-bottom:24px;">
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
                <div class="legend-chip"><span class="dot" style="background:#f59e0b;"></span> Pending Approval</div>
                <div class="legend-chip"><span class="dot" style="background:#dc2626;"></span> 🇵🇭 Philippine Public Holiday</div>
              </div>
            </div>
          </div>
        </div>

        <!-- TAB 4: DOLE RULES -->
        <div id="tab-ph-holidays" class="tab-pane" style="display:none;">
          <div class="page-header">
            <div class="page-title">
              <h1>Philippine Labor Standards & DOLE Entitlements</h1>
              <p>Statutory leaves compliant with the Philippine Labor Code & Special Laws.</p>
            </div>
          </div>

          <div class="dashboard-card">
            <div class="card-head"><h3><i data-lucide="book-open" style="color:var(--accent);"></i> Statutory Leave Entitlements</h3></div>
            <div class="card-body">
              <div style="padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-subtle); margin-bottom:12px;">
                <h4 style="font-size:13.5px; color:var(--primary); font-weight:700;">Service Incentive Leave (SIL) — 5 Days</h4>
                <p style="font-size:12px; color:var(--text-muted);">Art. 95 Labor Code: Mandatory for employees with ≥ 1 year service. Commutable to cash at year-end.</p>
              </div>
            </div>
          </div>
        </div>

      </div>
    </main>
  </div>

  <!-- DECISION MODAL -->
  <div class="modal-backdrop" id="decisionModal">
    <div class="modal-window">
      <div class="modal-header">
        <h3><i data-lucide="check-square"></i> Review Leave Application</h3>
        <button class="btn-close-modal" onclick="closeModal('decisionModal')">&times;</button>
      </div>
      <div class="modal-body">
        <div style="background:var(--bg-subtle); border:1px solid var(--border-color); border-radius:var(--radius-md); padding:14px; margin-bottom:16px;">
          <div style="font-size:11px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Application Reference</div>
          <div style="font-size:15px; font-weight:800; color:var(--primary);" id="decModalRef">LR-2026-XXX</div>
          <div style="font-size:13px; margin-top:4px;"><strong>Staff:</strong> <span id="decModalStaff"></span></div>
          <div style="font-size:13px;"><strong>Category:</strong> <span id="decModalType"></span> (<span id="decModalDays"></span> working day/s)</div>
        </div>

        <div class="form-group">
          <label class="form-label">Reviewer Feedback / Note (Optional for Approval, Required for Rejection):</label>
          <textarea id="decModalNote" class="form-textarea" placeholder="e.g., Approved with audit coverage assigned to Carlos. Or: Please reschedule after April 15."></textarea>
        </div>
      </div>
      <div class="modal-footer" style="justify-content:space-between;">
        <button type="button" class="btn-secondary" onclick="closeModal('decisionModal')">Cancel</button>
        <div style="display:flex; gap:10px;">
          <button type="button" class="btn-secondary" style="color:var(--danger); border-color:var(--danger);" onclick="executeDecisionWithNote('Rejected')">
            <i data-lucide="x" style="width:14px;height:14px;"></i> Reject
          </button>
          <button type="button" class="btn-primary" onclick="executeDecisionWithNote('Approved')">
            <i data-lucide="check" style="width:14px;height:14px;"></i> Approve
          </button>
        </div>
      </div>
    </div>
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
              <select name="leave_type" id="applyLeaveType" class="form-select" required>
                <option value="SIL">DOLE Service Incentive Leave (5 days/yr)</option>
                <option value="VL" selected>Vacation Leave (VL)</option>
                <option value="SL">Sick Leave (SL)</option>
                <option value="SoloParent">Solo Parent Leave (RA 8972 - 7 days)</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Duration Mode</label>
              <select name="duration_mode" id="applyDurationMode" class="form-select">
                <option value="full">Full Day (1.0 Day)</option>
                <option value="half-am">Half Day - Morning (0.5 Day)</option>
                <option value="half-pm">Half Day - Afternoon (0.5 Day)</option>
              </select>
            </div>
          </div>
          <div class="form-grid">
            <div class="form-group">
              <label class="form-label">Start Date <span class="req">*</span></label>
              <input type="date" name="start_date" id="applyStartDate" class="form-input" required>
            </div>
            <div class="form-group">
              <label class="form-label">End Date <span class="req">*</span></label>
              <input type="date" name="end_date" id="applyEndDate" class="form-input" required>
            </div>
          </div>
          <div class="form-group" style="margin-top:14px;">
            <label class="form-label">Reason / Client Coverage <span class="req">*</span></label>
            <textarea name="reason" id="applyReason" class="form-textarea" placeholder="Enter reason..." required></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-secondary" onclick="closeModal('applyModal')">Cancel</button>
          <button type="submit" class="btn-primary">Submit Application</button>
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
          if (props.is_holiday) { alert(`🇵🇭 PH Holiday: ${info.event.title}`); return; }
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

    let currentDecRef = '';
    function openDecisionModal(refNo, empName, leaveType, days, preferredDecision) {
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
          showToast(data.message || 'Error recording decision.', 'error');
        }
      } catch (err) {
        showToast('Network error while recording decision.', 'error');
      }
    }

    async function handleBackendLeaveSubmit(e) {
      e.preventDefault();
      const form = document.getElementById('phpLeaveForm');
      const formData = new FormData(form);
      try {
        const res = await fetch('actions/apply_leave.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
          showToast(data.message, 'success');
          closeModal('applyModal');
          setTimeout(() => window.location.reload(), 800);
        } else {
          showToast(data.message || 'Error submitting leave', 'error');
        }
      } catch (err) {
        showToast('Network error while saving leave.', 'error');
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
