<?php
/**
 * student/events.php — approved events a student may register for.
 *
 * Server-side filters (q, type, date) and one register/cancel action per row.
 * Registration is only offered while the event is still open: before the
 * deadline, before the event starts and while slots remain.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$student   = Permissions::requireStudentProfile();
Permissions::requireCapability('register_events');
$studentId = (int) $student['id'];

/* ---- POST actions (run BEFORE any output) ---- */
if (Helpers::isPost()) {
    Security::requireCsrf();
    $action  = (string) (Helpers::post('action') ?? '');
    $eventId = Helpers::postInt('event_id');
    try {
        if ($eventId < 1) {
            throw new RuntimeException('Event not found.');
        }
        if ($action === 'register') {
            $result = EventRepo::register($eventId, $studentId);
            if (!$result['ok']) {
                throw new RuntimeException($result['message']);
            }
            Helpers::flash('success', $result['message']);
        } elseif ($action === 'cancel') {
            if (!EventRepo::isRegistered($eventId, $studentId)) {
                throw new RuntimeException('You are not registered for that event.');
            }
            EventRepo::cancelRegistration($eventId, $studentId);
            Helpers::flash('success', 'Your registration was cancelled.');
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        Helpers::flash('error', $e->getMessage());
    }
    Helpers::redirect('student/events.php');
}

/* ---- read ---- */
$date = (string) (Helpers::get('date') ?? '');
$filters = [
    'status'     => 'approved',
    'q'          => (string) (Helpers::get('q') ?? ''),
    'event_type' => (string) (Helpers::get('type') ?? ''),
];
if ($date !== '') {
    $filters['from'] = $date;
    $filters['to']   = $date;
}

$result = EventRepo::list($filters, Helpers::page(), 12);
$page   = min(Helpers::page(), $result['pages']);
$rows   = $result['rows'];

$query = http_build_query(array_filter([
    'q'    => $filters['q'],
    'type' => $filters['event_type'],
    'date' => $date,
]));

$types = array_combine(AcademicRepo::EVENT_TYPES, AcademicRepo::EVENT_TYPES);

$PAGE_TITLE  = 'Events';
$PAGE_ACTIVE = 'events';
$PAGE_SUB    = (int) $result['total'] . ' approved event' . ((int) $result['total'] === 1 ? '' : 's')
    . ' are published. Register before the deadline to reserve a slot.';
$PAGE_ACTIONS = '<a class="btn ghost" href="' . Helpers::e(Helpers::url('student/my-registrations.php')) . '">'
    . icon('clipboard', 16) . '<span>My registrations</span></a>';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'student/dashboard.php'], ['label' => 'Events']];

require __DIR__ . '/../includes/layout/header.php';
?>

<?= ui_filter_form('student/events.php',
    ui_filter_input('q', 'Search', $filters['q'])
    . ui_filter_select('type', 'Type', $types, $filters['event_type'])
    . ui_filter_input('date', 'Date', $date, 'date')) ?>

<?php if ($rows === []): ?>
  <?= ui_empty('No approved event matches this search.', 'Clear the filters to see every published activity.', 'calendar') ?>
<?php else: ?>
  <section class="card flush">
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Event</th>
            <th>Organization</th>
            <th>Schedule</th>
            <th>Venue</th>
            <th class="num">Registered</th>
            <th>Deadline</th>
            <th class="actions">Registration</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $event): ?>
            <?php
              $isRegistered   = EventRepo::isRegistered((int) $event['id'], $studentId);
              $window         = EventRepo::eventWindow($event);
              $deadline       = (string) ($event['registration_deadline'] ?? '');
              $deadlineOk     = $deadline === '' || strtotime($deadline) >= time();
              $started        = time() >= $window['start_ts'];
              $max            = (int) $event['max_participants'];
              $full           = $max > 0 && (int) $event['registered_count'] >= $max;
              $needsReg       = !empty($event['requires_registration']);
              $canRegister    = !$isRegistered && $needsReg && $deadlineOk && !$started && !$full;
            ?>
            <tr>
              <td>
                <strong><?= Helpers::e((string) $event['title']) ?></strong>
                <span class="muted"><?= Helpers::e((string) $event['event_type']) ?>
                  <?= (string) $event['event_code'] !== '' ? ' · ' . Helpers::e((string) $event['event_code']) : '' ?></span>
              </td>
              <td class="nowrap">
                <?= Helpers::e((string) $event['organization_acronym']) ?>
                <span class="muted"><?= Helpers::e(Helpers::excerpt((string) $event['organization_name'], 40)) ?></span>
              </td>
              <td class="nowrap">
                <strong><?= Helpers::e(Helpers::fmtDate((string) $event['event_date'])) ?></strong>
                <span class="muted"><?= Helpers::e(Helpers::fmtTime((string) $event['start_time'])) ?>
                  – <?= Helpers::e(Helpers::fmtTime((string) $event['end_time'])) ?></span>
              </td>
              <td><?= Helpers::e(Helpers::excerpt((string) $event['venue'], 44)) ?></td>
              <td class="num nowrap">
                <?= $max > 0 ? (int) $event['registered_count'] . ' / ' . $max : (string) $event['registered_count'] ?>
              </td>
              <td class="nowrap">
                <?= $deadline === '' ? '<span class="muted">—</span>' : Helpers::e(Helpers::fmtDateTime($deadline)) ?>
              </td>
              <td class="actions">
                <?php if ($isRegistered): ?>
                  <?= ui_badge('Registered', 'green') ?>
                  <form method="post" class="inline-form">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                    <button class="btn sm danger" type="submit"
                      onclick="return confirm('Cancel your registration for this event?')">Cancel</button>
                  </form>
                <?php elseif ($canRegister): ?>
                  <form method="post" class="inline-form">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="action" value="register">
                    <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                    <button class="btn sm" type="submit">Register</button>
                  </form>
                <?php elseif (!$needsReg): ?>
                  <?= ui_badge('Walk-in', 'grey') ?>
                <?php elseif ($full): ?>
                  <?= ui_badge('Slots full', 'red') ?>
                <?php elseif (!$deadlineOk): ?>
                  <?= ui_badge('Deadline passed', 'grey') ?>
                <?php elseif ($started): ?>
                  <?= ui_badge('Event started', 'grey') ?>
                <?php else: ?>
                  <?= ui_badge('Closed', 'grey') ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?= ui_pagination($page, $result['pages'], $query) ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
