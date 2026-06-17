<?php
// modules/users.php — User Management (Admin only)

$pdo    = getDB();
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$lang   = $_SESSION['lang'] ?? 'ar';
$isRTL  = $lang === 'ar';

// Only admins can access
if ($_SESSION['user_role'] !== 'admin') {
    echo '<div class="alert alert-danger"><i class="fas fa-lock me-2"></i>'.
         ($isRTL ? 'ما عندك صلاحية للوصول لهذه الصفحة.' : 'Access denied. Admins only.').'</div>';
    return;
}

// ---- Handle POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;

    if ($action === 'add') {
        $username = strtolower(trim($d['username'] ?? ''));
        $password = trim($d['password'] ?? '');
        // DEBUG: Log employee_id value
        error_log("DEBUG: employee_id = " . var_export($d['employee_id'] ?? 'NOT SET', true));
        if (!$username || !$password || !trim($d['full_name_en'] ?? '')) {
            setFlash('danger', t('required_fields'));
        } elseif (strlen($password) < 6) {
            setFlash('danger', $isRTL ? 'كلمة المرور يجب أن تكون 6 أحرف على الأقل.' : 'Password must be at least 6 characters.');
        } else {
            try {
                $pdo->prepare("INSERT INTO users (username,full_name_en,full_name_ar,email,password_hash,role,status,employee_id)
                               VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$username, trim($d['full_name_en']), trim($d['full_name_ar'] ?? ''),
                               trim($d['email'] ?? ''), password_hash($password, PASSWORD_BCRYPT),
                               $d['role'] ?? 'viewer', $d['status'] ?? 'active', $d['employee_id'] ?: null]);
                setFlash('success', t('record_saved'));
            } catch (PDOException $e) {
                setFlash('danger', $isRTL ? 'اسم المستخدم أو الإيميل مكرر.' : 'Username or email already exists.');
            }
        }
        safeRedirect('?module=users');
    }

    if ($action === 'edit') {
        $fields = [
            'full_name_en' => trim($d['full_name_en'] ?? ''),
            'full_name_ar' => trim($d['full_name_ar'] ?? ''),
            'email'        => trim($d['email'] ?? ''),
            'role'         => $d['role'] ?? 'viewer',
            'status'       => $d['status'] ?? 'active',
            'employee_id'  => $d['employee_id'] ?: null,
            'updated_at'   => date('Y-m-d H:i:s'),
        ];
        // Only update password if provided
        if (!empty(trim($d['password'] ?? ''))) {
            if (strlen(trim($d['password'])) < 6) {
                setFlash('danger', $isRTL ? 'كلمة المرور يجب أن تكون 6 أحرف على الأقل.' : 'Password must be at least 6 characters.');
                safeRedirect('?module=users');
            }
            $fields['password_hash'] = password_hash(trim($d['password']), PASSWORD_BCRYPT);
        }
        $set = implode('=?,', array_keys($fields)).'=?';
        $pdo->prepare("UPDATE users SET $set WHERE id=?")->execute([...array_values($fields), $id]);
        setFlash('success', t('record_updated'));
        safeRedirect('?module=users');
    }

    if ($action === 'delete') {
        if ($id == $_SESSION['user_id']) {
            setFlash('danger', $isRTL ? 'ما تقدر تحذف حسابك الحالي.' : 'You cannot delete your own account.');
        } else {
            $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
            setFlash('success', t('record_deleted'));
        }
        safeRedirect('?module=users');
    }

    if ($action === 'change_own_password') {
        $current  = $_POST['current_password'] ?? '';
        $newPwd   = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';
        $user = $pdo->prepare("SELECT password_hash FROM users WHERE id=?");
        $user->execute([$_SESSION['user_id']]); $user = $user->fetch();
        if (!password_verify($current, $user['password_hash'])) {
            setFlash('danger', $isRTL ? 'كلمة المرور الحالية غلط.' : 'Current password is incorrect.');
        } elseif ($newPwd !== $confirm) {
            setFlash('danger', $isRTL ? 'كلمة المرور الجديدة ما تطابقت.' : 'New passwords do not match.');
        } elseif (strlen($newPwd) < 6) {
            setFlash('danger', $isRTL ? 'كلمة المرور يجب أن تكون 6 أحرف على الأقل.' : 'Password must be at least 6 characters.');
        } else {
            $pdo->prepare("UPDATE users SET password_hash=?, updated_at=NOW() WHERE id=?")
                ->execute([password_hash($newPwd, PASSWORD_BCRYPT), $_SESSION['user_id']]);
            setFlash('success', $isRTL ? 'تم تغيير كلمة المرور بنجاح.' : 'Password changed successfully.');
        }
        safeRedirect('?module=users');
    }
}

// ---- Load users ----
$users = $pdo->query("SELECT * FROM users ORDER BY role ASC, full_name_en ASC")->fetchAll();

$roleColors = ['admin'=>'danger','manager'=>'warning','viewer'=>'info','driver'=>'success'];
$roleLabels = ['admin'=> ($isRTL?'مدير النظام':'Admin'),
               'manager'=> ($isRTL?'مدير':'Manager'),
               'viewer'=> ($isRTL?'مشاهدة فقط':'Viewer'),
               'driver'=> ($isRTL?'سائق':'Driver')];
?>

<div class="row g-4">
<div class="col-lg-8">

<!-- Users Table -->
<div class="card mb-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span class="fw-bold"><i class="fas fa-users-cog me-2 text-warning"></i>
      <?= $isRTL ? 'المستخدمون' : 'System Users' ?>
    </span>
    <button class="btn btn-sm btn-success" onclick="new bootstrap.Modal(document.getElementById('addUserModal')).show()">
      <i class="fas fa-plus me-1"></i><?= $isRTL ? 'إضافة مستخدم' : 'Add User' ?>
    </button>
  </div>
  <div class="card-body p-0">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-dark">
        <tr>
          <th>#</th>
          <th><?= $isRTL ? 'اسم المستخدم' : 'Username' ?></th>
          <th><?= $isRTL ? 'الاسم الكامل' : 'Full Name' ?></th>
          <th><?= $isRTL ? 'الإيميل' : 'Email' ?></th>
          <th><?= $isRTL ? 'الدور' : 'Role' ?></th>
          <th><?= $isRTL ? 'آخر دخول' : 'Last Login' ?></th>
          <th><?= t('status') ?></th>
          <th><?= t('actions') ?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u): ?>
      <tr class="<?= $u['id'] == $_SESSION['user_id'] ? 'table-warning bg-opacity-25' : '' ?>">
        <td><?= $u['id'] ?></td>
        <td>
          <strong><?= e($u['username']) ?></strong>
          <?php if ($u['id'] == $_SESSION['user_id']): ?>
            <span class="badge bg-warning text-dark ms-1" style="font-size:9px"><?= $isRTL?'أنت':'YOU' ?></span>
          <?php endif; ?>
        </td>
        <td>
          <?= e($u['full_name_en']) ?>
          <?php if ($u['full_name_ar']): ?><br><small class="text-muted" dir="rtl"><?= e($u['full_name_ar']) ?></small><?php endif; ?>
        </td>
        <td><small><?= e($u['email']?:'—') ?></small></td>
        <td><span class="badge bg-<?= $roleColors[$u['role']] ?? 'secondary' ?>"><?= $roleLabels[$u['role']] ?? $u['role'] ?></span></td>
        <td><small class="text-muted"><?= $u['last_login'] ? fmtDate(substr($u['last_login'],0,10)) : '—' ?></small></td>
        <td><?= statusBadge($u['status']) ?></td>
        <td>
          <button class="btn btn-xs btn-outline-primary"
                  onclick="openEditModal(<?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>)">
            <i class="fas fa-edit"></i>
          </button>
          <?php if ($u['id'] != $_SESSION['user_id']): ?>
          <button class="btn btn-xs btn-outline-danger" onclick="confirmDeleteUser(<?= $u['id'] ?>, '<?= e($u['username']) ?>')"><i class="fas fa-trash"></i></button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer text-muted small"><?= count($users) ?> <?= $isRTL?'مستخدم':'users' ?></div>
</div>

</div><!-- col -->

<!-- Right col: roles guide + change own password -->
<div class="col-lg-4">

  <!-- Roles explanation -->
  <div class="card mb-3">
    <div class="card-header fw-bold"><i class="fas fa-info-circle me-2 text-info"></i><?= $isRTL?'الأدوار والصلاحيات':'Roles & Permissions' ?></div>
    <div class="card-body">
      <div class="mb-3">
        <span class="badge bg-danger mb-1">Admin <?= $isRTL?'مدير النظام':'' ?></span>
        <p class="small text-muted mb-0"><?= $isRTL ? 'وصول كامل — إضافة وتعديل وحذف وإدارة المستخدمين' : 'Full access — add, edit, delete all records + manage users' ?></p>
      </div>
      <div class="mb-3">
        <span class="badge bg-warning text-dark mb-1">Manager <?= $isRTL?'مدير':'' ?></span>
        <p class="small text-muted mb-0"><?= $isRTL ? 'إضافة وتعديل جميع السجلات بدون حذف أو إدارة مستخدمين' : 'Add & edit all records — no delete, no user management' ?></p>
      </div>
      <div>
        <span class="badge bg-info text-dark mb-1">Viewer <?= $isRTL?'مشاهدة فقط':'' ?></span>
        <p class="small text-muted mb-0"><?= $isRTL ? 'مشاهدة السجلات فقط، بدون أي تعديل' : 'View records only — no add, edit or delete' ?></p>
      </div>
    </div>
  </div>

  <!-- Change own password -->
  <div class="card">
    <div class="card-header fw-bold"><i class="fas fa-key me-2 text-warning"></i><?= $isRTL?'تغيير كلمة المرور':'Change My Password' ?></div>
    <div class="card-body">
      <form method="post" action="?module=users&action=change_own_password">
        <input type="hidden" name="action" value="change_own_password">
        <div class="mb-2">
          <label class="form-label small"><?= $isRTL?'كلمة المرور الحالية':'Current Password' ?></label>
          <input type="password" name="current_password" class="form-control form-control-sm" required>
        </div>
        <div class="mb-2">
          <label class="form-label small"><?= $isRTL?'كلمة المرور الجديدة':'New Password' ?></label>
          <input type="password" name="new_password" class="form-control form-control-sm" required minlength="6">
        </div>
        <div class="mb-3">
          <label class="form-label small"><?= $isRTL?'تأكيد كلمة المرور':'Confirm New Password' ?></label>
          <input type="password" name="confirm_password" class="form-control form-control-sm" required minlength="6">
        </div>
        <button type="submit" class="btn btn-sm btn-warning text-dark w-100">
          <i class="fas fa-save me-1"></i><?= $isRTL?'حفظ كلمة المرور':'Save Password' ?>
        </button>
      </form>
    </div>
  </div>

</div><!-- col -->
</div><!-- row -->

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="?module=users&action=add">
        <input type="hidden" name="action" value="add">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i><?= $isRTL?'إضافة مستخدم':'Add User' ?></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label"><?= $isRTL?'اسم المستخدم':'Username' ?> *</label>
              <input name="username" class="form-control" required placeholder="e.g. ahmed.ali" autocomplete="off">
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= $isRTL?'كلمة المرور':'Password' ?> *</label>
              <input name="password" type="password" class="form-control" required minlength="6" autocomplete="new-password">
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= $isRTL?'الاسم بالإنجليزي':'Full Name (EN)' ?> *</label>
              <input name="full_name_en" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= $isRTL?'الاسم بالعربي':'Full Name (AR)' ?></label>
              <input name="full_name_ar" class="form-control" dir="rtl">
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= $isRTL?'الإيميل':'Email' ?></label>
              <input name="email" type="email" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label"><?= $isRTL?'الدور':'Role' ?></label>
              <select name="role" class="form-select">
                <option value="viewer"><?= $roleLabels['viewer'] ?></option>
                <option value="driver"><?= $roleLabels['driver'] ?></option>
                <option value="manager"><?= $roleLabels['manager'] ?></option>
                <option value="admin"><?= $roleLabels['admin'] ?></option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label"><?= $isRTL?'الموظف':'Employee' ?></label>
              <select name="employee_id" class="form-select">
                <option value=""><?= $isRTL?'بدون ربط':'Not linked' ?></option>
                <?= employeeOptions() ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label"><?= t('status') ?></label>
              <select name="status" class="form-select">
                <option value="active"><?= t('active') ?></option>
                <option value="inactive"><?= t('inactive') ?></option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('cancel') ?></button>
          <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i><?= t('save') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" id="editUserForm">
        <input type="hidden" name="action" value="edit">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title"><i class="fas fa-user-edit me-2"></i><?= $isRTL?'تعديل مستخدم':'Edit User' ?></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label text-muted small"><?= $isRTL?'اسم المستخدم (لا يمكن تغييره)':'Username (cannot change)' ?></label>
              <input id="eUsername" class="form-control bg-light" readonly>
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= $isRTL?'الاسم بالإنجليزي':'Full Name (EN)' ?> *</label>
              <input name="full_name_en" id="eNameEn" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= $isRTL?'الاسم بالعربي':'Full Name (AR)' ?></label>
              <input name="full_name_ar" id="eNameAr" class="form-control" dir="rtl">
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= $isRTL?'الإيميل':'Email' ?></label>
              <input name="email" id="eEmail" type="email" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= $isRTL?'كلمة مرور جديدة':'New Password' ?> <small class="text-muted">(<?= $isRTL?'اتركه فارغ إذا ما تبي تغيير':'leave blank to keep current' ?>)</small></label>
              <input name="password" type="password" class="form-control" minlength="6" autocomplete="new-password">
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= $isRTL?'الدور':'Role' ?></label>
              <select name="role" id="eRole" class="form-select">
                <option value="viewer"><?= $roleLabels['viewer'] ?></option>
                <option value="driver"><?= $roleLabels['driver'] ?></option>
                <option value="manager"><?= $roleLabels['manager'] ?></option>
                <option value="admin"><?= $roleLabels['admin'] ?></option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= $isRTL?'الموظف':'Employee' ?></label>
              <select name="employee_id" id="eEmployee" class="form-select">
                <option value=""><?= $isRTL?'بدون ربط':'Not linked' ?></option>
                <?= employeeOptions() ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= t('status') ?></label>
              <select name="status" id="eStatus" class="form-select">
                <option value="active"><?= t('active') ?></option>
                <option value="inactive"><?= t('inactive') ?></option>
                <option value="suspended"><?= t('suspended') ?></option>
              </select>
            </div>
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

<!-- Delete Confirm Modal -->
<div class="modal fade" id="deleteUserModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white py-2">
        <h6 class="modal-title"><i class="fas fa-trash me-2"></i><?= $isRTL?'حذف مستخدم':'Delete User' ?></h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center py-3">
        <i class="fas fa-exclamation-triangle fa-2x text-danger mb-2 d-block"></i>
        <p class="mb-1"><?= $isRTL?'تبي تحذف المستخدم':'Delete user' ?>:</p>
        <strong id="delUserName" class="text-danger"></strong>
        <p class="text-muted small mt-2 mb-0"><?= $isRTL?'هذا الإجراء لا يمكن التراجع عنه.':'This action cannot be undone.' ?></p>
      </div>
      <div class="modal-footer py-2 justify-content-center gap-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= t('cancel') ?></button>
        <button type="button" class="btn btn-danger btn-sm" id="delUserConfirmBtn"><i class="fas fa-trash me-1"></i><?= $isRTL?'حذف':'Delete' ?></button>
      </div>
    </div>
  </div>
</div>
<form id="deleteUserForm" method="post">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="deleteUserId">
</form>

<script>
function confirmDeleteUser(id, username){
  document.getElementById('delUserName').textContent = username;
  document.getElementById('deleteUserId').value = id;
  document.getElementById('deleteUserForm').action = '?module=users&action=delete&id=' + id;
  new bootstrap.Modal(document.getElementById('deleteUserModal')).show();
}
document.getElementById('delUserConfirmBtn').addEventListener('click', function(){
  document.getElementById('deleteUserForm').submit();
});
function openEditModal(u){
  document.getElementById('eUsername').value = u.username;
  document.getElementById('eNameEn').value   = u.full_name_en ?? '';
  document.getElementById('eNameAr').value   = u.full_name_ar ?? '';
  document.getElementById('eEmail').value    = u.email ?? '';
  document.getElementById('eRole').value     = u.role;
  document.getElementById('eEmployee').value = u.employee_id ?? '';
  document.getElementById('eStatus').value   = u.status;
  document.getElementById('editUserForm').action = '?module=users&action=edit&id=' + u.id;
  new bootstrap.Modal(document.getElementById('editUserModal')).show();
}
</script>
