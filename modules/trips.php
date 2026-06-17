<?php
// modules/trips.php - Trip Management Module

$pdo = getDB();
$id = (int)($_GET['id'] ?? 0);
$action = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']))
          ? $_POST['action']
          : ($_GET['action'] ?? 'list');

$tripStatuses = ['planned', 'in_progress', 'completed', 'cancelled'];
$expenseTypes = ['fuel', 'toll', 'parking', 'repair', 'other'];

// ---- GET-only state changes ----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'start_trip' && $id) {
        try {
            $pdo->prepare("UPDATE trips SET status='in_progress', start_time=NOW() WHERE id=?")->execute([$id]);
            setFlash('success', t('trip_started'));
        } catch (PDOException $e) { setFlash('danger', t('error_occurred')); }
        header("Location: ?module=trips"); exit;
    }
    if ($action === 'delete_expense' && $id) {
        $tripId = (int)($_GET['trip_id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM trip_expenses WHERE id=?")->execute([$id]);
            setFlash('success', t('record_deleted'));
        } catch (PDOException $e) { setFlash('danger', t('error_occurred')); }
        header("Location: ?module=trips&action=view&id=" . $tripId); exit;
    }
}

// ---- POST Actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;
    
    if ($action === 'add' || $action === 'edit') {
        $tripNumber = $d['trip_number'] ?? 'TRIP-' . date('Ymd-His');
        
        $fields = [
            'vehicle_id' => (int)$d['vehicle_id'],
            'driver_id' => (int)$d['driver_id'],
            'trip_number' => $tripNumber,
            'start_time' => $d['start_time'],
            'end_time' => $d['end_time'] ?: null,
            'start_location' => trim($d['start_location'] ?? ''),
            'end_location' => trim($d['end_location'] ?? ''),
            'start_odometer' => $d['start_odometer'] ?: null,
            'end_odometer' => $d['end_odometer'] ?: null,
            'distance_km' => $d['distance_km'] ?: null,
            'purpose' => trim($d['purpose'] ?? ''),
            'status' => $d['status'] ?? 'planned',
            'notes' => trim($d['notes'] ?? ''),
        ];
        
        if (!$fields['vehicle_id'] || !$fields['driver_id'] || !$fields['start_time']) {
            setFlash('danger', t('required_fields'));
        } else {
            try {
                if ($action === 'add') {
                    $cols = implode(',', array_keys($fields));
                    $vals = implode(',', array_fill(0, count($fields), '?'));
                    $pdo->prepare("INSERT INTO trips ($cols) VALUES ($vals)")->execute(array_values($fields));
                    setFlash('success', t('record_saved'));
                } else {
                    $set = implode('=?,', array_keys($fields)) . '=?';
                    $pdo->prepare("UPDATE trips SET $set WHERE id=?")->execute([...array_values($fields), $id]);
                    setFlash('success', t('record_updated'));
                }
            } catch (PDOException $e) {
                setFlash('danger', t('error_occurred') . ' ' . $e->getMessage());
            }
        }
        header("Location: ?module=trips"); exit;
    }
    
    if ($action === 'end_trip') {
        try {
            $endOdometer = (int)($_POST['end_odometer'] ?? 0);
            $endLocation = trim($_POST['end_location'] ?? '');
            $pdo->prepare("UPDATE trips SET status='completed', end_time=NOW(), end_odometer=?, end_location=?, distance_km=end_odometer-start_odometer WHERE id=?")
                ->execute([$endOdometer, $endLocation, $id]);
            setFlash('success', t('trip_completed'));
        } catch (PDOException $e) {
            setFlash('danger', t('error_occurred'));
        }
        header("Location: ?module=trips"); exit;
    }
    
    if ($action === 'delete') {
        try {
            $pdo->prepare("DELETE FROM trips WHERE id=?")->execute([$id]);
            setFlash('success', t('record_deleted'));
        } catch (PDOException $e) {
            setFlash('danger', t('error_occurred'));
        }
        header("Location: ?module=trips"); exit;
    }
    
    if ($action === 'add_expense') {
        $expenseFields = [
            'trip_id' => (int)$d['trip_id'],
            'expense_type' => $d['expense_type'],
            'amount' => (float)$d['amount'],
            'currency' => 'KWD',
            'expense_date' => $d['expense_date'],
            'description' => trim($d['description'] ?? ''),
            'notes' => trim($d['notes'] ?? ''),
        ];
        
        if (!$expenseFields['trip_id'] || !$expenseFields['amount'] || !$expenseFields['expense_date']) {
            setFlash('danger', t('required_fields'));
        } else {
            try {
                $cols = implode(',', array_keys($expenseFields));
                $vals = implode(',', array_fill(0, count($expenseFields), '?'));
                $pdo->prepare("INSERT INTO trip_expenses ($cols) VALUES ($vals)")->execute(array_values($expenseFields));
                setFlash('success', t('record_saved'));
            } catch (PDOException $e) {
                setFlash('danger', t('error_occurred') . ' ' . $e->getMessage());
            }
        }
        header("Location: ?module=trips&action=view&id=" . $expenseFields['trip_id']); exit;
    }
    
}

// ---- Filters ----
$fVehicle = (int)($_GET['vehicle_id'] ?? 0);
$fDriver = (int)($_GET['driver_id'] ?? 0);
$fStatus = $_GET['status'] ?? '';
$fDateFrom = $_GET['date_from'] ?? '';
$fDateTo = $_GET['date_to'] ?? '';

// ---- Pagination ----
$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;
$totalPages = 1;

// ---- LIST ----
if ($action === 'list') {
    $baseWhere = " FROM trips t JOIN vehicles v ON v.id = t.vehicle_id JOIN employees e ON e.id = t.driver_id WHERE 1=1";
    $params = [];
    if ($fVehicle)  { $baseWhere .= " AND t.vehicle_id=?"; $params[] = $fVehicle; }
    if ($fDriver)   { $baseWhere .= " AND t.driver_id=?"; $params[] = $fDriver; }
    if ($fStatus)   { $baseWhere .= " AND t.status=?"; $params[] = $fStatus; }
    if ($fDateFrom) { $baseWhere .= " AND t.start_time >= ?"; $params[] = $fDateFrom; }
    if ($fDateTo)   { $baseWhere .= " AND t.start_time <= ?"; $params[] = $fDateTo; }
    
    $cStmt = $pdo->prepare("SELECT COUNT(*) " . $baseWhere); $cStmt->execute($params);
    $totalRows  = (int)$cStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    
    $sql = "SELECT t.*, v.plate_number, v.make, v.model, e.name_en AS driver_name" . $baseWhere;
    $sql .= " ORDER BY t.start_time DESC LIMIT $perPage OFFSET $offset";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $trips = $stmt->fetchAll();
}

// ---- VIEW TRIP ----
if ($action === 'view') {
    $stmt = $pdo->prepare("SELECT t.*, v.plate_number, v.make, v.model, e.name_en AS driver_name
                           FROM trips t
                           JOIN vehicles v ON v.id = t.vehicle_id
                           JOIN employees e ON e.id = t.driver_id
                           WHERE t.id=?");
    $stmt->execute([$id]);
    $trip = $stmt->fetch();
    
    if ($trip) {
        $estmt = $pdo->prepare("SELECT * FROM trip_expenses WHERE trip_id=? ORDER BY expense_date DESC");
        $estmt->execute([$id]);
        $expenses = $estmt->fetchAll();
        
        $totalExpenses = array_sum(array_column($expenses, 'amount'));
    }
}
?>

<?php if ($action === 'view'): ?>
<!-- Trip Detail View -->
<div class="d-flex justify-content-between align-items-center mb-4">
  <h3><i class="fas fa-route me-2"></i><?= t('trip_details') ?>: <?= e($trip['trip_number']) ?></h3>
  <a href="?module=trips" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i><?= t('back') ?></a>
</div>

<div class="row g-4 mb-4">
  <div class="col-md-6">
    <div class="card">
      <div class="card-header bg-primary text-white"><?= t('trip_info') ?></div>
      <div class="card-body">
        <table class="table table-sm table-borderless">
          <tr><td class="text-muted"><?= t('trip_number') ?></td><td><?= e($trip['trip_number']) ?></td></tr>
          <tr><td class="text-muted"><?= t('vehicle') ?></td><td><?= e($trip['plate_number'] . ' - ' . $trip['make'] . ' ' . $trip['model']) ?></td></tr>
          <tr><td class="text-muted"><?= t('driver') ?></td><td><?= e($trip['driver_name']) ?></td></tr>
          <tr><td class="text-muted"><?= t('status') ?></td><td><?= statusBadge($trip['status']) ?></td></tr>
          <tr><td class="text-muted"><?= t('start_time') ?></td><td><?= fmtDate($trip['start_time'], true) ?></td></tr>
          <tr><td class="text-muted"><?= t('end_time') ?></td><td><?= $trip['end_time'] ? fmtDate($trip['end_time'], true) : '—' ?></td></tr>
          <tr><td class="text-muted"><?= t('start_location') ?></td><td><?= e($trip['start_location'] ?: '—') ?></td></tr>
          <tr><td class="text-muted"><?= t('end_location') ?></td><td><?= e($trip['end_location'] ?: '—') ?></td></tr>
          <tr><td class="text-muted"><?= t('distance_km') ?></td><td><?= $trip['distance_km'] ? number_format($trip['distance_km'], 2) . ' km' : '—' ?></td></tr>
          <tr><td class="text-muted"><?= t('purpose') ?></td><td><?= e($trip['purpose'] ?: '—') ?></td></tr>
        </table>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card">
      <div class="card-header bg-success text-white"><?= t('trip_expenses') ?></div>
      <div class="card-body">
        <h4 class="text-success">KWD <?= number_format($totalExpenses, 3) ?></h4>
        <p class="text-muted mb-3"><?= count($expenses) ?> <?= t('expenses') ?></p>
        <?php if ($trip['status'] === 'in_progress'): ?>
        <button class="btn btn-warning w-100" onclick="openEndTripModal()">
          <i class="fas fa-stop-circle me-1"></i><?= t('end_trip') ?>
        </button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Add Expense -->
<div class="card mb-4">
  <div class="card-header bg-info text-white">
    <i class="fas fa-plus me-2"></i><?= t('add_expense') ?>
  </div>
  <div class="card-body">
    <form method="POST">
      <input type="hidden" name="action" value="add_expense">
      <input type="hidden" name="trip_id" value="<?= $id ?>">
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label"><?= t('expense_type') ?></label>
          <select name="expense_type" class="form-select" required>
            <?php foreach ($expenseTypes as $et): ?>
            <option value="<?= $et ?>"><?= t($et) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label"><?= t('amount') ?></label>
          <input name="amount" type="number" step="0.001" class="form-control" required>
        </div>
        <div class="col-md-3">
          <label class="form-label"><?= t('date') ?></label>
          <input name="expense_date" type="date" class="form-control" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">&nbsp;</label>
          <button type="submit" class="btn btn-primary w-100"><?= t('add') ?></button>
        </div>
        <div class="col-12">
          <label class="form-label"><?= t('description') ?></label>
          <input name="description" type="text" class="form-control">
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Expenses List -->
<div class="card">
  <div class="card-header bg-info text-white"><?= t('expenses') ?></div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th><?= t('date') ?></th>
            <th><?= t('expense_type') ?></th>
            <th><?= t('description') ?></th>
            <th><?= t('amount') ?></th>
            <th><?= t('actions') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($expenses as $exp): ?>
          <tr>
            <td><?= fmtDate($exp['expense_date']) ?></td>
            <td><span class="badge bg-secondary"><?= t($exp['expense_type']) ?></span></td>
            <td><?= e($exp['description'] ?: '—') ?></td>
            <td><strong>KWD <?= number_format($exp['amount'], 3) ?></strong></td>
            <td>
              <a href="?module=trips&action=delete_expense&id=<?= $exp['id'] ?>&trip_id=<?= $id ?>" class="btn btn-xs btn-outline-danger" onclick="return confirm('<?= t('confirm_delete') ?>')">
                <i class="fas fa-trash"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$expenses): ?>
          <tr><td colspan="5" class="text-center text-muted py-3"><?= t('no_records') ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- End Trip Modal -->
<div class="modal fade" id="endTripModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="end_trip">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="modal-header bg-warning text-dark">
          <h5 class="modal-title"><?= t('end_trip') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label"><?= t('end_odometer') ?></label>
            <input name="end_odometer" type="number" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label"><?= t('end_location') ?></label>
            <input name="end_location" type="text" class="form-control" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('cancel') ?></button>
          <button type="submit" class="btn btn-warning"><?= t('end_trip') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openEndTripModal(){
  new bootstrap.Modal(document.getElementById('endTripModal')).show();
}
</script>

<?php else: ?>
<!-- Trip List View -->
<div class="d-flex justify-content-between align-items-center mb-4">
  <h3><i class="fas fa-route me-2"></i><?= t('trips') ?></h3>
  <button class="btn btn-primary" onclick="openAddModal()">
    <i class="fas fa-plus me-1"></i><?= t('add_trip') ?>
  </button>
</div>

<!-- Filters -->
<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-3">
      <input type="hidden" name="module" value="trips">
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
        <label class="form-label"><?= t('status') ?></label>
        <select name="status" class="form-select">
          <option value=""><?= t('all') ?></option>
          <?php foreach ($tripStatuses as $ts): ?>
          <option value="<?= $ts ?>" <?= $fStatus === $ts ? 'selected' : '' ?>><?= t($ts) ?></option>
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
        <a href="?module=trips" class="btn btn-secondary"><?= t('reset') ?></a>
      </div>
    </form>
  </div>
</div>

<!-- Trips Table -->
<div class="card">
  <div class="card-header bg-primary text-white">
    <i class="fas fa-list me-2"></i><?= t('trips') ?>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th><?= t('trip_number') ?></th>
            <th><?= t('vehicle') ?></th>
            <th><?= t('driver') ?></th>
            <th><?= t('start_time') ?></th>
            <th><?= t('end_time') ?></th>
            <th><?= t('distance_km') ?></th>
            <th><?= t('status') ?></th>
            <th><?= t('actions') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($trips as $t): ?>
          <tr>
            <td><strong><?= e($t['trip_number']) ?></strong></td>
            <td><?= e($t['plate_number']) ?></td>
            <td><?= e($t['driver_name']) ?></td>
            <td><?= fmtDate($t['start_time'], true) ?></td>
            <td><?= $t['end_time'] ? fmtDate($t['end_time'], true) : '—' ?></td>
            <td><?= $t['distance_km'] ? number_format($t['distance_km'], 2) . ' km' : '—' ?></td>
            <td><?= statusBadge($t['status']) ?></td>
            <td>
              <a href="?module=trips&action=view&id=<?= $t['id'] ?>" class="btn btn-xs btn-outline-info" title="<?= t('view') ?>">
                <i class="fas fa-eye"></i>
              </a>
              <?php if ($t['status'] === 'planned'): ?>
              <a href="?module=trips&action=start_trip&id=<?= $t['id'] ?>" class="btn btn-xs btn-outline-success" title="<?= t('start_trip') ?>" onclick="return confirm('<?= t('confirm_start_trip') ?>')">
                <i class="fas fa-play"></i>
              </a>
              <?php endif; ?>
              <button class="btn btn-xs btn-outline-primary" onclick="openEditModal(<?= htmlspecialchars(json_encode($t), ENT_QUOTES) ?>)">
                <i class="fas fa-edit"></i>
              </button>
              <button class="btn btn-xs btn-outline-danger" onclick="confirmDelete(<?= $t['id'] ?>, '<?= e($t['trip_number']) ?>')">
                <i class="fas fa-trash"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$trips): ?>
          <tr><td colspan="8" class="text-center text-muted py-3"><?= t('no_records') ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="tripModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" id="tAction" value="add">
        <input type="hidden" name="id" id="tId" value="">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title" id="modalTitle"><?= t('add_trip') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label"><?= t('vehicles') ?> *</label>
              <select name="vehicle_id" id="tVehicle" class="form-select" required>
                <?= vehicleOptions() ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('drivers') ?> *</label>
              <select name="driver_id" id="tDriver" class="form-select" required>
                <?= employeeOptions() ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('trip_number') ?></label>
              <input name="trip_number" id="tNumber" type="text" class="form-control" value="TRIP-<?= date('Ymd-His') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('status') ?></label>
              <select name="status" id="tStatus" class="form-select">
                <?php foreach ($tripStatuses as $ts): ?>
                <option value="<?= $ts ?>" <?= $ts === 'planned' ? 'selected' : '' ?>><?= t($ts) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('start_time') ?> *</label>
              <input name="start_time" id="tStartTime" type="datetime-local" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('end_time') ?></label>
              <input name="end_time" id="tEndTime" type="datetime-local" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('start_location') ?></label>
              <input name="start_location" id="tStartLoc" type="text" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('end_location') ?></label>
              <input name="end_location" id="tEndLoc" type="text" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label"><?= t('start_odometer') ?></label>
              <input name="start_odometer" id="tStartOdo" type="number" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label"><?= t('end_odometer') ?></label>
              <input name="end_odometer" id="tEndOdo" type="number" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label"><?= t('distance_km') ?></label>
              <input name="distance_km" id="tDistance" type="number" step="0.01" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label"><?= t('purpose') ?></label>
              <input name="purpose" id="tPurpose" type="text" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label"><?= t('notes') ?></label>
              <textarea name="notes" id="tNotes" class="form-control" rows="2"></textarea>
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
      <a class="page-link" href="?module=trips&page=<?= $p ?>&vehicle_id=<?= $fVehicle ?>&driver_id=<?= $fDriver ?>&status=<?= e($fStatus) ?>&date_from=<?= e($fDateFrom) ?>&date_to=<?= e($fDateTo) ?>"><?= $p ?></a>
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
function openAddModal(){
  document.getElementById('tAction').value='add';
  document.getElementById('tId').value='';
  document.getElementById('modalTitle').textContent='<?= t('add_trip') ?>';
  document.getElementById('tVehicle').value='';
  document.getElementById('tDriver').value='';
  document.getElementById('tNumber').value='TRIP-<?= date('Ymd-His') ?>';
  document.getElementById('tStatus').value='planned';
  document.getElementById('tStartTime').value='';
  document.getElementById('tEndTime').value='';
  document.getElementById('tStartLoc').value='';
  document.getElementById('tEndLoc').value='';
  document.getElementById('tStartOdo').value='';
  document.getElementById('tEndOdo').value='';
  document.getElementById('tDistance').value='';
  document.getElementById('tPurpose').value='';
  document.getElementById('tNotes').value='';
  new bootstrap.Modal(document.getElementById('tripModal')).show();
}
function openEditModal(t){
  document.getElementById('tAction').value='edit';
  document.getElementById('tId').value=t.id;
  document.getElementById('modalTitle').textContent='<?= t('edit_trip') ?>';
  document.getElementById('tVehicle').value=t.vehicle_id;
  document.getElementById('tDriver').value=t.driver_id;
  document.getElementById('tNumber').value=t.trip_number;
  document.getElementById('tStatus').value=t.status;
  document.getElementById('tStartTime').value=t.start_time;
  document.getElementById('tEndTime').value=t.end_time||'';
  document.getElementById('tStartLoc').value=t.start_location||'';
  document.getElementById('tEndLoc').value=t.end_location||'';
  document.getElementById('tStartOdo').value=t.start_odometer||'';
  document.getElementById('tEndOdo').value=t.end_odometer||'';
  document.getElementById('tDistance').value=t.distance_km||'';
  document.getElementById('tPurpose').value=t.purpose||'';
  document.getElementById('tNotes').value=t.notes||'';
  new bootstrap.Modal(document.getElementById('tripModal')).show();
}
function confirmDelete(id,number){
  if(confirm('<?= t('confirm_delete') ?>\n'+number)){
    document.getElementById('deleteId').value=id;
    document.getElementById('deleteForm').submit();
  }
}
</script>
<?php endif; ?>
