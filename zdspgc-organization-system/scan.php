<?php
/**
 * scan.php — QR check-in station.
 *
 * Works three ways:
 *  1. deep link  scan.php?t=<token>   (a student opens the poster QR)
 *  2. camera     the in-app scanner posts the scanned token to the API
 *  3. manual     token or Student ID typed (always available as a fallback)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

Auth::requireLogin();

$isStaff    = Permissions::can('record_attendance');
$staffOrgIds = $isStaff ? Permissions::managedOrganizationIds() : [];

if (!$isStaff && Auth::role() !== 'student') {
    Helpers::flash('error', 'Your role cannot use the check-in station.');
    Helpers::redirect(Permissions::homeForRole());
}

$events = [];
if ($isStaff) {
    $events = Database::all(
        'SELECT ev.id, ev.title, ev.event_date, ev.start_time FROM events ev
          WHERE ev.status IN ("approved","ongoing")
            AND ev.event_date BETWEEN CURDATE() - INTERVAL 1 DAY AND CURDATE() + INTERVAL 7 DAY'
            . ($staffOrgIds === [] ? '' : ' AND ev.organization_id IN (' . implode(',', array_map('intval', $staffOrgIds)) . ')')
          . ' ORDER BY ev.event_date ASC, ev.start_time ASC LIMIT 20'
    );
}

/* Staff manual entry: a Student ID becomes an attendance row. */
$manualMessage = null;
if ($isStaff && Helpers::isPost() && (string) (Helpers::post('manual') ?? '') === '1') {
    Security::requireCsrf();
    $eventId = Helpers::postInt('event_id');
    $value   = trim((string) (Helpers::post('token') ?? ''));
    $row     = EventRepo::find($eventId);

    if ($row === null || !Permissions::canManageOrganization((int) $row['organization_id'])) {
        $manualMessage = ['bad', 'Select an event of your organization first.'];
    } else {
        $window = EventRepo::eventWindow($row);
        $now    = time();
        if ($now < $window['start_ts'] - (EARLY_CHECKIN_MINUTES * 60) || $now > $window['end_ts'] + (LATE_CHECKIN_HOURS * 3600)) {
            $manualMessage = ['warn', 'The check-in window for that event is not open.'];
        } else {
            $student = StudentRepo::findByStudentId($value)
                ?? Database::one('SELECT * FROM students WHERE student_id = :s', ['s' => strtoupper($value)]);
            if ($student === null) {
                $manualMessage = ['bad', 'No student matches "' . $value . '".'];
            } else {
                $status  = $now <= $window['start_ts'] + ($window['grace_minutes'] * 60) ? 'present' : 'late';
                $record  = AttendanceRepo::record($eventId, (int) $student['id'], $status, 'manual');
                $manualMessage = $record['ok']
                    ? ['ok', $record['message'] . ' ' . (string) $student['first_name'] . ' ' . (string) $student['last_name']]
                    : ['bad', $record['message']];
            }
        }
    }
}

$token   = (string) (Helpers::get('t') ?? '');
$event   = $token === '' ? null : Qr::eventFromToken(Qr::tokenFromScan($token));
$verdict = null;
if ($event !== null && Auth::role() === 'student') {
    $verdict = AttendanceRepo::selfCheckIn((int) $event['id'], Permissions::studentId());
}

/* Student alternative: send a check-in request instead of scanning. */
$requestMessage = null;
if (!$isStaff && Helpers::isPost() && (string) (Helpers::post('request_checkin') ?? '') === '1') {
    Security::requireCsrf();
    $result          = AttendanceRepo::requestCheckIn(Helpers::postInt('request_event_id'), Permissions::studentId());
    $requestMessage  = [$result['ok'] ? 'ok' : 'bad', $result['message']];
}

/* Staff approves or rejects a pending check-in request. */
$decideMessage = null;
if ($isStaff && Helpers::isPost() && (string) (Helpers::post('checkin_decide') ?? '') === '1') {
    Security::requireCsrf();
    $requestId = Helpers::postInt('request_id');
    $decision  = (string) (Helpers::post('decision') ?? '') === 'approve' ? 'approve' : 'reject';
    $target    = Database::one(
        'SELECT e.organization_id FROM checkin_requests cr JOIN events e ON e.id = cr.event_id WHERE cr.id = :id',
        ['id' => $requestId]
    );
    if ($target === null || !Permissions::canManageOrganization((int) $target['organization_id'])) {
        $decideMessage = ['bad', 'That request does not belong to your organization.'];
    } else {
        $result         = AttendanceRepo::decideRequest($requestId, $decision);
        $decideMessage  = [$result['ok'] ? 'ok' : 'bad', $result['message']];
    }
}

/* Which events a student may request right now, and their latest request. */
$requestEvents = [];
$myRequest     = null;
if (!$isStaff) {
    $requestEvents = Database::all(
        'SELECT ev.id, ev.title, ev.event_date, ev.start_time FROM events ev
          WHERE ev.status IN ("approved","ongoing")
            AND NOW() >= TIMESTAMP(ev.event_date, ev.start_time) - INTERVAL ' . (int) EARLY_CHECKIN_MINUTES . ' MINUTE
            AND NOW() <= TIMESTAMP(ev.event_date, ev.end_time) + INTERVAL ' . (int) LATE_CHECKIN_HOURS . ' HOUR
          ORDER BY ev.event_date ASC, ev.start_time ASC LIMIT 20'
    );
    $myRequest = Database::one(
        'SELECT cr.status, cr.requested_at, e.title AS event_title
           FROM checkin_requests cr JOIN events e ON e.id = cr.event_id
          WHERE cr.student_id = :s ORDER BY cr.id DESC LIMIT 1',
        ['s' => Permissions::studentId()]
    );
}
$pendingRequests = $isStaff ? AttendanceRepo::pendingRequests($staffOrgIds) : [];

/* Staff scans with the camera; students see a feature notice instead. */
$EXTRA_JS = $isStaff ? ['vendor/html5-qrcode.min.js', 'scanner.js'] : [];

$PAGE_TITLE  = $isStaff ? 'Attendance scan station' : 'QR check-in';
$PAGE_ACTIVE = 'scan';
$PAGE_SUB    = $isStaff
    ? 'Scan a student QR ID, or approve the check-in requests students sent from their phones.'
    : 'Scan the venue QR code, or send a check-in request for your adviser to confirm.';

require __DIR__ . '/includes/layout/header.php';
?>

<?php if ($verdict !== null): ?>
  <div class="scan-result <?= $verdict['ok'] ? ($verdict['status'] === 'present' ? 'ok' : 'warn') : 'bad' ?>">
    <?= icon($verdict['ok'] ? 'check-circle' : 'alert', 26) ?>
    <div>
      <strong><?= Helpers::e($verdict['ok'] ? ($verdict['status'] === 'present' ? 'Present' : 'Late') : 'Check-in refused') ?></strong>
      <div class="small"><?= Helpers::e($verdict['message']) ?></div>
    </div>
  </div>
<?php elseif ($event !== null): ?>
  <div class="scan-result warn">
    <?= icon('info', 26) ?>
    <div><strong>Event QR recognised</strong>
      <div class="small"><?= Helpers::e((string) $event['title']) ?> — use the camera or the manual box to complete the check-in.</div></div>
  </div>
<?php endif; ?>

<?php if ($event !== null): ?>
  <section class="card">
    <div class="org-cell gap-wide">
      <?= icon('calendar', 30) ?>
      <div>
        <h2 class="mb-tight"><?= Helpers::e((string) $event['title']) ?></h2>
        <p class="muted no-gap">
          <?= Helpers::e(Helpers::fmtDate((string) $event['event_date'], 'l, F j, Y')) ?>
          · <?= Helpers::e(Helpers::fmtTime((string) $event['start_time'])) ?>
          · <?= Helpers::e((string) $event['venue']) ?>
        </p>
      </div>
    </div>
  </section>
<?php endif; ?>

<?php if ($manualMessage !== null): ?>
  <div class="scan-result <?= $manualMessage[0] ?>">
    <?= icon($manualMessage[0] === 'ok' ? 'check-circle' : 'alert', 24) ?>
    <div><strong>Attendance</strong><div class="small"><?= Helpers::e($manualMessage[1]) ?></div></div>
  </div>
<?php endif; ?>

<?php foreach ([$requestMessage, $decideMessage] as $msg): if ($msg === null) { continue; } ?>
  <div class="scan-result <?= Helpers::e($msg[0]) ?>">
    <?= icon($msg[0] === 'ok' ? 'check-circle' : 'alert', 24) ?>
    <div><strong>Check-in</strong><div class="small"><?= Helpers::e($msg[1]) ?></div></div>
  </div>
<?php endforeach; ?>

<div class="grid cols-2">
  <?php if ($isStaff): ?>
    <section class="card">
      <div class="card-head"><h3>Record attendance</h3></div>
      <form method="post" action="scan.php">
        <?= Security::csrfField() ?>
        <input type="hidden" name="manual" value="1">
        <?= ui_select('event_id', 'Event', array_combine(
            array_map(static fn (array $e): int => (int) $e['id'], $events),
            array_map(static fn (array $e): string => (string) $e['title'] . ' — ' . Helpers::fmtDate((string) $e['event_date']), $events)
        ), (string) (Helpers::post('event_id') ?? ($event !== null ? (int) $event['id'] : 0)), ['placeholder' => 'Select an event…']) ?>
        <?= ui_input('token', 'QR token or Student ID', '', ['placeholder' => 'Paste a token, or type e.g. 2024-00412']) ?>
        <div class="form-actions">
          <button class="btn" type="submit"><?= icon('check', 16) ?><span>Record attendance</span></button>
        </div>
      </form>

      <div class="card-head spaced-head"><h3>Scan a student QR ID</h3></div>
      <div class="scanner-shell" id="scanner-region"></div>
      <p class="hint mt">The event is taken from the selection above. Officers scan the student's own ID card.</p>
    </section>

    <section class="card">
      <div class="card-head">
        <div>
          <h3>Check-in requests</h3>
          <p class="sub muted small"><?= count($pendingRequests) ?> pending · students who could not scan</p>
        </div>
      </div>
      <?php if ($pendingRequests === []): ?>
        <?= ui_empty('No pending check-in requests.', 'Requests appear here when students cannot use the camera and ask for confirmation instead.', 'clock') ?>
      <?php else: ?>
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
                  <td class="small"><?= Helpers::e(Helpers::excerpt((string) $req['event_title'], 40)) ?><br><span class="muted"><?= Helpers::e(Helpers::fmtDate((string) $req['event_date'])) ?></span></td>
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
      <?php endif; ?>
    </section>
  <?php else: ?>
    <section class="card">
      <div class="card-head">
        <h3>Request a check-in</h3>
      </div>

      <?php if ($myRequest !== null && $myRequest['status'] === 'pending'): ?>
        <div class="scan-result warn">
          <?= icon('clock', 24) ?>
          <div>
            <strong>Waiting for confirmation</strong>
            <div class="small"><?= Helpers::e((string) $myRequest['event_title']) ?> — requested <?= Helpers::e(Helpers::fmtDateTime((string) $myRequest['requested_at'])) ?>. An officer or your adviser will approve it shortly.</div>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($requestEvents === []): ?>
        <?= ui_empty('No event is open for check-in right now.', 'Check-in opens shortly before the event starts.', 'calendar') ?>
      <?php else: ?>
        <form method="post" action="scan.php">
          <?= Security::csrfField() ?>
          <input type="hidden" name="request_checkin" value="1">
          <?= ui_select('request_event_id', 'Event', array_combine(
              array_map(static fn (array $e): int => (int) $e['id'], $requestEvents),
              array_map(static fn (array $e): string => (string) $e['title'] . ' — ' . Helpers::fmtDate((string) $e['event_date']), $requestEvents)
          ), (string) (Helpers::post('request_event_id') ?? ''), ['placeholder' => 'Select an event…']) ?>
          <div class="form-actions">
            <button class="btn" type="submit"><?= icon('clock', 16) ?><span>Send check-in request</span></button>
          </div>
        </form>
        <p class="hint mt">Your attendance time is recorded the moment you send the request — no camera needed. An officer or your adviser confirms it right after.</p>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head"><h3>Scan the event QR code</h3></div>
      <div class="scanner-shell scanner-locked">
        <span class="scanner-lock-ico" aria-hidden="true"><?= icon('qr', 26) ?></span>
        <strong>Camera scanning is not enabled yet</strong>
        <p>Contact the developer to get this feature.</p>
      </div>
      <p class="hint mt">Until then, send a check-in request above, or an officer can check you in with your Student ID.</p>
    </section>
  <?php endif; ?>

  <section class="card">
    <div class="card-head"><h3>How check-in works</h3></div>
    <ul class="timeline">
      <li>
        <div class="tl-time">Step 1</div>
        <div class="tl-title">The event QR code is signed</div>
        <div class="tl-desc">The code contains an HMAC-signed token, not a database id. Re-issuing the code instantly voids the old printout.</div>
      </li>
      <li>
        <div class="tl-time">Step 2</div>
        <div class="tl-title">The server validates it</div>
        <div class="tl-desc">Signature, nonce, event window and duplicate check — all on the server, never in the browser.</div>
      </li>
      <li>
        <div class="tl-time">Step 3</div>
        <div class="tl-title">Present or late is decided automatically</div>
        <div class="tl-desc">A scan after the start time plus the <?= (int) DEFAULT_GRACE_MINUTES ?> minute grace period is recorded as late.</div>
      </li>
    </ul>
    <div id="scan-result" class="scan-result mt" hidden>
      <?= icon('info', 24) ?>
      <div><strong id="scan-title">Ready</strong><div class="small" id="scan-message"></div></div>
    </div>
  </section>
</div>

<?php if ($isStaff): ?>
<script>
(function () {
  var Z = window.ZDSPGC;
  var box = document.getElementById('scanner-region');
  if (!Z || !box || typeof window.Html5Qrcode !== 'function') { return; }
  var eventSelect = document.getElementById('event_id');
  var html5 = new window.Html5Qrcode('scanner-region');

  function show(ok, text) {
    var el = document.getElementById('scan-result');
    var title = document.getElementById('scan-title');
    var msg = document.getElementById('scan-message');
    if (!el || !title || !msg) { return; }
    el.hidden = false;
    el.className = 'scan-result ' + (ok ? 'ok' : 'bad');
    title.textContent = ok ? 'Recorded' : 'Refused';
    msg.textContent = text;
  }

  html5.start(
    { facingMode: 'environment' },
    { fps: 10 },
    function (text) {
      var body = { token: text };
      if (eventSelect && eventSelect.value) { body.event_id = Number(eventSelect.value); }
      Z.api('api/attendance/scan.php', { method: 'POST', body: body })
        .then(function (payload) { show(true, payload.message); })
        .catch(function (error) { show(false, error.message); });
    },
    function () { /* a frame without a code */ }
  ).catch(function (error) {
    box.innerHTML = '<p class="hint padded-hint">Camera unavailable: '
      + (error && error.message ? error.message : 'permission denied') + '. Use manual entry instead.</p>';
  });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout/footer.php'; ?>
