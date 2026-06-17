<?php
// modules/expenses.php - Expenses Management Module

$pdo = getDB();
$id = (int)($_GET['id'] ?? 0);
// Action: POST forms embed action in hidden field, GET links use query string
$action = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']))
          ? $_POST['action']
          : ($_GET['action'] ?? 'list');

$paymentMethods = ['cash', 'bank_transfer', 'card', 'cheque'];
$statuses = ['pending', 'approved', 'rejected'];

// ---- GET-based state changes (approve / reject / delete_attachment) ----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'approve' && $id) {
        try {
            $pdo->beginTransaction();
            $exp = $pdo->prepare("SELECT * FROM expenses WHERE id=?");
            $exp->execute([$id]); $exp = $exp->fetch();
            if (!$exp) throw new Exception('Expense not found');

            $pdo->prepare("UPDATE expenses SET status='approved', approved_by=?, approved_at=NOW() WHERE id=?")
                ->execute([$_SESSION['user_id'], $id]);

            // Auto-deduct from petty cash if paid by cash
            if ($exp['payment_method'] === 'cash') {
                $bal = (float)$pdo->query("SELECT current_balance FROM cash_ledger WHERE id=1")->fetchColumn();
                $newBal = $bal - $exp['amount'];
                $pdo->prepare("UPDATE cash_ledger SET current_balance=? WHERE id=1")->execute([$newBal]);
                $desc = 'Expense #'.$id.($exp['vendor_name'] ? ' — '.$exp['vendor_name'] : '');
                $pdo->prepare("INSERT INTO cash_transactions (transaction_type,amount,balance_after,description,created_by) VALUES ('withdrawal',?,?,?,?)")
                    ->execute([$exp['amount'], $newBal, $desc, $_SESSION['user_id']]);
                $pdo->commit();
                setFlash('success', t('expense_approved').' — '.number_format($exp['amount'],3).' KWD deducted from petty cash.');
            } else {
                $pdo->commit();
                setFlash('success', t('expense_approved').' ('.ucfirst(str_replace('_',' ',$exp['payment_method'])).') — petty cash not affected.');
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', t('error_occurred').' '.$e->getMessage());
        }
        header("Location: ?module=expenses"); exit;
    }
    if ($action === 'reject' && $id) {
        try {
            $pdo->beginTransaction();
            $exp = $pdo->prepare("SELECT * FROM expenses WHERE id=?");
            $exp->execute([$id]); $exp = $exp->fetch();

            $pdo->prepare("UPDATE expenses SET status='rejected', approved_by=?, approved_at=NOW() WHERE id=?")
                ->execute([$_SESSION['user_id'], $id]);

            // If was previously approved cash expense, reverse the deduction
            if (($exp['status'] ?? '') === 'approved' && $exp['payment_method'] === 'cash') {
                $bal = (float)$pdo->query("SELECT current_balance FROM cash_ledger WHERE id=1")->fetchColumn();
                $newBal = $bal + $exp['amount'];
                $pdo->prepare("UPDATE cash_ledger SET current_balance=? WHERE id=1")->execute([$newBal]);
                $pdo->prepare("INSERT INTO cash_transactions (transaction_type,amount,balance_after,description,created_by) VALUES ('deposit',?,?,?,?)")
                    ->execute([$exp['amount'], $newBal, 'Reversal: Expense #'.$id, $_SESSION['user_id']]);
                $pdo->commit();
                setFlash('success', t('expense_rejected').' — '.number_format($exp['amount'],3).' KWD returned to petty cash.');
            } else {
                $pdo->commit();
                setFlash('success', t('expense_rejected'));
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', t('error_occurred'));
        }
        header("Location: ?module=expenses"); exit;
    }
    if ($action === 'delete_attachment' && $id) {
        $attachId = (int)($_GET['attach_id'] ?? 0);
        if ($attachId) {
            try {
                $stmt = $pdo->prepare("SELECT file_path FROM expense_attachments WHERE id=?");
                $stmt->execute([$attachId]);
                $file = $stmt->fetch();
                if ($file) { $fp = __DIR__ . '/../' . $file['file_path']; if (file_exists($fp)) unlink($fp); }
                $pdo->prepare("DELETE FROM expense_attachments WHERE id=?")->execute([$attachId]);
                setFlash('success', t('record_deleted'));
            } catch (PDOException $e) { setFlash('danger', t('error_occurred')); }
        }
        header("Location: ?module=expenses&action=view&id=" . $id); exit;
    }
    if ($action === 'delete_category' && $id) {
        try {
            $pdo->prepare("DELETE FROM expense_categories WHERE id=?")->execute([$id]);
            setFlash('success', t('record_deleted'));
        } catch (PDOException $e) { setFlash('danger', t('error_occurred')); }
        header("Location: ?module=expenses&action=categories"); exit;
    }
}

// ---- POST Actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;
    
    if ($action === 'add' || $action === 'edit') {
        $fields = [
            'category_id' => (int)$d['category_id'],
            'expense_date' => $d['expense_date'],
            'amount' => (float)$d['amount'],
            'currency' => $d['currency'] ?? 'KWD',
            'vendor_name' => trim($d['vendor_name'] ?? ''),
            'vendor_contact' => trim($d['vendor_contact'] ?? ''),
            'invoice_number' => trim($d['invoice_number'] ?? ''),
            'payment_method' => $d['payment_method'] ?? 'cash',
            'description_en' => trim($d['description_en'] ?? ''),
            'description_ar' => trim($d['description_ar'] ?? ''),
            'notes' => trim($d['notes'] ?? ''),
        ];
        
        if (!$fields['category_id'] || !$fields['expense_date'] || !$fields['amount']) {
            setFlash('danger', t('required_fields'));
        } else {
            try {
                if ($action === 'add') {
                    $cols = implode(',', array_keys($fields));
                    $vals = implode(',', array_fill(0, count($fields), '?'));
                    $fields['created_by'] = $_SESSION['user_id'];
                    $cols .= ',created_by';
                    $vals .= ',?';
                    $pdo->prepare("INSERT INTO expenses ($cols) VALUES ($vals)")->execute(array_values($fields));
                    setFlash('success', t('record_saved'));
                } else {
                    $set = implode('=?,', array_keys($fields)) . '=?';
                    $pdo->prepare("UPDATE expenses SET $set WHERE id=?")->execute([...array_values($fields), $id]);
                    setFlash('success', t('record_updated'));
                }
            } catch (PDOException $e) {
                setFlash('danger', t('error_occurred') . ' ' . $e->getMessage());
            }
        }
        header("Location: ?module=expenses"); exit;
    }
    
    if ($action === 'delete') {
        try {
            $pdo->prepare("DELETE FROM expenses WHERE id=?")->execute([$id]);
            setFlash('success', t('record_deleted'));
        } catch (PDOException $e) {
            setFlash('danger', t('error_occurred'));
        }
        header("Location: ?module=expenses"); exit;
    }
    
    if ($action === 'add_category') {
        $nameEn = trim($d['name_en']);
        $nameAr = trim($d['name_ar'] ?? '');
        $descEn = trim($d['description_en'] ?? '');
        $descAr = trim($d['description_ar'] ?? '');
        
        if (!$nameEn) {
            setFlash('danger', t('required_fields'));
        } else {
            try {
                $pdo->prepare("INSERT INTO expense_categories (name_en, name_ar, description_en, description_ar) VALUES (?, ?, ?, ?)")
                    ->execute([$nameEn, $nameAr, $descEn, $descAr]);
                setFlash('success', t('record_saved'));
            } catch (PDOException $e) {
                setFlash('danger', t('error_occurred'));
            }
        }
        header("Location: ?module=expenses&action=categories"); exit;
    }
    
    if ($action === 'upload_attachment') {
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === 0) {
            $uploadDir = __DIR__ . '/../uploads/expense_attachments/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            
            $fileName = time() . '_' . basename($_FILES['attachment']['name']);
            $filePath = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $filePath)) {
                $pdo->prepare("INSERT INTO expense_attachments (expense_id, file_name, file_path, file_size, file_type) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$id, $fileName, 'uploads/expense_attachments/' . $fileName, $_FILES['attachment']['size'], $_FILES['attachment']['type']]);
                setFlash('success', t('file_uploaded'));
            } else {
                setFlash('danger', t('upload_failed'));
            }
        }
        header("Location: ?module=expenses&action=view&id=" . $id); exit;
    }
    
}

// ---- Filters ----
$fCategory = (int)($_GET['category_id'] ?? 0);
$fVendor = trim($_GET['vendor'] ?? '');
$fStatus = $_GET['status'] ?? '';
$fDateFrom = $_GET['date_from'] ?? '';
$fDateTo = $_GET['date_to'] ?? '';

// ---- LIST ----
$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;
$totalPages = 1;

if ($action === 'list') {
    $categories = $pdo->query("SELECT MIN(id) as id, name_en, name_ar, status FROM expense_categories WHERE status='active' GROUP BY name_en ORDER BY name_en")->fetchAll();
    
    $baseWhere = " FROM expenses e LEFT JOIN expense_categories c ON c.id = e.category_id LEFT JOIN users u ON u.id = e.created_by WHERE 1=1";
    $params = [];
    if ($fCategory) { $baseWhere .= " AND e.category_id=?"; $params[] = $fCategory; }
    if ($fVendor)   { $baseWhere .= " AND e.vendor_name LIKE ?"; $params[] = "%$fVendor%"; }
    if ($fStatus)   { $baseWhere .= " AND e.status=?"; $params[] = $fStatus; }
    if ($fDateFrom) { $baseWhere .= " AND e.expense_date >= ?"; $params[] = $fDateFrom; }
    if ($fDateTo)   { $baseWhere .= " AND e.expense_date <= ?"; $params[] = $fDateTo; }
    
    $cStmt = $pdo->prepare("SELECT COUNT(*) " . $baseWhere); $cStmt->execute($params);
    $totalRows  = (int)$cStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    
    $sql = "SELECT e.*, c.name_en AS category_name, u.username AS created_by_name " . $baseWhere;
    $sql .= " ORDER BY e.expense_date DESC, e.id DESC LIMIT $perPage OFFSET $offset";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $expenses = $stmt->fetchAll();
}

// ---- CATEGORIES ----
if ($action === 'categories') {
    $categories = $pdo->query("SELECT MIN(id) as id, name_en, name_ar, description_en, description_ar, status FROM expense_categories GROUP BY name_en ORDER BY name_en")->fetchAll();
}

// ---- VIEW ----
if ($action === 'view') {
    $stmt = $pdo->prepare("SELECT e.*, c.name_en AS category_name, u.username AS created_by_name 
                           FROM expenses e 
                           LEFT JOIN expense_categories c ON c.id = e.category_id 
                           LEFT JOIN users u ON u.id = e.created_by 
                           WHERE e.id=?");
    $stmt->execute([$id]);
    $expense = $stmt->fetch();
    
    if ($expense) {
        $astmt = $pdo->prepare("SELECT * FROM expense_attachments WHERE expense_id=? ORDER BY uploaded_at DESC");
        $astmt->execute([$id]);
        $attachments = $astmt->fetchAll();
    }
}
?>

<?php if ($action === 'categories'): ?>
<!-- Categories Management -->
<div class="d-flex justify-content-between align-items-center mb-4">
  <h3><i class="fas fa-tags me-2"></i><?= t('expense_categories') ?></h3>
  <button class="btn btn-primary" onclick="openAddCategoryModal()">
    <i class="fas fa-plus me-1"></i><?= t('add_category') ?>
  </button>
</div>

<div class="card">
  <div class="card-header bg-info text-white">
    <i class="fas fa-list me-2"></i><?= t('categories') ?>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th><?= t('name_en') ?></th>
            <th><?= t('name_ar') ?></th>
            <th><?= t('description_en') ?></th>
            <th><?= t('status') ?></th>
            <th><?= t('actions') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($categories as $cat): ?>
          <tr>
            <td><strong><?= e($cat['name_en']) ?></strong></td>
            <td><?= e($cat['name_ar'] ?: '—') ?></td>
            <td><?= e($cat['description_en'] ?: '—') ?></td>
            <td><?= statusBadge($cat['status']) ?></td>
            <td>
              <a href="?module=expenses&action=delete_category&id=<?= $cat['id'] ?>" class="btn btn-xs btn-outline-danger" onclick="return confirm('<?= t('confirm_delete') ?>')">
                <i class="fas fa-trash"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="add_category">
        <div class="modal-header bg-info text-white">
          <h5 class="modal-title"><?= t('add_category') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label"><?= t('name_en') ?> *</label>
            <input name="name_en" type="text" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label"><?= t('name_ar') ?></label>
            <input name="name_ar" type="text" class="form-control">
          </div>
          <div class="mb-3">
            <label class="form-label"><?= t('description_en') ?></label>
            <textarea name="description_en" class="form-control" rows="2"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label"><?= t('description_ar') ?></label>
            <textarea name="description_ar" class="form-control" rows="2"></textarea>
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

<script>
function openAddCategoryModal(){
  new bootstrap.Modal(document.getElementById('addCategoryModal')).show();
}
</script>

<?php elseif ($action === 'view'): ?>
<!-- Expense Detail View -->
<div class="d-flex justify-content-between align-items-center mb-4">
  <h3><i class="fas fa-receipt me-2"></i><?= t('expense_details') ?> #<?= $id ?></h3>
  <a href="?module=expenses" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i><?= t('back') ?></a>
</div>

<div class="row g-4 mb-4">
  <div class="col-md-8">
    <div class="card">
      <div class="card-header bg-primary text-white"><?= t('expense_info') ?></div>
      <div class="card-body">
        <table class="table table-sm table-borderless">
          <tr><td class="text-muted"><?= t('category') ?></td><td><?= e($expense['category_name']) ?></td></tr>
          <tr><td class="text-muted"><?= t('expense_date') ?></td><td><?= fmtDate($expense['expense_date']) ?></td></tr>
          <tr><td class="text-muted"><?= t('amount') ?></td><td><strong>KWD <?= number_format($expense['amount'], 3) ?></strong></td></tr>
          <tr><td class="text-muted"><?= t('vendor') ?></td><td><?= e($expense['vendor_name'] ?: '—') ?></td></tr>
          <tr><td class="text-muted"><?= t('invoice_number') ?></td><td><?= e($expense['invoice_number'] ?: '—') ?></td></tr>
          <tr><td class="text-muted"><?= t('payment_method') ?></td><td><?= t($expense['payment_method']) ?></td></tr>
          <tr><td class="text-muted"><?= t('status') ?></td><td><?= statusBadge($expense['status']) ?></td></tr>
          <tr><td class="text-muted"><?= t('description') ?></td><td><?= e($expense['description_en'] ?: '—') ?></td></tr>
        </table>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card">
      <div class="card-header bg-success text-white"><?= t('actions') ?></div>
      <div class="card-body">
        <?php if ($expense['status'] === 'pending'): ?>
        <a href="?module=expenses&action=approve&id=<?= $id ?>" class="btn btn-success w-100 mb-2">
          <i class="fas fa-check me-1"></i><?= t('approve') ?>
        </a>
        <a href="?module=expenses&action=reject&id=<?= $id ?>" class="btn btn-danger w-100 mb-2">
          <i class="fas fa-times me-1"></i><?= t('reject') ?>
        </a>
        <?php endif; ?>
        <button class="btn btn-primary w-100 mb-2" onclick="openUploadModal()">
          <i class="fas fa-upload me-1"></i><?= t('upload_attachment') ?>
        </button>
        <a href="?module=expenses&action=edit&id=<?= $id ?>" class="btn btn-warning w-100 mb-2">
          <i class="fas fa-edit me-1"></i><?= t('edit') ?>
        </a>
      </div>
    </div>
  </div>
</div>

<!-- Attachments -->
<div class="card mb-4">
  <div class="card-header bg-info text-white">
    <i class="fas fa-paperclip me-2"></i><?= t('attachments') ?>
  </div>
  <div class="card-body">
    <?php if ($attachments): ?>
    <div class="row g-3">
      <?php foreach ($attachments as $att): ?>
      <div class="col-md-4">
        <div class="card">
          <div class="card-body">
            <i class="fas fa-file fa-2x text-muted mb-2"></i>
            <p class="mb-2 text-truncate"><?= e($att['file_name']) ?></p>
            <a href="<?= $att['file_path'] ?>" target="_blank" class="btn btn-sm btn-outline-primary me-1">
              <i class="fas fa-eye"></i>
            </a>
            <a href="?module=expenses&action=delete_attachment&id=<?= $id ?>&attach_id=<?= $att['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('<?= t('confirm_delete') ?>')">
              <i class="fas fa-trash"></i>
            </a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="text-muted mb-0"><?= t('no_attachments') ?></p>
    <?php endif; ?>
  </div>
</div>

<!-- Upload Modal -->
<div class="modal fade" id="uploadModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="upload_attachment">
        <div class="modal-header bg-info text-white">
          <h5 class="modal-title"><?= t('upload_attachment') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label"><?= t('file') ?> *</label>
            <input name="attachment" type="file" class="form-control" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('cancel') ?></button>
          <button type="submit" class="btn btn-primary"><?= t('upload') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openUploadModal(){
  new bootstrap.Modal(document.getElementById('uploadModal')).show();
}
</script>

<?php else: ?>
<!-- Expense List View -->
<div class="d-flex justify-content-between align-items-center mb-4">
  <h3><i class="fas fa-receipt me-2"></i><?= t('expenses') ?></h3>
  <div>
    <a href="?module=expenses&action=categories" class="btn btn-info me-2">
      <i class="fas fa-tags me-1"></i><?= t('categories') ?>
    </a>
    <button class="btn btn-primary" onclick="openAddModal()">
      <i class="fas fa-plus me-1"></i><?= t('add_expense') ?>
    </button>
  </div>
</div>

<!-- Filters -->
<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-3">
      <input type="hidden" name="module" value="expenses">
      <div class="col-md-2">
        <label class="form-label"><?= t('category') ?></label>
        <select name="category_id" class="form-select">
          <option value=""><?= t('all') ?></option>
          <?php foreach ($categories as $cat): ?>
          <option value="<?= $cat['id'] ?>" <?= $fCategory === $cat['id'] ? 'selected' : '' ?>><?= e($cat['name_en']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label"><?= t('vendor') ?></label>
        <input name="vendor" type="text" class="form-control" value="<?= e($fVendor) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label"><?= t('status') ?></label>
        <select name="status" class="form-select">
          <option value=""><?= t('all') ?></option>
          <?php foreach ($statuses as $st): ?>
          <option value="<?= $st ?>" <?= $fStatus === $st ? 'selected' : '' ?>><?= t($st) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label"><?= t('date_from') ?></label>
        <input name="date_from" type="date" class="form-control" value="<?= e($fDateFrom) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label"><?= t('date_to') ?></label>
        <input name="date_to" type="date" class="form-control" value="<?= e($fDateTo) ?>">
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button type="submit" class="btn btn-primary me-2"><?= t('filter') ?></button>
        <a href="?module=expenses" class="btn btn-secondary"><?= t('reset') ?></a>
      </div>
    </form>
  </div>
</div>

<!-- Expenses Table -->
<div class="card">
  <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
    <span><i class="fas fa-list me-2"></i><?= t('expense_list') ?></span>
    <button class="btn btn-sm btn-light" onclick="exportToExcel()">
      <i class="fas fa-file-excel me-1"></i><?= t('export_excel') ?>
    </button>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="expensesTable">
        <thead>
          <tr>
            <th><?= t('date') ?></th>
            <th><?= t('category') ?></th>
            <th><?= t('vendor') ?></th>
            <th><?= t('amount') ?></th>
            <th><?= t('payment_method') ?></th>
            <th><?= t('status') ?></th>
            <th><?= t('actions') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($expenses as $e): ?>
          <tr>
            <td><?= fmtDate($e['expense_date']) ?></td>
            <td><?= e($e['category_name']) ?></td>
            <td><?= e($e['vendor_name'] ?: '—') ?></td>
            <td><strong>KWD <?= number_format($e['amount'], 3) ?></strong></td>
            <td><?= t($e['payment_method']) ?></td>
            <td><?= statusBadge($e['status']) ?></td>
            <td>
              <a href="?module=expenses&action=view&id=<?= $e['id'] ?>" class="btn btn-xs btn-outline-info" title="<?= t('view') ?>">
                <i class="fas fa-eye"></i>
              </a>
              <?php if ($e['status'] === 'pending'): ?>
              <a href="?module=expenses&action=approve&id=<?= $e['id'] ?>" class="btn btn-xs btn-outline-success" title="<?= t('approve') ?>">
                <i class="fas fa-check"></i>
              </a>
              <?php endif; ?>
              <button class="btn btn-xs btn-outline-primary" onclick="openEditModal(<?= htmlspecialchars(json_encode($e), ENT_QUOTES) ?>)">
                <i class="fas fa-edit"></i>
              </button>
              <button class="btn btn-xs btn-outline-danger" onclick="confirmDelete(<?= $e['id'] ?>)">
                <i class="fas fa-trash"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$expenses): ?>
          <tr><td colspan="7" class="text-center text-muted py-3"><?= t('no_records') ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="expenseModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" id="eAction" value="add">
        <input type="hidden" name="id" id="eId" value="">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title" id="modalTitle"><?= t('add_expense') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label"><?= t('category') ?> *</label>
              <select name="category_id" id="eCategory" class="form-select" required>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>"><?= e($cat['name_en']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('expense_date') ?> *</label>
              <input name="expense_date" id="eDate" type="date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label"><?= t('amount') ?> *</label>
              <input name="amount" id="eAmount" type="number" step="0.001" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label"><?= t('currency') ?></label>
              <select name="currency" id="eCurrency" class="form-select">
                <option value="KWD">KWD</option>
                <option value="USD">USD</option>
                <option value="EUR">EUR</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label"><?= t('payment_method') ?></label>
              <select name="payment_method" id="ePayment" class="form-select">
                <?php foreach ($paymentMethods as $pm): ?>
                <option value="<?= $pm ?>"><?= t($pm) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('vendor_name') ?></label>
              <input name="vendor_name" id="eVendor" type="text" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('vendor_contact') ?></label>
              <input name="vendor_contact" id="eVendorContact" type="text" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('invoice_number') ?></label>
              <input name="invoice_number" id="eInvoice" type="text" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">&nbsp;</label>
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('description_en') ?></label>
              <textarea name="description_en" id="eDescEn" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('description_ar') ?></label>
              <textarea name="description_ar" id="eDescAr" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label"><?= t('notes') ?></label>
              <textarea name="notes" id="eNotes" class="form-control" rows="2"></textarea>
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

<?php if ($totalPages > 1): ?>
<nav class="mt-3">
  <ul class="pagination pagination-sm justify-content-center">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
      <a class="page-link" href="?module=expenses&page=<?= $p ?>&category_id=<?= $fCategory ?>&vendor=<?= e($fVendor) ?>&status=<?= e($fStatus) ?>&date_from=<?= e($fDateFrom) ?>&date_to=<?= e($fDateTo) ?>"><?= $p ?></a>
    </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>

<form id="deleteForm" method="POST">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="deleteId">
</form>

<script>
function openAddModal(){
  document.getElementById('eAction').value='add';
  document.getElementById('eId').value='';
  document.getElementById('modalTitle').textContent='<?= t('add_expense') ?>';
  document.getElementById('eCategory').value='';
  document.getElementById('eDate').value='<?= date('Y-m-d') ?>';
  document.getElementById('eAmount').value='';
  document.getElementById('eCurrency').value='KWD';
  document.getElementById('ePayment').value='cash';
  document.getElementById('eVendor').value='';
  document.getElementById('eVendorContact').value='';
  document.getElementById('eInvoice').value='';
  document.getElementById('eDescEn').value='';
  document.getElementById('eDescAr').value='';
  document.getElementById('eNotes').value='';
  new bootstrap.Modal(document.getElementById('expenseModal')).show();
}
function openEditModal(e){
  document.getElementById('eAction').value='edit';
  document.getElementById('eId').value=e.id;
  document.getElementById('modalTitle').textContent='<?= t('edit_expense') ?>';
  document.getElementById('eCategory').value=e.category_id;
  document.getElementById('eDate').value=e.expense_date;
  document.getElementById('eAmount').value=e.amount;
  document.getElementById('eCurrency').value=e.currency;
  document.getElementById('ePayment').value=e.payment_method;
  document.getElementById('eVendor').value=e.vendor_name||'';
  document.getElementById('eVendorContact').value=e.vendor_contact||'';
  document.getElementById('eInvoice').value=e.invoice_number||'';
  document.getElementById('eDescEn').value=e.description_en||'';
  document.getElementById('eDescAr').value=e.description_ar||'';
  document.getElementById('eNotes').value=e.notes||'';
  new bootstrap.Modal(document.getElementById('expenseModal')).show();
}
function confirmDelete(id){
  if(confirm('<?= t('confirm_delete') ?>')){
    document.getElementById('deleteId').value=id;
    document.getElementById('deleteForm').submit();
  }
}
function exportToExcel(){
  const table=document.getElementById('expensesTable');
  const wb=XLSX.utils.table_to_book(table,{sheet:'Expenses'});
  XLSX.writeFile(wb,'expenses.xlsx');
}
</script>
<?php endif; ?>
