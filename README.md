# JTYeo CPA Accounting Office — Leave Management System (LMS)

A modern, high-performance Leave Management System custom-engineered for **JTYeo CPA Accounting Office** (Boutique Practice with 6 personnel: 1 Managing Partner + 5 Professional Associates). Built with PHP, SQLite, and vanilla CSS/JavaScript for rapid, zero-dependency local and server deployment.

---

## 🌟 Key System Capabilities

### 1. 👤 Staff CPA Associate Portal (`staff_dashboard.php`)
- **Real-Time Balance Tracking**: Dynamic personal balance cards for Vacation Leave (12.0d), Sick Leave (10.0d), and Total Active Balance (22.0d).
- **Self-Service Leave Application**: Clean modal with live date duration computation and automatic balance previews.
- **5 Core Firm Categories**:
  - 🌴 Vacation Leave (VL)
  - 🏥 Sick Leave (SL)
  - 🚨 Emergency Leave
  - 🕊️ Bereavement Leave
  - 💼 Leave Without Pay (LWOP)
- **Personal Leave History**: Master table tracking status (*Approved, Pending, Rejected*) with 1-click application details inspection.

### 2. 🏛️ Managing Partner & HR Executive Portal (`admin_dashboard.php`)
- **Executive KPI Grid**: Real-time overview of Active Leaves Today, Pending Approval Queue, Approved Applications, and Total Firm Headcount.
- **1-Click Review & Decision Queue**: Instant Approve or Reject actions with engagement feedback notes.
- **Partner Proxy Filing**: Ability for Atty. Jonathan Yeo to submit leaves directly on behalf of any associate with automatic balance lookups.
- **Master Firm Leave Ledger**: Complete audit record of all employee submissions across the practice.
- **Detailed Leave Review Modal**: Clean breakdown of employee info, requested dates, duration, reason notes, and Partner review status.

### 3. ⚡ High-Performance Architecture
- **Offline Icon Engine**: Local Lucide vector icons (`lucide.js`) with zero external CDN dependencies for instant 0ms rendering.
- **Lightweight SQLite Database**: Serverless, zero-configuration database embedded directly in `database/leave_system.db`.
- **Responsive Modern UI**: Custom CSS design system with responsive KPI auto-fitting, card layouts, and mobile-friendly navigation.

---

## 👥 Active Demo Accounts

| Name | Role | Email | Password |
| :--- | :--- | :--- | :--- |
| **Jessica Alcantara, CPA** | Senior Tax Associate (Staff) | `jessica@jtyeocpa.ph` | `password123` |
| **Atty. Jonathan Yeo, CPA** | Managing Partner & HR Head | `admin@jtyeocpa.ph` | `password123` |

---

## 🚀 Quick Start (Local Setup)

1. Clone or place this repository into your XAMPP `htdocs` folder:
   ```bash
   git clone https://github.com/ggaaiiaaaaaa/leave-management.git c:\xampp\htdocs\leave-jtyeo
   ```
2. Start **Apache** in the XAMPP Control Panel.
3. Open your browser and navigate to:
   ```
   http://localhost/leave-jtyeo/
   ```

---

## 💼 Implementation & Pricing Tiers

Detailed commercial specifications for **JTYeo CPA Accounting Office** (see [`PROPOSAL_TIERS.md`](PROPOSAL_TIERS.md)):

* **🥈 Tier 1: Boutique Practice Edition (₱75,000)**
  - Standalone 6-user digital leave management portal
  - 5 core leave categories, personal balance ledgers, and Managing Partner approval queue
  - Partner proxy filing on behalf of associates
  - Full server deployment & 60-day standard maintenance

* **🥇 Tier 2: Boutique Practice Cloud Suite (₱85,000) ⭐ *(Recommended Flagship)***
  - *Everything in Tier 1 PLUS:*
  - **1-Click Formatted Payroll CSV Export** (JuanTax / Sprout / QuickBooks / Excel) with auto-deductions
  - **Year-End Unused Leave Cash Conversion Ledger & Reserve Calculator** (Live payout liability forecast)
  - **Interactive 6-Person Team Coverage Calendar** (Visual schedule preventing overlapping absences)
  - **Digital Medical Certificate & Supporting Proof Engine** (1-click secure in-app viewer)
  - **Immutable Enterprise Audit Trail with Client IP Tracking**
  - **Automated Real-Time Transactional Email Alerts**
  - **Automated Working Days Engine** (Auto-excludes weekends & official statutory holidays)
  - **Peak Tax Season Blackout Advisory Engine** (March 15 – April 15 clearance warnings)
  - **Automated Monthly Accruals (+1.25d/mo) & Annual Rollover Engine**
  - **3 Months Priority SLA Support + Live Staff Onboarding & Training Session**
