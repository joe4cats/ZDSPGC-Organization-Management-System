<?php
/**
 * admin/events.php — system-wide event register and approval queue.
 *
 * Filters (q, status, organization, type, date range) with stat cards, the
 * approve / reject / complete / cancel actions offered only for statuses that
 * can still move, and a printable event detail (?id=N) with the approval
 * history, registration totals and the signed attendance QR poster.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Permissions::requireCapability('view_all_events');

/* ---- POST actions (run BEFORE any output) ---- */
if (Helpers::isPost()) {
    Security::requireCsrf();
    $action  = (string) (Helpers::post('action') ?? '');
    $eventId = Helpers::postInt('event_id');
    $back    = (string) (Helpers::post('back') ?? '');
    $return  = $back === 'detail' && $eventId > 0 ? 'admin/events.php?id=' . $eventId : 'admin/events.php';

    $allowedActions = [
        'approve'  => ['pending_adviser', 'pending_admin'],
        'reject'   => ['pending_adviser', 'pending_admin'],
        'complete' => ['approved', 'ongoing'],
        'cancel'   => ['draft', 'pending_adviser', 'pending_admin', 'approved', 'ongoing'],
    ];

    if ($eventId > 0 && isset($allowedActions[$action])) {
        $event = EventRepo::find($eventId);
        if ($event === null) {
            Helpers::flash('error', 'That event no longer exists.');
        } elseif (!in_array((string) $event['status'], $allowedActions[$action], true)) {
            Helpers::flash('error', 'That action is not available while the event is ' . ui_status((string) $event['status']) . '.');
        } else {
            try {
                if ($action === 'approve') {
                    EventRepo::decide($eventId, 'approve');
                    Helpers::flash('success', 'Event approved and published.');
                } elseif ($action === 'reject') {
                    $reason = (string) (Helpers::post('reason') ?? '');
                    if ($reason === '') {
                        $reason = 'The event proposal did not meet the requirements.';
                    }
                    EventRepo::decide($eventId, 'reject', $reason);
                    Helpers::flash('success', 'Event rejected. The organization was notified.');
                } elseif ($action === 'complete') {
                    EventRepo::complete($eventId);
                    Helpers::flash('success', 'Event marked as completed.');
                } else {
                    EventRepo::setStatus($eventId, 'cancelled');
                    Helpers::flash('success', 'Event cancelled.');
                }
            } catch (Throwable $e) {
                Helpers::flash('error', $e->getMessage());
            }
        }
    }
    Helpers::redirect($return);
}

/* ---- read ---- */
$viewId = Helpers::getInt('id');
$event  = $viewId > 0 ? EventRepo::find($viewId) : null;

if ($viewId > 0 && $event === null) {
    Helpers::flash('error', 'That event no longer exists.');
    Helpers::redirect('admin/events.php');
}

$filters = [
    'q'              => (string) (Helpers::get('q') ?? ''),
    'status'         => (string) (Helpers::get('status') ?? ''),
    'organization_id' => (string) (Helpers::get('organization_id') ?? ''),
    'event_type'     => (string) (Helpers::get('event_type') ?? ''),
    'from'           => (string) (Helpers::get('from') ?? ''),
    'to'             => (string) (Helpers::get('to') ?? ''),
];

$result = $event === null ? EventRepo::list($filters, Helpers::page(), 20) : ['rows' => [], 'total' => 0, 'pages' => 1];
$page   = min(Helpers::page(), $result['pages']);
$rows   = $result['rows'];
$query  = http_build_query(array_filter($filters));

$counts  = EventRepo::statusCounts();
$total   = array_sum($counts);
$orgList = OrgRepo::list([], 1, 300)['rows'];

$orgOptions = [];
foreach ($orgList as $org) {
    $orgOptions[(int) $org['id']] = (string) $org['name'];
}
$typeOptions    = array_combine(AcademicRepo::EVENT_TYPES, AcademicRepo::EVENT_TYPES);
$statusOptions  = array_combine(AcademicRepo::EVENT_STATUSES, AcademicRepo::EVENT_STATUSES);

$allowedActions = [
    'approve'  => ['pending_adviser', 'pending_admin'],
    'reject'   => ['pending_adviser', 'pending_admin'],
    'complete' => ['approved', 'ongoing'],
    'cancel'   => ['draft', 'pending_adviser', 'pending_admin', 'approved', 'ongoing'],
];

$eventActions = [];
if ($event !== null) {
    foreach ($allowedActions as $name => $statuses) {
        if (in_array((string) $event['status'], $statuses, true)) {
            $eventActions[] = $name;
        }
    }
    $adviserApprover = !empty($event['adviser_approved_by']) ? UserRepo::find((int) $event['adviser_approved_by']) : null;
    $adminApprover   = !empty($event['approved_by']) ? UserRepo::find((int) $event['approved_by']) : null;
    $qrToken         = Qr::eventToken($event, false);
    $qrPayload       = Qr::eventPayload($event);
}

/* ---- page meta (before header) ---- */
$PAGE_TITLE  = $event !== null ? 'Event detail' : 'Events';
$PAGE_ACTIVE = 'events';
$PAGE_SUB    = $event !== null
    ? Helpers::e((string) $event['event_code']) . ' · ' . Helpers::e((string) $event['organization_name'])
    : (int) $total . ' event(s) on record · approvals, schedules and attendance QR codes';
$PAGE_BREADCRUMBS = $event !== null
    ? [['label' => 'Dashboard', 'href' => 'admin/dashboard.php'], ['label' => 'Events', 'href' => 'admin/events.php'], ['label' => (string) $event['title']]]
    : [['label' => 'Dashboard', 'href' => 'admin/dashboard.php'], ['label' => 'Events']];

if ($event !== null) {
    $PAGE_ACTIONS = '<a class="btn ghost" href="' . Helpers::e(Helpers::url('admin/events.php')) . '">'
        . icon('chevron-left', 16) . '<span>All events</span></a>';
} else {
    $PAGE_ACTIONS = '<a class="btn ghost" href="' . Helpers::e(Helpers::url('admin/attendance.php')) . '">'
        . icon('qr', 16) . '<span>Attendance records</span></a>';
}

$EXTRA_JS = ['vendor/qrcode-generator.js', 'qr.js'];

require __DIR__ . '/../includes/layout/header.php';
?>

<?php if ($event !== null): ?>
  <div class="stat-grid">
    <?= ui_stat('Registered', (int) $event['registered_count'], 'users', (int) $event['max_participants'] > 0 && (int) $event['registered_count'] >= (int) $event['max_participants'] ? 'amber' : '', (int) $event['max_participants'] > 0 ? 'Max ' . (int) $event['max_participants'] : 'No cap') ?>
    <?= ui_stat('Attendance recorded', (int) $event['attendance_count'], 'qr') ?>
    <?= ui_stat('Status', ui_status((string) $event['status']), 'clock', ui_tone((string) $event['status'])) ?>
  </div>

  <div class="grid cols-2">
    <section class="card">
      <div class="card-head">
        <h3><?= Helpers::e((string) $event['title']) ?></h3>
        <?= ui_status_badge((string) $event['status']) ?>
      </div>
      <dl class="detail-list">
        <dt>Event code</dt><dd><code><?= Helpers::e((string) $event['event_code']) ?></code></dd>
        <dt>Organization</dt><dd><?= Helpers::e((string) $event['organization_name']) ?> (<?= Helpers::e((string) $event['organization_acronym']) ?>)</dd>
        <dt>Academic year</dt><dd><?= Helpers::e((string) ($event['academic_year_name'] ?? '—')) ?></dd>
        <dt>Type</dt><dd><?= Helpers::e((string) $event['event_type']) ?></dd>
        <dt>Date</dt><dd><?= Helpers::e(Helpers::fmtDate((string) $event['event_date'], 'l, F j, Y')) ?></dd>
        <dt>Time</dt><dd><?= Helpers::e(Helpers::fmtTime((string) $event['start_time'])) ?> – <?= Helpers::e(Helpers::fmtTime((string) $event['end_time'])) ?></dd>
        <dt>Venue</dt><dd><?= Helpers::e((string) $event['venue']) ?></dd>
        <dt>Organizer</dt><dd><?= Helpers::e((string) ($event['organizer'] !== '' ? $event['organizer'] : '—')) ?></dd>
        <dt>Adviser</dt><dd><?= Helpers::e((string) ($event['adviser_name'] ?? '—')) ?></dd>
        <dt>Registration</dt><dd>
          <?= (int) $event['requires_registration'] === 1 ? 'Required' : 'Walk-in' ?>
          <?php if ((int) $event['max_participants'] > 0): ?>
            · <?= (int) $event['registered_count'] ?> / <?= (int) $event['max_participants'] ?> seats
          <?php endif; ?>
        </dd>
        <dt>Deadline</dt><dd><?= Helpers::e(Helpers::fmtDateTime((string) ($event['registration_deadline'] ?? ''))) ?></dd>
        <dt>Grace period</dt><dd><?= (int) $event['grace_minutes'] ?> minute(s)</dd>
        <dt>Description</dt><dd><?= Helpers::e(Helpers::excerpt((string) ($event['description'] ?? ''), 400)) ?></dd>
      </dl>
    </section>

    <div>
      <section class="card">
        <div class="card-head"><h3>Approval history</h3></div>
        <dl class="detail-list">
          <dt>Created</dt><dd><?= Helpers::e(Helpers::fmtDateTime((string) $event['created_at'])) ?></dd>
          <dt>Submitted</dt><dd><?= Helpers::e(Helpers::fmtDateTime((string) ($event['submitted_at'] ?? ''))) ?></dd>
          <dt>Adviser decision</dt><dd>
            <?php if (!empty($event['adviser_approved_at'])): ?>
              <?= Helpers::e($adviserApprover['full_name'] ?? 'Unknown user') ?> · <?= Helpers::e(Helpers::fmtDateTime((string) $event['adviser_approved_at'])) ?>
            <?php else: ?>
              —
            <?php endif; ?>
          </dd>
          <dt>Administrator decision</dt><dd>
            <?php if (!empty($event['approved_at'])): ?>
              <?= Helpers::e($adminApprover['full_name'] ?? 'Unknown user') ?> · <?= Helpers::e(Helpers::fmtDateTime((string) $event['approved_at'])) ?>
            <?php else: ?>
              —
            <?php endif; ?>
          </dd>
          <dt>Rejection reason</dt><dd><?= $event['rejection_reason'] !== '' ? Helpers::e((string) $event['rejection_reason']) : '—' ?></dd>
          <dt>Completed</dt><dd><?= Helpers::e(Helpers::fmtDateTime((string) ($event['completed_at'] ?? ''))) ?></dd>
        </dl>
      </section>

      <section class="card">
        <div class="card-head">
          <h3>Attendance QR poster</h3>
          <button class="btn sm ghost" type="button" data-print><?= icon('print', 15) ?><span>Print poster</span></button>
        </div>
        <div class="center">
          <div data-qr="<?= Helpers::e($qrPayload) ?>" data-qr-cell="7" data-qr-label="Attendance QR for <?= Helpers::e((string) $event['title']) ?>"></div>
        </div>
        <p class="hint">Students scan this code at the venue to record their attendance. The code is signed for
          <code><?= Helpers::e((string) $event['event_code']) ?></code>; re-issuing it voids every printed copy.</p>
        <p class="small muted">Token: <code><?= Helpers::e($qrToken) ?></code></p>
        <p class="small">
          <a href="<?= Helpers::e(Helpers::url('admin/attendance.php?event_id=' . (int) $event['id'])) ?>">
            <?= icon('qr', 15) ?> Attendance list for this event
          </a>
        </p>
      </section>
    </div>
  </div>

  <section class="card">
    <div class="card-head"><h3>Decision</h3></div>
    <?php if ($eventActions === []): ?>
      <p class="hint">This event is <?= Helpers::e(ui_status((string) $event['status'])) ?> — there is no further action available here.</p>
    <?php else: ?>
      <form method="post">
        <?= Security::csrfField() ?>
        <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
        <input type="hidden" name="back" value="detail">
        <?php if (in_array('approve', $eventActions, true) || in_array('reject', $eventActions, true)): ?>
          <?= ui_textarea('reason', 'Reason (used when you reject)', '', ['rows' => 3, 'placeholder' => 'Optional when approving…']) ?>
        <?php endif; ?>
        <div class="form-actions">
          <?php if (in_array('approve', $eventActions, true)): ?>
            <button class="btn" type="submit" name="action" value="approve" data-confirm="Approve and publish this event?">
              <?= icon('check-circle', 16) ?><span>Approve</span>
            </button>
          <?php endif; ?>
          <?php if (in_array('reject', $eventActions, true)): ?>
            <button class="btn danger" type="submit" name="action" value="reject" data-confirm="Reject this event? The reason is stored on the record.">
              <?= icon('close', 16) ?><span>Reject</span>
            </button>
          <?php endif; ?>
          <?php if (in_array('complete', $eventActions, true)): ?>
            <button class="btn" type="submit" name="action" value="complete" data-confirm="Mark this event as completed?">
              <?= icon('check', 16) ?><span>Mark completed</span>
            </button>
          <?php endif; ?>
          <?php if (in_array('cancel', $eventActions, true)): ?>
            <button class="btn danger" type="submit" name="action" value="cancel" data-confirm="Cancel this event? Registrations stay on the record.">
              <?= icon('alert', 16) ?><span>Cancel event</span>
            </button>
          <?php endif; ?>
        </div>
      </form>
    <?php endif; ?>
  </section>

<?php else: ?>
  <div class="stat-grid">
    <?= ui_stat('Total events', $total, 'calendar') ?>
    <?= ui_stat('Awaiting adviser', $counts['pending_adviser'], 'clock', $counts['pending_adviser'] > 0 ? 'amber' : 'grey') ?>
    <?= ui_stat('Awaiting admin', $counts['pending_admin'], 'clock', $counts['pending_admin'] > 0 ? 'amber' : 'grey') ?>
    <?= ui_stat('Approved', $counts['approved'], 'check-circle', 'blue') ?>
    <?= ui_stat('Ongoing', $counts['ongoing'], 'play', $counts['ongoing'] > 0 ? 'amber' : 'grey') ?>
    <?= ui_stat('Completed', $counts['completed'], 'award', $counts['completed'] > 0 ? '' : 'grey') ?>
    <?= ui_stat('Rejected', $counts['rejected'], 'close', $counts['rejected'] > 0 ? 'red' : 'grey') ?>
    <?= ui_stat('Cancelled', $counts['cancelled'], 'alert', $counts['cancelled'] > 0 ? 'red' : 'grey') ?>
  </div>

  <?= ui_filter_form('admin/events.php',
      ui_filter_input('q', 'Search', $filters['q'])
      . ui_filter_select('status', 'Status', $statusOptions, $filters['status'])
      . ui_filter_select('organization_id', 'Organization', $orgOptions, $filters['organization_id'])
      . ui_filter_select('event_type', 'Type', $typeOptions, $filters['event_type'])
      . ui_filter_input('from', 'From', $filters['from'], 'date')
      . ui_filter_input('to', 'To', $filters['to'], 'date')) ?>

  <section class="card">
    <div class="card-head">
      <h3>Events</h3>
      <span class="badge blue"><?= (int) $result['total'] ?> found</span>
    </div>
    <?php if ($rows === []): ?>
      <?= ui_empty('No event matches these filters.', 'Clear the filters or widen the date range.', 'calendar') ?>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tbl">
          <thead>
            <tr>
              <th>Event</th><th>Organization</th><th>Date &amp; time</th><th>Venue</th>
              <th>Status</th><th class="num">Registered</th><th class="actions">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <?php
                $rowActions = [];
                foreach ($allowedActions as $name => $statuses) {
                    if (in_array((string) $row['status'], $statuses, true)) {
                        $rowActions[] = $name;
                    }
                }
              ?>
              <tr>
                <td>
                  <strong><?= Helpers::e(Helpers::excerpt((string) $row['title'], 52)) ?></strong>
                  <span class="muted"><?= Helpers::e((string) $row['event_code']) ?> · <?= Helpers::e((string) $row['event_type']) ?></span>
                </td>
                <td class="small"><?= Helpers::e((string) $row['organization_acronym']) ?><span class="muted"><?= Helpers::e(Helpers::excerpt((string) $row['organization_name'], 40)) ?></span></td>
                <td class="small nowrap"><?= Helpers::e(Helpers::fmtDate((string) $row['event_date'])) ?><span class="muted"><?= Helpers::e(Helpers::fmtTime((string) $row['start_time'])) ?> – <?= Helpers::e(Helpers::fmtTime((string) $row['end_time'])) ?></span></td>
                <td class="small"><?= Helpers::e(Helpers::excerpt((string) $row['venue'], 40)) ?></td>
                <td><?= ui_status_badge((string) $row['status']) ?></td>
                <td class="num"><?= (int) $row['registered_count'] ?><?= (int) $row['max_participants'] > 0 ? ' / ' . (int) $row['max_participants'] : '' ?></td>
                <td class="actions">
                  <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('admin/events.php?id=' . (int) $row['id'])) ?>">
                    <?= icon('eye', 15) ?><span>View</span>
                  </a>
                  <?php if ($rowActions !== []): ?>
                    <form method="post" class="inline">
                      <?= Security::csrfField() ?>
                      <input type="hidden" name="event_id" value="<?= (int) $row['id'] ?>">
                      <?php if (in_array('approve', $rowActions, true)): ?>
                        <button class="btn sm" type="submit" name="action" value="approve" data-confirm="Approve and publish <?= Helpers::e(Helpers::excerpt((string) $row['title'], 40)) ?>?">
                          <?= icon('check', 14) ?><span>Approve</span>
                        </button>
                      <?php endif; ?>
                      <?php if (in_array('reject', $rowActions, true)): ?>
                        <button class="btn sm danger" type="submit" name="action" value="reject" data-confirm="Reject <?= Helpers::e(Helpers::excerpt((string) $row['title'], 40)) ?>?">
                          <?= icon('close', 14) ?><span>Reject</span>
                        </button>
                      <?php endif; ?>
                      <?php if (in_array('complete', $rowActions, true)): ?>
                        <button class="btn sm" type="submit" name="action" value="complete" data-confirm="Mark this event as completed?">
                          <?= icon('check', 14) ?><span>Complete</span>
                        </button>
                      <?php endif; ?>
                      <?php if (in_array('cancel', $rowActions, true)): ?>
                        <button class="btn sm danger" type="submit" name="action" value="cancel" data-confirm="Cancel this event?">
                          <?= icon('alert', 14) ?><span>Cancel</span>
                        </button>
                      <?php endif; ?>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?= ui_pagination($page, $result['pages'], $query) ?>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
