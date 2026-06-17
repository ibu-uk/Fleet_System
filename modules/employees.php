<?php
// modules/employees.php

$pdo    = getDB();
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$lang   = $_SESSION['lang'] ?? 'en';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;
    if ($action === 'add' || $action === 'edit') {
        $fields = [
            'emp_id'           => strtoupper(trim($d['emp_id'] ?? '')),
            'name_en'          => trim($d['name_en'] ?? ''),
            'name_ar'          => trim($d['name_ar'] ?? ''),
            'phone'            => trim($d['phone'] ?? ''),
            'whatsapp'         => trim($d['whatsapp'] ?? ''),
            'email'            => trim($d['email'] ?? ''),
            'nationality'      => trim($d['nationality'] ?? ''),
            'civil_id'         => trim($d['civil_id'] ?? ''),
            'civil_id_expiry'  => $d['civil_id_expiry'] ?: null,
            'passport_number'  => trim($d['passport_number'] ?? ''),
            'passport_expiry'  => $d['passport_expiry'] ?: null,
            'license_number'   => trim($d['license_number'] ?? ''),
            'license_type'     => trim($d['license_type'] ?? ''),
            'license_expiry'   => $d['license_expiry'] ?: null,
            'duty_location_id' => $d['duty_location_id'] ?: null,
            'residency_company' => trim($d['residency_company'] ?? ''),
            'platform'         => $d['platform'] ?? '',
            'petrol_card_number' => trim($d['petrol_card_number'] ?? ''),
            'bonus_eligible'   => isset($d['bonus_eligible']) ? 1 : 0,
            'monthly_order_target' => $d['monthly_order_target'] !== '' ? (int)$d['monthly_order_target'] : null,
            'status'           => $d['status'] ?? 'active',
            'join_date'        => $d['join_date'] ?: null,
            'notes'            => trim($d['notes'] ?? ''),
        ];
        if (!$fields['emp_id'] || !$fields['name_en']) {
            setFlash('danger', t('required_fields'));
        } else {
            try {
                if ($action === 'add') {
                    $cols = implode(',', array_keys($fields));
                    $vals = implode(',', array_fill(0, count($fields), '?'));
                    $pdo->prepare("INSERT INTO employees ($cols) VALUES ($vals)")->execute(array_values($fields));
                    setFlash('success', t('record_saved'));
                } else {
                    $set = implode('=?,', array_keys($fields)).'=?';
                    $pdo->prepare("UPDATE employees SET $set WHERE id=?")->execute([...array_values($fields), $id]);
                    setFlash('success', t('record_updated'));
                }
            } catch (PDOException $e) {
                setFlash('danger', t('error_occurred').' '.$e->getMessage());
            }
        }
        header("Location: ?module=employees"); exit;
    }
    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM employees WHERE id=?")->execute([$id]);
        setFlash('success', t('record_deleted'));
        header("Location: ?module=employees"); exit;
    }
}

$search    = trim($_GET['q'] ?? '');
$fStatus   = $_GET['status'] ?? '';
$fLocation = $_GET['location_id'] ?? '';
$fPlatform = $_GET['platform'] ?? '';

if ($action === 'list') {
    $perPage = 50;
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $offset  = ($page - 1) * $perPage;

    $where  = " FROM employees e
            LEFT JOIN duty_locations dl ON dl.id=e.duty_location_id
            WHERE 1=1";
    $params = [];
    if ($fStatus)   { $where .= " AND e.status=?";            $params[] = $fStatus; }
    if ($fLocation) { $where .= " AND e.duty_location_id=?";  $params[] = $fLocation; }
    if ($fPlatform) { $where .= " AND e.platform LIKE ?";     $params[] = "%$fPlatform%"; }
    if ($search) {
        $where .= " AND (
            e.name_en           LIKE ? OR
            e.name_ar           LIKE ? OR
            e.emp_id            LIKE ? OR
            e.phone             LIKE ? OR
            e.whatsapp          LIKE ? OR
            e.nationality       LIKE ? OR
            e.civil_id          LIKE ? OR
            e.passport_number   LIKE ? OR
            e.license_number    LIKE ? OR
            e.residency_company LIKE ? OR
            e.platform          LIKE ? OR
            dl.name_en          LIKE ? OR
            dl.name_ar          LIKE ?
        )";
        for ($i = 0; $i < 13; $i++) $params[] = "%$search%";
    }

    $cStmt = $pdo->prepare("SELECT COUNT(*)".$where); $cStmt->execute($params);
    $totalRows  = (int)$cStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));

    $sql = "SELECT e.*, dl.name_en AS loc_en, dl.name_ar AS loc_ar,
                   (SELECT COUNT(*) FROM driver_assignments da WHERE da.employee_id=e.id AND da.status='active') AS active_assign,
                   (SELECT v.plate_number FROM vehicles v WHERE v.current_driver_id=e.id LIMIT 1) AS vehicle_plate"
            .$where." ORDER BY e.name_en LIMIT $perPage OFFSET $offset";
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    $employees = $stmt->fetchAll();
    $locations = $pdo->query("SELECT * FROM duty_locations WHERE status='active' ORDER BY name_en")->fetchAll();
?>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <input type="hidden" name="module" value="employees">
      <div class="col-md-3"><input name="q" class="form-control form-control-sm" placeholder="<?= t('search') ?>" value="<?= e($search) ?>"></div>
      <div class="col-auto">
        <select name="status" class="form-select form-select-sm">
          <option value=""><?= t('all') ?> <?= t('status') ?></option>
          <?php foreach (['active','inactive','suspended','on_leave'] as $s): ?>
          <option value="<?= $s ?>" <?= $fStatus===$s?'selected':'' ?>><?= t($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <select name="location_id" class="form-select form-select-sm">
          <option value=""><?= t('all') ?> <?= t('locations') ?></option>
          <?php foreach ($locations as $l): ?>
          <option value="<?= $l['id'] ?>" <?= $fLocation==$l['id']?'selected':'' ?>><?= e($lang==='ar'?($l['name_ar']??$l['name_en']):$l['name_en']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <select name="platform" class="form-select form-select-sm">
          <option value=""><?= t('all') ?> <?= t('platform') ?></option>
          <option value="talabat" <?= $fPlatform==='talabat'?'selected':'' ?>>Talabat</option>
          <option value="keeta"   <?= $fPlatform==='keeta'  ?'selected':'' ?>>Keeta</option>
        </select>
      </div>
      <div class="col-auto"><button class="btn btn-sm btn-primary"><?= t('filter') ?></button></div>
      <div class="col-auto"><a href="?module=employees" class="btn btn-sm btn-outline-secondary"><?= t('reset') ?></a></div>
      <div class="col-auto ms-auto">
        <button type="button" class="btn btn-sm btn-success" onclick="openAddModal()">
          <i class="fas fa-plus me-1"></i><?= t('add_employee') ?>
        </button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-dark">
        <tr>
          <th><?= t('emp_id') ?></th>
          <th><?= t('name') ?></th>
          <th><?= t('phone') ?></th>
          <th><?= t('nationality') ?></th>
          <th><?= t('residency_company') ?></th>
          <th><?= t('duty_location') ?></th>
          <th><?= t('platform') ?></th>
          <th><?= t('license_expiry') ?></th>
          <th><?= t('civil_id_expiry') ?></th>
          <th><?= t('assigned_driver') ?></th>
          <th><?= t('status') ?></th>
          <th><?= t('actions') ?></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$employees): ?>
        <tr><td colspan="12" class="text-center text-muted py-3"><?= t('no_records') ?></td></tr>
      <?php endif; ?>
      <?php foreach ($employees as $emp): ?>
        <tr>
          <td><strong><?= e($emp['emp_id']) ?></strong></td>
          <td>
            <?= e($emp['name_en']) ?>
            <?php if ($emp['name_ar']): ?><br><small class="text-muted" dir="rtl"><?= e($emp['name_ar']) ?></small><?php endif; ?>
          </td>
          <td><?= e($emp['phone']) ?></td>
          <td><?= e($emp['nationality']) ?></td>
          <td><?= e($emp['residency_company']?:'—') ?></td>
          <td><?= e($lang==='ar'?($emp['loc_ar']??$emp['loc_en']):($emp['loc_en']??'—')) ?></td>
          <td><?= platformBadge($emp['platform']) ?></td>
          <td><?= expiryBadge($emp['license_expiry']) ?></td>
          <td><?= expiryBadge($emp['civil_id_expiry']) ?></td>
          <td><?= $emp['vehicle_plate'] ? '<span class="badge bg-info text-dark">'.e($emp['vehicle_plate']).'</span>' : '<span class="text-muted">—</span>' ?></td>
          <td><?= statusBadge($emp['status']) ?></td>
          <td>
            <a href="?module=employees&action=view&id=<?= $emp['id'] ?>" class="btn btn-xs btn-outline-info"><i class="fas fa-eye"></i></a>
            <button class="btn btn-xs btn-outline-primary" onclick="openEditModal(<?= htmlspecialchars(json_encode($emp),ENT_QUOTES) ?>)"><i class="fas fa-edit"></i></button>
            <button class="btn btn-xs btn-outline-danger" onclick="confirmDelete(<?= $emp['id'] ?>,'<?= e($emp['name_en']) ?>')"><i class="fas fa-trash"></i></button>
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
  <li class="page-item <?= $p===$page?'active':'' ?>"><a class="page-link" href="?module=employees&page=<?= $p ?>&q=<?= e($search) ?>&status=<?= e($fStatus) ?>&location_id=<?= e($fLocation) ?>&platform=<?= e($fPlatform) ?>"><?= $p ?></a></li>
  <?php endfor; ?>
</ul></nav>
<?php endif; ?>

<!-- Modal -->
<div class="modal fade" id="empModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <form method="post" id="empForm">
        <input type="hidden" name="action" id="fAction" value="add">
        <input type="hidden" name="id" id="fId" value="">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title" id="modalTitle"><?= t('add_employee') ?></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-2"><label class="form-label"><?= t('emp_id') ?> *</label><input name="emp_id" id="fEmpId" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label"><?= t('name_en') ?> *</label><input name="name_en" id="fNameEn" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label"><?= t('name_ar') ?></label><input name="name_ar" id="fNameAr" class="form-control" dir="rtl"></div>
            <div class="col-md-2"><label class="form-label"><?= t('status') ?></label>
              <select name="status" id="fStatus" class="form-select">
                <?php foreach (['active','inactive','suspended','on_leave'] as $s): ?><option value="<?= $s ?>"><?= t($s) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3"><label class="form-label"><?= t('phone') ?></label><input name="phone" id="fPhone" class="form-control"></div>
            <div class="col-md-3"><label class="form-label"><?= t('whatsapp') ?></label><input name="whatsapp" id="fWhatsapp" class="form-control"></div>
            <div class="col-md-3"><label class="form-label"><?= t('email') ?></label><input name="email" id="fEmail" type="email" class="form-control"></div>
            <div class="col-md-3"><label class="form-label"><?= t('nationality') ?></label><input name="nationality" id="fNat" class="form-control"></div>
            <div class="col-md-3"><label class="form-label"><?= t('civil_id') ?></label><input name="civil_id" id="fCivil" class="form-control"></div>
            <div class="col-md-3"><label class="form-label"><?= t('civil_id_expiry') ?></label><input name="civil_id_expiry" id="fCivilExp" type="date" class="form-control"></div>
            <div class="col-md-3"><label class="form-label"><?= t('passport_number') ?></label><input name="passport_number" id="fPass" class="form-control"></div>
            <div class="col-md-3"><label class="form-label"><?= t('passport_expiry') ?></label><input name="passport_expiry" id="fPassExp" type="date" class="form-control"></div>
            <div class="col-md-3"><label class="form-label"><?= t('license_number') ?></label><input name="license_number" id="fLicNo" class="form-control"></div>
            <div class="col-md-2"><label class="form-label"><?= t('license_type') ?></label><input name="license_type" id="fLicType" class="form-control" placeholder="e.g. 2"></div>
            <div class="col-md-3"><label class="form-label"><?= t('license_expiry') ?></label><input name="license_expiry" id="fLicExp" type="date" class="form-control"></div>
            <div class="col-md-4">
              <label class="form-label"><?= t('duty_location') ?></label>
              <select name="duty_location_id" id="fLocId" class="form-select"><?= locationOptions() ?></select>
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
            <div class="col-md-3"><label class="form-label"><?= t('residency_company') ?></label><input name="residency_company" id="fResidency" class="form-control" placeholder="e.g. Al-Qurain Company"></div>
            <div class="col-md-3"><label class="form-label"><?= t('join_date') ?></label><input name="join_date" id="fJoin" type="date" class="form-control"></div>
            <div class="col-md-3"><label class="form-label"><?= t('petrol_card_number') ?></label><input name="petrol_card_number" id="fPetrolCard" class="form-control" placeholder="e.g. 1234-5678"></div>
            <div class="col-md-3"><label class="form-label"><?= t('monthly_order_target') ?></label><input name="monthly_order_target" id="fOrderTarget" type="number" min="0" class="form-control" placeholder="<?= t('default') ?>"></div>
            <div class="col-md-3 d-flex align-items-end">
              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" name="bonus_eligible" id="fBonusEligible" value="1">
                <label class="form-check-label" for="fBonusEligible"><?= t('bonus_eligible') ?></label>
              </div>
            </div>
            <div class="col-12"><label class="form-label"><?= t('notes') ?></label><textarea name="notes" id="fNotes" class="form-control" rows="2"></textarea></div>
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
function openAddModal(){
  document.getElementById('fAction').value='add'; document.getElementById('fId').value='';
  document.getElementById('modalTitle').textContent='<?= t('add_employee') ?>';
  document.getElementById('empForm').reset();
  new bootstrap.Modal(document.getElementById('empModal')).show();
}
function openEditModal(e){
  document.getElementById('fAction').value='edit'; document.getElementById('fId').value=e.id;
  document.getElementById('modalTitle').textContent='<?= t('edit_employee') ?>';
  const map={emp_id:'fEmpId',name_en:'fNameEn',name_ar:'fNameAr',status:'fStatus',
    phone:'fPhone',whatsapp:'fWhatsapp',email:'fEmail',nationality:'fNat',
    civil_id:'fCivil',civil_id_expiry:'fCivilExp',passport_number:'fPass',passport_expiry:'fPassExp',
    license_number:'fLicNo',license_type:'fLicType',license_expiry:'fLicExp',
    duty_location_id:'fLocId',residency_company:'fResidency',platform:'fPlatform',join_date:'fJoin',
    petrol_card_number:'fPetrolCard',monthly_order_target:'fOrderTarget',notes:'fNotes'};
  for(const[k,v]of Object.entries(map)){const el=document.getElementById(v);if(el)el.value=e[k]??'';}
  document.getElementById('fBonusEligible').checked = (e.bonus_eligible==1||e.bonus_eligible==='1');
  new bootstrap.Modal(document.getElementById('empModal')).show();
}
function confirmDelete(id,name){
  if(confirm('<?= t('confirm_delete') ?>\n'+name)){document.getElementById('deleteId').value=id;document.getElementById('deleteForm').submit();}
}
document.getElementById('empForm').addEventListener('submit',function(){
  this.action='?module=employees&action='+document.getElementById('fAction').value+
    (document.getElementById('fId').value?'&id='+document.getElementById('fId').value:'');
});
</script>

<?php } // list

if ($action === 'view' && $id) {
    $emp = $pdo->prepare("SELECT e.*, dl.name_en AS loc_en, dl.name_ar AS loc_ar FROM employees e LEFT JOIN duty_locations dl ON dl.id=e.duty_location_id WHERE e.id=?");
    $emp->execute([$id]); $emp = $emp->fetch();
    if (!$emp) { echo '<div class="alert alert-danger">Not found.</div>'; return; }

    $assign = $pdo->prepare("SELECT da.*, v.plate_number, v.make, v.model, dl.name_en AS loc_en FROM driver_assignments da JOIN vehicles v ON v.id=da.vehicle_id LEFT JOIN duty_locations dl ON dl.id=da.duty_location_id WHERE da.employee_id=? ORDER BY da.assigned_date DESC");
    $assign->execute([$id]); $assign = $assign->fetchAll();
?>
<a href="?module=employees" class="btn btn-sm btn-outline-secondary mb-3"><i class="fas fa-arrow-left me-1"></i><?= t('back') ?></a>
<div class="row g-4">
  <div class="col-md-5">
    <div class="card">
      <div class="card-header bg-success text-white fw-bold"><i class="fas fa-user me-2"></i><?= e($emp['name_en']) ?></div>
      <div class="card-body">
        <table class="table table-sm table-borderless mb-0">
          <?php $rows=[
            [t('emp_id'), $emp['emp_id']],
            [t('name_en'), $emp['name_en']],
            [t('name_ar'), $emp['name_ar']?:'—'],
            [t('phone'), $emp['phone']?:'—'],
            [t('whatsapp'), $emp['whatsapp']?:'—'],
            [t('email'), $emp['email']?:'—'],
            [t('nationality'), $emp['nationality']?:'—'],
            [t('civil_id'), $emp['civil_id']?:'—'],
            [t('civil_id_expiry'), expiryBadge($emp['civil_id_expiry'])],
            [t('passport_number'), $emp['passport_number']?:'—'],
            [t('passport_expiry'), expiryBadge($emp['passport_expiry'])],
            [t('license_number'), $emp['license_number']?:'—'],
            [t('license_type'), $emp['license_type']?:'—'],
            [t('license_expiry'), expiryBadge($emp['license_expiry'])],
            [t('duty_location'), $lang==='ar'?($emp['loc_ar']??$emp['loc_en']):($emp['loc_en']??'—')],
            [t('platform'), platformBadge($emp['platform'])],
            [t('residency_company'), $emp['residency_company']?:'—'],
            [t('join_date'), fmtDate($emp['join_date'])],
            [t('status'), statusBadge($emp['status']) ],
          ];
          foreach ($rows as $r): ?>
          <tr><td class="text-muted small" style="width:40%"><?= $r[0] ?></td><td><?= $r[1] ?></td></tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  </div>
  <div class="col-md-7">
    <div class="card">
      <div class="card-header fw-bold"><i class="fas fa-history me-2"></i><?= t('assignments') ?> <?= t('history') ?></div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th><?= t('plate_number') ?></th><th><?= t('assigned_date') ?></th><th><?= t('unassigned_date') ?></th><th><?= t('duty_location') ?></th><th><?= t('shift') ?></th><th><?= t('status') ?></th></tr></thead>
          <tbody>
          <?php foreach ($assign as $a): ?>
          <tr>
            <td><a href="?module=vehicles&action=view&id=<?= $a['vehicle_id'] ?>"><?= e($a['plate_number']) ?></a> <small class="text-muted"><?= e($a['make'].' '.$a['model']) ?></small></td>
            <td><?= fmtDate($a['assigned_date']) ?></td>
            <td><?= fmtDate($a['unassigned_date']) ?></td>
            <td><?= e($a['loc_en']??'—') ?></td>
            <td><?= t($a['shift']) ?></td>
            <td><?= statusBadge($a['status']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$assign): ?><tr><td colspan="6" class="text-center text-muted"><?= t('no_records') ?></td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php if ($emp['notes']): ?>
    <div class="card mt-3">
      <div class="card-header"><?= t('notes') ?></div>
      <div class="card-body"><?= nl2br(e($emp['notes'])) ?></div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php } ?>
