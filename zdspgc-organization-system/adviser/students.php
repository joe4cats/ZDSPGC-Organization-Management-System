<?php
/**
 * adviser/students.php — bulk student import for teachers and advisers.
 *
 * Uploads a CSV roster (a template is provided) and creates the student
 * record plus a sign-in account for every valid row. Student IDs that already
 * exist are skipped, and every rejected row is reported with its line number.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireRole('adviser');
Permissions::requireCapability('import_students');

$adviser = Database::one('SELECT department_id FROM advisers WHERE user_id = :u', ['u' => Auth::id()]);
$deptId  = (int) ($adviser['department_id'] ?? 0);

/* ---- Template download (before any output) ---- */
if (Helpers::get('template') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="students-template.csv"');
    $stream = fopen('php://output', 'w');
    fputcsv($stream, ['student_id', 'first_name', 'last_name', 'email', 'course', 'year_level', 'section', 'contact_number']);
    fputcsv($stream, ['2026-00123', 'Maria', 'Santos', 'maria.santos@zdspgc.edu.ph', 'BS Information Technology', '1st Year', 'BSIT-1A', '09171234567']);
    fputcsv($stream, ['2026-00124', 'Jose', 'Reyes', 'jose.reyes@zdspgc.edu.ph', 'BS Information Technology', '2nd Year', 'BSIT-2B', '09181234567']);
    fclose($stream);
    exit;
}

/* ---- Import (POST → redirect with the report kept in the session) ---- */
if (Helpers::isPost() && (string) (Helpers::post('action') ?? '') === 'import') {
    Security::requireCsrf();
    $password = (string) (Helpers::post('password') ?? '');
    $policy   = Security::passwordProblem($password);

    if ($policy !== null) {
        Helpers::flash('error', $policy);
    } elseif (!isset($_FILES['csv']) || (int) $_FILES['csv']['error'] === UPLOAD_ERR_NO_FILE) {
        Helpers::flash('error', 'Choose a CSV file to import.');
    } else {
        $file  = $_FILES['csv'];
        $ext   = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
        $mime  = $finfo !== null ? (string) finfo_file($finfo, (string) $file['tmp_name']) : '';
        if ($finfo !== null) {
            finfo_close($finfo);
        }
        $csvMimes = array_merge(ALLOWED_UPLOADS['csv'] ?? [], ['application/vnd.ms-excel']);

        $problem = null;
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            $problem = 'The upload failed (error ' . (int) $file['error'] . ').';
        } elseif ((int) $file['size'] > UPLOAD_MAX_BYTES) {
            $problem = 'The file is larger than the ' . UPLOAD_MAX_MB . ' MB limit.';
        } elseif ($ext !== 'csv') {
            $problem = 'Only .csv files can be imported.';
        } elseif ($mime !== '' && !in_array($mime, $csvMimes, true)) {
            $problem = 'That file does not look like a CSV document (' . $mime . ').';
        }

        $report = null;
        if ($problem !== null) {
            Helpers::flash('error', $problem);
        } elseif (($handle = fopen((string) $file['tmp_name'], 'r')) === false) {
            Helpers::flash('error', 'The uploaded file could not be read.');
        } else {
            $header = fgetcsv($handle);
            $cols   = [];
            if ($header !== false) {
                $alias = [
                    'studentid' => 'student_id', 'id' => 'student_id',
                    'firstname' => 'first_name', 'lastname' => 'last_name', 'middlename' => 'middle_name',
                    'emailaddress' => 'email', 'mail' => 'email',
                    'phone' => 'contact_number', 'phonenumber' => 'contact_number', 'mobile' => 'contact_number',
                    'year' => 'year_level', 'yr' => 'year_level',
                ];
                foreach ($header as $i => $name) {
                    $key       = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string) $name))), '_');
                    $cols[$i]  = $alias[$key] ?? $key;
                }
            }

            $missing = array_values(array_diff(['student_id', 'first_name', 'last_name', 'email'], array_values($cols)));
            if ($header === false || $missing !== []) {
                Helpers::flash('error', $header === false
                    ? 'That file is empty.'
                    : 'The header row is missing required column(s): ' . implode(', ', $missing) . '.');
                fclose($handle);
            } else {
                $report = ['imported' => 0, 'skipped' => [], 'errors' => [], 'rows' => 0];
                $seen   = [];
                $line   = 1;
                while (($row = fgetcsv($handle)) !== false) {
                    $line++;
                    if ($row === null || trim(implode('', array_map(static fn ($c): string => (string) $c, $row))) === '') {
                        continue;
                    }
                    $data = [];
                    foreach ($cols as $i => $key) {
                        $data[$key] = trim((string) ($row[$i] ?? ''));
                    }
                    $data['department_id'] = $deptId;
                    $report['rows']++;

                    $label = $data['student_id'] !== '' ? strtoupper($data['student_id']) : ('row ' . $line);
                    if ($data['student_id'] === '' || $data['first_name'] === '' || $data['last_name'] === '') {
                        $report['errors'][] = ['row' => $line, 'reason' => 'student_id, first_name and last_name are all required.'];
                        continue;
                    }
                    if (Security::validEmail($data['email']) === false) {
                        $report['errors'][] = ['row' => $line, 'reason' => $label . ': a valid e-mail address is required to create the account.'];
                        continue;
                    }
                    if (isset($seen[$label])) {
                        $report['errors'][] = ['row' => $line, 'reason' => $label . ': duplicated earlier in this file.'];
                        continue;
                    }
                    $seen[$label] = true;
                    if (StudentRepo::findByStudentId($label) !== null) {
                        $report['skipped'][] = ['row' => $line, 'reason' => $label . ': already exists in the system.'];
                        continue;
                    }
                    try {
                        StudentRepo::create($data, $password);
                        $report['imported']++;
                    } catch (Throwable $e) {
                        $report['errors'][] = ['row' => $line, 'reason' => $label . ': ' . $e->getMessage()];
                    }
                    if ($report['rows'] >= 500) {
                        $report['errors'][] = ['row' => $line, 'reason' => 'Stopped after 500 data rows. Split the file and import the rest.'];
                        break;
                    }
                }
                fclose($handle);

                if ($report['rows'] === 0) {
                    Helpers::flash('warning', 'No data rows were found below the header.');
                    $report = null;
                } else {
                    $_SESSION['import_report'] = $report;
                    Helpers::flash(
                        $report['imported'] > 0 ? 'success' : 'warning',
                        'Import finished: ' . $report['imported'] . ' created, '
                        . count($report['skipped']) . ' skipped, ' . count($report['errors']) . ' error(s).'
                    );
                }
            }
        }
    }
    Helpers::redirect('adviser/students.php');
}

$report = $_SESSION['import_report'] ?? null;
unset($_SESSION['import_report']);

$PAGE_TITLE   = 'Import Students';
$PAGE_ACTIVE  = 'students';
$PAGE_SUB     = 'Add your students in bulk — one CSV row creates the record and the sign-in account.';
$PAGE_ACTIONS = '<a class="btn ghost" href="' . Helpers::e(Helpers::url('adviser/students.php?template=1')) . '">'
    . icon('download', 16) . '<span>Download template</span></a>';

require __DIR__ . '/../includes/layout/header.php';
?>

<?php if ($report !== null): ?>
<section class="card">
  <div class="card-head">
    <div>
      <h3>Import results</h3>
      <p class="sub muted small">
        Read <?= (int) $report['rows'] ?> data row(s) — <?= (int) $report['imported'] ?> created,
        <?= count($report['skipped']) ?> skipped, <?= count($report['errors']) ?> error(s).
      </p>
    </div>
    <?= ui_badge((int) $report['imported'] . ' created', $report['errors'] === [] ? 'green' : 'amber') ?>
  </div>

  <?php if ($report['errors'] !== []): ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>CSV row</th><th>Problem</th></tr></thead>
        <tbody>
          <?php foreach ($report['errors'] as $err): ?>
            <tr><td class="nowrap"><?= (int) $err['row'] ?></td><td class="small"><?= Helpers::e((string) $err['reason']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if ($report['skipped'] !== []): ?>
    <div class="card-head spaced-head"><h3>Skipped rows</h3></div>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>CSV row</th><th>Reason</th></tr></thead>
        <tbody>
          <?php foreach ($report['skipped'] as $skip): ?>
            <tr><td class="nowrap"><?= (int) $skip['row'] ?></td><td class="small"><?= Helpers::e((string) $skip['reason']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card">
  <div class="card-head">
    <div>
      <h3>Upload a CSV file</h3>
      <p class="sub muted small">Maximum <?= (int) UPLOAD_MAX_MB ?> MB · up to 500 rows per import.</p>
    </div>
  </div>

  <form method="post" enctype="multipart/form-data">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="import">

    <label class="field">
      <span class="field-label">CSV file <b class="req">*</b></span>
      <input type="file" name="csv" id="csv" accept=".csv,text/csv,application/vnd.ms-excel" required>
      <span class="field-hint">Use the template button above if you are starting from scratch. The first row must be the header.</span>
    </label>

    <?= ui_input('password', 'Password for the new accounts', 'StudentPass@2026', [
        'type'   => 'password',
        'hint'   => 'Every student imported from this file signs in with this password (at least 8 characters, letters and numbers). They can change it later from their profile.',
    ], true) ?>

    <div class="form-actions">
      <button class="btn" type="submit"><?= icon('upload', 16) ?><span>Import students</span></button>
      <a class="btn grey" href="<?= Helpers::e(Helpers::url('adviser/students.php?template=1')) ?>"><?= icon('download', 16) ?><span>Download template</span></a>
    </div>
  </form>
</section>

<section class="card">
  <div class="card-head"><h3>CSV format</h3></div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Column</th><th>Required</th><th>Notes</th></tr></thead>
      <tbody>
        <tr><td><code>student_id</code></td><td><?= ui_badge('Yes', 'red') ?></td><td class="small">School ID like 2026-00123 — unique across the system.</td></tr>
        <tr><td><code>first_name</code></td><td><?= ui_badge('Yes', 'red') ?></td><td class="small">Given name.</td></tr>
        <tr><td><code>last_name</code></td><td><?= ui_badge('Yes', 'red') ?></td><td class="small">Family name.</td></tr>
        <tr><td><code>email</code></td><td><?= ui_badge('Yes', 'red') ?></td><td class="small">Used for the sign-in account — must be unique.</td></tr>
        <tr><td><code>course</code></td><td><?= ui_badge('No', 'grey') ?></td><td class="small">e.g. BS Information Technology.</td></tr>
        <tr><td><code>year_level</code></td><td><?= ui_badge('No', 'grey') ?></td><td class="small">e.g. 1st Year.</td></tr>
        <tr><td><code>section</code></td><td><?= ui_badge('No', 'grey') ?></td><td class="small">e.g. BSIT-1A.</td></tr>
        <tr><td><code>contact_number</code></td><td><?= ui_badge('No', 'grey') ?></td><td class="small">Mobile number.</td></tr>
      </tbody>
    </table>
  </div>
  <p class="hint mt">Columns may appear in any order — they are matched by the header names. Rows whose student ID already exists are skipped, so the same file can be re-imported safely.</p>
</section>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
