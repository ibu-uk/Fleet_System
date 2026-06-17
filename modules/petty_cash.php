<?php
// modules/petty_cash.php - Petty Cash Management Module

$pdo = getDB();
$id = (int)($_GET['id'] ?? 0);
// Action: prefer POST action when submitting forms, else use GET
$action = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']))
          ? $_POST['action']
          : ($_GET['action'] ?? 'list');

// ---- POST Actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;
    
    if ($action === 'set_opening_balance') {
        $amount = (float)$d['opening_balance'];
        try {
            $pdo->prepare("UPDATE cash_ledger SET opening_balance=?, current_balance=? WHERE id=1")->execute([$amount, $amount]);
            // Record as initial deposit
            $pdo->prepare("INSERT INTO cash_transactions (transaction_type, amount, balance_after, description, created_by) VALUES ('deposit', ?, ?, 'Opening Balance', ?)")
                ->execute([$amount, $amount, $_SESSION['user_id']]);
            setFlash('success', t('opening_balance_set'));
        } catch (PDOException $e) {
            setFlash('danger', t('error_occurred'));
        }
        header("Location: ?module=petty_cash"); exit;
    }
    
    if ($action === 'add_cash') {
        $amount = (float)$d['amount'];
        $description = trim($d['description'] ?? '');
        
        if (!$amount || $amount <= 0) {
            setFlash('danger', t('invalid_amount'));
        } else {
            try {
                $pdo->beginTransaction();
                
                // Get current balance
                $stmt = $pdo->query("SELECT current_balance FROM cash_ledger WHERE id=1");
                $current = $stmt->fetchColumn();
                $newBalance = $current + $amount;
                
                // Update ledger
                $pdo->prepare("UPDATE cash_ledger SET current_balance=? WHERE id=1")->execute([$newBalance]);
                
                // Record transaction
                $pdo->prepare("INSERT INTO cash_transactions (transaction_type, amount, balance_after, description, created_by) VALUES ('deposit', ?, ?, ?, ?)")
                    ->execute([$amount, $newBalance, $description, $_SESSION['user_id']]);
                
                $pdo->commit();
                setFlash('success', t('cash_added'));
            } catch (PDOException $e) {
                $pdo->rollBack();
                setFlash('danger', t('error_occurred'));
            }
        }
        header("Location: ?module=petty_cash"); exit;
    }
    
    if ($action === 'withdraw_cash') {
        $amount = (float)$d['amount'];
        $description = trim($d['description'] ?? '');
        
        if (!$amount || $amount <= 0) {
            setFlash('danger', t('invalid_amount'));
        } else {
            try {
                $pdo->beginTransaction();
                
                // Get current balance
                $stmt = $pdo->query("SELECT current_balance FROM cash_ledger WHERE id=1");
                $current = $stmt->fetchColumn();
                
                if ($amount > $current) {
                    $pdo->rollBack();
                    setFlash('danger', t('insufficient_balance'));
                    header("Location: ?module=petty_cash"); exit;
                }
                
                $newBalance = $current - $amount;
                
                // Update ledger
                $pdo->prepare("UPDATE cash_ledger SET current_balance=? WHERE id=1")->execute([$newBalance]);
                
                // Record transaction
                $pdo->prepare("INSERT INTO cash_transactions (transaction_type, amount, balance_after, description, created_by) VALUES ('withdrawal', ?, ?, ?, ?)")
                    ->execute([$amount, $newBalance, $description, $_SESSION['user_id']]);
                
                $pdo->commit();
                setFlash('success', t('cash_withdrawn'));
            } catch (PDOException $e) {
                $pdo->rollBack();
                setFlash('danger', t('error_occurred'));
            }
        }
        header("Location: ?module=petty_cash"); exit;
    }
}

// ---- MANAGER REPORT ----
if ($action === 'manager_report') {
    $rFrom  = $_GET['r_from'] ?? date('Y-m-01');
    $rTo    = $_GET['r_to']   ?? date('Y-m-t');
    $ledger = $pdo->query("SELECT * FROM cash_ledger WHERE id=1")->fetch();

    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_transactions WHERE transaction_type=? AND DATE(created_at) BETWEEN ? AND ?");
    $s->execute(['deposit',    $rFrom, $rTo]); $rDeposits    = (float)$s->fetchColumn();
    $s->execute(['withdrawal', $rFrom, $rTo]); $rWithdrawals = (float)$s->fetchColumn();

    $s2 = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status='approved' AND expense_date BETWEEN ? AND ?");
    $s2->execute([$rFrom, $rTo]); $rExpenses = (float)$s2->fetchColumn();
    $s2b = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status='approved' AND payment_method='cash' AND expense_date BETWEEN ? AND ?");
    $s2b->execute([$rFrom, $rTo]); $rCashExp = (float)$s2b->fetchColumn();
    $rNonCashExp = $rExpenses - $rCashExp;

    $catStmt = $pdo->prepare("
        SELECT c.name_en, COUNT(e.id) AS cnt, SUM(e.amount) AS total
        FROM expenses e LEFT JOIN expense_categories c ON c.id=e.category_id
        WHERE e.status='approved' AND e.expense_date BETWEEN ? AND ?
        GROUP BY e.category_id ORDER BY total DESC");
    $catStmt->execute([$rFrom, $rTo]); $catBreakdown = $catStmt->fetchAll();

    $txStmt = $pdo->prepare("
        SELECT ct.*, u.username FROM cash_transactions ct
        LEFT JOIN users u ON u.id=ct.created_by
        WHERE DATE(ct.created_at) BETWEEN ? AND ?
        ORDER BY ct.created_at DESC");
    $txStmt->execute([$rFrom, $rTo]); $rTx = $txStmt->fetchAll();

    $cashInHand  = (float)($ledger['current_balance'] ?? 0);
    // Cash expenses already deducted from current_balance; only non-cash affects future petty cash
    $netBalance  = $cashInHand - $rNonCashExp;
    $reportLabel = ($rFrom === $rTo) ? 'Daily Report — '.date('d M Y', strtotime($rFrom))
                                     : 'Report: '.date('d M Y', strtotime($rFrom)).' to '.date('d M Y', strtotime($rTo));
    ?>
<!DOCTYPE html><html lang="en">
<head><meta charset="UTF-8">
<title>Manager Report — <?= e($reportLabel) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<style>
  body{font-family:Arial,sans-serif;font-size:13px;padding:30px;color:#222}
  h4{font-weight:700} .section{margin-bottom:24px}
  table{width:100%;border-collapse:collapse;font-size:12px}
  th,td{padding:6px 10px;border:1px solid #ccc}
  thead{background:#222;color:#fff}
  tfoot td{background:#f0f0f0;font-weight:700}
  .badge-green{background:#198754;color:#fff;padding:2px 7px;border-radius:4px}
  .badge-red{background:#dc3545;color:#fff;padding:2px 7px;border-radius:4px}
  .kpi{display:inline-block;min-width:180px;border:1px solid #ddd;border-radius:6px;padding:12px 18px;margin:6px;text-align:center}
  .kpi .val{font-size:20px;font-weight:700}
  @media print{.no-print{display:none}.page-break{page-break-before:always}}
</style></head>
<body>
<div class="no-print mb-3 d-flex gap-2">
  <form method="get" class="d-flex gap-2 align-items-end flex-wrap">
    <input type="hidden" name="module" value="petty_cash">
    <input type="hidden" name="action" value="manager_report">
    <div><label class="form-label mb-0 small">From</label><input type="date" name="r_from" class="form-control form-control-sm" value="<?= e($rFrom) ?>"></div>
    <div><label class="form-label mb-0 small">To</label><input type="date" name="r_to" class="form-control form-control-sm" value="<?= e($rTo) ?>"></div>
    <button class="btn btn-sm btn-primary">Generate</button>
    <button type="button" class="btn btn-sm btn-secondary" onclick="window.print()"><i>🖨</i> Print / Save PDF</button>
    <a href="?module=petty_cash" class="btn btn-sm btn-outline-secondary">← Back</a>
  </form>
</div>

<div class="text-center mb-4">
  <h4>FLEET MANAGEMENT — MANAGER CASH REPORT</h4>
  <div class="text-muted"><?= e($reportLabel) ?> &nbsp;|&nbsp; Generated: <?= date('d M Y H:i') ?> &nbsp;|&nbsp; By: <?= e($_SESSION['user_name'] ?? '') ?></div>
</div>

<div class="section">
  <h6 class="fw-bold border-bottom pb-1">📊 Summary</h6>
  <div>
    <div class="kpi"><div class="text-muted small">Cash In Hand</div><div class="val text-success"><?= number_format($cashInHand,3) ?> KWD</div></div>
    <div class="kpi"><div class="text-muted small">Cash Added</div><div class="val text-primary">+<?= number_format($rDeposits,3) ?> KWD</div></div>
    <div class="kpi"><div class="text-muted small">Cash Withdrawn</div><div class="val text-danger">-<?= number_format($rWithdrawals,3) ?> KWD</div></div>
    <div class="kpi"><div class="text-muted small">Approved Expenses</div><div class="val text-warning">-<?= number_format($rExpenses,3) ?> KWD</div></div>
    <div class="kpi" style="border-color:<?= $netBalance>=0?'#198754':'#dc3545' ?>">
      <div class="text-muted small">Net Balance After Expenses</div>
      <div class="val" style="color:<?= $netBalance>=0?'#198754':'#dc3545' ?>"><?= number_format($netBalance,3) ?> KWD</div>
    </div>
  </div>
</div>

<?php if ($catBreakdown): ?>
<div class="section">
  <h6 class="fw-bold border-bottom pb-1">📂 Expenses by Category</h6>
  <table>
    <thead><tr><th>Category</th><th>Transactions</th><th>Total (KWD)</th></tr></thead>
    <tbody>
    <?php foreach ($catBreakdown as $c): ?>
    <tr><td><?= e($c['name_en']?:'Uncategorized') ?></td><td><?= $c['cnt'] ?></td><td><?= number_format($c['total'],3) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td>TOTAL</td><td><?= array_sum(array_column($catBreakdown,'cnt')) ?></td><td><?= number_format($rExpenses,3) ?></td></tr></tfoot>
  </table>
</div>
<?php endif; ?>

<?php if ($rTx): ?>
<div class="section page-break">
  <h6 class="fw-bold border-bottom pb-1">📋 Cash Transactions</h6>
  <table>
    <thead><tr><th>Date/Time</th><th>Type</th><th>Amount (KWD)</th><th>Balance After</th><th>Description</th><th>By</th></tr></thead>
    <tbody>
    <?php foreach ($rTx as $tx): ?>
    <tr>
      <td><?= $tx['created_at'] ?></td>
      <td><?= $tx['transaction_type']==='deposit'?'<span class="badge-green">Deposit</span>':'<span class="badge-red">Withdrawal</span>' ?></td>
      <td><?= $tx['transaction_type']==='deposit'?'+':'-' ?><?= number_format($tx['amount'],3) ?></td>
      <td><?= number_format($tx['balance_after'],3) ?></td>
      <td><?= e($tx['description']?:'—') ?></td>
      <td><?= e($tx['username']?:'—') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="mt-5 pt-4 border-top row no-print">&nbsp;</div>
<div class="mt-4 row">
  <div class="col-6"><div class="border-top pt-2 text-center text-muted small">Prepared By: <?= e($_SESSION['user_name'] ?? '') ?></div></div>
  <div class="col-6"><div class="border-top pt-2 text-center text-muted small">Manager Signature: ___________________________</div></div>
</div>

</body></html>
    <?php
    exit;
}

// ---- Get ledger data ----
$ledger = $pdo->query("SELECT * FROM cash_ledger WHERE id=1")->fetch();

// ---- This Month Summary ----
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');

$today = date('Y-m-d');

$s1 = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_transactions WHERE transaction_type=? AND DATE(created_at)=?");
$s1->execute(['deposit',    $today]); $tDeposits    = (float)$s1->fetchColumn();
$s1->execute(['withdrawal', $today]); $tWithdrawals = (float)$s1->fetchColumn();
// All approved expenses today (informational)
$s2 = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status='approved' AND expense_date=?");
$s2->execute([$today]); $tExpenses = (float)$s2->fetchColumn();
// Non-cash only (cash already deducted via cash_transactions)
$s3 = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status='approved' AND payment_method!='cash' AND expense_date=?");
$s3->execute([$today]); $tNonCashExp = (float)$s3->fetchColumn();

$s1 = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_transactions WHERE transaction_type=? AND DATE(created_at) BETWEEN ? AND ?");
$s1->execute(['deposit',    $monthStart, $monthEnd]); $mDeposits    = (float)$s1->fetchColumn();
$s1->execute(['withdrawal', $monthStart, $monthEnd]); $mWithdrawals = (float)$s1->fetchColumn();
$s2 = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status='approved' AND expense_date BETWEEN ? AND ?");
$s2->execute([$monthStart, $monthEnd]); $mExpenses = (float)$s2->fetchColumn();
$s3 = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE status='approved' AND payment_method!='cash' AND expense_date BETWEEN ? AND ?");
$s3->execute([$monthStart, $monthEnd]); $mNonCashExp = (float)$s3->fetchColumn();

$cashInHand  = (float)($ledger['current_balance'] ?? 0);
// Cash expenses already deducted from current_balance — only subtract non-cash for pending impact
$balAfterExp = $cashInHand - $mNonCashExp;

// ---- Get transactions ----
$fDateFrom = $_GET['date_from'] ?? '';
$fDateTo = $_GET['date_to'] ?? '';
$fType = $_GET['type'] ?? '';

// ---- Pagination ----
$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$countSql = "SELECT COUNT(*) FROM cash_transactions ct WHERE 1=1";
$params = [];
if ($fDateFrom) { $countSql .= " AND ct.created_at >= ?"; $params[] = $fDateFrom . ' 00:00:00'; }
if ($fDateTo)   { $countSql .= " AND ct.created_at <= ?"; $params[] = $fDateTo   . ' 23:59:59'; }
if ($fType)     { $countSql .= " AND ct.transaction_type = ?"; $params[] = $fType; }
$cStmt = $pdo->prepare($countSql); $cStmt->execute($params); $totalRows = (int)$cStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$sql = "SELECT ct.*, u.username FROM cash_transactions ct LEFT JOIN users u ON u.id=ct.created_by WHERE 1=1";
$params2 = [];
if ($fDateFrom) { $sql .= " AND ct.created_at >= ?"; $params2[] = $fDateFrom . ' 00:00:00'; }
if ($fDateTo)   { $sql .= " AND ct.created_at <= ?"; $params2[] = $fDateTo   . ' 23:59:59'; }
if ($fType)     { $sql .= " AND ct.transaction_type = ?"; $params2[] = $fType; }
$sql .= " ORDER BY ct.created_at DESC LIMIT $perPage OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params2);
$transactions = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h3><i class="fas fa-wallet me-2"></i><?= t('petty_cash') ?></h3>
  <div class="d-flex gap-2 flex-wrap">
    <a href="?module=petty_cash&action=manager_report&r_from=<?= date('Y-m-d') ?>&r_to=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-file-alt me-1"></i>Daily Report</a>
    <a href="?module=petty_cash&action=manager_report&r_from=<?= date('Y-m-01') ?>&r_to=<?= date('Y-m-t') ?>" class="btn btn-outline-primary btn-sm"><i class="fas fa-file-alt me-1"></i>Monthly Report</a>
    <a href="?module=petty_cash&action=manager_report" class="btn btn-dark btn-sm"><i class="fas fa-print me-1"></i>Manager Report</a>
    <?php if (!$ledger || $ledger['opening_balance'] == 0): ?>
    <button class="btn btn-warning btn-sm" onclick="openOpeningBalanceModal()"><i class="fas fa-cog me-1"></i><?= t('set_opening_balance') ?></button>
    <?php endif; ?>
  </div>
</div>

<!-- Balance Cards -->
<div class="row g-3 mb-3">
  <div class="col-md-4">
    <div class="card bg-primary text-white">
      <div class="card-body">
        <h6 class="card-title"><?= t('opening_balance') ?></h6>
        <h3><?= number_format($ledger['opening_balance'] ?? 0, 3) ?> <?= $ledger['currency'] ?? 'KWD' ?></h3>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card bg-success text-white">
      <div class="card-body">
        <h6 class="card-title"><?= t('current_balance') ?></h6>
        <h3><?= number_format($cashInHand, 3) ?> <?= $ledger['currency'] ?? 'KWD' ?></h3>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card <?= $balAfterExp >= 0 ? 'bg-info' : 'bg-danger' ?> text-white">
      <div class="card-body">
        <h6 class="card-title">Non-Cash Expenses This Month</h6>
        <h3><?= number_format($mNonCashExp, 3) ?> KWD</h3>
      </div>
    </div>
  </div>
</div>

<!-- Today Summary -->
<div class="card mb-3 border-info">
  <div class="card-header bg-info text-white fw-bold">
    <i class="fas fa-sun me-2"></i>Today's Summary &mdash; <?= date('d M Y') ?>
  </div>
  <div class="card-body p-0">
    <div class="row g-0 text-center">
      <div class="col-md-3 border-end py-3">
        <div class="text-muted small mb-1">Cash Added Today</div>
        <div class="fw-bold text-success fs-5">+<?= number_format($tDeposits,3) ?> KWD</div>
      </div>
      <div class="col-md-3 border-end py-3">
        <div class="text-muted small mb-1">Cash Withdrawn Today</div>
        <div class="fw-bold text-danger fs-5">-<?= number_format($tWithdrawals,3) ?> KWD</div>
      </div>
      <div class="col-md-3 border-end py-3">
        <div class="text-muted small mb-1">Approved Expenses Today</div>
        <div class="fw-bold text-warning fs-5">-<?= number_format($tExpenses,3) ?> KWD</div>
      </div>
      <div class="col-md-3 py-3">
        <div class="text-muted small mb-1">Cash In Hand Now</div>
        <div class="fw-bold <?= $cashInHand >= 0 ? 'text-success' : 'text-danger' ?> fs-5">
          <?= number_format($cashInHand, 3) ?> KWD
        </div>
      </div>
    </div>
  </div>
</div>

<!-- This Month Summary -->
<div class="card mb-4 border-warning">
  <div class="card-header bg-warning text-dark fw-bold">
    <i class="fas fa-calendar-alt me-2"></i>This Month Summary — <?= date('F Y') ?>
  </div>
  <div class="card-body p-0">
    <div class="row g-0 text-center">
      <div class="col-md-3 border-end py-3">
        <div class="text-muted small mb-1">Cash Added</div>
        <div class="fw-bold text-success fs-5">+<?= number_format($mDeposits,3) ?> KWD</div>
      </div>
      <div class="col-md-3 border-end py-3">
        <div class="text-muted small mb-1">Cash Withdrawn</div>
        <div class="fw-bold text-danger fs-5">-<?= number_format($mWithdrawals,3) ?> KWD</div>
      </div>
      <div class="col-md-3 border-end py-3">
        <div class="text-muted small mb-1">Approved Expenses</div>
        <div class="fw-bold text-warning fs-5">-<?= number_format($mExpenses,3) ?> KWD</div>
      </div>
      <div class="col-md-3 py-3">
        <div class="text-muted small mb-1">Cash In Hand Now</div>
        <div class="fw-bold <?= $cashInHand >= 0 ? 'text-success' : 'text-danger' ?> fs-5"><?= number_format($cashInHand,3) ?> KWD</div>
      </div>
    </div>
  </div>
</div>

<!-- Action Buttons -->
<div class="row g-3 mb-4">
  <div class="col-md-6">
    <button class="btn btn-success w-100" onclick="openAddCashModal()">
      <i class="fas fa-plus-circle me-2"></i><?= t('add_cash') ?>
    </button>
  </div>
  <div class="col-md-6">
    <button class="btn btn-danger w-100" onclick="openWithdrawCashModal()">
      <i class="fas fa-minus-circle me-2"></i><?= t('withdraw_cash') ?>
    </button>
  </div>
</div>

<!-- Filters -->
<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-3">
      <input type="hidden" name="module" value="petty_cash">
      <div class="col-md-3">
        <label class="form-label"><?= t('date_from') ?></label>
        <input type="date" name="date_from" class="form-control" value="<?= e($fDateFrom) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label"><?= t('date_to') ?></label>
        <input type="date" name="date_to" class="form-control" value="<?= e($fDateTo) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label"><?= t('transaction_type') ?></label>
        <select name="type" class="form-select">
          <option value=""><?= t('all') ?></option>
          <option value="deposit" <?= $fType === 'deposit' ? 'selected' : '' ?>><?= t('deposit') ?></option>
          <option value="withdrawal" <?= $fType === 'withdrawal' ? 'selected' : '' ?>><?= t('withdrawal') ?></option>
        </select>
      </div>
      <div class="col-md-3 d-flex align-items-end">
        <button type="submit" class="btn btn-primary me-2"><?= t('filter') ?></button>
        <a href="?module=petty_cash" class="btn btn-secondary"><?= t('reset') ?></a>
      </div>
    </form>
  </div>
</div>

<!-- Transactions Table -->
<div class="card">
  <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
    <span><i class="fas fa-list me-2"></i><?= t('cash_ledger') ?> <small class="opacity-75">(<?= $totalRows ?> <?= t('total_transactions') ?>)</small></span>
    <div>
      <button class="btn btn-sm btn-light me-1" onclick="exportLedger()"><i class="fas fa-file-excel me-1"></i><?= t('export_excel') ?></button>
      <button class="btn btn-sm btn-light" onclick="printLedger()"><i class="fas fa-print me-1"></i><?= t('print') ?></button>
    </div>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="ledgerTable">
        <thead>
          <tr>
            <th><?= t('date') ?></th>
            <th><?= t('type') ?></th>
            <th><?= t('amount') ?></th>
            <th><?= t('balance_after') ?></th>
            <th><?= t('description') ?></th>
            <th><?= t('created_by') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($transactions as $tr): ?>
          <tr>
            <td><?= fmtDate($tr['created_at'], true) ?></td>
            <td>
              <span class="badge bg-<?= $tr['transaction_type'] === 'deposit' ? 'success' : 'danger' ?>">
                <?= t($tr['transaction_type']) ?>
              </span>
            </td>
            <td class="<?= $tr['transaction_type'] === 'deposit' ? 'text-success' : 'text-danger' ?>">
              <?= $tr['transaction_type'] === 'deposit' ? '+' : '-' ?><?= number_format($tr['amount'], 3) ?>
            </td>
            <td><strong><?= number_format($tr['balance_after'], 3) ?></strong></td>
            <td><?= e($tr['description'] ?: '—') ?></td>
            <td><?= e($tr['username'] ?: '—') ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$transactions): ?>
          <tr><td colspan="6" class="text-center text-muted py-3"><?= t('no_records') ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3">
  <ul class="pagination pagination-sm justify-content-center">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
      <a class="page-link" href="?module=petty_cash&page=<?= $p ?>&date_from=<?= e($fDateFrom) ?>&date_to=<?= e($fDateTo) ?>&type=<?= e($fType) ?>"><?= $p ?></a>
    </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>

<!-- Opening Balance Modal -->
<div class="modal fade" id="openingBalanceModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="set_opening_balance">
        <div class="modal-header bg-warning text-dark">
          <h5 class="modal-title"><?= t('set_opening_balance') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label"><?= t('opening_balance') ?> *</label>
            <input name="opening_balance" type="number" step="0.001" class="form-control" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('cancel') ?></button>
          <button type="submit" class="btn btn-warning"><?= t('save') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Add Cash Modal -->
<div class="modal fade" id="addCashModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="add_cash">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title"><?= t('add_cash') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label"><?= t('amount') ?> *</label>
            <input name="amount" type="number" step="0.001" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label"><?= t('description') ?></label>
            <textarea name="description" class="form-control" rows="3"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('cancel') ?></button>
          <button type="submit" class="btn btn-success"><?= t('add') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Withdraw Cash Modal -->
<div class="modal fade" id="withdrawCashModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="withdraw_cash">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title"><?= t('withdraw_cash') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label"><?= t('amount') ?> *</label>
            <input name="amount" type="number" step="0.001" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label"><?= t('description') ?></label>
            <textarea name="description" class="form-control" rows="3"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('cancel') ?></button>
          <button type="submit" class="btn btn-danger"><?= t('withdraw') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openOpeningBalanceModal(){
  new bootstrap.Modal(document.getElementById('openingBalanceModal')).show();
}
function openAddCashModal(){
  new bootstrap.Modal(document.getElementById('addCashModal')).show();
}
function openWithdrawCashModal(){
  new bootstrap.Modal(document.getElementById('withdrawCashModal')).show();
}
function printLedger(){
  window.print();
}
function exportLedger(){
  const table=document.getElementById('ledgerTable');
  const wb=XLSX.utils.table_to_book(table,{sheet:'Cash Ledger'});
  XLSX.writeFile(wb,'cash_ledger.xlsx');
}
</script>
