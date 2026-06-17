<?php
// modules/dashboard.php

$pdo = getDB();

// KPIs
$stats = [];
$stats['total_cars']       = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE type='car'")->fetchColumn();
$stats['total_bikes']      = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE type='bike'")->fetchColumn();
$stats['active_vehicles']  = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE status='active'")->fetchColumn();
$stats['inactive_vehicles']= $pdo->query("SELECT COUNT(*) FROM vehicles WHERE status='inactive'")->fetchColumn();
$stats['in_service']       = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE status='in_service'")->fetchColumn();
$stats['in_accident']      = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE status='accident'")->fetchColumn();
$stats['total_employees']  = $pdo->query("SELECT COUNT(*) FROM employees WHERE status='active'")->fetchColumn();
$stats['active_assign']    = $pdo->query("SELECT COUNT(*) FROM driver_assignments WHERE status='active'")->fetchColumn();

// Online users (active in last 5 minutes) - only for admin
$onlineUsers = [];
if ($_SESSION['user_role'] === 'admin') {
    $onlineUsers = $pdo->query("
        SELECT id, username, full_name_en, full_name_ar, role, last_activity
        FROM users
        WHERE status='active' AND last_activity >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ORDER BY last_activity DESC
    ")->fetchAll();
}

// Expiring insurance (next 30 days)
$expIns = $pdo->query("
    SELECT vi.*, v.plate_number, v.make, v.model, v.type,
           DATEDIFF(vi.expiry_date, CURDATE()) AS days_left
    FROM vehicle_insurance vi
    JOIN vehicles v ON v.id = vi.vehicle_id
    WHERE vi.status='active' AND vi.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)
    ORDER BY vi.expiry_date ASC LIMIT 10
")->fetchAll();

// Expired insurance
$expiredIns = $pdo->query("
    SELECT vi.*, v.plate_number, v.make, v.model
    FROM vehicle_insurance vi
    JOIN vehicles v ON v.id = vi.vehicle_id
    WHERE vi.status='active' AND vi.expiry_date < CURDATE()
    LIMIT 10
")->fetchAll();

// Service due (vehicles where current_km >= next_service_km - 1000)
$svcDue = $pdo->query("
    SELECT v.*, 
           (SELECT MAX(vs.next_service_km) FROM vehicle_services vs WHERE vs.vehicle_id = v.id) AS next_km,
           COALESCE(e.name_en,'—') AS driver_name
    FROM vehicles v
    LEFT JOIN employees e ON e.id = v.current_driver_id
    WHERE v.status != 'sold'
    HAVING next_km IS NOT NULL AND v.current_km >= (next_km - 1000)
    ORDER BY (v.current_km - next_km) DESC LIMIT 10
")->fetchAll();

// Expiring documents (licenses, civil IDs, passports — next 30 days)
$expDocs = $pdo->query("
    SELECT emp_id, name_en, name_ar, 'License' AS doc_type, license_expiry AS exp_date,
           DATEDIFF(license_expiry, CURDATE()) AS days_left
    FROM employees WHERE license_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)
    UNION ALL
    SELECT emp_id, name_en, name_ar, 'Civil ID' AS doc_type, civil_id_expiry AS exp_date,
           DATEDIFF(civil_id_expiry, CURDATE()) AS days_left
    FROM employees WHERE civil_id_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)
    ORDER BY days_left ASC LIMIT 10
")->fetchAll();

// Recent accidents
$recentAcc = $pdo->query("
    SELECT va.*, v.plate_number, v.make, v.model, COALESCE(e.name_en,'—') AS driver_name
    FROM vehicle_accidents va
    JOIN vehicles v ON v.id = va.vehicle_id
    LEFT JOIN employees e ON e.id = va.driver_id
    ORDER BY va.accident_date DESC LIMIT 6
")->fetchAll();
?>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="kpi-card kpi-blue">
      <div class="kpi-icon"><i class="fas fa-car"></i></div>
      <div class="kpi-val"><?= $stats['total_cars'] ?></div>
      <div class="kpi-lbl"><?= t('total_cars') ?></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card kpi-orange">
      <div class="kpi-icon"><i class="fas fa-motorcycle"></i></div>
      <div class="kpi-val"><?= $stats['total_bikes'] ?></div>
      <div class="kpi-lbl"><?= t('total_bikes') ?></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card kpi-green">
      <div class="kpi-icon"><i class="fas fa-users"></i></div>
      <div class="kpi-val"><?= $stats['total_employees'] ?></div>
      <div class="kpi-lbl"><?= t('total_employees') ?></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card kpi-teal">
      <div class="kpi-icon"><i class="fas fa-link"></i></div>
      <div class="kpi-val"><?= $stats['active_assign'] ?></div>
      <div class="kpi-lbl"><?= t('assignments') ?></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card kpi-cyan">
      <div class="kpi-icon"><i class="fas fa-check-circle"></i></div>
      <div class="kpi-val"><?= $stats['active_vehicles'] ?></div>
      <div class="kpi-lbl"><?= t('active_vehicles') ?></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card kpi-yellow">
      <div class="kpi-icon"><i class="fas fa-pause-circle"></i></div>
      <div class="kpi-val"><?= $stats['inactive_vehicles'] ?></div>
      <div class="kpi-lbl"><?= t('inactive_vehicles') ?></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card kpi-yellow">
      <div class="kpi-icon"><i class="fas fa-wrench"></i></div>
      <div class="kpi-val"><?= $stats['in_service'] ?></div>
      <div class="kpi-lbl"><?= t('vehicles_in_service') ?></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card kpi-red">
      <div class="kpi-icon"><i class="fas fa-car-crash"></i></div>
      <div class="kpi-val"><?= $stats['in_accident'] ?></div>
      <div class="kpi-lbl"><?= t('accident_vehicles') ?></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card kpi-purple">
      <div class="kpi-icon"><i class="fas fa-shield-alt"></i></div>
      <div class="kpi-val"><?= count($expIns) + count($expiredIns) ?></div>
      <div class="kpi-lbl"><?= t('expiring_insurance') ?></div>
    </div>
  </div>
</div>

<?php if ($_SESSION['user_role'] === 'admin' && $onlineUsers): ?>
<!-- Online Users - Admin Only -->
<div class="row g-4 mb-4">
  <div class="col-12">
    <div class="card" style="border-left: 4px solid var(--green);">
      <div class="card-header fw-bold" style="background: rgba(5,150,105,.05);">
        <i class="fas fa-circle text-success me-2" style="font-size: 8px;"></i>
        <?= t('online_users') ?> <span class="badge bg-success ms-2"><?= count($onlineUsers) ?></span>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead>
            <tr>
              <th><?= t('name') ?></th>
              <th><?= t('username') ?></th>
              <th><?= t('role') ?></th>
              <th><?= t('last_activity') ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($onlineUsers as $u): ?>
            <tr>
              <td><?= e($lang==='ar' && $u['full_name_ar'] ? $u['full_name_ar'] : $u['full_name_en']) ?></td>
              <td><?= e($u['username']) ?></td>
              <td><span class="badge bg-<?= $u['role']==='admin'?'danger':($u['role']==='manager'?'warning':'secondary') ?>"><?= e($u['role']) ?></span></td>
              <td class="text-muted" style="font-size: 11.5px;"><?= timeAgo($u['last_activity']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="row g-4">

  <!-- Alerts column -->
  <div class="col-lg-6">

    <?php if ($expiredIns): ?>
    <div class="card alert-card border-danger mb-3">
      <div class="card-header bg-danger text-white fw-bold">
        <i class="fas fa-exclamation-triangle me-2"></i><?= t('insurance') ?> — <span class="text-warning"><?= t('expired') ?></span>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th><?= t('plate_number') ?></th><th><?= t('insurance_company') ?></th><th><?= t('expiry_date') ?></th></tr></thead>
          <tbody>
          <?php foreach ($expiredIns as $r): ?>
            <tr>
              <td><a href="?module=vehicles&action=view&id=<?= $r['vehicle_id'] ?>"><?= e($r['plate_number']) ?></a> <small class="text-muted"><?= e($r['make'].' '.$r['model']) ?></small></td>
              <td><?= e($r['insurance_company']) ?></td>
              <td><span class="badge bg-danger"><?= fmtDate($r['expiry_date']) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($expIns): ?>
    <div class="card alert-card border-warning mb-3">
      <div class="card-header bg-warning text-dark fw-bold">
        <i class="fas fa-clock me-2"></i><?= t('insurance') ?> — <?= t('expiring_in') ?> 30 <?= t('date') ?>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th><?= t('plate_number') ?></th><th><?= t('insurance_company') ?></th><th><?= t('expiry_date') ?></th></tr></thead>
          <tbody>
          <?php foreach ($expIns as $r): ?>
            <tr>
              <td><a href="?module=vehicles&action=view&id=<?= $r['vehicle_id'] ?>"><?= e($r['plate_number']) ?></a></td>
              <td><?= e($r['insurance_company']) ?></td>
              <td><span class="badge bg-warning text-dark"><?= fmtDate($r['expiry_date']) ?> (<?= $r['days_left'] ?>d)</span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($svcDue): ?>
    <div class="card alert-card border-info mb-3">
      <div class="card-header bg-info text-white fw-bold">
        <i class="fas fa-oil-can me-2"></i><?= t('service_due') ?>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th><?= t('plate_number') ?></th><th><?= t('current_km') ?></th><th><?= t('next_service_km') ?></th><th></th></tr></thead>
          <tbody>
          <?php foreach ($svcDue as $r): ?>
            <tr>
              <td><a href="?module=vehicles&action=view&id=<?= $r['id'] ?>"><?= e($r['plate_number']) ?></a> <small class="text-muted"><?= e($r['make'].' '.$r['model']) ?></small></td>
              <td><?= number_format($r['current_km']) ?></td>
              <td><?= number_format($r['next_km']) ?></td>
              <td><?= serviceAlert($r + ['next_service_km'=>$r['next_km']]) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($expDocs): ?>
    <div class="card alert-card border-secondary mb-3">
      <div class="card-header bg-secondary text-white fw-bold">
        <i class="fas fa-id-card me-2"></i><?= t('employees') ?> — <?= t('expiring_in') ?> 30 <?= t('date') ?>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th><?= t('name') ?></th><th><?= t('type') ?></th><th><?= t('expiry_date') ?></th></tr></thead>
          <tbody>
          <?php foreach ($expDocs as $r): ?>
            <tr>
              <td><?= e($r['name_en']) ?></td>
              <td><?= e($r['doc_type']) ?></td>
              <td><?= expiryBadge($r['exp_date']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!$expiredIns && !$expIns && !$svcDue && !$expDocs): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i> All clear — no alerts today!</div>
    <?php endif; ?>

  </div>

  <!-- Recent Accidents -->
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header fw-bold">
        <i class="fas fa-car-crash me-2 text-danger"></i><?= t('recent_accidents') ?>
      </div>
      <div class="card-body p-0">
        <?php if ($recentAcc): ?>
        <table class="table table-sm table-hover mb-0">
          <thead><tr><th><?= t('plate_number') ?></th><th><?= t('date') ?></th><th><?= t('damage_level') ?></th><th><?= t('status') ?></th></tr></thead>
          <tbody>
          <?php foreach ($recentAcc as $r): ?>
            <tr>
              <td><strong><?= e($r['plate_number']) ?></strong><br><small class="text-muted"><?= e($r['driver_name']) ?></small></td>
              <td><?= fmtDate($r['accident_date']) ?></td>
              <td><?= statusBadge($r['damage_level']) ?></td>
              <td><?= statusBadge($r['status']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
        <div class="p-3 text-muted text-center"><?= t('no_records') ?></div>
        <?php endif; ?>
      </div>
      <div class="card-footer text-end">
        <a href="?module=accidents" class="btn btn-sm btn-outline-danger"><?= t('view') ?> <?= t('accidents') ?></a>
      </div>
    </div>
  </div>

</div>
