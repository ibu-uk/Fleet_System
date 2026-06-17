<?php
// ============================================================
//  setup_password.php
//  Run this ONCE in your browser after importing setup_users.sql
//  e.g. http://localhost/fleet/setup_password.php
//  DELETE this file after running it!
// ============================================================

require_once __DIR__.'/config.php';

$defaultPassword = 'fleet@2024';
$hash = password_hash($defaultPassword, PASSWORD_BCRYPT);

$pdo = getDB();
$pdo->prepare("UPDATE users SET password_hash=? WHERE username='admin'")->execute([$hash]);

echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>Fleet — Password Setup</title>
<style>
  body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f4f6f9;}
  .box{background:white;border-radius:12px;padding:40px;max-width:480px;width:100%;box-shadow:0 4px 24px rgba(0,0,0,.1);text-align:center;}
  .icon{font-size:48px;margin-bottom:16px;}
  h2{color:#0c0f13;margin:0 0 8px;}
  p{color:#666;margin:8px 0;}
  .cred{background:#f4f6f9;border-radius:8px;padding:16px;margin:20px 0;font-family:monospace;font-size:15px;}
  .cred span{color:#f59e0b;font-weight:bold;}
  .warn{background:#fef3cd;border:1px solid #f59e0b;border-radius:8px;padding:12px;margin-top:16px;font-size:13px;color:#856404;}
  a{display:inline-block;margin-top:20px;background:#f59e0b;color:#0c0f13;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:bold;}
</style></head><body>
<div class="box">
  <div class="icon">✅</div>
  <h2>Admin Password Set!</h2>
  <p>Your default admin account is ready:</p>
  <div class="cred">
    Username: <span>admin</span><br>
    Password: <span>'.$defaultPassword.'</span>
  </div>
  <div class="warn">
    ⚠️ <strong>Delete this file immediately!</strong><br>
    <code>setup_password.php</code> — anyone who opens it will reset the password.
  </div>
  <a href="index.php">Go to Login →</a>
</div>
</body></html>';
