<?php
/**
 * organization/calendar.php — organization events calendar for officers.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireRole('officer');

$org = Permissions::activeOrganization();
if ($org === null) {
    Helpers::flash('error', 'You must be assigned to an active organization.');
    Helpers::redirect('organization/dashboard.php');
}
$orgId = (int) $org['id'];

$month = (string) (Helpers::get('month') ?? '');
if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
    $month = date('Y-m');
}

$firstTs     = (int) strtotime($month . '-01');
$daysInMonth = (int) date('t', $firstTs);
$lead        = ((int) date('N', $firstTs)) - 1;
$from        = $month . '-01';
$to          = date('Y-m-t', $firstTs);
$prevMonth   = date('Y-m', (int) strtotime($month . '-01 -1 month'));
$nextMonth   = date('Y-m', (int) strtotime($month . '-01 +1 month'));

$events = EventRepo::forCalendar($from, $to, $orgId);

$byDay = [];
$counts = ['pending_adviser' => 0, 'pending_admin' => 0, 'approved' => 0, 'completed' => 0];
foreach ($events as $event) {
    $byDay[substr((string) $event['event_date'], 0, 10)][] = $event;
    $st = (string) $event['status'];
    if (isset($counts[$st])) {
        $counts[$st]++;
    }
}

$monthLabel = date('F Y', $firstTs);

$PAGE_TITLE       = 'Activity Calendar';
$PAGE_ACTIVE      = 'calendar';
$PAGE_SUB         = Helpers::e((string) $org['name']) . ' · ' . $monthLabel . ' schedule';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'organization/dashboard.php'], ['label' => 'Calendar']];
$PAGE_ACTIONS     = '<div class="btn-row">'
    . '<a class="btn sm ghost" href="' . Helpers::e(Helpers::url('organization/calendar.php?month=' . $prevMonth)) . '">'
    . icon('chevron-left', 15) . '<span>' . Helpers::e(date('M Y', (int) strtotime($prevMonth . '-01'))) . '</span></a>'
    . '<a class="btn sm" href="' . Helpers::e(Helpers::url('organization/calendar.php?month=' . date('Y-m'))) . '">'
    . icon('refresh', 15) . '<span>This Month</span></a>'
    . '<a class="btn sm ghost" href="' . Helpers::e(Helpers::url('organization/calendar.php?month=' . $nextMonth)) . '">'
    . '<span>' . Helpers::e(date('M Y', (int) strtotime($nextMonth . '-01'))) . '</span>' . icon('chevron-right', 15) . '</a>'
    . '</div>';

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="stat-grid">
  <?= ui_stat('Events This Month', count($events), 'calendar') ?>
  <?= ui_stat('Under Review', $counts['pending_adviser'] + $counts['pending_admin'], 'clock', 'amber') ?>
  <?= ui_stat('Approved & Upcoming', $counts['approved'], 'check-circle', 'green') ?>
  <?= ui_stat('Completed', $counts['completed'], 'doc-check') ?>
</div>

<div class="grid side-main">
  <section class="card flush">
    <?= ui_calendar($month, $byDay, [
        'label' => $monthLabel,
        'sub'   => count($events) . ' scheduled activities',
        'right' => '<a class="btn sm" href="' . Helpers::e(Helpers::url('organization/events.php')) . '">'
            . icon('plus', 14) . '<span>Schedule Event</span></a>',
        'more'  => 'organization/events.php',
        'map'   => static function (array $one): array {
            return [
                'href'  => 'organization/events.php?id=' . (int) $one['id'],
                'time'  => Helpers::fmtTime((string) $one['start_time']),
                'title' => (string) $one['title'],
                'tone'  => ui_tone((string) $one['status']),
                'tip'   => (string) $one['title'] . ' · ' . ui_status((string) $one['status']),
            ];
        },
    ]) ?>
  </section>

  <section class="card">
    <div class="card-head">
      <h3>Events Timeline</h3>
      <span class="muted small"><?= count($events) ?> event(s)</span>
    </div>

    <?php if ($events === []): ?>
      <?= ui_empty('No events scheduled for this month.', 'Propose or create an event in the Events section.', 'calendar') ?>
    <?php else: ?>
      <ul class="timeline">
        <?php foreach ($events as $event): ?>
          <li>
            <div class="tl-time">
              <?= Helpers::e(Helpers::fmtDate((string) $event['event_date'], 'D, M j')) ?> · <?= Helpers::e(Helpers::fmtTime((string) $event['start_time'])) ?>
            </div>
            <div class="tl-title">
              <a href="<?= Helpers::e(Helpers::url('organization/events.php?id=' . (int) $event['id'])) ?>">
                <?= Helpers::e((string) $event['title']) ?>
              </a>
            </div>
            <div class="tl-desc">
              <?= ui_status_badge((string) $event['status']) ?>
              <span class="muted small">· <?= Helpers::e((string) $event['venue']) ?></span>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
