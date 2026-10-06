<?php
/**
 * admin/departments.php — manage college/department records.
 *
 * Full CRUD: list, add, edit, activate/deactivate.
 * Uses the existing `departments` table (code, name, head_name, status).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Permissions::requireCapability('manage_departments');

/* ── POST actions ── */
if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) (Helpers::post('action') ?? '');
    $id     = (int)    (Helpers::post('id')     ?? 0);

    try {
        if ($action === 'create' || $action === 'edit') {
            $code      = strtoupper(trim((string) (Helpers::post('code')      ?? '')));
            $name      = trim((string) (Helpers::post('name')      ?? ''));
            $head_name = trim((string) (Helpers::post('head_name') ?? ''));
            $status    = in_array(Helpers::post('status'), ['active', 'inactive'], true)
                       ? (string) Helpers::post('status') : 'active';

            if ($code === '' || $name === '') {
                throw new RuntimeException('Department code and name are required.');
            }

            // Duplicate code check
            $dupSql = 'SELECT id FROM departments WHERE UPPER(code) = UPPER(:c)';
            $dupPar = ['c' => $code];
            if ($action === 'edit') {
                $dupSql .= ' AND id <> :id';
                $dupPar['id'] = $id;
            }
            if (Database::scalar($dupSql, $dupPar) !== null) {
                throw new RuntimeException('A department with code "' . $code . '" already exists.');
            }

            $row = [
                'code'      => $code,
                'name'      => $name,
                'head_name' => $head_name,
                'status'    => $status,
            ];

            if ($action === 'create') {
                $row['created_at'] = Helpers::now();
                Database::insert('departments', $row);
                Helpers::flash('success', 'Department "' . $name . '" was created.');
            } else {
                if ($id < 1) {
                    throw new RuntimeException('No department selected for editing.');
                }
                Database::update('departments', $row, 'id = :id', ['id' => $id]);
                Helpers::flash('success', 'Department "' . $name . '" was updated.');
            }

        } elseif ($action === 'toggle') {
            $dept = Database::one('SELECT * FROM departments WHERE id = :id', ['id' => $id]);
            if ($dept === null) {
                throw new RuntimeException('Department not found.');
            }
            $newStatus = (string) $dept['status'] === 'active' ? 'inactive' : 'active';
            Database::update('departments', ['status' => $newStatus], 'id = :id', ['id' => $id]);
            Helpers::flash('success', 'Department status changed to ' . $newStatus . '.');
        }
    } catch (Throwable $e) {
        Helpers::flash('error', $e->getMessage());
    }

    Helpers::redirect('admin/departments.php');
}

/* ── Read ── */
$q      = (string) (Helpers::get('q')      ?? '');
$status = (string) (Helpers::get('status') ?? '');
$editId = (int)    (Helpers::get('edit')   ?? 0);

$sql    = 'SELECT d.*,
               (SELECT COUNT(*) FROM students  s WHERE s.department_id  = d.id) AS student_count,
               (SELECT COUNT(*) FROM advisers  a WHERE a.department_id  = d.id) AS adviser_count,
               (SELECT COUNT(*) FROM organizations o WHERE o.department_id = d.id) AS org_count
           FROM departments d WHERE 1=1';
$params = [];
if ($q !== '') {
    $sql .= ' AND (d.code LIKE :q OR d.name LIKE :q2 OR d.head_name LIKE :q3)';
    $params['q'] = '%' . $q . '%';
    $params['q2'] = '%' . $q . '%';
    $params['q3'] = '%' . $q . '%';
}
if ($status !== '') {
    $sql .= ' AND d.status = :s';
    $params['s'] = $status;
}
$sql .= ' ORDER BY d.name ASC';
$departments = Database::all($sql, $params);

$editing = null;
if ($editId > 0) {
    $editing = Database::one('SELECT * FROM departments WHERE id = :id', ['id' => $editId]);
}

$PAGE_TITLE       = 'Departments';
$PAGE_ACTIVE      = 'departments';
$PAGE_SUB         = 'Colleges and departments linked to students, advisers, and organizations.';
$PAGE_ACTIONS     = '<a class="btn" href="#dept-form">' . icon('plus', 16) . '<span>Add department</span></a>';
$PAGE_BREADCRUMBS = [
    ['label' => 'Dashboard',   'href' => 'admin/dashboard.php'],
    ['label' => 'Configuration'],
    ['label' => 'Departments'],
];
require __DIR__ . '/../includes/layout/header.php';
?>

<?= ui_filter_form('admin/departments.php',
    ui_filter_input('q', 'Search', $q) .
    ui_filter_select('status', 'Status', ['active' => 'Active', 'inactive' => 'Inactive'], $status, 'All statuses')
) ?>

<div class="grid cols-2">
  <!-- ── Department list ── -->
  <section class="card">
    <div class="card-head">
      <div>
        <h3>Departments</h3>
        <p class="sub muted small"><?= count($departments) ?> result(s)</p>
      </div>
      <span class="badge blue"><?= count($departments) ?></span>
    </div>

    <?php if ($departments === []): ?>
      <?= ui_empty('No departments found.', 'Add the first department or clear the search filter.', 'org') ?>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tbl">
          <thead>
            <tr>
              <th>Code</th>
              <th>Name / Head</th>
              <th class="num">Students</th>
              <th class="num">Advisers</th>
              <th class="num">Orgs</th>
              <th>Status</th>
              <th class="actions">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($departments as $dept): ?>
              <tr>
                <td><code><?= Helpers::e((string) $dept['code']) ?></code></td>
                <td>
                  <strong><?= Helpers::e((string) $dept['name']) ?></strong>
                  <?php if ((string) $dept['head_name'] !== ''): ?>
                    <br><span class="muted small"><?= Helpers::e((string) $dept['head_name']) ?></span>
                  <?php endif; ?>
                </td>
                <td class="num small"><?= (int) $dept['student_count'] ?></td>
                <td class="num small"><?= (int) $dept['adviser_count'] ?></td>
                <td class="num small"><?= (int) $dept['org_count'] ?></td>
                <td><?= ui_status_badge((string) $dept['status']) ?></td>
                <td class="actions">
                  <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('admin/departments.php?edit=' . (int) $dept['id'])) ?>">
                    <?= icon('edit', 14) ?><span>Edit</span>
                  </a>
                  <form method="post" class="inline-form">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int) $dept['id'] ?>">
                    <button class="btn sm grey" type="submit">
                      <?= icon((string)$dept['status'] === 'active' ? 'close' : 'check', 14) ?>
                      <span><?= (string)$dept['status'] === 'active' ? 'Deactivate' : 'Activate' ?></span>
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <!-- ── Add / Edit form ── -->
  <section class="card" id="dept-form">
    <div class="card-head">
      <h3><?= $editing !== null ? 'Edit: ' . Helpers::e((string) $editing['name']) : 'Add a department' ?></h3>
      <?php if ($editing !== null): ?><?= ui_status_badge((string) $editing['status']) ?><?php endif; ?>
    </div>

    <form method="post">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="<?= $editing !== null ? 'edit' : 'create' ?>">
      <input type="hidden" name="id"     value="<?= (int) ($editing['id'] ?? 0) ?>">

      <?= ui_input('code', 'Department code', (string) ($editing['code'] ?? ''),
          ['placeholder' => 'e.g. CICS, CBA, CTE', 'hint' => 'Short uppercase abbreviation. Must be unique.'], true) ?>
      <?= ui_input('name', 'Full department name', (string) ($editing['name'] ?? ''),
          ['placeholder' => 'e.g. College of Information and Computing Studies'], true) ?>
      <?= ui_input('head_name', 'Department head / dean', (string) ($editing['head_name'] ?? ''),
          ['placeholder' => 'e.g. Dr. Maria Santos']) ?>
      <?= ui_select('status', 'Status', ['active' => 'Active', 'inactive' => 'Inactive'],
          (string) ($editing['status'] ?? 'active'), [], true) ?>

      <div class="form-actions">
        <button class="btn" type="submit">
          <?= icon('check', 16) ?>
          <span><?= $editing !== null ? 'Save changes' : 'Create department' ?></span>
        </button>
        <?php if ($editing !== null): ?>
          <a class="btn grey" href="<?= Helpers::e(Helpers::url('admin/departments.php')) ?>">
            <?= icon('close', 16) ?><span>Cancel</span>
          </a>
        <?php endif; ?>
      </div>
    </form>
  </section>
</div>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
