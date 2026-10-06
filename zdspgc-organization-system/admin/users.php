<?php
/**
 * admin/users.php — full user account management.
 *
 * Admins can view, create, edit, change roles, activate/deactivate/suspend
 * and reset passwords for all user accounts. Never exposes plaintext passwords.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Permissions::requireCapability('manage_users');

/* ── POST actions ── */
if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) (Helpers::post('action') ?? '');
    $id     = (int)    (Helpers::post('id')     ?? 0);

    try {
        switch ($action) {

            case 'create':
                $newId = UserRepo::create([
                    'username'  => (string) (Helpers::post('username')  ?? ''),
                    'email'     => (string) (Helpers::post('email')     ?? ''),
                    'full_name' => (string) (Helpers::post('full_name') ?? ''),
                    'role'      => (string) (Helpers::post('role')      ?? 'student'),
                    'phone'     => (string) (Helpers::post('phone')     ?? ''),
                    'status'    => 'active',
                    'password'  => (string) (Helpers::post('password')  ?? ''),
                ]);
                // If role is adviser, create the adviser profile row too
                if (Helpers::post('role') === 'adviser') {
                    Database::insert('advisers', [
                        'user_id'    => $newId,
                        'status'     => 'active',
                        'created_at' => Helpers::now(),
                    ]);
                }
                Helpers::flash('success', 'Account was created successfully.');
                break;

            case 'edit':
                if ($id < 1) {
                    throw new RuntimeException('No user selected.');
                }
                $password = (string) (Helpers::post('password') ?? '');
                UserRepo::updateUser($id, [
                    'full_name' => (string) (Helpers::post('full_name') ?? ''),
                    'email'     => (string) (Helpers::post('email')     ?? ''),
                    'phone'     => (string) (Helpers::post('phone')     ?? ''),
                    'role'      => (string) (Helpers::post('role')      ?? ''),
                    'status'    => (string) (Helpers::post('status')    ?? ''),
                ], $password !== '' ? $password : null);
                Helpers::flash('success', 'Account was updated.');
                break;

            case 'activate':
            case 'deactivate':
            case 'suspend':
                if ($id < 1) {
                    throw new RuntimeException('No user selected.');
                }
                $newStatus = $action === 'activate' ? 'active' : ($action === 'suspend' ? 'suspended' : 'inactive');
                UserRepo::setStatus($id, $newStatus);
                Helpers::flash('success', 'Account status changed to ' . $newStatus . '.');
                break;

            case 'reset_password':
                if ($id < 1) {
                    throw new RuntimeException('No user selected.');
                }
                $newPw = (string) (Helpers::post('new_password') ?? '');
                if ($newPw === '') {
                    throw new RuntimeException('New password cannot be empty.');
                }
                UserRepo::updateUser($id, [], $newPw);
                Helpers::flash('success', 'Password was reset successfully.');
                break;
        }
    } catch (Throwable $e) {
        Helpers::flash('error', $e->getMessage());
    }

    Helpers::redirect('admin/users.php');
}

/* ── Read ── */
$q      = (string) (Helpers::get('q')      ?? '');
$role   = (string) (Helpers::get('role')   ?? '');
$status = (string) (Helpers::get('status') ?? '');
$editId = (int)    (Helpers::get('id')     ?? 0);
$page   = max(1, (int) (Helpers::get('page') ?? 1));

$filters = [];
if ($q      !== '') $filters['q']      = $q;
if ($role   !== '') $filters['role']   = $role;
if ($status !== '') $filters['status'] = $status;

$result  = UserRepo::list($filters, $page, 25);
$users   = $result['rows'];
$pages   = (int) $result['pages'];
$total   = (int) $result['total'];
$editing = $editId > 0 ? UserRepo::find($editId) : null;

$roleOptions   = ['admin' => 'Administrator', 'adviser' => 'Adviser', 'officer' => 'Officer', 'student' => 'Student'];
$statusOptions = ['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended'];

$queryStr = http_build_query(array_filter(['q' => $q, 'role' => $role, 'status' => $status]));

$PAGE_TITLE       = 'User Management';
$PAGE_ACTIVE      = 'users';
$PAGE_SUB         = 'All ' . $total . ' account(s) in the system — administrators, advisers, officers and students.';
$PAGE_ACTIONS     = '<a class="btn" href="#user-form">' . icon('plus', 16) . '<span>Add user</span></a>';
$PAGE_BREADCRUMBS = [
    ['label' => 'Dashboard',   'href' => 'admin/dashboard.php'],
    ['label' => 'Configuration'],
    ['label' => 'Users'],
];
require __DIR__ . '/../includes/layout/header.php';
?>

<?= ui_filter_form('admin/users.php',
    ui_filter_input('q', 'Search', $q) .
    ui_filter_select('role',   'Role',   $roleOptions,   $role,   'All roles') .
    ui_filter_select('status', 'Status', $statusOptions, $status, 'All statuses')
) ?>

<div class="grid cols-2">
  <!-- ── User list ── -->
  <section class="card" style="grid-column: 1 / -1;">
    <div class="card-head">
      <div>
        <h3>User accounts</h3>
        <p class="sub muted small"><?= $total ?> total · page <?= $page ?> of <?= $pages ?></p>
      </div>
      <a class="btn sm" href="#user-form"><?= icon('plus', 14) ?><span>Add user</span></a>
    </div>

    <?php if ($users === []): ?>
      <?= ui_empty('No accounts found.', 'Try adjusting the filters above.', 'shield') ?>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tbl">
          <thead>
            <tr>
              <th>#</th>
              <th>Name / Username</th>
              <th>E-mail</th>
              <th>Role</th>
              <th>Status</th>
              <th class="nowrap">Created</th>
              <th class="nowrap">Last login</th>
              <th class="actions">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $user): ?>
              <tr>
                <td class="small muted"><?= (int) $user['id'] ?></td>
                <td>
                  <strong><?= Helpers::e((string) $user['full_name']) ?></strong>
                  <br><span class="muted small">@<?= Helpers::e((string) $user['username']) ?></span>
                </td>
                <td class="small"><?= Helpers::e((string) $user['email']) ?></td>
                <td><?= ui_badge($roleOptions[(string)$user['role']] ?? ucfirst((string)$user['role']), 'blue') ?></td>
                <td><?= ui_status_badge((string) $user['status']) ?></td>
                <td class="small nowrap"><?= Helpers::e(Helpers::fmtDate((string) $user['created_at'])) ?></td>
                <td class="small nowrap">
                  <?= $user['last_login_at'] ? Helpers::e(Helpers::fmtDate((string) $user['last_login_at'])) : '<span class="muted">—</span>' ?>
                </td>
                <td class="actions">
                  <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('admin/users.php?id=' . (int) $user['id'])) ?>#user-form">
                    <?= icon('edit', 14) ?><span>Edit</span>
                  </a>

                  <?php if ((string) $user['status'] !== 'active'): ?>
                    <form method="post" class="inline-form">
                      <?= Security::csrfField() ?>
                      <input type="hidden" name="action" value="activate">
                      <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                      <button class="btn sm" type="submit"><?= icon('check', 14) ?><span>Activate</span></button>
                    </form>
                  <?php elseif ((int) $user['id'] !== (int) Auth::id()): ?>
                    <form method="post" class="inline-form">
                      <?= Security::csrfField() ?>
                      <input type="hidden" name="action" value="deactivate">
                      <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                      <button class="btn sm grey" type="submit"><?= icon('close', 14) ?><span>Deactivate</span></button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?= ui_pagination($page, $pages, $queryStr) ?>
    <?php endif; ?>
  </section>

  <!-- ── Add / Edit form ── -->
  <section class="card" id="user-form">
    <div class="card-head">
      <h3><?= $editing !== null
            ? 'Edit: ' . Helpers::e((string) $editing['full_name'])
            : 'Create a user account' ?></h3>
      <?php if ($editing !== null): ?><?= ui_status_badge((string) $editing['status']) ?><?php endif; ?>
    </div>

    <?php if ($editing !== null): ?>
      <!-- Edit form -->
      <form method="post">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id"     value="<?= (int) $editing['id'] ?>">

        <?= ui_input('full_name', 'Full name', (string) $editing['full_name'], [], true) ?>
        <?= ui_input('email', 'E-mail address', (string) $editing['email'], ['type' => 'email'], true) ?>
        <?= ui_input('phone', 'Phone', (string) $editing['phone'], ['type' => 'tel']) ?>
        <?= ui_select('role', 'Role', $roleOptions, (string) $editing['role'], [], true) ?>
        <?= ui_select('status', 'Account status', $statusOptions, (string) $editing['status'], [], true) ?>
        <p class="hint">Leave password blank to keep the current one.</p>
        <?= ui_input('password', 'New password', '', ['type' => 'password', 'hint' => 'Minimum 8 characters, letter + number.']) ?>

        <div class="form-actions">
          <button class="btn" type="submit"><?= icon('check', 16) ?><span>Save changes</span></button>
          <a class="btn grey" href="<?= Helpers::e(Helpers::url('admin/users.php')) ?>">
            <?= icon('close', 16) ?><span>Cancel</span>
          </a>
        </div>
      </form>
    <?php else: ?>
      <!-- Create form -->
      <form method="post">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="create">

        <?= ui_input('full_name', 'Full name', '', [], true) ?>
        <?= ui_input('username',  'Username',  '', ['placeholder' => 'e.g. juan.delosreyes'], true) ?>
        <?= ui_input('email',     'E-mail address', '', ['type' => 'email'], true) ?>
        <?= ui_input('phone',     'Phone', '', ['type' => 'tel']) ?>
        <?= ui_select('role', 'Role', $roleOptions, 'student', [], true) ?>
        <?= ui_input('password', 'Password', '', [
            'type' => 'password',
            'hint' => 'Minimum 8 characters with a letter and a number.',
        ], true) ?>

        <div class="form-actions">
          <button class="btn" type="submit"><?= icon('plus', 16) ?><span>Create account</span></button>
        </div>
      </form>
    <?php endif; ?>
  </section>
</div>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
