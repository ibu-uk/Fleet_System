<?php
// modules/inspections.php

$pdo    = getDB();
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$preVeh = (int)($_GET['vehicle_id'] ?? 0);
$lang   = $_SESSION['lang'] ?? 'en';

// ---- Upload helper ----
function handleImageUploads(PDO $pdo, int $inspId): int {
    $count = 0;
    if (empty($_FILES['photos']['name'][0])) return 0;
    $uploadDir = __DIR__.'/../uploads/inspections/';
    $zones     = $_POST['photo_zones'] ?? [];

    foreach ($_FILES['photos']['tmp_name'] as $i => $tmp) {
        if (!$tmp || $_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) continue;
        $orig  = basename($_FILES['photos']['name'][$i]);
        $ext   = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) continue;
        $fname = 'insp_'.$inspId.'_'.time().'_'.$i.'.'.$ext;
        if (move_uploaded_file($tmp, $uploadDir.$fname)) {
            $pdo->prepare("INSERT INTO inspection_images (inspection_id,filename,original_name,image_zone,file_size) VALUES (?,?,?,?,?)")
                ->execute([$inspId, $fname, $orig, $zones[$i] ?? 'other', filesize($uploadDir.$fname)]);
            $count++;
        }
    }
    return $count;
}

// ---- Handle POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;
    if ($action === 'add' || $action === 'edit') {
        $fields = [
            'vehicle_id'        => (int)$d['vehicle_id'],
            'driver_id'         => $d['driver_id'] ?: null,
            'inspector_name'    => trim($d['inspector_name'] ?? ''),
            'inspection_type'   => $d['inspection_type'] ?? 'routine',
            'inspection_date'   => $d['inspection_date'],
            'inspection_time'   => $d['inspection_time'] ?: null,
            'overall_condition' => $d['overall_condition'] ?? 'good',
            'front_damage'      => trim($d['front_damage']    ?? ''),
            'rear_damage'       => trim($d['rear_damage']     ?? ''),
            'left_damage'       => trim($d['left_damage']     ?? ''),
            'right_damage'      => trim($d['right_damage']    ?? ''),
            'interior_damage'   => trim($d['interior_damage'] ?? ''),
            'engine_damage'     => trim($d['engine_damage']   ?? ''),
            'top_damage'        => trim($d['top_damage']      ?? ''),
            'check_lights'      => isset($d['check_lights'])      ? 1 : 0,
            'check_brakes'      => isset($d['check_brakes'])      ? 1 : 0,
            'check_tires'       => isset($d['check_tires'])       ? 1 : 0,
            'check_mirrors'     => isset($d['check_mirrors'])     ? 1 : 0,
            'check_ac'          => isset($d['check_ac'])          ? 1 : 0,
            'check_fuel'        => isset($d['check_fuel'])        ? 1 : 0,
            'check_cleanliness' => isset($d['check_cleanliness']) ? 1 : 0,
            'check_documents'   => isset($d['check_documents'])   ? 1 : 0,
            'current_km'        => $d['current_km'] ?: null,
            'fuel_level'        => $d['fuel_level'] ?? 'half',
            'damage_description'=> trim($d['damage_description'] ?? ''),
            'action_required'   => trim($d['action_required']    ?? ''),
            'notes'             => trim($d['notes']              ?? ''),
            'status'            => $d['status'] ?? 'pending_review',
            'driver_notified'   => isset($d['driver_notified']) ? 1 : 0,
        ];
        if ($fields['driver_notified']) {
            $fields['driver_notified_at'] = date('Y-m-d H:i:s');
        }
        if (!$fields['vehicle_id'] || !$fields['inspection_date']) {
            setFlash('danger', t('required_fields'));
        } else {
            try {
                if ($action === 'add') {
                    $cols = implode(',', array_keys($fields));
                    $vals = implode(',', array_fill(0, count($fields), '?'));
                    $pdo->prepare("INSERT INTO vehicle_inspections ($cols) VALUES ($vals)")->execute(array_values($fields));
                    $newId = (int)$pdo->lastInsertId();
                    handleImageUploads($pdo, $newId);
                    // Auto-notify: create notification if issues found
                    if ($fields['overall_condition'] === 'poor' || $fields['overall_condition'] === 'critical') {
                        $vInfo = $pdo->prepare("SELECT plate_number, make, model FROM vehicles WHERE id=?");
                        $vInfo->execute([$fields['vehicle_id']]);
                        $vInfo = $vInfo->fetch();
                        $pdo->prepare("INSERT INTO notifications (type,title,message,icon,color,link) VALUES (?,?,?,?,?,?)")
                            ->execute(['inspection_issue',
                                       'Inspection Issue — '.$vInfo['plate_number'],
                                       ucfirst($fields['overall_condition']).' condition found during '.t($fields['inspection_type']).' inspection',
                                       'fa-exclamation-triangle','danger',
                                       '?module=inspections&action=view&id='.$newId]);
                    }
                    setFlash('success', t('record_saved'));
                    header("Location: ?module=inspections&action=view&id=$newId"); exit;
                } else {
                    $set = implode('=?,', array_keys($fields)).'=?';
                    $pdo->prepare("UPDATE vehicle_inspections SET $set WHERE id=?")->execute([...array_values($fields), $id]);
                    if (!empty($_FILES['photos']['name'][0])) handleImageUploads($pdo, $id);
                    setFlash('success', t('record_updated'));
                }
            } catch (PDOException $e) { setFlash('danger', t('error_occurred').' '.$e->getMessage()); }
        }
        if ($action !== 'add') { header("Location: ?module=inspections"); exit; }
    }
    if ($action === 'delete') {
        // Delete images from disk
        $imgs = $pdo->prepare("SELECT filename FROM inspection_images WHERE inspection_id=?");
        $imgs->execute([$id]);
        foreach ($imgs->fetchAll() as $img) @unlink(__DIR__.'/../uploads/inspections/'.$img['filename']);
        $pdo->prepare("DELETE FROM vehicle_inspections WHERE id=?")->execute([$id]);
        setFlash('success', t('record_deleted'));
        header("Location: ?module=inspections"); exit;
    }
    if ($action === 'delete_image') {
        $imgId = (int)($_GET['img_id'] ?? 0);
        $img = $pdo->prepare("SELECT filename, inspection_id FROM inspection_images WHERE id=?");
        $img->execute([$imgId]); $img = $img->fetch();
        if ($img) {
            @unlink(__DIR__.'/../uploads/inspections/'.$img['filename']);
            $pdo->prepare("DELETE FROM inspection_images WHERE id=?")->execute([$imgId]);
        }
        header("Location: ?module=inspections&action=view&id=".($img['inspection_id']??0)); exit;
    }
    if ($action === 'notify') {
        $pdo->prepare("UPDATE vehicle_inspections SET driver_notified=1, driver_notified_at=NOW() WHERE id=?")->execute([$id]);
        setFlash('success', t('driver_notified').'.');
        header("Location: ?module=inspections&action=view&id=$id"); exit;
    }
    if ($action === 'set_status') {
        $newStatus = $_POST['status'] ?? 'reviewed';
        $reviewer  = trim($_POST['reviewed_by'] ?? '');
        $pdo->prepare("UPDATE vehicle_inspections SET status=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?")
            ->execute([$newStatus, $reviewer, $id]);
        setFlash('success', t('record_updated'));
        header("Location: ?module=inspections&action=view&id=$id"); exit;
    }
}

// ---- Filters ----
$search   = trim($_GET['q'] ?? '');
$fType    = $_GET['itype']  ?? '';
$fCond    = $_GET['cond']   ?? '';
$fStatus  = $_GET['status'] ?? '';
$fVehType = $_GET['vtype']  ?? '';

$condColors = ['good'=>'success','fair'=>'warning','poor'=>'danger','critical'=>'dark'];

$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;
$totalPages = 1;

// ---- LIST ----
if ($action === 'list') {
    $baseWhere = " FROM vehicle_inspections vi
            JOIN vehicles v ON v.id=vi.vehicle_id
            LEFT JOIN employees e ON e.id=vi.driver_id
            WHERE 1=1";
    $params=[];
    if ($fType)    { $baseWhere.=" AND vi.inspection_type=?"; $params[]=$fType; }
    if ($fCond)    { $baseWhere.=" AND vi.overall_condition=?"; $params[]=$fCond; }
    if ($fStatus)  { $baseWhere.=" AND vi.status=?"; $params[]=$fStatus; }
    if ($fVehType) { $baseWhere.=" AND v.type=?"; $params[]=$fVehType; }
    if ($search) {
        $baseWhere.=" AND (v.plate_number LIKE ? OR vi.inspector_name LIKE ? OR e.name_en LIKE ? OR vi.damage_description LIKE ?)";
        for($i=0;$i<4;$i++) $params[]="%$search%";
    }
    $cStmt=$pdo->prepare("SELECT COUNT(*) ".$baseWhere); $cStmt->execute($params);
    $totalRows=(int)$cStmt->fetchColumn();
    $totalPages=max(1,(int)ceil($totalRows/$perPage));

    $sql="SELECT vi.*, v.plate_number, v.make, v.model, v.type AS vtype,
                   COALESCE(e.name_en,'—') AS driver_name,
                   (SELECT COUNT(*) FROM inspection_images ii WHERE ii.inspection_id=vi.id) AS photo_count".$baseWhere;
    $sql.=" ORDER BY vi.inspection_date DESC, vi.id DESC LIMIT $perPage OFFSET $offset";
    $stmt=$pdo->prepare($sql); $stmt->execute($params);
    $rows=$stmt->fetchAll();
?>

<div class="card mb-3"><div class="card-body py-2">
  <form method="get" class="row g-2 align-items-end">
    <input type="hidden" name="module" value="inspections">
    <div class="col-md-3"><input name="q" class="form-control form-control-sm" placeholder="<?= t('search') ?>" value="<?= e($search) ?>"></div>
    <div class="col-auto">
      <select name="itype" class="form-select form-select-sm">
        <option value=""><?= t('all') ?> <?= t('type') ?></option>
        <?php foreach (['pre_trip','post_trip','routine','incident','handover'] as $s): ?>
        <option value="<?= $s ?>" <?= $fType===$s?'selected':''?>><?= t($s) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <select name="cond" class="form-select form-select-sm">
        <option value=""><?= t('all') ?> <?= t('overall_condition') ?></option>
        <?php foreach (['good','fair','poor','critical'] as $s): ?>
        <option value="<?= $s ?>" <?= $fCond===$s?'selected':''?>><?= t($s) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <select name="status" class="form-select form-select-sm">
        <option value=""><?= t('all') ?> <?= t('status') ?></option>
        <?php foreach (['pending_review','reviewed','action_required','closed'] as $s): ?>
        <option value="<?= $s ?>" <?= $fStatus===$s?'selected':''?>><?= t($s) ?></option><?php endforeach; ?>
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
    <div class="col-auto"><a href="?module=inspections" class="btn btn-sm btn-outline-secondary"><?= t('reset') ?></a></div>
    <div class="col-auto ms-auto">
      <a href="?module=inspections&action=add<?= $preVeh?"&vehicle_id=$preVeh":'' ?>" class="btn btn-sm btn-success">
        <i class="fas fa-plus me-1"></i><?= t('add_inspection') ?>
      </a>
    </div>
  </form>
</div></div>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover align-middle mb-0">
  <thead class="table-dark"><tr>
    <th>#</th><th><?= t('vehicles') ?></th><th><?= t('inspection_type') ?></th>
    <th><?= t('inspection_date') ?></th><th><?= t('inspector_name') ?></th>
    <th><?= t('assigned_driver') ?></th><th><?= t('overall_condition') ?></th>
    <th><?= t('photos') ?></th><th><?= t('driver_notified') ?></th>
    <th><?= t('status') ?></th><th><?= t('actions') ?></th>
  </tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="11" class="text-center text-muted py-3"><?= t('no_records') ?></td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= $r['id'] ?></td>
    <td>
      <strong><?= e($r['plate_number']) ?></strong>
      <br><small class="text-muted"><?= e($r['make'].' '.$r['model']) ?></small>
    </td>
    <td><span class="badge bg-primary"><?= t($r['inspection_type']) ?></span></td>
    <td><?= fmtDate($r['inspection_date']) ?><?= $r['inspection_time']?'<br><small class="text-muted">'.e(substr($r['inspection_time'],0,5)).'</small>':'' ?></td>
    <td><?= e($r['inspector_name']?:'—') ?></td>
    <td><?= e($r['driver_name']) ?></td>
    <td>
      <span class="badge bg-<?= $condColors[$r['overall_condition']] ?>">
        <?= t($r['overall_condition']) ?>
      </span>
    </td>
    <td>
      <?php if ($r['photo_count']>0): ?>
        <span class="badge bg-info text-dark"><i class="fas fa-images me-1"></i><?= $r['photo_count'] ?></span>
      <?php else: ?><span class="text-muted">—</span><?php endif; ?>
    </td>
    <td>
      <?php if ($r['driver_notified']): ?>
        <span class="badge bg-success"><i class="fas fa-check me-1"></i><?= t('yes') ?></span>
        <?php if ($r['driver_notified_at']): ?><br><small class="text-muted"><?= fmtDate(substr($r['driver_notified_at'],0,10)) ?></small><?php endif; ?>
      <?php else: ?>
        <span class="badge bg-secondary"><?= t('no') ?></span>
      <?php endif; ?>
    </td>
    <td><?= statusBadge($r['status']) ?></td>
    <td>
      <a href="?module=inspections&action=view&id=<?= $r['id'] ?>" class="btn btn-xs btn-outline-info"><i class="fas fa-eye"></i></a>
      <a href="?module=inspections&action=edit&id=<?= $r['id'] ?>" class="btn btn-xs btn-outline-primary"><i class="fas fa-edit"></i></a>
      <form method="post" action="?module=inspections&action=delete&id=<?= $r['id'] ?>" style="display:inline" onsubmit="return confirm('<?= t('confirm_delete') ?>')">
        <input type="hidden" name="action" value="delete">
        <button class="btn btn-xs btn-outline-danger"><i class="fas fa-trash"></i></button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div></div><div class="card-footer text-muted small"><?= $totalRows ?> <?= t('total') ?></div></div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center">
  <?php for ($p=1;$p<=$totalPages;$p++): ?>
  <li class="page-item <?= $p===$page?'active':'' ?>"><a class="page-link" href="?module=inspections&page=<?= $p ?>&q=<?= e($search) ?>&itype=<?= e($fType) ?>&cond=<?= e($fCond) ?>&status=<?= e($fStatus) ?>&vtype=<?= e($fVehType) ?>"><?= $p ?></a></li>
  <?php endfor; ?>
</ul></nav>
<?php endif; ?>

<?php } // list

// ---- VIEW single inspection ----
if ($action === 'view' && $id) {
    $insp = $pdo->prepare("SELECT vi.*, v.plate_number, v.make, v.model, v.type AS vtype,
                                   COALESCE(e.name_en,'—') AS driver_name, e.phone AS driver_phone
                            FROM vehicle_inspections vi
                            JOIN vehicles v ON v.id=vi.vehicle_id
                            LEFT JOIN employees e ON e.id=vi.driver_id
                            WHERE vi.id=?");
    $insp->execute([$id]); $insp = $insp->fetch();
    if (!$insp) { echo '<div class="alert alert-danger">Not found.</div>'; return; }
    $images = $pdo->prepare("SELECT * FROM inspection_images WHERE inspection_id=? ORDER BY uploaded_at");
    $images->execute([$id]); $images = $images->fetchAll();
    $condColor = $condColors[$insp['overall_condition']] ?? 'secondary';
?>
<div class="d-flex gap-2 mb-3 flex-wrap">
  <a href="?module=inspections" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i><?= t('back') ?></a>
  <a href="?module=inspections&action=edit&id=<?= $id ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit me-1"></i><?= t('edit') ?></a>
  <?php if (!$insp['driver_notified']): ?>
  <form method="post" action="?module=inspections&action=notify&id=<?= $id ?>" style="display:inline">
    <input type="hidden" name="action" value="notify">
    <button class="btn btn-sm btn-warning text-dark"><i class="fas fa-bell me-1"></i><?= t('notify_driver') ?></button>
  </form>
  <?php endif; ?>
  <form method="post" action="?module=inspections&action=set_status&id=<?= $id ?>" class="d-flex gap-2 align-items-center">
    <input type="hidden" name="action" value="set_status">
    <select name="status" class="form-select form-select-sm" style="width:auto">
      <?php foreach (['pending_review','reviewed','action_required','closed'] as $s): ?>
      <option value="<?= $s ?>" <?= $insp['status']===$s?'selected':''?>><?= t($s) ?></option>
      <?php endforeach; ?>
    </select>
    <input name="reviewed_by" class="form-control form-control-sm" placeholder="<?= t('reviewed_by') ?>" style="width:160px" value="<?= e($insp['reviewed_by']??'') ?>">
    <button class="btn btn-sm btn-primary"><?= t('save') ?></button>
  </form>
</div>

<div class="row g-3">

  <!-- Left col: summary card -->
  <div class="col-lg-4">
    <div class="card mb-3" style="border-top: 4px solid var(--<?= $condColor==='warning'?'yellow':($condColor==='success'?'green':($condColor==='danger'?'red':'ink')) ?>)">
      <div class="card-header fw-bold">
        <i class="fas fa-clipboard-check me-2"></i><?= e($insp['plate_number']) ?> — <?= t($insp['inspection_type']) ?>
      </div>
      <div class="card-body">
        <table class="table table-sm table-borderless mb-0">
          <?php
          $chk = ['check_lights','check_brakes','check_tires','check_mirrors','check_ac','check_fuel','check_cleanliness','check_documents'];
          $rows2 = [
            [t('inspection_date'), fmtDate($insp['inspection_date']).($insp['inspection_time']?' '.substr($insp['inspection_time'],0,5):'')],
            [t('overall_condition'), '<span class="badge bg-'.$condColor.'">'.t($insp['overall_condition']).'</span>'],
            [t('vehicles'), e($insp['make'].' '.$insp['model'])],
            [t('assigned_driver'), e($insp['driver_name']).'<br><small class="text-muted">'.e($insp['driver_phone']??'').'</small>'],
            [t('inspector_name'), e($insp['inspector_name']?:'—')],
            [t('current_km'), $insp['current_km'] ? number_format($insp['current_km']).' km' : '—'],
            [t('fuel_level'), ucfirst(str_replace('_',' ',$insp['fuel_level']))],
            [t('status'), statusBadge($insp['status'])],
            [t('driver_notified'), $insp['driver_notified'] ? '<span class="badge bg-success">'.t('yes').'</span> '.fmtDate(substr($insp['driver_notified_at']??'',0,10)) : '<span class="badge bg-secondary">'.t('no').'</span>'],
          ];
          foreach ($rows2 as $r): ?>
          <tr><td class="text-muted small" style="width:40%"><?= $r[0] ?></td><td><?= $r[1] ?></td></tr>
          <?php endforeach; ?>
        </table>

        <!-- Checklist -->
        <hr class="my-2">
        <div class="fw-bold small mb-2"><?= t('checklist') ?></div>
        <div class="row g-1">
          <?php foreach ($chk as $c): ?>
          <div class="col-6">
            <span class="badge <?= $insp[$c] ? 'bg-success' : 'bg-danger' ?> w-100 text-start">
              <i class="fas <?= $insp[$c] ? 'fa-check' : 'fa-times' ?> me-1"></i><?= t($c) ?>
            </span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Right col: damage zones + photos -->
  <div class="col-lg-8">

    <!-- Vehicle diagram / damage zones -->
    <div class="card mb-3">
      <div class="card-header fw-bold"><i class="fas fa-car-side me-2 text-danger"></i><?= t('damage_zones') ?></div>
      <div class="card-body">
        <?php
        $zones = [
          'zone_front' => $insp['front_damage'],
          'zone_rear'  => $insp['rear_damage'],
          'zone_left'  => $insp['left_damage'],
          'zone_right' => $insp['right_damage'],
          'zone_interior' => $insp['interior_damage'],
          'zone_engine'   => $insp['engine_damage'],
          'zone_top'      => $insp['top_damage'],
        ];
        $hasAny = array_filter($zones);
        if (!$hasAny): ?>
          <p class="text-muted mb-0"><i class="fas fa-check-circle text-success me-2"></i>No damage recorded in any zone.</p>
        <?php else: ?>
        <div class="row g-2">
          <?php foreach ($zones as $zk => $zv): if (!$zv) continue; ?>
          <div class="col-md-6">
            <div class="border rounded p-2 bg-danger bg-opacity-10">
              <div class="fw-bold small text-danger mb-1"><i class="fas fa-exclamation-triangle me-1"></i><?= t($zk) ?></div>
              <div class="small"><?= nl2br(e($zv)) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($insp['damage_description']): ?>
        <div class="mt-3 p-2 bg-warning bg-opacity-10 border border-warning rounded">
          <strong class="small"><?= t('damage_description') ?>:</strong>
          <p class="mb-0 small mt-1"><?= nl2br(e($insp['damage_description'])) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($insp['action_required']): ?>
        <div class="mt-2 p-2 bg-danger bg-opacity-10 border border-danger rounded">
          <strong class="small text-danger"><?= t('action_required') ?>:</strong>
          <p class="mb-0 small mt-1"><?= nl2br(e($insp['action_required'])) ?></p>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Photos -->
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-bold"><i class="fas fa-images me-2 text-info"></i><?= t('photos') ?> (<?= count($images) ?>)</span>
        <!-- Quick add more photos -->
        <form method="post" action="?module=inspections&action=edit&id=<?= $id ?>" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
          <input type="hidden" name="action" value="edit">
          <input type="hidden" name="vehicle_id" value="<?= $insp['vehicle_id'] ?>">
          <input type="hidden" name="inspection_date" value="<?= $insp['inspection_date'] ?>">
          <input type="hidden" name="inspection_type" value="<?= $insp['inspection_type'] ?>">
          <input type="hidden" name="overall_condition" value="<?= $insp['overall_condition'] ?>">
          <input type="hidden" name="status" value="<?= $insp['status'] ?>">
          <label class="btn btn-sm btn-outline-info mb-0">
            <i class="fas fa-upload me-1"></i><?= t('upload_photos') ?>
            <input type="file" name="photos[]" multiple accept="image/*" style="display:none" onchange="this.form.submit()">
          </label>
        </form>
      </div>
      <div class="card-body">
        <?php if ($images): ?>
        <div class="row g-2">
          <?php foreach ($images as $img): ?>
          <div class="col-6 col-md-3">
            <div class="position-relative">
              <a href="../uploads/inspections/<?= e($img['filename']) ?>" target="_blank">
                <img src="../uploads/inspections/<?= e($img['filename']) ?>"
                     class="img-fluid rounded border"
                     style="width:100%;height:120px;object-fit:cover;"
                     alt="<?= e($img['original_name']) ?>">
              </a>
              <span class="badge bg-dark position-absolute top-0 start-0 m-1" style="font-size:9px"><?= ucfirst($img['image_zone']) ?></span>
              <form method="post" action="?module=inspections&action=delete_image&id=<?= $id ?>&img_id=<?= $img['id'] ?>" style="position:absolute;top:4px;right:4px">
                <input type="hidden" name="action" value="delete_image">
                <button class="btn btn-xs btn-danger" onclick="return confirm('Delete photo?')" style="padding:2px 5px"><i class="fas fa-times"></i></button>
              </form>
            </div>
            <small class="text-muted d-block text-center mt-1" style="font-size:10px"><?= e(strlen($img['original_name'])>20?substr($img['original_name'],0,18).'…':$img['original_name']) ?></small>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="text-muted text-center py-3"><i class="fas fa-camera fa-2x mb-2 d-block opacity-25"></i>No photos uploaded yet.</div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<?php } // view

// ---- ADD / EDIT FORM ----
if ($action === 'add' || ($action === 'edit' && $id)) {

    $rec = null;
    if ($action === 'edit' && $id) {
        $q = $pdo->prepare("SELECT * FROM vehicle_inspections WHERE id=?");
        $q->execute([$id]); $rec = $q->fetch();
        if (!$rec) { echo '<div class="alert alert-danger">Not found.</div>'; return; }
    }
    $v = $rec ?? [];
    $preVehId = $preVeh ?: (int)($v['vehicle_id'] ?? 0);
?>

<div class="d-flex gap-2 mb-3">
  <a href="?module=inspections" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i><?= t('back') ?></a>
</div>

<form method="post" action="?module=inspections&action=<?= $action ?><?= $id?"&id=$id":'' ?>" enctype="multipart/form-data">
  <input type="hidden" name="action" value="<?= $action ?>">

<div class="row g-3">

  <!-- Main info card -->
  <div class="col-lg-8">
    <div class="card mb-3">
      <div class="card-header fw-bold"><i class="fas fa-clipboard-check me-2"></i><?= t($action==='add'?'add_inspection':'edit_inspection') ?></div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label"><?= t('vehicles') ?> *</label><select name="vehicle_id" id="inspVehicle" class="form-select" required onchange="loadInspDriver(this.value)"><?= vehicleOptions($preVehId) ?></select></div>
          <div class="col-md-6"><label class="form-label"><?= t('assigned_driver') ?> <small class="text-muted" id="driverHint"></small></label><select name="driver_id" id="inspDriver" class="form-select"><?= employeeOptions((int)($v['driver_id']??0)) ?></select></div>
          <div class="col-md-3"><label class="form-label"><?= t('inspection_date') ?> *</label><input name="inspection_date" type="date" class="form-control" value="<?= e($v['inspection_date']??date('Y-m-d')) ?>" required></div>
          <div class="col-md-2"><label class="form-label"><?= t('accident_time') ?></label><input name="inspection_time" type="time" class="form-control" value="<?= e($v['inspection_time']??'') ?>"></div>
          <div class="col-md-3">
            <label class="form-label"><?= t('inspection_type') ?></label>
            <select name="inspection_type" class="form-select">
              <?php foreach (['pre_trip','post_trip','routine','incident','handover'] as $s): ?>
              <option value="<?= $s ?>" <?= ($v['inspection_type']??'routine')===$s?'selected':''?>><?= t($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label"><?= t('overall_condition') ?></label>
            <select name="overall_condition" class="form-select">
              <?php foreach (['good','fair','poor','critical'] as $s): ?>
              <option value="<?= $s ?>" <?= ($v['overall_condition']??'good')===$s?'selected':''?>><?= t($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2"><label class="form-label"><?= t('inspector_name') ?></label><input name="inspector_name" class="form-control" value="<?= e($v['inspector_name']??'') ?>"></div>
          <div class="col-md-2"><label class="form-label"><?= t('current_km') ?></label><input name="current_km" type="number" class="form-control" value="<?= e($v['current_km']??'') ?>"></div>
          <div class="col-md-2">
            <label class="form-label"><?= t('fuel_level') ?></label>
            <select name="fuel_level" class="form-select">
              <?php foreach (['empty','quarter','half','three_quarter','full'] as $s): ?>
              <option value="<?= $s ?>" <?= ($v['fuel_level']??'half')===$s?'selected':''?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label"><?= t('status') ?></label>
            <select name="status" class="form-select">
              <?php foreach (['pending_review','reviewed','action_required','closed'] as $s): ?>
              <option value="<?= $s ?>" <?= ($v['status']??'pending_review')===$s?'selected':''?>><?= t($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
    </div>

    <!-- Damage Zones -->
    <div class="card mb-3">
      <div class="card-header fw-bold text-danger"><i class="fas fa-exclamation-triangle me-2"></i><?= t('damage_zones') ?> <small class="text-muted fw-normal">(leave blank if no damage)</small></div>
      <div class="card-body">
        <div class="row g-3">
          <?php
          $zoneFields = ['front_damage'=>'zone_front','rear_damage'=>'zone_rear','left_damage'=>'zone_left',
                         'right_damage'=>'zone_right','interior_damage'=>'zone_interior',
                         'engine_damage'=>'zone_engine','top_damage'=>'zone_top'];
          foreach ($zoneFields as $field=>$label): ?>
          <div class="col-md-6">
            <label class="form-label small"><i class="fas fa-map-marker-alt me-1 text-danger"></i><?= t($label) ?></label>
            <textarea name="<?= $field ?>" class="form-control form-control-sm" rows="2" placeholder="Describe damage..."><?= e($v[$field]??'') ?></textarea>
          </div>
          <?php endforeach; ?>
          <div class="col-12"><label class="form-label"><?= t('damage_description') ?></label><textarea name="damage_description" class="form-control" rows="3" placeholder="Overall damage summary..."><?= e($v['damage_description']??'') ?></textarea></div>
          <div class="col-12"><label class="form-label text-danger"><?= t('action_required') ?></label><textarea name="action_required" class="form-control border-danger" rows="2" placeholder="What needs to be done..."><?= e($v['action_required']??'') ?></textarea></div>
        </div>
      </div>
    </div>

    <!-- Notes -->
    <div class="card mb-3">
      <div class="card-body">
        <label class="form-label"><?= t('notes') ?></label>
        <textarea name="notes" class="form-control" rows="3"><?= e($v['notes']??'') ?></textarea>
      </div>
    </div>
  </div>

  <!-- Right col: checklist + photos -->
  <div class="col-lg-4">

    <!-- Checklist -->
    <div class="card mb-3">
      <div class="card-header fw-bold"><i class="fas fa-tasks me-2"></i><?= t('checklist') ?></div>
      <div class="card-body">
        <?php
        $checks = ['check_lights','check_brakes','check_tires','check_mirrors','check_ac','check_fuel','check_cleanliness','check_documents'];
        foreach ($checks as $c): $checked = isset($v[$c]) ? (bool)$v[$c] : true; ?>
        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" name="<?= $c ?>" id="<?= $c ?>" value="1" <?= $checked?'checked':'' ?>>
          <label class="form-check-label" for="<?= $c ?>"><?= t($c) ?></label>
        </div>
        <?php endforeach; ?>
        <hr>
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" name="driver_notified" id="chk_notified" value="1" <?= ($v['driver_notified']??0)?'checked':'' ?>>
          <label class="form-check-label fw-bold" for="chk_notified"><i class="fas fa-bell me-1 text-warning"></i><?= t('driver_notified') ?></label>
        </div>
      </div>
    </div>

    <!-- Photo upload -->
    <div class="card mb-3">
      <div class="card-header fw-bold"><i class="fas fa-camera me-2 text-info"></i><?= t('upload_photos') ?></div>
      <div class="card-body">
        <div id="photoDropzone" class="border-2 border-dashed rounded text-center p-3 mb-2" style="border-color:#dee2e6;cursor:pointer;min-height:100px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:6px"
             onclick="document.getElementById('photoInput').click()">
          <i class="fas fa-cloud-upload-alt fa-2x text-muted"></i>
          <div class="small text-muted">Click to select photos<br><small>JPG, PNG, WEBP — multiple allowed</small></div>
        </div>
        <input type="file" id="photoInput" name="photos[]" multiple accept="image/*" style="display:none" onchange="previewPhotos(this)">
        <div id="photoZones" style="display:none">
          <label class="form-label small">Assign zone to each photo:</label>
          <div id="zoneSelects"></div>
        </div>
        <div id="photoPreviews" class="row g-1 mt-2"></div>
      </div>
    </div>

  </div>
</div>

<div class="d-flex gap-2 pb-4">
  <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i><?= t('save') ?></button>
  <a href="?module=inspections" class="btn btn-outline-secondary"><?= t('cancel') ?></a>
</div>

</form>

<script>
function loadInspDriver(vehId){
  const sel=document.getElementById('inspDriver');
  const hint=document.getElementById('driverHint');
  if(!vehId){
    sel.innerHTML='<option value=""><?= t('select_employee') ?></option>';
    hint.textContent=''; return;
  }
  const vehSel=document.getElementById('inspVehicle');
  const opt=vehSel.options[vehSel.selectedIndex];
  const driverId=opt ? opt.dataset.driverId : '0';
  const driverName=opt ? opt.dataset.driverName : '';
  if(driverId && driverId!=='0'){
    sel.innerHTML='<option value="'+driverId+'">'+driverName+'</option>';
    sel.value=driverId;
    hint.textContent='';
  } else {
    sel.innerHTML='<option value=""><?= t('select_employee') ?></option>';
    hint.textContent='(<?= t('no_driver_assigned') ?>)';
    hint.className='text-warning';
  }
}
<?php if($preVehId && $action==='add'): ?>window.addEventListener('DOMContentLoaded',()=>loadInspDriver(<?= $preVehId ?>));<?php endif; ?>
function previewPhotos(input){
  const prev=document.getElementById('photoPreviews');
  const zoneWrap=document.getElementById('zoneSelects');
  const zones=document.getElementById('photoZones');
  prev.innerHTML=''; zoneWrap.innerHTML='';
  if(!input.files.length){zones.style.display='none';return;}
  zones.style.display='block';
  const zoneOpts=['front','rear','left','right','interior','engine','top','overview','damage','other'];
  Array.from(input.files).forEach((f,i)=>{
    // Preview thumb
    const col=document.createElement('div'); col.className='col-4';
    const img=document.createElement('img'); img.className='img-fluid rounded'; img.style.height='70px'; img.style.objectFit='cover';
    const reader=new FileReader(); reader.onload=e=>img.src=e.target.result; reader.readAsDataURL(f);
    col.appendChild(img); prev.appendChild(col);
    // Zone select
    const sel=document.createElement('select'); sel.name='photo_zones[]'; sel.className='form-select form-select-sm mb-1';
    zoneOpts.forEach(z=>{const o=document.createElement('option');o.value=z;o.textContent=z.charAt(0).toUpperCase()+z.slice(1);sel.appendChild(o);});
    const lbl=document.createElement('div'); lbl.className='small text-muted mb-1';
    lbl.textContent=(i+1)+'. '+f.name.substring(0,25);
    zoneWrap.appendChild(lbl); zoneWrap.appendChild(sel);
  });
  // Highlight drop zone
  document.getElementById('photoDropzone').style.borderColor='var(--amber)';
}
// Drag & drop
const dz=document.getElementById('photoDropzone');
if(dz){
  dz.addEventListener('dragover',e=>{e.preventDefault();dz.style.background='rgba(245,158,11,.05)';});
  dz.addEventListener('dragleave',()=>{dz.style.background='';});
  dz.addEventListener('drop',e=>{e.preventDefault();document.getElementById('photoInput').files=e.dataTransfer.files;previewPhotos(document.getElementById('photoInput'));dz.style.background='';});
}
</script>

<?php } // add/edit
