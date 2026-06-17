<?php
// modules/accidents.php

$pdo    = getDB();
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$preVeh = (int)($_GET['vehicle_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;
    if ($action === 'add' || $action === 'edit') {
        $vid = (int)$d['vehicle_id'];
        $fields = [
            'vehicle_id'             => $vid,
            'driver_id'              => $d['driver_id'] ?: null,
            'accident_date'          => $d['accident_date'],
            'accident_time'          => $d['accident_time'] ?: null,
            'location'               => trim($d['location'] ?? ''),
            'description'            => trim($d['description'] ?? ''),
            'damage_level'           => $d['damage_level'] ?? 'minor',
            'repair_cost'            => $d['repair_cost'] ?: null,
            'insurance_claim'        => isset($d['insurance_claim']) ? 1 : 0,
            'claim_number'           => trim($d['claim_number'] ?? ''),
            'at_fault'               => $d['at_fault'] ?? 'unknown',
            'police_report'          => isset($d['police_report']) ? 1 : 0,
            'police_report_number'   => trim($d['police_report_number'] ?? ''),
            'status'                 => $d['status'] ?? 'reported',
            'repair_completion_date' => $d['repair_completion_date'] ?: null,
            'notes'                  => trim($d['notes'] ?? ''),
        ];
        if (!$vid || !$fields['accident_date']) { setFlash('danger', t('required_fields')); }
        else {
            try {
                if ($action === 'add') {
                    $cols=implode(',',array_keys($fields)); $vals=implode(',',array_fill(0,count($fields),'?'));
                    $pdo->prepare("INSERT INTO vehicle_accidents ($cols) VALUES ($vals)")->execute(array_values($fields));
                    // Mark vehicle as in accident
                    if (in_array($fields['status'],['reported','under_assessment','under_repair'])) {
                        $pdo->prepare("UPDATE vehicles SET status='accident' WHERE id=?")->execute([$vid]);
                    }
                    setFlash('success', t('record_saved'));
                } else {
                    $set=implode('=?,',array_keys($fields)).'=?';
                    $pdo->prepare("UPDATE vehicle_accidents SET $set WHERE id=?")->execute([...array_values($fields),$id]);
                    // Restore vehicle status if repaired/closed
                    if (in_array($fields['status'],['repaired','written_off','closed'])) {
                        $pdo->prepare("UPDATE vehicles SET status='active' WHERE id=? AND status='accident'")->execute([$vid]);
                    }
                    setFlash('success', t('record_updated'));
                }
            } catch (PDOException $e) { setFlash('danger', t('error_occurred').' '.$e->getMessage()); }
        }
        header("Location: ?module=accidents"); exit;
    }
    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM vehicle_accidents WHERE id=?")->execute([$id]);
        setFlash('success', t('record_deleted'));
        header("Location: ?module=accidents"); exit;
    }
}

$search   = trim($_GET['q'] ?? '');
$fStatus  = $_GET['status'] ?? '';
$fDamage  = $_GET['damage'] ?? '';
$fVehType = $_GET['vtype'] ?? '';

$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$baseWhere = " FROM vehicle_accidents va
        JOIN vehicles v ON v.id=va.vehicle_id
        LEFT JOIN employees e ON e.id=va.driver_id
        WHERE 1=1";
$params=[];
if ($fStatus)  { $baseWhere.=" AND va.status=?";        $params[]=$fStatus; }
if ($fDamage)  { $baseWhere.=" AND va.damage_level=?";  $params[]=$fDamage; }
if ($fVehType) { $baseWhere.=" AND v.type=?";           $params[]=$fVehType; }
if ($search) {
    $baseWhere.=" AND (v.plate_number LIKE ? OR va.location LIKE ? OR e.name_en LIKE ?)";
    for($i=0;$i<3;$i++) $params[]="%$search%";
}
$cStmt=$pdo->prepare("SELECT COUNT(*) ".$baseWhere); $cStmt->execute($params);
$totalRows=(int)$cStmt->fetchColumn();
$totalPages=max(1,(int)ceil($totalRows/$perPage));

$sql="SELECT va.*, v.plate_number, v.make, v.model, v.type AS vtype,
               COALESCE(e.name_en,'—') AS driver_name".$baseWhere;
$sql.=" ORDER BY va.accident_date DESC, va.id DESC LIMIT $perPage OFFSET $offset";
$stmt=$pdo->prepare($sql); $stmt->execute($params);
$rows=$stmt->fetchAll();

$damageColors=['minor'=>'success','moderate'=>'warning','severe'=>'danger','total_loss'=>'dark'];
?>

<div class="card mb-3"><div class="card-body py-2">
  <form method="get" class="row g-2 align-items-end">
    <input type="hidden" name="module" value="accidents">
    <div class="col-md-3"><input name="q" class="form-control form-control-sm" placeholder="<?= t('search') ?>" value="<?= e($search) ?>"></div>
    <div class="col-auto">
      <select name="status" class="form-select form-select-sm">
        <option value=""><?= t('all') ?> <?= t('status') ?></option>
        <?php foreach (['reported','under_assessment','under_repair','repaired','written_off','closed'] as $s): ?>
        <option value="<?= $s ?>" <?= $fStatus===$s?'selected':''?>><?= t($s) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <select name="damage" class="form-select form-select-sm">
        <option value=""><?= t('all') ?> <?= t('damage_level') ?></option>
        <?php foreach (['minor','moderate','severe','total_loss'] as $s): ?><option value="<?= $s ?>" <?= $fDamage===$s?'selected':''?>><?= t($s) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <select name="vtype" class="form-select form-select-sm">
        <option value=""><?= t('all') ?></option>
        <option value="car" <?= $fVehType==='car'?'selected':''?>><?= t('car') ?></option>
        <option value="bike" <?= $fVehType==='bike'?'selected':''?>><?= t('bike') ?></option>
      </select>
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-primary"><?= t('filter') ?></button></div>
    <div class="col-auto"><a href="?module=accidents" class="btn btn-sm btn-outline-secondary"><?= t('reset') ?></a></div>
    <div class="col-auto ms-auto"><button type="button" class="btn btn-sm btn-danger" onclick="openAddModal()"><i class="fas fa-plus me-1"></i><?= t('add_accident') ?></button></div>
  </form>
</div></div>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover align-middle mb-0">
  <thead class="table-dark"><tr>
    <th>#</th><th><?= t('plate_number') ?></th><th><?= t('date') ?></th>
    <th><?= t('location') ?></th><th><?= t('assigned_driver') ?></th>
    <th><?= t('damage_level') ?></th><th><?= t('at_fault') ?></th>
    <th><?= t('repair_cost') ?></th><th><?= t('insurance_claim') ?></th>
    <th><?= t('police_report') ?></th><th><?= t('status') ?></th><th><?= t('actions') ?></th>
  </tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="12" class="text-center text-muted py-3"><?= t('no_records') ?></td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= $r['id'] ?></td>
    <td><strong><?= e($r['plate_number']) ?></strong><br><small class="text-muted"><?= e($r['make'].' '.$r['model']) ?></small></td>
    <td><?= fmtDate($r['accident_date']) ?><?= $r['accident_time']?'<br><small class="text-muted">'.e($r['accident_time']).'</small>':'' ?></td>
    <td><?= e($r['location']?:'—') ?></td>
    <td><?= e($r['driver_name']) ?></td>
    <td><span class="badge bg-<?= $damageColors[$r['damage_level']] ?>"><?= t($r['damage_level']) ?></span></td>
    <td><?= t($r['at_fault']) ?></td>
    <td><?= $r['repair_cost'] ? 'KWD '.number_format($r['repair_cost'],3) : '—' ?></td>
    <td><?= $r['insurance_claim'] ? '<span class="badge bg-success">'.t('yes').'</span>'.($r['claim_number']?'<br><small>'.e($r['claim_number']).'</small>':'') : '<span class="badge bg-secondary">'.t('no').'</span>' ?></td>
    <td><?= $r['police_report'] ? '<span class="badge bg-info text-dark">'.t('yes').'</span>'.($r['police_report_number']?'<br><small>'.e($r['police_report_number']).'</small>':'') : '—' ?></td>
    <td><?= statusBadge($r['status']) ?></td>
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
  <li class="page-item <?= $p===$page?'active':'' ?>"><a class="page-link" href="?module=accidents&page=<?= $p ?>&q=<?= e($search) ?>&status=<?= e($fStatus) ?>&damage=<?= e($fDamage) ?>&vtype=<?= e($fVehType) ?>"><?= $p ?></a></li>
  <?php endfor; ?>
</ul></nav>
<?php endif; ?>

<!-- Modal -->
<div class="modal fade" id="accModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <form method="post" id="accForm">
        <input type="hidden" name="action" id="fAction" value="add">
        <input type="hidden" name="id" id="fId" value="">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title" id="modalTitle"><?= t('add_accident') ?></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label"><?= t('vehicles') ?> *</label><select name="vehicle_id" id="fVeh" class="form-select" required onchange="accLoadDriver(this)"><?= vehicleOptions($preVeh ?: null) ?></select></div>
            <div class="col-md-6"><label class="form-label"><?= t('assigned_driver') ?></label><select name="driver_id" id="fDriver" class="form-select"><?= employeeOptions() ?></select></div>
            <div class="col-md-3"><label class="form-label"><?= t('accident_date') ?> *</label><input name="accident_date" id="fDate" type="date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-md-3"><label class="form-label"><?= t('accident_time') ?></label><input name="accident_time" id="fTime" type="time" class="form-control"></div>
            <div class="col-md-6"><label class="form-label"><?= t('location') ?></label><input name="location" id="fLoc" class="form-control"></div>
            <div class="col-md-3">
              <label class="form-label"><?= t('damage_level') ?></label>
              <select name="damage_level" id="fDmg" class="form-select">
                <?php foreach (['minor','moderate','severe','total_loss'] as $s): ?><option value="<?= $s ?>"><?= t($s) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label"><?= t('at_fault') ?></label>
              <select name="at_fault" id="fFault" class="form-select">
                <?php foreach (['unknown','our_driver','third_party','shared'] as $s): ?><option value="<?= $s ?>"><?= t($s) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label"><?= t('status') ?></label>
              <select name="status" id="fStatus" class="form-select">
                <?php foreach (['reported','under_assessment','under_repair','repaired','written_off','closed'] as $s): ?><option value="<?= $s ?>"><?= t($s) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3"><label class="form-label"><?= t('repair_cost') ?></label><input name="repair_cost" id="fCost" type="number" step="0.001" class="form-control"></div>
            <div class="col-md-3"><label class="form-label"><?= t('repair_done_date') ?></label><input name="repair_completion_date" id="fRepDate" type="date" class="form-control"></div>
            <div class="col-md-12">
              <div class="row g-2">
                <div class="col-auto pt-4">
                  <div class="form-check"><input class="form-check-input" type="checkbox" name="insurance_claim" id="fInsClaim" value="1"><label class="form-check-label" for="fInsClaim"><?= t('insurance_claim') ?></label></div>
                </div>
                <div class="col-md-4"><label class="form-label"><?= t('claim_number') ?></label><input name="claim_number" id="fClaimNo" class="form-control"></div>
                <div class="col-auto pt-4">
                  <div class="form-check"><input class="form-check-input" type="checkbox" name="police_report" id="fPolice" value="1"><label class="form-check-label" for="fPolice"><?= t('police_report') ?></label></div>
                </div>
                <div class="col-md-4"><label class="form-label"><?= t('police_report_no') ?></label><input name="police_report_number" id="fPoliceNo" class="form-control"></div>
              </div>
            </div>
            <div class="col-12"><label class="form-label"><?= t('description') ?></label><textarea name="description" id="fDesc" class="form-control" rows="3"></textarea></div>
            <div class="col-12"><label class="form-label"><?= t('notes') ?></label><textarea name="notes" id="fNotes" class="form-control" rows="2"></textarea></div>
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
<form id="deleteForm" method="post"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="deleteId"></form>
<script>
function openAddModal(){document.getElementById('fAction').value='add';document.getElementById('fId').value='';document.getElementById('modalTitle').textContent='<?= t('add_accident') ?>';document.getElementById('accForm').reset();document.querySelector('[name=accident_date]').value='<?= date('Y-m-d') ?>';<?php if($preVeh):?>document.getElementById('fVeh').value='<?=$preVeh?>';<?php endif;?>new bootstrap.Modal(document.getElementById('accModal')).show();}
function openEditModal(r){
  document.getElementById('fAction').value='edit';document.getElementById('fId').value=r.id;
  document.getElementById('modalTitle').textContent='<?= t('edit_accident') ?>';
  ['vehicle_id','driver_id','accident_date','accident_time','location','description','damage_level','repair_cost','claim_number','at_fault','police_report_number','status','repair_completion_date','notes'].forEach(k=>{const el=document.querySelector('[name='+k+']');if(el)el.value=r[k]??'';});
  document.getElementById('fInsClaim').checked=r.insurance_claim=='1';
  document.getElementById('fPolice').checked=r.police_report=='1';
  new bootstrap.Modal(document.getElementById('accModal')).show();
}
function confirmDelete(id){if(confirm('<?= t('confirm_delete') ?>')){const f=document.getElementById('deleteForm');f.action='?module=accidents&action=delete&id='+id;f.submit();}}
function accLoadDriver(sel){const opt=sel.options[sel.selectedIndex];const did=opt?opt.dataset.driverId:'0';const dn=opt?opt.dataset.driverName:'';const ds=document.getElementById('fDriver');if(did&&did!=='0'){ds.innerHTML='<option value="'+did+'">'+dn+'</option>';ds.value=did;}else{ds.innerHTML='<?= employeeOptions() ?>';}}
document.getElementById('accForm').addEventListener('submit',function(){this.action='?module=accidents&action='+document.getElementById('fAction').value+(document.getElementById('fId').value?'&id='+document.getElementById('fId').value:'');});
<?php if($preVeh):?>window.addEventListener('DOMContentLoaded',()=>openAddModal());<?php endif;?>
</script>
