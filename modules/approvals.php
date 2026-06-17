<?php
// modules/approvals.php — Kuwait Authority Hygiene / Delivery Approvals

$pdo    = getDB();
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$preVeh = (int)($_GET['vehicle_id'] ?? 0);
$lang   = $_SESSION['lang'] ?? 'en';

$uploadDir  = __DIR__.'/../uploads/approvals/';
$uploadBase = 'uploads/approvals/';

// ---- Handle POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;
    if ($action === 'add' || $action === 'edit') {
        // Handle PDF upload
        $filename = $d['existing_filename'] ?? null;
        $origName = $d['existing_original'] ?? null;
        $fileSize = $d['existing_filesize'] ?? null;

        if (!empty($_FILES['approval_pdf']['tmp_name']) && $_FILES['approval_pdf']['error'] === UPLOAD_ERR_OK) {
            $orig = basename($_FILES['approval_pdf']['name']);
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf','jpg','jpeg','png'])) {
                // Delete old file if editing
                if ($action === 'edit' && $filename) {
                    @unlink($uploadDir.$filename);
                }
                $filename = 'appr_'.time().'_'.uniqid().'.'.$ext;
                $origName = $orig;
                $fileSize = $_FILES['approval_pdf']['size'];
                move_uploaded_file($_FILES['approval_pdf']['tmp_name'], $uploadDir.$filename);
            }
        }

        $fields = [
            'vehicle_id'       => $d['vehicle_id'] ?: null,
            'approval_number'  => trim($d['approval_number'] ?? ''),
            'approval_type'    => $d['approval_type'] ?? 'food_hygiene',
            'issued_by'        => trim($d['issued_by'] ?? ''),
            'issue_date'       => $d['issue_date'],
            'expiry_date'      => $d['expiry_date'],
            'filename'         => $filename,
            'original_filename'=> $origName,
            'file_size'        => $fileSize,
            'status'           => $d['status'] ?? 'active',
            'notes'            => trim($d['notes'] ?? ''),
        ];

        if (!$fields['issued_by'] || !$fields['expiry_date']) {
            setFlash('danger', t('required_fields'));
        } else {
            try {
                if ($action === 'add') {
                    $cols=implode(',',array_keys($fields)); $vals=implode(',',array_fill(0,count($fields),'?'));
                    $pdo->prepare("INSERT INTO hygiene_approvals ($cols) VALUES ($vals)")->execute(array_values($fields));
                    // Auto notification if expiry within 30 days
                    $days = daysUntil($fields['expiry_date']);
                    if ($days !== null && $days <= 30) {
                        $label = $fields['vehicle_id'] ? 'Vehicle #'.$fields['vehicle_id'] : 'Fleet-Wide';
                        $pdo->prepare("INSERT INTO notifications (type,title,message,icon,color,link) VALUES (?,?,?,?,?,?)")
                            ->execute(['approval_expiry','Approval Expiring — '.$label,
                                       t($fields['approval_type']).' expires in '.$days.' days',
                                       'fa-file-certificate','warning','?module=approvals']);
                    }
                    setFlash('success', t('record_saved'));
                } else {
                    $set=implode('=?,',array_keys($fields)).'=?';
                    $pdo->prepare("UPDATE hygiene_approvals SET $set WHERE id=?")->execute([...array_values($fields),$id]);
                    setFlash('success', t('record_updated'));
                }
            } catch (PDOException $e) { setFlash('danger', t('error_occurred').' '.$e->getMessage()); }
        }
        header("Location: ?module=approvals"); exit;
    }
    if ($action === 'delete') {
        $row = $pdo->prepare("SELECT filename FROM hygiene_approvals WHERE id=?");
        $row->execute([$id]); $row = $row->fetch();
        if ($row && $row['filename']) @unlink($uploadDir.$row['filename']);
        $pdo->prepare("DELETE FROM hygiene_approvals WHERE id=?")->execute([$id]);
        setFlash('success', t('record_deleted'));
        header("Location: ?module=approvals"); exit;
    }
}

// ---- Filters ----
$search  = trim($_GET['q']      ?? '');
$fType   = $_GET['atype']       ?? '';
$fStatus = $_GET['status']      ?? '';
$fExpiry = $_GET['expiry']      ?? '';

$sql = "SELECT ha.*, v.plate_number, v.make, v.model,
               DATEDIFF(ha.expiry_date, CURDATE()) AS days_left
        FROM hygiene_approvals ha
        LEFT JOIN vehicles v ON v.id=ha.vehicle_id
        WHERE 1=1";
$params=[];
if ($fType)   { $sql.=" AND ha.approval_type=?"; $params[]=$fType; }
if ($fStatus) { $sql.=" AND ha.status=?";         $params[]=$fStatus; }
if ($fExpiry === 'expired')  { $sql.=" AND ha.expiry_date < CURDATE()"; }
if ($fExpiry === 'expiring') { $sql.=" AND ha.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)"; }
if ($search) {
    $sql.=" AND (ha.approval_number LIKE ? OR ha.issued_by LIKE ? OR v.plate_number LIKE ?)";
    for($i=0;$i<3;$i++) $params[]="%$search%";
}
$sql.=" ORDER BY ha.expiry_date ASC";
$stmt=$pdo->prepare($sql); $stmt->execute($params);
$rows=$stmt->fetchAll();

$typeColors = ['food_hygiene'=>'success','vehicle_cleanliness'=>'info','delivery_permit'=>'primary',
               'health_certificate'=>'teal','municipality'=>'warning','other'=>'secondary'];
?>

<!-- Summary alert strip -->
<?php
$expiredCount  = $pdo->query("SELECT COUNT(*) FROM hygiene_approvals WHERE expiry_date < CURDATE() AND status='active'")->fetchColumn();
$expiringCount = $pdo->query("SELECT COUNT(*) FROM hygiene_approvals WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) AND status='active'")->fetchColumn();
if ($expiredCount || $expiringCount): ?>
<div class="row g-2 mb-3">
  <?php if ($expiredCount): ?>
  <div class="col-auto">
    <div class="alert alert-danger d-flex align-items-center gap-2 py-2 mb-0">
      <i class="fas fa-times-circle"></i>
      <strong><?= $expiredCount ?></strong> approval(s) EXPIRED
      <a href="?module=approvals&expiry=expired" class="btn btn-sm btn-danger ms-2">View</a>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($expiringCount): ?>
  <div class="col-auto">
    <div class="alert alert-warning d-flex align-items-center gap-2 py-2 mb-0">
      <i class="fas fa-exclamation-triangle"></i>
      <strong><?= $expiringCount ?></strong> approval(s) expiring in 30 days
      <a href="?module=approvals&expiry=expiring" class="btn btn-sm btn-warning ms-2">View</a>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card mb-3"><div class="card-body py-2">
  <form method="get" class="row g-2 align-items-end">
    <input type="hidden" name="module" value="approvals">
    <div class="col-md-3"><input name="q" class="form-control form-control-sm" placeholder="<?= t('search') ?>" value="<?= e($search) ?>"></div>
    <div class="col-auto">
      <select name="atype" class="form-select form-select-sm">
        <option value=""><?= t('all') ?> <?= t('type') ?></option>
        <?php foreach (['food_hygiene','vehicle_cleanliness','delivery_permit','health_certificate','municipality','other'] as $s): ?>
        <option value="<?= $s ?>" <?= $fType===$s?'selected':''?>><?= t($s) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <select name="status" class="form-select form-select-sm">
        <option value=""><?= t('all') ?> <?= t('status') ?></option>
        <?php foreach (['active','expired','pending_renewal','suspended'] as $s): ?>
        <option value="<?= $s ?>" <?= $fStatus===$s?'selected':''?>><?= t($s) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <select name="expiry" class="form-select form-select-sm">
        <option value=""><?= t('all') ?></option>
        <option value="expired" <?= $fExpiry==='expired'?'selected':''?>>⚠️ <?= t('expired') ?></option>
        <option value="expiring" <?= $fExpiry==='expiring'?'selected':''?>>🔔 <?= t('expiring_in') ?> 30d</option>
      </select>
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-primary"><?= t('filter') ?></button></div>
    <div class="col-auto"><a href="?module=approvals" class="btn btn-sm btn-outline-secondary"><?= t('reset') ?></a></div>
    <div class="col-auto ms-auto">
      <button type="button" class="btn btn-sm btn-success" onclick="document.getElementById('approvalModal').style.display='';new bootstrap.Modal(document.getElementById('approvalModal')).show()">
        <i class="fas fa-plus me-1"></i><?= t('add_approval') ?>
      </button>
    </div>
  </form>
</div></div>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover align-middle mb-0">
  <thead class="table-dark"><tr>
    <th>#</th><th><?= t('vehicles') ?></th><th><?= t('approval_type') ?></th>
    <th><?= t('approval_number') ?></th><th><?= t('issued_by') ?></th>
    <th><?= t('issue_date') ?></th><th><?= t('expiry_date') ?></th>
    <th><?= t('upload_pdf') ?></th><th><?= t('status') ?></th><th><?= t('actions') ?></th>
  </tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="10" class="text-center text-muted py-3"><?= t('no_records') ?></td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
  <tr class="<?= $r['days_left']<0?'table-danger':($r['days_left']<=30?'table-warning':'') ?>">
    <td><?= $r['id'] ?></td>
    <td>
      <?php if ($r['vehicle_id']): ?>
        <strong><?= e($r['plate_number']) ?></strong><br>
        <small class="text-muted"><?= e($r['make'].' '.$r['model']) ?></small>
      <?php else: ?>
        <span class="badge bg-dark"><i class="fas fa-layer-group me-1"></i><?= t('fleet_wide') ?></span>
      <?php endif; ?>
    </td>
    <td><span class="badge bg-<?= $typeColors[$r['approval_type']] ?? 'secondary' ?>"><?= t($r['approval_type']) ?></span></td>
    <td><strong><?= e($r['approval_number']?:'—') ?></strong></td>
    <td><?= e($r['issued_by']) ?></td>
    <td><?= fmtDate($r['issue_date']) ?></td>
    <td><?= expiryBadge($r['expiry_date']) ?></td>
    <td>
      <?php if ($r['filename']): ?>
        <?php $ext = strtolower(pathinfo($r['filename'], PATHINFO_EXTENSION)); ?>
        <?php if ($ext === 'pdf'): ?>
          <a href="../<?= $uploadBase.e($r['filename']) ?>" target="_blank" class="btn btn-xs btn-outline-danger">
            <i class="fas fa-file-pdf me-1"></i><?= t('view_pdf') ?>
          </a>
        <?php else: ?>
          <a href="../<?= $uploadBase.e($r['filename']) ?>" target="_blank" class="btn btn-xs btn-outline-info">
            <i class="fas fa-image me-1"></i>View
          </a>
        <?php endif; ?>
        <small class="d-block text-muted"><?= $r['file_size'] ? round($r['file_size']/1024).' KB' : '' ?></small>
      <?php else: ?>
        <span class="badge bg-secondary"><i class="fas fa-minus me-1"></i>No file</span>
      <?php endif; ?>
    </td>
    <td><?= statusBadge($r['status']) ?></td>
    <td>
      <button class="btn btn-xs btn-outline-primary" onclick="openEditModal(<?= htmlspecialchars(json_encode($r),ENT_QUOTES) ?>)"><i class="fas fa-edit"></i></button>
      <form method="post" action="?module=approvals&action=delete&id=<?= $r['id'] ?>" style="display:inline" onsubmit="return confirm('<?= t('confirm_delete') ?>')">
        <input type="hidden" name="action" value="delete">
        <button class="btn btn-xs btn-outline-danger"><i class="fas fa-trash"></i></button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div></div><div class="card-footer text-muted small"><?= count($rows) ?> <?= t('total') ?></div></div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="approvalModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" id="approvalForm" enctype="multipart/form-data">
        <input type="hidden" name="action" id="fAction" value="add">
        <input type="hidden" name="id" id="fId" value="">
        <input type="hidden" name="existing_filename" id="fExFile" value="">
        <input type="hidden" name="existing_original" id="fExOrig" value="">
        <input type="hidden" name="existing_filesize" id="fExSize" value="">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title" id="modalTitle"><?= t('add_approval') ?></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label"><?= t('vehicles') ?> <small class="text-muted">(or leave blank for fleet-wide)</small></label>
              <select name="vehicle_id" id="fVeh" class="form-select"><?= vehicleOptions($preVeh ?: null) ?></select>
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('approval_type') ?> *</label>
              <select name="approval_type" id="fType" class="form-select">
                <?php foreach (['food_hygiene','vehicle_cleanliness','delivery_permit','health_certificate','municipality','other'] as $s): ?>
                <option value="<?= $s ?>"><?= t($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4"><label class="form-label"><?= t('approval_number') ?></label><input name="approval_number" id="fApprNo" class="form-control"></div>
            <div class="col-md-8"><label class="form-label"><?= t('issued_by') ?> *</label><input name="issued_by" id="fIssuedBy" class="form-control" required placeholder="e.g. Kuwait Municipality, MOH..."></div>
            <div class="col-md-4"><label class="form-label"><?= t('issue_date') ?> *</label><input name="issue_date" id="fIssueDate" type="date" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label"><?= t('expiry_date') ?> *</label><input name="expiry_date" id="fExpDate" type="date" class="form-control" required></div>
            <div class="col-md-4">
              <label class="form-label"><?= t('status') ?></label>
              <select name="status" id="fStatus" class="form-select">
                <?php foreach (['active','expired','pending_renewal','suspended'] as $s): ?>
                <option value="<?= $s ?>"><?= t($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- PDF Upload area -->
            <div class="col-12">
              <label class="form-label"><?= t('upload_pdf') ?> <small class="text-muted">(PDF, JPG, PNG)</small></label>
              <div id="pdfDropZone" class="border rounded p-3 text-center" style="cursor:pointer;border-style:dashed!important;min-height:80px;display:flex;align-items:center;justify-content:center;gap:8px"
                   onclick="document.getElementById('pdfInput').click()">
                <i class="fas fa-file-upload fa-lg text-muted"></i>
                <div>
                  <div id="pdfLabel" class="text-muted small">Click to upload approval document</div>
                  <div id="pdfExisting" class="small fw-bold text-success" style="display:none"></div>
                </div>
              </div>
              <input type="file" id="pdfInput" name="approval_pdf" accept=".pdf,.jpg,.jpeg,.png" style="display:none" onchange="handlePdfSelect(this)">
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

<script>
function handlePdfSelect(input){
  const label=document.getElementById('pdfLabel');
  const ex=document.getElementById('pdfExisting');
  if(input.files[0]){
    label.textContent=input.files[0].name+' ('+Math.round(input.files[0].size/1024)+' KB)';
    label.style.color='var(--green)';
    ex.style.display='none';
    document.getElementById('pdfDropZone').style.borderColor='var(--green)';
  }
}
function openEditModal(r){
  document.getElementById('fAction').value='edit';
  document.getElementById('fId').value=r.id;
  document.getElementById('modalTitle').textContent='<?= t('edit_approval') ?>';
  ['vehicle_id','approval_type','approval_number','issued_by','issue_date','expiry_date','status','notes'].forEach(k=>{
    const el=document.querySelector('[name='+k+']');if(el)el.value=r[k]??'';
  });
  document.getElementById('fExFile').value=r.filename??'';
  document.getElementById('fExOrig').value=r.original_filename??'';
  document.getElementById('fExSize').value=r.file_size??'';
  const ex=document.getElementById('pdfExisting');
  if(r.filename){ex.textContent='Current: '+r.original_filename;ex.style.display='block';}
  else{ex.style.display='none';}
  document.getElementById('pdfLabel').textContent='Click to replace document';
  document.getElementById('pdfDropZone').style.borderColor='';
  document.getElementById('approvalForm').action='?module=approvals&action=edit&id='+r.id;
  new bootstrap.Modal(document.getElementById('approvalModal')).show();
}
document.getElementById('approvalForm').addEventListener('submit',function(){
  if(document.getElementById('fAction').value==='add'){
    this.action='?module=approvals&action=add';
  }
});
</script>
