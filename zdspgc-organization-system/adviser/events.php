<?php
/**
 * adviser/events.php — event monitoring for adviser's assigned organizations.
 * Includes review/endorsement workflow for pending events.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireRole('adviser');
Permissions::requireCapability('review_events');

$adviserId = Permissions::adviserId();
$orgIds    = Permissions::managedOrganizationIds();
$inList    = $orgIds === [] ? 'NULL' : implode(',', array_map('intval', $orgIds));

/* ── Adviser endorsement POST ── */
if (Helpers::isPost()) {
    Security::requireCsrf();
    $action  = (string) (Helpers::post('action')  ?? '');
    $eventId = (int)    (Helpers::post('event_id') ?? 0);
    $remarks = (string) (Helpers::post('remarks')  ?? '');

    try {
        $event = EventRepo::find($eventId);
        if ($event === null || !in_array((int)$event['organization_id'], $orgIds, true)) {
            throw new RuntimeException('Event not found or not in your scope.');
        }
        if ($action === 'endorse') {
            Database::update('events',
                ['status' => 'pending_admin', 'updated_at' => Helpers::now(),
                 'adviser_remarks' => $remarks],
                'id = :id', ['id' => $eventId]);
            Helpers::flash('success', 'Event endorsed and forwarded to the administrator.');
        } elseif ($action === 'return') {
            Database::update('events',
                ['status' => 'revision_required', 'updated_at' => Helpers::now(),
                 'adviser_remarks' => $remarks],
                'id = :id', ['id' => $eventId]);
            Helpers::flash('success', 'Event returned to the organization for revision.');
        }
    } catch (Throwable $e) {
        Helpers::flash('error', $e->getMessage());
    }
    Helpers::redirect('adviser/events.php');
}

/* ── Read ── */
$q        = (string) (Helpers::get('q')        ?? '');
$orgId    = (int)    (Helpers::get('org_id')   ?? 0);
$status   = (string) (Helpers::get('status')   ?? '');
$fromDate = (string) (Helpers::get('from')     ?? '');
$toDate   = (string) (Helpers::get('to')       ?? '');
$viewId   = (int)    (Helpers::get('id')       ?? 0);
$page     = max(1, (int) (Helpers::get('page') ?? 1));
$perPage  = 20;

if ($orgId > 0 && !in_array($orgId, $orgIds, true)) {
    $orgId = 0;
}
$scopedList = $orgId > 0 ? (string)$orgId : $inList;

$viewEvent = null;
if ($viewId > 0) {
    $viewEvent = EventRepo::find($viewId);
    if ($viewEvent !== null && !in_array((int)$viewEvent['organization_id'], $orgIds, true)) {
        $viewEvent = null;
    }
}

$sql    = "SELECT ev.*, o.name AS organization_name, o.acronym AS organization_acronym,
                  ay.name AS academic_year_name,
                  (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = ev.id AND r.status='registered') AS registered_count,
                  (SELECT COUNT(*) FROM attendance a WHERE a.event_id = ev.id AND a.status='present') AS present_count
           FROM events ev
           JOIN organizations o ON o.id = ev.organization_id
           LEFT JOIN academic_years ay ON ay.id = ev.academic_year_id
           WHERE ev.organization_id IN ($scopedList)";
$params = [];
if ($status !== '') {
    $sql .= ' AND ev.status = :s';
    $params['s'] = $status;
}
if ($fromDate !== '') {
    $sql .= ' AND ev.event_date >= :from';
    $params['from'] = $fromDate;
}
if ($toDate !== '') {
    $sql .= ' AND ev.event_date <= :to';
    $params['to'] = $toDate;
}
if ($q !== '') {
    $sql .= ' AND (ev.title LIKE :q OR ev.venue LIKE :q2)';
    $params['q'] = '%' . $q . '%';
    $params['q2'] = '%' . $q . '%';
}

$total  = (int) Database::scalar('SELECT COUNT(*) FROM (' . $sql . ') AS cnt', $params);
$pages  = (int) max(1, ceil($total / $perPage));
$events = Database::all($sql . ' ORDER BY ev.event_date DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);

$orgOptions = [];
foreach (OrgRepo::list(['adviser_id' => $adviserId], 1, 100)['rows'] as $org) {
    $orgOptions[(int) $org['id']] = (string) $org['name'];
}
$statusOptions = [
    'pending_adviser' => 'Pending Endorsement',
    'pending_admin'   => 'Pending Admin',
    'approved'        => 'Approved',
    'completed'       => 'Completed',
    'cancelled'       => 'Cancelled',
    'revision_required' => 'Revision Required',
];
$queryStr = http_build_query(array_filter(['q' => $q, 'org_id' => $orgId ?: '', 'status' => $status, 'from' => $fromDate, 'to' => $toDate]));

$PAGE_TITLE       = 'Event Monitoring';
$PAGE_ACTIVE      = 'events';
$PAGE_SUB         = $total . ' event(s) across your assigned organizations.';
$PAGE_BREADCRUMBS = [
    ['label' => 'Dashboard', 'href' => 'adviser/dashboard.php'],
    ['label' => 'Events'],
];
require __DIR__ . '/../includes/layout/header.php';
?>

<?php if ($orgIds === []): ?>
  <section class="card"><?= ui_empty('No organizations assigned yet.', '', 'calendar') ?></section>
<?php else: ?>

<?php if ($viewEvent !== null): ?>
  <!-- ── Event detail / review panel ── -->
  <section class="card">
    <div class="card-head">
      <div>
        <h3><?= Helpers::e((string)$viewEvent['title']) ?></h3>
        <p class="sub muted small"><?= Helpers::e((string)$viewEvent['organization_name']) ?> · <?= Helpers::e(Helpers::fmtDate((string)$viewEvent['event_date'])) ?></p>
      </div>
      <div class="cluster">
        <?= ui_status_badge((string)$viewEvent['status']) ?>
        <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('adviser/events.php')) ?>">
          <?= icon('close', 14) ?><span>Close</span>
        </a>
      </div>
    </div>

    <div class="grid cols-2">
      <div>
        <p><strong>Date:</strong> <?= Helpers::e(Helpers::fmtDate((string)$viewEvent['event_date'])) ?></p>
        <p><strong>Time:</strong> <?= Helpers::e(Helpers::fmtTime((string)$viewEvent['start_time'])) ?><?= $viewEvent['end_time'] ? ' — ' . Helpers::e(Helpers::fmtTime((string)$viewEvent['end_time'])) : '' ?></p>
        <p><strong>Venue:</strong> <?= Helpers::e((string)$viewEvent['venue']) ?></p>
        <p><strong>Type:</strong> <?= Helpers::e((string)$viewEvent['event_type']) ?></p>
        <p><strong>Organization:</strong> <?= Helpers::e((string)$viewEvent['organization_name']) ?></p>
      </div>
      <div>
        <p><strong>Description:</strong><br><?= nl2br(Helpers::e((string)$viewEvent['description'])) ?></p>
      </div>
    </div>

    <?php if ((string)$viewEvent['status'] === 'pending_adviser'): ?>
      <hr>
      <h4>Your Review</h4>
      <div class="grid cols-2">
        <form method="post">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="endorse">
          <input type="hidden" name="event_id" value="<?= (int)$viewEvent['id'] ?>">
          <?= ui_textarea('remarks', 'Endorsement remarks (optional)', '', ['rows' => '3', 'placeholder' => 'Add any comments or conditions for this event…']) ?>
          <button class="btn" type="submit"><?= icon('check-circle', 16) ?><span>Endorse &amp; forward to Admin</span></button>
        </form>
        <form method="post">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="return">
          <input type="hidden" name="event_id" value="<?= (int)$viewEvent['id'] ?>">
          <?= ui_textarea('remarks', 'Return remarks (required for revision)', '', ['rows' => '3', 'placeholder' => 'Explain what needs to be revised…']) ?>
          <button class="btn grey" type="submit"><?= icon('alert', 16) ?><span>Return for revision</span></button>
        </form>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?= ui_filter_form('adviser/events.php',
    ui_filter_input('q', 'Search', $q) .
    (count($orgOptions) > 1 ? ui_filter_select('org_id', 'Organization', $orgOptions, (string)$orgId, 'All organizations') : '') .
    ui_filter_select('status', 'Status', $statusOptions, $status, 'All statuses') .
    ui_filter_input('from', 'From date', $fromDate, 'date') .
    ui_filter_input('to', 'To date', $toDate, 'date')
) ?>

<section class="card">
  <div class="card-head">
    <div>
      <h3>Events</h3>
      <p class="sub muted small"><?= $total ?> record(s) · page <?= $page ?> of <?= $pages ?></p>
    </div>
  </div>

  <?php if ($events === []): ?>
    <?= ui_empty('No events found.', 'Try adjusting the search or status filter.', 'calendar') ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Event</th>
            <th>Organization</th>
            <th>Date</th>
            <th>Venue</th>
            <th>Status</th>
            <th class="num">Registered</th>
            <th class="num">Present</th>
            <th class="actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($events as $ev): ?>
            <tr>
              <td><strong><?= Helpers::e(Helpers::excerpt((string)$ev['title'], 50)) ?></strong></td>
              <td class="small"><?= Helpers::e((string)$ev['organization_acronym']) ?></td>
              <td class="small nowrap"><?= Helpers::e(Helpers::fmtDate((string)$ev['event_date'])) ?></td>
              <td class="small"><?= Helpers::e(Helpers::excerpt((string)$ev['venue'], 30)) ?></td>
              <td><?= ui_status_badge((string)$ev['status']) ?></td>
              <td class="num"><?= (int)$ev['registered_count'] ?></td>
              <td class="num"><?= (int)$ev['present_count'] ?></td>
              <td class="actions">
                <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('adviser/events.php?id=' . (int)$ev['id'] . ($orgId ? '&org_id=' . $orgId : ''))) ?>">
                  <?= icon('external', 14) ?><span><?= (string)$ev['status'] === 'pending_adviser' ? 'Review' : 'View' ?></span>
                </a>
              </td>
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
