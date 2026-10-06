<?php
/**
 * admin/calendar.php — institution-wide calendar of organization events.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireRole('admin');

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

$orgFilter = Helpers::getInt('organization_id');

$where = ['event_date >= :from', 'event_date <= :to', 'e.status <> "cancelled"'];
$params = ['from' => $from, 'to' => $to];

if ($orgFilter > 0) {
    $where[] = 'organization_id = :org';
    $params['org'] = $orgFilter;
}

$events = Database::all(
    'SELECT e.*, o.name AS organization_name, o.acronym AS organization_acronym '
    . 'FROM events e '
    . 'LEFT JOIN organizations o ON o.id = e.organization_id '
    . 'WHERE ' . implode(' AND ', $where) . ' '
    . 'ORDER BY event_date ASC, start_time ASC',
    $params
);

$byDay = [];
$counts = ['pending_admin' => 0, 'pending_adviser' => 0, 'approved' => 0, 'completed' => 0];
foreach ($events as $event) {
    $byDay[substr((string) $event['event_date'], 0, 10)][] = $event;
    $st = (string) $event['status'];
    if (isset($counts[$st])) {
        $counts[$st]++;
    }
}

$monthLabel = date('F Y', $firstTs);
$orgs = Database::all('SELECT id, name, acronym FROM organizations ORDER BY name');

$PAGE_TITLE       = 'Campus Activities Calendar';
$PAGE_ACTIVE      = 'calendar';
$PAGE_SUB         = $monthLabel . ' · ' . count($events) . ' scheduled activity/activities';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'admin/dashboard.php'], ['label' => 'Calendar']];
$PAGE_ACTIONS     = '<div class="btn-row">'
    . '<a class="btn sm ghost" href="' . Helpers::e(Helpers::url('admin/calendar.php?month=' . $prevMonth . ($orgFilter > 0 ? '&organization_id=' . $orgFilter : ''))) . '">'
    . icon('chevron-left', 15) . '<span>' . Helpers::e(date('M Y', (int) strtotime($prevMonth . '-01'))) . '</span></a>'
    . '<a class="btn sm" href="' . Helpers::e(Helpers::url('admin/calendar.php?month=' . date('Y-m') . ($orgFilter > 0 ? '&organization_id=' . $orgFilter : ''))) . '">'
    . icon('refresh', 15) . '<span>Current Month</span></a>'
    . '<a class="btn sm ghost" href="' . Helpers::e(Helpers::url('admin/calendar.php?month=' . $nextMonth . ($orgFilter > 0 ? '&organization_id=' . $orgFilter : ''))) . '">'
    . '<span>' . Helpers::e(date('M Y', (int) strtotime($nextMonth . '-01'))) . '</span>' . icon('chevron-right', 15) . '</a>'
    . '</div>';

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="stat-grid">
  <?= ui_stat('Events This Month', count($events), 'calendar') ?>
  <?= ui_stat('Pending Admin Review', $counts['pending_admin'], 'alert', $counts['pending_admin'] > 0 ? 'amber' : 'grey') ?>
  <?= ui_stat('Approved & Upcoming', $counts['approved'], 'check-circle', $counts['approved'] > 0 ? 'green' : 'grey') ?>
  <?= ui_stat('Completed', $counts['completed'], 'doc-check') ?>
</div>

<div class="grid side-main">
  <section class="card flush">
    <?php
    $orgForm = '<form method="get" action="' . Helpers::e(Helpers::url('admin/calendar.php')) . '" class="inline">'
        . '<input type="hidden" name="month" value="' . Helpers::e($month) . '">'
        . '<select name="organization_id" onchange="this.form.submit()">'
        . '<option value="">All Organizations</option>';
    foreach ($orgs as $o) {
        $orgForm .= '<option value="' . (int) $o['id'] . '"' . ($orgFilter === (int) $o['id'] ? ' selected' : '') . '>'
            . Helpers::e((string) ($o['acronym'] ?: $o['name'])) . '</option>';
    }
    $orgForm .= '</select></form>';
    ?>
    <?= ui_calendar($month, $byDay, [
        'label' => $monthLabel,
        'sub'   => count($events) . ' scheduled activities',
        'right' => $orgForm,
        'more'  => 'admin/events.php',
        'map'   => static function (array $one): array {
            $org = (string) ($one['organization_acronym'] ?: $one['organization_name']);
            return [
                'href'  => 'admin/events.php?id=' . (int) $one['id'],
                'time'  => Helpers::fmtTime((string) $one['start_time']),
                'title' => (string) $one['title'],
                'tone'  => ui_tone((string) $one['status']),
                'tip'   => $org . ' · ' . (string) $one['title'] . ' · ' . ui_status((string) $one['status']),
            ];
        },
    ]) ?>
  </section>

  <section class="card">
    <div class="card-head">
      <h3>Scheduled Activities (<?= Helpers::e($monthLabel) ?>)</h3>
      <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('admin/events.php')) ?>">Events Manager</a>
    </div>

    <?php if ($events === []): ?>
      <?= ui_empty('No events scheduled for this month.', 'Events submitted by student organizations will appear on the calendar once scheduled.', 'calendar') ?>
    <?php else: ?>
      <ul class="timeline">
        <?php foreach ($events as $event): ?>
          <li>
            <div class="tl-time">
              <?= Helpers::e(Helpers::fmtDate((string) $event['event_date'], 'D, M j')) ?> · <?= Helpers::e(Helpers::fmtTime((string) $event['start_time'])) ?>
            </div>
            <div class="tl-title">
              <a href="<?= Helpers::e(Helpers::url('admin/events.php?id=' . (int) $event['id'])) ?>">
                <?= Helpers::e((string) $event['title']) ?>
              </a>
            </div>
            <div class="tl-desc">
              <?= ui_status_badge((string) $event['status']) ?>
              <span class="muted small">
                · <?= Helpers::e((string) ($event['organization_acronym'] ?: $event['organization_name'])) ?>
                · <?= Helpers::e((string) $event['venue']) ?>
              </span>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
