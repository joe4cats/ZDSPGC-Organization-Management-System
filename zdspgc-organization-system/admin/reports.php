<?php
/**
 * admin/reports.php — institution-wide reporting for System Administrators.
 *
 * Covers: organizations, membership, officers, events, attendance, projects/proposals.
 * Provides live filtering, CSV exports, print formatting, and full audit integration.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireRole('admin');
Permissions::requireCapability('view_reports');

/* ── Filters ── */
$section  = (string) (Helpers::get('section')  ?? 'organizations');
$orgId    = (int)    (Helpers::get('org_id')   ?? 0);
$yearId   = (int)    (Helpers::get('year_id')  ?? 0);
$deptId   = (int)    (Helpers::get('dept_id')  ?? 0);
$catId    = (int)    (Helpers::get('cat_id')   ?? 0);
$fromDate = (string) (Helpers::get('from')     ?? '');
$toDate   = (string) (Helpers::get('to')       ?? '');
$q        = (string) (Helpers::get('q')        ?? '');
$export   = (string) (Helpers::get('export')   ?? '');

$yearOptions = AcademicRepo::yearOptions();
$deptOptions = AcademicRepo::departmentOptions();
$catOptions  = AcademicRepo::categoryOptions();
$orgOptions  = [];
foreach (Database::all('SELECT id, name, acronym FROM organizations ORDER BY name') as $o) {
    $orgOptions[(int) $o['id']] = (string) ($o['acronym'] ? $o['acronym'] . ' — ' . $o['name'] : $o['name']);
}

/* ── Section data query ── */
$rows = [];

switch ($section) {
    case 'organizations':
        $w = ['1=1'];
        $p = [];
        if ($catId > 0) { $w[] = 'o.category_id = :cat'; $p['cat'] = $catId; }
        if ($deptId > 0) { $w[] = 'o.department_id = :dept'; $p['dept'] = $deptId; }
        if ($q !== '') {
            $w[] = '(o.name LIKE :q OR o.acronym LIKE :q2 OR o.organization_code LIKE :q3)';
            $p['q'] = '%' . $q . '%'; $p['q2'] = '%' . $q . '%'; $p['q3'] = '%' . $q . '%';
        }
        $rows = Database::all(
            "SELECT o.*, c.name AS category_name, d.name AS department_name,
                    ay.name AS academic_year_name,
                    u_adv.full_name AS adviser_name,
                    s_pres.first_name AS pres_first, s_pres.last_name AS pres_last,
                    (SELECT COUNT(*) FROM organization_members m WHERE m.organization_id = o.id AND m.status = 'active') AS member_count,
                    (SELECT COUNT(*) FROM organization_officers off WHERE off.organization_id = o.id AND off.status = 'active') AS officer_count,
                    (SELECT COUNT(*) FROM events e WHERE e.organization_id = o.id) AS event_count
             FROM organizations o
             LEFT JOIN organization_categories c ON c.id = o.category_id
             LEFT JOIN departments d ON d.id = o.department_id
             LEFT JOIN academic_years ay ON ay.id = o.academic_year_id
             LEFT JOIN advisers adv ON adv.id = o.adviser_id
             LEFT JOIN users u_adv ON u_adv.id = adv.user_id
             LEFT JOIN students s_pres ON s_pres.id = o.president_id
             WHERE " . implode(' AND ', $w) . "
             ORDER BY o.name ASC",
            $p
        );
        break;

    case 'members':
        $w = ['1=1'];
        $p = [];
        if ($orgId > 0) { $w[] = 'm.organization_id = :org'; $p['org'] = $orgId; }
        if ($yearId > 0) { $w[] = 'm.academic_year_id = :yr'; $p['yr'] = $yearId; }
        if ($q !== '') {
            $w[] = '(s.first_name LIKE :q OR s.last_name LIKE :q2 OR s.student_id LIKE :q3 OR o.name LIKE :q4)';
            $p['q'] = '%' . $q . '%'; $p['q2'] = '%' . $q . '%'; $p['q3'] = '%' . $q . '%'; $p['q4'] = '%' . $q . '%';
        }
        $rows = Database::all(
            "SELECT m.*, s.student_id, s.first_name, s.last_name, s.course, s.year_level, s.email,
                    o.name AS organization_name, o.acronym AS org_acronym,
                    ay.name AS academic_year_name, p.name AS position_name
             FROM organization_members m
             JOIN students s ON s.id = m.student_id
             JOIN organizations o ON o.id = m.organization_id
             LEFT JOIN academic_years ay ON ay.id = m.academic_year_id
             LEFT JOIN officer_positions p ON p.id = m.position_id
             WHERE " . implode(' AND ', $w) . "
             ORDER BY o.name ASC, s.last_name ASC",
            $p
        );
        break;

    case 'officers':
        $w = ['1=1'];
        $p = [];
        if ($orgId > 0) { $w[] = 'off.organization_id = :org'; $p['org'] = $orgId; }
        if ($yearId > 0) { $w[] = 'off.academic_year_id = :yr'; $p['yr'] = $yearId; }
        if ($q !== '') {
            $w[] = '(s.first_name LIKE :q OR s.last_name LIKE :q2 OR s.student_id LIKE :q3 OR o.name LIKE :q4)';
            $p['q'] = '%' . $q . '%'; $p['q2'] = '%' . $q . '%'; $p['q3'] = '%' . $q . '%'; $p['q4'] = '%' . $q . '%';
        }
        $rows = Database::all(
            "SELECT off.*, s.student_id, s.first_name, s.last_name, s.course, s.year_level,
                    p.name AS position_name, p.sort_order,
                    o.name AS organization_name, o.acronym AS org_acronym,
                    ay.name AS academic_year_name
             FROM organization_officers off
             JOIN students s ON s.id = off.student_id
             JOIN officer_positions p ON p.id = off.position_id
             JOIN organizations o ON o.id = off.organization_id
             LEFT JOIN academic_years ay ON ay.id = off.academic_year_id
             WHERE " . implode(' AND ', $w) . "
             ORDER BY o.name ASC, p.sort_order ASC, s.last_name ASC",
            $p
        );
        break;

    case 'events':
        $w = ['1=1'];
        $p = [];
        if ($orgId > 0) { $w[] = 'e.organization_id = :org'; $p['org'] = $orgId; }
        if ($yearId > 0) { $w[] = 'e.academic_year_id = :yr'; $p['yr'] = $yearId; }
        if ($fromDate !== '') { $w[] = 'e.event_date >= :fd'; $p['fd'] = $fromDate; }
        if ($toDate !== '') { $w[] = 'e.event_date <= :td'; $p['td'] = $toDate; }
        if ($q !== '') {
            $w[] = '(e.title LIKE :q OR e.venue LIKE :q2 OR o.name LIKE :q3)';
            $p['q'] = '%' . $q . '%'; $p['q2'] = '%' . $q . '%'; $p['q3'] = '%' . $q . '%';
        }
        $rows = Database::all(
            "SELECT e.*, o.name AS organization_name, o.acronym AS org_acronym,
                    ay.name AS academic_year_name,
                    (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id) AS reg_count,
                    (SELECT COUNT(*) FROM attendance a WHERE a.event_id = e.id AND a.status IN ('present','late')) AS att_count
             FROM events e
             JOIN organizations o ON o.id = e.organization_id
             LEFT JOIN academic_years ay ON ay.id = e.academic_year_id
             WHERE " . implode(' AND ', $w) . "
             ORDER BY e.event_date DESC",
            $p
        );
        break;

    case 'attendance':
        $w = ['1=1'];
        $p = [];
        if ($orgId > 0) { $w[] = 'e.organization_id = :org'; $p['org'] = $orgId; }
        if ($fromDate !== '') { $w[] = 'e.event_date >= :fd'; $p['fd'] = $fromDate; }
        if ($toDate !== '') { $w[] = 'e.event_date <= :td'; $p['td'] = $toDate; }
        if ($q !== '') {
            $w[] = '(s.first_name LIKE :q OR s.last_name LIKE :q2 OR s.student_id LIKE :q3 OR e.title LIKE :q4)';
            $p['q'] = '%' . $q . '%'; $p['q2'] = '%' . $q . '%'; $p['q3'] = '%' . $q . '%'; $p['q4'] = '%' . $q . '%';
        }
        $rows = Database::all(
            "SELECT a.*, s.student_id, s.first_name, s.last_name, s.course, s.year_level,
                    e.title AS event_title, e.event_date,
                    o.name AS organization_name, o.acronym AS org_acronym,
                    u_rec.full_name AS recorder_name
             FROM attendance a
             JOIN students s ON s.id = a.student_id
             JOIN events e ON e.id = a.event_id
             JOIN organizations o ON o.id = e.organization_id
             LEFT JOIN users u_rec ON u_rec.id = a.recorded_by
             WHERE " . implode(' AND ', $w) . "
             ORDER BY a.attendance_time DESC",
            $p
        );
        break;

    case 'projects':
        $w = ['1=1'];
        $p = [];
        if ($orgId > 0) { $w[] = 'p.organization_id = :org'; $p['org'] = $orgId; }
        if ($yearId > 0) { $w[] = 'p.academic_year_id = :yr'; $p['yr'] = $yearId; }
        if ($q !== '') {
            $w[] = '(p.title LIKE :q OR o.name LIKE :q2)';
            $p['q'] = '%' . $q . '%'; $p['q2'] = '%' . $q . '%';
        }
        $rows = Database::all(
            "SELECT p.*, o.name AS organization_name, o.acronym AS org_acronym,
                    ay.name AS academic_year_name, u.full_name AS submitter_name
             FROM activity_proposals p
             JOIN organizations o ON o.id = p.organization_id
             LEFT JOIN academic_years ay ON ay.id = p.academic_year_id
             LEFT JOIN users u ON u.id = p.submitted_by
             WHERE " . implode(' AND ', $w) . "
             ORDER BY p.submitted_at DESC",
            $p
        );
        break;
}

/* ── CSV Export ── */
if ($export === 'csv') {
    $filename = 'zdspgc-' . $section . '-report-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');

    switch ($section) {
        case 'organizations':
            fputcsv($out, ['Code', 'Organization Name', 'Acronym', 'Category', 'Department', 'Adviser', 'President', 'Members', 'Officers', 'Events', 'Status', 'Accreditation']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['organization_code'],
                    $r['name'],
                    $r['acronym'],
                    $r['category_name'] ?? '—',
                    $r['department_name'] ?? 'General',
                    $r['adviser_name'] ?? 'None',
                    trim(($r['pres_first'] ?? '') . ' ' . ($r['pres_last'] ?? '')) ?: 'None',
                    $r['member_count'],
                    $r['officer_count'],
                    $r['event_count'],
                    $r['status'],
                    $r['accreditation_status'],
                ]);
            }
            break;

        case 'members':
            fputcsv($out, ['Student ID', 'Last Name', 'First Name', 'Course', 'Year Level', 'Organization', 'Position', 'Status', 'Joined Date']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['student_id'],
                    $r['last_name'],
                    $r['first_name'],
                    $r['course'],
                    $r['year_level'],
                    $r['org_acronym'] ?: $r['organization_name'],
                    $r['position_name'] ?? 'Member',
                    $r['status'],
                    $r['joined_at'] ?? $r['applied_at'],
                ]);
            }
            break;

        case 'officers':
            fputcsv($out, ['Organization', 'Position', 'Student ID', 'Officer Name', 'Course', 'Year Level', 'Term', 'Status']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['org_acronym'] ?: $r['organization_name'],
                    $r['position_name'],
                    $r['student_id'],
                    $r['last_name'] . ', ' . $r['first_name'],
                    $r['course'],
                    $r['year_level'],
                    $r['term'] ?? $r['academic_year_name'],
                    $r['status'],
                ]);
            }
            break;

        case 'events':
            fputcsv($out, ['Event Code', 'Title', 'Organization', 'Date', 'Time', 'Venue', 'Status', 'Registrations', 'Attendance']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['event_code'],
                    $r['title'],
                    $r['org_acronym'] ?: $r['organization_name'],
                    $r['event_date'],
                    $r['start_time'] . ' - ' . $r['end_time'],
                    $r['venue'],
                    $r['status'],
                    $r['reg_count'],
                    $r['att_count'],
                ]);
            }
            break;

        case 'attendance':
            fputcsv($out, ['Event', 'Event Date', 'Organization', 'Student ID', 'Student Name', 'Course', 'Time In', 'Status', 'Recorded By']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['event_title'],
                    $r['event_date'],
                    $r['org_acronym'] ?: $r['organization_name'],
                    $r['student_id'],
                    $r['last_name'] . ', ' . $r['first_name'],
                    $r['course'],
                    $r['attendance_time'],
                    $r['status'],
                    $r['recorder_name'] ?? 'Self / Scanner',
                ]);
            }
            break;

        case 'projects':
            fputcsv($out, ['Proposal ID', 'Organization', 'Title', 'Target Date', 'Budget', 'Status', 'Submitted By', 'Submitted At']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['id'],
                    $r['org_acronym'] ?: $r['organization_name'],
                    $r['title'],
                    $r['target_date'] ?? '—',
                    $r['budget'] ? number_format((float) $r['budget'], 2) : '0.00',
                    $r['status'],
                    $r['submitter_name'] ?? '—',
                    $r['submitted_at'],
                ]);
            }
            break;
    }
    fclose($out);
    exit;
}

$sectionLabels = [
    'organizations' => 'Organizations',
    'members'       => 'Membership',
    'officers'      => 'Officers',
    'events'        => 'Events',
    'attendance'    => 'Attendance',
    'projects'      => 'Projects & Proposals',
];

$PAGE_TITLE       = 'Institutional Reports';
$PAGE_ACTIVE      = 'reports';
$PAGE_SUB         = 'Comprehensive data analysis and export for college administration';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'admin/dashboard.php'], ['label' => 'Reports']];
$PAGE_ACTIONS     = '<div class="btn-row">'
    . '<a class="btn sm" href="' . Helpers::e(Helpers::url('admin/reports.php?' . http_build_query(array_merge($_GET, ['export' => 'csv'])))) . '">'
    . icon('download', 15) . '<span>Export CSV</span></a>'
    . '<button class="btn sm ghost" onclick="window.print()">'
    . icon('printer', 15) . '<span>Print Sheet</span></button>'
    . '</div>';

require __DIR__ . '/../includes/layout/header.php';
?>

<!-- Section Switcher Tabs -->
<div class="tabs mb-4">
  <?php foreach ($sectionLabels as $key => $label): ?>
    <a href="<?= Helpers::e(Helpers::url('admin/reports.php?section=' . $key)) ?>"
       class="tab <?= $section === $key ? 'active' : '' ?>">
      <?= Helpers::e($label) ?>
    </a>
  <?php endforeach; ?>
</div>

<section class="card mb-4 no-print">
  <form method="get" action="<?= Helpers::e(Helpers::url('admin/reports.php')) ?>" class="filter-bar">
    <input type="hidden" name="section" value="<?= Helpers::e($section) ?>">

    <div class="filter-field">
      <input type="search" name="q" value="<?= Helpers::e($q) ?>" placeholder="Search records...">
    </div>

    <?php if ($section !== 'organizations'): ?>
      <div class="filter-field">
        <select name="org_id">
          <option value="">All Organizations</option>
          <?php foreach ($orgOptions as $id => $name): ?>
            <option value="<?= $id ?>" <?= $orgId === $id ? 'selected' : '' ?>><?= Helpers::e($name) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>

    <?php if (in_array($section, ['members', 'officers', 'events', 'projects'], true)): ?>
      <div class="filter-field">
        <select name="year_id">
          <option value="">All Academic Years</option>
          <?php foreach ($yearOptions as $id => $name): ?>
            <option value="<?= $id ?>" <?= $yearId === $id ? 'selected' : '' ?>><?= Helpers::e($name) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>

    <?php if ($section === 'organizations'): ?>
      <div class="filter-field">
        <select name="cat_id">
          <option value="">All Categories</option>
          <?php foreach ($catOptions as $id => $name): ?>
            <option value="<?= $id ?>" <?= $catId === $id ? 'selected' : '' ?>><?= Helpers::e($name) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-field">
        <select name="dept_id">
          <option value="">All Departments</option>
          <?php foreach ($deptOptions as $id => $name): ?>
            <option value="<?= $id ?>" <?= $deptId === $id ? 'selected' : '' ?>><?= Helpers::e($name) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>

    <?php if (in_array($section, ['events', 'attendance'], true)): ?>
      <div class="filter-field">
        <input type="date" name="from" value="<?= Helpers::e($fromDate) ?>" title="From date">
      </div>
      <div class="filter-field">
        <input type="date" name="to" value="<?= Helpers::e($toDate) ?>" title="To date">
      </div>
    <?php endif; ?>

    <div class="filter-actions">
      <button class="btn sm" type="submit"><?= icon('search', 15) ?><span>Apply</span></button>
      <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('admin/reports.php?section=' . $section)) ?>"><?= icon('refresh', 15) ?><span>Reset</span></a>
    </div>
  </form>
</section>

<!-- Print Document Header -->
<div class="print-doc-head">
  <div class="print-doc-brand">
    <img src="<?= Helpers::e(Helpers::url('assets/img/logo.png')) ?>" alt="Seal">
  </div>
  <div>
    <div class="print-doc-unit"><?= Helpers::e(SCHOOL_NAME) ?> · Office of Student Affairs</div>
    <h1><?= Helpers::e($sectionLabels[$section] ?? 'Institutional Report') ?></h1>
    <div class="small muted">Generated on <?= date('F j, Y, g:i a') ?> by <?= Helpers::e(Auth::user()['full_name'] ?? 'Administrator') ?></div>
  </div>
  <div class="print-doc-meta">
    Total records: <?= count($rows) ?><br>
    Academic Year: <?= Helpers::e(AcademicRepo::activeYear()['name'] ?? 'Current') ?>
  </div>
</div>

<section class="card">
  <div class="card-head">
    <h3><?= Helpers::e($sectionLabels[$section]) ?> Data Table</h3>
    <span class="muted small"><?= count($rows) ?> record(s)</span>
  </div>

  <?php if ($rows === []): ?>
    <?= ui_empty('No records found for this report.', 'Try broadening your search or date criteria.', 'chart') ?>
  <?php else: ?>
    <div class="table-wrap">
      <?php if ($section === 'organizations'): ?>
        <table class="tbl">
          <thead>
            <tr>
              <th>Code</th>
              <th>Organization Name</th>
              <th>Category</th>
              <th>Department</th>
              <th>Adviser</th>
              <th>President</th>
              <th>Members</th>
              <th>Officers</th>
              <th>Status</th>
              <th>Accreditation</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><code><?= Helpers::e((string) $r['organization_code']) ?></code></td>
                <td>
                  <strong><?= Helpers::e((string) $r['name']) ?></strong>
                  <?php if (!empty($r['acronym'])): ?><span class="muted small">(<?= Helpers::e((string) $r['acronym']) ?>)</span><?php endif; ?>
                </td>
                <td><?= Helpers::e((string) ($r['category_name'] ?? '—')) ?></td>
                <td><?= Helpers::e((string) ($r['department_name'] ?? 'General')) ?></td>
                <td><?= Helpers::e((string) ($r['adviser_name'] ?? 'None')) ?></td>
                <td><?= Helpers::e(trim(($r['pres_first'] ?? '') . ' ' . ($r['pres_last'] ?? '')) ?: 'None') ?></td>
                <td><span class="badge blue"><?= (int) $r['member_count'] ?></span></td>
                <td><span class="badge grey"><?= (int) $r['officer_count'] ?></span></td>
                <td><?= ui_status_badge((string) $r['status']) ?></td>
                <td><?= ui_status_badge((string) $r['accreditation_status']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

      <?php elseif ($section === 'members'): ?>
        <table class="tbl">
          <thead>
            <tr>
              <th>Student ID</th>
              <th>Full Name</th>
              <th>Course / Year</th>
              <th>Organization</th>
              <th>Position</th>
              <th>Academic Year</th>
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
                <td><?= Helpers::e((string) ($r['org_acronym'] ?: $r['organization_name'])) ?></td>
                <td><?= ui_badge((string) ($r['position_name'] ?? 'Member'), 'blue') ?></td>
                <td><?= Helpers::e((string) $r['academic_year_name']) ?></td>
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
              <th>Course & Year</th>
              <th>Organization</th>
              <th>Term / Academic Year</th>
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
                <td><?= Helpers::e((string) ($r['org_acronym'] ?: $r['organization_name'])) ?></td>
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
              <th>Organization</th>
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
                <td><?= Helpers::e((string) ($r['org_acronym'] ?: $r['organization_name'])) ?></td>
                <td>
                  <?= Helpers::e(Helpers::fmtDate((string) $r['event_date'])) ?><br>
                  <span class="muted small"><?= Helpers::e(Helpers::fmtTime((string) $r['start_time'])) ?> - <?= Helpers::e(Helpers::fmtTime((string) $r['end_time'])) ?></span>
                </td>
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
              <th>Organization</th>
              <th>Student</th>
              <th>Check-in Time</th>
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
                <td><?= Helpers::e((string) ($r['org_acronym'] ?: $r['organization_name'])) ?></td>
                <td>
                  <strong><?= Helpers::e((string) $r['last_name'] . ', ' . (string) $r['first_name']) ?></strong>
                  <span class="muted small d-block"><code><?= Helpers::e((string) $r['student_id']) ?></code> · <?= Helpers::e((string) $r['course']) ?></span>
                </td>
                <td><?= Helpers::e((string) $r['attendance_time']) ?></td>
                <td><?= ui_status_badge((string) $r['status']) ?></td>
                <td><?= Helpers::e((string) ($r['recorder_name'] ?? 'Scanner Deep-link')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

      <?php elseif ($section === 'projects'): ?>
        <table class="tbl">
          <thead>
            <tr>
              <th>Proposal Title</th>
              <th>Organization</th>
              <th>Target Date</th>
              <th>Budget</th>
              <th>Submitted By</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><strong><?= Helpers::e((string) $r['title']) ?></strong></td>
                <td><?= Helpers::e((string) ($r['org_acronym'] ?: $r['organization_name'])) ?></td>
                <td><?= Helpers::e(Helpers::fmtDate((string) ($r['target_date'] ?? ''))) ?></td>
                <td>₱<?= number_format((float) ($r['budget'] ?? 0), 2) ?></td>
                <td><?= Helpers::e((string) ($r['submitter_name'] ?? '—')) ?></td>
                <td><?= ui_status_badge((string) $r['status']) ?></td>
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
    <span><?= Helpers::e(Auth::user()['full_name'] ?? 'System Administrator') ?></span><br>
    <span class="small muted"><?= Helpers::e(Permissions::ROLE_LABELS[Auth::role() ?? ''] ?? '') ?></span>
  </div>
  <div class="sig">
    <span class="sig-line"></span>
    <b>Verified By</b><br>
    <span>Office of Student Affairs</span><br>
    <span class="small muted">Director / Coordinator</span>
  </div>
  <div class="sig">
    <span class="sig-line"></span>
    <b>Approved By</b><br>
    <span>College President / VP for Academic Affairs</span><br>
    <span class="small muted">ZDSPGC</span>
  </div>
</div>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
