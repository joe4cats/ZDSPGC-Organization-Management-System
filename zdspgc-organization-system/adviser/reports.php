<?php
/**
 * adviser/reports.php — comprehensive reports for the adviser's assigned organizations.
 *
 * Covers: organization overview, members, officers, events, attendance, projects/proposals.
 * All data scoped to organizations the signed-in adviser is authorized to monitor.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireRole('adviser');

$adviserId = Permissions::adviserId();
$orgIds    = Permissions::managedOrganizationIds();
$inList    = $orgIds === [] ? 'NULL' : implode(',', array_map('intval', $orgIds));

/* ── Filters ── */
$section  = (string) (Helpers::get('section')  ?? 'organizations');
$orgId    = (int)    (Helpers::get('org_id')   ?? 0);
$yearId   = (int)    (Helpers::get('year_id')  ?? 0);
$fromDate = (string) (Helpers::get('from')     ?? '');
$toDate   = (string) (Helpers::get('to')       ?? '');
$q        = (string) (Helpers::get('q')        ?? '');
$export   = (string) (Helpers::get('export')   ?? '');

// Validate org scope
if ($orgId > 0 && !in_array($orgId, $orgIds, true)) {
    $orgId = 0;
}
$scopedOrgIds = $orgId > 0 ? [$orgId] : $orgIds;
$scopedList   = $scopedOrgIds === [] ? 'NULL' : implode(',', array_map('intval', $scopedOrgIds));

$yearOptions = AcademicRepo::yearOptions();
$orgOptions  = [];
foreach (OrgRepo::list(['adviser_id' => $adviserId], 1, 100)['rows'] as $org) {
    $orgOptions[(int) $org['id']] = (string) $org['name'];
}

/* ── Section data ── */
$rows = [];

if ($orgIds !== []) {
    switch ($section) {
        case 'organizations':
            $rows = Database::all(
                "SELECT o.*, c.name AS category_name, d.name AS department_name,
                        ay.name AS academic_year_name,
                        (SELECT COUNT(*) FROM organization_members m WHERE m.organization_id = o.id AND m.status = 'active') AS member_count,
                        (SELECT COUNT(*) FROM organization_officers oc WHERE oc.organization_id = o.id AND oc.status = 'active') AS officer_count,
                        (SELECT COUNT(*) FROM events ev WHERE ev.organization_id = o.id AND ev.status = 'approved' AND ev.event_date >= CURDATE()) AS upcoming_events
                 FROM organizations o
                 LEFT JOIN organization_categories c ON c.id = o.category_id
                 LEFT JOIN departments d ON d.id = o.department_id
                 LEFT JOIN academic_years ay ON ay.id = o.academic_year_id
                 WHERE o.id IN ($inList)
                 ORDER BY o.name ASC"
            );
            break;

        case 'members':
            $sql    = "SELECT s.student_id, s.first_name, s.last_name, s.course, s.year_level, s.section,
                              om.status AS membership_status, om.joined_at,
                              o.name AS organization_name, o.acronym AS organization_acronym,
                              ay.name AS academic_year_name
                       FROM organization_members om
                       JOIN students s ON s.id = om.student_id
                       JOIN organizations o ON o.id = om.organization_id
                       LEFT JOIN academic_years ay ON ay.id = om.academic_year_id
                       WHERE om.organization_id IN ($scopedList)";
            $params = [];
            if ($yearId > 0) {
                $sql .= ' AND om.academic_year_id = :y';
                $params['y'] = $yearId;
            }
            if ($q !== '') {
                $sql .= ' AND (s.first_name LIKE :q OR s.last_name LIKE :q2 OR s.student_id LIKE :q3)';
                $params['q'] = '%' . $q . '%';
                $params['q2'] = '%' . $q . '%';
                $params['q3'] = '%' . $q . '%';
            }
            $sql .= ' ORDER BY o.name ASC, s.last_name ASC, s.first_name ASC';
            $rows  = Database::all($sql, $params);
            break;

        case 'officers':
            $sql    = "SELECT s.student_id, s.first_name, s.last_name, s.course,
                              oo.status AS officer_status, oo.start_date, oo.end_date,
                              op.name AS position_name,
                              o.name AS organization_name, o.acronym AS organization_acronym,
                              ay.name AS academic_year_name
                       FROM organization_officers oo
                       JOIN students s ON s.id = oo.student_id
                       JOIN officer_positions op ON op.id = oo.position_id
                       JOIN organizations o ON o.id = oo.organization_id
                       LEFT JOIN academic_years ay ON ay.id = oo.academic_year_id
                       WHERE oo.organization_id IN ($scopedList)";
            $params = [];
            if ($yearId > 0) {
                $sql .= ' AND oo.academic_year_id = :y';
                $params['y'] = $yearId;
            }
            $sql .= ' ORDER BY o.name ASC, op.sort_order ASC';
            $rows  = Database::all($sql, $params);
            break;

        case 'events':
            $sql    = "SELECT ev.*, o.name AS organization_name, o.acronym AS organization_acronym,
                              ay.name AS academic_year_name,
                              (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = ev.id AND r.status = 'registered') AS registered_count,
                              (SELECT COUNT(*) FROM attendance a WHERE a.event_id = ev.id AND a.status = 'present') AS present_count
                       FROM events ev
                       JOIN organizations o ON o.id = ev.organization_id
                       LEFT JOIN academic_years ay ON ay.id = ev.academic_year_id
                       WHERE ev.organization_id IN ($scopedList)";
            $params = [];
            if ($fromDate !== '') {
                $sql .= ' AND ev.event_date >= :from';
                $params['from'] = $fromDate;
            }
            if ($toDate !== '') {
                $sql .= ' AND ev.event_date <= :to';
                $params['to'] = $toDate;
            }
            if ($q !== '') {
                $sql .= ' AND (ev.title LIKE :q OR ev.venue LIKE :q2)';
                $params['q'] = '%' . $q . '%';
                $params['q2'] = '%' . $q . '%';
            }
            $sql .= ' ORDER BY ev.event_date DESC';
            $rows  = Database::all($sql, $params);
            break;

        case 'attendance':
            $sql    = "SELECT a.status AS attendance_status, a.attendance_time AS recorded_at,
                              s.student_id, s.first_name, s.last_name,
                              ev.title AS event_title, ev.event_date, ev.venue,
                              o.name AS organization_name, o.acronym AS organization_acronym
                       FROM attendance a
                       JOIN students s ON s.id = a.student_id
                       JOIN events ev ON ev.id = a.event_id
                       JOIN organizations o ON o.id = ev.organization_id
                       WHERE ev.organization_id IN ($scopedList)";
            $params = [];
            if ($fromDate !== '') {
                $sql .= ' AND ev.event_date >= :from';
                $params['from'] = $fromDate;
            }
            if ($toDate !== '') {
                $sql .= ' AND ev.event_date <= :to';
                $params['to'] = $toDate;
            }
            if ($q !== '') {
                $sql .= ' AND (s.first_name LIKE :q OR s.last_name LIKE :q2 OR ev.title LIKE :q3)';
                $params['q'] = '%' . $q . '%';
                $params['q2'] = '%' . $q . '%';
                $params['q3'] = '%' . $q . '%';
            }
            $sql .= ' ORDER BY ev.event_date DESC, s.last_name ASC';
            $rows  = Database::all($sql, $params);
            break;

        case 'projects':
            $sql    = "SELECT pr.*, p.status AS proposal_status, p.submitted_at,
                              o.name AS organization_name, o.acronym AS organization_acronym,
                              ay.name AS academic_year_name,
                              CONCAT(s.first_name, ' ', s.last_name) AS submitter_name
                       FROM projects pr
                       LEFT JOIN proposals p ON p.project_id = pr.id
                       JOIN organizations o ON o.id = pr.organization_id
                       LEFT JOIN academic_years ay ON ay.id = pr.academic_year_id
                       LEFT JOIN students s ON s.id = p.submitted_by
                       WHERE pr.organization_id IN ($scopedList)";
            $params = [];
            if ($yearId > 0) {
                $sql .= ' AND pr.academic_year_id = :y';
                $params['y'] = $yearId;
            }
            if ($q !== '') {
                $sql .= ' AND pr.title LIKE :q';
                $params['q'] = '%' . $q . '%';
            }
            $sql .= ' ORDER BY pr.created_at DESC';
            $rows  = Database::all($sql, $params);
            break;
    }
}

/* ── CSV export ── */
if ($export === 'csv' && $rows !== []) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="adviser-report-' . $section . '-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array_keys($rows[0]));
    foreach ($rows as $row) {
        fputcsv($out, array_values($row));
    }
    fclose($out);
    exit;
}

/* ── Attendance summary stats ── */
$attendanceSummary = [];
if ($section === 'attendance' && $rows !== []) {
    $statuses = array_column($rows, 'attendance_status');
    $attendanceSummary = [
        'total'   => count($rows),
        'present' => count(array_filter($statuses, fn ($s) => $s === 'present')),
        'late'    => count(array_filter($statuses, fn ($s) => $s === 'late')),
        'absent'  => count(array_filter($statuses, fn ($s) => $s === 'absent')),
    ];
    $attendanceSummary['pct'] = $attendanceSummary['total'] > 0
        ? round(($attendanceSummary['present'] + $attendanceSummary['late']) / $attendanceSummary['total'] * 100)
        : 0;
}

$PAGE_TITLE       = 'Reports';
$PAGE_ACTIVE      = 'reports';
$PAGE_SUB         = 'Monitoring reports for your assigned organizations.';
$PAGE_BREADCRUMBS = [
    ['label' => 'Dashboard', 'href' => 'adviser/dashboard.php'],
    ['label' => 'Reports'],
];
$PAGE_PRINT = isset($_GET['print']);
require __DIR__ . '/../includes/layout/header.php';
?>

<?php if ($orgIds === []): ?>
  <section class="card">
    <?= ui_empty('No organizations are assigned to you yet.',
        'Reports will be available once the Office of Student Affairs assigns an organization.',
        'chart') ?>
  </section>
<?php else: ?>

<!-- ── Section tabs ── -->
<nav class="tab-nav" aria-label="Report sections">
  <?php
  $tabs = [
    'organizations' => ['label' => 'Organizations', 'icon' => 'org'],
    'members'       => ['label' => 'Members',       'icon' => 'users'],
    'officers'      => ['label' => 'Officers',      'icon' => 'award'],
    'events'        => ['label' => 'Events',        'icon' => 'calendar'],
    'attendance'    => ['label' => 'Attendance',    'icon' => 'doc-check'],
    'projects'      => ['label' => 'Projects',      'icon' => 'layers'],
  ];
  foreach ($tabs as $key => $tab):
  ?>
  <a href="<?= Helpers::e(Helpers::url('adviser/reports.php?section=' . $key
      . ($orgId ? '&org_id=' . $orgId : '')
      . ($yearId ? '&year_id=' . $yearId : ''))) ?>"
     class="<?= $section === $key ? 'active' : '' ?>">
    <?= icon($tab['icon'], 15) ?> <?= Helpers::e($tab['label']) ?>
  </a>
  <?php endforeach; ?>
</nav>

<!-- ── Filters ── -->
<form class="filter-bar" method="get" action="<?= Helpers::e(Helpers::url('adviser/reports.php')) ?>">
  <input type="hidden" name="section" value="<?= Helpers::e($section) ?>">
  <?php if (count($orgOptions) > 1): ?>
    <?= ui_filter_select('org_id', 'Organization', $orgOptions, (string)$orgId, 'All organizations') ?>
  <?php endif; ?>
  <?php if (in_array($section, ['members', 'officers', 'projects'], true)): ?>
    <?= ui_filter_select('year_id', 'Academic year', $yearOptions, (string)$yearId, 'All years') ?>
  <?php endif; ?>
  <?php if (in_array($section, ['events', 'attendance'], true)): ?>
    <?= ui_filter_input('from', 'From date', $fromDate, 'date') ?>
    <?= ui_filter_input('to',   'To date',   $toDate,   'date') ?>
  <?php endif; ?>
  <?php if (in_array($section, ['members', 'events', 'attendance', 'projects'], true)): ?>
    <?= ui_filter_input('q', 'Search', $q) ?>
  <?php endif; ?>
  <div class="filter-actions">
    <button class="btn sm" type="submit"><?= icon('filter', 15) ?><span>Apply</span></button>
    <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('adviser/reports.php?section=' . $section)) ?>">Reset</a>
    <?php if ($rows !== []): ?>
      <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('adviser/reports.php?' . http_build_query(array_merge(
          ['section' => $section, 'org_id' => $orgId, 'year_id' => $yearId,
           'from' => $fromDate, 'to' => $toDate, 'q' => $q, 'export' => 'csv']
      )))) ?>"><?= icon('download', 15) ?><span>CSV</span></a>
      <a class="btn sm ghost no-print" href="?<?= Helpers::e(http_build_query(array_merge(
          $_GET, ['print' => '1']
      ))) ?>" target="_blank"><?= icon('print', 15) ?><span>Print</span></a>
    <?php endif; ?>
  </div>
</form>

<!-- ── Attendance summary ── -->
<?php if ($section === 'attendance' && $rows !== []): ?>
  <div class="stat-grid">
    <?= ui_stat('Total records',  $attendanceSummary['total'],   'users') ?>
    <?= ui_stat('Present',        $attendanceSummary['present'], 'check-circle', 'green') ?>
    <?= ui_stat('Late',           $attendanceSummary['late'],    'clock', 'amber') ?>
    <?= ui_stat('Absent',         $attendanceSummary['absent'],  'close', 'red') ?>
    <?= ui_stat('Attendance rate', $attendanceSummary['pct'] . '%', 'chart',
        $attendanceSummary['pct'] >= 75 ? 'green' : ($attendanceSummary['pct'] >= 50 ? 'amber' : 'red')) ?>
  </div>
<?php endif; ?>

<!-- ── Main table ── -->
<section class="card">
  <div class="card-head">
    <h3><?= Helpers::e($tabs[$section]['label'] ?? $section) ?> Report</h3>
    <span class="badge grey"><?= count($rows) ?> record(s)</span>
  </div>

  <?php if ($rows === []): ?>
    <?= ui_empty('No records found for the selected filters.',
        'Try adjusting the organization, date, or year filters.',
        $tabs[$section]['icon'] ?? 'chart') ?>
  <?php else: ?>
    <div class="table-wrap">
    <?php switch ($section):
      case 'organizations': ?>
        <table class="tbl">
          <thead><tr>
            <th>Organization</th><th>Type</th><th>Status</th>
            <th class="num">Members</th><th class="num">Officers</th><th class="num">Upcoming events</th>
          </tr></thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><strong><?= Helpers::e((string)$r['name']) ?></strong>
                    <?php if ((string)$r['acronym'] !== ''): ?>
                      <span class="muted small"> · <?= Helpers::e((string)$r['acronym']) ?></span>
                    <?php endif; ?></td>
                <td class="small"><?= Helpers::e((string)($r['category_name'] ?? '—')) ?></td>
                <td><?= ui_status_badge((string)$r['status']) ?></td>
                <td class="num"><?= (int)$r['member_count'] ?></td>
                <td class="num"><?= (int)$r['officer_count'] ?></td>
                <td class="num"><?= (int)$r['upcoming_events'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php break; case 'members': ?>
        <table class="tbl">
          <thead><tr>
            <th>Student</th><th>Course</th><th>Organization</th>
            <th>Academic Year</th><th>Status</th><th>Joined</th>
          </tr></thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><strong><?= Helpers::e((string)$r['last_name']) ?>, <?= Helpers::e((string)$r['first_name']) ?></strong>
                    <br><span class="muted small"><?= Helpers::e((string)$r['student_id']) ?></span></td>
                <td class="small"><?= Helpers::e((string)$r['course']) ?> · <?= Helpers::e((string)$r['year_level']) ?></td>
                <td class="small"><?= Helpers::e((string)$r['organization_acronym']) ?></td>
                <td class="small"><?= Helpers::e((string)($r['academic_year_name'] ?? '—')) ?></td>
                <td><?= ui_status_badge((string)$r['membership_status']) ?></td>
                <td class="small nowrap"><?= Helpers::e(Helpers::fmtDate((string)$r['joined_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php break; case 'officers': ?>
        <table class="tbl">
          <thead><tr>
            <th>Officer</th><th>Position</th><th>Organization</th>
            <th>Academic Year</th><th>Status</th><th>Term</th>
          </tr></thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><strong><?= Helpers::e((string)$r['last_name']) ?>, <?= Helpers::e((string)$r['first_name']) ?></strong>
                    <br><span class="muted small"><?= Helpers::e((string)$r['student_id']) ?></span></td>
                <td class="small"><?= Helpers::e((string)$r['position_name']) ?></td>
                <td class="small"><?= Helpers::e((string)$r['organization_acronym']) ?></td>
                <td class="small"><?= Helpers::e((string)($r['academic_year_name'] ?? '—')) ?></td>
                <td><?= ui_status_badge((string)$r['officer_status']) ?></td>
                <td class="small nowrap">
                  <?= Helpers::e(Helpers::fmtDate((string)$r['start_date'])) ?>
                  <?= $r['end_date'] ? ' — ' . Helpers::e(Helpers::fmtDate((string)$r['end_date'])) : '' ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php break; case 'events': ?>
        <table class="tbl">
          <thead><tr>
            <th>Event</th><th>Organization</th><th>Date</th>
            <th>Venue</th><th>Status</th><th class="num">Registered</th><th class="num">Present</th>
          </tr></thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><strong><?= Helpers::e(Helpers::excerpt((string)$r['title'], 50)) ?></strong></td>
                <td class="small"><?= Helpers::e((string)$r['organization_acronym']) ?></td>
                <td class="small nowrap"><?= Helpers::e(Helpers::fmtDate((string)$r['event_date'])) ?></td>
                <td class="small"><?= Helpers::e(Helpers::excerpt((string)$r['venue'], 40)) ?></td>
                <td><?= ui_status_badge((string)$r['status']) ?></td>
                <td class="num"><?= (int)$r['registered_count'] ?></td>
                <td class="num"><?= (int)$r['present_count'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php break; case 'attendance': ?>
        <table class="tbl">
          <thead><tr>
            <th>Student</th><th>Event</th><th>Organization</th>
            <th>Date</th><th>Status</th>
          </tr></thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><strong><?= Helpers::e((string)$r['last_name']) ?>, <?= Helpers::e((string)$r['first_name']) ?></strong>
                    <br><span class="muted small"><?= Helpers::e((string)$r['student_id']) ?></span></td>
                <td class="small"><?= Helpers::e(Helpers::excerpt((string)$r['event_title'], 45)) ?></td>
                <td class="small"><?= Helpers::e((string)$r['organization_acronym']) ?></td>
                <td class="small nowrap"><?= Helpers::e(Helpers::fmtDate((string)$r['event_date'])) ?></td>
                <td><?= ui_status_badge((string)$r['attendance_status']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php break; case 'projects': ?>
        <table class="tbl">
          <thead><tr>
            <th>Project / Activity</th><th>Organization</th><th>Academic Year</th>
            <th>Status</th><th>Proposal status</th><th>Submitted by</th>
          </tr></thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><strong><?= Helpers::e(Helpers::excerpt((string)$r['title'], 50)) ?></strong></td>
                <td class="small"><?= Helpers::e((string)$r['organization_acronym']) ?></td>
                <td class="small"><?= Helpers::e((string)($r['academic_year_name'] ?? '—')) ?></td>
                <td><?= ui_status_badge((string)$r['status']) ?></td>
                <td><?= ui_status_badge((string)($r['proposal_status'] ?? 'draft')) ?></td>
                <td class="small"><?= Helpers::e((string)($r['submitter_name'] ?? '—')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php break; endswitch; ?>
    </div>
  <?php endif; ?>
</section>

<?php endif; ?>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
