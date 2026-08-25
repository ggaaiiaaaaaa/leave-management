<?php
// login.php - Executive Authentication Portal for JTYEO CPAs
require_once __DIR__ . '/config/db.php';

$error = '';
$success = '';

if (isset($_GET['msg']) && $_GET['msg'] === 'logged_out') {
    $success = 'You have been successfully logged out.';
}

// Handle Form Submission (Both manual and 1-Click Demo Login)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Quick demo login trigger
    if (isset($_POST['quick_login'])) {
        $email = $_POST['quick_login'];
        $password = 'password123'; // Standard demo password
    }

    if (empty($email) || empty($password)) {
        $error = 'Please enter both email address and password.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Set session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['department'] = $user['department'];
            $_SESSION['title'] = $user['title'];
            $_SESSION['avatar'] = $user['avatar_initials'];

            header('Location: index.php');
            exit;
        } else {
            $error = 'Invalid email or password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login | JTYeo CPA Accounting Office Leave Portal</title>
  <link rel="stylesheet" href="style.css">
  <script src="https://unpkg.com/lucide@latest"></script>
  <style>
    .login-body {
      background: linear-gradient(135deg, #091728 0%, #0f2744 50%, #1e3e62 100%);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
    }
    .login-card {
      background: #ffffff;
      border-radius: var(--radius-xl);
      box-shadow: 0 20px 50px rgba(0, 0, 0, 0.4);
      width: 100%;
      max-width: 460px;
      overflow: hidden;
    }
    .login-header {
      background: linear-gradient(135deg, #0f2744 0%, #1e3e62 100%);
      color: white;
      padding: 32px 28px 24px;
      text-align: center;
      position: relative;
    }
    .login-logo {
      width: 56px;
      height: 56px;
      background: linear-gradient(135deg, var(--accent) 0%, #0369a1 100%);
      border-radius: var(--radius-md);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 800;
      font-size: 22px;
      margin: 0 auto 14px;
      box-shadow: 0 4px 16px rgba(2, 132, 199, 0.4);
    }
    .login-header h2 {
      font-size: 20px;
      font-weight: 800;
      letter-spacing: -0.5px;
      margin-bottom: 4px;
    }
    .login-header p {
      font-size: 12.5px;
      color: #94a3b8;
    }
    .login-body-content {
      padding: 28px;
    }
    .quick-login-section {
      margin-top: 24px;
      padding-top: 20px;
      border-top: 1px dashed var(--border-color);
    }
    .quick-login-title {
      font-size: 11px;
      font-weight: 700;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.8px;
      text-align: center;
      margin-bottom: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
    }
    .quick-btn-grid {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    .quick-btn {
      display: flex;
      align-items: center;
      justify-content: space-between;
      width: 100%;
      padding: 10px 14px;
      background: var(--bg-subtle);
      border: 1px solid var(--border-color);
      border-radius: var(--radius-md);
      cursor: pointer;
      transition: var(--transition);
      font-size: 12.5px;
      font-weight: 600;
      color: var(--text-main);
      text-align: left;
    }
    .quick-btn:hover {
      background: var(--accent-soft);
      border-color: var(--accent);
      color: var(--accent);
      transform: translateX(2px);
    }
    .quick-btn span.role-pill {
      font-size: 10px;
      font-weight: 700;
      padding: 2px 8px;
      border-radius: 12px;
      background: white;
      color: var(--primary);
      border: 1px solid var(--border-color);
    }
  </style>
</head>
<body class="login-body">
  <div class="login-card">
    <div class="login-header">
      <div class="login-logo">JT</div>
      <h2>JTYeo CPA Accounting Office</h2>
    </div>

    <div class="login-body-content">
      <?php if (!empty($error)): ?>
        <div style="background:var(--danger-soft); border:1px solid #fca5a5; color:#991b1b; padding:10px 14px; border-radius:var(--radius-md); font-size:12.5px; font-weight:600; margin-bottom:16px; display:flex; align-items:center; gap:8px;">
          <i data-lucide="alert-circle" style="width:16px;height:16px;"></i>
          <span><?= htmlspecialchars($error) ?></span>
        </div>
      <?php endif; ?>

      <?php if (!empty($success)): ?>
        <div style="background:var(--success-soft); border:1px solid #86efac; color:#065f46; padding:10px 14px; border-radius:var(--radius-md); font-size:12.5px; font-weight:600; margin-bottom:16px; display:flex; align-items:center; gap:8px;">
          <i data-lucide="check-circle" style="width:16px;height:16px;"></i>
          <span><?= htmlspecialchars($success) ?></span>
        </div>
      <?php endif; ?>

      <form method="POST" action="login.php">
        <div class="form-group">
          <label class="form-label" for="email">Work Email Address</label>
          <input type="email" id="email" name="email" class="form-input" placeholder="e.g., jessica@jtyeocpa.ph" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label class="form-label" for="password">Password</label>
          <input type="password" id="password" name="password" class="form-input" placeholder="••••••••" required>
        </div>

        <button type="submit" class="btn-primary" style="width:100%; justify-content:center; padding:12px; margin-top:8px;">
          <i data-lucide="log-in"></i>
          <span>Sign In to Portal</span>
        </button>
      </form>

      <!-- 1-Click Fast Login for Demo Mode -->
      <div class="quick-login-section">
        <div class="quick-login-title">
          <i data-lucide="shield" style="width:13px;height:13px; color:var(--accent);"></i>
          <span>Quick Role Switcher (Demo Mode)</span>
        </div>
        
        <form method="POST" action="login.php" class="quick-btn-grid">
          <button type="submit" name="quick_login" value="jessica@jtyeocpa.ph" class="quick-btn">
            <div>
              <div><strong>Jessica Alcantara, CPA</strong></div>
              <div style="font-size:11px; color:var(--text-muted);">Senior Tax Associate</div>
            </div>
            <span class="role-pill">Staff View</span>
          </button>

          <button type="submit" name="quick_login" value="mark@jtyeocpa.ph" class="quick-btn">
            <div>
              <div><strong>Mark Castillo, CPA</strong></div>
              <div style="font-size:11px; color:var(--text-muted);">Senior Audit Lead</div>
            </div>
            <span class="role-pill">Supervisor</span>
          </button>

          <button type="submit" name="quick_login" value="admin@jtyeocpa.ph" class="quick-btn">
            <div>
              <div><strong>Atty. Jonathan Yeo, CPA</strong></div>
              <div style="font-size:11px; color:var(--text-muted);">Managing Partner & HR</div>
            </div>
            <span class="role-pill">Partner / Admin</span>
          </button>
        </form>
      </div>
    </div>
  </div>

  <script>
    if (window.lucide) {
      lucide.createIcons();
    }
  </script>
</body>
</html>
