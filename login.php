<?php
// login.php - Professional Authentication Portal for JTYEO CPAs
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
        $password = 'password123';
    }

    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['department'] = $user['department'];
            $_SESSION['title'] = $user['title'];
            $_SESSION['avatar'] = $user['avatar_initials'];

            header('Location: index.php');
            exit;
        } else {
            $error = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login &bull; JTYeo CPA Leave System</title>
  <link rel="stylesheet" href="style.css">
  <script src="lucide.js"></script>
  <style>
    .login-body {
      background: #0a1728;
      background: radial-gradient(circle at 50% 25%, #112847 0%, #0a1728 65%, #050c17 100%);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 24px 16px;
      font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }

    .login-panel {
      width: 100%;
      max-width: 390px;
      background: #ffffff;
      border-radius: 14px;
      box-shadow: 0 20px 45px -10px rgba(0, 0, 0, 0.45);
      overflow: hidden;
    }

    .login-top {
      padding: 30px 24px 20px;
      text-align: center;
      background: #fbfcfd;
      border-bottom: 1px solid #edf2f7;
    }

    .login-logo-img {
      max-width: 135px;
      height: auto;
      display: block;
      margin: 0 auto;
    }

    .portal-tag {
      font-size: 11px;
      font-weight: 700;
      color: #64748b;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      margin-top: 10px;
    }

    .login-main {
      padding: 24px;
    }

    .alert-box {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 9px 12px;
      border-radius: 8px;
      font-size: 12px;
      font-weight: 600;
      margin-bottom: 18px;
    }

    .alert-error {
      background: #fee2e2;
      border: 1px solid #fecaca;
      color: #991b1b;
    }

    .alert-success {
      background: #d1fae5;
      border: 1px solid #a7f3d0;
      color: #065f46;
    }

    .field-group {
      margin-bottom: 16px;
    }

    .field-label {
      display: block;
      font-size: 12px;
      font-weight: 600;
      color: #334155;
      margin-bottom: 5px;
    }

    .field-input {
      width: 100%;
      box-sizing: border-box;
      padding: 10px 12px;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      font-size: 13.5px;
      color: #0f172a;
      background: #ffffff;
      transition: border-color 0.15s ease, box-shadow 0.15s ease;
      font-family: inherit;
    }

    .field-input:focus {
      outline: none;
      border-color: #0284c7;
      box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.12);
    }

    .btn-submit {
      width: 100%;
      padding: 11px 16px;
      background: #0f2744;
      color: #ffffff;
      border: none;
      border-radius: 8px;
      font-size: 13.5px;
      font-weight: 600;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: background-color 0.15s ease;
      margin-top: 4px;
    }

    .btn-submit:hover {
      background: #1e3e62;
    }

    .demo-divider {
      position: relative;
      text-align: center;
      margin: 20px 0 12px;
    }

    .demo-divider::before {
      content: '';
      position: absolute;
      top: 50%;
      left: 0;
      right: 0;
      height: 1px;
      background: #e2e8f0;
    }

    .demo-divider span {
      position: relative;
      background: #ffffff;
      padding: 0 8px;
      font-size: 10.5px;
      font-weight: 600;
      color: #94a3b8;
      text-transform: uppercase;
      letter-spacing: 0.6px;
    }

    .demo-list {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .demo-btn {
      display: flex;
      align-items: center;
      justify-content: space-between;
      width: 100%;
      padding: 8px 12px;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      cursor: pointer;
      transition: all 0.15s ease;
      text-align: left;
    }

    .demo-btn:hover {
      background: #f1f5f9;
      border-color: #cbd5e1;
    }

    .demo-name {
      font-size: 12.5px;
      font-weight: 600;
      color: #0f172a;
      line-height: 1.2;
    }

    .demo-role {
      font-size: 11px;
      color: #64748b;
    }

    .demo-badge {
      font-size: 10px;
      font-weight: 600;
      padding: 2px 7px;
      border-radius: 4px;
      background: #e2e8f0;
      color: #475569;
    }

    .demo-btn:hover .demo-badge {
      background: #0f2744;
      color: #ffffff;
    }

    .login-footer {
      margin-top: 18px;
      text-align: center;
      font-size: 11px;
      color: #64748b;
    }
  </style>
</head>
<body class="login-body">
  <div class="login-panel">
    <div class="login-top">
      <img src="JTYEO-Logo.png" alt="J.T. Yeo CPA Accounting Office" class="login-logo-img">
      <div class="portal-tag">Leave Management System</div>
    </div>

    <div class="login-main">
      <?php if (!empty($error)): ?>
        <div class="alert-box alert-error">
          <i data-lucide="alert-circle" style="width:15px;height:15px;flex-shrink:0;"></i>
          <span><?= htmlspecialchars($error) ?></span>
        </div>
      <?php endif; ?>

      <?php if (!empty($success)): ?>
        <div class="alert-box alert-success">
          <i data-lucide="check-circle" style="width:15px;height:15px;flex-shrink:0;"></i>
          <span><?= htmlspecialchars($success) ?></span>
        </div>
      <?php endif; ?>

      <form method="POST" action="login.php">
        <div class="field-group">
          <label class="field-label" for="email">Email</label>
          <input type="email" id="email" name="email" class="field-input" placeholder="name@jtyeocpa.ph" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>

        <div class="field-group">
          <label class="field-label" for="password">Password</label>
          <input type="password" id="password" name="password" class="field-input" placeholder="••••••••" required>
        </div>

        <button type="submit" class="btn-submit">
          <span>Sign In</span>
          <i data-lucide="arrow-right" style="width:15px;height:15px;"></i>
        </button>
      </form>

      <div class="demo-divider">
        <span>Demo Logins</span>
      </div>

      <form method="POST" action="login.php" class="demo-list">
        <button type="submit" name="quick_login" value="jessica@jtyeocpa.ph" class="demo-btn">
          <div>
            <div class="demo-name">Jessica Alcantara, CPA</div>
            <div class="demo-role">Senior Tax Associate</div>
          </div>
          <span class="demo-badge">Staff</span>
        </button>

        <button type="submit" name="quick_login" value="admin@jtyeocpa.ph" class="demo-btn">
          <div>
            <div class="demo-name">Atty. Jonathan Yeo, CPA</div>
            <div class="demo-role">Managing Partner &amp; HR</div>
          </div>
          <span class="demo-badge">Admin</span>
        </button>
      </form>
    </div>
  </div>

  <div class="login-footer">
    &copy; <?= date('Y') ?> J.T. Yeo CPA Accounting Office
  </div>

  <script>
    if (window.lucide) {
      lucide.createIcons();
    }
  </script>
</body>
</html>