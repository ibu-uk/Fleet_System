<?php
// modules/driver_dashboard.php - Driver view for assigned vehicle service status

$pdo = getDB();
$userId = $_SESSION['user_id'] ?? 0;
$userRole = $_SESSION['user_role'] ?? '';

// Get driver's employee record
$empStmt = $pdo->prepare("SELECT id, emp_id, name_en, name_ar FROM employees WHERE id=?");
$empStmt->execute([$userId]);
$employee = $empStmt->fetch();

if (!$employee && $userRole !== 'admin') {
    echo '<div class="alert alert-danger">Driver profile not found.</div>';
    return;
}

// If admin, allow selecting a driver
$selectedDriverId = $employee['id'] ?? 0;
if ($userRole === 'admin' && isset($_GET['driver_id'])) {
    $selectedDriverId = (int)$_GET['driver_id'];
}

// Get current vehicle assignment
$vehStmt = $pdo->prepare("
    SELECT v.*, da.assigned_date, da.shift, dl.name_en AS location_en, dl.name_ar AS location_ar
    FROM vehicles v
    JOIN driver_assignments da ON da.vehicle_id = v.id
    LEFT JOIN duty_locations dl ON dl.id = da.duty_location_id
    WHERE da.employee_id = ? AND da.status = 'active'
    ORDER BY da.assigned_date DESC
    LIMIT 1
");
$vehStmt->execute([$selectedDriverId]);
$vehicle = $vehStmt->fetch();

// Get service notifications
$notifications = getDriverServiceNotifications($selectedDriverId);

// Get service history for the vehicle
$services = [];
if ($vehicle) {
    $svcStmt = $pdo->prepare("
        SELECT vs.*, 
               (vs.next_service_km - v.current_km) as km_remaining
        FROM vehicle_services vs
        JOIN vehicles v ON v.id = vs.vehicle_id
        WHERE vs.vehicle_id = ?
        ORDER BY vs.service_date DESC
        LIMIT 5
    ");
    $svcStmt->execute([$vehicle['id']]);
    $services = $svcStmt->fetchAll();
}
?>

<div class="row g-4">
    <!-- Driver Info Card -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header bg-primary text-white">
                <i class="fas fa-user me-2"></i><?= t('driver_info') ?>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless">
                    <tr><td class="text-muted"><?= t('emp_id') ?></td><td><?= e($employee['emp_id']) ?></td></tr>
                    <tr><td class="text-muted"><?= t('name') ?></td><td><?= e($employee['name_en']) ?></td></tr>
                </table>
                
                <?php if ($userRole === 'admin'): ?>
                <hr>
                <label class="form-label small"><?= t('select_driver') ?></label>
                <select class="form-select form-select-sm" onchange="location.href='?module=driver_dashboard&driver_id='+this.value">
                    <?= employeeOptions($selectedDriverId) ?>
                </select>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Vehicle Status Card -->
    <div class="col-lg-8">
        <?php if ($vehicle): ?>
        <div class="card h-100">
            <div class="card-header bg-success text-white">
                <i class="fas fa-car me-2"></i><?= t('assigned_vehicle') ?>: <?= e($vehicle['plate_number']) ?>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <strong><?= t('make') ?> / <?= t('model') ?></strong><br>
                        <?= e($vehicle['make'].' '.$vehicle['model'].' '.$vehicle['year']) ?>
                    </div>
                    <div class="col-md-6">
                        <strong><?= t('current_km') ?></strong><br>
                        <span class="badge bg-primary fs-6"><?= number_format($vehicle['current_km']) ?> km</span>
                    </div>
                    <div class="col-md-6">
                        <strong><?= t('service_counter') ?></strong><br>
                        <span class="badge bg-info"><?= $vehicle['service_counter'] ?></span>
                        <?php if ($vehicle['free_service_km_threshold']): ?>
                            <span class="badge bg-success ms-1"><?= t('free_service') ?></span>
                            <small class="text-muted d-block">Every <?= number_format($vehicle['free_service_km_threshold']) ?>km</small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <strong><?= t('km_since_last_service') ?></strong><br>
                        <?= number_format($vehicle['km_since_last_service']) ?> km
                    </div>
                    <div class="col-md-6">
                        <strong><?= t('duty_location') ?></strong><br>
                        <?= e($vehicle['location_en'] ?? '—') ?>
                    </div>
                    <div class="col-md-6">
                        <strong><?= t('shift') ?></strong><br>
                        <?= t($vehicle['shift']) ?>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="card h-100">
            <div class="card-body text-center text-muted py-5">
                <i class="fas fa-car fa-3x mb-3"></i>
                <p><?= t('no_vehicle_assigned') ?></p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($vehicle): ?>
<!-- Service Notifications -->
<div class="card mt-4">
    <div class="card-header bg-warning text-dark">
        <i class="fas fa-bell me-2"></i><?= t('service_notifications') ?>
        <span class="badge bg-danger ms-auto"><?= count($notifications) ?></span>
    </div>
    <div class="card-body">
        <?php if ($notifications): ?>
        <div class="list-group">
            <?php foreach ($notifications as $n): ?>
            <div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                <div>
                    <strong><?= e($n['plate_number']) ?></strong>
                    <p class="mb-0 small"><?= e($n['message']) ?></p>
                    <small class="text-muted"><?= fmtDate($n['created_at']) ?></small>
                </div>
                <button class="btn btn-sm btn-outline-secondary" onclick="markRead(<?= $n['id'] ?>)">
                    <i class="fas fa-check"></i>
                </button>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="text-center text-muted py-3">
            <i class="fas fa-check-circle fa-2x mb-2"></i>
            <p><?= t('no_notifications') ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Services -->
<div class="card mt-4">
    <div class="card-header bg-info text-white">
        <i class="fas fa-wrench me-2"></i><?= t('recent_services') ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th><?= t('date') ?></th>
                        <th><?= t('service_type') ?></th>
                        <th><?= t('service_km') ?></th>
                        <th><?= t('next_service_km') ?></th>
                        <th><?= t('status') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($services as $s): ?>
                    <tr>
                        <td><?= fmtDate($s['service_date']) ?></td>
                        <td><?= t($s['service_type']) ?></td>
                        <td><?= number_format($s['service_km']) ?> km</td>
                        <td><?= number_format($s['next_service_km']) ?> km</td>
                        <td>
                            <?php if ($s['is_free_service']): ?>
                                <span class="badge bg-success">FREE</span>
                            <?php endif; ?>
                            <?php 
                            $kmRemaining = $s['km_remaining'] ?? 0;
                            if ($kmRemaining <= 0) {
                                echo '<span class="badge bg-danger">Overdue</span>';
                            } elseif ($kmRemaining <= 500) {
                                echo '<span class="badge bg-warning text-dark">Due in '.$kmRemaining.'km</span>';
                            } else {
                                echo '<span class="badge bg-success">'.$kmRemaining.'km remaining</span>';
                            }
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$services): ?>
                    <tr><td colspan="5" class="text-center text-muted py-3"><?= t('no_records') ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function markRead(id) {
    fetch('?module=api&action=mark_notification_read', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'id='+id
    }).then(() => location.reload());
}
</script>
