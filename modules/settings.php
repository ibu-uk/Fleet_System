<?php
// modules/settings.php - Application Settings (admin only)

$pdo  = getDB();
$lang = $_SESSION['lang'] ?? 'en';

// Admin guard
if (!isAdmin()) {
    echo '<div class="alert alert-danger"><i class="fas fa-lock me-2"></i>'.t('admin_only').'</div>';
    return;
}

// ---- Save settings ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_bonus') {
    setSetting('bonus_enabled',        isset($_POST['bonus_enabled']) ? '1' : '0');
    setSetting('bonus_monthly_target', max(1, (int)($_POST['bonus_monthly_target'] ?? 450)));
    setSetting('bonus_amount',         max(0, (float)($_POST['bonus_amount'] ?? 0)));
    setSetting('daily_order_target',   max(1, (int)($_POST['daily_order_target'] ?? 15)));
    setFlash('success', t('record_updated'));
    header('Location: ?module=settings'); exit;
}

$bonusEnabled = (int)getSetting('bonus_enabled', '0') === 1;
$monthlyTarget = (int)getSetting('bonus_monthly_target', '450');
$bonusAmt = (float)getSetting('bonus_amount', '0');
$dailyTarget = (int)getSetting('daily_order_target', '15');

// Count bonus-eligible drivers for context
$eligibleCount = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE status='active' AND bonus_eligible=1")->fetchColumn();
$activeDrivers = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE status='active'")->fetchColumn();
?>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header fw-bold"><i class="fas fa-award me-2 text-warning"></i><?= t('bonus_policy') ?></div>
      <div class="card-body">
        <form method="post" action="?module=settings">
          <input type="hidden" name="action" value="save_bonus">

          <!-- Master toggle -->
          <div class="d-flex align-items-center justify-content-between p-3 mb-3 rounded" style="background:#fafbfc;border:1px solid var(--border)">
            <div>
              <div class="fw-bold"><?= t('enable_bonus') ?></div>
              <small class="text-muted"><?= t('enable_bonus_hint') ?></small>
            </div>
            <div class="form-check form-switch fs-4 mb-0">
              <input class="form-check-input" type="checkbox" role="switch" name="bonus_enabled" id="bonusEnabled" <?= $bonusEnabled ? 'checked' : '' ?>>
            </div>
          </div>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label"><?= t('bonus_monthly_target') ?></label>
              <div class="input-group">
                <input type="number" min="1" name="bonus_monthly_target" class="form-control" value="<?= $monthlyTarget ?>">
                <span class="input-group-text"><?= t('orders') ?></span>
              </div>
              <small class="text-muted"><?= t('bonus_target_hint') ?></small>
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('bonus_amount') ?></label>
              <div class="input-group">
                <input type="number" min="0" step="0.001" name="bonus_amount" class="form-control" value="<?= $bonusAmt ?>">
                <span class="input-group-text">KWD</span>
              </div>
              <small class="text-muted"><?= t('bonus_amount_hint') ?></small>
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('daily_order_target') ?></label>
              <div class="input-group">
                <input type="number" min="1" name="daily_order_target" class="form-control" value="<?= $dailyTarget ?>">
                <span class="input-group-text"><?= t('orders') ?></span>
              </div>
              <small class="text-muted"><?= t('daily_target_hint') ?></small>
            </div>
          </div>

          <div class="mt-4">
            <button class="btn btn-warning"><i class="fas fa-save me-1"></i><?= t('save') ?></button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card">
      <div class="card-header fw-bold"><i class="fas fa-info-circle me-2 text-info"></i><?= t('how_bonus_works') ?></div>
      <div class="card-body">
        <ol class="small mb-3" style="padding-<?= $isRTL?'right':'left' ?>:18px;line-height:1.9">
          <li><?= t('bonus_step_1') ?></li>
          <li><?= t('bonus_step_2') ?></li>
          <li><?= t('bonus_step_3') ?></li>
          <li><?= t('bonus_step_4') ?></li>
        </ol>
        <div class="alert alert-info py-2 mb-0 small">
          <i class="fas fa-users me-1"></i>
          <strong><?= $eligibleCount ?></strong> / <?= $activeDrivers ?> <?= t('drivers_bonus_eligible') ?>.
          <a href="?module=employees"><?= t('manage_in_employees') ?></a>
        </div>
      </div>
    </div>
  </div>
</div>
