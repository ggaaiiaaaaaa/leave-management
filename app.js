// JTYEO & Associates CPAs — Leave Management System (LMS)
// Client Mockup & Demonstration Engine

// 1. Initial State & Realistic CPA Firm Data
const state = {
  currentRole: 'employee', // 'employee' | 'supervisor' | 'admin'
  currentUser: {
    name: 'Jessica Alcantara, CPA',
    role: 'Senior Tax Associate (Taxation Dept)',
    avatar: 'JA',
    balances: {
      SIL: 4.0, // DOLE Service Incentive Leave (5 total)
      VL: 8.5,  // Vacation Leave (12 total)
      SL: 9.0,  // Sick Leave (10 total)
      SoloParent: 7.0
    }
  },
  leaveRequests: [
    {
      id: 'LR-2026-081',
      employee: 'Jessica Alcantara, CPA',
      dept: 'Taxation & Compliance',
      type: 'VL',
      typeName: 'Vacation Leave',
      startDate: '2026-09-04',
      endDate: '2026-09-05',
      days: 2.0,
      reason: 'Post-filing rest & personal matters. Working papers delegated to Carlos.',
      status: 'Pending',
      submittedAt: '2026-08-24 09:15 AM',
      approver: 'Mark Castillo (Lead)'
    },
    {
      id: 'LR-2026-079',
      employee: 'Elena Lim',
      dept: 'Taxation & Compliance',
      type: 'SL',
      typeName: 'Sick Leave',
      startDate: '2026-08-25',
      endDate: '2026-08-25',
      days: 1.0,
      reason: 'Severe migraine & flu. Medical consultation slip uploaded.',
      status: 'Approved',
      submittedAt: '2026-08-25 07:45 AM',
      approver: 'Atty. Jonathan Yeo (Partner)'
    },
    {
      id: 'LR-2026-078',
      employee: 'Mark Castillo, CPA',
      dept: 'Audit & Assurance',
      type: 'VL',
      typeName: 'Vacation Leave',
      startDate: '2026-08-26',
      endDate: '2026-08-27',
      days: 2.0,
      reason: 'Family out of town before San Miguel Corp year-end audit commencement.',
      status: 'Approved',
      submittedAt: '2026-08-22 02:30 PM',
      approver: 'Atty. Jonathan Yeo (Partner)'
    },
    {
      id: 'LR-2026-075',
      employee: 'Rico Tolentino',
      dept: 'Bookkeeping & Advisory',
      type: 'SIL',
      typeName: 'Service Incentive Leave',
      startDate: '2026-08-20',
      endDate: '2026-08-20',
      days: 0.5,
      reason: 'LGU Barangay clearance renewal (Half-day afternoon).',
      status: 'Approved',
      submittedAt: '2026-08-19 11:00 AM',
      approver: 'Mark Castillo (Lead)'
    },
    {
      id: 'LR-2026-072',
      employee: 'Patricia Tan',
      dept: 'Audit & Assurance',
      type: 'VL',
      typeName: 'Vacation Leave',
      startDate: '2026-04-10',
      endDate: '2026-04-14',
      days: 3.0,
      reason: 'Planned vacation during BIR tax deadline week.',
      status: 'Rejected',
      submittedAt: '2026-08-15 04:00 PM',
      approver: 'Atty. Jonathan Yeo (Partner)',
      rejectReason: 'Peak BIR Tax filing blackout window. Reschedule post-April 15.'
    }
  ],
  phHolidays: [
    '2026-01-01', '2026-02-25', '2026-04-02', '2026-04-03', '2026-04-09',
    '2026-05-01', '2026-06-12', '2026-08-31', '2026-11-01', '2026-11-02',
    '2026-11-30', '2026-12-25', '2026-12-30', '2026-12-31'
  ]
};

// 2. DOM Initialization
document.addEventListener('DOMContentLoaded', () => {
  if (window.lucide) {
    lucide.createIcons();
  }
  
  initLiveClock();
  setDefaultDates();
  renderBalances();
  renderLeaveTable();
  renderApprovalsQueue();
  renderReportsTable();
  updatePendingBadge();
});

// 3. Live Philippine Clock
function initLiveClock() {
  const clockEl = document.getElementById('liveClock');
  const update = () => {
    const now = new Date();
    const options = { 
      timeZone: 'Asia/Manila',
      hour12: true, 
      hour: '2-digit', 
      minute: '2-digit', 
      second: '2-digit',
      weekday: 'short',
      month: 'short',
      day: 'numeric'
    };
    clockEl.innerText = 'PHT: ' + now.toLocaleString('en-US', options);
  };
  update();
  setInterval(update, 1000);
}

// 4. Set Default Form Dates
function setDefaultDates() {
  const today = new Date();
  const nextWeek = new Date();
  nextWeek.setDate(today.getDate() + 7);

  const startInput = document.getElementById('applyStartDate');
  const endInput = document.getElementById('applyEndDate');
  
  if (startInput && endInput) {
    startInput.value = formatDateYMD(today);
    endInput.value = formatDateYMD(today);
    calculateWorkingDays();
  }
}

function formatDateYMD(d) {
  const year = d.getFullYear();
  const month = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

// 5. Role Switcher Logic
function switchRole(role) {
  state.currentRole = role;
  
  document.querySelectorAll('.role-btn').forEach(btn => btn.classList.remove('active'));
  document.getElementById(`role-${role}`).classList.add('active');

  const avatar = document.getElementById('userAvatar');
  const nameEl = document.getElementById('currentUserName');
  const roleEl = document.getElementById('currentUserRole');

  if (role === 'employee') {
    state.currentUser.name = 'Jessica Alcantara, CPA';
    state.currentUser.role = 'Senior Tax Associate (Taxation Dept)';
    state.currentUser.avatar = 'JA';
    showToast('Switched to Staff Accountant view (Jessica Alcantara)', 'info');
  } else if (role === 'supervisor') {
    state.currentUser.name = 'Mark Castillo, CPA';
    state.currentUser.role = 'Senior Audit Lead / Reviewer';
    state.currentUser.avatar = 'MC';
    showToast('Switched to Senior Audit Lead view (Mark Castillo)', 'info');
  } else if (role === 'admin') {
    state.currentUser.name = 'Atty. Jonathan Yeo, CPA';
    state.currentUser.role = 'Managing Partner & HR Head';
    state.currentUser.avatar = 'JY';
    showToast('Switched to Managing Partner / HR view (Atty. Yeo)', 'info');
  }

  avatar.innerText = state.currentUser.avatar;
  nameEl.innerText = state.currentUser.name;
  roleEl.innerText = state.currentUser.role;

  renderLeaveTable();
  renderApprovalsQueue();
}

// 6. Navigation Tabs
function switchTab(tabId) {
  document.querySelectorAll('.sidebar-nav .nav-item').forEach(item => {
    item.classList.remove('active');
    if (item.getAttribute('data-tab') === tabId) {
      item.classList.add('active');
    }
  });

  document.querySelectorAll('.tab-pane').forEach(pane => {
    pane.style.display = 'none';
  });

  const activePane = document.getElementById(`tab-${tabId}`);
  if (activePane) {
    activePane.style.display = 'block';
  }

  if (window.lucide) {
    lucide.createIcons();
  }
}

// 7. Render Balance Display
function renderBalances() {
  const b = state.currentUser.balances;
  document.getElementById('silBalanceDisplay').innerText = b.SIL.toFixed(1);
  document.getElementById('vlBalanceDisplay').innerText = b.VL.toFixed(1);
  document.getElementById('slBalanceDisplay').innerText = b.SL.toFixed(1);

  document.getElementById('cardSilBal').innerText = b.SIL.toFixed(1);
  document.getElementById('cardVlBal').innerText = b.VL.toFixed(1);
  document.getElementById('cardSlBal').innerText = b.SL.toFixed(1);
}

// 8. Working Days Calculator
function calculateWorkingDays() {
  const startStr = document.getElementById('applyStartDate').value;
  const endStr = document.getElementById('applyEndDate').value;
  const durationMode = document.getElementById('applyDurationMode').value;
  const leaveType = document.getElementById('applyLeaveType').value;

  if (!startStr || !endStr) return;

  const start = new Date(startStr);
  const end = new Date(endStr);

  if (start > end) {
    document.getElementById('computedDaysPreview').innerText = 'Invalid Date Range';
    return;
  }

  let workingDays = 0;
  let cur = new Date(start);

  while (cur <= end) {
    const dayOfWeek = cur.getDay(); // 0 = Sun, 6 = Sat
    const ymd = formatDateYMD(cur);
    const isWeekend = (dayOfWeek === 0 || dayOfWeek === 6);
    const isHoliday = state.phHolidays.includes(ymd);

    if (!isWeekend && !isHoliday) {
      workingDays += 1;
    }
    cur.setDate(cur.getDate() + 1);
  }

  if (durationMode === 'half-am' || durationMode === 'half-pm') {
    workingDays = Math.min(workingDays, 1) * 0.5;
  }

  document.getElementById('computedDaysPreview').innerText = `${workingDays} Working Day${workingDays === 1 ? '' : 's'}`;

  // Estimate Remaining Balance
  let curBal = state.currentUser.balances[leaveType] || 0;
  let remainingAfter = Math.max(0, curBal - workingDays);
  document.getElementById('newBalPreview').innerText = `${remainingAfter.toFixed(1)} Days`;
}

// 9. Handle Leave Submission
function handleLeaveSubmit(e) {
  e.preventDefault();

  const leaveType = document.getElementById('applyLeaveType').value;
  const leaveTypeText = document.getElementById('applyLeaveType').selectedOptions[0].text.split('(')[0].trim();
  const startDate = document.getElementById('applyStartDate').value;
  const endDate = document.getElementById('applyEndDate').value;
  const durationMode = document.getElementById('applyDurationMode').value;
  const reason = document.getElementById('applyReason').value;

  // Calculate days
  const start = new Date(startDate);
  const end = new Date(endDate);
  let workingDays = 0;
  let cur = new Date(start);

  while (cur <= end) {
    const dayOfWeek = cur.getDay();
    const isWeekend = (dayOfWeek === 0 || dayOfWeek === 6);
    if (!isWeekend) workingDays += 1;
    cur.setDate(cur.getDate() + 1);
  }
  if (durationMode.startsWith('half')) {
    workingDays = 0.5;
  }

  // Deduct balance
  if (state.currentUser.balances[leaveType] !== undefined) {
    state.currentUser.balances[leaveType] = Math.max(0, state.currentUser.balances[leaveType] - workingDays);
  }

  // Create new request
  const newReq = {
    id: `LR-2026-0${Math.floor(82 + Math.random() * 20)}`,
    employee: state.currentUser.name,
    dept: 'Taxation & Compliance',
    type: leaveType,
    typeName: leaveTypeText,
    startDate: startDate,
    endDate: endDate,
    days: workingDays,
    reason: reason,
    status: 'Pending',
    submittedAt: 'Just now',
    approver: 'Mark Castillo (Lead)'
  };

  state.leaveRequests.unshift(newReq);

  renderBalances();
  renderLeaveTable();
  renderApprovalsQueue();
  renderReportsTable();
  updatePendingBadge();

  closeModal('applyModal');
  showToast(`Leave request (${workingDays} day/s) successfully filed for ${leaveTypeText}!`, 'success');
  document.getElementById('leaveApplicationForm').reset();
  setDefaultDates();
}

// 10. Render Leave Table
function renderLeaveTable() {
  const filter = document.getElementById('statusFilter').value;
  const tbody = document.getElementById('leaveTableBody');
  tbody.innerHTML = '';

  let list = state.leaveRequests;
  if (state.currentRole === 'employee') {
    // Show only current employee requests or all for demo clarity
    list = state.leaveRequests.filter(r => r.employee === state.currentUser.name || true);
  }

  if (filter !== 'all') {
    list = list.filter(r => r.status === filter);
  }

  list.forEach(req => {
    const tr = document.createElement('tr');
    
    let badgeClass = 'badge-pending';
    if (req.status === 'Approved') badgeClass = 'badge-approved';
    if (req.status === 'Rejected') badgeClass = 'badge-rejected';

    let typeBadge = 'badge-vl';
    if (req.type === 'SIL') typeBadge = 'badge-sil';
    if (req.type === 'SL') typeBadge = 'badge-sl';
    if (req.type === 'SoloParent') typeBadge = 'badge-spl';

    const isSupervisorOrAdmin = state.currentRole === 'supervisor' || state.currentRole === 'admin';

    tr.innerHTML = `
      <td>
        <div style="font-weight:700; color:var(--primary);">${req.employee}</div>
        <div style="font-size:11px; color:var(--text-muted);">${req.dept}</div>
      </td>
      <td>
        <span class="badge ${typeBadge}">${req.typeName}</span>
      </td>
      <td>
        <div style="font-weight:600;">${req.startDate} ${req.startDate !== req.endDate ? 'to ' + req.endDate : ''}</div>
        <div style="font-size:11px; color:var(--text-muted);">${req.days} Working Day(s)</div>
      </td>
      <td>
        <div style="font-size:12.5px; max-width:240px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="${req.reason}">${req.reason}</div>
        <div style="font-size:10.5px; color:var(--text-light);">Submitted: ${req.submittedAt}</div>
      </td>
      <td>
        <span class="badge ${badgeClass}">${req.status}</span>
      </td>
      <td>
        <div class="action-btn-group">
          ${isSupervisorOrAdmin && req.status === 'Pending' ? `
            <button class="btn-icon approve" title="Approve Leave" onclick="decideLeave('${req.id}', 'Approved')">
              <i data-lucide="check" style="width:14px;height:14px;"></i>
            </button>
            <button class="btn-icon reject" title="Reject Leave" onclick="decideLeave('${req.id}', 'Rejected')">
              <i data-lucide="x" style="width:14px;height:14px;"></i>
            </button>
          ` : `
            <button class="btn-icon" title="View Details" onclick="viewDetails('${req.id}')">
              <i data-lucide="eye" style="width:14px;height:14px;"></i>
            </button>
          `}
        </div>
      </td>
    `;
    tbody.appendChild(tr);
  });

  if (window.lucide) {
    lucide.createIcons();
  }
}

// 11. Render Approvals Queue Tab
function renderApprovalsQueue() {
  const tbody = document.getElementById('approvalsQueueBody');
  if (!tbody) return;
  tbody.innerHTML = '';

  const pendingList = state.leaveRequests.filter(r => r.status === 'Pending');

  pendingList.forEach(req => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>
        <div style="font-weight:700; color:var(--primary);">${req.employee}</div>
        <div style="font-size:11px; color:var(--text-muted);">${req.dept}</div>
      </td>
      <td><span class="badge badge-vl">${req.typeName}</span></td>
      <td>${req.startDate} to ${req.endDate}</td>
      <td><strong>${req.days} Day(s)</strong></td>
      <td style="font-size:12.5px;">${req.reason}</td>
      <td>
        <div style="display:flex; gap:8px;">
          <button class="btn-primary" style="padding:6px 12px; font-size:12px;" onclick="decideLeave('${req.id}', 'Approved')">
            <i data-lucide="check" style="width:13px;height:13px;"></i> Approve
          </button>
          <button class="btn-secondary" style="padding:6px 12px; font-size:12px; color:var(--danger);" onclick="decideLeave('${req.id}', 'Rejected')">
            <i data-lucide="x" style="width:13px;height:13px;"></i> Reject
          </button>
        </div>
      </td>
    `;
    tbody.appendChild(tr);
  });

  const countText = document.getElementById('pendingCountText');
  if (countText) {
    countText.innerText = `${pendingList.length} Pending Request(s)`;
  }
}

// 12. Render Reports Tab
function renderReportsTable() {
  const tbody = document.getElementById('reportsTableBody');
  if (!tbody) return;
  tbody.innerHTML = '';

  state.leaveRequests.forEach(req => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><code style="font-family:'JetBrains Mono'; font-size:11px; font-weight:700;">${req.id}</code></td>
      <td><strong>${req.employee}</strong></td>
      <td>${req.dept}</td>
      <td>${req.typeName}</td>
      <td><span class="badge badge-approved" style="font-size:10.5px;">Paid Statutory</span></td>
      <td>${req.startDate} to ${req.endDate}</td>
      <td>${req.days} Day(s)</td>
      <td><span style="font-size:12px; color:var(--text-muted);">${req.approver || 'Atty. Yeo (HR)'}</span></td>
    `;
    tbody.appendChild(tr);
  });
}

// 13. Leave Decision Handler
function decideLeave(id, decision) {
  const req = state.leaveRequests.find(r => r.id === id);
  if (!req) return;

  req.status = decision;
  req.approver = state.currentUser.name;

  renderLeaveTable();
  renderApprovalsQueue();
  renderReportsTable();
  updatePendingBadge();

  if (decision === 'Approved') {
    showToast(`Leave application #${id} for ${req.employee} has been APPROVED!`, 'success');
  } else {
    showToast(`Leave application #${id} for ${req.employee} was marked as REJECTED.`, 'error');
  }
}

// 14. Update Pending Approvals Badge
function updatePendingBadge() {
  const pending = state.leaveRequests.filter(r => r.status === 'Pending').length;
  const badge = document.getElementById('pendingApprovalsBadge');
  if (badge) {
    badge.innerText = pending;
    badge.style.display = pending > 0 ? 'inline-block' : 'none';
  }
}

// 15. Export CSV for Payroll
function exportLeaveReport() {
  let csvContent = "data:text/csv;charset=utf-8,";
  csvContent += "Reference_ID,Employee_Name,Department,Leave_Type,Days_Duration,Start_Date,End_Date,Status,Approval_Signoff\n";

  state.leaveRequests.forEach(r => {
    const row = `"${r.id}","${r.employee}","${r.dept}","${r.typeName}",${r.days},"${r.startDate}","${r.endDate}","${r.status}","${r.approver || 'Pending'}"`;
    csvContent += row + "\n";
  });

  const encodedUri = encodeURI(csvContent);
  const link = document.createElement("a");
  link.setAttribute("href", encodedUri);
  link.setAttribute("download", `JTYEO_CPAs_Leave_Payroll_Export_${new Date().toISOString().slice(0,10)}.csv`);
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);

  showToast('Generated & downloaded DOLE Payroll CSV Ledger!', 'success');
}

// 16. Modal Management
function openModal(id) {
  const modal = document.getElementById(id);
  if (modal) modal.classList.add('show');
}

function closeModal(id) {
  const modal = document.getElementById(id);
  if (modal) modal.classList.remove('show');
}

function openTierModal() {
  openModal('tierModal');
}

function openStatutoryInfo() {
  switchTab('team-calendar');
}

function openTierPitchScript() {
  showToast('Pitch Tip: Show ₱50k Base, then highlight BIR Season Leave Freeze & 2-Tier approval to upsell ₱70k/₱80k!', 'info');
}

function highlightTierUpgrade(tier) {
  showToast(`Selected Tier: ₱${tier},000 Package! Review full scope in PROPOSAL_TIERS.md`, 'success');
}

function viewDetails(id) {
  const req = state.leaveRequests.find(r => r.id === id);
  if (req) {
    alert(`Leave Reference: ${req.id}\nStaff: ${req.employee} (${req.dept})\nType: ${req.typeName}\nDuration: ${req.days} day(s) from ${req.startDate} to ${req.endDate}\nReason: ${req.reason}\nStatus: ${req.status}\nSignoff: ${req.approver || 'Pending'}`);
  }
}

// 17. Toast Notifications
function showToast(message, type = 'info') {
  const container = document.getElementById('toastContainer');
  const toast = document.createElement('div');
  toast.className = `toast ${type}`;
  toast.innerHTML = `
    <div style="font-size:13px; font-weight:600;">${message}</div>
  `;
  container.appendChild(toast);

  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transform = 'translateX(100%)';
    toast.style.transition = 'all 0.3s ease';
    setTimeout(() => toast.remove(), 300);
  }, 3500);
}
