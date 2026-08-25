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

