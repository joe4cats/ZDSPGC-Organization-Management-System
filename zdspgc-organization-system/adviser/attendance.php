<?php
/**
 * adviser/attendance.php — attendance monitoring for events in assigned organizations.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireRole('adviser');
Permissions::requireCapability('view_attendance');

$adviserId = Permissions::adviserId();
$orgIds    = Permissions::managedOrganizationIds();
$inList    = $orgIds === [] ? 'NULL' : implode(',', array_map('intval', $orgIds));

/* POST: approve / reject a pending check-in request (runs before output). */
if (Helpers::isPost() && (string) (Helpers::post('checkin_decide') ?? '') === '1') {
    Security::requireCsrf();
    $requestId = Helpers::postInt('request_id');
    $decision  = (string) (Helpers::post('decision') ?? '') === 'approve' ? 'approve' : 'reject';
    $target    = Database::one(
        'SELECT e.organization_id FROM checkin_requests cr JOIN events e ON e.id = cr.event_id WHERE cr.id = :id',
        ['id' => $requestId]
    );
    if ($target === null || !in_array((int) $target['organization_id'], $orgIds, true)) {
        Helpers::flash('error', 'That request does not belong to your organizations.');
    } else {
        $result = AttendanceRepo::decideRequest($requestId, $decision);
        Helpers::flash($result['ok'] ? 'success' : 'error', $result['message']);
    }
    Helpers::redirect('adviser/attendance.php');
}

$pendingRequests = AttendanceRepo::pendingRequests($orgIds);

$q        = (string) (Helpers::get('q')        ?? '');
$orgId    = (int)    (Helpers::get('org_id')   ?? 0);
$fromDate = (string) (Helpers::get('from')     ?? '');
$toDate   = (string) (Helpers::get('to')       ?? '');
$yearId   = (int)    (Helpers::get('year_id')  ?? 0);
$page     = max(1, (int) (Helpers::get('page') ?? 1));
$perPage  = 30;

if ($orgId > 0 && !in_array($orgId, $orgIds, true)) {
    $orgId = 0;
}
$scopedList = $orgId > 0 ? (string)$orgId : $inList;

$sql    = "SELECT a.status AS attendance_status, a.attendance_time AS recorded_at,
                  s.student_id, s.first_name, s.last_name, s.course, s.year_level,
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
if ($yearId > 0) {
    $sql .= ' AND ev.academic_year_id = :y';
    $params['y'] = $yearId;
}
if ($q !== '') {
    $sql .= ' AND (s.first_name LIKE :q OR s.last_name LIKE :q2 OR s.student_id LIKE :q3 OR ev.title LIKE :q4)';
    $like = '%' . $q . '%';
    $params['q'] = $like;
    $params['q2'] = $like;
    $params['q3'] = $like;
    $params['q4'] = $like;
}

$total  = (int) Database::scalar('SELECT COUNT(*) FROM (' . $sql . ') AS cnt', $params);
$pages  = (int) max(1, ceil($total / $perPage));
$page   = min($page, $pages);
$records = Database::all($sql . ' ORDER BY ev.event_date DESC, s.last_name ASC'
    . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);

// Summary stats (same filters as the list, so the cards always match the table)
$statSql = "SELECT
    COUNT(*) AS total,
    SUM(a.status = 'present') AS present_count,
    SUM(a.status = 'late')    AS late_count,
    SUM(a.status = 'absent')  AS absent_count,
    SUM(a.status = 'excused') AS excused_count
    FROM attendance a
    JOIN events ev ON ev.id = a.event_id
    JOIN students s ON s.id = a.student_id
    WHERE ev.organization_id IN ($scopedList)";
$statParams = [];
if ($fromDate !== '') { $statSql .= ' AND ev.event_date >= :from'; $statParams['from'] = $fromDate; }
if ($toDate   !== '') { $statSql .= ' AND ev.event_date <= :to';   $statParams['to']   = $toDate; }
if ($yearId   > 0)    { $statSql .= ' AND ev.academic_year_id = :y'; $statParams['y'] = $yearId; }
if ($q !== '') {
    $statSql .= ' AND (s.first_name LIKE :q OR s.last_name LIKE :q2 OR s.student_id LIKE :q3 OR ev.title LIKE :q4)';
    $like = '%' . $q . '%';
    $statParams['q'] = $like;
    $statParams['q2'] = $like;
    $statParams['q3'] = $like;
    $statParams['q4'] = $like;
}
$stats = Database::one($statSql, $statParams)
    ?? ['total' => 0, 'present_count' => 0, 'late_count' => 0, 'absent_count' => 0, 'excused_count' => 0];
$pct = (int)$stats['total'] > 0
    ? round(((int)$stats['present_count'] + (int)$stats['late_count']) / (int)$stats['total'] * 100)
    : 0;

$orgOptions  = [];
foreach (OrgRepo::list(['adviser_id' => $adviserId], 1, 100)['rows'] as $org) {
    $orgOptions[(int) $org['id']] = (string) $org['name'];
}
$yearOptions = AcademicRepo::yearOptions();
$queryStr    = http_build_query(array_filter(['q' => $q, 'org_id' => $orgId ?: '', 'from' => $fromDate, 'to' => $toDate, 'year_id' => $yearId ?: '']));

$PAGE_TITLE       = 'Attendance Monitoring';
$PAGE_ACTIVE      = 'attendance';
$PAGE_SUB         = $total . ' attendance record(s) across your assigned organizations.';
$PAGE_BREADCRUMBS = [
    ['label' => 'Dashboard', 'href' => 'adviser/dashboard.php'],
    ['label' => 'Attendance'],
];
require __DIR__ . '/../includes/layout/header.php';
?>

<?php if ($orgIds === []): ?>
  <section class="card"><?= ui_empty('No organizations assigned yet.', '', 'doc-check') ?></section>
<?php else: ?>

<div class="stat-grid">
  <?= ui_stat('Total records',   (int)$stats['total'],         'users') ?>
  <?= ui_stat('Present',         (int)$stats['present_count'], 'check-circle', 'green') ?>
  <?= ui_stat('Late',            (int)$stats['late_count'],    'clock', 'amber') ?>
  <?= ui_stat('Absent',          (int)$stats['absent_count'],  'close', 'red') ?>
  <?= ui_stat('Excused',         (int)$stats['excused_count'], 'info', (int)$stats['excused_count'] > 0 ? 'blue' : 'grey') ?>
  <?= ui_stat('Attendance rate', $pct . '%',                   'chart',
      $pct >= 75 ? 'green' : ($pct >= 50 ? 'amber' : 'red')) ?>
</div>

<?php if ($pendingRequests !== []): ?>
<section class="card">
  <div class="card-head">
    <div>
      <h3>Pending check-in requests</h3>
      <p class="sub muted small"><?= count($pendingRequests) ?> student(s) waiting — their requested time is kept on approval.</p>
    </div>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead>
        <tr><th>Student</th><th>Event</th><th>Requested</th><th class="actions">Decision</th></tr>
      </thead>
      <tbody>
        <?php foreach ($pendingRequests as $req): ?>
          <tr>
            <td>
              <strong><?= Helpers::e((string) $req['last_name']) ?>, <?= Helpers::e((string) $req['first_name']) ?></strong>
              <br><span class="muted small"><?= Helpers::e((string) $req['sid']) ?> · <?= Helpers::e((string) $req['course']) ?> <?= Helpers::e((string) $req['year_level']) ?></span>
            </td>
            <td class="small"><?= Helpers::e(Helpers::excerpt((string) $req['event_title'], 45)) ?><br><span class="muted"><?= Helpers::e(Helpers::fmtDate((string) $req['event_date'])) ?></span></td>
            <td class="small nowrap"><?= Helpers::e(Helpers::fmtDateTime((string) $req['requested_at'])) ?></td>
            <td class="actions nowrap">
              <form method="post" class="inline">
                <?= Security::csrfField() ?>
                <input type="hidden" name="checkin_decide" value="1">
                <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                <button class="btn sm" type="submit" name="decision" value="approve" data-confirm="Approve this check-in? The student keeps their requested time."><?= icon('check', 15) ?><span>Approve</span></button>
              </form>
              <form method="post" class="inline">
                <?= Security::csrfField() ?>
                <input type="hidden" name="checkin_decide" value="1">
                <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                <button class="btn sm danger" type="submit" name="decision" value="reject" data-confirm="Reject this check-in request? The student will be notified."><?= icon('close', 15) ?><span>Reject</span></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<?= ui_filter_form('adviser/attendance.php',
    ui_filter_input('q', 'Search', $q) .
    (count($orgOptions) > 1 ? ui_filter_select('org_id', 'Organization', $orgOptions, (string)$orgId, 'All organizations') : '') .
    ui_filter_select('year_id', 'Academic year', $yearOptions, (string)$yearId, 'All years') .
    ui_filter_input('from', 'From date', $fromDate, 'date') .
    ui_filter_input('to',   'To date',   $toDate,   'date')
) ?>

<section class="card">
  <div class="card-head">
    <div>
      <h3>Attendance Records</h3>
      <p class="sub muted small"><?= $total ?> record(s) · page <?= $page ?> of <?= $pages ?></p>
    </div>
  </div>

  <?php if ($records === []): ?>
    <?= ui_empty('No attendance records found.', 'Try adjusting the date range or organization filter.', 'doc-check') ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Student</th>
            <th>Event</th>
            <th>Organization</th>
            <th>Date</th>
            <th>Status</th>
            <th>Recorded</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($records as $r): ?>
            <tr>
              <td>
                <strong><?= Helpers::e((string)$r['last_name']) ?>, <?= Helpers::e((string)$r['first_name']) ?></strong>
                <br><span class="muted small"><?= Helpers::e((string)$r['student_id']) ?> · <?= Helpers::e((string)$r['course']) ?></span>
              </td>
              <td class="small"><?= Helpers::e(Helpers::excerpt((string)$r['event_title'], 45)) ?></td>
              <td class="small"><?= Helpers::e((string)$r['organization_acronym']) ?></td>
              <td class="small nowrap"><?= Helpers::e(Helpers::fmtDate((string)$r['event_date'])) ?></td>
              <td><?= ui_status_badge((string)$r['attendance_status']) ?></td>
              <td class="small nowrap"><?= Helpers::e(Helpers::fmtDate((string)$r['recorded_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= ui_pagination($page, $pages, $queryStr) ?>
  <?php endif; ?>
</section>

<?php endif; ?>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
