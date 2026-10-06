<?php
/**
 * admin/activity-logs.php — searchable audit trail of the whole system.
 *
 * Entries can be filtered by text, module, action, actor and date range. The
 * counters show the size of the log, today's entries and how many entries
 * match the current filters, the table lists each action with its actor,
 * module, description, reference and IP address.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Permissions::requireCapability('view_logs');

$filters = [
    'q'      => (string) (Helpers::get('q') ?? ''),
    'module' => (string) (Helpers::get('module') ?? ''),
    'action' => (string) (Helpers::get('action') ?? ''),
    'user'   => (string) (Helpers::get('user') ?? ''),
    'from'   => (string) (Helpers::get('from') ?? ''),
    'to'     => (string) (Helpers::get('to') ?? ''),
];

foreach (['from', 'to'] as $dateKey) {
    if ($filters[$dateKey] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$dateKey]) !== 1) {
        $filters[$dateKey] = '';
    }
}

$result = Audit::search($filters, Helpers::page(), 20);
$page   = min(Helpers::page(), $result['pages']);
$rows   = $result['rows'];
$query  = http_build_query(array_filter($filters));

$totalCount = Audit::search([], 1, 1)['total'];
$todayCount = Audit::search(['from' => Helpers::today(), 'to' => Helpers::today()], 1, 1)['total'];

$moduleOptions = array_combine(Audit::MODULES, Audit::MODULES);
$actionList    = Audit::distinctActions();
$actionOptions = array_combine($actionList, $actionList);

$filterFields = ui_filter_input('q', 'Search', $filters['q'])
    . ui_filter_select('module', 'Module', $moduleOptions, $filters['module'])
    . ui_filter_select('action', 'Action', $actionOptions, $filters['action'])
    . ui_filter_input('user', 'User', $filters['user'])
    . ui_filter_input('from', 'From', $filters['from'], 'date')
    . ui_filter_input('to', 'To', $filters['to'], 'date');

$PAGE_TITLE  = 'Activity Logs';
$PAGE_ACTIVE = 'logs';
$PAGE_SUB    = $totalCount . ' recorded entr' . ($totalCount === 1 ? 'y' : 'ies')
    . ' · ' . $todayCount . ' today · ' . $result['total'] . ' matching the current filters';
$PAGE_BREADCRUMBS = [
    ['label' => 'Dashboard', 'href' => 'admin/dashboard.php'],
    ['label' => 'Activity Logs'],
];

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="stat-grid">
  <?= ui_stat('Total entries', $totalCount, 'log') ?>
  <?= ui_stat("Today's entries", $todayCount, 'clock', $todayCount > 0 ? 'blue' : 'grey') ?>
  <?= ui_stat('Matching filters', $result['total'], 'search', $result['total'] > 0 ? 'amber' : 'grey') ?>
  <?= ui_stat('Distinct actions', count($actionOptions), 'shield') ?>
</div>

<?= ui_filter_form('admin/activity-logs.php', $filterFields, 'Apply filters') ?>

<section class="card">
  <div class="card-head">
    <h3>Recorded activity</h3>
    <span class="badge"><?= (int) $result['total'] ?> found</span>
  </div>

  <?php if ($rows === []): ?>
    <?= ui_empty(
        'No activity matches these filters.',
        'Widen the date range or clear the filters to see the recorded entries.',
        'log'
    ) ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Date and time</th><th>Actor</th><th>Action</th><th>Module</th>
            <th>Description</th><th class="num">Reference</th><th>IP address</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td class="small nowrap"><?= Helpers::e(Helpers::fmtDateTime((string) $row['created_at'])) ?></td>
              <td>
                <div class="person-cell">
                  <?= ui_avatar((string) $row['actor_name'], null, 30) ?>
                  <div class="person-meta">
                    <strong><?= Helpers::e(Helpers::excerpt((string) $row['actor_name'], 26)) ?></strong>
                    <small><?= $row['actor_role'] !== '' ? Helpers::e(ucwords(str_replace('_', ' ', (string) $row['actor_role']))) : '—' ?></small>
                  </div>
                </div>
              </td>
              <td><?= ui_badge((string) $row['action'], match (true) {
                    str_contains((string) $row['action'], 'DENIED') || str_contains((string) $row['action'], 'REJECT') => 'red',
                    str_contains((string) $row['action'], 'APPROVE') || str_contains((string) $row['action'], 'PUBLISH')   => 'green',
                    default                                                                                                   => 'blue',
                }) ?></td>
              <td class="small"><?= ui_badge((string) $row['module'], 'grey') ?></td>
              <td class="small"><?= Helpers::e(Helpers::excerpt((string) $row['description'], 150)) ?></td>
              <td class="num small"><?= !empty($row['reference_id']) ? '#' . (int) $row['reference_id'] : '<span class="muted">—</span>' ?></td>
              <td class="small nowrap"><code><?= Helpers::e((string) $row['ip_address']) ?></code></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= ui_pagination($page, $result['pages'], $query) ?>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
