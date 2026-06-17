<?php
// modules/insurance.php

$pdo    = getDB();
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$preVeh = (int)($_GET['vehicle_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;
    if ($action === 'add' || $action === 'edit') {
        $fields = [
            'vehicle_id'        => (int)$d['vehicle_id'],
            'insurance_company' => trim($d['insurance_company'] ?? ''),
            'policy_number'     => trim($d['policy_number'] ?? ''),
            'insurance_type'    => $d['insurance_type'] ?? 'comprehensive',
            'start_date'        => $d['start_date'],
            'expiry_date'       => $d['expiry_date'],
            'amount'            => $d['amount'] ?: null,
            'status'            => $d['status'] ?? 'active',
            'notes'             => trim($d['notes'] ?? ''),
        ];
        if (!$fields['vehicle_id'] || !$fields['insurance_company'] || !$fields['expiry_date']) {
            setFlash('danger', t('required_fields'));
        } else {
            try {
                if ($action === 'add') {
                    $cols=implode(',',array_keys($fields)); $vals=implode(',',array_fill(0,count($fields),'?'));
                    $pdo->prepare("INSERT INTO vehicle_insurance ($cols) VALUES ($vals)")->execute(array_values($fields));
                    setFlash('success', t('record_saved'));
                } else {
                    $set=implode('=?,',array_keys($fields)).'=?';
                    $pdo->prepare("UPDATE vehicle_insurance SET $set WHERE id=?")->execute([...array_values($fields),$id]);
                    setFlash('success', t('record_updated'));
                }
            } catch (PDOException $e) { setFlash('danger', t('error_occurred').' '.$e->getMessage()); }
        }
        header("Location: ?module=insurance"); exit;
    }
    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM vehicle_insurance WHERE id=?")->execute([$id]);
        setFlash('success', t('record_deleted'));
        header("Location: ?module=insurance"); exit;
    }
}

$search   = trim($_GET['q'] ?? '');
$fStatus  = $_GET['status'] ?? '';
$fType    = $_GET['itype'] ?? '';
$fExpiry  = $_GET['expiry'] ?? '';
$fVehType = $_GET['vtype'] ?? '';

$sql = "SELECT vi.*, v.plate_number, v.make, v.model, v.type AS vtype,
               DATEDIFF(vi.expiry_date, CURDATE()) AS days_left
        FROM vehicle_insurance vi
        JOIN vehicles v ON v.id=vi.vehicle_id
        WHERE 1=1";
$params=[];
if ($fStatus)  { $sql.=" AND vi.status=?";             $params[]=$fStatus; }
if ($fType)    { $sql.=" AND vi.insurance_type=?";     $params[]=$fType; }
if ($fVehType) { $sql.=" AND v.type=?";                $params[]=$fVehType; }
if ($fExpiry === 'expired')  { $sql.=" AND vi.expiry_date < CURDATE()"; }
if ($fExpiry === 'expiring') { $sql.=" AND vi.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)"; }
if ($search) {
    $sql.=" AND (v.plate_number LIKE ? OR vi.insurance_company LIKE ? OR vi.policy_number LIKE ?)";
    for($i=0;$i<3;$i++) $params[]="%$search%";
}
$sql.=" ORDER BY vi.expiry_date ASC";
$stmt=$pdo->prepare($sql); $stmt->execute($params);
$rows=$stmt->fetchAll();
?>

<div class="card mb-3"><div class="card-body py-2">
  <form method="get" class="row g-2 align-items-end">
    <input type="hidden" name="module" value="insurance">
    <div class="col-md-3"><input name="q" class="form-control form-control-sm" placeholder="<?= t('search') ?>" value="<?= e($search) ?>"></div>
    <div class="col-auto">
      <select name="status" class="form-select form-select-sm">
        <option value=""><?= t('all') ?> <?= t('status') ?></option>
        <?php foreach (['active','expired','cancelled','renewed'] as $s): ?><option value="<?= $s ?>" <?= $fStatus===$s?'selected':''?>><?= t($s) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <select name="itype" class="form-select form-select-sm">
        <option value=""><?= t('all') ?> <?= t('type') ?></option>
        <?php foreach (['comprehensive','third_party','fire_theft'] as $s): ?><option value="<?= $s ?>" <?= $fType===$s?'selected':''?>><?= t($s) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <select name="expiry" class="form-select form-select-sm">
        <option value=""><?= t('all') ?></option>
        <option value="expired" <?= $fExpiry==='expired'?'selected':''?>>⚠️ <?= t('expired') ?></option>
        <option value="expiring" <?= $fExpiry==='expiring'?'selected':''?>>🔔 <?= t('expiring_in') ?> 30d</option>
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
    <div class="col-auto"><a href="?module=insurance" class="btn btn-sm btn-outline-secondary"><?= t('reset') ?></a></div>
    <div class="col-auto ms-auto"><button type="button" class="btn btn-sm btn-success" onclick="openAddModal()"><i class="fas fa-plus me-1"></i><?= t('add_insurance') ?></button></div>
  </form>
</div></div>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover align-middle mb-0">
  <thead class="table-dark"><tr>
    <th>#</th><th><?= t('plate_number') ?></th><th><?= t('insurance_company') ?></th>
    <th><?= t('policy_number') ?></th><th><?= t('insurance_type') ?></th>
    <th><?= t('start_date') ?></th><th><?= t('expiry_date') ?></th>
    <th><?= t('amount') ?></th><th><?= t('status') ?></th><th><?= t('actions') ?></th>
  </tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="10" class="text-center text-muted py-3"><?= t('no_records') ?></td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
  <tr class="<?= $r['days_left']<0 ? 'table-danger' : ($r['days_left']<=30 ? 'table-warning' : '') ?>">
    <td><?= $r['id'] ?></td>
    <td><strong><?= e($r['plate_number']) ?></strong><br><small class="text-muted"><?= e($r['make'].' '.$r['model']) ?></small></td>
    <td><?= e($r['insurance_company']) ?></td>
    <td><?= e($r['policy_number']?:'—') ?></td>
    <td><?= t($r['insurance_type']) ?></td>
    <td><?= fmtDate($r['start_date']) ?></td>
    <td><?= expiryBadge($r['expiry_date']) ?></td>
    <td><?= $r['amount'] ? 'KWD '.number_format($r['amount'],3) : '—' ?></td>
    <td><?= statusBadge($r['status']) ?></td>
    <td>
      <button class="btn btn-xs btn-outline-primary" onclick="openEditModal(<?= htmlspecialchars(json_encode($r),ENT_QUOTES) ?>)"><i class="fas fa-edit"></i></button>
      <button class="btn btn-xs btn-outline-danger" onclick="confirmDelete(<?= $r['id'] ?>)"><i class="fas fa-trash"></i></button>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div></div><div class="card-footer text-muted small"><?= count($rows) ?> <?= t('total') ?></div></div>

<!-- Modal -->
<div class="modal fade" id="insModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" id="insForm">
        <input type="hidden" name="action" id="fAction" value="add">
        <input type="hidden" name="id" id="fId" value="">
        <div class="modal-header bg-info text-white">
          <h5 class="modal-title" id="modalTitle"><?= t('add_insurance') ?></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label"><?= t('vehicles') ?> *</label><select name="vehicle_id" id="fVeh" class="form-select" required><?= vehicleOptions($preVeh ?: null) ?></select></div>
            <div class="col-md-6"><label class="form-label"><?= t('insurance_company') ?> *</label><input name="insurance_company" id="fCompany" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label"><?= t('policy_number') ?></label><input name="policy_number" id="fPolicy" class="form-control"></div>
            <div class="col-md-4">
              <label class="form-label"><?= t('insurance_type') ?></label>
              <select name="insurance_type" id="fInsType" class="form-select">
                <?php foreach (['comprehensive','third_party','fire_theft'] as $s): ?><option value="<?= $s ?>"><?= t($s) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label"><?= t('status') ?></label>
              <select name="status" id="fStatus" class="form-select">
                <?php foreach (['active','expired','cancelled','renewed'] as $s): ?><option value="<?= $s ?>"><?= t($s) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4"><label class="form-label"><?= t('start_date') ?> *</label><input name="start_date" id="fStart" type="date" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label"><?= t('expiry_date') ?> *</label><input name="expiry_date" id="fExpiry" type="date" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label"><?= t('amount') ?></label><input name="amount" id="fAmount" type="number" step="0.001" class="form-control"></div>
            <div class="col-12"><label class="form-label"><?= t('notes') ?></label><textarea name="notes" id="fNotes" class="form-control" rows="2"></textarea></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('cancel') ?></button>
          <button type="submit" class="btn btn-info text-white"><i class="fas fa-save me-1"></i><?= t('save') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>
<form id="deleteForm" method="post"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="deleteId"></form>
<script>
function openAddModal(){document.getElementById('fAction').value='add';document.getElementById('fId').value='';document.getElementById('modalTitle').textContent='<?= t('add_insurance') ?>';document.getElementById('insForm').reset();<?php if($preVeh):?>document.getElementById('fVeh').value='<?=$preVeh?>';<?php endif;?>new bootstrap.Modal(document.getElementById('insModal')).show();}
function openEditModal(r){document.getElementById('fAction').value='edit';document.getElementById('fId').value=r.id;document.getElementById('modalTitle').textContent='<?= t('edit_insurance') ?>';['vehicle_id','insurance_company','policy_number','insurance_type','start_date','expiry_date','amount','status','notes'].forEach(k=>{const el=document.querySelector('[name='+k+']');if(el)el.value=r[k]??'';});new bootstrap.Modal(document.getElementById('insModal')).show();}
function confirmDelete(id){if(confirm('<?= t('confirm_delete') ?>')){const f=document.getElementById('deleteForm');f.action='?module=insurance&action=delete&id='+id;f.submit();}}
document.getElementById('insForm').addEventListener('submit',function(){this.action='?module=insurance&action='+document.getElementById('fAction').value+(document.getElementById('fId').value?'&id='+document.getElementById('fId').value:'');});
<?php if($preVeh):?>window.addEventListener('DOMContentLoaded',()=>openAddModal());<?php endif;?>
</script>
