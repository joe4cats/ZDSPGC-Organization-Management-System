<?php
/**
 * student/dashboard.php â€” the signed-in student's home.
 *
 * Only the student's own data: memberships, registered events, personal
 * attendance, announcements addressed to them and pending applications.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$student   = Permissions::requireStudentProfile();
$studentId = (int) $student['id'];

$memberships = MemberRepo::forStudent($studentId);
$active      = array_values(array_filter($memberships, static fn (array $m): bool => $m['status'] === 'active'));
$pending     = array_values(array_filter($memberships, static fn (array $m): bool => $m['status'] === 'pending'));

$totals   = AttendanceRepo::studentTotals($studentId);
$upcoming = Database::all(
    "SELECT e.*, o.acronym AS organization_acronym FROM event_registrations r
       JOIN events e ON e.id = r.event_id
       JOIN organizations o ON o.id = e.organization_id
      WHERE r.student_id = :s AND r.status = 'registered' AND e.event_date >= CURDATE()
      ORDER BY e.event_date ASC LIMIT 5",
    ['s' => $studentId]
);
$history = array_slice(AttendanceRepo::forStudent($studentId, 6), 0, 6);

$announcements = Database::all(
    "SELECT a.*, o.name AS organization_name FROM announcements a
       LEFT JOIN organizations o ON o.id = a.organization_id
      WHERE a.status = 'published' AND a.publish_date <= NOW()
        AND (a.expiration_date IS NULL OR a.expiration_date >= NOW())
        AND (a.audience = 'all'
             OR (a.audience = 'department' AND a.audience_department_id = :dept)
             OR (a.audience IN ('organization','officers','members') AND a.organization_id IN (
                 SELECT organization_id FROM organization_members
                  WHERE student_id = :s2 AND status = 'active')))
      ORDER BY a.is_pinned DESC, a.publish_date DESC LIMIT 5",
    ['dept' => (int) $student['department_id'], 's2' => $studentId]
);

$year = AcademicRepo::activeYear();

$PAGE_TITLE   = 'Dashboard';
$PAGE_ACTIVE  = 'dashboard';
$PAGE_SUB     = Helpers::e((string) $student['first_name'] . ' ' . (string) $student['last_name'])
    . ' · ' . Helpers::e((string) $student['student_id'])
    . ' · AY ' . Helpers::e((string) ($year['name'] ?? '—'));
$PAGE_ACTIONS = '<a class="btn ghost" href="' . Helpers::e(Helpers::url('organizations.php')) . '">' . icon('org', 16)
    . '<span>Browse organizations</span></a>';

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="stat-grid">
  <?= ui_stat('My organizations', count($active), 'org') ?>
  <?= ui_stat('Pending applications', count($pending), 'clock', count($pending) > 0 ? 'amber' : 'grey') ?>
  <?= ui_stat('Events attended', (int) $totals['events'], 'check-circle') ?>
  <?= ui_stat('Attendance rate', (float) $totals['percentage'] . '%', 'chart', $totals['percentage'] >= 80 ? '' : 'amber') ?>
</div>

<div class="grid cols-2">
  <section class="card">
    <div class="card-head">
      <h3>My organizations</h3>
      <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('student/my-organizations.php')) ?>">Manage</a>
    </div>
    <?php if ($active === []): ?>
      <?= ui_empty('You have not joined any organization yet.', 'Browse the directory and apply for membership.', 'org') ?>
    <?php else: ?>
      <div class="org-grid">
        <?php foreach ($active as $membership): ?>
          <a class="org-card" href="<?= Helpers::e(Helpers::url('organization.php?id=' . (int) $membership['organization_id'])) ?>">
            <div class="org-cell">
              <?= ui_org_badge($membership, 38) ?>
              <div>
                <h3><?= Helpers::e((string) $membership['organization_name']) ?></h3>
                <?= ui_org_acronym($membership) ?>
              </div>
            </div>
            <div class="meta">
              <?= ui_status_badge((string) $membership['status']) ?>
              <?php if ((string) $membership['position_title'] !== ''): ?>
                <span><?= icon('award', 13) ?> <?= Helpers::e((string) $membership['position_title']) ?></span>
              <?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <div>
    <section class="card">
      <div class="card-head">
        <h3>Upcoming events you registered for</h3>
        <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('student/events.php')) ?>">All events</a>
      </div>
      <?php if ($upcoming === []): ?>
        <?= ui_empty('No upcoming registration.', 'Register from the Events page to reserve a slot.', 'calendar') ?>
      <?php else: ?>
        <table class="tbl compact">
          <tbody>
            <?php foreach ($upcoming as $event): ?>
              <tr>
                <td class="nowrap">
                  <strong><?= Helpers::e(Helpers::fmtDate((string) $event['event_date'], 'M j')) ?></strong><br>
                  <span class="muted small"><?= Helpers::e(Helpers::fmtTime((string) $event['start_time'])) ?></span>
                </td>
                <td>
                  <strong><?= Helpers::e(Helpers::excerpt((string) $event['title'], 52)) ?></strong><br>
                  <span class="muted small"><?= Helpers::e((string) $event['organization_acronym']) ?> · <?= Helpers::e((string) $event['venue']) ?></span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head">
        <h3>My attendance</h3>
        <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('student/my-attendance.php')) ?>">Details</a>
      </div>
      <div class="card-head compact-head">
        <?= ui_badge('Present: ' . (int) $totals['present'], 'green') ?>
        <?= ui_badge('Late: ' . (int) $totals['late'], 'amber') ?>
        <?= ui_badge('Excused: ' . (int) $totals['excused'], 'blue') ?>
        <?= ui_badge('Absent: ' . (int) $totals['absent'], 'red') ?>
      </div>
      <?php if ($history === []): ?>
        <?= ui_empty('No attendance has been recorded yet.', 'Scan the event QR code at the venue.', 'qr') ?>
      <?php else: ?>
        <ul class="timeline">
          <?php foreach ($history as $row): ?>
            <li>
              <div class="tl-time"><?= Helpers::e(Helpers::fmtDateTime((string) $row['attendance_time'])) ?></div>
              <div class="tl-title"><?= Helpers::e(Helpers::excerpt((string) $row['event_title'], 54)) ?></div>
              <div class="tl-desc">
                <?= ui_status_badge((string) $row['status']) ?>
                <span class="muted small"> · <?= Helpers::e((string) $row['organization_acronym']) ?></span>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>
</div>

<div class="grid cols-2">
  <section class="card">
    <div class="card-head">
      <h3>Announcements</h3>
      <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('student/announcements.php')) ?>">All</a>
    </div>
    <?php if ($announcements === []): ?>
      <?= ui_empty('No announcement for you right now.', '', 'megaphone') ?>
    <?php else: ?>
      <ul class="timeline">
        <?php foreach ($announcements as $item): ?>
          <li>
            <div class="tl-time"><?= Helpers::e(Helpers::fmtDate((string) $item['publish_date'])) ?>
              <?= ui_badge(ui_status((string) $item['audience']), 'grey') ?></div>
            <div class="tl-title"><?= Helpers::e((string) $item['title']) ?></div>
            <div class="tl-desc"><?= Helpers::e(Helpers::excerpt((string) $item['content'], 150)) ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head"><h3>My pending applications</h3></div>
    <?php if ($pending === []): ?>
      <?= ui_empty('No application is waiting for a decision.', '', 'check-circle') ?>
    <?php else: ?>
      <table class="tbl compact">
        <thead><tr><th>Organization</th><th>Applied</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($pending as $membership): ?>
            <tr>
              <td><strong><?= Helpers::e((string) $membership['organization_name']) ?></strong><br>
                  <span class="muted small"><?= Helpers::e(Helpers::excerpt((string) $membership['remarks'], 60)) ?></span></td>
              <td class="small nowrap"><?= Helpers::e(Helpers::fmtDate((string) $membership['applied_at'])) ?></td>
              <td><?= ui_status_badge((string) $membership['status']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
</div>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
