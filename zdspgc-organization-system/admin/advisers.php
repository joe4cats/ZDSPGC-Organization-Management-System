<?php
/**
 * admin/advisers.php — organization adviser roster.
 *
 * Filters (q, status, department) over UserRepo::advisers(), an inline card to
 * create and edit advisers (UserRepo::createAdviser / updateAdviser + updateUser)
 * and account status actions through UserRepo::setStatus.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Permissions::requireCapability('manage_advisers');

/* ---- POST actions (before any output) ---- */
if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) (Helpers::post('action') ?? '');

    try {
        if ($action === 'create') {
            $fullName = (string) (Helpers::post('full_name') ?? '');
            if ($fullName === '') {
                throw new RuntimeException('The adviser needs a full name.');
            }
            UserRepo::createAdviser([
                'full_name'     => $fullName,
                'username'      => (string) (Helpers::post('username') ?? ''),
                'email'         => (string) (Helpers::post('email') ?? ''),
                'password'      => (string) (Helpers::post('password') ?? ''),
                'phone'         => (string) (Helpers::post('phone') ?? ''),
                'employee_no'   => (string) (Helpers::post('employee_no') ?? ''),
                'department_id' => (string) (Helpers::post('department_id') ?? ''),
                'specialization'=> (string) (Helpers::post('specialization') ?? ''),
            ]);
            Helpers::flash('success', 'Adviser account created.');
        } elseif ($action === 'edit') {
            $adviserId = Helpers::postInt('adviser_id');
            $userId    = Helpers::postInt('user_id');
            if ($adviserId < 1 || UserRepo::findAdviser($adviserId) === null) {
                throw new RuntimeException('That adviser record no longer exists.');
            }
            if ($userId < 1 || UserRepo::find($userId) === null) {
                throw new RuntimeException('That account no longer exists.');
            }
            UserRepo::updateAdviser($adviserId, [
                'employee_no'   => (string) (Helpers::post('employee_no') ?? ''),
                'department_id' => (string) (Helpers::post('department_id') ?? ''),
                'specialization'=> (string) (Helpers::post('specialization') ?? ''),
            ]);
            $password = (string) (Helpers::post('password') ?? '');
            UserRepo::updateUser($userId, [
                'full_name' => (string) (Helpers::post('full_name') ?? ''),
                'email'     => (string) (Helpers::post('email') ?? ''),
            ], $password !== '' ? $password : null);
            Helpers::flash('success', 'Adviser record updated.');
        } elseif ($action === 'status') {
            $userId = Helpers::postInt('user_id');
            $status = (string) (Helpers::post('status') ?? '');
            if (!in_array($status, ['active', 'inactive', 'suspended'], true)) {
                throw new RuntimeException('Unknown account status.');
            }
            UserRepo::setStatus($userId, $status);
            Helpers::flash('success', 'Account status updated.');
        }
    } catch (Throwable $e) {
        Helpers::flash('error', $e->getMessage());
    }
    Helpers::redirect('admin/advisers.php');
}

/* ---- read ---- */
$filters = [
    'q'             => (string) (Helpers::get('q') ?? ''),
    'status'        => (string) (Helpers::get('status') ?? ''),
    'department_id' => (string) (Helpers::get('department_id') ?? ''),
];

$result = UserRepo::advisers($filters, Helpers::page(), 20);
$page   = min(Helpers::page(), $result['pages']);
$rows   = $result['rows'];
$query  = http_build_query(array_filter($filters));

$editId  = Helpers::getInt('edit');
$edit    = $editId > 0 ? UserRepo::findAdviser($editId) : null;
$editVal = static fn (string $key): string => (string) ($edit === null ? '' : ($edit[$key] ?? ''));

$statusOptions = ['active' => 'Active', 'inactive' => 'Inactive'];

/* ---- page meta (before header) ---- */
$PAGE_TITLE  = 'Advisers';
$PAGE_ACTIVE = 'advisers';
$PAGE_SUB    = 'Faculty and staff who guide a student organization.';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'admin/dashboard.php'], ['label' => 'Advisers']];

require __DIR__ . '/../includes/layout/header.php';
?>

<section class="card">
  <div class="card-head">
    <h3><?= $edit !== null ? 'Edit adviser' : 'Add adviser' ?></h3>
    <?php if ($edit !== null): ?>
      <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('admin/advisers.php')) ?>"><?= icon('close', 15) ?><span>Cancel</span></a>
    <?php endif; ?>
  </div>
  <form method="post">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="<?= $edit !== null ? 'edit' : 'create' ?>">
    <?php if ($edit !== null): ?>
      <input type="hidden" name="adviser_id" value="<?= (int) $edit['id'] ?>">
      <input type="hidden" name="user_id" value="<?= (int) $edit['user_id'] ?>">
    <?php endif; ?>

    <div class="grid cols-2">
      <?= ui_input('full_name', 'Full name', $editVal('full_name'), [], true) ?>
      <?= ui_input('username', 'Username', $editVal('username'),
          ['readonly' => $edit !== null ? 'readonly' : '',
           'hint' => $edit !== null ? 'Usernames cannot be changed after the account exists.' : 'Used to sign in.'], $edit === null) ?>
      <?= ui_input('email', 'E-mail', $editVal('email'), ['type' => 'email'], true) ?>
      <?= ui_input('password', 'Password', '', ['type' => 'password',
          'hint' => $edit !== null ? 'Leave empty to keep the current password.' : 'Leave empty to generate a random password.']) ?>
      <?= ui_input('employee_no', 'Employee number', $editVal('employee_no')) ?>
      <?= ui_select('department_id', 'Department', AcademicRepo::departmentOptions(),
          (string) ($edit['department_id'] ?? ''), ['placeholder' => 'Unassigned']) ?>
      <?= ui_input('specialization', 'Specialization', $editVal('specialization')) ?>
      <?php if ($edit === null): ?>
        <?= ui_input('phone', 'Contact number', '', ['type' => 'tel']) ?>
      <?php endif; ?>
    </div>

    <div class="form-actions">
      <button class="btn" type="submit"><?= icon($edit !== null ? 'check' : 'plus', 16) ?><span><?= $edit !== null ? 'Save changes' : 'Create adviser' ?></span></button>
      <a class="btn grey" href="<?= Helpers::e(Helpers::url('admin/advisers.php')) ?>">Cancel</a>
    </div>
  </form>
</section>

<?= ui_filter_form('admin/advisers.php',
    ui_filter_input('q', 'Search', $filters['q'])
    . ui_filter_select('status', 'Adviser status', $statusOptions, $filters['status'])
    . ui_filter_select('department_id', 'Department', AcademicRepo::departmentOptions(), $filters['department_id'])) ?>

<section class="card">
  <div class="card-head">
    <h3>Adviser roster</h3>
    <?= ui_badge((string) $result['total'], 'blue') ?>
  </div>

  <?php if ($rows === []): ?>
    <?= ui_empty('No adviser matches this search.', 'Create the first adviser account with the form above.', 'briefcase') ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Adviser</th>
            <th>Employee no.</th>
            <th>Department</th>
            <th>E-mail</th>
            <th class="num">Organizations</th>
            <th>Account status</th>
            <th class="actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td>
                <div class="person-cell">
                  <?= ui_avatar((string) $row['full_name'], null, 34) ?>
                  <div class="person-meta">
                    <strong><?= Helpers::e((string) $row['full_name']) ?></strong>
                    <small><?= Helpers::e((string) $row['username']) ?></small>
                  </div>
                </div>
              </td>
              <td class="small nowrap"><?= Helpers::e((string) ($row['employee_no'] !== '' ? $row['employee_no'] : '—')) ?></td>
              <td class="small"><?= Helpers::e((string) ($row['department_name'] !== '' ? $row['department_name'] : '—')) ?></td>
              <td class="small"><?= Helpers::e((string) $row['email']) ?></td>
              <td class="num"><?= (int) $row['organization_count'] ?></td>
              <td><?= ui_status_badge((string) $row['account_status']) ?></td>
              <td class="actions nowrap">
                <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('admin/advisers.php?edit=' . (int) $row['id'])) ?>"><?= icon('edit', 15) ?><span>Edit</span></a>
                <form method="post" class="inline">
                  <?= Security::csrfField() ?>
                  <input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>">
                  <?php if ((string) $row['account_status'] === 'active'): ?>
                    <button class="btn sm danger" type="submit" name="action" value="status"
                            onclick="return confirm('Deactivate this adviser account? The adviser will no longer be able to sign in.')">Deactivate</button>
                    <input type="hidden" name="status" value="inactive">
                  <?php else: ?>
                    <button class="btn sm" type="submit" name="action" value="status">Activate</button>
                    <input type="hidden" name="status" value="active">
                  <?php endif; ?>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= ui_pagination($page, $result['pages'], $query) ?>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
