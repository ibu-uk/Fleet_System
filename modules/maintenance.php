<?php
// modules/maintenance.php — independent copy of services (uses vehicle_maintenance table)

$pdo    = getDB();
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$preVeh = (int)($_GET['vehicle_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;
    if ($action === 'add' || $action === 'edit') {
        $vid = (int)$d['vehicle_id'];
        $sKm = (int)$d['service_km'];
        $interval = (int)($d['service_interval_override'] ?: 5000);
        $nextKm = $sKm + $interval;
        $svcNumber = (int)($d['service_number'] ?? 1);

        $fields = [
            'vehicle_id'      => $vid,
            'service_date'    => $d['service_date'],
            'service_km'      => $sKm,
            'service_type'    => $d['service_type'],
            'service_number'  => $svcNumber,
            'next_service_km' => $d['next_service_km'] ?: $nextKm,
            'cost'            => $d['cost'] ?: null,
            'garage_name'     => trim($d['garage_name'] ?? ''),
            'performed_by'    => trim($d['performed_by'] ?? ''),
            'notes'           => trim($d['notes'] ?? ''),
        ];
        if (!$vid || !$fields['service_date'] || !$fields['service_type']) {
            setFlash('danger', t('required_fields'));
        } else {
            try {
                if ($action === 'add') {
                    $cols=implode(',',array_keys($fields)); $vals=implode(',',array_fill(0,count($fields),'?'));
                    $pdo->prepare("INSERT INTO vehicle_maintenance ($cols) VALUES ($vals)")->execute(array_values($fields));
                    // Update vehicle current KM if higher
                    $pdo->prepare("UPDATE vehicles SET current_km=GREATEST(current_km,?) WHERE id=?")->execute([$sKm,$vid]);
                    setFlash('success', t('record_saved'));
                } else {
                    $set=implode('=?,',array_keys($fields)).'=?';
                    $pdo->prepare("UPDATE vehicle_maintenance SET $set WHERE id=?")->execute([...array_values($fields),$id]);
                    setFlash('success', t('record_updated'));
                }
            } catch (PDOException $e) { setFlash('danger', t('error_occurred').' '.$e->getMessage()); }
        }
        header("Location: ?module=maintenance"); exit;
    }
    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM vehicle_maintenance WHERE id=?")->execute([$id]);
        setFlash('success', t('record_deleted'));
        header("Location: ?module=maintenance"); exit;
    }
}

$search   = trim($_GET['q'] ?? '');
$fType    = $_GET['stype'] ?? '';
$fVeh     = (int)($_GET['vehicle_id'] ?? 0);
$fVehType = $_GET['vtype'] ?? '';

$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$baseWhere = " FROM vehicle_maintenance vs
        JOIN vehicles v ON v.id=vs.vehicle_id
        LEFT JOIN employees e ON e.id=v.current_driver_id
        WHERE 1=1";
$params=[];
if ($fType)    { $baseWhere.=" AND vs.service_type=?"; $params[]=$fType; }
if ($fVeh)     { $baseWhere.=" AND vs.vehicle_id=?";   $params[]=$fVeh; }
if ($fVehType) { $baseWhere.=" AND v.type=?";          $params[]=$fVehType; }
if ($search) {
    $baseWhere.=" AND (v.plate_number LIKE ? OR v.make LIKE ? OR vs.garage_name LIKE ?)";
    for($i=0;$i<3;$i++) $params[]="%$search%";
}
$cStmt=$pdo->prepare("SELECT COUNT(*) ".$baseWhere); $cStmt->execute($params);
$totalRows=(int)$cStmt->fetchColumn();
$totalPages=max(1,(int)ceil($totalRows/$perPage));

$sql="SELECT vs.*, v.plate_number, v.make, v.model, v.type AS vtype, v.current_km,
               COALESCE(e.name_en,'—') AS driver_name".$baseWhere;
$sql.=" ORDER BY vs.service_date DESC, vs.id DESC LIMIT $perPage OFFSET $offset";
$stmt=$pdo->prepare($sql); $stmt->execute($params);
$rows=$stmt->fetchAll();

$serviceTypes=['oil_change','tire_rotation','tire_replacement','brake','engine',
               'transmission','battery','ac','general_checkup','major_service','other'];
?>

<div class="card mb-3"><div class="card-body py-2">
  <form method="get" class="row g-2 align-items-end">
    <input type="hidden" name="module" value="maintenance">
    <div class="col-md-3"><input name="q" class="form-control form-control-sm" placeholder="<?= t('search') ?>" value="<?= e($search) ?>"></div>
    <div class="col-auto">
      <select name="stype" class="form-select form-select-sm">
        <option value=""><?= t('all') ?> <?= t('service_type') ?></option>
        <?php foreach ($serviceTypes as $s): ?><option value="<?= $s ?>" <?= $fType===$s?'selected':''?>><?= t($s) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <select name="vtype" class="form-select form-select-sm">
        <option value=""><?= t('all') ?> <?= t('vehicle_type') ?></option>
        <option value="car" <?= $fVehType==='car'?'selected':''?>><?= t('car') ?></option>
        <option value="bike" <?= $fVehType==='bike'?'selected':''?>><?= t('bike') ?></option>
      </select>
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-primary"><?= t('filter') ?></button></div>
    <div class="col-auto"><a href="?module=maintenance" class="btn btn-sm btn-outline-secondary"><?= t('reset') ?></a></div>
    <div class="col-auto ms-auto"><button type="button" class="btn btn-sm btn-success" onclick="openAddModal()"><i class="fas fa-plus me-1"></i><?= t('add_maintenance') ?></button></div>
  </form>
</div></div>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover align-middle mb-0">
  <thead class="table-dark"><tr>
    <th>#</th><th><?= t('plate_number') ?></th><th><?= t('service_date') ?></th>
    <th><?= t('service_type') ?></th><th><?= t('service_number') ?></th>
    <th><?= t('service_km') ?></th><th><?= t('next_service_km') ?></th>
    <th><?= t('current_km') ?></th><th><?= t('cost') ?></th><th><?= t('garage_name') ?></th><th><?= t('actions') ?></th>
  </tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="11" class="text-center text-muted py-3"><?= t('no_records') ?></td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= $r['id'] ?></td>
    <td><strong><?= e($r['plate_number']) ?></strong><br><small class="text-muted"><?= e($r['make'].' '.$r['model']) ?></small></td>
    <td><?= fmtDate($r['service_date']) ?></td>
    <td><span class="badge bg-primary"><?= t($r['service_type']) ?></span></td>
    <td class="text-center"><?= $r['service_number'] ?></td>
    <td><?= number_format($r['service_km']) ?> km</td>
    <td><?= number_format($r['next_service_km']) ?> km</td>
    <td><?= number_format($r['current_km']) ?> km
      <?php $diff=$r['next_service_km']-$r['current_km'];
        if ($diff<=0) echo '<span class="badge bg-danger ms-1">'.t('overdue').' '.abs($diff).'km</span>';
        elseif ($diff<=1000) echo '<span class="badge bg-warning text-dark ms-1">'.t('due_in').' '.$diff.'km</span>';
      ?>
    </td>
    <td><?= $r['cost'] ? 'KWD '.number_format($r['cost'],3) : '—' ?></td>
    <td><?= e($r['garage_name']?:'—') ?></td>
    <td>
      <button class="btn btn-xs btn-outline-primary" onclick="openEditModal(<?= htmlspecialchars(json_encode($r),ENT_QUOTES) ?>)"><i class="fas fa-edit"></i></button>
      <button class="btn btn-xs btn-outline-danger" onclick="confirmDelete(<?= $r['id'] ?>)"><i class="fas fa-trash"></i></button>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div></div><div class="card-footer text-muted small"><?= $totalRows ?> <?= t('total') ?></div></div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center">
  <?php for ($p=1;$p<=$totalPages;$p++): ?>
  <li class="page-item <?= $p===$page?'active':'' ?>"><a class="page-link" href="?module=maintenance&page=<?= $p ?>&q=<?= e($search) ?>&stype=<?= e($fType) ?>&vtype=<?= e($fVehType) ?>&vehicle_id=<?= $fVeh ?>"><?= $p ?></a></li>
  <?php endfor; ?>
</ul></nav>
<?php endif; ?>

<!-- Modal -->
<div class="modal fade" id="svcModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" id="svcForm">
        <input type="hidden" name="action" id="fAction" value="add">
        <input type="hidden" name="id" id="fId" value="">
        <div class="modal-header bg-warning text-dark">
          <h5 class="modal-title" id="modalTitle"><?= t('add_maintenance') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label"><?= t('vehicles') ?> *</label><select name="vehicle_id" id="fVeh" class="form-select" required onchange="loadVehicleKm(this.value)"><?= vehicleOptions($preVeh ?: null) ?></select></div>
            <div class="col-md-3"><label class="form-label"><?= t('service_date') ?> *</label><input name="service_date" id="fDate" type="date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-md-3"><label class="form-label"><?= t('service_number') ?></label><input name="service_number" id="fSvcNo" type="number" class="form-control" value="1" min="1"></div>
            <div class="col-md-4">
              <label class="form-label"><?= t('service_type') ?> *</label>
              <select name="service_type" id="fSvcType" class="form-select" required>
                <?php foreach ($serviceTypes as $s): ?><option value="<?= $s ?>"><?= t($s) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4"><label class="form-label"><?= t('service_km') ?></label><input name="service_km" id="fSvcKm" type="number" class="form-control" min="0" oninput="calcNextKm()"></div>
            <div class="col-md-4"><label class="form-label"><?= t('next_service_km') ?></label><input name="next_service_km" id="fNextKm" type="number" class="form-control" min="0"><small class="text-muted">Auto-calculated or override</small></div>
            <div class="col-md-3"><label class="form-label"><?= t('cost') ?></label><input name="cost" id="fCost" type="number" step="0.001" class="form-control"></div>
            <div class="col-md-3"><label class="form-label">Interval KM</label><input name="service_interval_override" id="fInterval" type="number" class="form-control" value="5000" min="500" oninput="calcNextKm()"><small class="text-muted">For next KM calc</small></div>
            <div class="col-md-3"><label class="form-label"><?= t('garage_name') ?></label><input name="garage_name" id="fGarage" class="form-control"></div>
            <div class="col-md-3"><label class="form-label"><?= t('performed_by') ?></label><input name="performed_by" id="fBy" class="form-control"></div>
            <div class="col-12"><label class="form-label"><?= t('notes') ?></label><textarea name="notes" id="fNotes" class="form-control" rows="2"></textarea></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('cancel') ?></button>
          <button type="submit" class="btn btn-warning text-dark"><i class="fas fa-save me-1"></i><?= t('save') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>
<form id="deleteForm" method="post"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="deleteId"></form>
<script>
function calcNextKm(){
  const km=parseInt(document.getElementById('fSvcKm').value)||0;
  const int=parseInt(document.getElementById('fInterval').value)||5000;
  if(km>0) document.getElementById('fNextKm').value=km+int;
}
function loadVehicleKm(vehId){
  if(!vehId) return;
  fetch('?module=api&action=get_vehicle_km&id='+vehId)
    .then(r=>r.json())
    .then(d=>{
      if(d.current_km){
        document.getElementById('fSvcKm').value=d.current_km;
        calcNextKm();
      }
    });
}
function openAddModal(){
  document.getElementById('fAction').value='add';document.getElementById('fId').value='';
  document.getElementById('modalTitle').textContent='<?= t('add_maintenance') ?>';
  document.getElementById('svcForm').reset();
  document.querySelector('[name=service_date]').value='<?= date('Y-m-d') ?>';
  document.getElementById('fInterval').value=5000;
  <?php if ($preVeh): ?>document.getElementById('fVeh').value='<?= $preVeh ?>';<?php endif; ?>
  new bootstrap.Modal(document.getElementById('svcModal')).show();
}
function openEditModal(r){
  document.getElementById('fAction').value='edit';document.getElementById('fId').value=r.id;
  document.getElementById('modalTitle').textContent='<?= t('edit_maintenance') ?>';
  ['vehicle_id','service_date','service_km','service_type','service_number','next_service_km','cost','garage_name','performed_by','notes'].forEach(k=>{const el=document.querySelector('[name='+k+']');if(el)el.value=r[k]??'';});
  new bootstrap.Modal(document.getElementById('svcModal')).show();
}
function confirmDelete(id){if(confirm('<?= t('confirm_delete') ?>')){const f=document.getElementById('deleteForm');f.action='?module=maintenance&action=delete&id='+id;f.submit();}}
document.getElementById('svcForm').addEventListener('submit',function(){this.action='?module=maintenance&action='+document.getElementById('fAction').value+(document.getElementById('fId').value?'&id='+document.getElementById('fId').value:'');});
<?php if ($preVeh): ?>window.addEventListener('DOMContentLoaded',()=>openAddModal());<?php endif; ?>
</script>
