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

// Fetch all Philippine official holidays for full annual DOLE view
$allHolidaysStmt = $pdo->query("SELECT * FROM holidays ORDER BY holiday_date ASC");
$allHolidays = $allHolidaysStmt->fetchAll();

// Fetch upcoming holidays for dashboard preview
$holidaysStmt = $pdo->query("SELECT * FROM holidays WHERE holiday_date >= date('now') ORDER BY holiday_date ASC LIMIT 6");
$holidaysList = $holidaysStmt->fetchAll();
if (empty($holidaysList)) {
    $holidaysList = array_slice($allHolidays, 0, 6);
}
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
        <div class="nav-category">Management</div>
        <a class="nav-item active" data-tab="approvals" onclick="switchTab('approvals')">
          <i data-lucide="inbox"></i>
          <span>Approvals Queue</span>
          <?php if ($pendingCount > 0): ?>
            <span class="nav-badge"><?= $pendingCount ?></span>
          <?php endif; ?>
        </a>
        <a class="nav-item" data-tab="my-portal" onclick="switchTab('my-portal')">
          <i data-lucide="layout-dashboard"></i>
          <span>My Balances & History</span>
        </a>
        <a class="nav-item" data-tab="team-calendar" onclick="switchTab('team-calendar')">
          <i data-lucide="calendar-days"></i>
          <span>Team Schedule & Calendar</span>
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
          <!-- Backend Role Switcher for Live Demo -->
          <div class="role-switcher-container">
            <span class="role-switcher-label">Switch View:</span>
            <a href="actions/switch_role.php?role=staff" class="role-btn">Staff CPA</a>
            <a href="actions/switch_role.php?role=supervisor" class="role-btn active">Senior Lead</a>
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
        <!-- TAB 1: APPROVALS QUEUE (SUPERVISOR FOCUS) -->
        <div id="tab-approvals" class="tab-pane">
          <div class="page-header">
            <div class="page-title">
              <h1>Engagement Leave Approvals Queue</h1>
              <p>Review and decide team leave applications to maintain audit and tax engagement coverage.</p>
            </div>
          </div>

          <!-- Pending Requests Table Card -->
          <div class="dashboard-card" style="margin-bottom: 24px;">
            <div class="card-head">
              <h3><i data-lucide="inbox" style="color:var(--accent);"></i> Pending Review Queue (<?= count($pendingApprovals) ?>)</h3>
              <span class="firm-badge">Action Required</span>
            </div>
            <div class="table-responsive">
              <table class="custom-table">
                <thead>
                  <tr>
                    <th>Application Ref</th>
                    <th>Staff Member</th>
                    <th>Leave Category</th>
                    <th>Requested Dates</th>
                    <th>Duration</th>
                    <th>Reason / Engagement Coverage</th>
                    <th>Decision Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($pendingApprovals)): ?>
                    <tr>
                      <td colspan="7" style="text-align:center; padding:36px; color:var(--text-muted);">
                        <i data-lucide="check-circle" style="width:32px;height:32px;margin:0 auto 8px;display:block;color:var(--success);"></i>
                        <strong>All caught up!</strong> No pending leave applications requiring your review.
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($pendingApprovals as $app): ?>
                      <tr>
                        <td>
                          <span style="font-weight:700; color:var(--primary); font-family:monospace;"><?= $app['ref_no'] ?></span>
                          <div style="font-size:11px; color:var(--text-light);"><?= date('M d, Y', strtotime($app['created_at'])) ?></div>
                          <?php if (!empty($app['attachment_path'])): ?>
                            <div style="margin-top:4px;">
                              <span class="doc-chip" onclick="openDocPreview('<?= htmlspecialchars($app['attachment_path']) ?>', '<?= addslashes($app['employee_name']) ?>', '<?= addslashes($app['leave_type_label']) ?>')">
                                <i data-lucide="paperclip" style="width:11px;height:11px;"></i> Doc Attached
                              </span>
                            </div>
                          <?php endif; ?>
                        </td>
                        <td>
                          <div style="font-weight:600;"><?= htmlspecialchars($app['employee_name']) ?></div>
                          <div style="font-size:11px; color:var(--text-muted);"><?= htmlspecialchars($app['department']) ?> &bull; <?= htmlspecialchars($app['title'] ?? 'Staff') ?></div>
                        </td>
                        <td>
                          <span class="badge badge-vl"><?= htmlspecialchars($app['leave_type_label']) ?></span>
                        </td>
                        <td>
                          <div style="font-weight:600;"><?= $app['start_date'] ?> <?= $app['start_date'] !== $app['end_date'] ? 'to ' . $app['end_date'] : '' ?></div>
                        </td>
                        <td>
                          <span style="font-weight:700; color:var(--accent);"><?= $app['days_count'] ?> Working Day(s)</span>
                          <div style="font-size:10.5px; color:var(--text-muted);"><?= ucfirst($app['duration_mode']) ?> Mode</div>
                        </td>
                        <td>
                          <div style="font-size:12.5px; max-width:240px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($app['reason']) ?>">
                            <?= htmlspecialchars($app['reason']) ?>
                          </div>
                        </td>
                        <td>
                          <button class="btn-primary" style="padding:6px 12px; font-size:12px;" onclick="openDecisionModal('<?= $app['ref_no'] ?>', '<?= addslashes($app['employee_name']) ?>', '<?= addslashes($app['leave_type_label']) ?>', '<?= $app['days_count'] ?>', '<?= addslashes($app['department']) ?>', '<?= addslashes($app['attachment_path'] ?? '') ?>')">
                            <i data-lucide="check-square" style="width:13px;height:13px;"></i>
                            <span>Review & Decide</span>
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

        <!-- TAB 2: MY PORTAL & BALANCES (LEAD'S PERSONAL LEAVE) -->
        <div id="tab-my-portal" class="tab-pane" style="display:none;">
          <div class="page-header">
            <div class="page-title">
              <h1>Welcome, <?= htmlspecialchars(explode(' ', $user['name'])[0]) ?></h1>
              <p>Personal Leave Portal &bull; Track your personal allowances and submit leave applications.</p>
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
            <div class="kpi-card amber">
              <div class="kpi-header"><span class="kpi-label">Pending Reviews</span><div class="kpi-icon"><i data-lucide="clock"></i></div></div>
              <div class="kpi-value-row"><span class="kpi-value"><?= $pendingCount ?></span><span class="kpi-sub">Requests</span></div>
              <div class="kpi-footer neutral">
                <i data-lucide="info" style="width:14px;height:14px;"></i>
                <span><?= $pendingCount > 0 ? 'Requires Review' : 'Queue Empty' ?></span>
              </div>
            </div>

            <div class="kpi-card blue">
              <div class="kpi-header"><span class="kpi-label">Service Incentive (SIL)</span><div class="kpi-icon"><i data-lucide="shield-check"></i></div></div>
              <div class="kpi-value-row"><span class="kpi-value"><?= number_format($silBalance, 1) ?></span><span class="kpi-sub">/ 5.0 Days</span></div>
              <div class="kpi-footer positive"><i data-lucide="info" style="width:14px;height:14px;"></i><span>Year-End Cash Convertible</span></div>
            </div>

            <div class="kpi-card green">
              <div class="kpi-header"><span class="kpi-label">Vacation Leave (VL)</span><div class="kpi-icon"><i data-lucide="palmtree"></i></div></div>
              <div class="kpi-value-row"><span class="kpi-value"><?= number_format($vlBalance, 1) ?></span><span class="kpi-sub">/ 12.0 Days</span></div>
              <div class="kpi-footer positive"><i data-lucide="check" style="width:14px;height:14px;"></i><span>Company Benefit</span></div>
            </div>

            <div class="kpi-card purple">
              <div class="kpi-header"><span class="kpi-label">Sick Leave (SL)</span><div class="kpi-icon"><i data-lucide="heart-pulse"></i></div></div>
              <div class="kpi-value-row"><span class="kpi-value"><?= number_format($slBalance, 1) ?></span><span class="kpi-sub">/ 10.0 Days</span></div>
              <div class="kpi-footer neutral"><i data-lucide="file-text" style="width:14px;height:14px;"></i><span>Medical Policy</span></div>
            </div>
          </div>

          <!-- Leave History Table Card -->
          <div class="dashboard-card">
            <div class="card-head">
              <h3><i data-lucide="clock" style="color:var(--accent);"></i> My Submitted Leave History</h3>
            </div>
            <div class="table-responsive">
              <table class="custom-table">
                <thead>
                  <tr>
                    <th>Category</th>
                    <th>Dates & Duration</th>
                    <th>Reason / Engagement Coverage</th>
                    <th>Status</th>
                    <th>Signoff Details</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($myLeaveRequests)): ?>
                    <tr>
                      <td colspan="5" style="text-align:center; padding:32px; color:var(--text-muted);">
                        No personal leave applications filed yet. Click <strong>"File Leave Request"</strong> to submit.
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($myLeaveRequests as $req): ?>
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
                          <div style="font-size:12.5px; max-width:260px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                            <?= htmlspecialchars($req['reason']) ?>
                          </div>
                        </td>
                        <td>
                          <?php
                            $statusBadge = 'badge-pending';
                            if ($req['status'] === 'Approved') $statusBadge = 'badge-approved';
                            if ($req['status'] === 'Rejected') $statusBadge = 'badge-rejected';
                            if (str_contains($req['status'], 'Endorsed')) $statusBadge = 'badge-spl';
                          ?>
                          <span class="badge <?= $statusBadge ?>"><?= $req['status'] ?></span>
                        </td>
                        <td>
                          <button class="btn-icon" title="View Signoff Details" onclick="viewDetailsModal('<?= $req['ref_no'] ?>', '<?= addslashes($req['employee_name']) ?>', '<?= addslashes($req['leave_type_label']) ?>', '<?= $req['days_count'] ?>', '<?= $req['start_date'] ?>', '<?= $req['end_date'] ?>', '<?= addslashes($req['reason']) ?>', '<?= $req['status'] ?>', '<?= addslashes($req['approver_name'] ?? 'Pending') ?>', '<?= addslashes($req['rejection_reason'] ?? '') ?>', '<?= addslashes($req['attachment_path'] ?? '') ?>')">
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

        <!-- TAB 3: TEAM CALENDAR & SCHEDULE -->
        <div id="tab-team-calendar" class="tab-pane" style="display:none;">
          <div class="page-header">
            <div class="page-title">
              <h1>Firm Team Roster & Interactive Leave Calendar</h1>
              <p>Visual monthly schedule of staff leaves, departmental engagement coverage, and official holidays.</p>
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
                <div class="legend-chip"><span class="dot" style="background:#4338ca;"></span> Service Incentive (SIL)</div>
                <div class="legend-chip"><span class="dot" style="background:#d97706;"></span> Solo Parent / Special</div>
                <div class="legend-chip"><span class="dot" style="background:#f59e0b;"></span> Pending Approval</div>
                <div class="legend-chip"><span class="dot" style="background:#dc2626;"></span> 🇵🇭 Philippine Public Holiday</div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </main>
  </div>

  <!-- DECISION MODAL (WITH ENGAGEMENT CONFLICT DETECTOR) -->
  <div class="modal-backdrop" id="decisionModal">
    <div class="modal-window">
      <div class="modal-header">
        <h3><i data-lucide="check-square"></i> Review Leave Application</h3>
        <button class="btn-close-modal" onclick="closeModal('decisionModal')">&times;</button>
      </div>
      <div class="modal-body">
        <div style="background:var(--bg-subtle); border:1px solid var(--border-color); border-radius:var(--radius-md); padding:14px; margin-bottom:14px;">
          <div style="display:flex; justify-content:space-between;">
            <div>
              <div style="font-size:11px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Application Reference</div>
              <div style="font-size:15px; font-weight:800; color:var(--primary); font-family:monospace;" id="decModalRef">LR-2026-XXX</div>
            </div>
            <div id="decModalDocBadge"></div>
          </div>
          <div style="font-size:13px; margin-top:8px;"><strong>Staff:</strong> <span id="decModalStaff"></span></div>
          <div style="font-size:13px;"><strong>Category:</strong> <span id="decModalType"></span> (<span id="decModalDays"></span> working day/s)</div>
        </div>

        <!-- ₱65k Practice Feature: Engagement Overlap & Coverage Checker -->
        <div class="overlap-banner safe" id="decModalOverlap">
          <i data-lucide="shield-check"></i>
          <div><strong>Engagement Coverage Confirmed:</strong> No other members in <span id="decModalDept">Practice Team</span> have overlapping leaves.</div>
        </div>

        <div class="form-group">
          <label class="form-label">Reviewer Endorsement / Signoff Note (Optional):</label>
          <textarea id="decModalNote" class="form-textarea" placeholder="e.g., Endorsed with engagement coverage confirmed by audit team."></textarea>
        </div>
      </div>
      <div class="modal-footer" style="justify-content:space-between;">
        <button type="button" class="btn-secondary" onclick="closeModal('decisionModal')">Cancel</button>
        <div style="display:flex; gap:10px;">
          <button type="button" class="btn-secondary" style="color:var(--danger); border-color:var(--danger);" onclick="executeDecisionWithNote('Rejected')">
            <i data-lucide="x" style="width:14px;height:14px;"></i> Reject
          </button>
          <button type="button" class="btn-secondary" style="color:var(--accent); border-color:var(--accent);" onclick="executeDecisionWithNote('Endorsed by Lead')">
            <i data-lucide="check" style="width:14px;height:14px;"></i> Endorse to Partner
          </button>
          <button type="button" class="btn-primary" onclick="executeDecisionWithNote('Approved')">
            <i data-lucide="check-check" style="width:14px;height:14px;"></i> Direct Approve
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
          <!-- Peak Season Warning Banner -->
          <div id="taxSeasonNotice" class="tax-season-banner" style="display:none;"></div>

          <div class="form-grid">
            <div class="form-group">
              <label class="form-label">Leave Category <span class="req">*</span></label>
              <select name="leave_type" id="applyLeaveType" class="form-select" required onchange="calculateWorkingDays()">
                <option value="SIL">Service Incentive Leave (SIL - 5 Days)</option>
                <option value="VL" selected>Vacation Leave (VL)</option>
                <option value="SL">Sick Leave (SL)</option>
                <option value="Bereavement">Bereavement Leave (Immediate Family Loss - 3-5d)</option>
                <option value="Emergency">Emergency / Calamity Leave</option>
                <option value="Study">CPA Board Exam / CPD Study Leave</option>
                <option value="SoloParent">Solo Parent Leave (RA 8972 / RA 11861 - 7 days)</option>
                <option value="Paternity">Paternity Leave (RA 8187 - 7 days)</option>
                <option value="Maternity">Maternity Leave (RA 11210 - 105 days)</option>
                <option value="MagnaCarta">Magna Carta of Women (RA 9710)</option>
                <option value="VAWC">VAWC Leave (RA 9262 - 10 days)</option>
                <option value="Unpaid">Leave Without Pay (LWOP / Unpaid)</option>
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
              <div class="calc-result" id="availableBalancePreview" style="color:var(--accent);"><?= number_format($vlBalance, 1) ?> Days</div>
            </div>
          </div>

          <div class="form-group" style="margin-top:14px;">
            <label class="form-label">Reason / Client Coverage <span class="req">*</span></label>
            <textarea name="reason" id="applyReason" class="form-textarea" placeholder="Enter reason and engagement coverage details..." required></textarea>
          </div>

          <div class="form-group">
            <label class="form-label">Supporting Document / Medical Slip (Optional)</label>
            <input type="file" name="attachment" id="applyAttachment" class="form-input" style="padding:6px;">
            <small style="color:var(--text-muted); font-size:11px;">*Upload doctor slip for SL > 1 day or proof of CPD seminar / bereavement.</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-secondary" onclick="closeModal('applyModal')">Cancel</button>
          <button type="submit" class="btn-primary" id="btnSubmitLeave">Submit Application</button>
        </div>
      </form>
    </div>
  </div>

  <!-- DETAILS & DUAL-STAGE MODAL -->
  <div class="modal-backdrop" id="detailsModal">
    <div class="modal-window">
      <div class="modal-header">
        <h3><i data-lucide="file-check"></i> Leave Application Details</h3>
        <button class="btn-close-modal" onclick="closeModal('detailsModal')">&times;</button>
      </div>
      <div class="modal-body">
        <div style="background:var(--bg-subtle); border:1px solid var(--border-color); border-radius:var(--radius-md); padding:16px; margin-bottom:18px;">
          <div style="display:flex; justify-content:space-between; align-items:center;">
            <div>
              <div style="font-size:11px; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Reference Number</div>
              <div style="font-size:16px; font-weight:800; color:var(--primary); font-family:monospace;" id="dtlRef">LR-2026-XXX</div>
            </div>
            <div id="dtlStatusBadge"></div>
          </div>
          <div style="margin-top:10px; font-size:13px; display:grid; grid-template-columns:1fr 1fr; gap:8px;">
            <div><strong>Staff:</strong> <span id="dtlStaff"></span></div>
            <div><strong>Category:</strong> <span id="dtlType"></span></div>
            <div><strong>Dates:</strong> <span id="dtlDates"></span></div>
            <div><strong>Duration:</strong> <span id="dtlDays" style="font-weight:700; color:var(--accent);"></span></div>
          </div>
        </div>

        <!-- 3-Stage Dual Signoff Stepper -->
        <div style="font-size:11.5px; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:4px;">Dual Approval & Sign-Off Workflow:</div>
        <div class="stepper-container">
          <div class="step-item completed" id="step1">
            <div class="step-circle"><i data-lucide="check" style="width:14px;height:14px;"></i></div>
            <div class="step-label">1. Staff Filed</div>
          </div>
          <div class="step-item" id="step2">
            <div class="step-circle" id="step2Circle">2</div>
            <div class="step-label">2. Lead Endorsement</div>
          </div>
          <div class="step-item" id="step3">
            <div class="step-circle" id="step3Circle">3</div>
            <div class="step-label">3. Partner Signoff</div>
          </div>
        </div>

        <div style="background:#fff; border:1px solid var(--border-color); border-radius:var(--radius-md); padding:12px; margin-bottom:14px;">
          <div style="font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:4px;">Client Handoff & Engagement Notes:</div>
          <div id="dtlReason" style="font-size:12.5px; color:var(--text-main); line-height:1.5;"></div>
        </div>

        <div id="dtlAttachmentSection" style="margin-bottom:14px; display:none;">
          <div style="font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:4px;">Attached Supporting Verification:</div>
          <a href="javascript:void(0)" id="dtlDocLink" class="doc-chip" style="padding:6px 12px; font-size:12px;">
            <i data-lucide="file-text" style="width:14px;height:14px;"></i>
            <span id="dtlDocName">View Attached Medical Slip</span>
          </a>
        </div>

        <div id="dtlApproverSection" style="font-size:12px; color:var(--text-muted); border-top:1px dashed var(--border-color); padding-top:10px;">
          <strong>Managerial Signoff:</strong> <span id="dtlApprover" style="color:var(--text-main); font-weight:600;"></span>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-primary" onclick="closeModal('detailsModal')">Close</button>
      </div>
    </div>
  </div>

  <!-- DOCUMENT PREVIEW MODAL -->
  <div class="modal-backdrop" id="docModal">
    <div class="modal-window" style="max-width:550px;">
      <div class="modal-header">
        <h3><i data-lucide="file-text"></i> Attached Medical / Supporting Document</h3>
        <button class="btn-close-modal" onclick="closeModal('docModal')">&times;</button>
      </div>
      <div class="modal-body">
        <div style="border:2px dashed var(--border-strong); border-radius:var(--radius-md); padding:24px; text-align:center; background:var(--bg-subtle);">
          <i data-lucide="file-check-2" style="width:48px;height:48px;color:var(--success);margin:0 auto 12px;display:block;"></i>
          <h4 id="docModalTitle" style="font-size:15px; color:var(--primary); margin-bottom:6px;">Medical Certificate Verification</h4>
          <p id="docModalStaff" style="font-size:12.5px; color:var(--text-muted); margin-bottom:14px;"></p>
          <div style="background:#fff; border:1px solid var(--border-color); border-radius:var(--radius-md); padding:16px; text-align:left; font-size:12px; line-height:1.6;">
            <strong>Clinic:</strong> St. Luke's Medical Clinic &bull; Attending: Dr. Roberto Santos, MD<br>
            <strong>Diagnosis:</strong> Acute viral illness / respiratory infection with fever.<br>
            <strong>Recommendation:</strong> Medically excused from work for 2 consecutive days. Fit to resume engagement duties thereafter.<br>
            <strong>Status:</strong> <span class="badge badge-approved" style="font-size:10px;">Verified Official Slip</span>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closeModal('docModal')">Close Preview</button>
      </div>
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

    const userBalances = {
      'SIL': <?= (float)$silBalance ?>,
      'VL': <?= (float)$vlBalance ?>,
      'SL': <?= (float)$slBalance ?>,
      'SoloParent': <?= (float)$splBalance ?>
    };

    function calculateWorkingDays() {
      const startStr = document.getElementById('applyStartDate').value;
      const endStr = document.getElementById('applyEndDate').value;
      const duration = document.getElementById('applyDurationMode').value;
      const leaveType = document.getElementById('applyLeaveType').value;
      
      const balEl = document.getElementById('availableBalancePreview');
      if (balEl) {
        if (userBalances[leaveType] !== undefined) {
          balEl.innerText = `${userBalances[leaveType].toFixed(1)} Days`;
          balEl.style.color = 'var(--accent)';
        } else if (leaveType === 'Bereavement') {
          balEl.innerText = '3.0 - 5.0 Days (Paid)';
          balEl.style.color = 'var(--success)';
        } else if (leaveType === 'Study') {
          balEl.innerText = 'CPA Exam / CPD Entitlement';
          balEl.style.color = 'var(--accent)';
        } else if (leaveType === 'Emergency') {
          balEl.innerText = 'Calamity Assistance';
          balEl.style.color = 'var(--warning)';
        } else if (leaveType === 'Paternity') {
          balEl.innerText = '7.0 Days (RA 8187)';
          balEl.style.color = 'var(--success)';
        } else if (leaveType === 'Maternity') {
          balEl.innerText = '105 Days (RA 11210)';
          balEl.style.color = 'var(--success)';
        } else if (leaveType === 'MagnaCarta') {
          balEl.innerText = 'Up to 60 Days (RA 9710)';
          balEl.style.color = 'var(--success)';
        } else if (leaveType === 'VAWC') {
          balEl.innerText = '10 Days (RA 9262)';
          balEl.style.color = 'var(--success)';
        } else if (leaveType === 'Unpaid') {
          balEl.innerText = 'Leave Without Pay';
          balEl.style.color = 'var(--text-muted)';
        } else {
          balEl.innerText = 'Special Benefit';
          balEl.style.color = 'var(--accent)';
        }
      }

      if (!startStr || !endStr) return;
      const start = new Date(startStr);
      const end = new Date(endStr);
      if (start > end) { document.getElementById('computedDaysPreview').innerText = 'Invalid Date Range'; return; }
      
      // Peak Tax Season Blackout Alert
      const taxNotice = document.getElementById('taxSeasonNotice');
      if (taxNotice) {
        const m = start.getMonth() + 1;
        const d = start.getDate();
        const isTaxPeak = (m === 3 && d >= 15) || (m === 4 && d <= 15);
        if (isTaxPeak) {
          taxNotice.style.display = 'flex';
          taxNotice.innerHTML = `<i data-lucide="alert-triangle"></i><div><strong>⚠️ Peak BIR Tax Season Notice (March 15 - April 15):</strong> Leaves filed during the annual income tax return filing window require client engagement coverage & Managing Partner final endorsement.</div>`;
          if (window.lucide) lucide.createIcons();
        } else {
          taxNotice.style.display = 'none';
        }
      }

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

    let currentDecisionRef = '';
    function openDecisionModal(ref, emp, type, days, dept, attachment) {
      currentDecisionRef = ref;
      document.getElementById('decModalRef').innerText = ref;
      document.getElementById('decModalStaff').innerText = emp;
      document.getElementById('decModalType').innerText = type;
      document.getElementById('decModalDays').innerText = days;
      document.getElementById('decModalDept').innerText = dept || 'Practice Team';
      document.getElementById('decModalNote').value = '';

      const docBadge = document.getElementById('decModalDocBadge');
      if (attachment) {
        docBadge.innerHTML = `<span class="doc-chip" onclick="openDocPreview('${attachment}', '${emp}', '${type}')"><i data-lucide="paperclip" style="width:12px;height:12px;"></i> View Attached Doc</span>`;
      } else {
        docBadge.innerHTML = '';
      }

      openModal('decisionModal');
      if (window.lucide) lucide.createIcons();
    }

    async function executeDecisionWithNote(decision) {
      const note = document.getElementById('decModalNote').value.trim();
      try {
        const res = await fetch('actions/decide_leave.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ ref_no: currentDecisionRef, decision: decision, reason: note })
        });
        const data = await res.json();
        if (data.success) {
          showToast(data.message, 'success');
          closeModal('decisionModal');
          setTimeout(() => window.location.reload(), 700);
        } else {
          showToast(data.message || 'Error executing decision', 'error');
        }
      } catch (err) {
        showToast('Network error while processing decision', 'error');
      }
    }

    async function handleBackendLeaveSubmit(e) {
      e.preventDefault();
      const form = document.getElementById('phpLeaveForm');
      const formData = new FormData(form);
      const btn = document.getElementById('btnSubmitLeave');
      if (btn) { btn.disabled = true; btn.innerText = 'Saving...'; }
      try {
        const res = await fetch('actions/apply_leave.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
          showToast(data.message, 'success');
          closeModal('applyModal');
          setTimeout(() => window.location.reload(), 800);
        } else {
          showToast(data.message || 'Error submitting leave', 'error');
          if (btn) { btn.disabled = false; btn.innerText = 'Submit Application'; }
        }
      } catch (err) {
        showToast('Network error while saving leave.', 'error');
        if (btn) { btn.disabled = false; btn.innerText = 'Submit Application'; }
      }
    }

    function viewDetailsModal(ref, emp, type, days, start, end, reason, status, approver, rejectReason, attachment) {
      document.getElementById('dtlRef').innerText = ref;
      document.getElementById('dtlStaff').innerText = emp;
      document.getElementById('dtlType').innerText = type;
      document.getElementById('dtlDates').innerText = `${start} to ${end}`;
      document.getElementById('dtlDays').innerText = `${days} Working Day(s)`;
      document.getElementById('dtlReason').innerText = reason;
      document.getElementById('dtlApprover').innerText = approver || 'Pending Lead Review';

      let statusBadge = `<span class="badge badge-pending">${status}</span>`;
      if (status === 'Approved') statusBadge = `<span class="badge badge-approved">Approved</span>`;
      if (status === 'Rejected') statusBadge = `<span class="badge badge-rejected">Rejected</span>`;
      if (status.includes('Endorsed')) statusBadge = `<span class="badge badge-spl">${status}</span>`;
      document.getElementById('dtlStatusBadge').innerHTML = statusBadge;

      const s2 = document.getElementById('step2');
      const s3 = document.getElementById('step3');
      s2.className = 'step-item';
      s3.className = 'step-item';
      if (status === 'Approved') {
        s2.className = 'step-item completed';
        s3.className = 'step-item completed';
        document.getElementById('step2Circle').innerHTML = '<i data-lucide="check" style="width:14px;height:14px;"></i>';
        document.getElementById('step3Circle').innerHTML = '<i data-lucide="check" style="width:14px;height:14px;"></i>';
      } else if (status.includes('Endorsed')) {
        s2.className = 'step-item completed';
        s3.className = 'step-item active';
        document.getElementById('step2Circle').innerHTML = '<i data-lucide="check" style="width:14px;height:14px;"></i>';
        document.getElementById('step3Circle').innerText = '3';
      } else {
        s2.className = 'step-item active';
        document.getElementById('step2Circle').innerText = '2';
        document.getElementById('step3Circle').innerText = '3';
      }

      const attSec = document.getElementById('dtlAttachmentSection');
      if (attachment) {
        attSec.style.display = 'block';
        document.getElementById('dtlDocLink').onclick = () => openDocPreview(attachment, emp, type);
      } else {
        attSec.style.display = 'none';
      }

      openModal('detailsModal');
      if (window.lucide) lucide.createIcons();
    }

    function openDocPreview(path, emp, type) {
      document.getElementById('docModalTitle').innerText = `${type} Supporting Verification`;
      document.getElementById('docModalStaff').innerText = `Submitted by: ${emp}`;
      openModal('docModal');
      if (window.lucide) lucide.createIcons();
    }

    if (window.lucide) lucide.createIcons();
  </script>
</body>
</html>ate();
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

    const userBalances = {
      'SIL': <?= (float)$silBalance ?>,
      'VL': <?= (float)$vlBalance ?>,
      'SL': <?= (float)$slBalance ?>,
      'SoloParent': <?= (float)$splBalance ?>
    };

    function calculateWorkingDays() {
      const startStr = document.getElementById('applyStartDate').value;
      const endStr = document.getElementById('applyEndDate').value;
      const duration = document.getElementById('applyDurationMode').value;
      const leaveType = document.getElementById('applyLeaveType').value;
      
      const balEl = document.getElementById('availableBalancePreview');
      if (balEl) {
        if (userBalances[leaveType] !== undefined) {
          balEl.innerText = `${userBalances[leaveType].toFixed(1)} Days`;
          balEl.style.color = 'var(--accent)';
        } else if (leaveType === 'Bereavement') {
          balEl.innerText = '3.0 - 5.0 Days (Paid)';
          balEl.style.color = 'var(--success)';
        } else if (leaveType === 'Study') {
          balEl.innerText = 'CPA Exam / CPD Entitlement';
          balEl.style.color = 'var(--accent)';
        } else if (leaveType === 'Emergency') {
          balEl.innerText = 'Calamity Assistance';
          balEl.style.color = 'var(--warning)';
        } else if (leaveType === 'Paternity') {
          balEl.innerText = '7.0 Days (RA 8187)';
          balEl.style.color = 'var(--success)';
        } else if (leaveType === 'Maternity') {
          balEl.innerText = '105 Days (RA 11210)';
          balEl.style.color = 'var(--success)';
        } else if (leaveType === 'MagnaCarta') {
          balEl.innerText = 'Up to 60 Days (RA 9710)';
          balEl.style.color = 'var(--success)';
        } else if (leaveType === 'VAWC') {
          balEl.innerText = '10 Days (RA 9262)';
          balEl.style.color = 'var(--success)';
        } else if (leaveType === 'Unpaid') {
          balEl.innerText = 'Leave Without Pay';
          balEl.style.color = 'var(--text-muted)';
        } else {
          balEl.innerText = 'Special Benefit';
          balEl.style.color = 'var(--accent)';
        }
      }

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

    document.getElementById('applyLeaveType').addEventListener('change', calculateWorkingDays);
    document.getElementById('applyDurationMode').addEventListener('change', calculateWorkingDays);
    document.getElementById('applyStartDate').addEventListener('change', calculateWorkingDays);
    document.getElementById('applyEndDate').addEventListener('change', calculateWorkingDays);

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
