<?php
ob_start();
session_start();
require_once __DIR__.'/config.php';
require_once __DIR__.'/functions.php';

// ---- Auth guard ----
if (empty($_SESSION['user_id'])) {
    header('Location: login.php'); exit;
}

// ---- Driver role redirect to driver portal ----
if ($_SESSION['user_role'] === 'driver') {
    header('Location: driver-portal.php'); exit;
}

// ---- Update last activity timestamp ----
$pdo = getDB();
$pdo->prepare("UPDATE users SET last_activity=NOW() WHERE id=?")->execute([$_SESSION['user_id']]);

// ---- API passthrough (must be before any HTML output) ----
if (($_GET['module'] ?? '') === 'api') {
    require_once __DIR__.'/api/index.php'; exit;
}

// ---- Logout ----
if (isset($_GET['logout'])) {
    // Clear last_activity timestamp so user drops off online list immediately
    $pdo->prepare("UPDATE users SET last_activity=NULL WHERE id=?")->execute([$_SESSION['user_id']]);
    session_destroy();
    header('Location: login.php'); exit;
}

// ---- Language ----
if (isset($_GET['lang']) && in_array($_GET['lang'],['en','ar'])) {
    $_SESSION['lang'] = $_GET['lang'];
    header("Location: ?module=".($_GET['module'] ?? 'dashboard'));
    exit;
}
// Force Arabic as default on very first visit
if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'ar';
}
$lang  = $_SESSION['lang'];
$LANG  = require __DIR__."/lang/{$lang}.php";
$isRTL = $lang === 'ar';
$dir   = $isRTL ? 'rtl' : 'ltr';

// ---- Routing ----
$module = preg_replace('/[^a-z_]/', '', $_GET['module'] ?? 'dashboard');
$allowed = ['dashboard','vehicles','employees','assignments','services','insurance',
            'accidents','locations','inspections','approvals','reminders','users',
            'trips','fuel_management','gps_tracking','petty_cash','expenses','reports','penalties','maintenance',
            'orders','settings','driver_portal'];
if (!in_array($module, $allowed)) $module = 'dashboard';

// ---- Module titles ----
$moduleTitles = [
    'dashboard'       => ['icon'=>'fa-tachometer-alt',     'color'=>'primary'],
    'vehicles'        => ['icon'=>'fa-car',                'color'=>'primary'],
    'employees'       => ['icon'=>'fa-users',              'color'=>'success'],
    'assignments'     => ['icon'=>'fa-link',               'color'=>'info'],
    'services'        => ['icon'=>'fa-wrench',             'color'=>'warning'],
    'maintenance'     => ['icon'=>'fa-tools',              'color'=>'warning'],
    'insurance'       => ['icon'=>'fa-shield-alt',         'color'=>'cyan'],
    'accidents'       => ['icon'=>'fa-car-crash',          'color'=>'danger'],
    'penalties'       => ['icon'=>'fa-exclamation-triangle','color'=>'warning'],
    'locations'       => ['icon'=>'fa-map-marker-alt',     'color'=>'secondary'],
    'inspections'     => ['icon'=>'fa-clipboard-check',    'color'=>'info'],
    'approvals'       => ['icon'=>'fa-file-certificate',   'color'=>'success'],
    'reminders'       => ['icon'=>'fa-bell',               'color'=>'warning'],
    'users'           => ['icon'=>'fa-users-cog',          'color'=>'danger'],
    'trips'           => ['icon'=>'fa-route',              'color'=>'primary'],
    'fuel_management' => ['icon'=>'fa-gas-pump',           'color'=>'success'],
    'gps_tracking'    => ['icon'=>'fa-satellite',          'color'=>'info'],
    'petty_cash'      => ['icon'=>'fa-wallet',              'color'=>'warning'],
    'expenses'        => ['icon'=>'fa-receipt',             'color'=>'danger'],
    'reports'         => ['icon'=>'fa-chart-bar',           'color'=>'primary'],
    'orders'          => ['icon'=>'fa-clipboard-list',      'color'=>'warning'],
    'settings'        => ['icon'=>'fa-cog',                 'color'=>'secondary'],
    'driver_portal'   => ['icon'=>'fa-mobile-alt',           'color'=>'info'],
];

// ---- Alerts (for badge counts) ----
$pdo = getDB();
$alertCounts = [
    'insurance'   => (int)$pdo->query("SELECT COUNT(*) FROM vehicle_insurance WHERE status='active' AND expiry_date <= DATE_ADD(CURDATE(),INTERVAL 30 DAY)")->fetchColumn(),
    'accidents'   => (int)$pdo->query("SELECT COUNT(*) FROM vehicle_accidents WHERE status IN ('reported','under_assessment','under_repair')")->fetchColumn(),
    'inspections' => (int)$pdo->query("SELECT COUNT(*) FROM vehicle_inspections WHERE status IN ('pending_review','action_required')")->fetchColumn(),
    'approvals'   => (int)$pdo->query("SELECT COUNT(*) FROM hygiene_approvals WHERE status='active' AND expiry_date <= DATE_ADD(CURDATE(),INTERVAL 30 DAY)")->fetchColumn(),
    'reminders'   => (int)$pdo->query("SELECT COUNT(*) FROM reminders WHERE status='pending' AND (snoozed_until IS NULL OR snoozed_until <= CURDATE())")->fetchColumn(),
    'notifications'=> (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read=0")->fetchColumn(),
];

// ---- Flash ----
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= t('app_name') ?> — <?= t($module) ?></title>

<!-- Bootstrap 5 RTL/LTR -->
<?php if ($isRTL): ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
<?php else: ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<?php endif; ?>

<!-- Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<!-- Google Fonts — Syne (bold geometric display) + Cairo (Arabic) + JetBrains Mono (numbers) -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Oxanium:wght@700;800&family=Cairo:wght@400;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">

<style>
/* ================================================================
   FLEET MANAGEMENT SYSTEM
   Aesthetic: Operations Command Center
   Dark authoritative sidebar · Warm white workspace · Amber accent
   Typography: Syne (EN display) · Oxanium (numbers) · Cairo (AR)
   ================================================================ */

:root {
  /* Core palette */
  --ink:       #0c0f13;
  --ink2:      #161b23;
  --ink3:      #1e2633;
  --ink4:      #2a3545;
  --amber:     #f59e0b;
  --amber2:    #d97706;
  --amber-glow: rgba(245,158,11,.15);
  --slate:     #8899aa;
  --dim:       #4a5568;

  /* Content area */
  --bg:        #f4f6f9;
  --surface:   #ffffff;
  --border:    #e4e9f0;
  --border2:   #d0d8e4;
  --text:      #1a2233;
  --text2:     #4a5568;

  /* Status */
  --green:     #059669;
  --red:       #dc2626;
  --blue:      #2563eb;
  --teal:      #0891b2;
  --purple:    #7c3aed;
  --yellow:    #b45309;

  --sidebar-w: 264px;
  --topbar-h:  58px;
  --radius:    10px;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html { scroll-behavior: smooth; }

body {
  background: var(--bg);
  background-image:
    radial-gradient(circle at 20% 50%, rgba(245,158,11,.03) 0%, transparent 50%),
    radial-gradient(circle at 80% 20%, rgba(37,99,235,.03) 0%, transparent 50%);
  font-family: <?= $isRTL ? "'Cairo', sans-serif" : "'Syne', 'Cairo', sans-serif" ?>;
  font-size: 14px;
  color: var(--text);
  line-height: 1.5;
}

/* ================================================================
   SIDEBAR — Command Rail
   ================================================================ */
#sidebar {
  position: fixed;
  top: 0;
  <?= $isRTL ? 'right' : 'left' ?>: 0;
  width: var(--sidebar-w);
  height: 100vh;
  background: var(--ink);
  overflow-y: auto;
  overflow-x: hidden;
  z-index: 1040;
  display: flex;
  flex-direction: column;
  transition: transform .28s cubic-bezier(.4,0,.2,1);
  /* Subtle vertical scan lines — industrial texture */
  background-image: repeating-linear-gradient(
    to right,
    transparent,
    transparent 40px,
    rgba(255,255,255,.012) 40px,
    rgba(255,255,255,.012) 41px
  );
}

/* Brand area */
#sidebar .brand {
  padding: 22px 18px 18px;
  border-bottom: 1px solid rgba(255,255,255,.06);
  text-decoration: none;
  display: block;
}
#sidebar .brand-logo {
  display: flex;
  align-items: center;
  gap: 12px;
}
#sidebar .brand-icon {
  width: 44px;
  height: 44px;
  background: var(--amber);
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  color: var(--ink);
  flex-shrink: 0;
  box-shadow: 0 0 20px var(--amber-glow), 0 4px 12px rgba(0,0,0,.3);
  transition: box-shadow .2s;
}
#sidebar .brand:hover .brand-icon {
  box-shadow: 0 0 30px rgba(245,158,11,.3), 0 4px 16px rgba(0,0,0,.4);
}
#sidebar .brand-text {
  font-family: 'Oxanium', sans-serif;
  font-weight: 800;
  font-size: 14.5px;
  color: #fff;
  line-height: 1.25;
  letter-spacing: .3px;
}
#sidebar .brand-sub {
  font-size: 10.5px;
  color: var(--dim);
  font-weight: 400;
  margin-top: 1px;
  font-family: <?= $isRTL ? "'Cairo'" : "'Syne'" ?>, sans-serif;
}

/* Nav groups */
#sidebar .nav-section {
  padding: 14px 18px 4px;
  font-size: 9.5px;
  font-weight: 700;
  letter-spacing: 2px;
  text-transform: uppercase;
  color: var(--dim);
  font-family: 'JetBrains Mono', monospace;
}

#sidebar .nav-item a {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 14px 9px 18px;
  color: var(--slate);
  text-decoration: none;
  font-size: 13px;
  font-weight: 600;
  transition: color .15s, background .15s;
  position: relative;
  border-<?= $isRTL?'right':'left' ?>: 2px solid transparent;
  margin: 1px 0;
}
#sidebar .nav-item a::before {
  content: '';
  position: absolute;
  inset: 0;
  background: transparent;
  transition: background .15s;
}
#sidebar .nav-item a:hover {
  color: #fff;
  border-<?= $isRTL?'right':'left' ?>-color: var(--ink4);
}
#sidebar .nav-item a:hover::before { background: rgba(255,255,255,.04); }

#sidebar .nav-item a.active {
  color: var(--amber);
  border-<?= $isRTL?'right':'left' ?>-color: var(--amber);
  font-weight: 700;
}
#sidebar .nav-item a.active::before { background: var(--amber-glow); }

#sidebar .nav-item a .nav-icon {
  width: 18px;
  text-align: center;
  font-size: 13px;
  flex-shrink: 0;
  opacity: .7;
  transition: opacity .15s;
}
#sidebar .nav-item a.active .nav-icon,
#sidebar .nav-item a:hover .nav-icon { opacity: 1; }

#sidebar .nav-item a .alert-badge {
  margin-<?= $isRTL ? 'right' : 'left' ?>: auto;
  background: var(--red);
  color: white;
  font-size: 9.5px;
  font-weight: 700;
  padding: 2px 6px;
  border-radius: 20px;
  min-width: 20px;
  text-align: center;
  font-family: 'JetBrains Mono', monospace;
  animation: pulse-badge 2s infinite;
}
@keyframes pulse-badge {
  0%, 100% { opacity: 1; }
  50% { opacity: .6; }
}

/* Sidebar footer — language switcher */
#sidebar .sidebar-footer {
  margin-top: auto;
  padding: 14px 16px;
  border-top: 1px solid rgba(255,255,255,.06);
  display: flex;
  flex-direction: column;
  gap: 8px;
}
#sidebar .lang-row {
  display: flex;
  gap: 6px;
}
#sidebar .lang-btn {
  flex: 1;
  text-align: center;
  padding: 7px 6px;
  border-radius: 7px;
  background: var(--ink2);
  color: var(--slate);
  text-decoration: none;
  font-size: 11.5px;
  font-weight: 700;
  transition: all .15s;
  letter-spacing: .5px;
  border: 1px solid rgba(255,255,255,.05);
}
#sidebar .lang-btn.active {
  background: var(--amber);
  color: var(--ink);
  border-color: var(--amber);
}
#sidebar .lang-btn:hover:not(.active) {
  background: var(--ink3);
  color: #fff;
}
#sidebar .version-tag {
  text-align: center;
  font-size: 10px;
  color: var(--dim);
  font-family: 'JetBrains Mono', monospace;
}

/* ================================================================
   MAIN AREA
   ================================================================ */
#main {
  margin-<?= $isRTL ? 'right' : 'left' ?>: var(--sidebar-w);
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

/* ================================================================
   TOPBAR
   ================================================================ */
#topbar {
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  padding: 0 28px;
  height: var(--topbar-h);
  display: flex;
  align-items: center;
  gap: 14px;
  position: sticky;
  top: 0;
  z-index: 100;
  /* Very subtle dot grid */
  background-image: radial-gradient(circle, rgba(0,0,0,.04) 1px, transparent 1px);
  background-size: 20px 20px;
  background-color: var(--surface);
}
#topbar .page-title {
  font-family: 'Oxanium', sans-serif;
  font-weight: 800;
  font-size: 17px;
  color: var(--ink);
  display: flex;
  align-items: center;
  gap: 9px;
  letter-spacing: .2px;
  text-transform: uppercase;
}
#topbar .page-title .title-icon {
  color: var(--amber2);
  font-size: 15px;
}
#topbar .topbar-right {
  margin-<?= $isRTL?'right':'left' ?>: auto;
  display: flex;
  align-items: center;
  gap: 10px;
}
#topbar .date-chip {
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  color: var(--text2);
  background: var(--bg);
  padding: 5px 12px;
  border-radius: 20px;
  border: 1px solid var(--border);
  letter-spacing: .3px;
}
#topbar .topbar-btn {
  font-family: <?= $isRTL ? "'Cairo'" : "'Syne'" ?>, sans-serif;
  font-size: 12.5px;
  font-weight: 700;
  padding: 7px 14px;
  border-radius: 7px;
  background: var(--amber);
  color: var(--ink);
  border: none;
  text-decoration: none;
  display: flex;
  align-items: center;
  gap: 6px;
  transition: background .15s, transform .1s;
  letter-spacing: .3px;
}
#topbar .topbar-btn:hover { background: var(--amber2); transform: translateY(-1px); color: var(--ink); }

/* ================================================================
   CONTENT
   ================================================================ */
#content { padding: 26px 28px; flex: 1; }

/* ================================================================
   KPI CARDS — Command Metrics
   ================================================================ */
.kpi-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 20px 18px 18px;
  position: relative;
  overflow: hidden;
  transition: transform .2s cubic-bezier(.4,0,.2,1), box-shadow .2s, border-color .2s;
  cursor: default;
  animation: kpi-in .4s cubic-bezier(.4,0,.2,1) both;
}
.kpi-card:nth-child(1) { animation-delay: .04s; }
.kpi-card:nth-child(2) { animation-delay: .08s; }
.kpi-card:nth-child(3) { animation-delay: .12s; }
.kpi-card:nth-child(4) { animation-delay: .16s; }
.kpi-card:nth-child(5) { animation-delay: .20s; }
.kpi-card:nth-child(6) { animation-delay: .24s; }
.kpi-card:nth-child(7) { animation-delay: .28s; }
.kpi-card:nth-child(8) { animation-delay: .32s; }
@keyframes kpi-in {
  from { opacity: 0; transform: translateY(14px); }
  to   { opacity: 1; transform: translateY(0); }
}

.kpi-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,.09); }

/* Colored left border stripe — the accent */
.kpi-card::before {
  content: '';
  position: absolute;
  <?= $isRTL ? 'right' : 'left' ?>: 0;
  top: 0; bottom: 0;
  width: 4px;
  border-radius: 4px 0 0 4px;
  transition: width .2s;
}
.kpi-card:hover::before { width: 6px; }

.kpi-blue::before   { background: #2563eb; }
.kpi-orange::before { background: var(--amber); }
.kpi-green::before  { background: #059669; }
.kpi-teal::before   { background: #0891b2; }
.kpi-cyan::before   { background: #06b6d4; }
.kpi-yellow::before { background: #d97706; }
.kpi-red::before    { background: #dc2626; }
.kpi-purple::before { background: #7c3aed; }

/* KPI hover tint */
.kpi-blue:hover   { border-color: rgba(37,99,235,.3);   background: rgba(37,99,235,.02); }
.kpi-orange:hover { border-color: rgba(245,158,11,.3);  background: rgba(245,158,11,.02); }
.kpi-green:hover  { border-color: rgba(5,150,105,.3);   background: rgba(5,150,105,.02); }
.kpi-teal:hover   { border-color: rgba(8,145,178,.3);   background: rgba(8,145,178,.02); }
.kpi-cyan:hover   { border-color: rgba(6,182,212,.3);   background: rgba(6,182,212,.02); }
.kpi-yellow:hover { border-color: rgba(217,119,6,.3);   background: rgba(217,119,6,.02); }
.kpi-red:hover    { border-color: rgba(220,38,38,.3);   background: rgba(220,38,38,.02); }
.kpi-purple:hover { border-color: rgba(124,58,237,.3);  background: rgba(124,58,237,.02); }

.kpi-card .kpi-icon {
  font-size: 26px;
  position: absolute;
  top: 14px;
  <?= $isRTL?'left':'right' ?>: 16px;
  opacity: .08;
  transition: opacity .2s, transform .2s;
}
.kpi-card:hover .kpi-icon { opacity: .14; transform: scale(1.1) rotate(-5deg); }

.kpi-card .kpi-val {
  font-family: 'Oxanium', sans-serif;
  font-size: 34px;
  font-weight: 800;
  line-height: 1;
  color: var(--ink);
  letter-spacing: -1px;
}
.kpi-card .kpi-lbl {
  font-size: 11px;
  color: var(--text2);
  margin-top: 5px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .8px;
  font-family: 'JetBrains Mono', monospace;
}

/* ================================================================
   CARDS
   ================================================================ */
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: 0 1px 4px rgba(0,0,0,.05);
}
.card-header {
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  font-size: 13px;
  font-weight: 700;
  padding: 13px 18px;
  color: var(--ink);
  font-family: <?= $isRTL ? "'Cairo'" : "'Syne'" ?>, sans-serif;
}
.card-footer {
  background: #fafbfc;
  border-top: 1px solid var(--border);
  padding: 8px 16px;
}
.alert-card { border-left: 3px solid transparent; }
.alert-card.border-danger  { border-left-color: var(--red) !important; }
.alert-card.border-warning { border-left-color: var(--amber) !important; }
.alert-card.border-info    { border-left-color: var(--teal) !important; }
.alert-card .card-header   { padding: 10px 16px; }

/* ================================================================
   FILTER BAR
   ================================================================ */
.card .card-body.py-2 {
  background: #fafbfc;
  border-bottom: 1px solid var(--border);
}

/* ================================================================
   TABLES — Data Grid
   ================================================================ */
.table {
  font-size: 13px;
}
.table th {
  font-family: 'JetBrains Mono', monospace;
  font-size: 10.5px;
  text-transform: uppercase;
  letter-spacing: .8px;
  font-weight: 600;
  color: #fff;
  padding: 11px 14px;
  white-space: nowrap;
  border: none;
}
.table-dark th {
  background: var(--ink) !important;
  border-bottom: 2px solid var(--amber) !important;
}
.table td {
  vertical-align: middle;
  padding: 10px 14px;
  border-bottom: 1px solid var(--border);
  color: var(--text);
}
.table tbody tr {
  transition: background .1s;
}
.table tbody tr:hover td {
  background: rgba(245,158,11,.04);
}
/* Number columns — monospace */
.table td:is(:nth-child(8), :nth-child(9)) {
  font-family: 'JetBrains Mono', monospace;
  font-size: 12.5px;
}

/* ================================================================
   BADGES — refined
   ================================================================ */
.badge {
  font-size: 10.5px;
  font-weight: 700;
  padding: 3px 8px;
  border-radius: 4px;
  letter-spacing: .3px;
  font-family: <?= $isRTL ? "'Cairo'" : "'Syne'" ?>, sans-serif;
}

/* ================================================================
   BUTTONS
   ================================================================ */
.btn {
  font-family: <?= $isRTL ? "'Cairo'" : "'Syne'" ?>, sans-serif;
  font-weight: 700;
  letter-spacing: .3px;
  border-radius: 7px;
  transition: all .15s cubic-bezier(.4,0,.2,1);
}
.btn:hover { transform: translateY(-1px); }
.btn:active { transform: translateY(0); }
.btn-success  { background: var(--green);  border-color: var(--green); }
.btn-primary  { background: var(--blue);   border-color: var(--blue); }
.btn-warning  { background: var(--amber);  border-color: var(--amber); color: var(--ink); }
.btn-danger   { background: var(--red);    border-color: var(--red); }
.btn-xs { padding: 3px 8px; font-size: 11px; border-radius: 5px; }

/* Form controls */
.form-control, .form-select {
  font-family: <?= $isRTL ? "'Cairo'" : "'Syne'" ?>, sans-serif;
  font-size: 13px;
  border-color: var(--border2);
  border-radius: 7px;
}
.form-control:focus, .form-select:focus {
  border-color: var(--amber);
  box-shadow: 0 0 0 3px var(--amber-glow);
}
.form-label {
  font-size: 12px;
  font-weight: 700;
  color: var(--text2);
  letter-spacing: .3px;
  font-family: <?= $isRTL ? "'Cairo'" : "'Syne'" ?>, sans-serif;
  margin-bottom: 4px;
}

/* ================================================================
   MODAL
   ================================================================ */
.modal-header { border-bottom: none; padding: 18px 20px 10px; }
.modal-header .btn-close-white { filter: brightness(0) invert(1); }
.modal-title {
  font-family: 'Oxanium', sans-serif;
  font-weight: 800;
  font-size: 16px;
  letter-spacing: .3px;
}
.modal-body { padding: 16px 20px; }
.modal-footer { border-top: 1px solid var(--border); padding: 12px 20px; }

/* ================================================================
   FLASH / ALERT BAR
   ================================================================ */
.flash-bar {
  border-radius: 8px;
  padding: 11px 16px;
  margin-bottom: 18px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 9px;
  font-size: 13.5px;
  animation: flash-slide .3s cubic-bezier(.4,0,.2,1);
}
@keyframes flash-slide {
  from { opacity: 0; transform: translateY(-8px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* ================================================================
   MOBILE SIDEBAR TOGGLE
   ================================================================ */
#sidebarToggle {
  display: none;
  background: var(--amber);
  border: none;
  color: var(--ink);
  width: 36px; height: 36px;
  border-radius: 8px;
  font-size: 15px;
  cursor: pointer;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  transition: background .15s;
}
#sidebarToggle:hover { background: var(--amber2); }

/* ================================================================
   TABS
   ================================================================ */
.nav-tabs {
  border-bottom: 1px solid var(--border);
  gap: 2px;
}
.nav-tabs .nav-link {
  font-size: 12.5px;
  font-weight: 700;
  color: var(--text2);
  border: none;
  padding: 9px 14px;
  border-radius: 8px 8px 0 0;
  font-family: <?= $isRTL ? "'Cairo'" : "'Syne'" ?>, sans-serif;
  letter-spacing: .2px;
  transition: color .15s, background .15s;
}
.nav-tabs .nav-link:hover { color: var(--ink); background: rgba(0,0,0,.04); }
.nav-tabs .nav-link.active {
  color: var(--amber2);
  background: var(--surface);
  border-bottom: 2px solid var(--amber);
}
.tab-content { background: var(--surface); }

/* ================================================================
   SCROLLBAR — sidebar
   ================================================================ */
#sidebar::-webkit-scrollbar { width: 3px; }
#sidebar::-webkit-scrollbar-track { background: transparent; }
#sidebar::-webkit-scrollbar-thumb { background: var(--ink4); border-radius: 4px; }

/* ================================================================
   PRINT
   ================================================================ */
@media print {
  #sidebar, #topbar, .btn, .card-body.py-2 { display: none !important; }
  #main { margin: 0 !important; }
  #content { padding: 0 !important; }
  .card { box-shadow: none !important; border: 1px solid #ddd !important; }
}

/* ================================================================
   RESPONSIVE
   ================================================================ */
@media (max-width: 768px) {
  :root { --sidebar-w: 0px; }
  #sidebar { transform: translateX(<?= $isRTL ? '100%' : '-100%' ?>); width: 264px; }
  #sidebar.open { transform: translateX(0); }
  #sidebarToggle { display: flex; }
  #main { margin: 0 !important; }
  #content { padding: 14px; }
  #topbar { padding: 0 14px; }
  .kpi-card .kpi-val { font-size: 26px; }
}

/* Overlay on mobile */
#sidebarOverlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.5);
  z-index: 1039;
}
#sidebarOverlay.show { display: block; }
</style>
</head>
<body>

<!-- ================================================================
     SIDEBAR
     ================================================================ -->
<nav id="sidebar">
  <a href="?module=dashboard" class="brand">
    <div class="brand-logo">
      <div class="brand-icon"><i class="fas fa-truck"></i></div>
      <div>
        <div class="brand-text"><?= t('app_name') ?></div>
        <div class="brand-sub"><?= $lang==='ar' ? COMPANY_NAME_AR : COMPANY_NAME_EN ?></div>
      </div>
    </div>
  </a>

  <div class="nav-section"><?= t('overview') ?></div>
  <div class="nav-item">
    <a href="?module=dashboard" class="<?= $module==='dashboard'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span> <?= t('dashboard') ?>
    </a>
  </div>

  <?php if ($_SESSION['user_role'] !== 'accountant'): ?>
  <div class="nav-section"><?= t('vehicles') ?></div>
  <div class="nav-item">
    <a href="?module=vehicles" class="<?= $module==='vehicles'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-car"></i></span> <?= t('vehicles') ?>
    </a>
  </div>
  <div class="nav-item">
    <a href="?module=services" class="<?= $module==='services'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-wrench"></i></span> <?= t('services') ?>
    </a>
  </div>
  <div class="nav-item">
    <a href="?module=maintenance" class="<?= $module==='maintenance'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-tools"></i></span> <?= t('maintenance') ?>
    </a>
  </div>
  <div class="nav-item">
    <a href="?module=insurance" class="<?= $module==='insurance'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-shield-alt"></i></span> <?= t('insurance') ?>
      <?php if ($alertCounts['insurance']): ?><span class="alert-badge"><?= $alertCounts['insurance'] ?></span><?php endif; ?>
    </a>
  </div>
  <div class="nav-item">
    <a href="?module=accidents" class="<?= $module==='accidents'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-car-crash"></i></span> <?= t('accidents') ?>
      <?php if ($alertCounts['accidents']): ?><span class="alert-badge"><?= $alertCounts['accidents'] ?></span><?php endif; ?>
    </a>
  </div>
  <div class="nav-item">
    <a href="?module=penalties" class="<?= $module==='penalties'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-exclamation-triangle"></i></span> <?= t('penalties') ?>
    </a>
  </div>

  <div class="nav-section"><?= t('employees') ?></div>
  <div class="nav-item">
    <a href="?module=employees" class="<?= $module==='employees'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-users"></i></span> <?= t('employees') ?>
    </a>
  </div>
  <div class="nav-item">
    <a href="?module=assignments" class="<?= $module==='assignments'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-link"></i></span> <?= t('assignments') ?>
    </a>
  </div>

  <div class="nav-section"><?= t('settings') ?></div>
  <div class="nav-item">
    <a href="?module=locations" class="<?= $module==='locations'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-map-marker-alt"></i></span> <?= t('locations') ?>
    </a>
  </div>
  <?php if (isAdmin()): ?>
  <div class="nav-item">
    <a href="?module=settings" class="<?= $module==='settings'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-cog"></i></span> <?= t('bonus_settings') ?>
    </a>
  </div>
  <?php endif; ?>
  <div class="nav-section">Operations</div>
  <div class="nav-item">
    <a href="?module=trips" class="<?= $module==='trips'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-route"></i></span> <?= t('trips') ?>
    </a>
  </div>
  <div class="nav-item">
    <a href="?module=fuel_management" class="<?= $module==='fuel_management'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-gas-pump"></i></span> <?= t('fuel_management') ?>
    </a>
  </div>
  <div class="nav-item">
    <a href="?module=orders" class="<?= $module==='orders'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-clipboard-list"></i></span> <?= t('orders') ?>
    </a>
  </div>
  <div class="nav-item">
    <a href="?module=gps_tracking" class="<?= $module==='gps_tracking'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-satellite"></i></span> <?= t('gps_tracking') ?>
    </a>
  </div>
  <div class="nav-item">
    <a href="?module=inspections" class="<?= $module==='inspections'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-clipboard-check"></i></span> <?= t('inspections') ?>
      <?php if ($alertCounts['inspections']): ?><span class="alert-badge"><?= $alertCounts['inspections'] ?></span><?php endif; ?>
    </a>
  </div>
  <div class="nav-item">
    <a href="?module=approvals" class="<?= $module==='approvals'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-file-alt"></i></span> <?= t('approvals') ?>
      <?php if ($alertCounts['approvals']): ?><span class="alert-badge"><?= $alertCounts['approvals'] ?></span><?php endif; ?>
    </a>
  </div>
  <div class="nav-item">
    <a href="?module=reminders" class="<?= $module==='reminders'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-bell"></i></span> <?= t('reminders') ?>
      <?php $rc = $alertCounts['reminders'] + $alertCounts['notifications']; if ($rc): ?><span class="alert-badge"><?= $rc ?></span><?php endif; ?>
    </a>
  </div>
  <?php endif; ?>

  <div class="nav-section"><?= t('accounting') ?></div>
  <div class="nav-item">
    <a href="?module=petty_cash" class="<?= $module==='petty_cash'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-wallet"></i></span> <?= t('petty_cash') ?>
    </a>
  </div>
  <div class="nav-item">
    <a href="?module=expenses" class="<?= $module==='expenses'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-receipt"></i></span> <?= t('expenses') ?>
    </a>
  </div>
  <div class="nav-item">
    <a href="?module=reports" class="<?= $module==='reports'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-chart-bar"></i></span> <?= t('reports') ?>
    </a>
  </div>
  <?php if ($_SESSION['user_role'] === 'admin'): ?>
  <div class="nav-section"><?= $lang==='ar'?'النظام':'System' ?></div>
  <div class="nav-item">
    <a href="?module=users" class="<?= $module==='users'?'active':'' ?>">
      <span class="nav-icon"><i class="fas fa-users-cog"></i></span> <?= $lang==='ar'?'المستخدمون':'Users' ?>
    </a>
  </div>
  <?php endif; ?>

  <div class="sidebar-footer">
    <div class="lang-row">
      <a href="?lang=en&module=<?= $module ?>" class="lang-btn <?= $lang==='en'?'active':'' ?>">🇬🇧 EN</a>
      <a href="?lang=ar&module=<?= $module ?>" class="lang-btn <?= $lang==='ar'?'active':'' ?>">🇰🇼 AR</a>
    </div>
    <div class="version-tag">FLEET MGMT · v1.0</div>
  </div>
</nav>
<div id="sidebarOverlay" onclick="toggleSidebar()"></div>

<!-- ================================================================
     MAIN
     ================================================================ -->
<div id="main">

  <!-- Topbar -->
  <div id="topbar">
    <button id="sidebarToggle" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
    <div class="page-title">
      <i class="fas <?= $moduleTitles[$module]['icon'] ?> title-icon"></i>
      <?= strtoupper(t($module)) ?>
    </div>
    <div class="topbar-right">
      <span class="date-chip"><?= date('D, d M Y') ?></span>
      <!-- Logged-in user -->
      <div class="d-none d-md-flex align-items-center gap-2" style="background:rgba(0,0,0,.06);padding:5px 12px;border-radius:20px;border:1px solid var(--border)">
        <div style="width:28px;height:28px;background:var(--amber);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:var(--ink)">
          <?= strtoupper(substr($_SESSION['username'],0,1)) ?>
        </div>
        <div style="line-height:1.2">
          <div style="font-size:12px;font-weight:700;color:var(--ink)"><?= e($lang==='ar' && $_SESSION['user_name_ar'] ? $_SESSION['user_name_ar'] : $_SESSION['user_name']) ?></div>
          <div style="font-size:10px;color:var(--text2)"><?= e($_SESSION['user_role']) ?></div>
        </div>
      </div>
      <button onclick="printContent()" class="topbar-btn d-none d-md-flex" style="background:#6c757d;color:white" title="<?= $lang==='ar'?'طباعة':'Print' ?>"><i class="fas fa-print"></i><span class="d-none d-lg-inline ms-1"><?= $lang==='ar'?'طباعة':'Print' ?></span></button>
      <button onclick="exportExcel()" class="topbar-btn d-none d-md-flex" style="background:#1d6f42;color:white" title="<?= $lang==='ar'?'تصدير إكسل':'Export Excel' ?>"><i class="fas fa-file-excel"></i><span class="d-none d-lg-inline ms-1">Excel</span></button>
      <a href="#" class="topbar-btn" style="background:var(--red);color:white" data-bs-toggle="modal" data-bs-target="#logoutModal">
        <i class="fas fa-sign-out-alt"></i>
        <span class="d-none d-md-inline"><?= $lang==='ar'?'خروج':'Logout' ?></span>
      </a>
    </div>
  </div>

  <!-- Content -->
  <div id="content">

    <!-- Flash message -->
    <?php if ($flash): ?>
    <div class="flash-bar bg-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'danger' ? 'danger' : 'warning') ?> text-white">
      <i class="fas <?= $flash['type']==='success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
      <?= e($flash['msg']) ?>
    </div>
    <?php endif; ?>

    <!-- Module content -->
    <?php
    $moduleFile = __DIR__."/modules/{$module}.php";
    if (file_exists($moduleFile)) {
        require $moduleFile;
    } else {
        echo '<div class="alert alert-danger">Module not found: '.e($module).'</div>';
    }
    ?>

  </div><!-- /content -->
</div><!-- /main -->

<!-- Logout Confirmation Modal -->
<div class="modal fade" id="logoutModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0">
        <h5 class="modal-title"><?= $lang==='ar'?'تسجيل الخروج':'Confirm Logout' ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center">
        <i class="fas fa-sign-out-alt fa-3x text-danger mb-3"></i>
        <p class="mb-0"><?= $lang==='ar'?'هل أنت متأكد من أنك تريد تسجيل الخروج؟':'Are you sure you want to log out?' ?></p>
      </div>
      <div class="modal-footer border-0 justify-content-center">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= $lang==='ar'?'إلغاء':'Cancel' ?></button>
        <a href="?logout=1" class="btn btn-danger"><?= $lang==='ar'?'تسجيل الخروج':'Logout' ?></a>
      </div>
    </div>
  </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<style>
@media print {
  #sidebar, #sidebarOverlay, #topbar, .flash-bar,
  .card.mb-3, nav.mt-3, .modal, .btn:not(.print-keep),
  .card-footer { display: none !important; }
  #main { margin: 0 !important; padding: 0 !important; }
  #content { padding: 0 !important; }
  .card { border: none !important; box-shadow: none !important; }
  body { background: white !important; }
  .print-header { display: block !important; }
}
.print-header { display: none; }
</style>

<script>
function printContent(){
  const title = document.querySelector('.page-title')?.innerText ?? '';
  const tbl   = document.querySelector('#content table');
  if (!tbl) { alert('No table found to print.'); return; }
  const w = window.open('', '_blank', 'width=1100,height=750');
  w.document.write('<html><head><title>' + title + '</title>');
  w.document.write('<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">');
  w.document.write(`<style>
    @page { size: A4 landscape; margin: 10mm; }
    body  { padding: 12px; font-size: 11px; font-family: Arial, sans-serif; }
    h5    { font-size: 13px; font-weight: 700; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; table-layout: auto; }
    th, td{ padding: 4px 6px; border: 1px solid #ccc; white-space: nowrap; font-size: 11px; }
    thead { background: #222; color: #fff; }
    .badge{ font-size: 10px; padding: 2px 5px; border-radius: 3px; }
    button, .btn, a.btn { display: none !important; }
    img   { max-height: 30px; }
  </style>`);
  w.document.write('</head><body>');
  w.document.write('<h5>' + title + ' &mdash; <?= date('d M Y') ?></h5>');
  w.document.write(tbl.outerHTML);
  w.document.write('<script>window.onload=function(){window.print();}<\/script>');
  w.document.write('</body></html>');
  w.document.close();
}
function exportExcel(){
  const title = (document.querySelector('.page-title')?.innerText ?? 'export').trim().replace(/\s+/g,'-');
  const tbl   = document.querySelector('#content table');
  if (!tbl) { alert('No table found to export.'); return; }
  const wb = XLSX.utils.table_to_book(tbl, {sheet: title, raw: false});
  XLSX.writeFile(wb, title + '-<?= date('Y-m-d') ?>.xlsx');
}
function toggleSidebar(){
  const sb = document.getElementById('sidebar');
  const ov = document.getElementById('sidebarOverlay');
  sb.classList.toggle('open');
  ov.classList.toggle('show');
}
// Auto-dismiss flash
const flash = document.querySelector('.flash-bar');
if (flash) setTimeout(()=>{ flash.style.transition='opacity .5s ease'; flash.style.opacity=0; setTimeout(()=>flash.remove(),500); }, 3500);
</script>

</body>
</html>
<?php ob_end_flush(); ?>
