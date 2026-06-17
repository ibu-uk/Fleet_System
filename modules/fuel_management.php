<?php
// modules/fuel_management.php - Fuel Management Module

$pdo = getDB();
$id = (int)($_GET['id'] ?? 0);
$action = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']))
          ? $_POST['action']
          : ($_GET['action'] ?? 'list');

$fuelTypes = ['petrol', 'diesel', 'cng', 'electric'];

// ---- POST Actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;
    
    if ($action === 'add' || $action === 'edit') {
        $vid = (int)$d['vehicle_id'];
        $did = $d['driver_id'] ?: null;
        $liters = (float)$d['liters'];
        $pricePerLiter = (float)$d['price_per_liter'];
        $totalCost = $liters * $pricePerLiter;
        
        $fields = [
            'vehicle_id' => $vid,
            'driver_id' => $did,
            'card_number' => trim($d['card_number'] ?? ''),
            'fuel_date' => $d['fuel_date'],
            'fuel_type' => $d['fuel_type'],
            'liters' => $liters,
            'price_per_liter' => $pricePerLiter,
            'total_cost' => $totalCost,
            'station_name' => trim($d['station_name'] ?? ''),
            'station_location' => trim($d['station_location'] ?? ''),
            'odometer' => $d['odometer'] ?: null,
            'full_tank' => (int)($d['full_tank'] ?? 0),
            'notes' => trim($d['notes'] ?? ''),
        ];
        
        if (!$vid || !$fields['fuel_date'] || !$fields['liters'] || !$fields['price_per_liter']) {
            setFlash('danger', t('required_fields'));
        } else {
            try {
                if ($action === 'add') {
                    $cols = implode(',', array_keys($fields));
                    $vals = implode(',', array_fill(0, count($fields), '?'));
                    $pdo->prepare("INSERT INTO fuel_records ($cols) VALUES ($vals)")->execute(array_values($fields));
                    setFlash('success', t('record_saved'));
                } else {
                    $set = implode('=?,', array_keys($fields)) . '=?';
                    $pdo->prepare("UPDATE fuel_records SET $set WHERE id=?")->execute([...array_values($fields), $id]);
                    setFlash('success', t('record_updated'));
                }
            } catch (PDOException $e) {
                setFlash('danger', t('error_occurred') . ' ' . $e->getMessage());
            }
        }
        header("Location: ?module=fuel_management"); exit;
    }
    
    if ($action === 'delete') {
        try {
            $pdo->prepare("DELETE FROM fuel_records WHERE id=?")->execute([$id]);
            setFlash('success', t('record_deleted'));
        } catch (PDOException $e) {
            setFlash('danger', t('error_occurred'));
        }
        header("Location: ?module=fuel_management"); exit;
    }
}

// ---- Filters ----
$search = trim($_GET['q'] ?? '');
$fVehicle = (int)($_GET['vehicle_id'] ?? 0);
$fDriver = (int)($_GET['driver_id'] ?? 0);
$fType = $_GET['fuel_type'] ?? '';
$fDateFrom = $_GET['date_from'] ?? '';
$fDateTo = $_GET['date_to'] ?? '';

// ---- Pagination ----
$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;
$totalPages = 1;

// ---- LIST ----
if ($action === 'list') {
    $baseWhere = " FROM fuel_records fr JOIN vehicles v ON v.id = fr.vehicle_id LEFT JOIN employees e ON e.id = fr.driver_id WHERE 1=1";
    $params = [];
    if ($fVehicle)  { $baseWhere .= " AND fr.vehicle_id=?"; $params[] = $fVehicle; }
    if ($fDriver)   { $baseWhere .= " AND fr.driver_id=?"; $params[] = $fDriver; }
    if ($fType)     { $baseWhere .= " AND fr.fuel_type=?"; $params[] = $fType; }
    if ($fDateFrom) { $baseWhere .= " AND fr.fuel_date >= ?"; $params[] = $fDateFrom; }
    if ($fDateTo)   { $baseWhere .= " AND fr.fuel_date <= ?"; $params[] = $fDateTo; }
    if ($search) {
        $baseWhere .= " AND (v.plate_number LIKE ? OR fr.station_name LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    $cStmt = $pdo->prepare("SELECT COUNT(*) " . $baseWhere); $cStmt->execute($params);
    $totalRows  = (int)$cStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    
    $sql = "SELECT fr.*, v.plate_number, v.make, v.model, e.name_en AS driver_name" . $baseWhere;
    $sql .= " ORDER BY fr.fuel_date DESC, fr.id DESC LIMIT $perPage OFFSET $offset";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();
    
    // Calculate consumption stats
    $statsSql = "SELECT 
                    SUM(fr.liters) as total_liters,
                    SUM(fr.total_cost) as total_cost,
                    COUNT(*) as total_records
                 FROM fuel_records fr
                 WHERE 1=1";
    $sParams = [];
    if ($fVehicle) { $statsSql .= " AND fr.vehicle_id=?"; $sParams[] = $fVehicle; }
    if ($fDateFrom) { $statsSql .= " AND fr.fuel_date >= ?"; $sParams[] = $fDateFrom; }
    if ($fDateTo) { $statsSql .= " AND fr.fuel_date <= ?"; $sParams[] = $fDateTo; }
    
    $sStmt = $pdo->prepare($statsSql);
    $sStmt->execute($sParams);
    $stats = $sStmt->fetch();

    // Monthly fuel utilization by petrol card
    $cardMonth = $_GET['card_month'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $cardMonth)) $cardMonth = date('Y-m');
    $cardStart = $cardMonth . '-01';
    $cardEnd   = date('Y-m-t', strtotime($cardStart));

    $cardSql = "SELECT fr.card_number, e.name_en AS driver_name,
                       SUM(fr.liters) AS total_liters,
                       SUM(fr.total_cost) AS total_cost,
                       COUNT(*) AS fill_count
                FROM fuel_records fr
                LEFT JOIN employees e ON e.id = fr.driver_id
                WHERE fr.fuel_date BETWEEN ? AND ? AND fr.card_number IS NOT NULL AND fr.card_number != ''
                GROUP BY fr.card_number, e.name_en
                ORDER BY total_cost DESC";
    $cStmt = $pdo->prepare($cardSql);
    $cStmt->execute([$cardStart, $cardEnd]);
    $cardStats = $cStmt->fetchAll();
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h3><i class="fas fa-gas-pump me-2"></i><?= t('fuel_management') ?></h3>
  <button class="btn btn-primary" onclick="openAddModal()">
    <i class="fas fa-plus me-1"></i><?= t('add_fuel_record') ?>
  </button>
</div>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="card bg-primary text-white">
      <div class="card-body">
        <h6 class="card-title"><?= t('total_liters') ?></h6>
        <h3><?= number_format($stats['total_liters'] ?? 0, 2) ?> L</h3>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card bg-success text-white">
      <div class="card-body">
        <h6 class="card-title"><?= t('total_cost') ?></h6>
        <h3>KWD <?= number_format($stats['total_cost'] ?? 0, 3) ?></h3>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card bg-info text-white">
      <div class="card-body">
        <h6 class="card-title"><?= t('total_records') ?></h6>
        <h3><?= number_format($stats['total_records'] ?? 0) ?></h3>
      </div>
    </div>
  </div>
</div>

<!-- Monthly Per-Card Fuel Utilization -->
<div class="card mb-4">
  <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
    <span><i class="fas fa-credit-card me-2"></i><?= t('petrol_card_utilization') ?> — <?= date('F Y', strtotime($cardStart)) ?></span>
    <form method="get" class="d-flex align-items-center gap-2">
      <input type="hidden" name="module" value="fuel_management">
      <input type="month" name="card_month" value="<?= e($cardMonth) ?>" class="form-control form-control-sm" style="width:140px" onchange="this.form.submit()">
    </form>
  </div>
  <div class="card-body p-0 table-responsive">
    <table class="table table-sm mb-0">
      <thead><tr><th><?= t('petrol_card_number') ?></th><th><?= t('driver') ?></th><th class="text-end"><?= t('liters') ?></th><th class="text-end"><?= t('total_cost') ?></th><th class="text-center"><?= t('fills') ?></th></tr></thead>
      <tbody>
      <?php if (!$cardStats): ?><tr><td colspan="5" class="text-center text-muted py-2"><?= t('no_records') ?></td></tr><?php endif; ?>
      <?php foreach ($cardStats as $c): ?>
        <tr>
          <td><span class="badge bg-dark"><?= e($c['card_number']) ?></span></td>
          <td><?= e($c['driver_name'] ?: '—') ?></td>
          <td class="text-end"><?= number_format($c['total_liters'], 2) ?> L</td>
          <td class="text-end"><strong>KWD <?= number_format($c['total_cost'], 3) ?></strong></td>
          <td class="text-center"><?= $c['fill_count'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Filters -->
<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-3">
      <input type="hidden" name="module" value="fuel_management">
      <div class="col-md-2">
        <label class="form-label"><?= t('vehicles') ?></label>
        <select name="vehicle_id" class="form-select">
          <?= vehicleOptions($fVehicle) ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label"><?= t('drivers') ?></label>
        <select name="driver_id" class="form-select">
          <?= employeeOptions($fDriver) ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label"><?= t('fuel_type') ?></label>
        <select name="fuel_type" class="form-select">
          <option value=""><?= t('all') ?></option>
          <?php foreach ($fuelTypes as $ft): ?>
          <option value="<?= $ft ?>" <?= $fType === $ft ? 'selected' : '' ?>><?= t($ft) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label"><?= t('date_from') ?></label>
        <input type="date" name="date_from" class="form-control" value="<?= e($fDateFrom) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label"><?= t('date_to') ?></label>
        <input type="date" name="date_to" class="form-control" value="<?= e($fDateTo) ?>">
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button type="submit" class="btn btn-primary me-2"><?= t('filter') ?></button>
        <a href="?module=fuel_management" class="btn btn-secondary"><?= t('reset') ?></a>
      </div>
    </form>
  </div>
</div>

<!-- Records Table -->
<div class="card">
  <div class="card-header bg-success text-white">
    <i class="fas fa-list me-2"></i><?= t('fuel_records') ?>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th><?= t('date') ?></th>
            <th><?= t('plate_number') ?></th>
            <th><?= t('driver') ?></th>
            <th><?= t('petrol_card_number') ?></th>
            <th><?= t('fuel_type') ?></th>
            <th><?= t('liters') ?></th>
            <th><?= t('price_per_liter') ?></th>
            <th><?= t('total_cost') ?></th>
            <th><?= t('station') ?></th>
            <th><?= t('odometer') ?></th>
            <th><?= t('actions') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($records as $r): ?>
          <tr>
            <td><?= fmtDate($r['fuel_date']) ?></td>
            <td><strong><?= e($r['plate_number']) ?></strong></td>
            <td><?= e($r['driver_name'] ?: '—') ?></td>
            <td><?= $r['card_number'] ? '<span class="badge bg-dark">'.e($r['card_number']).'</span>' : '<span class="text-muted">—</span>' ?></td>
            <td><span class="badge bg-secondary"><?= t($r['fuel_type']) ?></span></td>
            <td><?= number_format($r['liters'], 2) ?> L</td>
            <td>KWD <?= number_format($r['price_per_liter'], 3) ?></td>
            <td><strong>KWD <?= number_format($r['total_cost'], 3) ?></strong></td>
            <td><?= e($r['station_name'] ?: '—') ?></td>
            <td><?= $r['odometer'] ? number_format($r['odometer']) . ' km' : '—' ?></td>
            <td>
              <button class="btn btn-xs btn-outline-primary" onclick="openEditModal(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)">
                <i class="fas fa-edit"></i>
              </button>
              <button class="btn btn-xs btn-outline-danger" onclick="confirmDelete(<?= $r['id'] ?>, '<?= fmtDate($r['fuel_date']) ?>')">
                <i class="fas fa-trash"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$records): ?>
          <tr><td colspan="11" class="text-center text-muted py-3"><?= t('no_records') ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="fuelModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" id="fAction" value="add">
        <input type="hidden" name="id" id="fId" value="">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title" id="modalTitle"><?= t('add_fuel_record') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label"><?= t('vehicles') ?> *</label>
              <select name="vehicle_id" id="fVehicle" class="form-select" required>
                <?= vehicleOptions() ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('drivers') ?></label>
              <select name="driver_id" id="fDriver" class="form-select" onchange="fillCard()">
                <?= employeeOptions() ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('petrol_card_number') ?></label>
              <input name="card_number" id="fCard" type="text" class="form-control" placeholder="<?= t('petrol_card_number') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('fuel_date') ?> *</label>
              <input name="fuel_date" id="fDate" type="date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('fuel_type') ?> *</label>
              <select name="fuel_type" id="fType" class="form-select" required>
                <?php foreach ($fuelTypes as $ft): ?>
                <option value="<?= $ft ?>"><?= t($ft) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label"><?= t('liters') ?> *</label>
              <input name="liters" id="fLiters" type="number" step="0.01" class="form-control" required oninput="calcTotal()">
            </div>
            <div class="col-md-4">
              <label class="form-label"><?= t('price_per_liter') ?> *</label>
              <input name="price_per_liter" id="fPrice" type="number" step="0.001" class="form-control" required oninput="calcTotal()">
            </div>
            <div class="col-md-4">
              <label class="form-label"><?= t('total_cost') ?></label>
              <input name="total_cost" id="fTotal" type="number" step="0.001" class="form-control" readonly>
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('station_name') ?></label>
              <input name="station_name" id="fStation" type="text" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('station_location') ?></label>
              <input name="station_location" id="fLocation" type="text" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('odometer') ?></label>
              <input name="odometer" id="fOdometer" type="number" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">&nbsp;</label>
              <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" name="full_tank" id="fFullTank" value="1">
                <label class="form-check-label" for="fFullTank"><?= t('full_tank') ?></label>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label"><?= t('notes') ?></label>
              <textarea name="notes" id="fNotes" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('cancel') ?></button>
          <button type="submit" class="btn btn-primary"><?= t('save') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3">
  <ul class="pagination pagination-sm justify-content-center">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
      <a class="page-link" href="?module=fuel_management&page=<?= $p ?>&vehicle_id=<?= $fVehicle ?>&driver_id=<?= $fDriver ?>&fuel_type=<?= e($fType) ?>&date_from=<?= e($fDateFrom) ?>&date_to=<?= e($fDateTo) ?>&q=<?= e($search) ?>"><?= $p ?></a>
    </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>

<form id="deleteForm" method="POST">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="deleteId">
</form>

<script>
function calcTotal(){
  const liters=parseFloat(document.getElementById('fLiters').value)||0;
  const price=parseFloat(document.getElementById('fPrice').value)||0;
  document.getElementById('fTotal').value=(liters*price).toFixed(3);
}
function fillCard(){
  const sel=document.getElementById('fDriver');
  const opt=sel.options[sel.selectedIndex];
  const card=opt?opt.getAttribute('data-card'):'';
  if(card)document.getElementById('fCard').value=card;
}
function openAddModal(){
  document.getElementById('fAction').value='add';
  document.getElementById('fId').value='';
  document.getElementById('modalTitle').textContent='<?= t('add_fuel_record') ?>';
  document.getElementById('fVehicle').value='';
  document.getElementById('fDriver').value='';
  document.getElementById('fCard').value='';
  document.getElementById('fDate').value='<?= date('Y-m-d') ?>';
  document.getElementById('fType').value='petrol';
  document.getElementById('fLiters').value='';
  document.getElementById('fPrice').value='';
  document.getElementById('fTotal').value='';
  document.getElementById('fStation').value='';
  document.getElementById('fLocation').value='';
  document.getElementById('fOdometer').value='';
  document.getElementById('fFullTank').checked=false;
  document.getElementById('fNotes').value='';
  new bootstrap.Modal(document.getElementById('fuelModal')).show();
}
function openEditModal(r){
  document.getElementById('fAction').value='edit';
  document.getElementById('fId').value=r.id;
  document.getElementById('modalTitle').textContent='<?= t('edit_fuel_record') ?>';
  document.getElementById('fVehicle').value=r.vehicle_id;
  document.getElementById('fDriver').value=r.driver_id||'';
  document.getElementById('fCard').value=r.card_number||'';
  document.getElementById('fDate').value=r.fuel_date;
  document.getElementById('fType').value=r.fuel_type;
  document.getElementById('fLiters').value=r.liters;
  document.getElementById('fPrice').value=r.price_per_liter;
  document.getElementById('fTotal').value=r.total_cost;
  document.getElementById('fStation').value=r.station_name||'';
  document.getElementById('fLocation').value=r.station_location||'';
  document.getElementById('fOdometer').value=r.odometer||'';
  document.getElementById('fFullTank').checked=r.full_tank==1;
  document.getElementById('fNotes').value=r.notes||'';
  new bootstrap.Modal(document.getElementById('fuelModal')).show();
}
function confirmDelete(id,date){
  if(confirm('<?= t('confirm_delete') ?>\n'+date)){
    document.getElementById('deleteId').value=id;
    document.getElementById('deleteForm').submit();
  }
}
</script>
