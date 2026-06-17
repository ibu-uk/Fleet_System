<?php
// ============================================================
//  login.php — Fleet Management Login
// ============================================================
session_start();
require_once __DIR__.'/config.php';
require_once __DIR__.'/functions.php';

// Already logged in → go to dashboard
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php'); exit;
}

$lang  = $_SESSION['lang'] ?? 'ar';
$LANG  = require __DIR__."/lang/{$lang}.php";
$isRTL = $lang === 'ar';
$error = '';

// ---- Handle language toggle on login page ----
if (isset($_GET['lang']) && in_array($_GET['lang'],['en','ar'])) {
    $_SESSION['lang'] = $_GET['lang'];
    header('Location: login.php'); exit;
}

// ---- Handle POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$password) {
        $error = $lang === 'ar' ? 'يرجى إدخال اسم المستخدم وكلمة المرور.' : 'Please enter username and password.';
    } else {
        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username=? AND status='active' LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // Login success
            $_SESSION['user_id']      = $user['id'];
            $_SESSION['user_name']    = $user['full_name_en'];
            $_SESSION['user_name_ar'] = $user['full_name_ar'];
            $_SESSION['user_role']    = $user['role'];
            $_SESSION['username']     = $user['username'];
            $_SESSION['lang']         = $_SESSION['lang'] ?? 'ar';
            // Update last login and last activity
            $pdo->prepare("UPDATE users SET last_login=NOW(), last_activity=NOW() WHERE id=?")->execute([$user['id']]);
            // Redirect based on role
            if ($user['role'] === 'driver') {
                header('Location: driver-portal.php'); exit;
            }
            header('Location: index.php'); exit;
        } else {
            $error = $lang === 'ar'
                ? 'اسم المستخدم أو كلمة المرور غلط.'
                : 'Invalid username or password.';
            // Small delay to prevent brute force
            sleep(1);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $isRTL ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $isRTL ? 'نظام إدارة الأسطول — تسجيل الدخول' : 'Fleet Management — Login' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Oxanium:wght@700;800&family=Cairo:wght@400;600;700&family=Syne:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --ink:    #0c0f13;
  --ink2:   #161b23;
  --amber:  #f59e0b;
  --amber2: #d97706;
  --red:    #dc2626;
  --border: rgba(255,255,255,.08);
  --text:   #e2e8f0;
  --dim:    #64748b;
}

body {
  min-height: 100vh;
  background: var(--ink);
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: <?= $isRTL ? "'Cairo'" : "'Syne'" ?>, sans-serif;
  padding: 20px;
  position: relative;
  overflow: hidden;
}

/* Background pattern */
body::before {
  content: '';
  position: fixed;
  inset: 0;
  background-image:
    repeating-linear-gradient(0deg, transparent, transparent 60px, rgba(255,255,255,.015) 60px, rgba(255,255,255,.015) 61px),
    repeating-linear-gradient(90deg, transparent, transparent 60px, rgba(255,255,255,.015) 60px, rgba(255,255,255,.015) 61px);
  pointer-events: none;
}
body::after {
  content: '';
  position: fixed;
  top: -30%;
  <?= $isRTL ? 'left' : 'right' ?>: -10%;
  width: 600px;
  height: 600px;
  background: radial-gradient(circle, rgba(245,158,11,.07) 0%, transparent 65%);
  pointer-events: none;
}

/* Card */
.login-card {
  background: #12171f;
  border: 1px solid var(--border);
  border-radius: 16px;
  width: 100%;
  max-width: 420px;
  padding: 44px 40px 36px;
  box-shadow: 0 24px 64px rgba(0,0,0,.5);
  position: relative;
  animation: card-in .4s cubic-bezier(.4,0,.2,1);
}
@keyframes card-in {
  from { opacity: 0; transform: translateY(20px) scale(.97); }
  to   { opacity: 1; transform: translateY(0) scale(1); }
}

/* Amber top accent line */
.login-card::before {
  content: '';
  position: absolute;
  top: 0; left: 24px; right: 24px;
  height: 3px;
  background: linear-gradient(90deg, transparent, var(--amber), transparent);
  border-radius: 0 0 4px 4px;
}

/* Logo area */
.login-brand {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 32px;
}
.login-brand-icon {
  width: 52px;
  height: 52px;
  background: var(--amber);
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  color: var(--ink);
  box-shadow: 0 0 24px rgba(245,158,11,.25);
  flex-shrink: 0;
}
.login-brand-text {
  font-family: 'Oxanium', sans-serif;
  font-weight: 800;
  font-size: 18px;
  color: #fff;
  line-height: 1.2;
}
.login-brand-sub {
  font-size: 12px;
  color: var(--dim);
  margin-top: 2px;
}

/* Heading */
.login-heading {
  font-family: 'Oxanium', sans-serif;
  font-size: 22px;
  font-weight: 800;
  color: #fff;
  margin-bottom: 6px;
}
.login-subheading {
  font-size: 13px;
  color: var(--dim);
  margin-bottom: 28px;
}

/* Error */
.login-error {
  background: rgba(220,38,38,.12);
  border: 1px solid rgba(220,38,38,.4);
  border-radius: 8px;
  padding: 11px 14px;
  color: #fca5a5;
  font-size: 13px;
  margin-bottom: 20px;
  display: flex;
  align-items: center;
  gap: 8px;
  animation: shake .3s cubic-bezier(.36,.07,.19,.97);
}
@keyframes shake {
  0%,100%{transform:translateX(0)}
  20%{transform:translateX(-6px)}
  40%{transform:translateX(6px)}
  60%{transform:translateX(-4px)}
  80%{transform:translateX(4px)}
}

/* Form fields */
.form-group {
  margin-bottom: 18px;
}
.form-group label {
  display: block;
  font-size: 12px;
  font-weight: 700;
  color: var(--dim);
  letter-spacing: .5px;
  text-transform: uppercase;
  margin-bottom: 7px;
}
.input-wrap {
  position: relative;
}
.input-wrap i {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  <?= $isRTL ? 'right' : 'left' ?>: 14px;
  color: var(--dim);
  font-size: 14px;
  pointer-events: none;
  transition: color .15s;
}
.input-wrap input {
  width: 100%;
  background: rgba(255,255,255,.05);
  border: 1px solid rgba(255,255,255,.1);
  border-radius: 9px;
  padding: 12px 14px 12px <?= $isRTL ? '14px' : '42px' ?>;
  <?= $isRTL ? 'padding-right: 42px;' : '' ?>
  color: #fff;
  font-size: 14px;
  font-family: <?= $isRTL ? "'Cairo'" : "'Syne'" ?>, sans-serif;
  outline: none;
  transition: border-color .15s, box-shadow .15s, background .15s;
}
.input-wrap input::placeholder { color: #475569; }
.input-wrap input:focus {
  border-color: var(--amber);
  background: rgba(245,158,11,.05);
  box-shadow: 0 0 0 3px rgba(245,158,11,.12);
}
.input-wrap input:focus + i,
.input-wrap:focus-within i { color: var(--amber); }

/* Password toggle */
.pwd-toggle {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  <?= $isRTL ? 'left' : 'right' ?>: 14px;
  background: none;
  border: none;
  color: var(--dim);
  cursor: pointer;
  font-size: 14px;
  padding: 4px;
  transition: color .15s;
}
.pwd-toggle:hover { color: var(--amber); }

/* Submit button */
.btn-login {
  width: 100%;
  padding: 14px;
  background: var(--amber);
  color: var(--ink);
  border: none;
  border-radius: 9px;
  font-family: 'Oxanium', sans-serif;
  font-size: 15px;
  font-weight: 800;
  cursor: pointer;
  letter-spacing: .5px;
  transition: background .15s, transform .1s, box-shadow .15s;
  margin-top: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  text-transform: uppercase;
}
.btn-login:hover {
  background: var(--amber2);
  transform: translateY(-1px);
  box-shadow: 0 6px 20px rgba(245,158,11,.3);
}
.btn-login:active { transform: translateY(0); }

/* Lang switcher */
.lang-row {
  display: flex;
  justify-content: center;
  gap: 8px;
  margin-top: 24px;
  padding-top: 20px;
  border-top: 1px solid var(--border);
}
.lang-link {
  font-size: 12px;
  font-weight: 700;
  color: var(--dim);
  text-decoration: none;
  padding: 5px 14px;
  border-radius: 20px;
  transition: all .15s;
  border: 1px solid transparent;
}
.lang-link.active {
  color: var(--amber);
  border-color: rgba(245,158,11,.3);
  background: rgba(245,158,11,.08);
}
.lang-link:hover:not(.active) { color: #fff; }

/* Footer note */
.login-footer {
  text-align: center;
  margin-top: 18px;
  font-size: 11px;
  color: #2d3748;
  font-family: monospace;
}
</style>
</head>
<body>

<div class="login-card">

  <!-- Brand -->
  <div class="login-brand">
    <div class="login-brand-icon"><i class="fas fa-truck"></i></div>
    <div>
      <div class="login-brand-text"><?= $isRTL ? 'نظام إدارة الأسطول' : 'Fleet Management' ?></div>
      <div class="login-brand-sub"><?= $isRTL ? 'أسطول التوصيل — الكويت 🇰🇼' : 'Kuwait Delivery Fleet 🇰🇼' ?></div>
    </div>
  </div>

  <!-- Heading -->
  <div class="login-heading"><?= $isRTL ? 'تسجيل الدخول' : 'Sign In' ?></div>
  <div class="login-subheading"><?= $isRTL ? 'أدخل بيانات حسابك للمتابعة' : 'Enter your credentials to continue' ?></div>

  <!-- Error -->
  <?php if ($error): ?>
  <div class="login-error">
    <i class="fas fa-exclamation-circle"></i>
    <?= e($error) ?>
  </div>
  <?php endif; ?>

  <!-- Form -->
  <form method="post" action="login.php" autocomplete="off">

    <div class="form-group">
      <label><?= $isRTL ? 'اسم المستخدم' : 'Username' ?></label>
      <div class="input-wrap">
        <input type="text" name="username" placeholder="<?= $isRTL ? 'أدخل اسم المستخدم' : 'Enter username' ?>"
               value="<?= e($_POST['username'] ?? '') ?>" autofocus autocomplete="username" required>
        <i class="fas fa-user"></i>
      </div>
    </div>

    <div class="form-group">
      <label><?= $isRTL ? 'كلمة المرور' : 'Password' ?></label>
      <div class="input-wrap">
        <input type="password" name="password" id="pwdInput"
               placeholder="<?= $isRTL ? 'أدخل كلمة المرور' : 'Enter password' ?>"
               autocomplete="current-password" required>
        <i class="fas fa-lock"></i>
        <button type="button" class="pwd-toggle" onclick="togglePwd()" id="pwdToggle" title="Show/Hide">
          <i class="fas fa-eye" id="pwdIcon"></i>
        </button>
      </div>
    </div>

    <button type="submit" class="btn-login">
      <i class="fas fa-sign-in-alt"></i>
      <?= $isRTL ? 'دخول' : 'Login' ?>
    </button>

  </form>

  <!-- Language -->
  <div class="lang-row">
    <a href="login.php?lang=ar" class="lang-link <?= $lang==='ar'?'active':'' ?>">🇰🇼 عربي</a>
    <a href="login.php?lang=en" class="lang-link <?= $lang==='en'?'active':'' ?>">🇬🇧 English</a>
  </div>

</div>

<div class="login-footer">FLEET MGMT · v2.0 · Kuwait</div>

<script>
function togglePwd(){
  const inp = document.getElementById('pwdInput');
  const ico = document.getElementById('pwdIcon');
  if(inp.type === 'password'){
    inp.type = 'text';
    ico.className = 'fas fa-eye-slash';
  } else {
    inp.type = 'password';
    ico.className = 'fas fa-eye';
  }
}
</script>
</body>
</html>
