<?php
/**
 * adviser/officers.php — officer roster for all assigned organizations.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireRole('adviser');
Permissions::requireCapability('review_officers');

$adviserId = Permissions::adviserId();
$orgIds    = Permissions::managedOrganizationIds();
$inList    = $orgIds === [] ? 'NULL' : implode(',', array_map('intval', $orgIds));

$q      = (string) (Helpers::get('q')       ?? '');
$orgId  = (int)    (Helpers::get('org_id')  ?? 0);
$yearId = (int)    (Helpers::get('year_id') ?? 0);
$status = (string) (Helpers::get('status')  ?? '');
$page   = max(1, (int) (Helpers::get('page') ?? 1));
$perPage = 25;

if ($orgId > 0 && !in_array($orgId, $orgIds, true)) {
    $orgId = 0;
}
$scopedList = $orgId > 0 ? (string)$orgId : $inList;

$sql    = "SELECT oo.id, oo.status AS officer_status, oo.start_date, oo.end_date,
                  s.student_id, s.first_name, s.last_name, s.course,
                  op.name AS position_name, op.sort_order AS position_order,
                  o.name AS organization_name, o.acronym AS organization_acronym,
                  ay.name AS academic_year_name
           FROM organization_officers oo
           JOIN students s ON s.id = oo.student_id
           JOIN officer_positions op ON op.id = oo.position_id
           JOIN organizations o ON o.id = oo.organization_id
           LEFT JOIN academic_years ay ON ay.id = oo.academic_year_id
           WHERE oo.organization_id IN ($scopedList)";
$params = [];
if ($status !== '') {
    $sql .= ' AND oo.status = :s';
    $params['s'] = $status;
}
if ($yearId > 0) {
    $sql .= ' AND oo.academic_year_id = :y';
    $params['y'] = $yearId;
}
if ($q !== '') {
    $sql .= ' AND (s.first_name LIKE :q OR s.last_name LIKE :q2 OR s.student_id LIKE :q3)';
    $params['q'] = '%' . $q . '%';
    $params['q2'] = '%' . $q . '%';
    $params['q3'] = '%' . $q . '%';
}

$total   = (int) Database::scalar('SELECT COUNT(*) FROM (' . $sql . ') AS cnt', $params);
$pages   = (int) max(1, ceil($total / $perPage));
$officers = Database::all($sql . ' ORDER BY o.name ASC, op.sort_order ASC, s.last_name ASC'
    . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);

$orgOptions  = [];
foreach (OrgRepo::list(['adviser_id' => $adviserId], 1, 100)['rows'] as $org) {
    $orgOptions[(int) $org['id']] = (string) $org['name'];
}
$yearOptions   = AcademicRepo::yearOptions();
$statusOptions = ['active' => 'Active', 'inactive' => 'Inactive'];
$queryStr = http_build_query(array_filter(['q' => $q, 'org_id' => $orgId ?: '', 'year_id' => $yearId ?: '', 'status' => $status]));

$PAGE_TITLE       = 'Officer Monitoring';
$PAGE_ACTIVE      = 'officers';
$PAGE_SUB         = $total . ' officer record(s) in your assigned organizations.';
$PAGE_BREADCRUMBS = [
    ['label' => 'Dashboard', 'href' => 'adviser/dashboard.php'],
    ['label' => 'Officers'],
];
require __DIR__ . '/../includes/layout/header.php';
?>

<?php if ($orgIds === []): ?>
  <section class="card"><?= ui_empty('No organizations assigned yet.', '', 'award') ?></section>
<?php else: ?>

<?= ui_filter_form('adviser/officers.php',
    ui_filter_input('q', 'Search', $q) .
    (count($orgOptions) > 1 ? ui_filter_select('org_id', 'Organization', $orgOptions, (string)$orgId, 'All organizations') : '') .
    ui_filter_select('year_id', 'Academic year', $yearOptions, (string)$yearId, 'All years') .
    ui_filter_select('status', 'Status', $statusOptions, $status, 'All statuses')
) ?>

<section class="card">
  <div class="card-head">
    <div>
      <h3>Officers</h3>
      <p class="sub muted small"><?= $total ?> record(s) · page <?= $page ?> of <?= $pages ?></p>
    </div>
  </div>

  <?php if ($officers === []): ?>
    <?= ui_empty('No officer records found.', 'Try adjusting the filters.', 'award') ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Officer</th>
            <th>Position</th>
            <th>Organization</th>
            <th>Academic Year</th>
            <th>Term</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($officers as $o): ?>
            <tr>
              <td>
                <strong><?= Helpers::e((string)$o['last_name']) ?>, <?= Helpers::e((string)$o['first_name']) ?></strong>
                <br><span class="muted small"><?= Helpers::e((string)$o['student_id']) ?> · <?= Helpers::e((string)$o['course']) ?></span>
              </td>
              <td><strong><?= Helpers::e((string)$o['position_name']) ?></strong></td>
              <td class="small"><?= Helpers::e((string)$o['organization_acronym']) ?></td>
              <td class="small"><?= Helpers::e((string)($o['academic_year_name'] ?? '—')) ?></td>
              <td class="small nowrap">
                <?= Helpers::e(Helpers::fmtDate((string)$o['start_date'])) ?>
                <?= $o['end_date'] ? ' — ' . Helpers::e(Helpers::fmtDate((string)$o['end_date'])) : '' ?>
              </td>
              <td><?= ui_status_badge((string)$o['officer_status']) ?></td>
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
