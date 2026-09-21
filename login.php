<?php
// login.php - Professional 2-Sided Authentication Portal for JTYEO CPAs
require_once __DIR__ . '/config/db.php';

$error = '';
$success = '';

if (isset($_GET['msg']) && $_GET['msg'] === 'logged_out') {
  $success = 'You have been successfully logged out.';
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $password = $_POST['password'] ?? '';

  if (empty($email) || empty($password)) {
    $error = 'Please enter both work email and password.';
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
  <title>Portal Sign In &bull; J.T. Yeo CPA Leave Management System</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link
    href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
    rel="stylesheet">
  <script src="lucide.js"></script>
  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      min-height: 100vh;
      background: #f8fafc;
      color: #0f172a;
      overflow-x: hidden;
    }

    .split-layout {
      display: flex;
      min-height: 100vh;
      width: 100%;
      position: relative;
      background: #f8fafc;
    }

    /* -------------------------------------------------------------
       LEFT SIDE: Accounting Desk Hero with Slanted Cut
       ------------------------------------------------------------- */
    .hero-side {
      flex: 0 0 53%;
      position: relative;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      padding: 48px 60px 44px 56px;
      color: #ffffff;
      clip-path: polygon(0 0, 100% 0, 85% 100%, 0 100%);
      z-index: 2;
    }

    /* Background leave management photo */
    .hero-bg {
      position: absolute;
      inset: 0;
      background-image: url('leave-bg.jpg');
      background-size: cover;
      background-position: center 40%;
      filter: saturate(1.05);
      z-index: -3;
    }

    /* Deep Crimson & Obsidian moody overlay matching logo */
    .hero-overlay {
      position: absolute;
      inset: 0;
      background:
        radial-gradient(circle at 20% 30%, rgba(220, 0, 0, 0.35) 0%, transparent 65%),
        linear-gradient(140deg, rgba(28, 6, 9, 0.95) 0%, rgba(45, 8, 14, 0.92) 45%, rgba(15, 3, 5, 0.97) 100%);
      z-index: -2;
    }

    /* Bottom dynamic abstract wave */
    .hero-wave {
      position: absolute;
      bottom: -10px;
      left: 0;
      width: 100%;
      height: 220px;
      pointer-events: none;
      z-index: -1;
      opacity: 0.9;
    }

    /* Top Left Firm Brand Header */
    .firm-brand {
      display: flex;
      align-items: center;
      gap: 16px;
      position: relative;
      z-index: 3;
    }

    .firm-logo-badge {
      width: 72px;
      height: 72px;
      background: #ffffff;
      border-radius: 18px;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 6px;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.28);
      flex-shrink: 0;
    }

    .firm-logo-badge img {
      max-width: 100%;
      max-height: 100%;
      object-fit: contain;
    }

    .firm-titles {
      display: flex;
      flex-direction: column;
    }

    .firm-name {
      font-size: 20px;
      font-weight: 800;
      color: #ffffff;
      letter-spacing: 0.4px;
      line-height: 1.2;
    }

    .firm-tagline {
      font-size: 11px;
      font-weight: 700;
      color: #94a3b8;
      letter-spacing: 2px;
      text-transform: uppercase;
      margin-top: 3px;
    }

    /* Center Hero Copy */
    .hero-center {
      position: relative;
      z-index: 3;
      max-width: 500px;
      margin-top: 36px;
      margin-bottom: 36px;
    }

    .hero-lead-text {
      font-size: 24px;
      font-weight: 400;
      color: rgba(255, 255, 255, 0.9);
      letter-spacing: -0.2px;
      line-height: 1.3;
      margin-bottom: 6px;
    }

    .hero-title {
      font-size: 46px;
      font-weight: 800;
      color: #ffffff;
      letter-spacing: -1px;
      line-height: 1.15;
      margin-bottom: 18px;
    }

    .hero-desc {
      font-size: 15px;
      line-height: 1.65;
      color: rgba(226, 232, 240, 0.88);
      margin-bottom: 32px;
    }

    .hero-desc strong {
      color: #ffffff;
      font-weight: 700;
    }

    /* 3 Feature Pills Row */
    .feature-row {
      display: flex;
      align-items: center;
      gap: 22px;
      flex-wrap: wrap;
    }

    .feature-item {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .feature-icon-circle {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      background: rgba(220, 0, 0, 0.16);
      border: 1px solid rgba(239, 68, 68, 0.4);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fca5a5;
      flex-shrink: 0;
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
    }

    .feature-label {
      font-size: 12.5px;
      line-height: 1.25;
      color: #cbd5e1;
    }

    .feature-label strong {
      display: block;
      color: #ffffff;
      font-size: 13.5px;
      font-weight: 700;
    }

    /* Bottom Decorative Signature: "Excellence in Practice." */
    .hero-footer-signature {
      position: relative;
      z-index: 3;
      padding-top: 10px;
    }

    .signature-wrap {
      display: inline-block;
      position: relative;
    }

    .signature-text {
      font-family: 'Caveat', cursive, sans-serif;
      font-size: 36px;
      font-weight: 700;
      color: #ffffff;
      letter-spacing: 0.5px;
      line-height: 1;
      transform: rotate(-2deg);
      display: block;
    }

    .signature-accent-svg {
      display: block;
      margin-top: 2px;
      width: 130px;
      height: 12px;
      overflow: visible;
    }

    /* -------------------------------------------------------------
       RIGHT SIDE: Floating Card & Help Header
       ------------------------------------------------------------- */
    .auth-side {
      flex: 1;
      background: #f8fafc;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      align-items: center;
      padding: 36px 40px 28px;
      position: relative;
      z-index: 1;
    }



    /* Center Floating Authentication Card */
    .card-wrap {
      width: 100%;
      display: flex;
      justify-content: center;
      align-items: center;
      margin: auto 0;
      padding: 10px 0;
    }

    .login-card {
      width: 100%;
      max-width: 480px;
      background: #ffffff;
      border-radius: 26px;
      padding: 44px 44px 38px;
      box-shadow:
        0 20px 50px -12px rgba(15, 23, 42, 0.09),
        0 4px 16px -2px rgba(15, 23, 42, 0.04),
        0 0 0 1px rgba(226, 232, 240, 0.9);
      position: relative;
    }

    /* Centered Top Logo in Card */
    .card-logo-block {
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      margin-bottom: 20px;
    }

    .card-logo-img {
      width: 175px;
      height: auto;
      max-height: 145px;
      object-fit: contain;
      filter: drop-shadow(0 6px 14px rgba(0, 0, 0, 0.08));
      margin-bottom: 8px;
    }



    /* Sign In Header */
    .card-heading {
      margin-bottom: 22px;
    }

    .card-heading h2 {
      font-size: 28px;
      font-weight: 800;
      color: #111827;
      letter-spacing: -0.5px;
      line-height: 1.2;
      margin-bottom: 6px;
    }

    .card-heading p {
      font-size: 14px;
      color: #64748b;
    }

    /* Alerts */
    .alert-box {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 12px 16px;
      border-radius: 12px;
      font-size: 13px;
      font-weight: 600;
      margin-bottom: 20px;
    }

    .alert-error {
      background: #fef2f2;
      border: 1px solid #fee2e2;
      color: #b91c1c;
    }

    .alert-success {
      background: #f0fdf4;
      border: 1px solid #dcfce7;
      color: #15803d;
    }

    /* Form Inputs */
    .form-group {
      margin-bottom: 18px;
    }

    .form-label {
      display: flex;
      align-items: center;
      gap: 7px;
      font-size: 13px;
      font-weight: 700;
      color: #334155;
      margin-bottom: 8px;
    }

    .form-label i {
      width: 16px;
      height: 16px;
      color: #475569;
    }

    .input-wrapper {
      position: relative;
      display: flex;
      align-items: center;
    }

    .form-input {
      width: 100%;
      padding: 13px 16px;
      border: 1.5px solid #cbd5e1;
      border-radius: 12px;
      font-size: 14.5px;
      color: #0f172a;
      background: #ffffff;
      transition: all 0.15s ease;
      font-family: inherit;
    }

    .form-input:focus {
      outline: none;
      border-color: #dc0000;
      box-shadow: 0 0 0 4px rgba(220, 0, 0, 0.12);
    }

    .form-input::placeholder {
      color: #94a3b8;
    }

    .input-has-action .form-input {
      padding-right: 46px;
    }

    .input-action-btn {
      position: absolute;
      right: 14px;
      background: none;
      border: none;
      color: #94a3b8;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 4px;
      transition: color 0.15s ease;
    }

    .input-action-btn:hover {
      color: #475569;
    }



    /* Sign In Submit Button */
    .btn-submit {
      width: 100%;
      margin-top: 10px;
      padding: 14px 20px;
      background: linear-gradient(180deg, #dc0000 0%, #b91c1c 100%);
      color: #ffffff;
      border: none;
      border-radius: 12px;
      font-size: 15.5px;
      font-weight: 700;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      box-shadow: 0 4px 14px rgba(220, 0, 0, 0.25);
      transition: all 0.18s ease;
    }

    .btn-submit:hover {
      background: linear-gradient(180deg, #ef2323 0%, #c51212 100%);
      transform: translateY(-1px);
      box-shadow: 0 6px 18px rgba(220, 0, 0, 0.35);
    }

    .btn-submit:active {
      transform: translateY(0);
    }



    /* Footer Copyright */
    .auth-footer {
      font-size: 12px;
      color: #94a3b8;
      text-align: center;
      margin-top: 14px;
    }

    /* -------------------------------------------------------------
       COMPREHENSIVE RESPONSIVE ADAPTATION FOR ALL DEVICES
       ------------------------------------------------------------- */
    /* Large Desktops & High-Res Monitors (>= 1440px) */
    @media (min-width: 1440px) {
      .hero-side {
        padding: 56px 72px 48px 64px;
      }
      .hero-title {
        font-size: 50px;
      }
      .login-card {
        max-width: 490px;
        padding: 46px 46px 40px;
      }
    }

    /* Short Height Desktop & 1366x768 Laptops (min-width: 961px and max-height: 820px) */
    @media (min-width: 961px) and (max-height: 820px) {
      .hero-side {
        padding: 32px 44px 28px 40px;
      }
      .hero-center {
        margin-top: 18px;
        margin-bottom: 18px;
      }
      .hero-title {
        font-size: 38px;
        margin-bottom: 10px;
      }
      .hero-desc {
        margin-bottom: 20px;
        font-size: 14px;
      }
      .auth-side {
        padding: 24px 32px 20px;
      }
      .login-card {
        padding: 30px 36px 26px;
      }
      .card-logo-img {
        max-height: 120px;
        width: 155px;
        margin-bottom: 4px;
      }
      .card-heading {
        margin-bottom: 16px;
      }
      .card-heading h2 {
        font-size: 24px;
      }
      .form-group {
        margin-bottom: 14px;
      }

      .hero-wave {
        height: 170px;
      }
    }

    /* Small Laptops & Landscape Tablets (961px - 1200px) */
    @media (max-width: 1200px) {
      .hero-side {
        flex: 0 0 51%;
        padding: 38px 36px 32px;
        clip-path: polygon(0 0, 100% 0, 89% 100%, 0 100%);
      }
      .hero-title {
        font-size: 38px;
      }
      .hero-desc {
        font-size: 14px;
        margin-bottom: 24px;
      }
      .feature-row {
        gap: 12px;
        flex-wrap: nowrap;
      }
      .feature-item {
        gap: 8px;
      }
      .feature-icon-circle {
        width: 38px;
        height: 38px;
      }
      .feature-label {
        font-size: 11.5px;
      }
      .feature-label strong {
        font-size: 12px;
      }
      .auth-side {
        padding: 28px 24px 22px;
      }
      .login-card {
        max-width: 440px;
        padding: 34px 30px 28px;
      }
      .card-logo-img {
        width: 155px;
        max-height: 125px;
      }
    }

    /* Tablets (Portrait: 768px - 960px) */
    @media (max-width: 960px) {
      .split-layout {
        flex-direction: column;
        min-height: 100vh;
      }

      .hero-side {
        flex: auto;
        clip-path: none;
        padding: 40px 32px 36px;
        min-height: auto;
      }

      .hero-wave {
        height: 140px;
      }

      .hero-center {
        margin: 24px 0 24px;
        max-width: 100%;
      }

      .hero-lead-text {
        font-size: 20px;
      }

      .hero-title {
        font-size: 36px;
      }

      .hero-desc {
        font-size: 14.5px;
        max-width: 650px;
        margin-bottom: 22px;
      }

      .feature-row {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 14px;
        max-width: 680px;
      }

      .feature-item {
        background: rgba(255, 255, 255, 0.07);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.14);
        border-radius: 14px;
        padding: 12px 14px;
        gap: 10px;
      }

      .hero-footer-signature {
        padding-top: 14px;
      }

      .auth-side {
        padding: 36px 24px 32px;
        width: 100%;
        min-height: auto;
        flex: auto;
      }

      .login-card {
        max-width: 480px;
        box-shadow: 0 10px 32px -5px rgba(15, 23, 42, 0.08);
        border: 1px solid #e2e8f0;
        padding: 38px 36px 32px;
      }
    }

    /* Mobile Phones (<= 768px): Display ONLY the right authentication panel */
    @media (max-width: 768px) {
      .hero-side {
        display: none !important;
      }

      .split-layout {
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        background: #f8fafc;
      }

      .auth-side {
        width: 100%;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        align-items: center;
        padding: 36px 20px 24px;
        background: #f8fafc;
      }

      .card-wrap {
        width: 100%;
        margin: auto 0;
        display: flex;
        justify-content: center;
        padding: 10px 0;
      }

      .login-card {
        width: 100%;
        max-width: 440px;
        padding: 38px 28px 32px;
        border-radius: 24px;
        box-shadow:
          0 20px 50px -12px rgba(15, 23, 42, 0.09),
          0 4px 16px -2px rgba(15, 23, 42, 0.04),
          0 0 0 1px rgba(226, 232, 240, 0.9);
      }

      .card-logo-img {
        width: 170px;
        max-height: 140px;
        margin-bottom: 10px;
      }

      .card-heading {
        margin-bottom: 22px;
      }

      .card-heading h2 {
        font-size: 26px;
      }

      .card-heading p {
        font-size: 13.5px;
      }

      /* Prevent iOS auto-zoom on input focus */
      .form-input {
        font-size: 16px !important;
        padding: 13px 16px;
      }

      .btn-submit {
        padding: 14px 20px;
        font-size: 15.5px;
        min-height: 48px;
      }

      .auth-footer {
        font-size: 12px;
        margin-top: 16px;
      }
    }

    /* Small Mobile Phones (<= 480px) */
    @media (max-width: 480px) {
      .auth-side {
        padding: 24px 14px 20px;
      }

      .login-card {
        padding: 30px 18px 24px;
        border-radius: 20px;
        max-width: 100%;
      }

      .card-logo-img {
        width: 150px;
        max-height: 125px;
        margin-bottom: 8px;
      }

      .card-heading h2 {
        font-size: 24px;
      }

      .card-heading p {
        font-size: 13px;
      }

      .form-group {
        margin-bottom: 16px;
      }

      .btn-submit {
        padding: 13px 16px;
        font-size: 15px;
        min-height: 46px;
      }

      .auth-footer {
        font-size: 11px;
        line-height: 1.4;
      }
    }

    /* Extra Small Displays (<= 340px) */
    @media (max-width: 340px) {
      .login-card {
        padding: 24px 14px 20px;
      }

      .card-logo-img {
        width: 130px;
      }
    }
  </style>
</head>

<body>
  <div class="split-layout">
    <!-- =========================================================
         LEFT PANEL: Professional Leave & Attendance System Showcase
         ========================================================= -->
    <div class="hero-side">
      <div class="hero-bg"></div>
      <div class="hero-overlay"></div>

      <!-- Abstract Bottom Curves matching reference mockup -->
      <svg class="hero-wave" viewBox="0 0 1200 240" preserveAspectRatio="none">
        <path d="M0,160 C240,220 480,140 760,190 C960,225 1100,200 1200,165 L1200,240 L0,240 Z"
          fill="rgba(220, 0, 0, 0.28)" />
        <path d="M0,195 C180,150 360,230 620,180 C840,140 1020,200 1200,175 L1200,240 L0,240 Z"
          fill="rgba(185, 28, 28, 0.75)" />
        <path d="M0,210 C260,175 520,250 820,195 C980,165 1120,210 1200,195 L1200,240 L0,240 Z"
          fill="rgba(15, 3, 5, 0.95)" />
      </svg>

      <!-- Top Left Brand Badge -->
      <div class="firm-brand">
        <div class="firm-logo-badge">
          <img src="JTYEO-Logo1.png" alt="J.T. Yeo CPA Logo">
        </div>
        <div class="firm-titles">
          <div class="firm-name">J.T. YEO CPA</div>
          <div class="firm-tagline">ACCOUNTING OFFICE</div>
        </div>
      </div>

      <!-- Hero Value Proposition -->
      <div class="hero-center">
        <div class="hero-lead-text">Practice Workforce &amp; Absence Portal</div>
        <h1 class="hero-title">Leave Management</h1>
        <p class="hero-desc">
          <strong>Centralized leave filing, real-time balance tracking,</strong> and partner approvals engineered for
          J.T. Yeo CPA associates and administration.
        </p>

        <!-- 3 Feature Badges tailored to the System -->
        <div class="feature-row">
          <div class="feature-item">
            <div class="feature-icon-circle">
              <i data-lucide="calendar-check" style="width:20px;height:20px;"></i>
            </div>
            <div class="feature-label">
              <strong>Real-Time</strong>
              Balance Tracking
            </div>
          </div>

          <div class="feature-item">
            <div class="feature-icon-circle">
              <i data-lucide="clock" style="width:20px;height:20px;"></i>
            </div>
            <div class="feature-label">
              <strong>Streamlined</strong>
              Leave Filing
            </div>
          </div>

          <div class="feature-item">
            <div class="feature-icon-circle">
              <i data-lucide="shield-check" style="width:20px;height:20px;"></i>
            </div>
            <div class="feature-label">
              <strong>Partner &amp; HR</strong>
              Approval Queue
            </div>
          </div>
        </div>
      </div>

      <!-- Bottom Signature: "Excellence in Practice." -->
      <div class="hero-footer-signature">
        <div class="signature-wrap">
          <span class="signature-text">Excellence in Practice.</span>
          <svg class="signature-accent-svg" viewBox="0 0 130 14" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M4 8C38 1.5 90 1.5 126 9C108 12 60 12.5 12 11" stroke="#c8102e" stroke-width="3"
              stroke-linecap="round" />
          </svg>
        </div>
      </div>
    </div>

    <!-- =========================================================
         RIGHT PANEL: Floating Sign In Card
         ========================================================= -->
    <div class="auth-side">
      <!-- Floating Sign In Card -->
      <div class="card-wrap">
        <div class="login-card">
          <!-- Card Brand Seal -->
          <div class="card-logo-block">
            <img src="JTYEO-Logo1.png" alt="J.T. Yeo CPA Accounting Office" class="card-logo-img">
          </div>

          <!-- Heading -->
          <div class="card-heading">
            <h2>Sign In</h2>
            <p>Enter your credentials to access your leave account</p>
          </div>

          <!-- Error / Success Notices -->
          <?php if (!empty($error)): ?>
            <div class="alert-box alert-error">
              <i data-lucide="alert-circle" style="width:18px;height:18px;flex-shrink:0;"></i>
              <span><?= htmlspecialchars($error) ?></span>
            </div>
          <?php endif; ?>

          <?php if (!empty($success)): ?>
            <div class="alert-box alert-success">
              <i data-lucide="check-circle" style="width:18px;height:18px;flex-shrink:0;"></i>
              <span><?= htmlspecialchars($success) ?></span>
            </div>
          <?php endif; ?>

          <!-- Login Form -->
          <form method="POST" action="login.php" id="loginForm">
            <div class="form-group">
              <label class="form-label" for="email">
                <i data-lucide="mail"></i>
                <span>Work Email</span>
              </label>
              <div class="input-wrapper">
                <input type="email" id="email" name="email" class="form-input" placeholder="name@jtyeocpa.ph" required
                  value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" autocomplete="email">
              </div>
            </div>

            <div class="form-group">
              <label class="form-label" for="password">
                <i data-lucide="lock"></i>
                <span>Password</span>
              </label>
              <div class="input-wrapper input-has-action">
                <input type="password" id="password" name="password" class="form-input"
                  placeholder="Enter your password" required autocomplete="current-password">
                <button type="button" class="input-action-btn" id="togglePasswordBtn"
                  title="Toggle password visibility">
                  <i data-lucide="eye" id="togglePasswordIcon" style="width:18px;height:18px;"></i>
                </button>
              </div>
            </div>



            <!-- Action Button -->
            <button type="submit" class="btn-submit">
              <span>Sign In</span>
              <i data-lucide="arrow-right" style="width:18px;height:18px;"></i>
            </button>
          </form>
        </div>
      </div>

      <!-- Bottom Copyright -->
      <div class="auth-footer">
        &copy; <?= date('Y') ?> J. T. Yeo CPA Accounting Office. All rights reserved.
      </div>
    </div>
  </div>



  <script>
    // Initialize icons
    if (window.lucide) {
      lucide.createIcons();
    }

    // Password visibility toggle
    const toggleBtn = document.getElementById('togglePasswordBtn');
    const passwordInput = document.getElementById('password');

    if (toggleBtn && passwordInput) {
      toggleBtn.addEventListener('click', function () {
        const isPassword = passwordInput.getAttribute('type') === 'password';
        passwordInput.setAttribute('type', isPassword ? 'text' : 'password');

        // Re-render icon
        const iconName = isPassword ? 'eye-off' : 'eye';
        toggleBtn.innerHTML = `<i data-lucide="${iconName}" style="width:18px;height:18px;"></i>`;
        if (window.lucide) {
          lucide.createIcons();
        }
      });
    }

  </script>
</body>

</html>