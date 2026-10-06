<?php
/**
 * student/my-attendance.php — attendance log and verification history for the signed-in student.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$student   = Permissions::requireStudentProfile();
$studentId = (int) $student['id'];

$totals  = AttendanceRepo::studentTotals($studentId);
$history = AttendanceRepo::forStudent($studentId, 200);

$statusFilter = (string) (Helpers::get('status') ?? '');
if ($statusFilter !== '') {
    $history = array_filter($history, static fn (array $r): bool => (string) $r['status'] === $statusFilter);
}

$PAGE_TITLE       = 'My Attendance';
$PAGE_ACTIVE      = 'my-attendance';
$PAGE_SUB         = 'Your activity check-in history and overall participation statistics';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'student/dashboard.php'], ['label' => 'My Attendance']];
$PAGE_ACTIONS     = '<a class="btn sm" href="' . Helpers::e(Helpers::url('scan.php')) . '">'
    . icon('qr', 15) . '<span>QR Check-In</span></a>';

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="stat-grid">
  <?= ui_stat('Events Attended', $totals['present'], 'check-circle', 'green') ?>
  <?= ui_stat('Late Check-Ins', $totals['late'], 'clock', $totals['late'] > 0 ? 'amber' : 'grey') ?>
  <?= ui_stat('Absences', $totals['absent'], 'x-circle', $totals['absent'] > 0 ? 'red' : 'grey') ?>
  <?= ui_stat('Attendance Rate', (float) $totals['percentage'] . '%', 'chart', $totals['percentage'] >= 80 ? 'green' : 'amber') ?>
</div>

<section class="card">
  <div class="card-head">
    <h3>Attendance Logs</h3>
    <div class="filter-actions">
      <a href="<?= Helpers::e(Helpers::url('student/my-attendance.php')) ?>" class="btn xs <?= $statusFilter === '' ? '' : 'ghost' ?>">All</a>
      <a href="<?= Helpers::e(Helpers::url('student/my-attendance.php?status=present')) ?>" class="btn xs <?= $statusFilter === 'present' ? '' : 'ghost' ?>">Present</a>
      <a href="<?= Helpers::e(Helpers::url('student/my-attendance.php?status=late')) ?>" class="btn xs <?= $statusFilter === 'late' ? '' : 'ghost' ?>">Late</a>
      <a href="<?= Helpers::e(Helpers::url('student/my-attendance.php?status=absent')) ?>" class="btn xs <?= $statusFilter === 'absent' ? '' : 'ghost' ?>">Absent</a>
    </div>
  </div>

  <?php if ($history === []): ?>
    <?= ui_empty('No attendance records found.', 'Your verified event attendance records will appear here after you check in with your QR code.', 'doc-check') ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Event Title</th>
            <th>Host Organization</th>
            <th>Event Date</th>
            <th>Check-In Time</th>
            <th>Method</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $row): ?>
            <tr>
              <td>
                <strong><?= Helpers::e((string) $row['event_title']) ?></strong>
              </td>
              <td><?= Helpers::e((string) ($row['organization_acronym'] ?: $row['organization_name'])) ?></td>
              <td><?= Helpers::e(Helpers::fmtDate((string) $row['event_date'])) ?></td>
              <td><?= Helpers::e(Helpers::fmtDateTime((string) $row['attendance_time'])) ?></td>
              <td>
                <span class="badge grey">
                  <?= match ((string) $row['verification_method']) {
                      'qr_event'     => 'QR Code',
                      'qr_student'   => 'Student Badge',
                      'self_checkin' => 'Self Check-in',
                      'manual'       => 'Manual Entry',
                      default        => 'Verified',
                  } ?>
                </span>
              </td>
              <td><?= ui_status_badge((string) $row['status']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
