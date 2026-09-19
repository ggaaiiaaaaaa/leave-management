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
        <a class="nav-item active" data-tab="my-portal" onclick="switchTab('my-portal')">
          <i data-lucide="layout-dashboard"></i>
          <span>My Balances &amp; History</span>
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
              <option value="VL" selected>Vacation Leave</option>
              <option value="SL">Sick Leave</option>
              <option value="Emergency">Emergency Leave</option>
              <option value="Bereavement">Bereavement Leave</option>
              <option value="SoloParent">Solo Parent Leave</option>
              <?php if ($userGender === 'Female'): ?>
                <option value="Maternity">Maternity Leave</option>
                <option value="SpecialWomen">Special Leave for Women</option>
              <?php else: ?>
                <option value="Paternity">Paternity Leave</option>
              <?php endif; ?>
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
              <div style="font-size:11px; color:var(--text-light);">Weekends &amp; PH Holidays excluded</div>
            </div>
            <div style="text-align:right;">
              <div class="calc-label">Available Balance:</div>
              <div class="calc-result" id="availableBalancePreview" style="color:var(--accent);"><?= number_format($vlBalance, 1) ?> Days</div>
            </div>
          </div>

          <!-- Medical Certificate Attachment (Only visible for Emergency & Sick Leave) -->
          <div class="form-group" id="attachmentGroup" style="margin-top:14px; display:none;">
            <label class="form-label"><i data-lucide="paperclip" style="width:13px;height:13px;"></i> Attach Medical Certificate / Proof (Optional):</label>
            <input type="file" name="attachment" id="applyAttachment" class="form-input" accept=".pdf,.jpg,.jpeg,.png">
            <div style="font-size:11px; color:var(--text-muted); margin-top:3px;">Required for medical or urgent emergency leaves (PDF, JPG, PNG).</div>
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

    function calculateWorkingDaysPreview() {
      const startStr = document.getElementById('applyStartDate').value;
      const endStr = document.getElementById('applyEndDate').value;
      const type = document.getElementById('applyLeaveType').value;

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