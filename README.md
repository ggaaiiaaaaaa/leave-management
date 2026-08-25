# JTYeo CPA Accounting Office — Leave Management System (LMS)

A role-separated, DOLE-compliant Leave & Attendance Management System built with PHP, SQLite, and vanilla CSS/JS tailored for Philippine CPA and Auditing firms.

---

## 🌟 Key Features

1. **Role-Separated Portals & Dedicated Dashboards**:
   - 👤 **Staff CPA Portal (`staff_dashboard.php`)**: Personal statutory leave balances (SIL, VL, SL, Solo Parent), leave request filing with live duration calculator, personal history, and team calendar.
   - 👔 **Senior Lead / Supervisor Portal (`supervisor_dashboard.php`)**: Front-and-center pending approvals queue with interactive decision modal & feedback notes.
   - 🏛️ **Managing Partner & HR Portal (`admin_dashboard.php`)**: Firm-wide workforce analytics, master leave ledger, HR credit adjuster (+/- days) & monthly/annual DOLE accrual engine, payroll CSV export, and audit activity trail.

2. **Philippine DOLE Statutory Compliance**:
   - **Service Incentive Leave (SIL - Art. 95)**: 5.0 monetizable days per year.
   - **Vacation & Sick Leave**: Configurable company allocations (12d VL, 10d SL).
   - **Special Statutory Leaves**: Solo Parent Leave (RA 8972 / RA 11861 - 7 days), Magna Carta of Women (RA 9710), and VAWC Leave (RA 9262).

3. **Philippine Holiday & Working Day Calculator**:
   - Automatically excludes weekends and official Regular/Special Non-Working Philippine holidays from leave duration calculations.

4. **Interactive FullCalendar Integration**:
   - Visual monthly calendar displaying staff out of office across Audit, Tax, and Advisory engagement teams.

5. **HR Credit Manager & DOLE Accrual Engine**:
   - ⚡ Global Monthly Accrual (`+1.25d VL`).
   - 🔄 Annual DOLE SIL Reset (`5.0d`) for fiscal year rollover.
   - Targeted manual balance adjustments with administrative audit logging.

6. **Payroll & Attendance CSV Export**:
   - 1-click export of leave records formatted for Philippine payroll processing and deductions.

---

## 🚀 Quick Start (Local Setup)

1. Clone this repository into your XAMPP `htdocs` directory:
   ```bash
   git clone https://github.com/ggaaiiaaaaaa/leave-management.git c:\xampp\htdocs\leave-jtyeo
   ```
2. Start **Apache** in the XAMPP Control Panel.
3. Open your browser and navigate to:
   ```
   http://localhost/leave-jtyeo/
   ```

---

## 👥 Demo Personas (1-Click Login / Switcher)

| Role | Name | Email | Password |
| :--- | :--- | :--- | :--- |
| **Staff CPA** | Jessica Alcantara, CPA | `jessica@jtyeocpa.ph` | `password123` |
| **Senior Lead** | Mark Castillo, CPA | `mark@jtyeocpa.ph` | `password123` |
| **Managing Partner / HR** | Atty. Jonathan Yeo, CPA | `admin@jtyeocpa.ph` | `password123` |
