<?php
// modules/reminders.php — Reminders & Notifications Center

$pdo    = getDB();
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$lang   = $_SESSION['lang'] ?? 'en';

// ---- Auto-generate reminders from live data ----
function generateAutoReminders(PDO $pdo): int {
    $count = 0;
    $today = date('Y-m-d');

    // --- Insurance expiring ---
    $rows = $pdo->query("
        SELECT vi.id, vi.vehicle_id, vi.expiry_date, v.plate_number,
               DATEDIFF(vi.expiry_date, CURDATE()) AS days
        FROM vehicle_insurance vi
        JOIN vehicles v ON v.id=vi.vehicle_id
        WHERE vi.status='active' AND vi.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 45 DAY)
    ")->fetchAll();
    foreach ($rows as $r) {
        $exists = $pdo->prepare("SELECT id FROM reminders WHERE reminder_type='insurance' AND reference_id=? AND status='pending'");
        $exists->execute([$r['id']]);
        if (!$exists->fetchColumn()) {
            $pdo->prepare("INSERT INTO reminders (reminder_type,title,reference_table,reference_id,vehicle_id,remind_date,message,priority) VALUES (?,?,?,?,?,?,?,?)")
                ->execute(['insurance','Insurance Expiring — '.$r['plate_number'],'vehicle_insurance',$r['id'],$r['vehicle_id'],$r['expiry_date'],
                           'Insurance expires in '.$r['days'].' days', $r['days']<=7?'critical':($r['days']<=14?'high':'medium')]);
            $count++;
        }
    }

    // --- Employee license expiring ---
    $rows = $pdo->query("
        SELECT id, name_en, license_expiry, DATEDIFF(license_expiry, CURDATE()) AS days
        FROM employees WHERE status='active' AND license_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 45 DAY)
    ")->fetchAll();
    foreach ($rows as $r) {
        $exists = $pdo->prepare("SELECT id FROM reminders WHERE reminder_type='license' AND reference_id=? AND status='pending'");
        $exists->execute([$r['id']]);
        if (!$exists->fetchColumn()) {
            $pdo->prepare("INSERT INTO reminders (reminder_type,title,reference_table,reference_id,employee_id,remind_date,message,priority) VALUES (?,?,?,?,?,?,?,?)")
                ->execute(['license','License Expiring — '.$r['name_en'],'employees',$r['id'],$r['id'],$r['license_expiry'],
                           'Driving license expires in '.$r['days'].' days', $r['days']<=7?'critical':'high']);
            $count++;
        }
    }

    // --- Civil ID expiring ---
    $rows = $pdo->query("
        SELECT id, name_en, civil_id_expiry, DATEDIFF(civil_id_expiry, CURDATE()) AS days
        FROM employees WHERE status='active' AND civil_id_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 45 DAY)
    ")->fetchAll();
    foreach ($rows as $r) {
        $exists = $pdo->prepare("SELECT id FROM reminders WHERE reminder_type='civil_id' AND reference_id=? AND status='pending'");
        $exists->execute([$r['id']]);
        if (!$exists->fetchColumn()) {
            $pdo->prepare("INSERT INTO reminders (reminder_type,title,reference_table,reference_id,employee_id,remind_date,message,priority) VALUES (?,?,?,?,?,?,?,?)")
                ->execute(['civil_id','Civil ID Expiring — '.$r['name_en'],'employees',$r['id'],$r['id'],$r['civil_id_expiry'],
                           'Civil ID expires in '.$r['days'].' days', $r['days']<=7?'critical':'high']);
            $count++;
        }
    }

    // --- Approvals expiring ---
    $rows = $pdo->query("
        SELECT ha.id, ha.expiry_date, ha.approval_type, v.plate_number,
               DATEDIFF(ha.expiry_date, CURDATE()) AS days
        FROM hygiene_approvals ha
        LEFT JOIN vehicles v ON v.id=ha.vehicle_id
        WHERE ha.status='active' AND ha.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 45 DAY)
    ")->fetchAll();
    foreach ($rows as $r) {
        $exists = $pdo->prepare("SELECT id FROM reminders WHERE reminder_type='approval' AND reference_id=? AND status='pending'");
        $exists->execute([$r['id']]);
        if (!$exists->fetchColumn()) {
            $label = $r['plate_number'] ?? 'Fleet';
            $pdo->prepare("INSERT INTO reminders (reminder_type,title,reference_table,reference_id,remind_date,message,priority) VALUES (?,?,?,?,?,?,?)")
                ->execute(['approval','Approval Expiring — '.$label,'hygiene_approvals',$r['id'],$r['expiry_date'],
                           ucfirst(str_replace('_',' ',$r['approval_type'])).' approval expires in '.$r['days'].' days',
                           $r['days']<=7?'critical':($r['days']<=14?'high':'medium')]);
            $count++;
        }
    }

    // --- Service due (within 1000 km) ---
    $rows = $pdo->query("
        SELECT v.id, v.plate_number, v.current_km,
               (SELECT MAX(vs.next_service_km) FROM vehicle_services vs WHERE vs.vehicle_id=v.id) AS next_km
        FROM vehicles v WHERE v.status!='sold'
        HAVING next_km IS NOT NULL AND v.current_km >= (next_km - 1000)
    ")->fetchAll();
    foreach ($rows as $r) {
        $exists = $pdo->prepare("SELECT id FROM reminders WHERE reminder_type='service' AND vehicle_id=? AND status='pending'");
        $exists->execute([$r['id']]);
        if (!$exists->fetchColumn()) {
            $diff = $r['next_km'] - $r['current_km'];
            $pdo->prepare("INSERT INTO reminders (reminder_type,title,vehicle_id,remind_date,message,priority) VALUES (?,?,?,?,?,?)")
                ->execute(['service','Service Due — '.$r['plate_number'],$r['id'],$today,
                           $diff<=0?'Overdue by '.abs($diff).' km':'Service due in '.$diff.' km',
                           $diff<=0?'critical':'high']);
            $count++;
        }
    }

    return $count;
}

// ---- Handle POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;
    if ($action === 'add') {
        $fields = [
            'reminder_type' => $d['reminder_type'] ?? 'custom',
            'title'         => trim($d['title'] ?? ''),
            'vehicle_id'    => $d['vehicle_id'] ?: null,
            'employee_id'   => $d['employee_id'] ?: null,
            'remind_date'   => $d['remind_date'],
            'message'       => trim($d['message'] ?? ''),
            'priority'      => $d['priority'] ?? 'medium',
            'status'        => 'pending',
        ];
        $cols=implode(',',array_keys($fields)); $vals=implode(',',array_fill(0,count($fields),'?'));
        $pdo->prepare("INSERT INTO reminders ($cols) VALUES ($vals)")->execute(array_values($fields));
        setFlash('success', t('record_saved'));
        header("Location: ?module=reminders"); exit;
    }
    if ($action === 'update_status') {
        $newStatus = $d['new_status'] ?? 'acknowledged';
        $snooze = null;
        if ($newStatus === 'snoozed') {
            $days = (int)($d['snooze_days'] ?? 7);
            $snooze = date('Y-m-d', strtotime("+$days days"));
        }
        $pdo->prepare("UPDATE reminders SET status=?, snoozed_until=? WHERE id=?")->execute([$newStatus,$snooze,$id]);
        header("Location: ?module=reminders"); exit;
    }
    if ($action === 'generate') {
        $n = generateAutoReminders($pdo);
        setFlash('success', "Generated $n new reminder(s) from live data.");
        header("Location: ?module=reminders"); exit;
    }
    if ($action === 'read_notification') {
        $nid = (int)($d['nid'] ?? 0);
        $pdo->prepare("UPDATE notifications SET is_read=1 WHERE id=?")->execute([$nid]);
        header("Location: ?module=reminders&tab=notifications"); exit;
    }
    if ($action === 'read_all') {
        $pdo->exec("UPDATE notifications SET is_read=1");
        header("Location: ?module=reminders&tab=notifications"); exit;
    }
    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM reminders WHERE id=?")->execute([$id]);
        header("Location: ?module=reminders"); exit;
    }
}

// ---- Load data ----
$tab = $_GET['tab'] ?? 'reminders';

// Reminders
$rems = $pdo->query("
    SELECT r.*, v.plate_number, COALESCE(e.name_en,'—') AS emp_name
    FROM reminders r
    LEFT JOIN vehicles v  ON v.id=r.vehicle_id
    LEFT JOIN employees e ON e.id=r.employee_id
    WHERE r.status IN ('pending','snoozed')
      AND (r.snoozed_until IS NULL OR r.snoozed_until <= CURDATE())
    ORDER BY FIELD(r.priority,'critical','high','medium','low'), r.remind_date ASC
")->fetchAll();

$doneRems = $pdo->query("
    SELECT r.*, v.plate_number, COALESCE(e.name_en,'—') AS emp_name
    FROM reminders r
    LEFT JOIN vehicles v  ON v.id=r.vehicle_id
    LEFT JOIN employees e ON e.id=r.employee_id
    WHERE r.status IN ('acknowledged','completed','dismissed')
    ORDER BY r.id DESC LIMIT 30
")->fetchAll();

// Notifications
$notifs     = $pdo->query("SELECT * FROM notifications ORDER BY is_read ASC, created_at DESC LIMIT 50")->fetchAll();
$unreadCnt  = $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read=0")->fetchColumn();

// Group reminders
$overdue  = array_filter($rems, fn($r) => $r['remind_date'] < date('Y-m-d'));
$today    = array_filter($rems, fn($r) => $r['remind_date'] === date('Y-m-d'));
$upcoming = array_filter($rems, fn($r) => $r['remind_date'] > date('Y-m-d'));

$priorityColors = ['critical'=>'danger','high'=>'warning','medium'=>'info','low'=>'secondary'];
$typeIcons = ['insurance'=>'fa-shield-alt','service'=>'fa-wrench','license'=>'fa-id-card',
              'civil_id'=>'fa-id-badge','passport'=>'fa-passport','approval'=>'fa-file-certificate',
              'inspection'=>'fa-clipboard-check','custom'=>'fa-bell'];
?>

<!-- Top action bar -->
<div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
  <form method="post" action="?module=reminders&action=generate">
    <input type="hidden" name="action" value="generate">
    <button class="btn btn-sm btn-primary"><i class="fas fa-magic me-1"></i><?= t('generate_auto') ?></button>
  </form>
  <button class="btn btn-sm btn-success" onclick="new bootstrap.Modal(document.getElementById('addReminderModal')).show()">
    <i class="fas fa-plus me-1"></i><?= t('add_reminder') ?>
  </button>
  <?php if ($unreadCnt): ?>
  <span class="badge bg-danger ms-auto" style="font-size:13px"><i class="fas fa-bell me-1"></i><?= $unreadCnt ?> unread notifications</span>
  <?php endif; ?>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-0">
  <li class="nav-item"><a class="nav-link <?= $tab==='reminders'?'active':'' ?>" href="?module=reminders&tab=reminders">
    <i class="fas fa-clock me-1"></i><?= t('reminders') ?>
    <?php if (count($rems)): ?><span class="badge bg-<?= count($overdue)?'danger':'warning' ?> ms-1"><?= count($rems) ?></span><?php endif; ?>
  </a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='notifications'?'active':'' ?>" href="?module=reminders&tab=notifications">
    <i class="fas fa-bell me-1"></i><?= t('notifications') ?>
    <?php if ($unreadCnt): ?><span class="badge bg-danger ms-1"><?= $unreadCnt ?></span><?php endif; ?>
  </a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='history'?'active':'' ?>" href="?module=reminders&tab=history">
    <i class="fas fa-history me-1"></i><?= t('history') ?>
  </a></li>
</ul>

<div class="border border-top-0 rounded-bottom p-3 bg-white">

<!-- ---- REMINDERS TAB ---- -->
<?php if ($tab === 'reminders'): ?>

<?php if (!$rems): ?>
  <div class="text-center py-5">
    <i class="fas fa-check-circle fa-3x text-success mb-3 d-block opacity-50"></i>
    <h5 class="text-muted"><?= t('all_clear') ?> — No pending reminders</h5>
    <p class="text-muted small">Click "Generate Auto-Reminders" to scan for insurance, license, service due alerts.</p>
  </div>
<?php endif; ?>

<?php
function renderReminderGroup(array $items, string $heading, string $headColor, array $priorityColors, array $typeIcons): void {
    if (!$items) return;
    echo '<div class="mb-4">';
    echo '<h6 class="fw-bold text-'.$headColor.' mb-2"><i class="fas fa-circle me-1"></i>'.htmlspecialchars($heading).' ('.count($items).')</h6>';
    echo '<div class="row g-2">';
    foreach ($items as $r) {
        $pc = $priorityColors[$r['priority']] ?? 'secondary';
        $ti = $typeIcons[$r['reminder_type']] ?? 'fa-bell';
        echo '<div class="col-md-6 col-lg-4">';
        echo '<div class="card border-'.$pc.' h-100" style="border-left-width:4px!important">';
        echo '<div class="card-body py-2 px-3">';
        echo '<div class="d-flex align-items-start gap-2">';
        echo '<i class="fas '.$ti.' text-'.$pc.' mt-1" style="font-size:16px;flex-shrink:0"></i>';
        echo '<div class="flex-grow-1 min-width-0">';
        echo '<div class="fw-bold small">'.htmlspecialchars($r['title']).'</div>';
        if ($r['message']) echo '<div class="text-muted small mt-1">'.htmlspecialchars($r['message']).'</div>';
        if ($r['plate_number']) echo '<span class="badge bg-primary mt-1">'.htmlspecialchars($r['plate_number']).'</span> ';
        if ($r['emp_name'] && $r['emp_name'] !== '—') echo '<span class="badge bg-success mt-1">'.htmlspecialchars($r['emp_name']).'</span>';
        echo '<div class="mt-2 d-flex gap-1 flex-wrap">';
        // Action buttons
        foreach (['acknowledged'=>'outline-success fa-check','completed'=>'outline-primary fa-check-double','snoozed'=>'outline-warning fa-clock','dismissed'=>'outline-secondary fa-times'] as $st => $cls) {
            list($btnCls, $icon) = explode(' ', $cls);
            echo '<form method="post" action="?module=reminders&action=update_status&id='.$r['id'].'" style="display:inline">';
            echo '<input type="hidden" name="action" value="update_status">';
            echo '<input type="hidden" name="new_status" value="'.$st.'">';
            if ($st === 'snoozed') echo '<input type="hidden" name="snooze_days" value="7">';
            $label = ['acknowledged'=>'Ack','completed'=>'Done','snoozed'=>'+7d','dismissed'=>'Dismiss'][$st];
            echo '<button class="btn btn-xs btn-'.$btnCls.'"><i class="fas '.$icon.' me-1"></i>'.$label.'</button>';
            echo '</form>';
        }
        echo '<form method="post" action="?module=reminders&action=delete&id='.$r['id'].'" style="display:inline" onsubmit="return confirm(\'Delete this reminder?\')">';
        echo '<input type="hidden" name="action" value="delete">';
        echo '<button class="btn btn-xs btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>';
        echo '</form>';
        echo '</div></div></div></div></div></div>';
    }
    echo '</div></div>';
}
renderReminderGroup(array_values($overdue), '⚠️ Overdue', 'danger', $priorityColors, $typeIcons);
renderReminderGroup(array_values($today), '📅 Due Today', 'warning', $priorityColors, $typeIcons);
renderReminderGroup(array_values($upcoming), '🔵 Upcoming', 'primary', $priorityColors, $typeIcons);
?>

<!-- ---- NOTIFICATIONS TAB ---- -->
<?php elseif ($tab === 'notifications'): ?>

<?php if ($notifs): ?>
<div class="d-flex justify-content-end mb-2">
  <form method="post" action="?module=reminders&action=read_all">
    <input type="hidden" name="action" value="read_all">
    <button class="btn btn-xs btn-outline-secondary"><i class="fas fa-check-double me-1"></i><?= t('mark_read') ?> All</button>
  </form>
</div>
<?php endif; ?>

<div class="list-group list-group-flush">
<?php if (!$notifs): ?>
  <div class="text-center py-4 text-muted"><i class="fas fa-bell-slash fa-2x mb-2 d-block opacity-25"></i>No notifications yet</div>
<?php endif; ?>
<?php foreach ($notifs as $n): ?>
  <div class="list-group-item d-flex gap-3 align-items-start <?= !$n['is_read']?'bg-warning bg-opacity-10':'' ?> py-2">
    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
         style="width:36px;height:36px;background:rgba(0,0,0,.06)">
      <i class="fas <?= e($n['icon']) ?> text-<?= e($n['color']) ?>"></i>
    </div>
    <div class="flex-grow-1">
      <div class="fw-bold small"><?= e($n['title']) ?></div>
      <?php if ($n['message']): ?><div class="text-muted small"><?= e($n['message']) ?></div><?php endif; ?>
      <div class="text-muted" style="font-size:10px;font-family:monospace"><?= $n['created_at'] ?></div>
    </div>
    <div class="d-flex gap-1 align-items-center">
      <?php if (!$n['is_read']): ?>
      <form method="post" action="?module=reminders&action=read_notification">
        <input type="hidden" name="action" value="read_notification">
        <input type="hidden" name="nid" value="<?= $n['id'] ?>">
        <button class="btn btn-xs btn-outline-secondary"><?= t('mark_read') ?></button>
      </form>
      <?php else: ?>
        <span class="badge bg-secondary" style="font-size:9px">Read</span>
      <?php endif; ?>
      <?php if ($n['link']): ?><a href="<?= e($n['link']) ?>" class="btn btn-xs btn-outline-primary"><?= t('view') ?></a><?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>

<!-- ---- HISTORY TAB ---- -->
<?php elseif ($tab === 'history'): ?>
<table class="table table-sm table-hover">
  <thead><tr><th>Type</th><th>Title</th><th>Message</th><th>Priority</th><th>Date</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($doneRems as $r): ?>
  <tr>
    <td><i class="fas <?= $typeIcons[$r['reminder_type']]??'fa-bell' ?> me-1"></i><?= t($r['reminder_type']) ?></td>
    <td><?= e($r['title']) ?></td>
    <td class="text-muted small"><?= e($r['message']) ?></td>
    <td><span class="badge bg-<?= $priorityColors[$r['priority']]?? 'secondary' ?>"><?= t($r['priority']) ?></span></td>
    <td><?= fmtDate($r['remind_date']) ?></td>
    <td><?= statusBadge($r['status']) ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$doneRems): ?><tr><td colspan="6" class="text-center text-muted"><?= t('no_records') ?></td></tr><?php endif; ?>
  </tbody>
</table>

<?php endif; ?>

</div><!-- tab content -->

<!-- Add Reminder Modal -->
<div class="modal fade" id="addReminderModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="?module=reminders&action=add">
        <input type="hidden" name="action" value="add">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title"><?= t('add_reminder') ?></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label"><?= t('reminder_type') ?></label>
              <select name="reminder_type" class="form-select">
                <?php foreach (['insurance','service','license','civil_id','passport','approval','inspection','custom'] as $s): ?>
                <option value="<?= $s ?>"><?= t($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('priority') ?></label>
              <select name="priority" class="form-select">
                <?php foreach (['low','medium','high','critical'] as $s): ?>
                <option value="<?= $s ?>" <?= $s==='medium'?'selected':'' ?>><?= t($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12"><label class="form-label"><?= t('name') ?> / Title *</label><input name="title" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label"><?= t('vehicles') ?></label><select name="vehicle_id" class="form-select"><?= vehicleOptions() ?></select></div>
            <div class="col-md-6"><label class="form-label"><?= t('employees') ?></label><select name="employee_id" class="form-select"><?= employeeOptions() ?></select></div>
            <div class="col-md-6"><label class="form-label"><?= t('remind_date') ?> *</label><input name="remind_date" type="date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-12"><label class="form-label"><?= t('description') ?></label><textarea name="message" class="form-control" rows="3"></textarea></div>
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
