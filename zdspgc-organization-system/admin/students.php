<?php
/**
 * admin/students.php — student records: filters, add/edit form, CSV export.
 *
 * Filters (q, department, course, year level, status) over StudentRepo::list,
 * an inline create/edit card backed by StudentRepo::create/update, archive and
 * restore actions and a ?export=1 CSV dump of the current result set.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Permissions::requireCapability('manage_students');

/* ---- POST actions (before any output) ---- */
if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) (Helpers::post('action') ?? '');

    try {
        if ($action === 'create' || $action === 'edit') {
            $data = [
                'first_name'     => (string) (Helpers::post('first_name') ?? ''),
                'middle_name'    => (string) (Helpers::post('middle_name') ?? ''),
                'last_name'      => (string) (Helpers::post('last_name') ?? ''),
                'suffix'         => (string) (Helpers::post('suffix') ?? ''),
                'gender'         => (string) (Helpers::post('gender') ?? ''),
                'birthdate'      => (string) (Helpers::post('birthdate') ?? ''),
                'email'          => (string) (Helpers::post('email') ?? ''),
                'contact_number' => (string) (Helpers::post('contact_number') ?? ''),
                'address'        => (string) (Helpers::post('address') ?? ''),
                'course'         => (string) (Helpers::post('course') ?? ''),
                'year_level'     => (string) (Helpers::post('year_level') ?? ''),
                'section'        => (string) (Helpers::post('section') ?? ''),
                'department_id'  => (string) (Helpers::post('department_id') ?? ''),
            ];

            if ($data['first_name'] === '' || $data['last_name'] === '') {
                throw new RuntimeException('First name and last name are required.');
            }

            if ($action === 'create') {
                $data['student_id'] = strtoupper((string) (Helpers::post('student_id') ?? ''));
                StudentRepo::create($data, (string) (Helpers::post('password') ?? ''));
                Helpers::flash('success', 'Student ' . $data['student_id'] . ' was added.');
            } else {
                $id = Helpers::postInt('record_id');
                if ($id < 1 || StudentRepo::find($id) === null) {
                    throw new RuntimeException('That student record no longer exists.');
                }
                StudentRepo::update($id, $data);
                Helpers::flash('success', 'Student record updated.');
            }
        } elseif ($action === 'archive' || $action === 'restore') {
            $id = Helpers::postInt('record_id');
            if ($id < 1 || StudentRepo::find($id) === null) {
                throw new RuntimeException('That student record no longer exists.');
            }
            StudentRepo::setStatus($id, $action === 'archive' ? 'archived' : 'active');
            Helpers::flash('success', 'Student record ' . ($action === 'archive' ? 'archived' : 'restored') . '.');
        }
    } catch (Throwable $e) {
        Helpers::flash('error', $e->getMessage());
    }
    Helpers::redirect('admin/students.php');
}

$filters = [
    'q'             => (string) (Helpers::get('q') ?? ''),
    'department_id' => (string) (Helpers::get('department_id') ?? ''),
    'course'        => (string) (Helpers::get('course') ?? ''),
    'year_level'    => (string) (Helpers::get('year_level') ?? ''),
    'status'        => (string) (Helpers::get('status') ?? ''),
];

/* ---- CSV export (UTF-8 BOM, before header.php) ---- */
if (Helpers::get('export') === '1') {
    $exportRows = StudentRepo::list($filters, 1, 5000)['rows'];
    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="students-' . date('Ymd') . '.csv"');
    }
    $stream = fopen('php://output', 'w');
    fwrite($stream, "\xEF\xBB\xBF");
    fputcsv($stream, ['Student ID', 'First name', 'Middle name', 'Last name', 'Gender', 'Birthdate',
                      'Course', 'Year level', 'Section', 'Department', 'E-mail', 'Contact number', 'Status']);
    foreach ($exportRows as $row) {
        fputcsv($stream, array_map(
            static fn (mixed $value): string => Security::csvSafe((string) $value),
            [
                $row['student_id'], $row['first_name'], $row['middle_name'], $row['last_name'],
                $row['gender'], (string) $row['birthdate'], $row['course'], $row['year_level'],
                $row['section'], $row['department_name'], $row['email'], $row['contact_number'], $row['status'],
            ]
        ));
    }
    fclose($stream);
    exit;
}

/* ---- read ---- */
$counts  = StudentRepo::counts();
$result  = StudentRepo::list($filters, Helpers::page(), 20);
$page    = min(Helpers::page(), $result['pages']);
$rows    = $result['rows'];
$query   = http_build_query(array_filter($filters));
$editId  = Helpers::getInt('edit');
$edit    = $editId > 0 ? StudentRepo::find($editId) : null;

$statusOptions = ['active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived'];
$genderOptions = ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'];

$value = static fn (string $key): string => (string) ($edit === null ? '' : ($edit[$key] ?? ''));

/* ---- page meta (before header) ---- */
$PAGE_TITLE  = 'Students';
$PAGE_ACTIVE = 'students';
$PAGE_SUB    = 'Registrar list of enrolled students, their programs and their sign-in accounts.';
$PAGE_ACTIONS = '<a class="btn ghost" href="' . Helpers::e(Helpers::url('admin/students.php?'
    . http_build_query(array_filter($filters + ['export' => 1])))) . '">' . icon('download', 16)
    . '<span>Export CSV</span></a>';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'admin/dashboard.php'], ['label' => 'Students']];

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="stat-grid">
  <?= ui_stat('Total students', $counts['total'], 'users') ?>
  <?= ui_stat('Active', $counts['active'], 'user-check') ?>
  <?= ui_stat('With sign-in account', $counts['with_account'], 'shield') ?>
  <?= ui_stat('Archived', $counts['archived'], 'folder') ?>
</div>

<section class="card" id="student-form">
  <div class="card-head">
    <h3><?= $edit !== null ? 'Edit student' : 'Add student' ?></h3>
    <?php if ($edit !== null): ?>
      <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('admin/students.php')) ?>"><?= icon('close', 15) ?><span>Cancel</span></a>
    <?php endif; ?>
  </div>
  <form method="post">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="<?= $edit !== null ? 'edit' : 'create' ?>">
    <?php if ($edit !== null): ?>
      <input type="hidden" name="record_id" value="<?= (int) $edit['id'] ?>">
    <?php endif; ?>

    <div class="grid cols-2">
      <?= ui_input('student_id', 'Student ID', $edit !== null ? $value('student_id') : '',
          ['placeholder' => '2024-00412', 'readonly' => $edit !== null ? 'readonly' : '', 'hint' => 'Format: YYYY-NNNNN.'], $edit === null) ?>
      <?= ui_input('first_name', 'First name', $value('first_name'), [], true) ?>
      <?= ui_input('middle_name', 'Middle name', $value('middle_name')) ?>
      <?= ui_input('last_name', 'Last name', $value('last_name'), [], true) ?>
      <?= ui_input('suffix', 'Suffix (Jr., III)', $value('suffix')) ?>
      <?= ui_select('gender', 'Gender', $genderOptions, $value('gender'), ['placeholder' => 'Prefer not to say']) ?>
      <?= ui_input('birthdate', 'Birthdate', $value('birthdate'), ['type' => 'date']) ?>
      <?= ui_input('email', 'E-mail', $value('email'), ['type' => 'email']) ?>
      <?= ui_input('contact_number', 'Contact number', $value('contact_number'), ['type' => 'tel']) ?>
      <?= ui_input('course', 'Course', $value('course')) ?>
      <?= ui_input('year_level', 'Year level', $value('year_level'), ['placeholder' => '1st Year']) ?>
      <?= ui_input('section', 'Section', $value('section')) ?>
      <?= ui_select('department_id', 'Department', AcademicRepo::departmentOptions(), (string) ($edit['department_id'] ?? ''), ['placeholder' => 'Unassigned']) ?>
      <?php if ($edit === null): ?>
        <?= ui_input('password', 'Account password', '', ['type' => 'password',
            'hint' => 'Optional. When set, a student sign-in account is created with this password.']) ?>
      <?php endif; ?>
      <?= ui_textarea('address', 'Address', $value('address')) ?>
    </div>

    <div class="form-actions">
      <button class="btn" type="submit"><?= icon($edit !== null ? 'check' : 'plus', 16) ?><span><?= $edit !== null ? 'Save changes' : 'Add student' ?></span></button>
      <a class="btn grey" href="<?= Helpers::e(Helpers::url('admin/students.php')) ?>">Cancel</a>
    </div>
  </form>
</section>

<?= ui_filter_form('admin/students.php',
    ui_filter_input('q', 'Search', $filters['q'])
    . ui_filter_select('department_id', 'Department', AcademicRepo::departmentOptions(), $filters['department_id'])
    . ui_filter_select('course', 'Course', StudentRepo::courseOptions(), $filters['course'])
    . ui_filter_select('year_level', 'Year level', StudentRepo::yearLevelOptions(), $filters['year_level'])
    . ui_filter_select('status', 'Status', $statusOptions, $filters['status'])) ?>

<section class="card">
  <div class="card-head">
    <h3>Student records</h3>
    <?= ui_badge((string) $result['total'], 'blue') ?>
  </div>

  <?php if ($rows === []): ?>
    <?= ui_empty('No student matches this search.', 'Clear the filters or add a new student record above.', 'users') ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Student</th>
            <th>Student ID</th>
            <th>Course · Year · Section</th>
            <th>Department</th>
            <th>E-mail</th>
            <th>Status</th>
            <th class="actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <?php $fullName = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']); ?>
            <tr>
              <td>
                <div class="person-cell">
                  <?= ui_avatar($fullName, Uploads::url((string) $row['profile_picture']), 34) ?>
                  <div class="person-meta"><strong><?= Helpers::e($fullName) ?></strong></div>
                </div>
              </td>
              <td class="small nowrap"><code><?= Helpers::e((string) $row['student_id']) ?></code></td>
              <td class="small"><?= Helpers::e(trim((string) $row['course'] . ' · ' . (string) $row['year_level'] . ' · ' . (string) $row['section'], ' ·')) ?></td>
              <td class="small"><?= Helpers::e((string) ($row['department_name'] !== '' ? $row['department_name'] : '—')) ?></td>
              <td class="small"><?= Helpers::e((string) ($row['email'] !== '' ? $row['email'] : (string) $row['account_email'])) ?></td>
              <td><?= ui_status_badge((string) $row['status']) ?></td>
              <td class="actions nowrap">
                <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('admin/students.php?edit=' . (int) $row['id'])) ?>"><?= icon('edit', 15) ?><span>Edit</span></a>
                <form method="post" class="inline">
                  <?= Security::csrfField() ?>
                  <input type="hidden" name="record_id" value="<?= (int) $row['id'] ?>">
                  <?php if ((string) $row['status'] === 'archived'): ?>
                    <button class="btn sm" type="submit" name="action" value="restore"
                            onclick="return confirm('Restore this student record?')">Restore</button>
                  <?php else: ?>
                    <button class="btn sm danger" type="submit" name="action" value="archive"
                            onclick="return confirm('Archive this student record? The account is signed out and the record leaves the active list.')">Archive</button>
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
