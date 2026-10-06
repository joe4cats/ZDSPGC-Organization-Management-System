<?php
/**
 * organizations.php — public organization directory.
 *
 * No sign-in required. Server-side filters (q, category, department, type) plus
 * an instant client-side filter box on the result list.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$filters = [
    'q'                 => (string) (Helpers::get('q') ?? ''),
    'category_id'       => (string) (Helpers::get('category_id') ?? ''),
    'department_id'     => (string) (Helpers::get('department_id') ?? ''),
    'organization_type' => (string) (Helpers::get('type') ?? ''),
];

$all   = OrgRepo::publicList($filters, 200);
$total = count($all);
$perPage = 12;
$pages  = max(1, (int) ceil($total / $perPage));
$page   = min(Helpers::page(), $pages);
$rows   = array_slice($all, ($page - 1) * $perPage, $perPage);

$query = http_build_query(array_filter([
    'q' => $filters['q'], 'category_id' => $filters['category_id'],
    'department_id' => $filters['department_id'], 'type' => $filters['organization_type'],
]));

$types = array_combine(AcademicRepo::ORGANIZATION_TYPES, AcademicRepo::ORGANIZATION_TYPES);

$PAGE_TITLE  = 'Organization Directory';
$PAGE_ACTIVE = '';
$PAGE_SUB    = 'Browse the ' . (int) $total . ' recognized student organizations of ' . SCHOOL_NAME;
$PAGE_ACTIONS = '<a class="btn ghost" href="' . Helpers::e(Helpers::url('index.php')) . '">'
    . icon('chevron-left', 16) . '<span>Back to home</span></a>';

require __DIR__ . '/includes/layout/header.php';
?>

<div class="public-page">
  <div class="public-nav">
    <a class="brand" href="<?= Helpers::e(Helpers::url('index.php')) ?>">
      <img class="brand-logo" src="<?= Helpers::e(Helpers::url('assets/img/logo.png')) ?>" alt="ZDSPGC seal" width="38" height="38">
      <span class="brand-text"><span class="name">ZDSPGC OrgSys</span><span class="sub">Organization directory</span></span>
    </a>
    <div class="btn-row">
      <a class="btn ghost sm" href="<?= Helpers::e(Helpers::url('index.php')) ?>">Home</a>
      <a class="btn sm" href="<?= Helpers::e(Helpers::url(Auth::check() ? Permissions::homeForRole() : 'login.php')) ?>">
        <?= icon(Auth::check() ? 'dashboard' : 'logout', 15) ?>
        <span><?= Auth::check() ? 'My dashboard' : 'Sign in' ?></span>
      </a>
    </div>
  </div>

  <div class="public-body">
    <?= ui_filter_form('organizations.php',
        ui_filter_input('q', 'Search', $filters['q'], 'text', 'data-filter="#directory"')
        . ui_filter_select('category_id', 'Category', AcademicRepo::categoryOptions(), $filters['category_id'])
        . ui_filter_select('department_id', 'Department', AcademicRepo::departmentOptions(), $filters['department_id'])
        . ui_filter_select('type', 'Type', $types, $filters['organization_type'])) ?>

    <div id="directory">
      <?php if ($rows === []): ?>
        <?= ui_empty('No organization matches this search.', 'Try a different keyword or clear the filters.', 'org') ?>
      <?php else: ?>
        <div class="org-grid">
          <?php foreach ($rows as $org): ?>
            <a class="org-card" href="<?= Helpers::e(Helpers::url('organization.php?id=' . (int) $org['id'])) ?>">
              <div class="org-cell">
                <?= ui_org_badge($org, 44) ?>
                <div>
                  <h3><?= Helpers::e((string) $org['name']) ?></h3>
                  <?= ui_org_acronym($org) ?>
                </div>
              </div>
              <?= ui_org_desc($org, 150) ?>
              <div class="meta">
                <?= ui_status_badge((string) $org['accreditation_status']) ?>
                <span><?= icon('users', 13) ?> <?= (int) $org['member_count'] ?> members</span>
                <?php if ((string) $org['category_name'] !== ''): ?>
                  <span><?= Helpers::e((string) $org['category_name']) ?></span>
                <?php endif; ?>
                <?php if ((string) $org['adviser_name'] !== ''): ?>
                  <span><?= icon('user-check', 13) ?> Adviser: <?= Helpers::e((string) $org['adviser_name']) ?></span>
                <?php endif; ?>
                <?php if ((string) $org['department_name'] !== ''): ?>
                  <span><?= icon('org', 13) ?> <?= Helpers::e((string) $org['department_name']) ?></span>
                <?php endif; ?>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
        <div data-filter-empty hidden>
          <?= ui_empty('Nothing matches as you type.', 'Clear the search box to see the full list.', 'org') ?>
        </div>
        <?= ui_pagination($page, $pages, $query) ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/layout/footer.php'; ?>
