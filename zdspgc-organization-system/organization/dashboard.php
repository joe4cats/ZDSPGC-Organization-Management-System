<?php
/**
 * organization/dashboard.php â€” organization officer workspace dashboard.
 *
 * Scoped strictly to the organization the signed-in officer belongs to
 * (Permissions::activeOrganization()).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireRole('officer');

$org = Permissions::activeOrganization();
if ($org === null) {
    $PAGE_TITLE  = 'No organization';
    $PAGE_ACTIVE = 'dashboard';
    $PAGE_SUB    = 'Your account is not an officer of any organization yet.';
    require __DIR__ . '/../includes/layout/header.php';
    echo '<section class="card">' . ui_empty(
        'This account is not yet an officer of an organization.',
        'Ask the Office of Student Affairs (or your organization president) to assign you an officer position.',
        'award'
    ) . '<div class="btn-row center"><a class="btn" href="' . Helpers::e(Helpers::url('organizations.php')) . '">Browse organizations</a></div></section>';
    require __DIR__ . '/../includes/layout/footer.php';
    exit;
}

$orgId       = (int) $org['id'];
$members     = MemberRepo::countByOrganization($orgId);
$officerCount = OfficerRepo::countByOrganization($orgId);
$attendance  = AttendanceRepo::statsForOrganization($orgId);
$eventCounts = array_filter(EventRepo::statusCounts($orgId), static fn (int $n): bool => $n > 0);

$upcoming     = EventRepo::upcoming(4, $orgId);
$pendingApps  = MemberRepo::list(['organization_id' => $orgId, 'status' => 'pending'], 1, 5)['rows'];
$recentMember = MemberRepo::list(['organization_id' => $orgId, 'status' => 'active'], 1, 5)['rows'];
$trend        = AttendanceRepo::trend($orgId, 6);

$PAGE_TITLE   = 'Organization Dashboard';
$PAGE_ACTIVE  = 'dashboard';
$PAGE_SUB     = Helpers::e((string) $org['name']) . ' · ' . Helpers::e((string) $org['organization_code'])
    . ' · ' . ui_status((string) $org['status']);
$PAGE_ACTIONS = '<a class="btn ghost" href="' . Helpers::e(Helpers::url('organization/members.php')) . '">' . icon('users', 16)
    . '<span>Members</span></a>'
    . '<a class="btn" href="' . Helpers::e(Helpers::url('organization/events.php')) . '">' . icon('calendar', 16)
    . '<span>Events</span></a>';
$PAGE_CHARTS = true;

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="stat-grid">
  <?= ui_stat('Members', (int) $members['active'], 'users') ?>
  <?= ui_stat('Officers', (int) $officerCount['active'], 'award') ?>
  <?= ui_stat('Pending applications', (int) $members['pending'], 'clock', $members['pending'] > 0 ? 'amber' : 'grey') ?>
  <?= ui_stat('Upcoming events', count($upcoming), 'calendar') ?>
  <?= ui_stat('Attendance rate', (float) $attendance['percentage'] . '%', 'chart', $attendance['percentage'] >= 80 ? '' : 'amber') ?>
</div>

<div class="grid cols-2">
  <?= Chart::card('chart-org-trend', 'Attendance trend (last 6 months)', 'line', $trend['labels'], [Chart::dataset('Present + late', $trend['values'], '', 'line')], '240px') ?>
  <?= Chart::card('chart-org-events', 'Events by status', 'doughnut', array_keys($eventCounts), [Chart::dataset('Events', array_values($eventCounts))], '240px') ?>
</div>

<div class="grid cols-2">
  <section class="card">
    <div class="card-head">
      <h3>Membership applications</h3>
      <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('organization/members.php')) ?>">Review all</a>
    </div>
    <?php if ($pendingApps === []): ?>
      <?= ui_empty('No pending membership application.', 'New applications appear here automatically.', 'check-circle') ?>
    <?php else: ?>
      <table class="tbl">
        <thead><tr><th>Student</th><th>Course</th><th>Applied</th></tr></thead>
        <tbody>
          <?php foreach ($pendingApps as $row): ?>
            <tr>
              <td>
                <div class="person-cell">
                  <?= ui_avatar((string) $row['first_name'] . ' ' . (string) $row['last_name'], null, 32) ?>
                  <div class="person-meta">
                    <strong><?= Helpers::e((string) $row['first_name'] . ' ' . (string) $row['last_name']) ?></strong>
                    <small><?= Helpers::e((string) $row['student_id']) ?></small>
                  </div>
                </div>
              </td>
              <td class="small"><?= Helpers::e((string) $row['course']) ?></td>
              <td class="small nowrap"><?= Helpers::e(Helpers::fmtDate((string) $row['applied_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <div>
    <section class="card">
      <div class="card-head">
        <h3>Upcoming events</h3>
        <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('organization/events.php')) ?>">All events</a>
      </div>
      <?php if ($upcoming === []): ?>
        <?= ui_empty('No approved event is scheduled.', '', 'calendar') ?>
      <?php else: ?>
        <ul class="timeline">
          <?php foreach ($upcoming as $event): ?>
            <li>
              <div class="tl-time"><?= Helpers::e(Helpers::fmtDate((string) $event['event_date'], 'l, M j, Y')) ?>
                · <?= Helpers::e(Helpers::fmtTime((string) $event['start_time'])) ?></div>
              <div class="tl-title"><?= Helpers::e((string) $event['title']) ?></div>
              <div class="tl-desc">
                <?= ui_status_badge((string) $event['status']) ?>
                <span class="muted small"> · <?= (int) $event['registered_count'] ?> registered · <?= Helpers::e((string) $event['venue']) ?></span>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head">
        <h3>Attendance summary</h3>
        <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('organization/attendance.php')) ?>">Details</a>
      </div>
      <div class="card-head compact-head">
        <?= ui_badge('Registered: ' . (int) $attendance['registered'], 'grey') ?>
        <?= ui_badge('Present: ' . (int) $attendance['present'], 'green') ?>
        <?= ui_badge('Late: ' . (int) $attendance['late'], 'amber') ?>
        <?= ui_badge('Excused: ' . (int) $attendance['excused'], 'blue') ?>
        <?= ui_badge('Absent: ' . (int) $attendance['absent'], 'red') ?>
      </div>
      <?= Chart::barList([
          'Present' => (int) $attendance['present'],
          'Late'    => (int) $attendance['late'],
          'Excused' => (int) $attendance['excused'],
          'Absent'  => (int) $attendance['absent'],
      ], '', 'green') ?>
    </section>
  </div>

  <section class="card">
    <div class="card-head">
      <h3>Latest members</h3>
      <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('organization/members.php')) ?>">Member list</a>
    </div>
    <?php if ($recentMember === []): ?>
      <?= ui_empty('No active member yet.', '', 'users') ?>
    <?php else: ?>
      <table class="tbl compact">
        <thead><tr><th>Student</th><th>Position</th><th>Joined</th></tr></thead>
        <tbody>
          <?php foreach ($recentMember as $row): ?>
            <tr>
              <td><strong><?= Helpers::e((string) $row['last_name'] . ', ' . (string) $row['first_name']) ?></strong><br>
                  <span class="muted small"><?= Helpers::e((string) $row['student_id']) ?> · <?= Helpers::e((string) $row['year_level']) ?></span></td>
              <td class="small"><?= Helpers::e((string) ($row['position_title'] !== '' ? $row['position_title'] : 'Member')) ?></td>
              <td class="small nowrap"><?= Helpers::e(Helpers::fmtDate((string) $row['joined_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
</div>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
