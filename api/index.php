<?php
// API Endpoint for Fleet Management
require_once '../config.php';
require_once '../functions.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$pdo = getDB();

switch ($action) {
    case 'get_vehicle_km':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            echo json_encode(['error' => 'Vehicle ID required']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT v.current_km, v.service_counter, v.free_service_km_threshold, v.free_service_driver_id, v.last_service_km, v.current_driver_id, COALESCE(e.name_en,'') AS current_driver_name FROM vehicles v LEFT JOIN employees e ON e.id=v.current_driver_id WHERE v.id=?");
        $stmt->execute([$id]);
        $v = $stmt->fetch();
        if ($v) {
            echo json_encode($v);
        } else {
            echo json_encode(['error' => 'Vehicle not found']);
        }
        break;

    case 'get_driver_notifications':
        $driverId = (int)($_GET['driver_id'] ?? 0);
        if (!$driverId) {
            echo json_encode(['error' => 'Driver ID required']);
            exit;
        }
        $notifications = getDriverServiceNotifications($driverId);
        echo json_encode($notifications);
        break;

    case 'mark_notification_read':
        $notifId = (int)($_POST['id'] ?? 0);
        if (!$notifId) {
            echo json_encode(['error' => 'Notification ID required']);
            exit;
        }
        markNotificationRead($notifId);
        echo json_encode(['success' => true]);
        break;

    case 'check_upcoming_services':
        checkUpcomingServices();
        echo json_encode(['success' => true]);
        break;

    // GPS Tracking API
    case 'gps_update':
        $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
        $latitude = (float)($_POST['latitude'] ?? 0);
        $longitude = (float)($_POST['longitude'] ?? 0);
        $speed = (float)($_POST['speed'] ?? 0);
        $heading = (float)($_POST['heading'] ?? 0);
        $altitude = (float)($_POST['altitude'] ?? 0);
        $accuracy = (float)($_POST['accuracy'] ?? 0);
        $locationTime = $_POST['location_time'] ?? date('Y-m-d H:i:s');
        $odometer = (int)($_POST['odometer'] ?? 0);
        $engineStatus = (int)($_POST['engine_status'] ?? 0);
        
        if (!$vehicleId || !$latitude || !$longitude) {
            echo json_encode(['error' => 'Vehicle ID, latitude, and longitude required']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("INSERT INTO vehicle_locations 
                (vehicle_id, latitude, longitude, speed, heading, altitude, accuracy, location_time, odometer, engine_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$vehicleId, $latitude, $longitude, $speed, $heading, $altitude, $accuracy, $locationTime, $odometer, $engineStatus]);
            
            // Check geofences
            checkGeofences($vehicleId, $latitude, $longitude, $speed);
            
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'get_vehicle_location':
        $vehicleId = (int)($_GET['vehicle_id'] ?? 0);
        if (!$vehicleId) {
            echo json_encode(['error' => 'Vehicle ID required']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT * FROM vehicle_locations WHERE vehicle_id=? ORDER BY location_time DESC LIMIT 1");
        $stmt->execute([$vehicleId]);
        $location = $stmt->fetch();
        echo json_encode($location ?: ['error' => 'No location data']);
        break;

    case 'get_vehicle_route':
        $vehicleId = (int)($_GET['vehicle_id'] ?? 0);
        $tripId = (int)($_GET['trip_id'] ?? 0);
        $limit = (int)($_GET['limit'] ?? 100);
        
        if (!$vehicleId) {
            echo json_encode(['error' => 'Vehicle ID required']);
            exit;
        }
        
        $sql = "SELECT * FROM vehicle_routes WHERE vehicle_id=?";
        $params = [$vehicleId];
        if ($tripId) { $sql .= " AND trip_id=?"; $params[] = $tripId; }
        $sql .= " ORDER BY route_time DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $routes = $stmt->fetchAll();
        echo json_encode($routes);
        break;

    default:
        echo json_encode(['error' => 'Invalid action']);
}

// Helper function to check geofences
function checkGeofences($vehicleId, $lat, $lng, $speed) {
    $pdo = getDB();
    
    // Get active geofences
    $stmt = $pdo->query("SELECT * FROM geofences WHERE status='active'");
    $geofences = $stmt->fetchAll();
    
    foreach ($geofences as $gf) {
        $isInside = false;
        
        if ($gf['type'] === 'circle') {
            // Calculate distance from center
            $distance = haversineDistance($lat, $lng, $gf['center_lat'], $gf['center_lng']);
            $isInside = $distance <= $gf['radius'];
        } else {
            // Polygon check (simplified - would need proper polygon algorithm)
            $isInside = false; // Implement polygon point-in-polygon if needed
        }
        
        if ($isInside) {
            // Check if vehicle was previously outside (entry event)
            $lastEvent = $pdo->prepare("SELECT * FROM geofence_events 
                                       WHERE vehicle_id=? AND geofence_id=? 
                                       ORDER BY event_time DESC LIMIT 1");
            $lastEvent->execute([$vehicleId, $gf['id']]);
            $last = $lastEvent->fetch();
            
            if (!$last || $last['event_type'] === 'exit') {
                if ($gf['alert_on_entry']) {
                    $pdo->prepare("INSERT INTO geofence_events 
                        (vehicle_id, geofence_id, event_type, latitude, longitude, event_time, speed)
                        VALUES (?, ?, 'entry', ?, ?, NOW(), ?)")
                        ->execute([$vehicleId, $gf['id'], $lat, $lng, $speed]);
                }
            }
        } else {
            // Check if vehicle was previously inside (exit event)
            $lastEvent = $pdo->prepare("SELECT * FROM geofence_events 
                                       WHERE vehicle_id=? AND geofence_id=? 
                                       ORDER BY event_time DESC LIMIT 1");
            $lastEvent->execute([$vehicleId, $gf['id']]);
            $last = $lastEvent->fetch();
            
            if ($last && $last['event_type'] === 'entry') {
                if ($gf['alert_on_exit']) {
                    $pdo->prepare("INSERT INTO geofence_events 
                        (vehicle_id, geofence_id, event_type, latitude, longitude, event_time, speed)
                        VALUES (?, ?, 'exit', ?, ?, NOW(), ?)")
                        ->execute([$vehicleId, $gf['id'], $lat, $lng, $speed]);
                }
            }
        }
    }
}

// Haversine distance calculation
function haversineDistance($lat1, $lng1, $lat2, $lng2) {
    $earthRadius = 6371000; // meters
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2) * sin($dLng/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earthRadius * $c;
}
