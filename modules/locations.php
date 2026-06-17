<?php
// modules/locations.php

$pdo    = getDB();
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;
    if ($action === 'add' || $action === 'edit') {
        $fields = [
            'name_en' => trim($d['name_en'] ?? ''),
            'name_ar' => trim($d['name_ar'] ?? ''),
            'city_en' => trim($d['city_en'] ?? ''),
            'city_ar' => trim($d['city_ar'] ?? ''),
            'status'  => $d['status'] ?? 'active',
        ];
        if (!$fields['name_en']) { setFlash('danger', t('required_fields')); }
        else {
            try {
                if ($action === 'add') {
                    $cols=implode(',',array_keys($fields)); $vals=implode(',',array_fill(0,count($fields),'?'));
                    $pdo->prepare("INSERT INTO duty_locations ($cols) VALUES ($vals)")->execute(array_values($fields));
                    setFlash('success', t('record_saved'));
                } else {
                    $set=implode('=?,',array_keys($fields)).'=?';
                    $pdo->prepare("UPDATE duty_locations SET $set WHERE id=?")->execute([...array_values($fields),$id]);
                    setFlash('success', t('record_updated'));
                }
            } catch (PDOException $e) { setFlash('danger', t('error_occurred').' '.$e->getMessage()); }
        }
        header("Location: ?module=locations"); exit;
    }
    if ($action === 'delete') {
        try {
            $pdo->prepare("DELETE FROM duty_locations WHERE id=?")->execute([$id]);
            setFlash('success', t('record_deleted'));
        } catch(PDOException $e) { setFlash('danger', 'Cannot delete — location is in use.'); }
        header("Location: ?module=locations"); exit;
    }
}

$search = trim($_GET['q'] ?? '');
$sql = "SELECT dl.*, 
               (SELECT COUNT(*) FROM employees e WHERE e.duty_location_id=dl.id) AS emp_count,
               (SELECT COUNT(*) FROM driver_assignments da WHERE da.duty_location_id=dl.id AND da.status='active') AS assign_count
        FROM duty_locations dl WHERE 1=1";
$params=[];
if ($search) { $sql.=" AND (dl.name_en LIKE ? OR dl.name_ar LIKE ? OR dl.city_en LIKE ?)"; for($i=0;$i<3;$i++) $params[]="%$search%"; }
$sql.=" ORDER BY dl.name_en";
$stmt=$pdo->prepare($sql); $stmt->execute($params);
$rows=$stmt->fetchAll();
?>

<div class="card mb-3"><div class="card-body py-2">
  <form method="get" class="row g-2 align-items-end">
    <input type="hidden" name="module" value="locations">
    <div class="col-md-4"><input name="q" class="form-control form-control-sm" placeholder="<?= t('search') ?>" value="<?= e($search) ?>"></div>
    <div class="col-auto"><button class="btn btn-sm btn-primary"><?= t('filter') ?></button></div>
    <div class="col-auto"><a href="?module=locations" class="btn btn-sm btn-outline-secondary"><?= t('reset') ?></a></div>
    <div class="col-auto ms-auto"><button type="button" class="btn btn-sm btn-success" onclick="openAddModal()"><i class="fas fa-plus me-1"></i><?= t('add_location') ?></button></div>
  </form>
</div></div>

<div class="card"><div class="card-body p-0">
<table class="table table-hover align-middle mb-0">
  <thead class="table-dark"><tr>
    <th>#</th><th><?= t('name') ?> (EN)</th><th><?= t('name') ?> (AR)</th>
    <th><?= t('city') ?></th><th><?= t('employees') ?></th><th><?= t('assignments') ?></th>
    <th><?= t('status') ?></th><th><?= t('actions') ?></th>
  </tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-3"><?= t('no_records') ?></td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= $r['id'] ?></td>
    <td><?= e($r['name_en']) ?></td>
    <td dir="rtl"><?= e($r['name_ar']?:'—') ?></td>
    <td><?= e($r['city_en']?:'—') ?></td>
    <td><span class="badge bg-info text-dark"><?= $r['emp_count'] ?></span></td>
    <td><span class="badge bg-primary"><?= $r['assign_count'] ?></span></td>
    <td><?= statusBadge($r['status']) ?></td>
    <td>
      <button class="btn btn-xs btn-outline-primary" onclick="openEditModal(<?= htmlspecialchars(json_encode($r),ENT_QUOTES) ?>)"><i class="fas fa-edit"></i></button>
      <button class="btn btn-xs btn-outline-danger" onclick="confirmDelete(<?= $r['id'] ?>,'<?= e($r['name_en']) ?>')"><i class="fas fa-trash"></i></button>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div><div class="card-footer text-muted small"><?= count($rows) ?> <?= t('total') ?></div></div>

<div class="modal fade" id="locModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" id="locForm">
        <input type="hidden" name="action" id="fAction" value="add">
        <input type="hidden" name="id" id="fId" value="">
        <div class="modal-header bg-secondary text-white">
          <h5 class="modal-title" id="modalTitle"><?= t('add_location') ?></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label"><?= t('name') ?> (EN) *</label><input name="name_en" id="fNameEn" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label"><?= t('name') ?> (AR)</label><input name="name_ar" id="fNameAr" class="form-control" dir="rtl"></div>
            <div class="col-md-6"><label class="form-label"><?= t('city') ?> (EN)</label><input name="city_en" id="fCityEn" class="form-control"></div>
            <div class="col-md-6"><label class="form-label"><?= t('city') ?> (AR)</label><input name="city_ar" id="fCityAr" class="form-control" dir="rtl"></div>
            <div class="col-md-6"><label class="form-label"><?= t('status') ?></label>
              <select name="status" id="fStatus" class="form-select">
                <option value="active"><?= t('active') ?></option>
                <option value="inactive"><?= t('inactive') ?></option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('cancel') ?></button>
          <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i><?= t('save') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>
<form id="deleteForm" method="post"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="deleteId"></form>
<script>
function openAddModal(){document.getElementById('fAction').value='add';document.getElementById('fId').value='';document.getElementById('locForm').reset();new bootstrap.Modal(document.getElementById('locModal')).show();}
function openEditModal(r){document.getElementById('fAction').value='edit';document.getElementById('fId').value=r.id;['name_en','name_ar','city_en','city_ar','status'].forEach(k=>{const el=document.querySelector('[name='+k+']');if(el)el.value=r[k]??'';});new bootstrap.Modal(document.getElementById('locModal')).show();}
function confirmDelete(id,nm){if(confirm('<?= t('confirm_delete') ?>\n'+nm)){document.getElementById('deleteId').value=id;document.getElementById('deleteForm').submit();}}
document.getElementById('locForm').addEventListener('submit',function(){this.action='?module=locations&action='+document.getElementById('fAction').value+(document.getElementById('fId').value?'&id='+document.getElementById('fId').value:'');});
</script>
