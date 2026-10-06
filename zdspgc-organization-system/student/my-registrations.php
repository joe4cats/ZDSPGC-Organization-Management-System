<?php
/**
 * student/my-registrations.php — event registrations of the signed-in student.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$student   = Permissions::requireStudentProfile();
$studentId = (int) $student['id'];

if (Helpers::isPost()) {
    Security::requireCsrf();
    $action  = (string) (Helpers::post('action') ?? '');
    $eventId = Helpers::postInt('event_id');

    try {
        if ($action === 'cancel' && $eventId > 0) {
            $reg = Database::one(
                'SELECT * FROM event_registrations WHERE event_id = :e AND student_id = :s',
                ['e' => $eventId, 's' => $studentId]
            );
            if ($reg === null) {
                throw new RuntimeException('Registration not found.');
            }
            if ($reg['status'] === 'attended') {
                throw new RuntimeException('Cannot cancel registration for an event already attended.');
            }

            Database::update('event_registrations', [
                'status'       => 'cancelled',
                'cancelled_at' => Helpers::now(),
            ], 'event_id = :e AND student_id = :s', ['e' => $eventId, 's' => $studentId]);

            Helpers::flash('success', 'Event registration cancelled.');
        }
    } catch (Throwable $e) {
        Helpers::flash('error', $e->getMessage());
    }

    Helpers::redirect('student/my-registrations.php');
}

$statusFilter = (string) (Helpers::get('status') ?? '');

$where = ['r.student_id = :s'];
$params = ['s' => $studentId];

if ($statusFilter !== '') {
    $where[] = 'r.status = :st';
    $params['st'] = $statusFilter;
}

$registrations = Database::all(
    'SELECT r.*, e.title AS event_title, e.event_code, e.event_date, e.start_time, e.end_time, '
    . 'e.venue, e.status AS event_status, o.name AS organization_name, o.acronym AS organization_acronym '
    . 'FROM event_registrations r '
    . 'JOIN events e ON e.id = r.event_id '
    . 'JOIN organizations o ON o.id = e.organization_id '
    . 'WHERE ' . implode(' AND ', $where) . ' '
    . 'ORDER BY e.event_date DESC, r.registered_at DESC',
    $params
);

$counts = ['registered' => 0, 'attended' => 0, 'cancelled' => 0];
$allRegs = Database::all('SELECT status FROM event_registrations WHERE student_id = :s', ['s' => $studentId]);
foreach ($allRegs as $ar) {
    $st = (string) $ar['status'];
    if (isset($counts[$st])) {
        $counts[$st]++;
    }
}

$PAGE_TITLE       = 'My Registrations';
$PAGE_ACTIVE      = 'my-registrations';
$PAGE_SUB         = 'Events and activities you have registered for';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'student/dashboard.php'], ['label' => 'My Registrations']];
$PAGE_ACTIONS     = '<a class="btn sm" href="' . Helpers::e(Helpers::url('student/events.php')) . '">'
    . icon('calendar', 15) . '<span>Browse Upcoming Events</span></a>';

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="stat-grid">
  <?= ui_stat('Active Registrations', $counts['registered'], 'calendar', 'blue') ?>
  <?= ui_stat('Attended Events', $counts['attended'], 'check-circle', 'green') ?>
  <?= ui_stat('Cancelled', $counts['cancelled'], 'x-circle', 'grey') ?>
  <?= ui_stat('Total Participations', count($allRegs), 'doc-check') ?>
</div>

<section class="card">
  <div class="card-head">
    <h3>Registered Activities</h3>
    <div class="filter-actions">
      <a href="<?= Helpers::e(Helpers::url('student/my-registrations.php')) ?>" class="btn xs <?= $statusFilter === '' ? '' : 'ghost' ?>">All</a>
      <a href="<?= Helpers::e(Helpers::url('student/my-registrations.php?status=registered')) ?>" class="btn xs <?= $statusFilter === 'registered' ? '' : 'ghost' ?>">Registered</a>
      <a href="<?= Helpers::e(Helpers::url('student/my-registrations.php?status=attended')) ?>" class="btn xs <?= $statusFilter === 'attended' ? '' : 'ghost' ?>">Attended</a>
    </div>
  </div>

  <?php if ($registrations === []): ?>
    <?= ui_empty('No event registrations found.', 'Explore upcoming events hosted by student organizations and register online.', 'calendar') ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Event Details</th>
            <th>Host Organization</th>
            <th>Schedule</th>
            <th>Venue</th>
            <th>Registered On</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($registrations as $reg): ?>
            <tr>
              <td>
                <strong><?= Helpers::e((string) $reg['event_title']) ?></strong>
                <span class="muted small d-block"><code><?= Helpers::e((string) $reg['event_code']) ?></code></span>
              </td>
              <td><?= Helpers::e((string) ($reg['organization_acronym'] ?: $reg['organization_name'])) ?></td>
              <td>
                <?= Helpers::e(Helpers::fmtDate((string) $reg['event_date'])) ?><br>
                <span class="muted small"><?= Helpers::e(Helpers::fmtTime((string) $reg['start_time'])) ?></span>
              </td>
              <td><?= Helpers::e((string) $reg['venue']) ?></td>
              <td><?= Helpers::e(Helpers::fmtDateTime((string) $reg['registered_at'])) ?></td>
              <td>
                <?php if ($reg['status'] === 'registered'): ?>
                  <?= ui_badge('Registered', 'blue') ?>
                <?php elseif ($reg['status'] === 'attended'): ?>
                  <?= ui_badge('Attended', 'green') ?>
                <?php else: ?>
                  <?= ui_badge('Cancelled', 'grey') ?>
                <?php endif; ?>
              </td>
              <td>
                <div class="btn-row">
                  <?php if ($reg['status'] === 'registered'): ?>
                    <a class="btn xs ghost" href="<?= Helpers::e(Helpers::url('scan.php?event_id=' . (int) $reg['event_id'])) ?>">
                      <?= icon('qr', 13) ?><span>Check In</span>
                    </a>
                    <?php if ($reg['event_date'] >= date('Y-m-d')): ?>
                      <form method="post" action="<?= Helpers::e(Helpers::url('student/my-registrations.php')) ?>" class="inline" onsubmit="return confirm('Cancel this registration?')">
                        <?= Security::csrfField() ?>
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="event_id" value="<?= (int) $reg['event_id'] ?>">
                        <button class="btn xs ghost text-danger" type="submit">Cancel</button>
                      </form>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
