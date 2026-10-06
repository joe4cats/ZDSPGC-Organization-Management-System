<?php
/**
 * adviser/calendar.php — month calendar of every assigned organization.
 *
 * ?month=YYYY-MM selects the month; the grid merges the events of all the
 * organizations this adviser handles, and the side list shows them by date.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireRole('adviser');

$orgIds = Permissions::managedOrganizationIds();

$month = (string) (Helpers::get('month') ?? '');
if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
    $month = date('Y-m');
}

$firstTs   = (int) strtotime($month . '-01');
$from      = $month . '-01';
$to        = date('Y-m-t', $firstTs);
$prevMonth = date('Y-m', (int) strtotime($month . '-01 -1 month'));
$nextMonth = date('Y-m', (int) strtotime($month . '-01 +1 month'));

$events = [];
if ($orgIds !== []) {
    foreach ($orgIds as $orgId) {
        foreach (EventRepo::forCalendar($from, $to, $orgId) as $event) {
            $events[] = $event;
        }
    }
    usort($events, static fn (array $a, array $b): int =>
        ((string) $a['event_date'] . ' ' . (string) $a['start_time']) <=> ((string) $b['event_date'] . ' ' . (string) $b['start_time']));
}

$byDay  = [];
$counts = ['pending_adviser' => 0, 'approved' => 0, 'completed' => 0, 'rejected' => 0];
foreach ($events as $event) {
    $byDay[substr((string) $event['event_date'], 0, 10)][] = $event;
    $status = (string) $event['status'];
    if (isset($counts[$status])) {
        $counts[$status]++;
    }
}

$prevTs = (int) strtotime($prevMonth . '-01');

$monthLabel = date('F Y', $firstTs);
$rangeLabel = Helpers::fmtDate($from, 'M j') . ' – ' . Helpers::fmtDate($to, 'M j, Y');
$eventWord  = count($events) === 1 ? 'event' : 'events';
$orgWord    = count($orgIds) === 1 ? 'organization' : 'organizations';

$PAGE_TITLE       = 'Calendar';
$PAGE_ACTIVE      = 'calendar';
$PAGE_SUB         = $monthLabel . ' · ' . count($events) . ' ' . $eventWord . ' across ' . count($orgIds) . ' ' . $orgWord;
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'adviser/dashboard.php'], ['label' => 'Calendar']];
$PAGE_ACTIONS     = '<div class="btn-row">'
    . '<a class="btn sm ghost" href="' . Helpers::e(Helpers::url('adviser/calendar.php?month=' . $prevMonth)) . '">'
    . icon('chevron-left', 15) . '<span>' . Helpers::e(date('M Y', $prevTs)) . '</span></a>'
    . '<a class="btn sm" href="' . Helpers::e(Helpers::url('adviser/calendar.php?month=' . date('Y-m'))) . '">'
    . icon('refresh', 15) . '<span>This month</span></a>'
    . '<a class="btn sm ghost" href="' . Helpers::e(Helpers::url('adviser/calendar.php?month=' . $nextMonth)) . '">'
    . '<span>' . Helpers::e(date('M Y', (int) strtotime($nextMonth . '-01'))) . '</span>' . icon('chevron-right', 15) . '</a>'
    . '</div>';

require __DIR__ . '/../includes/layout/header.php';

if ($orgIds === []) {
    echo '<section class="card">' . ui_empty(
        'No organization is assigned to you yet.',
        'The calendar fills in as soon as the Office of Student Affairs assigns you an organization.',
        'user-check'
    ) . '</section>';
    require __DIR__ . '/../includes/layout/footer.php';
    exit;
}
?>

<div class="stat-grid">
  <?= ui_stat('Events this month', count($events), 'calendar') ?>
  <?= ui_stat('Awaiting endorsement', $counts['pending_adviser'], 'clock', $counts['pending_adviser'] > 0 ? 'amber' : 'grey') ?>
  <?= ui_stat('Approved', $counts['approved'], 'check-circle', $counts['approved'] > 0 ? 'green' : 'grey') ?>
  <?= ui_stat('Completed', $counts['completed'], 'doc-check') ?>
</div>

<div class="grid side-main">
  <section class="card flush">
    <?= ui_calendar($month, $byDay, [
        'label' => $monthLabel,
        'sub'   => $rangeLabel . ' · ' . count($events) . ' ' . $eventWord,
        'right' => '<ul class="cal-legend">'
            . '<li><span class="cal-dot amber"></span>Awaiting endorsement</li>'
            . '<li><span class="cal-dot green"></span>Approved</li>'
            . '<li><span class="cal-dot red"></span>Rejected</li>'
            . '</ul>',
        'more'  => 'adviser/events.php',
        'map'   => static function (array $one): array {
            $orgLabel = (string) ($one['organization_acronym'] !== ''
                ? $one['organization_acronym']
                : $one['organization_name']);
            $time     = Helpers::fmtTime((string) $one['start_time']);
            return [
                'href'  => 'adviser/events.php?id=' . (int) $one['id'] . '&organization_id=' . (int) $one['organization_id'],
                'time'  => $time,
                'title' => (string) $one['title'],
                'tone'  => ui_tone((string) $one['status']),
                'tip'   => $orgLabel . ' · ' . (string) $one['title'] . ' · ' . $time . ' · ' . ui_status((string) $one['status']),
            ];
        },
    ]) ?>
  </section>

  <section class="card">
    <div class="card-head">
      <div>
        <h3>Events in <?= Helpers::e($monthLabel) ?></h3>
        <p class="sub">Chronological list of everything scheduled this month</p>
      </div>
      <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('adviser/events.php')) ?>">All events</a>
    </div>
    <?php if ($events === []): ?>
      <?= ui_empty('No event is scheduled in this month.', 'Change the month or check the events module.', 'calendar') ?>
    <?php else: ?>
      <ul class="timeline">
        <?php foreach ($events as $event): ?>
          <li>
            <div class="tl-time"><?= Helpers::e(Helpers::fmtDate((string) $event['event_date'], 'D, M j')) ?>
              · <?= Helpers::e(Helpers::fmtTime((string) $event['start_time'])) ?></div>
            <div class="tl-title">
              <a href="<?= Helpers::e(Helpers::url('adviser/events.php?id=' . (int) $event['id'] . '&organization_id=' . (int) $event['organization_id'])) ?>">
                <?= Helpers::e(Helpers::excerpt((string) $event['title'], 46)) ?></a>
            </div>
            <div class="tl-desc">
              <?= ui_status_badge((string) $event['status']) ?>
              <span class="muted small"> · <?= Helpers::e((string) ($event['organization_acronym'] !== '' ? $event['organization_acronym'] : $event['organization_name'])) ?> · <?= Helpers::e((string) $event['venue']) ?></span>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
