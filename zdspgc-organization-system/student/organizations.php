<?php
/**
 * student/organizations.php — organization directory for the signed-in student.
 *
 * Same filters as the public directory, but every card also shows the student's
 * own membership state and offers the application form while registration is open.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$student   = Permissions::requireStudentProfile();
$studentId = (int) $student['id'];

/* ---- POST actions (run BEFORE any output) ---- */
if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) (Helpers::post('action') ?? '');
    try {
        if ($action !== 'apply') {
            throw new RuntimeException('Unknown action.');
        }
        if (AcademicRepo::setting('organization_registration_open') !== '1') {
            throw new RuntimeException('Organization registration is closed right now.');
        }
        $organizationId = Helpers::postInt('organization_id');
        if ($organizationId < 1) {
            throw new RuntimeException('Choose the organization you want to apply to.');
        }
        $result = MemberRepo::apply($organizationId, $studentId, (string) (Helpers::post('message') ?? ''));
        if (!$result['ok']) {
            throw new RuntimeException($result['message']);
        }
        Helpers::flash('success', $result['message']);
    } catch (Throwable $e) {
        Helpers::flash('error', $e->getMessage());
    }
    Helpers::redirect('student/organizations.php');
}

/* ---- read ---- */
$filters = [
    'q'                 => (string) (Helpers::get('q') ?? ''),
    'category_id'       => (string) (Helpers::get('category_id') ?? ''),
    'department_id'     => (string) (Helpers::get('department_id') ?? ''),
    'organization_type' => (string) (Helpers::get('type') ?? ''),
];

$memberships = [];
foreach (MemberRepo::forStudent($studentId) as $membership) {
    $key = (int) $membership['organization_id'];
    if (!isset($memberships[$key])) {
        $memberships[$key] = $membership;
    }
}

$registrationOpen = AcademicRepo::setting('organization_registration_open') === '1';

$all     = OrgRepo::publicList($filters, 200);
$total   = count($all);
$perPage = 12;
$pages   = max(1, (int) ceil($total / $perPage));
$page    = min(Helpers::page(), $pages);
$rows    = array_slice($all, ($page - 1) * $perPage, $perPage);

$query = http_build_query(array_filter([
    'q'             => $filters['q'],
    'category_id'   => $filters['category_id'],
    'department_id' => $filters['department_id'],
    'type'          => $filters['organization_type'],
]));

$types = array_combine(AcademicRepo::ORGANIZATION_TYPES, AcademicRepo::ORGANIZATION_TYPES);

$PAGE_TITLE  = 'Organization Directory';
$PAGE_ACTIVE = 'organizations';
$PAGE_SUB    = 'Browse the ' . (int) $total . ' recognized student organizations of ' . SCHOOL_NAME
    . ' and apply for the ones you want to join.';
$PAGE_ACTIONS = '<a class="btn ghost" href="' . Helpers::e(Helpers::url('student/my-organizations.php')) . '">'
    . icon('layers', 16) . '<span>My organizations</span></a>';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'student/dashboard.php'], ['label' => 'Organizations']];

require __DIR__ . '/../includes/layout/header.php';
?>

<?= ui_filter_form('student/organizations.php',
    ui_filter_input('q', 'Search', $filters['q'])
    . ui_filter_select('category_id', 'Category', AcademicRepo::categoryOptions(), $filters['category_id'])
    . ui_filter_select('department_id', 'Department', AcademicRepo::departmentOptions(), $filters['department_id'])
    . ui_filter_select('type', 'Type', $types, $filters['organization_type'])) ?>

<?php if (!$registrationOpen): ?>
  <div class="flash flash-warning">
    <?= icon('info', 18) ?><span>Organization registration is closed. You can still browse the directory and open each profile.</span>
  </div>
<?php endif; ?>

<?php if ($rows === []): ?>
  <?= ui_empty('No organization matches this search.', 'Try a different keyword or clear the filters.', 'org') ?>
<?php else: ?>
  <div class="org-grid">
    <?php foreach ($rows as $org): ?>
      <?php $mine = $memberships[(int) $org['id']] ?? null; ?>
      <div class="org-card">
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
          <?php if ((string) $org['department_name'] !== ''): ?>
            <span><?= Helpers::e((string) $org['department_name']) ?></span>
          <?php endif; ?>
        </div>
        <div class="meta">
          <?php if ($mine !== null): ?>
            <?= ui_status_badge((string) $mine['status']) ?>
            <span><?= icon('user-check', 13) ?> Your record: <?= Helpers::e(ui_status((string) $mine['status'])) ?></span>
            <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('student/my-organizations.php')) ?>">Details</a>
          <?php elseif ($registrationOpen): ?>
            <form method="post" class="inline-form">
              <?= Security::csrfField() ?>
              <input type="hidden" name="action" value="apply">
              <input type="hidden" name="organization_id" value="<?= (int) $org['id'] ?>">
              <input type="text" name="message" maxlength="400" placeholder="Message (optional)" aria-label="Message to the officers">
              <button class="btn sm" type="submit"><?= icon('plus', 15) ?>Apply</button>
            </form>
          <?php else: ?>
            <?= ui_badge('Registration closed', 'grey') ?>
          <?php endif; ?>
          <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('organization.php?id=' . (int) $org['id'])) ?>">Profile</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?= ui_pagination($page, $pages, $query) ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
