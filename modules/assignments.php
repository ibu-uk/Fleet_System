<?php
// modules/assignments.php

$pdo    = getDB();
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$lang   = $_SESSION['lang'] ?? 'en';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;
    if ($action === 'add' || $action === 'edit') {
        $fields = [
            'vehicle_id'      => (int)$d['vehicle_id'],
            'employee_id'     => (int)$d['employee_id'],
            'assigned_date'   => $d['assigned_date'],
            'unassigned_date' => $d['unassigned_date'] ?: null,
            'duty_location_id'=> $d['duty_location_id'] ?: null,
            'shift'           => $d['shift'] ?? 'full_day',
            'status'          => $d['status'] ?? 'active',
            'notes'           => trim($d['notes'] ?? ''),
        ];
        if (!$fields['vehicle_id'] || !$fields['employee_id'] || !$fields['assigned_date']) {
            setFlash('danger', t('required_fields'));
        } else {
            try {
                // End previous active assignment for this vehicle
                if ($action === 'add') {
                    $pdo->prepare("UPDATE driver_assignments SET status='ended', unassigned_date=CURDATE() WHERE vehicle_id=? AND status='active'")->execute([$fields['vehicle_id']]);
                    $cols = implode(',', array_keys($fields));
                    $vals = implode(',', array_fill(0,count($fields),'?'));
                    $pdo->prepare("INSERT INTO driver_assignments ($cols) VALUES ($vals)")->execute(array_values($fields));
                    // Update vehicle current driver
                    $pdo->prepare("UPDATE vehicles SET current_driver_id=? WHERE id=?")->execute([$fields['employee_id'],$fields['vehicle_id']]);
                    setFlash('success', t('record_saved'));
                } else {
                    $set = implode('=?,', array_keys($fields)).'=?';
                    $pdo->prepare("UPDATE driver_assignments SET $set WHERE id=?")->execute([...array_values($fields),$id]);
                    if ($fields['status']==='ended' && $fields['unassigned_date']) {
                        $pdo->prepare("UPDATE vehicles SET current_driver_id=NULL WHERE current_driver_id=?")->execute([$fields['employee_id']]);
                    }
                    setFlash('success', t('record_updated'));
                }
            } catch (PDOException $e) { setFlash('danger', t('error_occurred').' '.$e->getMessage()); }
        }
        header("Location: ?module=assignments"); exit;
    }
    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM driver_assignments WHERE id=?")->execute([$id]);
        setFlash('success', t('record_deleted'));
        header("Location: ?module=assignments"); exit;
    }
    if ($action === 'end') {
        $pdo->prepare("UPDATE driver_assignments SET status='ended', unassigned_date=CURDATE() WHERE id=?")->execute([$id]);
        $da = $pdo->prepare("SELECT employee_id FROM driver_assignments WHERE id=?"); $da->execute([$id]);
        if ($da = $da->fetch()) $pdo->prepare("UPDATE vehicles SET current_driver_id=NULL WHERE current_driver_id=?")->execute([$da['employee_id']]);
        setFlash('success', t('record_updated'));
        header("Location: ?module=assignments"); exit;
    }
}

$search  = trim($_GET['q'] ?? '');
$fStatus = $_GET['status'] ?? '';
$fType   = $_GET['type'] ?? '';

$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$baseWhere = " FROM driver_assignments da
        JOIN vehicles v ON v.id=da.vehicle_id
        JOIN employees e ON e.id=da.employee_id
        LEFT JOIN duty_locations dl ON dl.id=da.duty_location_id
        WHERE 1=1";
$params=[];
if ($fStatus) { $baseWhere.=" AND da.status=?"; $params[]=$fStatus; }
if ($fType)   { $baseWhere.=" AND v.type=?";    $params[]=$fType; }
if ($search) {
    $baseWhere.=" AND (v.plate_number LIKE ? OR e.name_en LIKE ? OR e.emp_id LIKE ?)";
    for($i=0;$i<3;$i++) $params[]="%$search%";
}
$cStmt=$pdo->prepare("SELECT COUNT(*) ".$baseWhere); $cStmt->execute($params);
$totalRows=(int)$cStmt->fetchColumn();
$totalPages=max(1,(int)ceil($totalRows/$perPage));

$sql="SELECT da.*, v.plate_number, v.make, v.model, v.type AS vtype,
               e.name_en, e.emp_id,
               dl.name_en AS loc_en, dl.name_ar AS loc_ar".$baseWhere;
$sql.=" ORDER BY da.assigned_date DESC LIMIT $perPage OFFSET $offset";
$stmt=$pdo->prepare($sql); $stmt->execute($params);
$rows=$stmt->fetchAll();
?>

<div class="card mb-3"><div class="card-body py-2">
  <form method="get" class="row g-2 align-items-end">
    <input type="hidden" name="module" value="assignments">
    <div class="col-md-3"><input name="q" class="form-control form-control-sm" placeholder="<?= t('search') ?>" value="<?= e($search) ?>"></div>
    <div class="col-auto">
      <select name="status" class="form-select form-select-sm">
        <option value=""><?= t('all') ?></option>
        <option value="active" <?= $fStatus==='active'?'selected':''?>><?= t('active') ?></option>
        <option value="ended" <?= $fStatus==='ended'?'selected':''?>><?= t('ended') ?></option>
      </select>
    </div>
    <div class="col-auto">
      <select name="type" class="form-select form-select-sm">
        <option value=""><?= t('all') ?> <?= t('type') ?></option>
        <option value="car" <?= $fType==='car'?'selected':''?>><?= t('car') ?></option>
        <option value="bike" <?= $fType==='bike'?'selected':''?>><?= t('bike') ?></option>
      </select>
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-primary"><?= t('filter') ?></button></div>
    <div class="col-auto"><a href="?module=assignments" class="btn btn-sm btn-outline-secondary"><?= t('reset') ?></a></div>
    <div class="col-auto ms-auto"><button type="button" class="btn btn-sm btn-success" onclick="openAddModal()"><i class="fas fa-plus me-1"></i><?= t('add_assignment') ?></button></div>
  </form>
</div></div>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover align-middle mb-0">
  <thead class="table-dark"><tr>
    <th>#</th><th><?= t('plate_number') ?></th><th><?= t('employees') ?></th>
    <th><?= t('duty_location') ?></th><th><?= t('shift') ?></th>
    <th><?= t('assigned_date') ?></th><th><?= t('unassigned_date') ?></th>
    <th><?= t('status') ?></th><th><?= t('actions') ?></th>
  </tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-3"><?= t('no_records') ?></td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= $r['id'] ?></td>
    <td><strong><?= e($r['plate_number']) ?></strong><br><small class="text-muted"><?= e($r['make'].' '.$r['model']) ?></small></td>
    <td><?= e("[{$r['emp_id']}] {$r['name_en']}") ?></td>
    <td><?= e($lang==='ar'?($r['loc_ar']??$r['loc_en']):($r['loc_en']??'—')) ?></td>
    <td><?= t($r['shift']) ?></td>
    <td><?= fmtDate($r['assigned_date']) ?></td>
    <td><?= fmtDate($r['unassigned_date']) ?: '<span class="text-muted">—</span>' ?></td>
    <td><?= statusBadge($r['status']) ?></td>
    <td>
      <?php if ($r['status']==='active'): ?>
      <form method="post" style="display:inline" action="?module=assignments&action=end&id=<?= $r['id'] ?>">
        <input type="hidden" name="action" value="end"><input type="hidden" name="id" value="<?= $r['id'] ?>">
        <button class="btn btn-xs btn-warning" onclick="return confirm('End this assignment?')" title="<?= t('end_assignment') ?>"><i class="fas fa-stop"></i></button>
      </form>
      <?php endif; ?>
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
  <li class="page-item <?= $p===$page?'active':'' ?>"><a class="page-link" href="?module=assignments&page=<?= $p ?>&q=<?= e($search) ?>&status=<?= e($fStatus) ?>&type=<?= e($fType) ?>"><?= $p ?></a></li>
  <?php endfor; ?>
</ul></nav>
<?php endif; ?>

<!-- Modal -->
<div class="modal fade" id="assignModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" id="assignForm">
        <input type="hidden" name="action" id="fAction" value="add">
        <input type="hidden" name="id" id="fId" value="">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title" id="modalTitle"><?= t('add_assignment') ?></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label"><?= t('vehicles') ?> *</label><select name="vehicle_id" id="fVeh" class="form-select" required><?= vehicleOptions() ?></select></div>
            <div class="col-md-6"><label class="form-label"><?= t('employees') ?> *</label><select name="employee_id" id="fEmp" class="form-select" required><?= employeeOptions() ?></select></div>
            <div class="col-md-4"><label class="form-label"><?= t('assigned_date') ?> *</label><input name="assigned_date" id="fAsgDate" type="date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-md-4"><label class="form-label"><?= t('unassigned_date') ?></label><input name="unassigned_date" id="fUnasgDate" type="date" class="form-control"></div>
            <div class="col-md-4"><label class="form-label"><?= t('shift') ?></label>
              <select name="shift" id="fShift" class="form-select">
                <?php foreach (['full_day','morning','evening','night'] as $s): ?><option value="<?= $s ?>"><?= t($s) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label"><?= t('duty_location') ?></label><select name="duty_location_id" id="fLoc" class="form-select"><?= locationOptions() ?></select></div>
            <div class="col-md-3"><label class="form-label"><?= t('status') ?></label>
              <select name="status" id="fStatus" class="form-select">
                <option value="active"><?= t('active') ?></option>
                <option value="ended"><?= t('ended') ?></option>
              </select>
            </div>
            <div class="col-12"><label class="form-label"><?= t('notes') ?></label><textarea name="notes" id="fNotes" class="form-control" rows="2"></textarea></div>
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
<form id="deleteForm" method="post"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="deleteId"></form>
<script>
function openAddModal(){document.getElementById('fAction').value='add';document.getElementById('fId').value='';document.getElementById('modalTitle').textContent='<?= t('add_assignment') ?>';document.getElementById('assignForm').reset();document.querySelector('[name=assigned_date]').value='<?= date('Y-m-d') ?>';new bootstrap.Modal(document.getElementById('assignModal')).show();}
function openEditModal(r){document.getElementById('fAction').value='edit';document.getElementById('fId').value=r.id;document.getElementById('modalTitle').textContent='<?= t('edit_assignment') ?>';['vehicle_id','employee_id','assigned_date','unassigned_date','shift','duty_location_id','status','notes'].forEach(k=>{const el=document.querySelector('[name='+k+']');if(el)el.value=r[k]??'';});new bootstrap.Modal(document.getElementById('assignModal')).show();}
function confirmDelete(id){if(confirm('<?= t('confirm_delete') ?>')){const f=document.getElementById('deleteForm');f.action='?module=assignments&action=delete&id='+id;f.submit();}}
document.getElementById('assignForm').addEventListener('submit',function(){this.action='?module=assignments&action='+document.getElementById('fAction').value+(document.getElementById('fId').value?'&id='+document.getElementById('fId').value:'');});
</script>
