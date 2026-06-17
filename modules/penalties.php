<?php
// modules/penalties.php

$pdo    = getDB();
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$lang   = $_SESSION['lang'] ?? 'en';

// ---- Handle POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;
    if ($action === 'add' || $action === 'edit') {
        $fields = [
            'vehicle_id'       => (int)($d['vehicle_id'] ?? 0),
            'employee_id'      => (int)($d['employee_id'] ?? 0) ?: null,
            'penalty_type'     => $d['penalty_type'] ?? 'other',
            'penalty_date'     => $d['penalty_date'] ?: null,
            'amount'           => $d['amount'] ?: null,
            'reference_number' => trim($d['reference_number'] ?? ''),
            'status'           => $d['status'] ?? 'pending',
            'notes'            => trim($d['notes'] ?? ''),
        ];
        if (!$fields['vehicle_id'] || !$fields['penalty_type'] || !$fields['penalty_date']) {
            setFlash('danger', t('required_fields'));
        } else {
            try {
                if ($action === 'add') {
                    $cols = implode(',', array_keys($fields));
                    $vals = implode(',', array_fill(0, count($fields), '?'));
                    $pdo->prepare("INSERT INTO penalties ($cols) VALUES ($vals)")->execute(array_values($fields));
                    setFlash('success', t('record_saved'));
                } else {
                    $set = implode('=?,', array_keys($fields)).'=?';
                    $pdo->prepare("UPDATE penalties SET $set WHERE id=?")->execute([...array_values($fields), $id]);
                    setFlash('success', t('record_updated'));
                }
            } catch (PDOException $e) {
                setFlash('danger', t('error_occurred').' '.$e->getMessage());
            }
        }
        header("Location: ?module=penalties"); exit;
    }
    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM penalties WHERE id=?")->execute([$id]);
        setFlash('success', t('record_deleted'));
        header("Location: ?module=penalties"); exit;
    }
}

// ---- Filters ----
$search   = trim($_GET['q']      ?? '');
$fStatus  = $_GET['status']      ?? '';
$fType    = $_GET['ptype']       ?? '';

$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$where = " FROM penalties p
        LEFT JOIN vehicles v ON v.id = p.vehicle_id
        LEFT JOIN employees e ON e.id = p.employee_id
        WHERE 1=1";
$params = [];
if ($fStatus) { $where .= " AND p.status=?";       $params[] = $fStatus; }
if ($fType)   { $where .= " AND p.penalty_type=?";  $params[] = $fType; }
if ($search)  {
    $where .= " AND (v.plate_number LIKE ? OR e.name_en LIKE ? OR p.reference_number LIKE ?)";
    for ($i = 0; $i < 3; $i++) $params[] = "%$search%";
}

$cStmt = $pdo->prepare("SELECT COUNT(*)".$where); $cStmt->execute($params);
$totalRows  = (int)$cStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$sql = "SELECT p.*,
               v.plate_number, v.make, v.model, v.car_company,
               e.name_en AS driver_name, e.emp_id AS driver_emp_id, e.residency_company AS driver_residency"
        .$where." ORDER BY p.penalty_date DESC LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Fetch all vehicles with their current driver for JS auto-fill
$vehiclesData = $pdo->query("
    SELECT v.id, v.plate_number, v.make, v.model, v.car_company,
           e.id AS emp_id_fk, e.name_en AS driver_name, e.emp_id AS driver_emp_id, e.residency_company AS driver_residency
    FROM vehicles v
    LEFT JOIN employees e ON e.id = v.current_driver_id
    WHERE v.status != 'sold'
    ORDER BY v.plate_number
")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="card mb-3"><div class="card-body py-2">
  <form method="get" class="row g-2 align-items-end">
    <input type="hidden" name="module" value="penalties">
    <div class="col-md-3"><input name="q" class="form-control form-control-sm" placeholder="<?= t('search') ?>" value="<?= e($search) ?>"></div>
    <div class="col-auto">
      <select name="ptype" class="form-select form-select-sm">
        <option value=""><?= t('all') ?> <?= t('type') ?></option>
        <?php foreach (['over_speed','wrong_parking','belt','signal_crossing','phone_use','no_license','expired_license','reckless_driving','no_insurance','expired_registration','wrong_turn','no_plate','tinted_windows','other'] as $pt): ?>
          <option value="<?= $pt ?>" <?= $fType===$pt?'selected':''?>><?= t('penalty_'.$pt) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <select name="status" class="form-select form-select-sm">
        <option value=""><?= t('all') ?> <?= t('status') ?></option>
        <?php foreach (['pending','paid','disputed'] as $s): ?>
          <option value="<?= $s ?>" <?= $fStatus===$s?'selected':''?>><?= t($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-primary"><?= t('filter') ?></button></div>
    <div class="col-auto"><a href="?module=penalties" class="btn btn-sm btn-outline-secondary"><?= t('reset') ?></a></div>
    <div class="col-auto ms-auto">
      <button type="button" class="btn btn-sm btn-danger" onclick="openAddModal()">
        <i class="fas fa-plus me-1"></i><?= t('add_penalty') ?>
      </button>
    </div>
  </form>
</div></div>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover align-middle mb-0">
  <thead class="table-dark">
    <tr>
      <th>#</th>
      <th><?= t('plate_number') ?></th>
      <th><?= t('car_company') ?></th>
      <th><?= t('assigned_driver') ?></th>
      <th><?= t('driver_residency') ?></th>
      <th><?= t('penalty_type') ?></th>
      <th><?= t('penalty_date') ?></th>
      <th><?= t('amount') ?></th>
      <th><?= t('reference_number') ?></th>
      <th><?= t('status') ?></th>
      <th><?= t('actions') ?></th>
    </tr>
  </thead>
  <tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="11" class="text-center text-muted py-3"><?= t('no_records') ?></td></tr>
  <?php endif; ?>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= $r['id'] ?></td>
    <td><strong><?= e($r['plate_number']) ?></strong><br><small class="text-muted"><?= e($r['make'].' '.$r['model']) ?></small></td>
    <td><?= e($r['car_company']?:'—') ?></td>
    <td><?= $r['driver_name'] ? '<span class="badge bg-info text-dark">'.e($r['driver_name']).'</span>' : '<span class="text-muted">—</span>' ?></td>
    <td><?= e($r['driver_residency']?:'—') ?></td>
    <td><span class="badge bg-warning text-dark"><?= t('penalty_'.$r['penalty_type']) ?></span></td>
    <td><?= fmtDate($r['penalty_date']) ?></td>
    <td><?= $r['amount'] ? 'KWD '.number_format($r['amount'], 3) : '—' ?></td>
    <td><?= e($r['reference_number']?:'—') ?></td>
    <td><?= penaltyStatusBadge($r['status']) ?></td>
    <td>
      <button class="btn btn-xs btn-outline-primary" onclick="openEditModal(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)"><i class="fas fa-edit"></i></button>
      <button class="btn btn-xs btn-outline-danger" onclick="confirmDelete(<?= $r['id'] ?>)"><i class="fas fa-trash"></i></button>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div></div>
<div class="card-footer text-muted small"><?= $totalRows ?> <?= t('total') ?></div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center">
  <?php for ($p=1;$p<=$totalPages;$p++): ?>
  <li class="page-item <?= $p===$page?'active':'' ?>"><a class="page-link" href="?module=penalties&page=<?= $p ?>&q=<?= e($search) ?>&status=<?= e($fStatus) ?>&ptype=<?= e($fType) ?>"><?= $p ?></a></li>
  <?php endfor; ?>
</ul></nav>
<?php endif; ?>

<!-- Add/Edit Modal -->
<div class="modal fade" id="penaltyModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" id="penaltyForm">
        <input type="hidden" name="action" id="fAction" value="add">
        <input type="hidden" name="id"     id="fId"     value="">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title" id="modalTitle"><?= t('add_penalty') ?></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">

            <!-- Car plate dropdown -->
            <div class="col-md-4">
              <label class="form-label"><?= t('plate_number') ?> *</label>
              <select name="vehicle_id" id="fVehicle" class="form-select" required onchange="autoFillDriver(this.value)">
                <option value=""><?= t('select_vehicle') ?></option>
                <?php foreach ($vehiclesData as $vd): ?>
                  <option value="<?= $vd['id'] ?>"><?= e($vd['plate_number']) ?> — <?= e($vd['make'].' '.$vd['model']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Car company (auto-filled, editable) -->
            <div class="col-md-4">
              <label class="form-label"><?= t('car_company') ?></label>
              <input name="car_company_display" id="fCarCompany" class="form-control" readonly style="background:#f8f9fa">
            </div>

            <!-- Driver name (auto-filled) -->
            <div class="col-md-4">
              <label class="form-label"><?= t('assigned_driver') ?></label>
              <input name="driver_display" id="fDriverDisplay" class="form-control" readonly style="background:#f8f9fa">
              <input type="hidden" name="employee_id" id="fEmployee">
            </div>

            <!-- Driver residency (auto-filled) -->
            <div class="col-md-4">
              <label class="form-label"><?= t('driver_residency') ?></label>
              <input name="residency_display" id="fResidencyDisplay" class="form-control" readonly style="background:#f8f9fa">
            </div>

            <!-- Penalty type -->
            <div class="col-md-4">
              <label class="form-label"><?= t('penalty_type') ?> *</label>
              <select name="penalty_type" id="fPenaltyType" class="form-select" required>
                <?php foreach (['over_speed','wrong_parking','belt','signal_crossing','phone_use','no_license','expired_license','reckless_driving','no_insurance','expired_registration','wrong_turn','no_plate','tinted_windows','other'] as $pt): ?>
                  <option value="<?= $pt ?>"><?= t('penalty_'.$pt) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Penalty date -->
            <div class="col-md-4">
              <label class="form-label"><?= t('penalty_date') ?> *</label>
              <input name="penalty_date" id="fDate" type="date" class="form-control" required>
            </div>

            <!-- Amount -->
            <div class="col-md-4">
              <label class="form-label"><?= t('amount') ?> (KWD)</label>
              <input name="amount" id="fAmount" type="number" step="0.001" min="0" class="form-control">
            </div>

            <!-- Reference number -->
            <div class="col-md-4">
              <label class="form-label"><?= t('reference_number') ?></label>
              <input name="reference_number" id="fRef" class="form-control" placeholder="<?= t('reference_number') ?>">
            </div>

            <!-- Status -->
            <div class="col-md-4">
              <label class="form-label"><?= t('status') ?></label>
              <select name="status" id="fStatus" class="form-select">
                <option value="pending"><?= t('pending') ?></option>
                <option value="paid"><?= t('paid') ?></option>
                <option value="disputed"><?= t('disputed') ?></option>
              </select>
            </div>

            <!-- Notes -->
            <div class="col-12">
              <label class="form-label"><?= t('notes') ?></label>
              <textarea name="notes" id="fNotes" class="form-control" rows="2"></textarea>
            </div>

          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('cancel') ?></button>
          <button type="submit" class="btn btn-danger"><i class="fas fa-save me-1"></i><?= t('save') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<form id="deleteForm" method="post">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="deleteId">
</form>

<script>
// Vehicle data for auto-fill
const vehicleMap = <?= json_encode(array_column($vehiclesData, null, 'id')) ?>;

function autoFillDriver(vehicleId) {
  const v = vehicleMap[vehicleId];
  if (v) {
    document.getElementById('fCarCompany').value      = v.car_company      || '';
    document.getElementById('fDriverDisplay').value   = v.driver_name      ? '['+v.driver_emp_id+'] '+v.driver_name : '';
    document.getElementById('fEmployee').value        = v.emp_id_fk        || '';
    document.getElementById('fResidencyDisplay').value= v.driver_residency || '';
  } else {
    document.getElementById('fCarCompany').value      = '';
    document.getElementById('fDriverDisplay').value   = '';
    document.getElementById('fEmployee').value        = '';
    document.getElementById('fResidencyDisplay').value= '';
  }
}

function openAddModal() {
  document.getElementById('fAction').value = 'add';
  document.getElementById('fId').value     = '';
  document.getElementById('modalTitle').textContent = '<?= t('add_penalty') ?>';
  document.getElementById('penaltyForm').reset();
  document.getElementById('fCarCompany').value       = '';
  document.getElementById('fDriverDisplay').value    = '';
  document.getElementById('fResidencyDisplay').value = '';
  document.getElementById('fEmployee').value         = '';
  new bootstrap.Modal(document.getElementById('penaltyModal')).show();
}

function openEditModal(r) {
  document.getElementById('fAction').value = 'edit';
  document.getElementById('fId').value     = r.id;
  document.getElementById('modalTitle').textContent = '<?= t('edit_penalty') ?>';
  document.getElementById('fVehicle').value      = r.vehicle_id   ?? '';
  document.getElementById('fPenaltyType').value  = r.penalty_type ?? '';
  document.getElementById('fDate').value         = r.penalty_date ?? '';
  document.getElementById('fAmount').value       = r.amount       ?? '';
  document.getElementById('fRef').value          = r.reference_number ?? '';
  document.getElementById('fStatus').value       = r.status       ?? 'pending';
  document.getElementById('fNotes').value        = r.notes        ?? '';
  // auto-fill display fields
  autoFillDriver(r.vehicle_id);
  new bootstrap.Modal(document.getElementById('penaltyModal')).show();
}

function confirmDelete(id) {
  if (confirm('<?= t('confirm_delete') ?>')) {
    const f = document.getElementById('deleteForm');
    f.action = '?module=penalties&action=delete&id=' + id;
    f.submit();
  }
}

document.getElementById('penaltyForm').addEventListener('submit', function () {
  this.action = '?module=penalties&action=' + document.getElementById('fAction').value +
    (document.getElementById('fId').value ? '&id=' + document.getElementById('fId').value : '');
});
</script>
<?php

// ---- Helper: penalty status badge ----
function penaltyStatusBadge(string $s): string {
    $map = ['pending'=>'warning','paid'=>'success','disputed'=>'danger'];
    $color = $map[$s] ?? 'secondary';
    global $LANG;
    $label = $LANG[$s] ?? $s;
    return "<span class=\"badge bg-{$color} text-".($color==='warning'?'dark':'white')."\">{$label}</span>";
}
?>
