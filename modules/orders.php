<?php
// modules/orders.php - Driver Order Tracking & Monthly Bonus

$pdo  = getDB();
$lang = $_SESSION['lang'] ?? 'en';
$id   = (int)($_GET['id'] ?? 0);
$view = $_GET['view'] ?? 'summary';   // summary | entries | bonuses
$action = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']))
          ? $_POST['action']
          : ($_GET['action'] ?? '');

$platforms = ['talabat', 'keeta', 'other'];

// ---- Selected month (YYYY-MM), default current ----
$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
$monthStart = $month . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));
$monthLabel = date('F Y', strtotime($monthStart));

// ============================================================
//  POST ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;

    // ---- Add / edit a daily order entry ----
    if ($action === 'add' || $action === 'edit') {
        $driverId = (int)$d['driver_id'];
        $orderDate = $d['order_date'] ?? '';
        $platform  = in_array($d['platform'] ?? '', $platforms) ? $d['platform'] : 'talabat';
        $count     = max(0, (int)($d['order_count'] ?? 0));
        $notes     = trim($d['notes'] ?? '');

        if (!$driverId || !$orderDate) {
            setFlash('danger', t('required_fields'));
        } else {
            try {
                if ($action === 'add') {
                    // Upsert: if a row for driver+date+platform exists, add to it
                    $pdo->prepare("INSERT INTO driver_orders (driver_id, order_date, platform, order_count, notes)
                                   VALUES (?, ?, ?, ?, ?)
                                   ON DUPLICATE KEY UPDATE order_count=VALUES(order_count), notes=VALUES(notes), updated_at=NOW()")
                        ->execute([$driverId, $orderDate, $platform, $count, $notes]);
                    setFlash('success', t('record_saved'));
                } else {
                    $pdo->prepare("UPDATE driver_orders SET driver_id=?, order_date=?, platform=?, order_count=?, notes=?, updated_at=NOW() WHERE id=?")
                        ->execute([$driverId, $orderDate, $platform, $count, $notes, $id]);
                    setFlash('success', t('record_updated'));
                }
            } catch (PDOException $e) {
                setFlash('danger', t('error_occurred') . ' ' . $e->getMessage());
            }
        }
        header("Location: ?module=orders&view=entries&month=$month"); exit;
    }

    // ---- Delete a daily order entry ----
    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM driver_orders WHERE id=?")->execute([$id]);
        setFlash('success', t('record_deleted'));
        header("Location: ?module=orders&view=entries&month=$month"); exit;
    }

    // ---- Generate / recalculate bonuses for the month ----
    if ($action === 'generate_bonuses') {
        if (!canEdit()) { setFlash('danger', t('error_occurred')); header("Location: ?module=orders&month=$month"); exit; }
        $rows = $pdo->prepare("SELECT e.id, e.bonus_eligible, e.monthly_order_target,
                                      COALESCE(SUM(o.order_count),0) AS total_orders
                               FROM employees e
                               LEFT JOIN driver_orders o ON o.driver_id=e.id AND o.order_date BETWEEN ? AND ?
                               WHERE e.status='active'
                               GROUP BY e.id");
        $rows->execute([$monthStart, $monthEnd]);
        $count = 0;
        foreach ($rows->fetchAll() as $r) {
            $target = driverMonthlyTarget($r['monthly_order_target']);
            $eligible = (int)$r['bonus_eligible'] === 1;
            $bonus = calculateBonus((int)$r['total_orders'], $target, $eligible);
            if ($bonus <= 0) continue; // only log drivers who earned a bonus
            $pdo->prepare("INSERT INTO driver_bonuses (driver_id, bonus_month, total_orders, target, bonus_amount, status)
                           VALUES (?, ?, ?, ?, ?, 'pending')
                           ON DUPLICATE KEY UPDATE total_orders=VALUES(total_orders), target=VALUES(target),
                                                   bonus_amount=VALUES(bonus_amount), updated_at=NOW()")
                ->execute([$r['id'], $monthStart, (int)$r['total_orders'], $target, $bonus]);
            $count++;
        }
        setFlash('success', $count . ' ' . t('bonuses_generated'));
        header("Location: ?module=orders&view=bonuses&month=$month"); exit;
    }

    // ---- Update bonus status (approve / pay / cancel) ----
    if ($action === 'bonus_status' && canEdit()) {
        $newStatus = in_array($d['status'] ?? '', ['pending','approved','paid','cancelled']) ? $d['status'] : 'pending';
        $approvedBy = $newStatus === 'approved' || $newStatus === 'paid' ? (int)$_SESSION['user_id'] : null;
        $pdo->prepare("UPDATE driver_bonuses SET status=?, approved_by=?, updated_at=NOW() WHERE id=?")
            ->execute([$newStatus, $approvedBy, $id]);
        setFlash('success', t('record_updated'));
        header("Location: ?module=orders&view=bonuses&month=$month"); exit;
    }
}

// ============================================================
//  DATA FOR VIEWS
// ============================================================
$globalTarget = (int)getSetting('bonus_monthly_target', '450');
$bonusAmount  = (float)getSetting('bonus_amount', '0');
$dailyTarget  = (int)getSetting('daily_order_target', '15');
$isBonusOn    = bonusEnabled();

// ---- Monthly summary (per driver) ----
$summary = [];
if ($view === 'summary') {
    $stmt = $pdo->prepare("
        SELECT e.id, e.emp_id, e.name_en, e.name_ar, e.platform, e.bonus_eligible, e.monthly_order_target,
               COALESCE(SUM(o.order_count),0) AS total_orders,
               COALESCE(SUM(CASE WHEN o.platform='talabat' THEN o.order_count END),0) AS talabat_orders,
               COALESCE(SUM(CASE WHEN o.platform='keeta'   THEN o.order_count END),0) AS keeta_orders,
               COALESCE(SUM(CASE WHEN o.platform='other'   THEN o.order_count END),0) AS other_orders
        FROM employees e
        LEFT JOIN driver_orders o ON o.driver_id=e.id AND o.order_date BETWEEN ? AND ?
        WHERE e.status='active'
        GROUP BY e.id
        ORDER BY total_orders DESC, e.name_en ASC
    ");
    $stmt->execute([$monthStart, $monthEnd]);
    $summary = $stmt->fetchAll();
}

// ---- Daily entries list ----
$entries = [];
$fDriver = (int)($_GET['driver_id'] ?? 0);
if ($view === 'entries') {
    $where = " FROM driver_orders o JOIN employees e ON e.id=o.driver_id
               WHERE o.order_date BETWEEN ? AND ?";
    $params = [$monthStart, $monthEnd];
    if ($fDriver) { $where .= " AND o.driver_id=?"; $params[] = $fDriver; }
    $stmt = $pdo->prepare("SELECT o.*, e.name_en, e.emp_id" . $where . " ORDER BY o.order_date DESC, e.name_en");
    $stmt->execute($params);
    $entries = $stmt->fetchAll();
}

// ---- Bonuses list ----
$bonuses = [];
$bonusTotals = ['count' => 0, 'amount' => 0];
if ($view === 'bonuses') {
    $stmt = $pdo->prepare("
        SELECT b.*, e.name_en, e.name_ar, e.emp_id, u.full_name_en AS approver
        FROM driver_bonuses b
        JOIN employees e ON e.id=b.driver_id
        LEFT JOIN users u ON u.id=b.approved_by
        WHERE b.bonus_month=?
        ORDER BY b.bonus_amount DESC, e.name_en
    ");
    $stmt->execute([$monthStart]);
    $bonuses = $stmt->fetchAll();
    foreach ($bonuses as $b) {
        if ($b['status'] !== 'cancelled') { $bonusTotals['count']++; $bonusTotals['amount'] += $b['bonus_amount']; }
    }
}

// Helper for month navigation
$prevMonth = date('Y-m', strtotime($monthStart . ' -1 month'));
$nextMonth = date('Y-m', strtotime($monthStart . ' +1 month'));
$nm = function(string $v) use ($month) { return $v === ($_GET['view'] ?? 'summary') ? 'active' : ''; };
?>

<!-- Header / month navigation -->
<div class="card mb-3">
  <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
    <div class="btn-group" role="group">
      <a href="?module=orders&view=summary&month=<?= $month ?>" class="btn btn-sm <?= ($_GET['view']??'summary')==='summary'?'btn-warning':'btn-outline-secondary' ?>"><i class="fas fa-chart-line me-1"></i><?= t('monthly_summary') ?></a>
      <a href="?module=orders&view=entries&month=<?= $month ?>" class="btn btn-sm <?= ($_GET['view']??'')==='entries'?'btn-warning':'btn-outline-secondary' ?>"><i class="fas fa-clipboard-list me-1"></i><?= t('daily_entries') ?></a>
      <a href="?module=orders&view=bonuses&month=<?= $month ?>" class="btn btn-sm <?= ($_GET['view']??'')==='bonuses'?'btn-warning':'btn-outline-secondary' ?>"><i class="fas fa-award me-1"></i><?= t('bonuses') ?></a>
    </div>
    <div class="ms-auto d-flex align-items-center gap-2">
      <a href="?module=orders&view=<?= e($_GET['view']??'summary') ?>&month=<?= $prevMonth ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-chevron-<?= $isRTL?'right':'left' ?>"></i></a>
      <form method="get" class="d-flex align-items-center gap-1">
        <input type="hidden" name="module" value="orders">
        <input type="hidden" name="view" value="<?= e($_GET['view']??'summary') ?>">
        <input type="month" name="month" value="<?= e($month) ?>" class="form-control form-control-sm" style="width:160px" onchange="this.form.submit()">
      </form>
      <a href="?module=orders&view=<?= e($_GET['view']??'summary') ?>&month=<?= $nextMonth ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-chevron-<?= $isRTL?'left':'right' ?>"></i></a>
    </div>
  </div>
</div>

<!-- Bonus policy banner -->
<div class="alert <?= $isBonusOn ? 'alert-success' : 'alert-secondary' ?> d-flex flex-wrap align-items-center gap-3 py-2">
  <span><i class="fas fa-award me-1"></i><strong><?= t('bonus_policy') ?>:</strong></span>
  <?php if ($isBonusOn): ?>
    <span><?= t('bonus_monthly_target') ?>: <strong><?= number_format($globalTarget) ?></strong> <?= t('orders') ?></span>
    <span><?= t('bonus_amount') ?>: <strong><?= number_format($bonusAmount, 3) ?> KWD</strong></span>
    <span class="badge bg-success"><?= t('enabled') ?></span>
  <?php else: ?>
    <span class="badge bg-secondary"><?= t('disabled') ?></span>
  <?php endif; ?>
  <?php if (isAdmin()): ?><a href="?module=settings" class="btn btn-xs btn-outline-primary ms-auto"><i class="fas fa-cog me-1"></i><?= t('settings') ?></a><?php endif; ?>
</div>

<?php if (($_GET['view'] ?? 'summary') === 'summary'): ?>
<!-- ===================== MONTHLY SUMMARY ===================== -->
<div class="d-flex justify-content-between align-items-center mb-2">
  <h5 class="mb-0"><i class="fas fa-calendar me-2"></i><?= e($monthLabel) ?></h5>
  <?php if (canEdit() && $isBonusOn): ?>
  <form method="post" action="?module=orders&action=generate_bonuses&month=<?= $month ?>" onsubmit="return confirm('<?= t('confirm_generate_bonuses') ?>')">
    <input type="hidden" name="action" value="generate_bonuses">
    <button class="btn btn-sm btn-success"><i class="fas fa-magic me-1"></i><?= t('generate_bonuses') ?></button>
  </form>
  <?php endif; ?>
</div>
<div class="card">
  <div class="card-body p-0 table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-dark">
        <tr>
          <th><?= t('driver') ?></th>
          <th class="text-center">Talabat</th>
          <th class="text-center">Keeta</th>
          <th class="text-center"><?= t('other') ?></th>
          <th class="text-center"><?= t('total') ?></th>
          <th><?= t('target') ?> / <?= t('progress') ?></th>
          <th class="text-center"><?= t('bonus') ?></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$summary): ?>
        <tr><td colspan="7" class="text-center text-muted py-3"><?= t('no_records') ?></td></tr>
      <?php endif; ?>
      <?php foreach ($summary as $s):
        $total = (int)$s['total_orders'];
        $target = driverMonthlyTarget($s['monthly_order_target']);
        $eligible = (int)$s['bonus_eligible'] === 1;
        $bonus = calculateBonus($total, $target, $eligible);
        $pct = $target > 0 ? min(100, round($total / $target * 100)) : 0;
        $barColor = $pct >= 100 ? 'bg-success' : ($pct >= 70 ? 'bg-warning' : 'bg-danger');
      ?>
        <tr>
          <td>
            <strong><?= e($lang==='ar' && $s['name_ar'] ? $s['name_ar'] : $s['name_en']) ?></strong>
            <small class="text-muted">[<?= e($s['emp_id']) ?>]</small>
            <?php if (!$eligible): ?><span class="badge bg-light text-muted border" title="<?= t('not_bonus_eligible') ?>"><i class="fas fa-ban"></i></span><?php endif; ?>
          </td>
          <td class="text-center"><?= number_format($s['talabat_orders']) ?></td>
          <td class="text-center"><?= number_format($s['keeta_orders']) ?></td>
          <td class="text-center"><?= number_format($s['other_orders']) ?></td>
          <td class="text-center"><strong style="font-family:'Oxanium',sans-serif;font-size:15px"><?= number_format($total) ?></strong></td>
          <td style="min-width:180px">
            <div class="d-flex justify-content-between small text-muted"><span><?= number_format($total) ?>/<?= number_format($target) ?></span><span><?= $pct ?>%</span></div>
            <div class="progress" style="height:7px"><div class="progress-bar <?= $barColor ?>" style="width:<?= $pct ?>%"></div></div>
          </td>
          <td class="text-center">
            <?php if ($bonus > 0): ?>
              <span class="badge bg-success" style="font-size:12px"><?= number_format($bonus, 3) ?> KWD</span>
            <?php elseif ($eligible && $isBonusOn): ?>
              <span class="text-muted small"><?= $target - $total ?> <?= t('to_go') ?></span>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif (($_GET['view'] ?? '') === 'entries'): ?>
<!-- ===================== DAILY ENTRIES ===================== -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="get" class="row g-2 align-items-end">
    <input type="hidden" name="module" value="orders">
    <input type="hidden" name="view" value="entries">
    <input type="hidden" name="month" value="<?= e($month) ?>">
    <div class="col-auto">
      <select name="driver_id" class="form-select form-select-sm">
        <option value="0"><?= t('all') ?> <?= t('drivers') ?></option>
        <?= employeeOptions($fDriver ?: null) ?>
      </select>
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-primary"><?= t('filter') ?></button></div>
    <div class="col-auto ms-auto"><button type="button" class="btn btn-sm btn-success" onclick="openAddModal()"><i class="fas fa-plus me-1"></i><?= t('add_order') ?></button></div>
  </form>
</div></div>

<div class="card"><div class="card-body p-0 table-responsive">
  <table class="table table-hover align-middle mb-0">
    <thead class="table-dark"><tr>
      <th><?= t('date') ?></th><th><?= t('driver') ?></th><th><?= t('platform') ?></th>
      <th class="text-center"><?= t('orders') ?></th><th><?= t('notes') ?></th><th><?= t('actions') ?></th>
    </tr></thead>
    <tbody>
    <?php if (!$entries): ?><tr><td colspan="6" class="text-center text-muted py-3"><?= t('no_records') ?></td></tr><?php endif; ?>
    <?php foreach ($entries as $en): ?>
      <tr>
        <td><?= fmtDate($en['order_date']) ?></td>
        <td><?= e($en['name_en']) ?> <small class="text-muted">[<?= e($en['emp_id']) ?>]</small></td>
        <td><?= platformBadge($en['platform']) ?></td>
        <td class="text-center"><strong><?= number_format($en['order_count']) ?></strong></td>
        <td class="small text-muted"><?= e($en['notes'] ?: '—') ?></td>
        <td>
          <button class="btn btn-xs btn-outline-primary" onclick='openEditModal(<?= json_encode($en, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button>
          <button class="btn btn-xs btn-outline-danger" onclick="confirmDelete(<?= $en['id'] ?>)"><i class="fas fa-trash"></i></button>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div>

<!-- Add/Edit modal -->
<div class="modal fade" id="orderModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <form method="post" id="orderForm">
    <input type="hidden" name="action" id="fAction" value="add">
    <input type="hidden" name="id" id="fId" value="">
    <div class="modal-header bg-warning"><h5 class="modal-title" id="modalTitle"><?= t('add_order') ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-3">
      <div class="col-12"><label class="form-label"><?= t('driver') ?> *</label><select name="driver_id" id="fDriver" class="form-select" required><?= employeeOptions() ?></select></div>
      <div class="col-md-6"><label class="form-label"><?= t('date') ?> *</label><input type="date" name="order_date" id="fDate" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
      <div class="col-md-6"><label class="form-label"><?= t('platform') ?> *</label>
        <select name="platform" id="fPlatform" class="form-select" required>
          <option value="talabat">Talabat</option>
          <option value="keeta">Keeta</option>
          <option value="other"><?= t('other') ?></option>
        </select>
      </div>
      <div class="col-md-6"><label class="form-label"><?= t('orders') ?> *</label><input type="number" min="0" name="order_count" id="fCount" class="form-control" required></div>
      <div class="col-12"><label class="form-label"><?= t('notes') ?></label><input type="text" name="notes" id="fNotes" class="form-control"></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('cancel') ?></button><button class="btn btn-warning"><?= t('save') ?></button></div>
  </form>
</div></div></div>
<form id="deleteForm" method="post"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="deleteId"></form>

<script>
function openAddModal(){
  document.getElementById('fAction').value='add';document.getElementById('fId').value='';
  document.getElementById('modalTitle').textContent='<?= t('add_order') ?>';
  document.getElementById('orderForm').reset();document.getElementById('fDate').value='<?= date('Y-m-d') ?>';
  new bootstrap.Modal(document.getElementById('orderModal')).show();
}
function openEditModal(r){
  document.getElementById('fAction').value='edit';document.getElementById('fId').value=r.id;
  document.getElementById('modalTitle').textContent='<?= t('edit_order') ?>';
  document.getElementById('fDriver').value=r.driver_id;document.getElementById('fDate').value=r.order_date;
  document.getElementById('fPlatform').value=r.platform;document.getElementById('fCount').value=r.order_count;
  document.getElementById('fNotes').value=r.notes||'';
  new bootstrap.Modal(document.getElementById('orderModal')).show();
}
function confirmDelete(id){if(confirm('<?= t('confirm_delete') ?>')){document.getElementById('deleteId').value=id;const f=document.getElementById('deleteForm');f.action='?module=orders&action=delete&month=<?= $month ?>&id='+id;f.submit();}}
document.getElementById('orderForm').addEventListener('submit',function(){this.action='?module=orders&action='+document.getElementById('fAction').value+'&month=<?= $month ?>'+(document.getElementById('fId').value?'&id='+document.getElementById('fId').value:'');});
</script>

<?php elseif (($_GET['view'] ?? '') === 'bonuses'): ?>
<!-- ===================== BONUSES ===================== -->
<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="card bg-success text-white"><div class="card-body py-3"><h6 class="mb-1"><?= t('total_bonus') ?> — <?= e($monthLabel) ?></h6><h3 class="mb-0"><?= number_format($bonusTotals['amount'], 3) ?> KWD</h3></div></div></div>
  <div class="col-md-4"><div class="card bg-primary text-white"><div class="card-body py-3"><h6 class="mb-1"><?= t('drivers') ?></h6><h3 class="mb-0"><?= $bonusTotals['count'] ?></h3></div></div></div>
</div>
<div class="card"><div class="card-body p-0 table-responsive">
  <table class="table table-hover align-middle mb-0">
    <thead class="table-dark"><tr>
      <th><?= t('driver') ?></th><th class="text-center"><?= t('total') ?> <?= t('orders') ?></th>
      <th class="text-center"><?= t('target') ?></th><th class="text-center"><?= t('bonus') ?></th>
      <th><?= t('status') ?></th><th><?= t('actions') ?></th>
    </tr></thead>
    <tbody>
    <?php if (!$bonuses): ?><tr><td colspan="6" class="text-center text-muted py-3"><?= t('no_bonuses_generated') ?></td></tr><?php endif; ?>
    <?php foreach ($bonuses as $b):
      $sc = ['pending'=>'warning','approved'=>'info','paid'=>'success','cancelled'=>'secondary'][$b['status']] ?? 'secondary';
    ?>
      <tr>
        <td><strong><?= e($lang==='ar' && $b['name_ar'] ? $b['name_ar'] : $b['name_en']) ?></strong> <small class="text-muted">[<?= e($b['emp_id']) ?>]</small></td>
        <td class="text-center"><?= number_format($b['total_orders']) ?></td>
        <td class="text-center"><?= number_format($b['target']) ?></td>
        <td class="text-center"><span class="badge bg-success"><?= number_format($b['bonus_amount'], 3) ?> KWD</span></td>
        <td><span class="badge bg-<?= $sc ?>"><?= t($b['status']) ?></span><?php if ($b['approver']): ?><br><small class="text-muted"><?= e($b['approver']) ?></small><?php endif; ?></td>
        <td>
          <?php if (canEdit()): ?>
          <div class="btn-group btn-group-sm">
            <?php if ($b['status'] !== 'approved'): ?><button class="btn btn-xs btn-outline-info" onclick="setStatus(<?= $b['id'] ?>,'approved')" title="<?= t('approve') ?>"><i class="fas fa-check"></i></button><?php endif; ?>
            <?php if ($b['status'] !== 'paid'): ?><button class="btn btn-xs btn-outline-success" onclick="setStatus(<?= $b['id'] ?>,'paid')" title="<?= t('mark_paid') ?>"><i class="fas fa-money-bill"></i></button><?php endif; ?>
            <?php if ($b['status'] !== 'cancelled'): ?><button class="btn btn-xs btn-outline-danger" onclick="setStatus(<?= $b['id'] ?>,'cancelled')" title="<?= t('cancel') ?>"><i class="fas fa-times"></i></button><?php endif; ?>
          </div>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
<form id="statusForm" method="post"><input type="hidden" name="action" value="bonus_status"><input type="hidden" name="id" id="stId"><input type="hidden" name="status" id="stStatus"></form>
<script>
function setStatus(id,status){document.getElementById('stId').value=id;document.getElementById('stStatus').value=status;const f=document.getElementById('statusForm');f.action='?module=orders&action=bonus_status&view=bonuses&month=<?= $month ?>&id='+id;f.submit();}
</script>
<?php endif; ?>
