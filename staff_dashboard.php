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
        <div class="nav-category">Self-Service</div>
        <a class="nav-item active" data-tab="my-portal" onclick="switchTab('my-portal')">
          <i data-lucide="layout-dashboard"></i>
          <span>My Balances & Leaves</span>
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
                <span class="kpi-label">Service Incentive (SIL)</span>
                <div class="kpi-icon"><i data-lucide="shield-check"></i></div>
              </div>
              <div class="kpi-value-row">
                <span class="kpi-value"><?= number_format($silBalance, 1) ?></span>
                <span class="kpi-sub">/ 5.0 Days</span>
              </div>
              <div class="kpi-footer positive">
                <i data-lucide="info" style="width: 14px; height: 14px;"></i>
                <span>DOLE Art. 95</span>
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
                <i data-lucide="check" style="width: 14px; height: 14px;"></i>
                <span>Company Benefit</span>
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
                <i data-lucide="file-text" style="width: 14px; height: 14px;"></i>
                <span>Medical Policy</span>
              </div>
            </div>

            <div class="kpi-card amber">
              <div class="kpi-header">
                <span class="kpi-label">Solo Parent Leave</span>
                <div class="kpi-icon"><i data-lucide="user-check"></i></div>
              </div>
              <div class="kpi-value-row">
                <span class="kpi-value"><?= number_format($splBalance, 1) ?></span>
                <span class="kpi-sub">/ 7.0 Days</span>
              </div>
              <div class="kpi-footer neutral">
                <i data-lucide="info" style="width: 14px; height: 14px;"></i>
                <span>RA 8972 Eligible</span>
              </div>
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
                    <th>Leave Category</th>
                    <th>Requested Dates</th>
                    <th>Reason / Engagement Details</th>
                    <th>Status</th>
                    <th>Action</th>
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
                            if (str_contains($req['status'], 'Endorsed')) $statusBadge = 'badge-spl';
                          ?>
                          <span class="badge <?= $statusBadge ?>"><?= $req['status'] ?></span>
                        </td>
                        <td>
                          <button class="btn-icon" title="View Dual Signoff Stepper & Details" onclick="viewDetailsModal('<?= $req['ref_no'] ?>', '<?= addslashes($req['employee_name']) ?>', '<?= addslashes($req['leave_type_label']) ?>', '<?= $req['days_count'] ?>', '<?= $req['start_date'] ?>', '<?= $req['end_date'] ?>', '<?= addslashes($req['reason']) ?>', '<?= $req['status'] ?>', '<?= addslashes($req['approver_name'] ?? 'Pending') ?>', '<?= addslashes($req['rejection_reason'] ?? '') ?>')">
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
          <div id="taxSeasonNotice" class="tax-season-banner" style="display:none;"></div>

          <div class="form-grid">
            <div class="form-group">
              <label class="form-label">Leave Category <span class="req">*</span></label>
              <select name="leave_type" id="applyLeaveType" class="form-select" required onchange="calculateWorkingDays()">
                <option value="SIL">Service Incentive Leave (SIL - 5 Days)</option>
                <option value="VL" selected>Vacation Leave (VL)</option>
                <option value="SL">Sick Leave (SL)</option>
                <option value="Bereavement">Bereavement Leave</option>
                <option value="Emergency">Emergency / Calamity Leave</option>
                <option value="Study">CPA Board Exam / CPD Study Leave</option>
                <option value="SoloParent">Solo Parent Leave (RA 8972)</option>
                <option value="Paternity">Paternity Leave (RA 8187)</option>
                <option value="Maternity">Maternity Leave (RA 11210)</option>
                <option value="MagnaCarta">Magna Carta of Women (RA 9710)</option>
                <option value="VAWC">VAWC Leave (RA 9262)</option>
                <option value="Unpaid">Leave Without Pay (LWOP)</option>
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
          </div>

          <div class="form-group" style="margin-top:16px;">
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

  <!-- DETAILS MODAL -->
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
              <div style="font-size:16px; font-weight:800; color:var(--primary); font-family:monospace;" id="dtlRef"></div>
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

        <div style="font-size:11.5px; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:4px;">Dual Approval & Sign-Off Workflow:</div>
        <div class="stepper-container" id="dtlStepper">
          <div class="step-item completed" id="step1"><div class="step-circle"><i data-lucide="check" style="width:14px;height:14px;"></i></div><div class="step-label">1. Staff Filed</div></div>
          <div class="step-item" id="step2"><div class="step-circle" id="step2Circle">2</div><div class="step-label">2. Lead Endorsement</div></div>
          <div class="step-item" id="step3"><div class="step-circle" id="step3Circle">3</div><div class="step-label">3. Partner Signoff</div></div>
        </div>

        <div style="background:#fff; border:1px solid var(--border-color); border-radius:var(--radius-md); padding:12px; margin-bottom:14px;">
          <div style="font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:4px;">Client Handoff & Engagement Notes:</div>
          <div id="dtlReason" style="font-size:12.5px; color:var(--text-main); line-height:1.5;"></div>
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
      
      const taxNotice = document.getElementById('taxSeasonNotice');
      if (taxNotice) {
        const m = start.getMonth() + 1;
        const d = start.getDate();
        const isTaxPeak = (m === 3 && d >= 15) || (m === 4 && d <= 15);
        if (isTaxPeak) {
          taxNotice.style.display = 'flex';
          taxNotice.innerHTML = `<i data-lucide="alert-triangle"></i><div><strong>⚠️ Peak Tax Season:</strong> Leaves require partner endorsement.</div>`;
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

      openModal('detailsModal');
      if (window.lucide) lucide.createIcons();
    }

    if (window.lucide) lucide.createIcons();
  </script>
</body>
</html>
