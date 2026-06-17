<?php
// ============================================================
//  Shared Helper Functions
// ============================================================

// ---- Translation helper ----
function t(string $key): string {
    global $LANG;
    return $LANG[$key] ?? $key;
}

// ---- Safe HTML output ----
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ---- Safe redirect (works even if headers already sent) ----
function safeRedirect(string $url): void {
    if (!headers_sent()) {
        header("Location: $url"); exit;
    }
    echo '<script>location.replace('.json_encode($url).');</script>';
    exit;
}

// ---- Flash messages ----
function setFlash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

// ---- Date formatting ----
function fmtDate(?string $d): string {
    if (!$d || $d === '0000-00-00') return '—';
    return date('d M Y', strtotime($d));
}

// ---- Days until a date (negative = expired) ----
function daysUntil(?string $d): ?int {
    if (!$d || $d === '0000-00-00') return null;
    $diff = (strtotime($d) - time()) / 86400;
    return (int)$diff;
}

// ---- Time ago (e.g., "2 minutes ago") ----
function timeAgo(?string $datetime): string {
    if (!$datetime) return '—';
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;

    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    return fmtDate($datetime);
}

// ---- Expiry badge ----
function expiryBadge(?string $date, int $warnDays = 30): string {
    if (!$date || $date === '0000-00-00') return '<span class="badge bg-secondary">—</span>';
    $days = daysUntil($date);
    if ($days === null) return '<span class="badge bg-secondary">—</span>';
    if ($days < 0)          return '<span class="badge bg-danger">'.t('expired').' ('.abs($days).'d)</span>';
    if ($days <= $warnDays) return '<span class="badge bg-warning text-dark">'.t('expiring_in').' '.$days.'d</span>';
    return '<span class="badge bg-success">'.fmtDate($date).'</span>';
}

// ---- Status badge ----
function statusBadge(string $status): string {
    $map = [
        'active'           => 'success',
        'inactive'         => 'secondary',
        'suspended'        => 'danger',
        'on_leave'         => 'warning',
        'in_service'       => 'info',
        'accident'         => 'danger',
        'sold'             => 'dark',
        'expired'          => 'danger',
        'cancelled'        => 'secondary',
        'renewed'          => 'success',
        'reported'         => 'warning',
        'under_assessment' => 'info',
        'under_repair'     => 'primary',
        'repaired'         => 'success',
        'written_off'      => 'dark',
        'closed'           => 'secondary',
        'ended'            => 'secondary',
    ];
    $cls = $map[$status] ?? 'secondary';
    $label = t($status) ?: ucwords(str_replace('_',' ',$status));
    return '<span class="badge bg-'.$cls.'">'.e($label).'</span>';
}

// ---- Platform badge ----
function platformBadge(?string $p): string {
    if (!$p) return '—';
    $colors = ['talabat'=>'#FF6B2B','keeta'=>'#00B140','both'=>'#6366f1'];
    $parts = explode(',', $p);
    $out = '';
    foreach ($parts as $pl) {
        $pl = trim($pl);
        $c = $colors[$pl] ?? '#888';
        $out .= '<span class="badge" style="background:'.$c.'">'.ucfirst($pl).'</span> ';
    }
    return $out;
}

// ---- Service KM alert ----
function serviceAlert(array $vehicle): string {
    $cur  = (int)($vehicle['current_km']  ?? 0);
    $next = (int)($vehicle['next_service_km'] ?? 0);
    if (!$next) return '<span class="badge bg-secondary">—</span>';
    $diff = $next - $cur;
    if ($diff <= 0)    return '<span class="badge bg-danger">'.t('overdue').' '.abs($diff).' km</span>';
    if ($diff <= 1000) return '<span class="badge bg-warning text-dark">'.t('due_in').' '.$diff.' km</span>';
    return '<span class="badge bg-success">'.$diff.' km '.t('remaining').'</span>';
}

// ---- Fetch select options ----
function vehicleOptions(?int $selected = null, string $type = ''): string {
    $pdo = getDB();
    $sql = "SELECT v.id, v.plate_number, v.make, v.model, v.type,
                   COALESCE(e.name_en,'—') AS driver,
                   COALESCE(v.current_driver_id, 0) AS driver_id
            FROM vehicles v
            LEFT JOIN employees e ON e.id = v.current_driver_id
            WHERE v.status != 'sold'";
    $params = [];
    if ($type) { $sql .= " AND v.type = ?"; $params[] = $type; }
    $sql .= " ORDER BY v.plate_number";
    $rows = $pdo->prepare($sql);
    $rows->execute($params);
    $out = '<option value="">'.t('select_vehicle').'</option>';
    foreach ($rows as $r) {
        $sel = ($r['id'] == $selected) ? 'selected' : '';
        $label = "[".strtoupper($r['type'][0])."] ".$r['plate_number']." — ".$r['make']." ".$r['model']." (".$r['driver'].")";
        $out .= "<option value=\"{$r['id']}\" data-driver-id=\"{$r['driver_id']}\" data-driver-name=\"".htmlspecialchars($r['driver'],ENT_QUOTES)."\" $sel>".e($label)."</option>";
    }
    return $out;
}

function employeeOptions(?int $selected = null): string {
    $pdo = getDB();
    $rows = $pdo->query("SELECT id, emp_id, name_en, residency_company, petrol_card_number FROM employees WHERE status='active' ORDER BY name_en");
    $out = '<option value="">'.t('select_employee').'</option>';
    foreach ($rows as $r) {
        $sel = ($r['id'] == $selected) ? 'selected' : '';
        $label = e("[{$r['emp_id']}] {$r['name_en']}");
        if ($r['residency_company']) $label .= ' — '.e($r['residency_company']);
        $card = e($r['petrol_card_number'] ?? '');
        $out .= "<option value=\"{$r['id']}\" data-card=\"$card\" $sel>$label</option>";
    }
    return $out;
}

function locationOptions(?int $selected = null): string {
    global $LANG;
    $pdo  = getDB();
    $lang = $_SESSION['lang'] ?? 'en';
    $col  = $lang === 'ar' ? 'name_ar' : 'name_en';
    $rows = $pdo->query("SELECT id, name_en, name_ar FROM duty_locations WHERE status='active' ORDER BY name_en");
    $out  = '<option value="">'.t('select_location').'</option>';
    foreach ($rows as $r) {
        $sel = ($r['id'] == $selected) ? 'selected' : '';
        $name = $r[$col] ?: $r['name_en'];
        $out .= "<option value=\"{$r['id']}\" $sel>".e($name)."</option>";
    }
    return $out;
}

// ---- Redirect with flash ----
function redirectTo(string $url, string $type = 'success', string $msg = ''): void {
    if ($msg) setFlash($type, $msg);
    header("Location: $url");
    exit;
}

// ---- Build query filter from GET ----
function applySearch(string $sql, array &$params, array $fields, string $search): string {
    if (!$search) return $sql;
    $parts = [];
    foreach ($fields as $f) {
        $parts[] = "$f LIKE ?";
        $params[] = "%$search%";
    }
    return $sql . (stripos($sql,'WHERE')!==false ? ' AND ' : ' WHERE ') . '('.implode(' OR ', $parts).')';
}

// ---- Permission helpers ----
function canEdit(): bool {
    return in_array($_SESSION['user_role'] ?? '', ['admin','manager']);
}
function canDelete(): bool {
    return ($_SESSION['user_role'] ?? '') === 'admin';
}
function isAdmin(): bool {
    return ($_SESSION['user_role'] ?? '') === 'admin';
}

// ---- App Settings (key/value store) ----

// Get a single setting value (cached per-request), with a fallback default
function getSetting(string $key, $default = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $pdo = getDB();
        foreach ($pdo->query("SELECT setting_key, setting_value FROM app_settings") as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

// Persist a setting value (insert or update)
function setSetting(string $key, $value): void {
    $pdo = getDB();
    $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value, updated_at)
                   VALUES (?, ?, NOW())
                   ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=NOW()")
        ->execute([$key, (string)$value]);
}

// ---- Bonus Logic ----

// Whether the global bonus system is switched on
function bonusEnabled(): bool {
    return (int)getSetting('bonus_enabled', '0') === 1;
}

// The effective monthly order target for a driver
// (per-driver override falls back to the global default)
function driverMonthlyTarget(?int $override): int {
    $override = (int)$override;
    if ($override > 0) return $override;
    return (int)getSetting('bonus_monthly_target', '450');
}

// Calculate the bonus a driver earns for a month given their total orders.
// Flat amount: full bonus when total >= target, otherwise 0.
// Respects the global on/off switch and the per-driver eligibility flag.
function calculateBonus(int $totalOrders, int $target, bool $driverEligible): float {
    if (!bonusEnabled() || !$driverEligible) return 0.0;
    if ($target <= 0) return 0.0;
    if ($totalOrders < $target) return 0.0;
    return (float)getSetting('bonus_amount', '0');
}

// ---- Service Tracking Functions ----

// Calculate service counter based on KM (every 1000km = 1 service)
function calculateServiceCounter(int $currentKm, int $lastServiceKm): int {
    if ($currentKm <= $lastServiceKm) return 0;
    return (int)(($currentKm - $lastServiceKm) / 1000);
}

// Check if service is free based on vehicle-specific rules
function isServiceFreeForVehicle(int $vehicleId, int $currentKm, int $serviceKm): bool {
    $pdo = getDB();
    
    // Get vehicle free service rules
    $stmt = $pdo->prepare("SELECT free_service_km_threshold, free_service_driver_id, last_service_km, free_service_counter FROM vehicles WHERE id=?");
    $stmt->execute([$vehicleId]);
    $v = $stmt->fetch();
    
    if (!$v || !$v['free_service_km_threshold']) return false;
    
    // Calculate KM since last service
    $kmSinceService = $serviceKm - ($v['last_service_km'] ?? 0);
    
    // Check if we've reached the threshold
    if ($kmSinceService >= $v['free_service_km_threshold']) {
        return true;
    }
    
    return false;
}

// Check if next service will be free for this vehicle
function isNextServiceFree(int $vehicleId, int $currentKm): bool {
    $pdo = getDB();
    
    $stmt = $pdo->prepare("SELECT free_service_km_threshold, last_service_km FROM vehicles WHERE id=?");
    $stmt->execute([$vehicleId]);
    $v = $stmt->fetch();
    
    if (!$v || !$v['free_service_km_threshold']) return false;
    
    $kmSinceLast = $currentKm - ($v['last_service_km'] ?? 0);
    $kmUntilFree = $v['free_service_km_threshold'] - $kmSinceLast;
    
    // If within 100km of threshold, consider it eligible
    return ($kmUntilFree <= 100 && $kmUntilFree > 0);
}

// Get KM until next free service
function getKmUntilFreeService(int $vehicleId, int $currentKm): ?int {
    $pdo = getDB();
    
    $stmt = $pdo->prepare("SELECT free_service_km_threshold, last_service_km FROM vehicles WHERE id=?");
    $stmt->execute([$vehicleId]);
    $v = $stmt->fetch();
    
    if (!$v || !$v['free_service_km_threshold']) return null;
    
    $kmSinceLast = $currentKm - ($v['last_service_km'] ?? 0);
    $kmUntilFree = $v['free_service_km_threshold'] - $kmSinceLast;
    
    return $kmUntilFree > 0 ? $kmUntilFree : 0;
}

// Update vehicle service tracking after service completion
function updateVehicleServiceTracking(int $vehicleId, int $serviceKm, int $serviceNumber, bool $wasFree): void {
    $pdo = getDB();
    
    // Get current vehicle data
    $stmt = $pdo->prepare("SELECT current_km, last_service_km, service_counter, free_service_counter FROM vehicles WHERE id=?");
    $stmt->execute([$vehicleId]);
    $v = $stmt->fetch();
    
    if (!$v) return;
    
    // Calculate new service counter
    $newCounter = calculateServiceCounter($serviceKm, $v['last_service_km'] ?? 0);
    
    // Update free service counter if this was free
    $newFreeCounter = $v['free_service_counter'] ?? 0;
    if ($wasFree) {
        $newFreeCounter++;
    }
    
    // Update vehicle
    $pdo->prepare("UPDATE vehicles SET 
        service_counter = ?,
        last_service_km = ?,
        km_since_last_service = 0,
        free_service_counter = ?
        WHERE id = ?")->execute([$newCounter, $serviceKm, $newFreeCounter, $vehicleId]);
}

// Create service notification for driver
function createServiceNotification(int $vehicleId, int $driverId, string $type, string $message, ?int $serviceId = null, ?int $dueKm = null): void {
    $pdo = getDB();
    
    // Get current vehicle KM
    $stmt = $pdo->prepare("SELECT current_km FROM vehicles WHERE id=?");
    $stmt->execute([$vehicleId]);
    $v = $stmt->fetch();
    $currentKm = $v['current_km'] ?? 0;
    
    $pdo->prepare("INSERT INTO service_notifications 
        (vehicle_id, driver_id, service_id, notification_type, message, km_at_notification, due_km)
        VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([
        $vehicleId, $driverId, $serviceId, $type, $message, $currentKm, $dueKm
    ]);
}

// Check and create upcoming service notifications for all active drivers
function checkUpcomingServices(): void {
    $pdo = getDB();
    
    // Get all vehicles with active drivers
    $sql = "SELECT v.id, v.plate_number, v.current_km, v.current_driver_id, 
                   v.service_counter, v.free_service_km_threshold, v.last_service_km,
                   MAX(vs.next_service_km) as next_service_km,
                   e.name_en, e.emp_id
            FROM vehicles v
            LEFT JOIN vehicle_services vs ON vs.vehicle_id = v.id
            LEFT JOIN employees e ON e.id = v.current_driver_id
            WHERE v.status = 'active' AND v.current_driver_id IS NOT NULL
            GROUP BY v.id";
    
    $stmt = $pdo->query($sql);
    $vehicles = $stmt->fetchAll();
    
    foreach ($vehicles as $v) {
        $nextKm = (int)($v['next_service_km'] ?? 0);
        if (!$nextKm) continue;
        
        $currentKm = (int)$v['current_km'];
        $diff = $nextKm - $currentKm;
        
        // Check if next service is free
        $kmUntilFree = getKmUntilFreeService($v['id'], $currentKm);
        $isNextFree = isNextServiceFree($v['id'], $currentKm);
        
        // Notify if service is due within 500km or overdue
        if ($diff <= 500 && $diff > 0) {
            $msg = "Service due in {$diff}km for vehicle {$v['plate_number']}";
            if ($isNextFree) {
                $msg .= " (FREE SERVICE at {$v['free_service_km_threshold']}km threshold)";
            }
            createServiceNotification($v['id'], $v['current_driver_id'], 'upcoming', $msg, null, $nextKm);
        } elseif ($diff <= 0) {
            $msg = "Service OVERDUE for vehicle {$v['plate_number']} by " . abs($diff) . "km";
            createServiceNotification($v['id'], $v['current_driver_id'], 'overdue', $msg, null, $nextKm);
        }
        
        // Notify about upcoming free service
        if ($kmUntilFree !== null && $kmUntilFree <= 500 && $kmUntilFree > 0) {
            $msg = "FREE SERVICE eligible in {$kmUntilFree}km for vehicle {$v['plate_number']} (threshold: {$v['free_service_km_threshold']}km)";
            createServiceNotification($v['id'], $v['current_driver_id'], 'free_service', $msg, null, $currentKm + $kmUntilFree);
        }
    }
}

// Get unread service notifications for a driver
function getDriverServiceNotifications(int $driverId): array {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT sn.*, v.plate_number, v.make, v.model
        FROM service_notifications sn
        JOIN vehicles v ON v.id = sn.vehicle_id
        WHERE sn.driver_id = ? AND sn.is_read = 0
        ORDER BY sn.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$driverId]);
    return $stmt->fetchAll();
}

// Mark notification as read
function markNotificationRead(int $notificationId): void {
    $pdo = getDB();
    $pdo->prepare("UPDATE service_notifications SET is_read = 1, read_at = NOW() WHERE id = ?")->execute([$notificationId]);
}
