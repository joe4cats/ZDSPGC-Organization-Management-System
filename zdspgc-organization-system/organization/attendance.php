<?php
/**
 * organization/attendance.php — event attendance management for organization officers.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireRole('officer');
Permissions::requireCapability('view_attendance');

$org = Permissions::activeOrganization();
if ($org === null) {
    Helpers::flash('error', 'You must be assigned to an active organization.');
    Helpers::redirect('organization/dashboard.php');
}
$orgId = (int) $org['id'];

// Handle POST actions: manual attendance recording
if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) (Helpers::post('action') ?? '');

    try {
        if ($action === 'record') {
            $eventId   = Helpers::postInt('event_id');
            $studentId = Helpers::postInt('student_id');
            $status    = (string) (Helpers::post('status') ?? 'present');
            $timeIn    = trim((string) (Helpers::post('time_in') ?? ''));

            if ($eventId <= 0 || $studentId <= 0) {
                throw new RuntimeException('Event and student are required.');
            }

            // Verify event belongs to this org
            $event = EventRepo::find($eventId);
            if ($event === null || (int) $event['organization_id'] !== $orgId) {
                throw new RuntimeException('Event does not belong to your organization.');
            }

            if (!in_array($status, ['present', 'late', 'absent'], true)) {
                $status = 'present';
            }

            AttendanceRepo::record([
                'event_id'    => $eventId,
                'student_id'  => $studentId,
                'status'      => $status,
                'time_in'     => $timeIn !== '' ? $timeIn : date('H:i:s'),
                'recorded_by' => Auth::id(),
            ]);

            Helpers::flash('success', 'Attendance record saved.');
        } elseif ($action === 'delete') {
            $attId = Helpers::postInt('attendance_id');
            if ($attId > 0) {
                Database::delete('attendance', 'id = :id', ['id' => $attId]);
                Helpers::flash('success', 'Attendance record removed.');
            }
        }
    } catch (Throwable $e) {
        Helpers::flash('error', $e->getMessage());
    }

    $eventId = Helpers::postInt('event_id');
    Helpers::redirect('organization/attendance.php' . ($eventId > 0 ? '?event_id=' . $eventId : ''));
}

$events = Database::all(
    'SELECT id, title, event_code, event_date, start_time, status FROM events '
    . 'WHERE organization_id = :org ORDER BY event_date DESC',
    ['org' => $orgId]
);

$eventId = Helpers::getInt('event_id');
if ($eventId <= 0 && $events !== []) {
    $eventId = (int) $events[0]['id'];
}

$selectedEvent = null;
$records = [];
$stats = ['present' => 0, 'late' => 0, 'absent' => 0, 'total' => 0, 'rate' => 0];

if ($eventId > 0) {
    $selectedEvent = EventRepo::find($eventId);
    if ($selectedEvent !== null && (int) $selectedEvent['organization_id'] === $orgId) {
        $records = Database::all(
            'SELECT a.*, s.student_id, s.first_name, s.last_name, s.course, s.year_level, s.section, '
            . 'u.full_name AS recorder_name '
            . 'FROM attendance a '
            . 'JOIN students s ON s.id = a.student_id '
            . 'LEFT JOIN users u ON u.id = a.recorded_by '
            . 'WHERE a.event_id = :e '
            . 'ORDER BY a.attendance_time DESC',
            ['e' => $eventId]
        );

        foreach ($records as $r) {
            $st = (string) $r['status'];
            if (isset($stats[$st])) {
                $stats[$st]++;
            }
            $stats['total']++;
        }
        $valid = $stats['present'] + $stats['late'];
        $stats['rate'] = $stats['total'] > 0 ? round(($valid / $stats['total']) * 100, 1) : 0;
    }
}

// Members for manual check-in
$members = Database::all(
    'SELECT s.id, s.student_id, s.first_name, s.last_name FROM organization_members m '
    . 'JOIN students s ON s.id = m.student_id '
    . 'WHERE m.organization_id = :org AND m.status = "active" '
    . 'ORDER BY s.last_name, s.first_name',
    ['org' => $orgId]
);

$PAGE_TITLE       = 'Event Attendance';
$PAGE_ACTIVE      = 'attendance';
$PAGE_SUB         = Helpers::e((string) $org['name']) . ' · Monitor and record activity attendance';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'organization/dashboard.php'], ['label' => 'Attendance']];

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="stat-grid">
  <?= ui_stat('Total Logged', $stats['total'], 'users') ?>
  <?= ui_stat('Present', $stats['present'], 'check-circle', 'green') ?>
  <?= ui_stat('Late', $stats['late'], 'clock', $stats['late'] > 0 ? 'amber' : 'grey') ?>
  <?= ui_stat('Attendance Rate', $stats['rate'] . '%', 'chart', $stats['rate'] >= 75 ? 'green' : 'amber') ?>
</div>

<div class="grid side-main">
  <section class="card">
    <div class="card-head">
      <h3>Select Event</h3>
      <?php if ($selectedEvent !== null): ?>
        <a class="btn sm" href="<?= Helpers::e(Helpers::url('scan.php?event_id=' . (int) $selectedEvent['id'])) ?>">
          <?= icon('qr', 15) ?><span>Launch QR Scanner</span>
        </a>
      <?php endif; ?>
    </div>

    <form method="get" action="<?= Helpers::e(Helpers::url('organization/attendance.php')) ?>" class="stack">
      <div class="field">
        <label class="field-label" for="sel-event">Event</label>
        <select id="sel-event" name="event_id" onchange="this.form.submit()">
          <?php foreach ($events as $ev): ?>
            <option value="<?= $ev['id'] ?>" <?= $eventId === (int) $ev['id'] ? 'selected' : '' ?>>
              <?= Helpers::e((string) $ev['title']) ?> (<?= Helpers::e(Helpers::fmtDate((string) $ev['event_date'])) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>

    <?php if ($selectedEvent !== null): ?>
      <div class="mt-4 pt-4" style="border-top: 1px solid var(--line-soft);">
        <h4 style="font-size: var(--fs-sm); font-weight: 600; margin-bottom: 8px;">Manual Check-In</h4>
        <form method="post" action="<?= Helpers::e(Helpers::url('organization/attendance.php')) ?>" class="stack">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="record">
          <input type="hidden" name="event_id" value="<?= (int) $selectedEvent['id'] ?>">

          <div class="field">
            <label class="field-label" for="att-student">Student</label>
            <select id="att-student" name="student_id" required>
              <option value="">Select participant</option>
              <?php foreach ($members as $m): ?>
                <option value="<?= $m['id'] ?>">
                  <?= Helpers::e($m['last_name'] . ', ' . $m['first_name'] . ' (' . $m['student_id'] . ')') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label class="field-label" for="att-status">Status</label>
            <select id="att-status" name="status">
              <option value="present">Present</option>
              <option value="late">Late</option>
              <option value="absent">Absent</option>
            </select>
          </div>

          <div class="field">
            <label class="field-label" for="att-time">Check-in Time</label>
            <input id="att-time" type="time" name="time_in" value="<?= date('H:i') ?>">
          </div>

          <button class="btn sm" type="submit"><?= icon('check-circle', 14) ?><span>Record Entry</span></button>
        </form>
      </div>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head">
      <div>
        <h3>Attendance Roster</h3>
        <?php if ($selectedEvent !== null): ?>
          <span class="muted small"><?= Helpers::e((string) $selectedEvent['title']) ?> · <?= Helpers::e(Helpers::fmtDate((string) $selectedEvent['event_date'])) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($selectedEvent === null || $events === []): ?>
      <?= ui_empty('No events found for this organization.', 'Create an event first to start recording attendance.', 'calendar') ?>
    <?php elseif ($records === []): ?>
      <?= ui_empty('No attendance recorded yet for this event.', 'Use the QR scanner or the manual check-in form to log participant attendance.', 'qr') ?>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tbl">
          <thead>
            <tr>
              <th>Student Name</th>
              <th>Student ID</th>
              <th>Program & Year</th>
              <th>Check-in Time</th>
              <th>Status</th>
              <th>Recorded By</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($records as $r): ?>
              <tr>
                <td><strong><?= Helpers::e($r['last_name'] . ', ' . $r['first_name']) ?></strong></td>
                <td><code><?= Helpers::e((string) $r['student_id']) ?></code></td>
                <td><?= Helpers::e((string) $r['course'] . ' · ' . (string) $r['year_level']) ?></td>
                <td><?= Helpers::e((string) $r['attendance_time']) ?></td>
                <td><?= ui_status_badge((string) $r['status']) ?></td>
                <td><?= Helpers::e((string) ($r['recorder_name'] ?? 'Self / Scanner')) ?></td>
                <td>
                  <form method="post" action="<?= Helpers::e(Helpers::url('organization/attendance.php')) ?>" class="inline" onsubmit="return confirm('Remove attendance entry?')">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="attendance_id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="event_id" value="<?= (int) $selectedEvent['id'] ?>">
                    <button class="btn xs ghost text-danger" type="submit" title="Remove record">✕</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
