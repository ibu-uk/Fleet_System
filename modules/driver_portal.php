<?php
// modules/driver_portal.php — Driver Mobile Dashboard
// Only accessible to driver role users

$pdo  = getDB();
$lang = $_SESSION['lang'] ?? 'ar';
$LANG = require __DIR__."/../lang/{$lang}.php";
$isRTL = $lang === 'ar';

// Get current driver's employee record
$userId = (int)$_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT e.* FROM employees e JOIN users u ON u.employee_id=e.id WHERE u.id=?");
$stmt->execute([$userId]);
$driver = $stmt->fetch();

if (!$driver) {
    // User not linked to employee
    echo '<div class="alert alert-danger m-3">Driver profile not found. Please contact admin.</div>';
    exit;
}

// Today's orders
$today = date('Y-m-d');
$todayOrders = $pdo->prepare("
    SELECT platform, SUM(order_count) AS count
    FROM driver_orders
    WHERE driver_id=? AND order_date=?
    GROUP BY platform
");
$todayOrders->execute([$driver['id'], $today]);
$todayData = [];
foreach ($todayOrders as $r) $todayData[$r['platform']] = $r['count'];
$todayTotal = ($todayData['talabat'] ?? 0) + ($todayData['keeta'] ?? 0) + ($todayData['other'] ?? 0);

// This month's orders
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$monthOrders = $pdo->prepare("
    SELECT platform, SUM(order_count) AS count
    FROM driver_orders
    WHERE driver_id=? AND order_date BETWEEN ? AND ?
    GROUP BY platform
");
$monthOrders->execute([$driver['id'], $monthStart, $monthEnd]);
$monthData = [];
foreach ($monthOrders as $r) $monthData[$r['platform']] = $r['count'];
$monthTotal = ($monthData['talabat'] ?? 0) + ($monthData['keeta'] ?? 0) + ($monthData['other'] ?? 0);

// Bonus calculation
$target = driverMonthlyTarget($driver['monthly_order_target']);
$eligible = (int)$driver['bonus_eligible'] === 1;
$bonus = calculateBonus($monthTotal, $target, $eligible);
$bonusEnabled = bonusEnabled();
$bonusAmount = (float)getSetting('bonus_amount', 0);
$pct = $target > 0 ? min(100, round($monthTotal / $target * 100)) : 0;

// Penalties
$penalties = $pdo->prepare("
    SELECT p.*, DATE_FORMAT(p.penalty_date, '%Y-%m-%d') AS formatted_date
    FROM penalties p
    WHERE p.driver_id=? AND p.status='pending'
    ORDER BY p.penalty_date DESC
    LIMIT 5
");
$penalties->execute([$driver['id']]);
$penaltyList = $penalties->fetchAll();

// Service reminders for assigned vehicle
$vehicleReminders = [];
if ($driver['vehicle_id']) {
    $vRems = $pdo->prepare("
        SELECT r.*, v.plate_number
        FROM reminders r
        JOIN vehicles v ON v.id=r.vehicle_id
        WHERE r.vehicle_id=? AND r.status IN ('pending','snoozed')
          AND (r.snoozed_until IS NULL OR r.snoozed_until <= CURDATE())
        ORDER BY FIELD(r.priority,'critical','high','medium','low'), r.remind_date ASC
        LIMIT 5
    ");
    $vRems->execute([$driver['vehicle_id']]);
    $vehicleReminders = $vRems->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $isRTL ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="theme-color" content="#0c0f13">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title><?= $isRTL ? 'بوابة السائقين' : 'Driver Portal' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Oxanium:wght@700;800&family=Cairo:wght@400;600;700&family=Syne:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css">
<link rel="manifest" href="manifest.json">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }

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
  background: var(--ink);
  font-family: <?= $isRTL ? "'Cairo'" : "'Syne'" ?>, sans-serif;
  color: var(--text);
  min-height: 100vh;
  padding-bottom: 80px;
}

body::before {
  content: '';
  position: fixed;
  inset: 0;
  background-image:
    repeating-linear-gradient(0deg, transparent, transparent 60px, rgba(255,255,255,.015) 60px, rgba(255,255,255,.015) 61px),
    repeating-linear-gradient(90deg, transparent, transparent 60px, rgba(255,255,255,.015) 60px, rgba(255,255,255,.015) 61px);
  pointer-events: none;
}

/* Header */
.dp-header {
  background: #12171f;
  border-bottom: 1px solid var(--border);
  padding: 16px 20px;
  position: sticky;
  top: 0;
  z-index: 100;
}
.dp-header-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}
.dp-brand {
  display: flex;
  align-items: center;
  gap: 10px;
}
.dp-brand-icon {
  width: 40px;
  height: 40px;
  background: var(--amber);
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  color: var(--ink);
}
.dp-brand-text {
  font-family: 'Oxanium', sans-serif;
  font-weight: 800;
  font-size: 14px;
  color: #fff;
}
.dp-user {
  text-align: <?= $isRTL ? 'left' : 'right' ?>;
}
.dp-user-name {
  font-weight: 700;
  font-size: 13px;
  color: #fff;
}
.dp-user-role {
  font-size: 11px;
  color: var(--dim);
}

/* Stats Cards */
.dp-stats {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px;
  padding: 16px 20px;
}
.dp-stat-card {
  background: #12171f;
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 16px;
  text-align: center;
}
.dp-stat-icon {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 10px;
  font-size: 18px;
}
.dp-stat-icon.amber { background: rgba(245,158,11,.15); color: var(--amber); }
.dp-stat-icon.green { background: rgba(34,197,94,.15); color: #22c55e; }
.dp-stat-icon.red { background: rgba(220,38,38,.15); color: var(--red); }
.dp-stat-icon.blue { background: rgba(59,130,246,.15); color: #3b82f6; }
.dp-stat-value {
  font-family: 'Oxanium', sans-serif;
  font-weight: 800;
  font-size: 24px;
  color: #fff;
  line-height: 1;
}
.dp-stat-label {
  font-size: 11px;
  color: var(--dim);
  margin-top: 4px;
  text-transform: uppercase;
  letter-spacing: .5px;
}

/* Section Cards */
.dp-section {
  background: #12171f;
  border: 1px solid var(--border);
  border-radius: 12px;
  margin: 0 20px 16px;
  overflow: hidden;
}
.dp-section-header {
  padding: 14px 16px;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  gap: 10px;
}
.dp-section-header i {
  color: var(--amber);
  font-size: 16px;
}
.dp-section-title {
  font-family: 'Oxanium', sans-serif;
  font-weight: 700;
  font-size: 14px;
  color: #fff;
}
.dp-section-body {
  padding: 0;
}

/* Platform Bars */
.platform-bar {
  display: flex;
  align-items: center;
  padding: 12px 16px;
  border-bottom: 1px solid var(--border);
}
.platform-bar:last-child { border-bottom: none; }
.platform-icon {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  color: #fff;
  flex-shrink: 0;
}
.platform-icon.talabat { background: #ff5a00; }
.platform-icon.keeta { background: #00b96b; }
.platform-icon.other { background: #64748b; }
.platform-info {
  flex: 1;
  margin: 0 12px;
}
.platform-name {
  font-weight: 600;
  font-size: 13px;
  color: #fff;
}
.platform-count {
  font-family: 'Oxanium', sans-serif;
  font-weight: 800;
  font-size: 18px;
  color: var(--amber);
}

/* Progress Bar */
.progress-wrapper {
  padding: 16px;
}
.progress-label {
  display: flex;
  justify-content: space-between;
  font-size: 12px;
  color: var(--dim);
  margin-bottom: 8px;
}
.progress-label span:last-child {
  font-family: 'Oxanium', sans-serif;
  font-weight: 700;
  color: #fff;
}
.progress-track {
  height: 8px;
  background: rgba(255,255,255,.1);
  border-radius: 4px;
  overflow: hidden;
}
.progress-fill {
  height: 100%;
  background: linear-gradient(90deg, var(--amber), var(--amber2));
  border-radius: 4px;
  transition: width .3s ease;
}

/* Bonus Card */
.bonus-card {
  padding: 16px;
  text-align: center;
}
.bonus-amount {
  font-family: 'Oxanium', sans-serif;
  font-weight: 800;
  font-size: 32px;
  color: var(--amber);
  margin: 8px 0;
}
.bonus-status {
  font-size: 12px;
  color: var(--dim);
}
.bonus-badge {
  display: inline-block;
  padding: 4px 12px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 700;
  margin-top: 8px;
}
.bonus-badge.eligible { background: rgba(34,197,94,.15); color: #22c55e; }
.bonus-badge.not-eligible { background: rgba(107,114,128,.15); color: var(--dim); }
.bonus-badge.target-met { background: rgba(245,158,11,.15); color: var(--amber); }

/* List Items */
.dp-list-item {
  display: flex;
  align-items: center;
  padding: 12px 16px;
  border-bottom: 1px solid var(--border);
}
.dp-list-item:last-child { border-bottom: none; }
.dp-list-icon {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  color: #fff;
  flex-shrink: 0;
}
.dp-list-icon.penalty { background: rgba(220,38,38,.15); color: var(--red); }
.dp-list-icon.reminder { background: rgba(245,158,11,.15); color: var(--amber); }
.dp-list-icon.critical { background: rgba(220,38,38,.15); color: var(--red); }
.dp-list-icon.high { background: rgba(245,158,11,.15); color: var(--amber); }
.dp-list-content {
  flex: 1;
  margin: 0 12px;
}
.dp-list-title {
  font-weight: 600;
  font-size: 13px;
  color: #fff;
}
.dp-list-sub {
  font-size: 11px;
  color: var(--dim);
  margin-top: 2px;
}
.dp-list-badge {
  font-size: 10px;
  padding: 2px 8px;
  border-radius: 10px;
  font-weight: 700;
}
.dp-list-badge.pending { background: rgba(245,158,11,.15); color: var(--amber); }
.dp-list-badge.critical { background: rgba(220,38,38,.15); color: var(--red); }
.dp-list-badge.high { background: rgba(245,158,11,.15); color: var(--amber); }

/* Empty State */
.dp-empty {
  padding: 32px 16px;
  text-align: center;
  color: var(--dim);
}
.dp-empty i {
  font-size: 32px;
  margin-bottom: 12px;
  opacity: .3;
}
.dp-empty-text {
  font-size: 13px;
}

/* Bottom Nav */
.dp-nav {
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  background: #12171f;
  border-top: 1px solid var(--border);
  display: flex;
  padding: 8px 0;
  z-index: 100;
}
.dp-nav-item {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  padding: 8px;
  text-decoration: none;
  color: var(--dim);
  transition: color .15s;
}
.dp-nav-item.active {
  color: var(--amber);
}
.dp-nav-item i {
  font-size: 18px;
}
.dp-nav-item span {
  font-size: 10px;
  font-weight: 600;
}

/* Logout Button */
.dp-logout {
  position: fixed;
  bottom: 70px;
  <?= $isRTL ? 'left' : 'right' ?>: 20px;
  width: 50px;
  height: 50px;
  border-radius: 50%;
  background: rgba(220,38,38,.15);
  border: 1px solid rgba(220,38,38,.3);
  color: var(--red);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  cursor: pointer;
  z-index: 99;
  transition: all .15s;
}
.dp-logout:hover {
  background: rgba(220,38,38,.25);
  transform: scale(1.05);
}
</style>
</head>
<body>

<!-- Header -->
<div class="dp-header">
  <div class="dp-header-top">
    <div class="dp-brand">
      <div class="dp-brand-icon"><i class="fas fa-truck"></i></div>
      <div class="dp-brand-text"><?= $isRTL ? 'بوابة السائقين' : 'Driver Portal' ?></div>
    </div>
    <div class="dp-user">
      <div class="dp-user-name"><?= e($lang==='ar' && $driver['name_ar'] ? $driver['name_ar'] : $driver['name_en']) ?></div>
      <div class="dp-user-role"><?= $isRTL ? 'سائق' : 'Driver' ?></div>
    </div>
  </div>
</div>

<!-- Stats Cards -->
<div class="dp-stats">
  <div class="dp-stat-card">
    <div class="dp-stat-icon amber"><i class="fas fa-clipboard-list"></i></div>
    <div class="dp-stat-value"><?= $todayTotal ?></div>
    <div class="dp-stat-label"><?= $isRTL ? 'طلبات اليوم' : 'Today' ?></div>
  </div>
  <div class="dp-stat-card">
    <div class="dp-stat-icon green"><i class="fas fa-calendar"></i></div>
    <div class="dp-stat-value"><?= $monthTotal ?></div>
    <div class="dp-stat-label"><?= $isRTL ? 'هذا الشهر' : 'This Month' ?></div>
  </div>
</div>

<!-- Today's Orders -->
<div class="dp-section">
  <div class="dp-section-header">
    <i class="fas fa-clipboard-list"></i>
    <div class="dp-section-title"><?= $isRTL ? 'طلبات اليوم' : "Today's Orders" ?></div>
  </div>
  <div class="dp-section-body">
    <div class="platform-bar">
      <div class="platform-icon talabat"><i class="fas fa-utensils"></i></div>
      <div class="platform-info">
        <div class="platform-name">Talabat</div>
      </div>
      <div class="platform-count"><?= $todayData['talabat'] ?? 0 ?></div>
    </div>
    <div class="platform-bar">
      <div class="platform-icon keeta"><i class="fas fa-motorcycle"></i></div>
      <div class="platform-info">
        <div class="platform-name">Keeta</div>
      </div>
      <div class="platform-count"><?= $todayData['keeta'] ?? 0 ?></div>
    </div>
    <div class="platform-bar">
      <div class="platform-icon other"><i class="fas fa-box"></i></div>
      <div class="platform-info">
        <div class="platform-name"><?= $isRTL ? 'أخرى' : 'Other' ?></div>
      </div>
      <div class="platform-count"><?= $todayData['other'] ?? 0 ?></div>
    </div>
  </div>
</div>

<!-- Monthly Progress -->
<div class="dp-section">
  <div class="dp-section-header">
    <i class="fas fa-chart-line"></i>
    <div class="dp-section-title"><?= $isRTL ? 'التقدم الشهري' : 'Monthly Progress' ?></div>
  </div>
  <div class="dp-section-body">
    <div class="progress-wrapper">
      <div class="progress-label">
        <span><?= $isRTL ? 'الهدف' : 'Target' ?>: <?= number_format($target) ?></span>
        <span><?= $monthTotal ?> / <?= $target ?> (<?= $pct ?>%)</span>
      </div>
      <div class="progress-track">
        <div class="progress-fill" style="width: <?= $pct ?>%"></div>
      </div>
    </div>
  </div>
</div>

<!-- Bonus Status -->
<?php if ($bonusEnabled): ?>
<div class="dp-section">
  <div class="dp-section-header">
    <i class="fas fa-award"></i>
    <div class="dp-section-title"><?= $isRTL ? 'المكافأة' : 'Bonus' ?></div>
  </div>
  <div class="dp-section-body">
    <div class="bonus-card">
      <?php if ($bonus > 0): ?>
        <div class="bonus-amount"><?= number_format($bonus, 3) ?> KWD</div>
        <div class="bonus-status"><?= $isRTL ? 'مستحق' : 'Eligible' ?></div>
        <div class="bonus-badge target-met"><?= $isRTL ? 'تم تحقيق الهدف' : 'Target Met' ?></div>
      <?php elseif ($eligible): ?>
        <div class="bonus-amount" style="color: var(--dim)"><?= number_format($bonusAmount, 3) ?> KWD</div>
        <div class="bonus-status"><?= $isRTL ? 'تحتاج' : 'Need' ?> <?= $target - $monthTotal ?> <?= $isRTL ? 'طلب إضافي' : 'more orders' ?></div>
        <div class="bonus-badge eligible"><?= $isRTL ? 'مؤهل' : 'Eligible' ?></div>
      <?php else: ?>
        <div class="bonus-amount" style="color: var(--dim)">—</div>
        <div class="bonus-status"><?= $isRTL ? 'غير مؤهل للمكافأة' : 'Not bonus eligible' ?></div>
        <div class="bonus-badge not-eligible"><?= $isRTL ? 'غير مؤهل' : 'Not Eligible' ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Penalties -->
<div class="dp-section">
  <div class="dp-section-header">
    <i class="fas fa-exclamation-triangle"></i>
    <div class="dp-section-title"><?= $isRTL ? 'المخالفات' : 'Penalties' ?></div>
  </div>
  <div class="dp-section-body">
    <?php if (!$penaltyList): ?>
      <div class="dp-empty">
        <i class="fas fa-check-circle"></i>
        <div class="dp-empty-text"><?= $isRTL ? 'لا توجد مخالفات معلقة' : 'No pending penalties' ?></div>
      </div>
    <?php else: ?>
      <?php foreach ($penaltyList as $p): ?>
        <div class="dp-list-item">
          <div class="dp-list-icon penalty"><i class="fas fa-exclamation"></i></div>
          <div class="dp-list-content">
            <div class="dp-list-title"><?= t($p['penalty_type']) ?></div>
            <div class="dp-list-sub"><?= fmtDate($p['penalty_date']) ?> · <?= number_format($p['amount']) ?> KWD</div>
          </div>
          <div class="dp-list-badge pending"><?= $isRTL ? 'معلق' : 'Pending' ?></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- Vehicle Reminders -->
<?php if ($driver['vehicle_id']): ?>
<div class="dp-section">
  <div class="dp-section-header">
    <i class="fas fa-bell"></i>
    <div class="dp-section-title"><?= $isRTL ? 'تنبيهات المركبة' : 'Vehicle Alerts' ?></div>
  </div>
  <div class="dp-section-body">
    <?php if (!$vehicleReminders): ?>
      <div class="dp-empty">
        <i class="fas fa-check-circle"></i>
        <div class="dp-empty-text"><?= $isRTL ? 'لا توجد تنبيهات' : 'No alerts' ?></div>
      </div>
    <?php else: ?>
      <?php foreach ($vehicleReminders as $r): ?>
        <div class="dp-list-item">
          <div class="dp-list-icon <?= $r['priority'] ?>">
            <i class="fas fa-<?= $r['priority'] === 'critical' ? 'exclamation-circle' : 'bell' ?>"></i>
          </div>
          <div class="dp-list-content">
            <div class="dp-list-title"><?= e($r['title']) ?></div>
            <div class="dp-list-sub"><?= fmtDate($r['remind_date']) ?></div>
          </div>
          <div class="dp-list-badge <?= $r['priority'] ?>"><?= t($r['priority']) ?></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- Bottom Navigation -->
<div class="dp-nav">
  <a href="#" class="dp-nav-item active">
    <i class="fas fa-home"></i>
    <span><?= $isRTL ? 'الرئيسية' : 'Home' ?></span>
  </a>
  <a href="?module=orders" class="dp-nav-item">
    <i class="fas fa-clipboard-list"></i>
    <span><?= $isRTL ? 'الطلبات' : 'Orders' ?></span>
  </a>
  <a href="logout.php" class="dp-nav-item">
    <i class="fas fa-sign-out-alt"></i>
    <span><?= $isRTL ? 'خروج' : 'Logout' ?></span>
  </a>
</div>

<!-- Quick Logout Button -->
<a href="logout.php" class="dp-logout" title="<?= $isRTL ? 'خروج' : 'Logout' ?>">
  <i class="fas fa-sign-out-alt"></i>
</a>

<script>
// Register Service Worker
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/sw.js').then(reg => {
    console.log('SW registered:', reg);
  }).catch(err => {
    console.log('SW registration failed:', err);
  });
}
</script>
</body>
</html>
