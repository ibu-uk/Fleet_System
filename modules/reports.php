<?php
// modules/reports.php - Reports Module

$pdo = getDB();
$reportType = $_GET['report'] ?? 'daily';
$fDateFrom = $_GET['date_from'] ?? date('Y-m-01');
$fDateTo = $_GET['date_to'] ?? date('Y-m-t');
$fCategory = (int)($_GET['category_id'] ?? 0);
$fVendor = trim($_GET['vendor'] ?? '');

// ---- Generate Report Data ----
$data = [];
$title = '';
$columns = [];

switch ($reportType) {
    case 'daily':
        $title = t('daily_expense_report');
        $sql = "SELECT DATE(expense_date) as report_date, COUNT(*) as count, SUM(amount) as total
                FROM expenses 
                WHERE expense_date BETWEEN ? AND ? AND status='approved'";
        $params = [$fDateFrom, $fDateTo];
        if ($fCategory) { $sql .= " AND category_id=?"; $params[] = $fCategory; }
        $sql .= " GROUP BY DATE(expense_date) ORDER BY report_date DESC";
        $columns = ['report_date' => t('date'), 'count' => t('transactions'), 'total' => t('total_amount')];
        break;
        
    case 'monthly':
        $title = t('monthly_expense_report');
        $sql = "SELECT DATE_FORMAT(expense_date, '%Y-%m') as report_month, COUNT(*) as count, SUM(amount) as total
                FROM expenses 
                WHERE expense_date BETWEEN ? AND ? AND status='approved'";
        $params = [$fDateFrom, $fDateTo];
        if ($fCategory) { $sql .= " AND category_id=?"; $params[] = $fCategory; }
        $sql .= " GROUP BY DATE_FORMAT(expense_date, '%Y-%m') ORDER BY report_month DESC";
        $columns = ['report_month' => t('month'), 'count' => t('transactions'), 'total' => t('total_amount')];
        break;
        
    case 'category':
        $title = t('category_wise_report');
        $sql = "SELECT c.name_en as category_name, COUNT(e.id) as count, SUM(e.amount) as total
                FROM expenses e
                LEFT JOIN expense_categories c ON c.id = e.category_id
                WHERE e.expense_date BETWEEN ? AND ? AND e.status='approved'";
        $params = [$fDateFrom, $fDateTo];
        $sql .= " GROUP BY e.category_id ORDER BY total DESC";
        $columns = ['category_name' => t('category'), 'count' => t('transactions'), 'total' => t('total_amount')];
        break;
        
    case 'vendor':
        $title = t('vendor_wise_report');
        $sql = "SELECT vendor_name, COUNT(*) as count, SUM(amount) as total
                FROM expenses 
                WHERE expense_date BETWEEN ? AND ? AND status='approved'";
        $params = [$fDateFrom, $fDateTo];
        if ($fVendor) { $sql .= " AND vendor_name LIKE ?"; $params[] = "%$fVendor%"; }
        $sql .= " GROUP BY vendor_name ORDER BY total DESC";
        $columns = ['vendor_name' => t('vendor'), 'count' => t('transactions'), 'total' => t('total_amount')];
        break;

    case 'payment_method':
        $title = 'Expenses by Payment Method';
        $sql = "SELECT payment_method, COUNT(*) as count, SUM(amount) as total
                FROM expenses
                WHERE expense_date BETWEEN ? AND ? AND status='approved'
                GROUP BY payment_method ORDER BY total DESC";
        $params = [$fDateFrom, $fDateTo];
        $columns = ['payment_method' => 'Payment Method', 'count' => t('transactions'), 'total' => t('total_amount')];
        break;
        
    case 'ledger':
        $title = t('cash_ledger_report');
        $sql = "SELECT DATE(created_at) as report_date, 
                SUM(CASE WHEN transaction_type='deposit' THEN amount ELSE 0 END) as deposits,
                SUM(CASE WHEN transaction_type='withdrawal' THEN amount ELSE 0 END) as withdrawals,
                MAX(balance_after) as closing_balance
                FROM cash_transactions
                WHERE created_at BETWEEN ? AND ?";
        $params = [$fDateFrom, $fDateTo];
        $sql .= " GROUP BY DATE(created_at) ORDER BY report_date DESC";
        $columns = ['report_date' => t('date'), 'deposits' => t('deposits'), 'withdrawals' => t('withdrawals'), 'closing_balance' => t('closing_balance')];
        break;
        
    default:
        $reportType = 'daily';
        $title = t('daily_expense_report');
        $sql = "SELECT DATE(expense_date) as report_date, COUNT(*) as count, SUM(amount) as total
                FROM expenses 
                WHERE expense_date BETWEEN ? AND ? AND status='approved'
                GROUP BY DATE(expense_date) ORDER BY report_date DESC";
        $params = [$fDateFrom, $fDateTo];
        $columns = ['report_date' => t('date'), 'count' => t('transactions'), 'total' => t('total_amount')];
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$data = $stmt->fetchAll();

// Get categories for filter
$categories = $pdo->query("SELECT * FROM expense_categories WHERE status='active' ORDER BY name_en")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h3><i class="fas fa-chart-bar me-2"></i><?= t('reports') ?></h3>
</div>

<!-- Report Type Selector -->
<div class="card mb-4">
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label"><?= t('report_type') ?></label>
        <select name="report" class="form-select" onchange="changeReport(this.value)">
          <option value="daily" <?= $reportType === 'daily' ? 'selected' : '' ?>><?= t('daily_expense_report') ?></option>
          <option value="monthly" <?= $reportType === 'monthly' ? 'selected' : '' ?>><?= t('monthly_expense_report') ?></option>
          <option value="category" <?= $reportType === 'category' ? 'selected' : '' ?>><?= t('category_wise_report') ?></option>
          <option value="vendor" <?= $reportType === 'vendor' ? 'selected' : '' ?>><?= t('vendor_wise_report') ?></option>
          <option value="payment_method" <?= $reportType === 'payment_method' ? 'selected' : '' ?>>Expenses by Payment Method</option>
          <option value="ledger" <?= $reportType === 'ledger' ? 'selected' : '' ?>><?= t('cash_ledger_report') ?></option>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label"><?= t('date_from') ?></label>
        <input type="date" id="dateFrom" class="form-control" value="<?= e($fDateFrom) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label"><?= t('date_to') ?></label>
        <input type="date" id="dateTo" class="form-control" value="<?= e($fDateTo) ?>">
      </div>
      <?php if ($reportType !== 'ledger' && $reportType !== 'category'): ?>
      <div class="col-md-2">
        <label class="form-label"><?= t('category') ?></label>
        <select id="categoryFilter" class="form-select">
          <option value=""><?= t('all') ?></option>
          <?php foreach ($categories as $cat): ?>
          <option value="<?= $cat['id'] ?>" <?= $fCategory === $cat['id'] ? 'selected' : '' ?>><?= e($cat['name_en']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <?php if ($reportType === 'vendor'): ?>
      <div class="col-md-2">
        <label class="form-label"><?= t('vendor') ?></label>
        <input type="text" id="vendorFilter" class="form-control" value="<?= e($fVendor) ?>">
      </div>
      <?php endif; ?>
      <div class="col-md-<?= ($reportType === 'ledger' || $reportType === 'category') ? '3' : '1' ?> d-flex align-items-end">
        <button class="btn btn-primary me-2" onclick="generateReport()">
          <i class="fas fa-sync me-1"></i><?= t('generate') ?>
        </button>
        <button class="btn btn-success me-2" onclick="printReport()">
          <i class="fas fa-print me-1"></i><?= t('print') ?>
        </button>
        <button class="btn btn-info" onclick="exportToExcel()">
          <i class="fas fa-file-excel me-1"></i><?= t('export_excel') ?>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Report Card -->
<div class="card" id="reportCard">
  <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
    <span><i class="fas fa-file-alt me-2"></i><?= $title ?></span>
    <small><?= t('period') ?>: <?= fmtDate($fDateFrom) ?> - <?= fmtDate($fDateTo) ?></small>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="reportTable">
        <thead>
          <tr>
            <?php foreach ($columns as $key => $label): ?>
            <th><?= $label ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($data as $row): ?>
          <tr>
            <?php foreach ($columns as $key => $label): ?>
            <td>
              <?php if (in_array($key, ['total', 'deposits', 'withdrawals', 'closing_balance'])): ?>
                <strong><?= number_format($row[$key] ?? 0, 3) ?> KWD</strong>
              <?php else: ?>
                <?= e($row[$key] ?? '—') ?>
              <?php endif; ?>
            </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
          <?php if (!$data): ?>
          <tr><td colspan="<?= count($columns) ?>" class="text-center text-muted py-3"><?= t('no_records') ?></td></tr>
          <?php endif; ?>
        </tbody>
        <?php if ($data): ?>
        <tfoot>
          <tr class="table-primary">
            <td colspan="<?= count($columns) - 1 ?>" class="text-end"><strong><?= t('grand_total') ?></strong></td>
            <td>
              <?php 
              $total = 0;
              foreach ($data as $row) {
                if (isset($row['total'])) $total += $row['total'];
                elseif (isset($row['deposits'])) $total += $row['deposits'] - $row['withdrawals'];
              }
              ?>
              <strong><?= number_format($total, 3) ?> KWD</strong>
            </td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>
</div>

<script>
function changeReport(type){
  window.location.href='?module=reports&report='+type+'&date_from='+document.getElementById('dateFrom').value+'&date_to='+document.getElementById('dateTo').value;
}
function generateReport(){
  const type=document.querySelector('select[name="report"]').value;
  const dateFrom=document.getElementById('dateFrom').value;
  const dateTo=document.getElementById('dateTo').value;
  let url='?module=reports&report='+type+'&date_from='+dateFrom+'&date_to='+dateTo;
  if(document.getElementById('categoryFilter')){
    url+='&category_id='+document.getElementById('categoryFilter').value;
  }
  if(document.getElementById('vendorFilter')){
    url+='&vendor='+document.getElementById('vendorFilter').value;
  }
  window.location.href=url;
}
function printReport(){
  window.print();
}
function exportToExcel(){
  const table=document.getElementById('reportTable');
  const wb=XLSX.utils.table_to_book(table,{sheet:'Report'});
  XLSX.writeFile(wb,'<?= $reportType ?>_report.xlsx');
}
</script>

<style>
@media print {
  #sidebar, #topbar, .card:not(#reportCard), .btn { display: none !important; }
  #main { margin: 0 !important; }
  #content { padding: 0 !important; }
  .card { border: none !important; box-shadow: none !important; }
}
</style>
