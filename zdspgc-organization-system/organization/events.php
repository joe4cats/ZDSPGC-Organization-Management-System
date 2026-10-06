<?php
/**
 * organization/events.php — events of the open organization.
 *
 * List with filters and status counters, the create / edit form, the approval
 * workflow (draft → pending_adviser → pending_admin → approved) and the QR
 * poster used at the venue for attendance scanning.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Permissions::requireCapability('manage_events');

$org = Permissions::activeOrganization();
if ($org === null) {
    Helpers::flash('error', 'No organization workspace is open.');
    Helpers::redirect('organization/dashboard.php');
}
$orgId = (int) $org['id'];
Permissions::requireOrganization($orgId);

/** Loads an event of this workspace or refuses the request. */
$loadEvent = static function (int $eventId) use ($orgId): array {
    $event = $eventId > 0 ? EventRepo::find($eventId) : null;
    if ($event === null || (int) $event['organization_id'] !== $orgId) {
        throw new RuntimeException('That event does not belong to this organization.');
    }
    return $event;
};

/** Reads and validates the event form fields shared by create and edit. */
$readInput = static function (): array {
    $title = Security::clean((string) (Helpers::post('title') ?? ''), 180);
    if ($title === '') {
        throw new RuntimeException('The event needs a title.');
    }
    $date = (string) (Helpers::post('event_date') ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        throw new RuntimeException('Choose a valid event date.');
    }
    $start = (string) (Helpers::post('start_time') ?? '');
    $end   = (string) (Helpers::post('end_time') ?? '');
    if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start) !== 1 || preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $end) !== 1) {
        throw new RuntimeException('Enter the start and end times of the event.');
    }
    $type     = (string) (Helpers::post('event_type') ?? 'Other');
    $deadline = (string) (Helpers::post('registration_deadline') ?? '');

    return [
        'title'                 => $title,
        'description'           => Security::clean((string) (Helpers::post('description') ?? ''), 4000),
        'event_type'            => in_array($type, AcademicRepo::EVENT_TYPES, true) ? $type : 'Other',
        'venue'                 => Security::clean((string) (Helpers::post('venue') ?? ''), 160),
        'event_date'            => $date,
        'start_time'            => $start,
        'end_time'              => $end,
        'organizer'             => Security::clean((string) (Helpers::post('organizer') ?? ''), 160),
        'max_participants'      => (string) max(0, Helpers::postInt('max_participants')),
        'requires_registration' => Helpers::inputBool('requires_registration') ? 1 : 0,
        'grace_minutes'         => max(0, Helpers::postInt('grace_minutes')),
        'registration_deadline' => preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $deadline) === 1 ? $deadline : '',
    ];
};

/* ---- POST actions (run BEFORE any output) ---- */
$redirect = 'organization/events.php';
if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) (Helpers::post('action') ?? '');

    try {
        if ($action === 'create') {
            $data = $readInput();
            $data['organization_id'] = $orgId;
            if ($data['grace_minutes'] < 1) {
                unset($data['grace_minutes']);
            }
            $newId   = EventRepo::create($data);
            $redirect = 'organization/events.php?id=' . $newId;
            Helpers::flash('success', 'The event was created as a draft.');
        } elseif ($action === 'edit') {
            $eventId = Helpers::postInt('event_id');
            $loadEvent($eventId);
            $data = $readInput();
            if ($data['grace_minutes'] < 1) {
                unset($data['grace_minutes']);
            }
            EventRepo::update($eventId, $data);
            $redirect = 'organization/events.php?id=' . $eventId;
            Helpers::flash('success', 'The event was updated.');
        } elseif ($action === 'submit') {
            $eventId = Helpers::postInt('event_id');
            $event   = $loadEvent($eventId);
            if ((string) $event['status'] !== 'draft') {
                throw new RuntimeException('Only a draft event can be submitted for approval.');
            }
            EventRepo::submit($eventId);
            $redirect = 'organization/events.php?id=' . $eventId;
            Helpers::flash('success', 'The event was sent to the adviser for endorsement.');
        } elseif ($action === 'cancel') {
            $eventId = Helpers::postInt('event_id');
            $loadEvent($eventId);
            EventRepo::setStatus($eventId, 'cancelled');
            $redirect = 'organization/events.php?id=' . $eventId;
            Helpers::flash('success', 'The event was cancelled.');
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        Helpers::flash('error', $e->getMessage());
    }
    Helpers::redirect($redirect);
}

/* ---- read ---- */
$view      = 'list';
$editRow   = null;
$detailRow = null;
if (Helpers::get('id') !== null) {
    $view = 'detail';
} elseif (Helpers::get('new') === '1' || Helpers::get('edit') !== null) {
    $view = 'form';
}
try {
    if ($view === 'detail') {
        $detailRow = $loadEvent(Helpers::getInt('id'));
    } elseif ($view === 'form' && Helpers::get('edit') !== null) {
        $editRow = $loadEvent(Helpers::getInt('edit'));
    }
} catch (Throwable $e) {
    Helpers::flash('error', $e->getMessage());
    Helpers::redirect('organization/events.php');
}

$eventTypes = array_combine(AcademicRepo::EVENT_TYPES, AcademicRepo::EVENT_TYPES);

if ($view === 'list') {
    $filters = [
        'organization_id' => $orgId,
        'q'               => (string) (Helpers::get('q') ?? ''),
        'status'          => (string) (Helpers::get('status') ?? ''),
        'event_type'      => (string) (Helpers::get('type') ?? ''),
        'from'            => (string) (Helpers::get('from') ?? ''),
        'to'              => (string) (Helpers::get('to') ?? ''),
    ];
    $result = EventRepo::list($filters, Helpers::page(), 20);
    $page   = min(Helpers::page(), $result['pages']);
    $rows   = $result['rows'];
    $query  = http_build_query(array_filter([
        'q'      => $filters['q'],
        'status' => $filters['status'],
        'type'   => $filters['event_type'],
        'from'   => $filters['from'],
        'to'     => $filters['to'],
    ]));
    $counts = EventRepo::statusCounts($orgId);
    $total  = array_sum($counts);
    $inReview = (int) $counts['pending_adviser'] + (int) $counts['pending_admin'];
}

$PAGE_TITLE  = $view === 'detail' ? 'Event details' : ($view === 'form' ? ($editRow !== null ? 'Edit event' : 'New event') : 'Events');
$PAGE_ACTIVE = 'events';
$PAGE_SUB    = Helpers::e((string) $org['name']) . ' · ' . icon('calendar', 15)
    . ' Events and their approval workflow';
$PAGE_ACTIONS = $view === 'list'
    ? '<a class="btn ghost" href="' . Helpers::e(Helpers::url('organization/calendar.php')) . '">' . icon('calendar', 16) . '<span>Calendar</span></a>'
      . '<a class="btn" href="' . Helpers::e(Helpers::url('organization/events.php?new=1')) . '">' . icon('plus', 16) . '<span>New event</span></a>'
    : '<a class="btn ghost" href="' . Helpers::e(Helpers::url('organization/events.php')) . '">' . icon('chevron-left', 16) . '<span>All events</span></a>';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'organization/dashboard.php'], ['label' => 'Events']];
$EXTRA_JS = ['vendor/qrcode-generator.js', 'qr.js'];

require __DIR__ . '/../includes/layout/header.php';
?>

<?php if ($view === 'list'): ?>
  <div class="stat-grid">
    <?= ui_stat('Total events', $total, 'calendar') ?>
    <?= ui_stat('Drafts', (int) $counts['draft'], 'edit', (int) $counts['draft'] > 0 ? 'amber' : 'grey') ?>
    <?= ui_stat('Awaiting approval', $inReview, 'clock', $inReview > 0 ? 'amber' : 'grey') ?>
    <?= ui_stat('Approved', (int) $counts['approved'], 'check-circle') ?>
    <?= ui_stat('Completed', (int) $counts['completed'], 'doc-check') ?>
  </div>

  <section class="card">
    <div class="card-head"><h3>Events</h3><span class="muted small"><?= (int) $result['total'] ?> record(s)</span></div>
    <?= ui_filter_form('organization/events.php',
        ui_filter_input('q', 'Search', $filters['q'])
        . ui_filter_select('status', 'Status', array_combine(AcademicRepo::EVENT_STATUSES, AcademicRepo::EVENT_STATUSES), $filters['status'])
        . ui_filter_select('type', 'Type', $eventTypes, $filters['event_type'])
        . ui_filter_input('from', 'From', $filters['from'], 'date')
        . ui_filter_input('to', 'To', $filters['to'], 'date')) ?>

    <?php if ($rows === []): ?>
      <?= ui_empty('No event matches these filters.', 'Create the first event for this organization.', 'calendar') ?>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tbl">
          <thead>
            <tr><th>Event</th><th>Type</th><th>Date</th><th class="num">Registered</th><th>Status</th><th class="actions">Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td>
                  <strong><?= Helpers::e((string) $row['title']) ?></strong><br>
                  <span class="muted small"><?= Helpers::e((string) $row['event_code']) ?> · <?= Helpers::e((string) $row['venue']) ?></span>
                </td>
                <td class="small"><?= Helpers::e((string) $row['event_type']) ?></td>
                <td class="small nowrap"><?= Helpers::e(Helpers::fmtDate((string) $row['event_date'])) ?><br>
                  <span class="muted small"><?= Helpers::e(Helpers::fmtTime((string) $row['start_time'])) ?> – <?= Helpers::e(Helpers::fmtTime((string) $row['end_time'])) ?></span></td>
                <td class="num"><?= (int) $row['registered_count'] ?><?= (int) $row['max_participants'] > 0 ? ' / ' . (int) $row['max_participants'] : '' ?></td>
                <td><?= ui_status_badge((string) $row['status']) ?></td>
                <td class="actions">
                  <div class="btn-row">
                    <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('organization/events.php?id=' . (int) $row['id'])) ?>"><?= icon('eye', 15) ?><span>Open</span></a>
                    <?php if ((string) $row['status'] === 'draft'): ?>
                      <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('organization/events.php?edit=' . (int) $row['id'])) ?>"><?= icon('edit', 15) ?><span>Edit</span></a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?= ui_pagination($page, $result['pages'], $query) ?>
    <?php endif; ?>
  </section>

<?php elseif ($view === 'form'): ?>
  <section class="card">
    <div class="card-head">
      <h3><?= $editRow !== null ? 'Edit event' : 'Create an event' ?></h3>
      <span class="muted small"><?= $editRow !== null ? Helpers::e((string) $editRow['event_code']) : 'Saved as a draft' ?></span>
    </div>
    <form method="post">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="<?= $editRow !== null ? 'edit' : 'create' ?>">
      <?php if ($editRow !== null): ?>
        <input type="hidden" name="event_id" value="<?= (int) $editRow['id'] ?>">
      <?php endif; ?>

      <?= ui_input('title', 'Event title', (string) ($editRow['title'] ?? ''), ['placeholder' => 'e.g. General Assembly and Leadership Seminar'], true) ?>

      <div class="grid cols-2">
        <?= ui_select('event_type', 'Event type', $eventTypes, (string) ($editRow['event_type'] ?? 'Seminar'), [], true) ?>
        <?= ui_input('venue', 'Venue', (string) ($editRow['venue'] ?? ''), [], true) ?>
      </div>

      <div class="grid cols-3">
        <?= ui_input('event_date', 'Date', (string) ($editRow['event_date'] ?? ''), ['type' => 'date'], true) ?>
        <?= ui_input('start_time', 'Starts', $editRow !== null ? substr((string) $editRow['start_time'], 0, 5) : '', ['type' => 'time'], true) ?>
        <?= ui_input('end_time', 'Ends', $editRow !== null ? substr((string) $editRow['end_time'], 0, 5) : '', ['type' => 'time'], true) ?>
      </div>

      <?= ui_textarea('description', 'Description', (string) ($editRow['description'] ?? ''), ['rows' => '4', 'hint' => 'Objectives, programme and who should attend.']) ?>

      <div class="grid cols-3">
        <?= ui_input('organizer', 'Organizer', (string) ($editRow['organizer'] ?? (string) $org['name'])) ?>
        <?= ui_input('max_participants', 'Participant limit', (string) (int) ($editRow['max_participants'] ?? 0), ['type' => 'number', 'min' => '0', 'hint' => '0 = no limit.']) ?>
        <?= ui_input('grace_minutes', 'Grace period (minutes)', (string) (int) ($editRow['grace_minutes'] ?? DEFAULT_GRACE_MINUTES), ['type' => 'number', 'min' => '0']) ?>
      </div>

      <?= ui_input('registration_deadline', 'Registration deadline',
            $editRow !== null && !empty($editRow['registration_deadline']) ? substr((string) $editRow['registration_deadline'], 0, 16) : '',
            ['type' => 'datetime-local', 'hint' => 'Optional — leave empty for no deadline.']) ?>

      <label class="field field-check mb">
        <input type="checkbox" name="requires_registration" value="1" <?= (int) ($editRow['requires_registration'] ?? 1) === 1 ? 'checked' : '' ?>>
        <span>Students must register before attending.</span>
      </label>

      <div class="form-actions">
        <button class="btn" type="submit"><?= icon('check', 16) ?><span><?= $editRow !== null ? 'Save changes' : 'Create event' ?></span></button>
        <a class="btn grey" href="<?= Helpers::e(Helpers::url('organization/events.php')) ?>"><?= icon('close', 16) ?><span>Cancel</span></a>
      </div>
    </form>
  </section>

<?php else:
    $event     = $editRow ?? $loadEvent(Helpers::getInt('id'));
    $flow      = ['draft', 'pending_adviser', 'pending_admin', 'approved'];
    $stepIndex = array_search((string) $event['status'], $flow, true);
    $payload   = Qr::eventPayload($event);
    $token     = Qr::eventToken($event);
    $stats     = AttendanceRepo::stats((int) $event['id']);
?>
  <div class="stat-grid">
    <?= ui_stat('Status', ui_status((string) $event['status']), 'clipboard') ?>
    <?= ui_stat('Registered', (int) $event['registered_count'], 'users') ?>
    <?= ui_stat('Attendance', (int) $stats['total'], 'qr') ?>
    <?= ui_stat('Participant limit', (int) $event['max_participants'] > 0 ? (int) $event['max_participants'] : '—', 'chart') ?>
  </div>

  <div class="grid cols-2">
    <section class="card">
      <div class="card-head">
        <h3><?= Helpers::e((string) $event['title']) ?></h3>
        <?= ui_status_badge((string) $event['status']) ?>
      </div>
      <dl class="detail-list">
        <dt>Event code</dt><dd><code><?= Helpers::e((string) $event['event_code']) ?></code></dd>
        <dt>Type</dt><dd><?= Helpers::e((string) $event['event_type']) ?></dd>
        <dt>Date</dt><dd><?= Helpers::e(Helpers::fmtDate((string) $event['event_date'], 'l, F j, Y')) ?></dd>
        <dt>Time</dt><dd><?= Helpers::e(Helpers::fmtTime((string) $event['start_time'])) ?> – <?= Helpers::e(Helpers::fmtTime((string) $event['end_time'])) ?></dd>
        <dt>Venue</dt><dd><?= Helpers::e((string) $event['venue']) ?></dd>
        <dt>Organizer</dt><dd><?= Helpers::e((string) $event['organizer']) ?></dd>
        <dt>Deadline</dt>
        <dd><?= !empty($event['registration_deadline']) ? Helpers::e(Helpers::fmtDateTime((string) $event['registration_deadline'])) : '<span class="muted">No deadline</span>' ?></dd>
        <dt>Registrations</dt>
        <dd><?= (int) $event['registered_count'] ?><?= (int) $event['max_participants'] > 0 ? ' / ' . (int) $event['max_participants'] : '' ?>
            · <?= (int) $event['attendance_count'] ?> attended</dd>
        <dt>Description</dt><dd><?= $event['description'] !== null && (string) $event['description'] !== '' ? nl2br(Helpers::e((string) $event['description'])) : '<span class="muted">—</span>' ?></dd>
        <?php if (!empty($event['rejection_reason'])): ?>
          <dt>Rejection reason</dt><dd><?= Helpers::e((string) $event['rejection_reason']) ?></dd>
        <?php endif; ?>
        <dt>Submitted</dt><dd><?= !empty($event['submitted_at']) ? Helpers::e(Helpers::fmtDateTime((string) $event['submitted_at'])) : '<span class="muted">Not submitted yet</span>' ?></dd>
        <dt>Approved</dt><dd><?= !empty($event['approved_at']) ? Helpers::e(Helpers::fmtDateTime((string) $event['approved_at'])) : '<span class="muted">Not approved yet</span>' ?></dd>
      </dl>

      <hr class="divider">
      <div class="btn-row">
        <?php if ((string) $event['status'] === 'draft'): ?>
          <a class="btn ghost" href="<?= Helpers::e(Helpers::url('organization/events.php?edit=' . (int) $event['id'])) ?>"><?= icon('edit', 16) ?><span>Edit</span></a>
          <form method="post" class="inline-form" data-confirm="Submit this event for adviser endorsement?">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="submit">
            <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
            <button class="btn" type="submit"><?= icon('upload', 16) ?><span>Submit for approval</span></button>
          </form>
        <?php endif; ?>
        <?php if (!in_array((string) $event['status'], ['cancelled', 'completed', 'rejected'], true)): ?>
          <form method="post" class="inline-form" data-confirm="Cancel this event?">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
            <button class="btn sm danger" type="submit"><?= icon('close', 15) ?><span>Cancel event</span></button>
          </form>
        <?php endif; ?>
        <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('organization/attendance.php?event_id=' . (int) $event['id'])) ?>"><?= icon('qr', 15) ?><span>Attendance</span></a>
      </div>
    </section>

    <div class="stack">
      <section class="card">
        <div class="card-head"><h3>Approval workflow</h3></div>
        <div class="cluster">
          <?php foreach ($flow as $i => $step): ?>
            <?php if ($stepIndex !== false && $i > 0): ?><?= icon('chevron-right', 15) ?><?php endif; ?>
            <?php if ($stepIndex !== false && $i < (int) $stepIndex): ?>
              <?= ui_badge(ui_status($step), 'green') ?>
            <?php elseif ($stepIndex !== false && $i === (int) $stepIndex): ?>
              <?= ui_badge(ui_status($step), 'blue') ?>
            <?php else: ?>
              <?= ui_badge(ui_status($step), 'grey') ?>
            <?php endif; ?>
          <?php endforeach; ?>
          <?php if ($stepIndex === false): ?>
            <?= ui_status_badge((string) $event['status']) ?>
            <span class="muted small">This event left the regular approval path.</span>
          <?php endif; ?>
        </div>
        <p class="hint mt-tight">The adviser endorses the event, then the administrator publishes it. Only a draft can be edited or submitted.</p>
      </section>

      <section class="card">
        <div class="card-head">
          <h3><?= icon('qr', 17) ?> Venue QR poster</h3>
          <button class="btn sm" type="button" onclick="window.print()"><?= icon('print', 15) ?><span>Print poster</span></button>
        </div>
        <?php if (!in_array((string) $event['status'], ['approved', 'ongoing'], true)): ?>
          <p class="hint">The QR code becomes valid for check-in once the event is approved.</p>
        <?php endif; ?>
        <div class="padded-hint" style="text-align:center">
          <div data-qr="<?= Helpers::e($payload) ?>" data-qr-cell="6"
               data-qr-label="<?= Helpers::e('QR code for ' . $event['title']) ?>"></div>
          <p class="kicker mt-tight"><?= Helpers::e((string) $event['title']) ?></p>
          <p class="muted small"><?= Helpers::e(Helpers::fmtDate((string) $event['event_date'], 'M j, Y')) ?>
            · <?= Helpers::e(Helpers::fmtTime((string) $event['start_time'])) ?> · <?= Helpers::e((string) $event['venue']) ?></p>
          <p class="small"><code><?= Helpers::e($token) ?></code></p>
        </div>
        <p class="hint">Scan the code at the venue, or open the link from a phone camera. Re-issuing the code voids every printed copy.</p>
        <div class="btn-row">
          <a class="btn sm ghost" href="<?= Helpers::e(Helpers::absoluteUrl('scan.php?t=' . urlencode($token))) ?>"><?= icon('external', 15) ?><span>Check-in link</span></a>
          <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('organization/attendance.php?event_id=' . (int) $event['id'])) ?>"><?= icon('list', 15) ?><span>Attendance list</span></a>
        </div>
      </section>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
