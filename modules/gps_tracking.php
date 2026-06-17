<?php
// modules/gps_tracking.php - GPS Tracking Module

$pdo = getDB();
$id = (int)($_GET['id'] ?? 0);
$action = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']))
          ? $_POST['action']
          : ($_GET['action'] ?? 'list');

// ---- POST Actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;
    
    if ($action === 'add_geofence' || $action === 'edit_geofence') {
        $fields = [
            'name_en' => trim($d['name_en'] ?? ''),
            'name_ar' => trim($d['name_ar'] ?? ''),
            'type' => $d['type'] ?? 'circle',
            'center_lat' => $d['center_lat'] ?: null,
            'center_lng' => $d['center_lng'] ?: null,
            'radius' => $d['radius'] ?: null,
            'coordinates' => $d['coordinates'] ?: null,
            'alert_on_entry' => (int)($d['alert_on_entry'] ?? 1),
            'alert_on_exit' => (int)($d['alert_on_exit'] ?? 1),
            'status' => $d['status'] ?? 'active',
        ];
        
        if (!$fields['name_en']) {
            setFlash('danger', t('required_fields'));
        } else {
            try {
                if ($action === 'add_geofence') {
                    $cols = implode(',', array_keys($fields));
                    $vals = implode(',', array_fill(0, count($fields), '?'));
                    $pdo->prepare("INSERT INTO geofences ($cols) VALUES ($vals)")->execute(array_values($fields));
                    setFlash('success', t('record_saved'));
                } else {
                    $set = implode('=?,', array_keys($fields)) . '=?';
                    $pdo->prepare("UPDATE geofences SET $set WHERE id=?")->execute([...array_values($fields), $id]);
                    setFlash('success', t('record_updated'));
                }
            } catch (PDOException $e) {
                setFlash('danger', t('error_occurred') . ' ' . $e->getMessage());
            }
        }
        header("Location: ?module=gps_tracking"); exit;
    }
    
    if ($action === 'delete_geofence') {
        try {
            $pdo->prepare("DELETE FROM geofences WHERE id=?")->execute([$id]);
            setFlash('success', t('record_deleted'));
        } catch (PDOException $e) {
            setFlash('danger', t('error_occurred'));
        }
        header("Location: ?module=gps_tracking"); exit;
    }
}

// ---- Filters ----
$fVehicle = (int)($_GET['vehicle_id'] ?? 0);
$fDateFrom = $_GET['date_from'] ?? '';
$fDateTo = $_GET['date_to'] ?? '';

// ---- LIST ----
if ($action === 'list') {
    // Get geofences
    $geofences = $pdo->query("SELECT * FROM geofences ORDER BY name_en")->fetchAll();
    
    // Get recent vehicle locations
    $sql = "SELECT vl.*, v.plate_number, v.make, v.model, e.name_en AS driver_name
            FROM vehicle_locations vl
            JOIN vehicles v ON v.id = vl.vehicle_id
            LEFT JOIN employees e ON e.id = v.current_driver_id
            WHERE 1=1";
    $params = [];
    if ($fVehicle) { $sql .= " AND vl.vehicle_id=?"; $params[] = $fVehicle; }
    if ($fDateFrom) { $sql .= " AND vl.location_time >= ?"; $params[] = $fDateFrom; }
    if ($fDateTo) { $sql .= " AND vl.location_time <= ?"; $params[] = $fDateTo; }
    $sql .= " ORDER BY vl.location_time DESC LIMIT 100";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $locations = $stmt->fetchAll();
    
    // Get geofence events
    $geofenceSql = "SELECT ge.*, v.plate_number, g.name_en AS geofence_name
                    FROM geofence_events ge
                    JOIN vehicles v ON v.id = ge.vehicle_id
                    JOIN geofences g ON g.id = ge.geofence_id
                    WHERE 1=1";
    $gParams = [];
    if ($fVehicle) { $geofenceSql .= " AND ge.vehicle_id=?"; $gParams[] = $fVehicle; }
    if ($fDateFrom) { $geofenceSql .= " AND ge.event_time >= ?"; $gParams[] = $fDateFrom; }
    if ($fDateTo) { $geofenceSql .= " AND ge.event_time <= ?"; $gParams[] = $fDateTo; }
    $geofenceSql .= " ORDER BY ge.event_time DESC LIMIT 50";
    
    $gStmt = $pdo->prepare($geofenceSql);
    $gStmt->execute($gParams);
    $geofenceEvents = $gStmt->fetchAll();
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h3><i class="fas fa-map-marker-alt me-2"></i><?= t('gps_tracking') ?></h3>
  <button class="btn btn-primary" onclick="openGeofenceModal()">
    <i class="fas fa-draw-polygon me-1"></i><?= t('add_geofence') ?>
  </button>
</div>

<!-- Filters -->
<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-3">
      <input type="hidden" name="module" value="gps_tracking">
      <div class="col-md-3">
        <label class="form-label"><?= t('vehicles') ?></label>
        <select name="vehicle_id" class="form-select">
          <?= vehicleOptions($fVehicle) ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label"><?= t('date_from') ?></label>
        <input type="date" name="date_from" class="form-control" value="<?= e($fDateFrom) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label"><?= t('date_to') ?></label>
        <input type="date" name="date_to" class="form-control" value="<?= e($fDateTo) ?>">
      </div>
      <div class="col-md-3 d-flex align-items-end">
        <button type="submit" class="btn btn-primary me-2"><?= t('filter') ?></button>
        <a href="?module=gps_tracking" class="btn btn-secondary"><?= t('reset') ?></a>
      </div>
    </form>
  </div>
</div>

<!-- Live Locations -->
<div class="card mb-4">
  <div class="card-header bg-primary text-white">
    <i class="fas fa-satellite me-2"></i><?= t('live_locations') ?>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th><?= t('plate_number') ?></th>
            <th><?= t('driver') ?></th>
            <th><?= t('latitude') ?></th>
            <th><?= t('longitude') ?></th>
            <th><?= t('speed') ?></th>
            <th><?= t('heading') ?></th>
            <th><?= t('location_time') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($locations as $loc): ?>
          <tr>
            <td><strong><?= e($loc['plate_number']) ?></strong></td>
            <td><?= e($loc['driver_name'] ?: '—') ?></td>
            <td><?= $loc['latitude'] ?></td>
            <td><?= $loc['longitude'] ?></td>
            <td><?= $loc['speed'] ? number_format($loc['speed']) . ' km/h' : '—' ?></td>
            <td><?= $loc['heading'] ? number_format($loc['heading']) . '°' : '—' ?></td>
            <td><?= fmtDate($loc['location_time'], true) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$locations): ?>
          <tr><td colspan="7" class="text-center text-muted py-3"><?= t('no_records') ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Geofence Events -->
<div class="card mb-4">
  <div class="card-header bg-warning text-dark">
    <i class="fas fa-bell me-2"></i><?= t('geofence_events') ?>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th><?= t('plate_number') ?></th>
            <th><?= t('geofence') ?></th>
            <th><?= t('event_type') ?></th>
            <th><?= t('speed') ?></th>
            <th><?= t('event_time') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($geofenceEvents as $event): ?>
          <tr>
            <td><strong><?= e($event['plate_number']) ?></strong></td>
            <td><?= e($event['geofence_name']) ?></td>
            <td>
              <span class="badge bg-<?= $event['event_type'] === 'entry' ? 'success' : 'danger' ?>">
                <?= t($event['event_type']) ?>
              </span>
            </td>
            <td><?= $event['speed'] ? number_format($event['speed']) . ' km/h' : '—' ?></td>
            <td><?= fmtDate($event['event_time'], true) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$geofenceEvents): ?>
          <tr><td colspan="5" class="text-center text-muted py-3"><?= t('no_records') ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Geofences List -->
<div class="card">
  <div class="card-header bg-info text-white">
    <i class="fas fa-draw-polygon me-2"></i><?= t('geofences') ?>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th><?= t('name') ?></th>
            <th><?= t('type') ?></th>
            <th><?= t('center') ?></th>
            <th><?= t('radius') ?></th>
            <th><?= t('alerts') ?></th>
            <th><?= t('status') ?></th>
            <th><?= t('actions') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($geofences as $gf): ?>
          <tr>
            <td>
              <strong><?= e($gf['name_en']) ?></strong>
              <?php if ($gf['name_ar']): ?><br><small class="text-muted"><?= e($gf['name_ar']) ?></small><?php endif; ?>
            </td>
            <td><span class="badge bg-secondary"><?= t($gf['type']) ?></span></td>
            <td>
              <?php if ($gf['center_lat']): ?>
                <?= $gf['center_lat'] ?>, <?= $gf['center_lng'] ?>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
            <td><?= $gf['radius'] ? number_format($gf['radius']) . ' m' : '—' ?></td>
            <td>
              <?php if ($gf['alert_on_entry']): ?><span class="badge bg-success me-1"><?= t('entry') ?></span><?php endif; ?>
              <?php if ($gf['alert_on_exit']): ?><span class="badge bg-danger"><?= t('exit') ?></span><?php endif; ?>
            </td>
            <td><?= statusBadge($gf['status']) ?></td>
            <td>
              <button class="btn btn-xs btn-outline-primary" onclick="editGeofence(<?= htmlspecialchars(json_encode($gf), ENT_QUOTES) ?>)">
                <i class="fas fa-edit"></i>
              </button>
              <button class="btn btn-xs btn-outline-danger" onclick="deleteGeofence(<?= $gf['id'] ?>, '<?= e($gf['name_en']) ?>')">
                <i class="fas fa-trash"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$geofences): ?>
          <tr><td colspan="7" class="text-center text-muted py-3"><?= t('no_records') ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Geofence Modal -->
<div class="modal fade" id="geofenceModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" id="gfAction" value="add_geofence">
        <input type="hidden" name="id" id="gfId" value="">
        <div class="modal-header bg-warning text-dark">
          <h5 class="modal-title" id="gfModalTitle"><?= t('add_geofence') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label"><?= t('name_en') ?> *</label>
              <input name="name_en" id="gfNameEn" type="text" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('name_ar') ?></label>
              <input name="name_ar" id="gfNameAr" type="text" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('type') ?></label>
              <select name="type" id="gfType" class="form-select" onchange="toggleGeofenceType()">
                <option value="circle"><?= t('circle') ?></option>
                <option value="polygon"><?= t('polygon') ?></option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('status') ?></label>
              <select name="status" class="form-select">
                <option value="active"><?= t('active') ?></option>
                <option value="inactive"><?= t('inactive') ?></option>
              </select>
            </div>
            <div id="circleFields">
              <div class="col-md-4">
                <label class="form-label"><?= t('latitude') ?></label>
                <input name="center_lat" id="gfLat" type="number" step="any" class="form-control" placeholder="29.3759">
              </div>
              <div class="col-md-4">
                <label class="form-label"><?= t('longitude') ?></label>
                <input name="center_lng" id="gfLng" type="number" step="any" class="form-control" placeholder="47.9774">
              </div>
              <div class="col-md-4">
                <label class="form-label"><?= t('radius_meters') ?></label>
                <input name="radius" id="gfRadius" type="number" class="form-control" placeholder="500">
              </div>
            </div>
            <div id="polygonFields" style="display:none;">
              <div class="col-12">
                <label class="form-label"><?= t('coordinates_json') ?></label>
                <textarea name="coordinates" id="gfCoords" class="form-control" rows="3" placeholder='[[29.3759,47.9774],[29.3760,47.9775]]'></textarea>
                <small class="text-muted">JSON array of [lat,lng] pairs</small>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label"><?= t('alerts') ?></label>
              <div>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="checkbox" name="alert_on_entry" id="gfAlertEntry" value="1" checked>
                  <label class="form-check-label" for="gfAlertEntry"><?= t('alert_on_entry') ?></label>
                </div>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="checkbox" name="alert_on_exit" id="gfAlertExit" value="1" checked>
                  <label class="form-check-label" for="gfAlertExit"><?= t('alert_on_exit') ?></label>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('cancel') ?></button>
          <button type="submit" class="btn btn-primary"><?= t('save') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<form id="deleteGeofenceForm" method="POST">
  <input type="hidden" name="action" value="delete_geofence">
  <input type="hidden" name="id" id="deleteGfId">
</form>

<script>
function openGeofenceModal(){
  document.getElementById('gfAction').value='add_geofence';
  document.getElementById('gfId').value='';
  document.getElementById('gfModalTitle').textContent='<?= t('add_geofence') ?>';
  document.getElementById('gfNameEn').value='';
  document.getElementById('gfNameAr').value='';
  document.getElementById('gfType').value='circle';
  document.getElementById('gfLat').value='';
  document.getElementById('gfLng').value='';
  document.getElementById('gfRadius').value='';
  document.getElementById('gfCoords').value='';
  toggleGeofenceType();
  new bootstrap.Modal(document.getElementById('geofenceModal')).show();
}
function editGeofence(gf){
  document.getElementById('gfAction').value='edit_geofence';
  document.getElementById('gfId').value=gf.id;
  document.getElementById('gfModalTitle').textContent='<?= t('edit_geofence') ?>';
  document.getElementById('gfNameEn').value=gf.name_en;
  document.getElementById('gfNameAr').value=gf.name_ar||'';
  document.getElementById('gfType').value=gf.type;
  document.getElementById('gfLat').value=gf.center_lat||'';
  document.getElementById('gfLng').value=gf.center_lng||'';
  document.getElementById('gfRadius').value=gf.radius||'';
  document.getElementById('gfCoords').value=gf.coordinates||'';
  document.getElementById('gfAlertEntry').checked=gf.alert_on_entry==1;
  document.getElementById('gfAlertExit').checked=gf.alert_on_exit==1;
  toggleGeofenceType();
  new bootstrap.Modal(document.getElementById('geofenceModal')).show();
}
function toggleGeofenceType(){
  const type=document.getElementById('gfType').value;
  document.getElementById('circleFields').style.display=type==='circle'?'block':'none';
  document.getElementById('polygonFields').style.display=type==='polygon'?'block':'none';
}
function deleteGeofence(id,name){
  if(confirm('<?= t('confirm_delete') ?>\n'+name)){
    document.getElementById('deleteGfId').value=id;
    document.getElementById('deleteGeofenceForm').submit();
  }
}
</script>
