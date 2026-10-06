<?php
/**
 * admin/academic-years.php — create and maintain the academic years every
 * record in the system is attached to.
 *
 * Only one year may be active at a time: saving a year as Active closes the
 * year that is currently active, which the form explains before the change.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Permissions::requireCapability('manage_academic_years');

/* ---- POST actions (create / edit) run before any output ---- */
if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) (Helpers::post('action') ?? '');
    $id     = (int) (Helpers::post('id') ?? 0);
    $data   = [
        'name'       => (string) (Helpers::post('name') ?? ''),
        'start_date' => (string) (Helpers::post('start_date') ?? ''),
        'end_date'   => (string) (Helpers::post('end_date') ?? ''),
        'status'     => (string) (Helpers::post('status') ?? 'upcoming'),
    ];

    try {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['start_date']) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['end_date']) !== 1) {
            throw new RuntimeException('Enter both a start date and an end date for the academic year.');
        }
        if ($data['end_date'] < $data['start_date']) {
            throw new RuntimeException('The end date cannot be earlier than the start date.');
        }
        if ($action === 'create') {
            AcademicRepo::createYear($data);
            Helpers::flash('success', 'Academic year ' . $data['name'] . ' was created.');
        } elseif ($action === 'edit') {
            if ($id < 1) {
                throw new RuntimeException('Choose an academic year to edit.');
            }
            AcademicRepo::updateYear($id, $data);
            Helpers::flash('success', 'Academic year ' . $data['name'] . ' was updated.');
        }
    } catch (Throwable $e) {
        Helpers::flash('error', $e->getMessage());
    }

    Helpers::redirect('admin/academic-years.php');
}

/* ---- read ---- */
$years   = AcademicRepo::years();
$editId  = (int) (Helpers::get('edit') ?? 0);
$editing = null;
foreach ($years as $year) {
    if ((int) $year['id'] === $editId) {
        $editing = $year;
        break;
    }
}

$statusOptions = ['upcoming' => 'Upcoming', 'active' => 'Active', 'closed' => 'Closed', 'archived' => 'Archived'];

$PAGE_TITLE  = 'Academic Years';
$PAGE_ACTIVE = 'academic-years';
$PAGE_SUB    = 'The school years organizations, members, officers and events are attached to · only one year stays active.';
$PAGE_ACTIONS = '<a class="btn ghost" href="#year-form">' . icon('plus', 16) . '<span>Add academic year</span></a>';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'admin/dashboard.php'], ['label' => 'Academic Years']];

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="grid cols-2">
  <section class="card">
    <div class="card-head">
      <h3>Academic years</h3>
      <span class="badge blue"><?= count($years) ?></span>
    </div>
    <?php if ($years === []): ?>
      <?= ui_empty('No academic year exists yet.', 'Add the first year so records can be attached to it.', 'hash') ?>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tbl">
          <thead>
            <tr><th>Year</th><th>Starts</th><th>Ends</th><th>Status</th><th>Created</th><th class="actions">Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($years as $year): ?>
              <tr>
                <td><strong><?= Helpers::e((string) $year['name']) ?></strong></td>
                <td class="small nowrap"><?= Helpers::e(Helpers::fmtDate((string) $year['start_date'])) ?></td>
                <td class="small nowrap"><?= Helpers::e(Helpers::fmtDate((string) $year['end_date'])) ?></td>
                <td><?= ui_status_badge((string) $year['status']) ?></td>
                <td class="small nowrap"><?= Helpers::e(Helpers::fmtDate((string) $year['created_at'])) ?></td>
                <td class="actions">
                  <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('admin/academic-years.php?edit=' . (int) $year['id'])) ?>">
                    <?= icon('edit', 15) ?><span>Edit</span>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="card" id="year-form">
    <div class="card-head">
      <h3><?= $editing !== null ? 'Edit ' . Helpers::e((string) $editing['name']) : 'Add an academic year' ?></h3>
      <?php if ($editing !== null): ?>
        <?= ui_status_badge((string) $editing['status']) ?>
      <?php endif; ?>
    </div>
    <p class="hint mb-tight">Saving a year with the status <strong>Active</strong> makes it the current academic year and
      automatically closes the year that is active today — the system never keeps two active years.</p>
    <form method="post">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="<?= $editing !== null ? 'edit' : 'create' ?>">
      <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">

      <?= ui_input('name', 'Academic year name', (string) ($editing['name'] ?? ''), ['placeholder' => '2026-2027'], true) ?>
      <?= ui_input('start_date', 'Start date', (string) ($editing['start_date'] ?? ''), ['type' => 'date'], true) ?>
      <?= ui_input('end_date', 'End date', (string) ($editing['end_date'] ?? ''), ['type' => 'date'], true) ?>
      <?= ui_select('status', 'Status', $statusOptions, (string) ($editing['status'] ?? 'upcoming'), [
          'hint' => 'Upcoming, Active, Closed or Archived. Choosing Active closes the previously active year.',
      ], true) ?>

      <div class="form-actions">
        <button class="btn" type="submit"><?= icon('check', 16) ?><span><?= $editing !== null ? 'Save changes' : 'Create academic year' ?></span></button>
        <?php if ($editing !== null): ?>
          <a class="btn grey" href="<?= Helpers::e(Helpers::url('admin/academic-years.php')) ?>"><?= icon('close', 16) ?><span>Cancel</span></a>
        <?php endif; ?>
      </div>
    </form>
  </section>
</div>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
