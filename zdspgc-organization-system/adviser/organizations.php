<?php
/**
 * adviser/organizations.php — the organizations assigned to this adviser.
 *
 * Cards with registration status, accreditation, membership counters and
 * quick links into the per-organization review pages of this module.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireRole('adviser');

$orgIds = Permissions::managedOrganizationIds();

$filters = [
    'q'               => (string) (Helpers::get('q') ?? ''),
    'status'          => (string) (Helpers::get('status') ?? ''),
    'adviser_user_id' => Auth::id(),
];

$mine  = OrgRepo::list(['adviser_user_id' => Auth::id()], 1, 100)['rows'];
$total = count($mine);

$totals = ['members' => 0, 'pending' => 0, 'officers' => 0];
foreach ($mine as $one) {
    $counts          = MemberRepo::countByOrganization((int) $one['id']);
    $totals['members']  += (int) $counts['active'];
    $totals['pending']  += (int) $counts['pending'];
    $totals['officers'] += (int) $counts['officers'];
}

$statusOptions = array_combine(
    AcademicRepo::ORGANIZATION_STATUSES,
    array_map(static fn (string $s): string => ucwords(str_replace('_', ' ', $s)), AcademicRepo::ORGANIZATION_STATUSES)
);

$PAGE_TITLE       = 'My Organizations';
$PAGE_ACTIVE      = 'organizations';
$PAGE_SUB         = $total . ' organization(s) assigned to you · ' . SCHOOL_NAME;
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'adviser/dashboard.php'], ['label' => 'Organizations']];
$PAGE_ACTIONS     = '<a class="btn ghost" href="' . Helpers::e(Helpers::url('adviser/calendar.php')) . '">'
    . icon('calendar', 16) . '<span>Calendar</span></a>';

require __DIR__ . '/../includes/layout/header.php';

if ($orgIds === []) {
    echo '<section class="card">' . ui_empty(
        'No organization is assigned to you yet.',
        'The Office of Student Affairs assigns an adviser when an organization registration is approved.',
        'user-check'
    ) . '</section>';
    require __DIR__ . '/../includes/layout/footer.php';
    exit;
}

$result = OrgRepo::list($filters, Helpers::page(), 12);
$page   = min(Helpers::page(), $result['pages']);
$rows   = $result['rows'];
$query  = http_build_query(array_filter($filters));
?>

<div class="stat-grid">
  <?= ui_stat('Organizations advised', $total, 'org') ?>
  <?= ui_stat('Active members', $totals['members'], 'users') ?>
  <?= ui_stat('Officers', $totals['officers'], 'award') ?>
  <?= ui_stat('Pending applications', $totals['pending'], 'clock', $totals['pending'] > 0 ? 'amber' : 'grey') ?>
</div>

<?= ui_filter_form('adviser/organizations.php',
    ui_filter_input('q', 'Search', $filters['q'])
    . ui_filter_select('status', 'Status', $statusOptions, $filters['status'])) ?>

<?php if ($rows === []): ?>
  <section class="card">
    <?= ui_empty('No organization matches this search.', 'Clear the filters to see every organization you advise.', 'org') ?>
  </section>
<?php else: ?>
  <div class="grid cols-2">
    <?php foreach ($rows as $org): $counts = MemberRepo::countByOrganization((int) $org['id']); ?>
      <section class="card">
        <div class="card-head">
          <div class="org-cell">
            <?= ui_org_badge($org, 40) ?>
            <div>
              <h3><?= Helpers::e((string) $org['name']) ?></h3>
              <span class="muted small"><?= Helpers::e((string) $org['acronym']) ?> · <?= Helpers::e((string) $org['organization_code']) ?></span>
            </div>
          </div>
          <div class="cluster">
            <?= ui_status_badge((string) $org['status']) ?>
            <?= ui_status_badge((string) $org['accreditation_status']) ?>
          </div>
        </div>

        <dl class="detail-list">
          <dt>Type</dt>
          <dd><?= Helpers::e((string) $org['organization_type']) ?>
            <?php if ((string) $org['category_name'] !== ''): ?>
              <span class="muted small">· <?= Helpers::e((string) $org['category_name']) ?></span>
            <?php endif; ?></dd>
          <dt>Academic year</dt>
          <dd><?= Helpers::e((string) ($org['academic_year_name'] ?? '—')) ?></dd>
          <dt>Members</dt>
          <dd><strong><?= (int) $counts['active'] ?></strong> active
            · <?= (int) $counts['officers'] ?> officer(s)
            · <?= (int) $counts['pending'] ?> pending application(s)</dd>
          <dt>Adviser</dt>
          <dd><?= Helpers::e((string) ($org['adviser_name'] !== '' ? $org['adviser_name'] : Auth::userName())) ?></dd>
        </dl>

        <div class="btn-row">
          <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('adviser/members.php?org_id=' . (int) $org['id'])) ?>">
            <?= icon('users', 15) ?><span>Members</span></a>
          <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('adviser/officers.php?org_id=' . (int) $org['id'])) ?>">
            <?= icon('award', 15) ?><span>Officers</span></a>
          <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('adviser/events.php?org_id=' . (int) $org['id'])) ?>">
            <?= icon('calendar', 15) ?><span>Events</span></a>
          <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('adviser/proposals.php?org_id=' . (int) $org['id'])) ?>">
            <?= icon('clipboard', 15) ?><span>Proposals</span></a>
          <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('adviser/documents.php?organization_id=' . (int) $org['id'])) ?>">
            <?= icon('folder', 15) ?><span>Documents</span></a>
          <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('adviser/attendance.php?org_id=' . (int) $org['id'])) ?>">
            <?= icon('qr', 15) ?><span>Attendance</span></a>
          <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('adviser/announcements.php?organization_id=' . (int) $org['id'])) ?>">
            <?= icon('megaphone', 15) ?><span>Announcements</span></a>
          <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('organization.php?id=' . (int) $org['id'])) ?>">
            <?= icon('external', 15) ?><span>Public profile</span></a>
        </div>
      </section>
    <?php endforeach; ?>
  </div>

  <?= ui_pagination($page, $result['pages'], $query) ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
