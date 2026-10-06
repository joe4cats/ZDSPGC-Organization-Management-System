<?php
/**
 * organization/reports.php — organization reports, rosters & summaries for officers.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireRole('officer');
Permissions::requireCapability('org_reports');

$org = Permissions::activeOrganization();
if ($org === null) {
    Helpers::flash('error', 'You must be assigned to an active organization.');
    Helpers::redirect('organization/dashboard.php');
}
$orgId = (int) $org['id'];

$section = (string) (Helpers::get('section') ?? 'members');
$yearId  = (int)    (Helpers::get('year_id')  ?? 0);
$q       = (string) (Helpers::get('q')        ?? '');
$export  = (string) (Helpers::get('export')   ?? '');

$yearOptions = AcademicRepo::yearOptions();
$rows = [];

switch ($section) {
    case 'members':
        $w = ['m.organization_id = :org'];
        $p = ['org' => $orgId];
        if ($yearId > 0) { $w[] = 'm.academic_year_id = :yr'; $p['yr'] = $yearId; }
        if ($q !== '') {
            $w[] = '(s.first_name LIKE :q OR s.last_name LIKE :q2 OR s.student_id LIKE :q3)';
            $p['q'] = '%' . $q . '%'; $p['q2'] = '%' . $q . '%'; $p['q3'] = '%' . $q . '%';
        }
        $rows = Database::all(
            "SELECT m.*, s.student_id, s.first_name, s.last_name, s.course, s.year_level, s.email, s.contact_number,
                    ay.name AS academic_year_name, p.name AS position_name
             FROM organization_members m
             JOIN students s ON s.id = m.student_id
             LEFT JOIN academic_years ay ON ay.id = m.academic_year_id
             LEFT JOIN officer_positions p ON p.id = m.position_id
             WHERE " . implode(' AND ', $w) . "
             ORDER BY m.status ASC, s.last_name ASC",
            $p
        );
        break;

    case 'officers':
        $w = ['off.organization_id = :org'];
        $p = ['org' => $orgId];
        if ($yearId > 0) { $w[] = 'off.academic_year_id = :yr'; $p['yr'] = $yearId; }
        $rows = Database::all(
            "SELECT off.*, s.student_id, s.first_name, s.last_name, s.course, s.year_level, s.contact_number,
                    p.name AS position_name, p.sort_order, ay.name AS academic_year_name
             FROM organization_officers off
             JOIN students s ON s.id = off.student_id
             JOIN officer_positions p ON p.id = off.position_id
             LEFT JOIN academic_years ay ON ay.id = off.academic_year_id
             WHERE " . implode(' AND ', $w) . "
             ORDER BY p.sort_order ASC, s.last_name ASC",
            $p
        );
        break;

    case 'events':
        $w = ['e.organization_id = :org'];
        $p = ['org' => $orgId];
        if ($yearId > 0) { $w[] = 'e.academic_year_id = :yr'; $p['yr'] = $yearId; }
        $rows = Database::all(
            "SELECT e.*, ay.name AS academic_year_name,
                    (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id) AS reg_count,
                    (SELECT COUNT(*) FROM attendance a WHERE a.event_id = e.id AND a.status IN ('present','late')) AS att_count
             FROM events e
             LEFT JOIN academic_years ay ON ay.id = e.academic_year_id
             WHERE " . implode(' AND ', $w) . "
             ORDER BY e.event_date DESC",
            $p
        );
        break;

    case 'attendance':
        $w = ['e.organization_id = :org'];
        $p = ['org' => $orgId];
        if ($q !== '') {
            $w[] = '(s.first_name LIKE :q OR s.last_name LIKE :q2 OR s.student_id LIKE :q3 OR e.title LIKE :q4)';
            $p['q'] = '%' . $q . '%'; $p['q2'] = '%' . $q . '%'; $p['q3'] = '%' . $q . '%'; $p['q4'] = '%' . $q . '%';
        }
        $rows = Database::all(
            "SELECT a.*, s.student_id, s.first_name, s.last_name, s.course, s.year_level,
                    e.title AS event_title, e.event_date, u.full_name AS recorder_name
             FROM attendance a
             JOIN students s ON s.id = a.student_id
             JOIN events e ON e.id = a.event_id
             LEFT JOIN users u ON u.id = a.recorded_by
             WHERE " . implode(' AND ', $w) . "
             ORDER BY a.attendance_time DESC",
            $p
        );
        break;
}

if ($export === 'csv') {
    $filename = $org['acronym'] . '-' . $section . '-report-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');

    switch ($section) {
        case 'members':
            fputcsv($out, ['Student ID', 'Last Name', 'First Name', 'Course', 'Year Level', 'Email', 'Contact', 'Position', 'Status', 'Date Joined']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['student_id'], $r['last_name'], $r['first_name'], $r['course'], $r['year_level'], $r['email'], $r['contact_number'], $r['position_name'] ?? 'Member', $r['status'], $r['joined_at'] ?? $r['applied_at']]);
            }
            break;
        case 'officers':
            fputcsv($out, ['Position', 'Student ID', 'Officer Name', 'Course', 'Year Level', 'Contact', 'Term', 'Status']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['position_name'], $r['student_id'], $r['last_name'] . ', ' . $r['first_name'], $r['course'], $r['year_level'], $r['contact_number'], $r['term'] ?? $r['academic_year_name'], $r['status']]);
            }
            break;
        case 'events':
            fputcsv($out, ['Event Code', 'Title', 'Event Date', 'Venue', 'Status', 'Registrations', 'Attendance']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['event_code'], $r['title'], $r['event_date'], $r['venue'], $r['status'], $r['reg_count'], $r['att_count']]);
            }
            break;
        case 'attendance':
            fputcsv($out, ['Event', 'Date', 'Student ID', 'Student Name', 'Course', 'Time In', 'Status', 'Recorded By']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['event_title'], $r['event_date'], $r['student_id'], $r['last_name'] . ', ' . $r['first_name'], $r['course'], $r['attendance_time'], $r['status'], $r['recorder_name'] ?? 'Self']);
            }
            break;
    }
    fclose($out);
    exit;
}

$sectionLabels = [
    'members'    => 'Member Roster',
    'officers'   => 'Officer Roster',
    'events'     => 'Event Summary',
    'attendance' => 'Attendance Logs',
];

$PAGE_TITLE       = 'Organization Reports';
$PAGE_ACTIVE      = 'reports';
$PAGE_SUB         = Helpers::e((string) $org['name']) . ' · Export rosters, attendance sheets and activity records';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'organization/dashboard.php'], ['label' => 'Reports']];
$PAGE_ACTIONS     = '<div class="btn-row">'
    . '<a class="btn sm" href="' . Helpers::e(Helpers::url('organization/reports.php?' . http_build_query(array_merge($_GET, ['export' => 'csv'])))) . '">'
    . icon('download', 15) . '<span>Export CSV</span></a>'
    . '<button class="btn sm ghost" onclick="window.print()">'
    . icon('printer', 15) . '<span>Print Sheet</span></button>'
    . '</div>';

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="tabs mb-4">
  <?php foreach ($sectionLabels as $key => $label): ?>
    <a href="<?= Helpers::e(Helpers::url('organization/reports.php?section=' . $key)) ?>"
       class="tab <?= $section === $key ? 'active' : '' ?>">
      <?= Helpers::e($label) ?>
    </a>
  <?php endforeach; ?>
</div>

<section class="card mb-4 no-print">
  <form method="get" action="<?= Helpers::e(Helpers::url('organization/reports.php')) ?>" class="filter-bar">
    <input type="hidden" name="section" value="<?= Helpers::e($section) ?>">
    <div class="filter-field">
      <input type="search" name="q" value="<?= Helpers::e($q) ?>" placeholder="Search records...">
    </div>
    <div class="filter-field">
      <select name="year_id">
        <option value="">All Academic Years</option>
        <?php foreach ($yearOptions as $id => $name): ?>
          <option value="<?= $id ?>" <?= $yearId === $id ? 'selected' : '' ?>><?= Helpers::e($name) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-actions">
      <button class="btn sm" type="submit"><?= icon('search', 15) ?><span>Apply</span></button>
      <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('organization/reports.php?section=' . $section)) ?>"><?= icon('refresh', 15) ?><span>Reset</span></a>
    </div>
  </form>
</section>

<!-- Print Sheet Header -->
<div class="print-doc-head">
  <div class="print-doc-brand">
    <img src="<?= Helpers::e(Helpers::url('assets/img/logo.png')) ?>" alt="Seal">
  </div>
  <div>
    <div class="print-doc-unit"><?= Helpers::e(SCHOOL_NAME) ?> · <?= Helpers::e((string) $org['name']) ?></div>
    <h1><?= Helpers::e($sectionLabels[$section]) ?></h1>
    <div class="small muted">Generated on <?= date('F j, Y, g:i a') ?></div>
  </div>
  <div class="print-doc-meta">
    Total records: <?= count($rows) ?><br>
    Status: <?= ui_status((string) $org['status']) ?>
  </div>
</div>

<section class="card">
  <div class="card-head">
    <h3><?= Helpers::e($sectionLabels[$section]) ?></h3>
    <span class="muted small"><?= count($rows) ?> record(s)</span>
  </div>

  <?php if ($rows === []): ?>
    <?= ui_empty('No records found for this report.', 'Try broadening your filter criteria.', 'chart') ?>
  <?php else: ?>
    <div class="table-wrap">
      <?php if ($section === 'members'): ?>
        <table class="tbl">
          <thead>
            <tr>
              <th>Student ID</th>
              <th>Full Name</th>
              <th>Course / Year</th>
              <th>Position</th>
              <th>Contact</th>
              <th>Status</th>
              <th>Joined Date</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><code><?= Helpers::e((string) $r['student_id']) ?></code></td>
                <td><strong><?= Helpers::e((string) $r['last_name'] . ', ' . (string) $r['first_name']) ?></strong></td>
                <td><?= Helpers::e((string) $r['course'] . ' · ' . (string) $r['year_level']) ?></td>
                <td><?= ui_badge((string) ($r['position_name'] ?? 'Member'), 'blue') ?></td>
                <td><?= Helpers::e((string) ($r['contact_number'] ?: $r['email'])) ?></td>
                <td><?= ui_status_badge((string) $r['status']) ?></td>
                <td><?= Helpers::e(Helpers::fmtDate((string) ($r['joined_at'] ?? $r['applied_at']))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

      <?php elseif ($section === 'officers'): ?>
        <table class="tbl">
          <thead>
            <tr>
              <th>Position</th>
              <th>Officer Name</th>
              <th>Student ID</th>
              <th>Program & Year</th>
              <th>Term</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= ui_badge((string) $r['position_name'], 'blue') ?></td>
                <td><strong><?= Helpers::e((string) $r['last_name'] . ', ' . (string) $r['first_name']) ?></strong></td>
                <td><code><?= Helpers::e((string) $r['student_id']) ?></code></td>
                <td><?= Helpers::e((string) $r['course'] . ' · ' . (string) $r['year_level']) ?></td>
                <td><?= Helpers::e((string) ($r['term'] ?? $r['academic_year_name'])) ?></td>
                <td><?= ui_status_badge((string) $r['status']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

      <?php elseif ($section === 'events'): ?>
        <table class="tbl">
          <thead>
            <tr>
              <th>Event Code</th>
              <th>Title</th>
              <th>Date & Schedule</th>
              <th>Venue</th>
              <th>Registrations</th>
              <th>Attendees</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><code><?= Helpers::e((string) $r['event_code']) ?></code></td>
                <td><strong><?= Helpers::e((string) $r['title']) ?></strong></td>
                <td><?= Helpers::e(Helpers::fmtDate((string) $r['event_date'])) ?></td>
                <td><?= Helpers::e((string) $r['venue']) ?></td>
                <td><?= (int) $r['reg_count'] ?></td>
                <td><?= (int) $r['att_count'] ?></td>
                <td><?= ui_status_badge((string) $r['status']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

      <?php elseif ($section === 'attendance'): ?>
        <table class="tbl">
          <thead>
            <tr>
              <th>Event</th>
              <th>Student</th>
              <th>Time In</th>
              <th>Status</th>
              <th>Recorded By</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td>
                  <strong><?= Helpers::e((string) $r['event_title']) ?></strong>
                  <span class="muted small d-block"><?= Helpers::e(Helpers::fmtDate((string) $r['event_date'])) ?></span>
                </td>
                <td>
                  <strong><?= Helpers::e((string) $r['last_name'] . ', ' . (string) $r['first_name']) ?></strong>
                  <span class="muted small d-block"><code><?= Helpers::e((string) $r['student_id']) ?></code></span>
                </td>
                <td><?= Helpers::e((string) $r['attendance_time']) ?></td>
                <td><?= ui_status_badge((string) $r['status']) ?></td>
                <td><?= Helpers::e((string) ($r['recorder_name'] ?? 'Self / QR')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>

<!-- Print Signatories -->
<div class="print-signatories print-only">
  <div class="sig">
    <span class="sig-line"></span>
    <b>Prepared By</b><br>
    <span><?= Helpers::e(Auth::user()['full_name'] ?? 'Organization Officer') ?></span><br>
    <span class="small muted">Organization Officer</span>
  </div>
  <div class="sig">
    <span class="sig-line"></span>
    <b>Attested By</b><br>
    <span>Organization Adviser</span><br>
    <span class="small muted">Faculty Adviser</span>
  </div>
</div>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
