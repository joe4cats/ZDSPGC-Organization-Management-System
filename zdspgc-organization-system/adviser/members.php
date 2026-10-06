<?php
/**
 * adviser/members.php — view and search members of assigned organizations.
 * Read-only monitoring; no editing of student records.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireRole('adviser');
Permissions::requireCapability('review_members');

$adviserId = Permissions::adviserId();
$orgIds    = Permissions::managedOrganizationIds();
$inList    = $orgIds === [] ? 'NULL' : implode(',', array_map('intval', $orgIds));

$q      = (string) (Helpers::get('q')      ?? '');
$orgId  = (int)    (Helpers::get('org_id') ?? 0);
$status = (string) (Helpers::get('status') ?? '');
$yearId = (int)    (Helpers::get('year_id') ?? 0);
$page   = max(1, (int) (Helpers::get('page') ?? 1));
$perPage = 25;

if ($orgId > 0 && !in_array($orgId, $orgIds, true)) {
    $orgId = 0;
}
$scopedList = $orgId > 0 ? (string)$orgId : $inList;

$sql    = "SELECT om.id, om.status AS membership_status, om.joined_at,
                  s.student_id, s.first_name, s.last_name, s.course, s.year_level, s.section,
                  o.name AS organization_name, o.acronym AS organization_acronym,
                  op.name AS position_name,
                  ay.name AS academic_year_name
           FROM organization_members om
           JOIN students s ON s.id = om.student_id
           JOIN organizations o ON o.id = om.organization_id
           LEFT JOIN officer_positions op ON op.id = om.position_id
           LEFT JOIN academic_years ay ON ay.id = om.academic_year_id
           WHERE om.organization_id IN ($scopedList)";
$params = [];

if ($status !== '') {
    $sql .= ' AND om.status = :s';
    $params['s'] = $status;
}
if ($yearId > 0) {
    $sql .= ' AND om.academic_year_id = :y';
    $params['y'] = $yearId;
}
if ($q !== '') {
    $sql .= ' AND (s.first_name LIKE :q OR s.last_name LIKE :q2 OR s.student_id LIKE :q3 OR s.course LIKE :q4)';
    $params['q'] = '%' . $q . '%';
    $params['q2'] = '%' . $q . '%';
    $params['q3'] = '%' . $q . '%';
    $params['q4'] = '%' . $q . '%';
}

$total    = (int) Database::scalar('SELECT COUNT(*) FROM (' . $sql . ') AS cnt', $params);
$pages    = (int) max(1, ceil($total / $perPage));
$members  = Database::all($sql . ' ORDER BY o.name ASC, s.last_name ASC, s.first_name ASC'
    . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);

$orgOptions  = [];
foreach (OrgRepo::list(['adviser_id' => $adviserId], 1, 100)['rows'] as $org) {
    $orgOptions[(int) $org['id']] = (string) $org['name'];
}
$yearOptions   = AcademicRepo::yearOptions();
$statusOptions = ['active' => 'Active', 'pending' => 'Pending', 'inactive' => 'Inactive'];

$queryStr = http_build_query(array_filter(['q' => $q, 'org_id' => $orgId ?: '', 'status' => $status, 'year_id' => $yearId ?: '']));

$PAGE_TITLE       = 'Member Monitoring';
$PAGE_ACTIVE      = 'members';
$PAGE_SUB         = $total . ' membership record(s) across your assigned organizations.';
$PAGE_BREADCRUMBS = [
    ['label' => 'Dashboard', 'href' => 'adviser/dashboard.php'],
    ['label' => 'Members'],
];
require __DIR__ . '/../includes/layout/header.php';
?>

<?php if ($orgIds === []): ?>
  <section class="card"><?= ui_empty('No organizations assigned.', '', 'users') ?></section>
<?php else: ?>

<?= ui_filter_form('adviser/members.php',
    ui_filter_input('q', 'Search', $q) .
    (count($orgOptions) > 1 ? ui_filter_select('org_id', 'Organization', $orgOptions, (string)$orgId, 'All organizations') : '') .
    ui_filter_select('status', 'Status', $statusOptions, $status, 'All statuses') .
    ui_filter_select('year_id', 'Academic year', $yearOptions, (string)$yearId, 'All years')
) ?>

<section class="card">
  <div class="card-head">
    <div>
      <h3>Members</h3>
      <p class="sub muted small"><?= $total ?> record(s) · page <?= $page ?> of <?= $pages ?></p>
    </div>
  </div>

  <?php if ($members === []): ?>
    <?= ui_empty('No members found.', 'Try adjusting the search or filters.', 'users') ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Student</th>
            <th>Course / Section</th>
            <th>Organization</th>
            <th>Position</th>
            <th>Academic Year</th>
            <th>Status</th>
            <th>Joined</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($members as $m): ?>
            <tr>
              <td>
                <strong><?= Helpers::e((string)$m['last_name']) ?>, <?= Helpers::e((string)$m['first_name']) ?></strong>
                <br><span class="muted small"><?= Helpers::e((string)$m['student_id']) ?></span>
              </td>
              <td class="small">
                <?= Helpers::e((string)$m['course']) ?>
                <?php if ((string)$m['year_level'] !== ''): ?>
                  · <?= Helpers::e((string)$m['year_level']) ?>
                <?php endif; ?>
                <?php if ((string)$m['section'] !== ''): ?>
                  · <?= Helpers::e((string)$m['section']) ?>
                <?php endif; ?>
              </td>
              <td class="small"><?= Helpers::e((string)$m['organization_acronym']) ?></td>
              <td class="small"><?= Helpers::e((string)($m['position_name'] ?? '—')) ?></td>
              <td class="small"><?= Helpers::e((string)($m['academic_year_name'] ?? '—')) ?></td>
              <td><?= ui_status_badge((string)$m['membership_status']) ?></td>
              <td class="small nowrap"><?= Helpers::e(Helpers::fmtDate((string)$m['joined_at'])) ?></td>
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
