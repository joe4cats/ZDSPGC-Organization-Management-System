<?php
/**
 * admin/attendance.php — attendance records for every event and organization.
 *
 * Filters (q, event, organization, status, method, date range), stat cards
 * for the current filter and a UTF-8 CSV export (?export=1) produced before
 * the layout is rendered.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Permissions::requireCapability('view_all_attendance');

$filters = [
    'q'                  => (string) (Helpers::get('q') ?? ''),
    'event_id'           => (string) (Helpers::get('event_id') ?? ''),
    'organization_id'    => (string) (Helpers::get('organization_id') ?? ''),
    'status'             => (string) (Helpers::get('status') ?? ''),
    'verification_method' => (string) (Helpers::get('verification_method') ?? ''),
    'from'               => (string) (Helpers::get('from') ?? ''),
    'to'                 => (string) (Helpers::get('to') ?? ''),
];

/* ---- CSV export (before any layout output) ---- */
if (Helpers::get('export') === '1') {
    $exportRows = AttendanceRepo::exportRows($filters);
    $columns    = [
        'Student ID', 'Student name', 'Course', 'Year level', 'Event', 'Event date',
        'Organization', 'Attendance date', 'Attendance time', 'Status',
        'Verification method', 'IP address', 'Recorded by', 'Remark',
    ];

    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="attendance-' . date('Ymd-His') . '.csv"');
        header('Pragma: no-cache');
        header('Cache-Control: no-store');
    }

    $handle = fopen('php://output', 'wb');
    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, $columns);
    foreach ($exportRows as $row) {
        $name = trim((string) $row['first_name'] . ' ' . (string) $row['middle_name'] . ' ' . (string) $row['last_name']);
        fputcsv($handle, array_map(
            static fn (mixed $value): string => Security::csvSafe((string) $value),
            [
                (string) $row['student_id'], $name, (string) $row['course'], (string) $row['year_level'],
                (string) $row['event_title'], (string) $row['event_date'], (string) $row['organization_name'],
                (string) $row['attendance_date'], (string) $row['attendance_time'], (string) $row['status'],
                (string) $row['verification_method'], (string) $row['ip_address'],
                (string) ($row['recorded_by_name'] ?? ''), (string) ($row['remark'] ?? ''),
            ]
        ));
    }
    fclose($handle);
    exit;
}

/* ---- read ---- */
$result = AttendanceRepo::list($filters, Helpers::page(), 25);
$page   = min(Helpers::page(), $result['pages']);
$rows   = $result['rows'];
$query  = http_build_query(array_filter($filters));

$withoutStatus = $filters;
unset($withoutStatus['status']);

$counts = [];
foreach (AcademicRepo::ATTENDANCE_STATUSES as $status) {
    $one            = $withoutStatus;
    $one['status']  = $status;
    $counts[$status] = AttendanceRepo::list($one, 1, 1)['total'];
}
$recordTotal   = AttendanceRepo::list($withoutStatus, 1, 1)['total'];
$activeEventId = (int) ($filters['event_id'] ?? 0);
$eventStats    = $activeEventId > 0 ? AttendanceRepo::stats($activeEventId) : null;

$orgList    = OrgRepo::list([], 1, 300)['rows'];
$orgOptions = [];
foreach ($orgList as $org) {
    $orgOptions[(int) $org['id']] = (string) $org['name'];
}

$eventOptions = [];
foreach (EventRepo::list([], 1, 200)['rows'] as $evt) {
    $eventOptions[(int) $evt['id']] = (string) $evt['title'] . ' (' . (string) $evt['event_code'] . ')';
}

$statusOptions = [];
foreach (AcademicRepo::ATTENDANCE_STATUSES as $status) {
    $statusOptions[$status] = ui_status($status);
}
$methodOptions = [];
foreach (AttendanceRepo::METHODS as $method) {
    $methodOptions[$method] = ucwords(str_replace('_', ' ', $method));
}

/* ---- page meta (before header) ---- */
$PAGE_TITLE  = 'Attendance';
$PAGE_ACTIVE = 'attendance';
$PAGE_SUB    = (int) $result['total'] . ' record(s) matching the current filters';
$PAGE_ACTIONS = '<a class="btn ghost" href="' . Helpers::e(Helpers::url('admin/attendance.php?' . http_build_query(array_filter($filters + ['export' => '1'])))) . '">'
    . icon('download', 16) . '<span>Export CSV</span></a>';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'admin/dashboard.php'], ['label' => 'Attendance']];

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="stat-grid">
  <?php if ($eventStats !== null): ?>
    <?= ui_stat('Registered', (int) $eventStats['registered'], 'users') ?>
    <?= ui_stat('Attendance rate', Helpers::percent((int) $eventStats['present'] + (int) $eventStats['late'], (int) $eventStats['total']) . '%', 'chart') ?>
  <?php else: ?>
    <?= ui_stat('Records in filter', $recordTotal, 'qr') ?>
  <?php endif; ?>
  <?= ui_stat('Present', $counts['present'], 'check-circle', $counts['present'] > 0 ? '' : 'grey') ?>
  <?= ui_stat('Late', $counts['late'], 'clock', $counts['late'] > 0 ? 'amber' : 'grey') ?>
  <?= ui_stat('Excused', $counts['excused'], 'info', $counts['excused'] > 0 ? 'blue' : 'grey') ?>
  <?= ui_stat('Absent', $counts['absent'], 'close', $counts['absent'] > 0 ? 'red' : 'grey') ?>
</div>

<?= ui_filter_form('admin/attendance.php',
    ui_filter_input('q', 'Search', $filters['q'])
    . ui_filter_select('event_id', 'Event', $eventOptions, $filters['event_id'])
    . ui_filter_select('organization_id', 'Organization', $orgOptions, $filters['organization_id'])
    . ui_filter_select('status', 'Status', $statusOptions, $filters['status'])
    . ui_filter_select('verification_method', 'Method', $methodOptions, $filters['verification_method'])
    . ui_filter_input('from', 'From', $filters['from'], 'date')
    . ui_filter_input('to', 'To', $filters['to'], 'date')) ?>

<section class="card">
  <div class="card-head">
    <h3>Attendance records</h3>
    <span class="badge blue"><?= (int) $result['total'] ?> found</span>
  </div>
  <?php if ($rows === []): ?>
    <?= ui_empty('No attendance record matches these filters.', 'Records appear here as soon as a student scans an event or student QR code.', 'qr') ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Student</th><th>ID number</th><th>Event</th><th>Organization</th>
            <th>Date &amp; time</th><th>Status</th><th>Method</th><th>IP address</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td>
                <div class="person-cell">
                  <?= ui_avatar(trim((string) $row['first_name'] . ' ' . (string) $row['last_name']), null, 32) ?>
                  <div class="person-meta">
                    <strong><?= Helpers::e(trim((string) $row['first_name'] . ' ' . (string) $row['middle_name'] . ' ' . (string) $row['last_name'])) ?></strong>
                    <small><?= Helpers::e((string) $row['course']) ?><?= $row['year_level'] !== '' ? ' · ' . Helpers::e((string) $row['year_level']) : '' ?></small>
                  </div>
                </div>
              </td>
              <td class="small nowrap"><code><?= Helpers::e((string) $row['student_id']) ?></code></td>
              <td class="small"><?= Helpers::e(Helpers::excerpt((string) $row['event_title'], 42)) ?><span class="muted"><?= Helpers::e(Helpers::fmtDate((string) $row['event_date'])) ?></span></td>
              <td class="small"><?= Helpers::e((string) $row['organization_acronym']) ?><span class="muted"><?= Helpers::e(Helpers::excerpt((string) $row['organization_name'], 36)) ?></span></td>
              <td class="small nowrap"><?= Helpers::e(Helpers::fmtDate((string) $row['attendance_date'])) ?><span class="muted"><?= Helpers::e(Helpers::fmtTime((string) $row['attendance_time'])) ?></span></td>
              <td><?= ui_status_badge((string) $row['status']) ?></td>
              <td class="small"><?= Helpers::e(ucwords(str_replace('_', ' ', (string) $row['verification_method']))) ?></td>
              <td class="small muted nowrap"><?= Helpers::e((string) $row['ip_address']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= ui_pagination($page, $result['pages'], $query) ?>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
