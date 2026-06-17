<?php
// modules/vehicles.php

$pdo    = getDB();
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$lang   = $_SESSION['lang'] ?? 'en';

// ---- Handle POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;
    if ($action === 'add' || $action === 'edit') {
        $fields = [
            'type'                => $d['type']                ?? 'car',
            'make'                => trim($d['make']           ?? ''),
            'model'               => trim($d['model']          ?? ''),
            'year'                => $d['year']                ?: null,
            'color_en'            => trim($d['color_en']       ?? ''),
            'color_ar'            => trim($d['color_ar']       ?? ''),
            'plate_number'        => strtoupper(trim($d['plate_number'] ?? '')),
            'chassis_number'      => trim($d['chassis_number'] ?? ''),
            'engine_number'       => trim($d['engine_number']  ?? ''),
            'current_km'          => (int)($d['current_km']    ?? 0),
            'first_service_km'    => $d['first_service_km']    ?: null,
            'service_interval_km' => (int)($d['service_interval_km'] ?? 5000),
            'free_service_km_threshold' => $d['free_service_km_threshold'] ?: null,
            'free_service_driver_id'    => $d['free_service_driver_id'] ?: null,
            'current_driver_id'   => $d['current_driver_id']   ?: null,
            'car_company'      => trim($d['car_company']   ?? ''),
            'platform'            => $d['platform']            ?? '',
            'status'              => $d['status']              ?? 'active',
            'rc_date'             => $d['rc_date']             ?: null,
            'food_card_expiry'    => $d['food_card_expiry']    ?: null,
            'municipality_expiry' => $d['municipality_expiry'] ?: null,
            'purchase_date'       => $d['purchase_date']       ?: null,
            'purchase_price'      => $d['purchase_price']      ?: null,
            'notes'               => trim($d['notes']          ?? ''),
        ];
        if (!$fields['make'] || !$fields['model'] || !$fields['plate_number']) {
            setFlash('danger', t('required_fields'));
        } else {
            try {
                if ($action === 'add') {
                    $cols = implode(',', array_keys($fields));
                    $vals = implode(',', array_fill(0, count($fields), '?'));
                    $pdo->prepare("INSERT INTO vehicles ($cols) VALUES ($vals)")->execute(array_values($fields));
                    setFlash('success', t('record_saved'));
                } else {
                    $set = implode('=?,', array_keys($fields)).'=?';
                    $pdo->prepare("UPDATE vehicles SET $set WHERE id=?")->execute([...array_values($fields), $id]);
                    setFlash('success', t('record_updated'));
                }
            } catch (PDOException $e) {
                setFlash('danger', t('error_occurred').' '.$e->getMessage());
            }
        }
        header("Location: ?module=vehicles"); exit;
    }
    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM vehicles WHERE id=?")->execute([$id]);
        setFlash('success', t('record_deleted'));
        header("Location: ?module=vehicles"); exit;
    }
}

// ---- Filters ----
$search   = trim($_GET['q']       ?? '');
$fType    = $_GET['type']         ?? '';
$fStatus  = $_GET['status']       ?? '';
$fDriver  = $_GET['driver_id']    ?? '';
$fPlatform= $_GET['platform']     ?? '';

// ---- LIST ----
if ($action === 'list') {
    $perPage = 50;
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $offset  = ($page - 1) * $perPage;

    $where  = " FROM vehicles v
               LEFT JOIN employees e ON e.id=v.current_driver_id
               LEFT JOIN employees fs ON fs.id=v.free_service_driver_id
               WHERE 1=1";
    $params = [];
    if ($fType)     { $where .= " AND v.type=?";     $params[] = $fType; }
    if ($fStatus)   { $where .= " AND v.status=?";   $params[] = $fStatus; }
    if ($fDriver)   { $where .= " AND v.current_driver_id=?"; $params[] = $fDriver; }
    if ($fPlatform) { $where .= " AND v.platform LIKE ?"; $params[] = "%$fPlatform%"; }
    if ($search) {
        $where .= " AND (
            v.plate_number    LIKE ? OR
            v.make            LIKE ? OR
            v.model           LIKE ? OR
            v.year            LIKE ? OR
            v.color_en        LIKE ? OR
            v.color_ar        LIKE ? OR
            v.chassis_number  LIKE ? OR
            v.engine_number   LIKE ? OR
            v.car_company     LIKE ? OR
            v.platform        LIKE ? OR
            v.notes           LIKE ? OR
            e.name_en         LIKE ? OR
            e.name_ar         LIKE ? OR
            e.emp_id          LIKE ? OR
            e.residency_company LIKE ?
        )";
        for ($i = 0; $i < 15; $i++) $params[] = "%$search%";
    }

    $cStmt = $pdo->prepare("SELECT COUNT(*)".$where); $cStmt->execute($params);
    $totalRows  = (int)$cStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));

    $sql = "SELECT v.*, COALESCE(e.name_en,'—') AS driver_name, e.emp_id AS driver_emp_id, e.residency_company AS driver_residency,
                      COALESCE(fs.name_en,'—') AS free_service_driver_name,
                      (SELECT MAX(vs.next_service_km) FROM vehicle_services vs WHERE vs.vehicle_id=v.id) AS next_svc_km"
            .$where." ORDER BY v.type, v.plate_number LIMIT $perPage OFFSET $offset";
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    $vehicles = $stmt->fetchAll();
?>

<!-- Filter Bar -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <input type="hidden" name="module" value="vehicles">
      <div class="col-md-3"><input name="q" class="form-control form-control-sm" placeholder="<?= t('search') ?>" value="<?= e($search) ?>"></div>
      <div class="col-auto">
        <select name="type" class="form-select form-select-sm">
          <option value=""><?= t('all') ?> <?= t('type') ?></option>
          <option value="car"  <?= $fType==='car'  ? 'selected':'' ?>><?= t('car') ?></option>
          <option value="bike" <?= $fType==='bike' ? 'selected':'' ?>><?= t('bike') ?></option>
        </select>
      </div>
      <div class="col-auto">
        <select name="status" class="form-select form-select-sm">
          <option value=""><?= t('all') ?> <?= t('status') ?></option>
          <?php foreach (['active','inactive','in_service','accident','sold'] as $s): ?>
          <option value="<?= $s ?>" <?= $fStatus===$s?'selected':'' ?>><?= t($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <select name="platform" class="form-select form-select-sm">
          <option value=""><?= t('all') ?> <?= t('platform') ?></option>
          <option value="talabat" <?= $fPlatform==='talabat'?'selected':'' ?>>Talabat</option>
          <option value="keeta"   <?= $fPlatform==='keeta'?'selected':'' ?>>Keeta</option>
        </select>
      </div>
      <div class="col-auto"><button class="btn btn-sm btn-primary"><?= t('filter') ?></button></div>
      <div class="col-auto"><a href="?module=vehicles" class="btn btn-sm btn-outline-secondary"><?= t('reset') ?></a></div>
      <div class="col-auto ms-auto">
        <button type="button" class="btn btn-sm btn-success" onclick="openAddModal()">
          <i class="fas fa-plus me-1"></i><?= t('add_vehicle') ?>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Table -->
<style>
#vehiclesTable { font-size: 12px; }
#vehiclesTable th, #vehiclesTable td { padding: .35rem .4rem; white-space: nowrap; }
#vehiclesTable .badge { font-size: 10px; padding: .25em .45em; }
#vehiclesTable .btn-xs { padding: .1rem .3rem; font-size: 11px; }
</style>
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" id="vehiclesTable">
      <thead class="table-dark">
        <tr>
          <th>#</th>
          <th><?= t('type') ?></th>
          <th><?= t('plate_number') ?></th>
          <th><?= t('make') ?> / <?= t('model') ?></th>
          <th><?= t('color') ?></th>
          <th><?= t('assigned_driver') ?></th>
          <th><?= t('driver_residency') ?></th>
          <th><?= t('car_company') ?></th>
          <th><?= t('rc_date') ?></th>
          <th><?= t('food_card_expiry') ?></th>
          <th><?= t('municipality_expiry') ?></th>
          <th><?= t('platform') ?></th>
          <th><?= t('status') ?></th>
          <th><?= t('actions') ?></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$vehicles): ?>
        <tr><td colspan="13" class="text-center text-muted py-3"><?= t('no_records') ?></td></tr>
      <?php endif; ?>
      <?php foreach ($vehicles as $v): ?>
        <tr>
          <td><?= $v['id'] ?></td>
          <td>
            <?php if ($v['type']==='car'): ?>
              <span class="badge bg-primary"><i class="fas fa-car me-1"></i><?= t('car') ?></span>
            <?php else: ?>
              <span class="badge bg-warning text-dark"><i class="fas fa-motorcycle me-1"></i><?= t('bike') ?></span>
            <?php endif; ?>
          </td>
          <td><strong><?= e($v['plate_number']) ?></strong></td>
          <td><?= e($v['year'].' '.$v['make'].' '.$v['model']) ?></td>
          <td>
            <?php $color = $lang==='ar' ? ($v['color_ar']??$v['color_en']) : $v['color_en']; ?>
            <?= e($color) ?>
          </td>
          <td><?= $v['driver_name']!=='—' ? '<span class="badge bg-info text-dark">'.e($v['driver_name']).'</span>' : '<span class="text-muted">—</span>' ?></td>
          <td><?= e($v['driver_residency']?:'—') ?></td>
          <td><?= e($v['car_company']?:'—') ?></td>
          <td><?= expiryBadge($v['rc_date']) ?></td>
          <td><?= expiryBadge($v['food_card_expiry']) ?></td>
          <td><?= expiryBadge($v['municipality_expiry']) ?></td>
          <td><?= platformBadge($v['platform']) ?></td>
          <td><?= statusBadge($v['status']) ?></td>
          <td>
            <a href="?module=vehicles&action=view&id=<?= $v['id'] ?>" class="btn btn-xs btn-outline-info" title="<?= t('view') ?>"><i class="fas fa-eye"></i></a>
            <button class="btn btn-xs btn-outline-primary" onclick="openEditModal(<?= htmlspecialchars(json_encode($v),ENT_QUOTES) ?>)" title="<?= t('edit') ?>"><i class="fas fa-edit"></i></button>
            <button class="btn btn-xs btn-outline-danger" onclick="confirmDelete(<?= $v['id'] ?>,'<?= e($v['plate_number']) ?>')" title="<?= t('delete') ?>"><i class="fas fa-trash"></i></button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <div class="card-footer text-muted small"><?= $totalRows ?> <?= t('total') ?></div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center">
  <?php for ($p=1;$p<=$totalPages;$p++): ?>
  <li class="page-item <?= $p===$page?'active':'' ?>"><a class="page-link" href="?module=vehicles&page=<?= $p ?>&q=<?= e($search) ?>&type=<?= e($fType) ?>&status=<?= e($fStatus) ?>&platform=<?= e($fPlatform) ?>&driver_id=<?= e($fDriver) ?>"><?= $p ?></a></li>
  <?php endfor; ?>
</ul></nav>
<?php endif; ?>

<!-- Add/Edit Modal -->
<div class="modal fade" id="vehicleModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" id="vehicleForm">
        <input type="hidden" name="action" id="fAction" value="add">
        <input type="hidden" name="id"     id="fId"     value="">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title" id="modalTitle"><?= t('add_vehicle') ?></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label"><?= t('vehicle_type') ?> *</label>
              <select name="type" id="fType" class="form-select" required>
                <option value="car"><?= t('car') ?></option>
                <option value="bike"><?= t('bike') ?></option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label"><?= t('plate_number') ?> *</label>
              <input name="plate_number" id="fPlate" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label class="form-label"><?= t('make') ?> *</label>
              <input name="make" id="fMake" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label class="form-label"><?= t('model') ?> *</label>
              <input name="model" id="fModel" class="form-control" required>
            </div>
            <div class="col-md-2">
              <label class="form-label"><?= t('year') ?></label>
              <input name="year" id="fYear" type="number" class="form-control" min="2000" max="2030">
            </div>
            <div class="col-md-2">
              <label class="form-label"><?= t('color') ?> (EN)</label>
              <input name="color_en" id="fColorEn" class="form-control">
            </div>
            <div class="col-md-2">
              <label class="form-label"><?= t('color') ?> (AR)</label>
              <input name="color_ar" id="fColorAr" class="form-control" dir="rtl">
            </div>
            <div class="col-md-3">
              <label class="form-label"><?= t('chassis_number') ?></label>
              <input name="chassis_number" id="fChassis" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label"><?= t('engine_number') ?></label>
              <input name="engine_number" id="fEngine" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label"><?= t('current_km') ?></label>
              <input name="current_km" id="fKm" type="number" class="form-control" value="0" min="0">
            </div>
            <div class="col-md-3">
              <label class="form-label"><?= t('first_service_km') ?></label>
              <input name="first_service_km" id="fFirstSvc" type="number" class="form-control" min="0">
            </div>
            <div class="col-md-3">
              <label class="form-label"><?= t('service_interval') ?></label>
              <input name="service_interval_km" id="fSvcInt" type="number" class="form-control" value="5000" min="500">
            </div>
            <div class="col-md-3">
              <label class="form-label"><?= t('assigned_driver') ?></label>
              <select name="current_driver_id" id="fDriver" class="form-select">
                <?= employeeOptions() ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label"><?= t('free_service_km_threshold') ?></label>
              <input name="free_service_km_threshold" id="fFreeSvcKm" type="number" class="form-control" min="0" placeholder="e.g., 5000">
              <small class="text-muted">KM interval for free service</small>
            </div>
            <div class="col-md-3">
              <label class="form-label"><?= t('free_service_driver') ?></label>
              <select name="free_service_driver_id" id="fFreeDriver" class="form-select">
                <?= employeeOptions() ?>
              </select>
              <small class="text-muted">Driver eligible for free service</small>
            </div>
            <div class="col-md-3">
              <label class="form-label"><?= t('platform') ?></label>
              <select name="platform" id="fPlatform" class="form-select">
                <option value="">—</option>
                <option value="talabat">Talabat</option>
                <option value="keeta">Keeta</option>
                <option value="both">Both</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label"><?= t('car_company') ?></label>
              <input name="car_company" id="fHiringCompany" class="form-control" placeholder="e.g. Toyota Rental">
            </div>
            <div class="col-md-3">
              <label class="form-label"><?= t('status') ?></label>
              <select name="status" id="fStatus" class="form-select">
                <?php foreach (['active','inactive','in_service','accident','sold'] as $s): ?>
                <option value="<?= $s ?>"><?= t($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label"><?= t('rc_date') ?></label>
              <input name="rc_date" id="fRcDate" type="date" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label"><?= t('food_card_expiry') ?></label>
              <input name="food_card_expiry" id="fFoodCard" type="date" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label"><?= t('municipality_expiry') ?></label>
              <input name="municipality_expiry" id="fMunicipality" type="date" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label"><?= t('purchase_date') ?></label>
              <input name="purchase_date" id="fPurchDate" type="date" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label"><?= t('purchase_price') ?></label>
              <input name="purchase_price" id="fPurchPrice" type="number" step="0.001" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label"><?= t('notes') ?></label>
              <textarea name="notes" id="fNotes" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('cancel') ?></button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i><?= t('save') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete confirm -->
<form id="deleteForm" method="post">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="deleteId">
</form>

<script>
function openAddModal(){
  document.getElementById('fAction').value='add';
  document.getElementById('fId').value='';
  document.getElementById('modalTitle').textContent='<?= t('add_vehicle') ?>';
  document.getElementById('vehicleForm').reset();
  new bootstrap.Modal(document.getElementById('vehicleModal')).show();
}
function openEditModal(v){
  document.getElementById('fAction').value='edit';
  document.getElementById('fId').value=v.id;
  document.getElementById('modalTitle').textContent='<?= t('edit_vehicle') ?>';
  ['type','make','model','year','color_en','color_ar','plate_number',
   'chassis_number','engine_number','current_km','first_service_km',
   'service_interval_km','free_service_km_threshold','free_service_driver_id',
   'car_company','rc_date','food_card_expiry','municipality_expiry','platform','status','purchase_date','purchase_price','notes']
    .forEach(k=>{ let el=document.querySelector('[name='+k+']'); if(el) el.value=v[k]??''; });
  document.querySelector('[name=current_driver_id]').value=v.current_driver_id??'';
  new bootstrap.Modal(document.getElementById('vehicleModal')).show();
}
function confirmDelete(id,plate){
  if(confirm('<?= t('confirm_delete') ?>\n'+plate)){
    const f=document.getElementById('deleteForm');
    f.action='?module=vehicles&action=delete&id='+id;
    f.submit();
  }
}
// Set form action URL
document.getElementById('vehicleForm').action='?module=vehicles&action='+
  document.getElementById('fAction').value+'&id='+document.getElementById('fId').value;
document.getElementById('vehicleForm').addEventListener('submit',function(){
  this.action='?module=vehicles&action='+document.getElementById('fAction').value+
    (document.getElementById('fId').value ? '&id='+document.getElementById('fId').value : '');
});
</script>

<?php } // end list

// ---- VIEW single vehicle ----
if ($action === 'view' && $id) {
    $v = $pdo->prepare("SELECT v.*, COALESCE(e.name_en,'—') AS driver_name, e.emp_id AS driver_emp_id, e.residency_company AS driver_residency,
                                dl.name_en AS location_en, dl.name_ar AS location_ar
                         FROM vehicles v
                         LEFT JOIN employees e ON e.id=v.current_driver_id
                         LEFT JOIN duty_locations dl ON dl.id=e.duty_location_id
                         WHERE v.id=?");
    $v->execute([$id]);
    $vehicle = $v->fetch();
    if (!$vehicle) { echo '<div class="alert alert-danger">Vehicle not found.</div>'; return; }

    $services   = $pdo->prepare("SELECT * FROM vehicle_services WHERE vehicle_id=? ORDER BY service_date DESC");
    $services->execute([$id]); $services = $services->fetchAll();

    $insurance  = $pdo->prepare("SELECT * FROM vehicle_insurance WHERE vehicle_id=? ORDER BY expiry_date DESC");
    $insurance->execute([$id]); $insurance = $insurance->fetchAll();

    $accidents  = $pdo->prepare("SELECT va.*, COALESCE(e.name_en,'—') AS driver_name
                                  FROM vehicle_accidents va LEFT JOIN employees e ON e.id=va.driver_id
                                  WHERE va.vehicle_id=? ORDER BY va.accident_date DESC");
    $accidents->execute([$id]); $accidents = $accidents->fetchAll();

    $assignments= $pdo->prepare("SELECT da.*, e.name_en, e.emp_id, dl.name_en AS loc_en
                                  FROM driver_assignments da
                                  JOIN employees e ON e.id=da.employee_id
                                  LEFT JOIN duty_locations dl ON dl.id=da.duty_location_id
                                  WHERE da.vehicle_id=? ORDER BY da.assigned_date DESC");
    $assignments->execute([$id]); $assignments = $assignments->fetchAll();
?>
<a href="?module=vehicles" class="btn btn-sm btn-outline-secondary mb-3"><i class="fas fa-arrow-left me-1"></i><?= t('back') ?></a>

<div class="row g-4">
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header fw-bold bg-primary text-white">
        <?php echo $vehicle['type']==='car'?'<i class="fas fa-car me-2"></i>':'<i class="fas fa-motorcycle me-2"></i>'; ?>
        <?= e($vehicle['plate_number']) ?>
      </div>
      <div class="card-body">
        <table class="table table-sm table-borderless mb-0">
          <?php $rows=[
            [t('make').'/'.t('model'), $vehicle['year'].' '.$vehicle['make'].' '.$vehicle['model']],
            [t('color'), ($lang==='ar'?$vehicle['color_ar']:$vehicle['color_en'])],
            [t('plate_number'), $vehicle['plate_number']],
            [t('chassis_number'), $vehicle['chassis_number']?:'-'],
            [t('engine_number'), $vehicle['engine_number']?:'-'],
            [t('current_km'), number_format($vehicle['current_km']).' km'],
            [t('first_service_km'), $vehicle['first_service_km'] ? number_format($vehicle['first_service_km']).' km':'-'],
            [t('service_interval'), number_format($vehicle['service_interval_km']).' km'],
            [t('platform'), platformBadge($vehicle['platform'])],
            [t('status'), statusBadge($vehicle['status'])],
            [t('car_company'), e($vehicle['car_company']?:'—')],
            [t('rc_date'), expiryBadge($vehicle['rc_date'])],
            [t('food_card_expiry'), expiryBadge($vehicle['food_card_expiry'])],
            [t('municipality_expiry'), expiryBadge($vehicle['municipality_expiry'])],
            [t('purchase_date'), fmtDate($vehicle['purchase_date'])],
          ];
          foreach ($rows as $r): ?>
          <tr><td class="text-muted small"><?= $r[0] ?></td><td><?= $r[1] ?></td></tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <!-- Tabs -->
    <ul class="nav nav-tabs" id="vehTabs">
      <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabDriver"><?= t('assigned_driver') ?></a></li>
      <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabService"><i class="fas fa-wrench me-1"></i><?= t('services') ?> <span class="badge bg-secondary"><?= count($services) ?></span></a></li>
      <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabIns"><i class="fas fa-shield-alt me-1"></i><?= t('insurance') ?> <span class="badge bg-secondary"><?= count($insurance) ?></span></a></li>
      <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabAcc"><i class="fas fa-car-crash me-1"></i><?= t('accidents') ?> <span class="badge bg-danger"><?= count($accidents) ?></span></a></li>
      <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabAssign"><?= t('assignments') ?> <span class="badge bg-secondary"><?= count($assignments) ?></span></a></li>
    </ul>
    <div class="tab-content border border-top-0 rounded-bottom p-3 bg-white">

      <div class="tab-pane active" id="tabDriver">
        <?php if ($vehicle['current_driver_id']): ?>
        <table class="table table-sm table-borderless">
          <tr><td class="text-muted"><?= t('emp_id') ?></td><td><?= e($vehicle['driver_emp_id']) ?></td></tr>
          <tr><td class="text-muted"><?= t('name') ?></td><td><?= e($vehicle['driver_name']) ?></td></tr>
          <tr><td class="text-muted"><?= t('duty_location') ?></td><td><?= e($vehicle['location_en']) ?></td></tr>
          <tr><td class="text-muted"><?= t('driver_residency') ?></td><td><?= e($vehicle['driver_residency']?:'—') ?></td></tr>
        </table>
        <a href="?module=employees&action=view&id=<?= $vehicle['current_driver_id'] ?>" class="btn btn-sm btn-outline-info"><?= t('view') ?> <?= t('employees') ?></a>
        <?php else: ?><div class="text-muted">No driver assigned.</div><?php endif; ?>
      </div>

      <div class="tab-pane" id="tabService">
        <div class="text-end mb-2"><a href="?module=services&action=add&vehicle_id=<?= $id ?>" class="btn btn-sm btn-success"><i class="fas fa-plus me-1"></i><?= t('add_service') ?></a></div>
        <table class="table table-sm">
          <thead><tr><th><?= t('date') ?></th><th><?= t('service_type') ?></th><th><?= t('service_km') ?></th><th><?= t('next_service_km') ?></th><th><?= t('cost') ?></th><th><?= t('garage_name') ?></th></tr></thead>
          <tbody>
          <?php foreach ($services as $s): ?>
          <tr>
            <td><?= fmtDate($s['service_date']) ?></td>
            <td><?= t($s['service_type']) ?></td>
            <td><?= number_format($s['service_km']) ?></td>
            <td><?= number_format($s['next_service_km']) ?></td>
            <td><?= $s['cost'] ? 'KWD '.number_format($s['cost'],3) : '-' ?></td>
            <td><?= e($s['garage_name']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$services): ?><tr><td colspan="6" class="text-center text-muted"><?= t('no_records') ?></td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="tab-pane" id="tabIns">
        <div class="text-end mb-2"><a href="?module=insurance&action=add&vehicle_id=<?= $id ?>" class="btn btn-sm btn-success"><i class="fas fa-plus me-1"></i><?= t('add_insurance') ?></a></div>
        <table class="table table-sm">
          <thead><tr><th><?= t('insurance_company') ?></th><th><?= t('policy_number') ?></th><th><?= t('insurance_type') ?></th><th><?= t('start_date') ?></th><th><?= t('expiry_date') ?></th><th><?= t('amount') ?></th><th><?= t('status') ?></th></tr></thead>
          <tbody>
          <?php foreach ($insurance as $ins): ?>
          <tr>
            <td><?= e($ins['insurance_company']) ?></td>
            <td><?= e($ins['policy_number']) ?></td>
            <td><?= t($ins['insurance_type']) ?></td>
            <td><?= fmtDate($ins['start_date']) ?></td>
            <td><?= expiryBadge($ins['expiry_date']) ?></td>
            <td><?= $ins['amount'] ? 'KWD '.number_format($ins['amount'],3) : '-' ?></td>
            <td><?= statusBadge($ins['status']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$insurance): ?><tr><td colspan="7" class="text-center text-muted"><?= t('no_records') ?></td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="tab-pane" id="tabAcc">
        <div class="text-end mb-2"><a href="?module=accidents&action=add&vehicle_id=<?= $id ?>" class="btn btn-sm btn-danger"><i class="fas fa-plus me-1"></i><?= t('add_accident') ?></a></div>
        <table class="table table-sm">
          <thead><tr><th><?= t('date') ?></th><th><?= t('damage_level') ?></th><th><?= t('at_fault') ?></th><th><?= t('repair_cost') ?></th><th><?= t('status') ?></th></tr></thead>
          <tbody>
          <?php foreach ($accidents as $a): ?>
          <tr>
            <td><?= fmtDate($a['accident_date']) ?></td>
            <td><?= statusBadge($a['damage_level']) ?></td>
            <td><?= t($a['at_fault']) ?></td>
            <td><?= $a['repair_cost'] ? 'KWD '.number_format($a['repair_cost'],3) : '-' ?></td>
            <td><?= statusBadge($a['status']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$accidents): ?><tr><td colspan="5" class="text-center text-muted"><?= t('no_records') ?></td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="tab-pane" id="tabAssign">
        <table class="table table-sm">
          <thead><tr><th><?= t('employees') ?></th><th><?= t('assigned_date') ?></th><th><?= t('unassigned_date') ?></th><th><?= t('duty_location') ?></th><th><?= t('shift') ?></th><th><?= t('status') ?></th></tr></thead>
          <tbody>
          <?php foreach ($assignments as $a): ?>
          <tr>
            <td><?= e("[{$a['emp_id']}] {$a['name_en']}") ?></td>
            <td><?= fmtDate($a['assigned_date']) ?></td>
            <td><?= fmtDate($a['unassigned_date']) ?></td>
            <td><?= e($a['loc_en']??'—') ?></td>
            <td><?= t($a['shift']) ?></td>
            <td><?= statusBadge($a['status']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$assignments): ?><tr><td colspan="6" class="text-center text-muted"><?= t('no_records') ?></td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>

    </div>
  </div>
</div>

<?php } // end view
