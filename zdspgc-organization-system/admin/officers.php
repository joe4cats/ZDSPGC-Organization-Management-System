<?php
/**
 * admin/officers.php — manage organization officers across every organization.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Permissions::requireCapability('manage_officers');

if (Helpers::isPost()) {
    Security::requireCsrf();
    $action    = (string) (Helpers::post('action') ?? '');
    $officerId = Helpers::postInt('officer_id');

    try {
        if ($action === 'assign') {
            $orgId      = Helpers::postInt('organization_id');
            $studentId  = Helpers::postInt('student_id');
            $positionId = Helpers::postInt('position_id');
            $term       = trim((string) (Helpers::post('term') ?? ''));

            if ($orgId <= 0 || $studentId <= 0 || $positionId <= 0) {
                throw new RuntimeException('Organization, student, and position are required.');
            }

            OfficerRepo::assign($orgId, $studentId, $positionId, [
                'term'       => $term !== '' ? $term : 'AY ' . (AcademicRepo::activeYear()['name'] ?? date('Y')),
                'start_date' => date('Y-m-d'),
            ]);

            Helpers::flash('success', 'Officer appointed successfully.');
        } elseif ($action === 'remove' && $officerId > 0) {
            $reason = trim((string) (Helpers::post('reason') ?? ''));
            OfficerRepo::remove($officerId, $reason !== '' ? $reason : 'Removed by administrator');
            Helpers::flash('success', 'Officer removed from office.');
        } elseif ($action === 'status' && $officerId > 0) {
            $status = (string) (Helpers::post('status') ?? 'active');
            if (in_array($status, ['active', 'resigned', 'removed', 'archived'], true)) {
                Database::update('organization_officers', ['status' => $status], 'id = :id', ['id' => $officerId]);
                Helpers::flash('success', 'Officer status changed to ' . ui_status($status) . '.');
            }
        }
    } catch (Throwable $e) {
        Helpers::flash('error', $e->getMessage());
    }

    Helpers::redirect('admin/officers.php');
}

$filters = [
    'q'                => (string) (Helpers::get('q') ?? ''),
    'organization_id'  => Helpers::getInt('organization_id'),
    'academic_year_id' => Helpers::getInt('academic_year_id') ?: AcademicRepo::activeYearId(),
    'position_id'      => Helpers::getInt('position_id'),
    'status'           => (string) (Helpers::get('status') ?? 'active'),
];

$allOfficers = OfficerRepo::list(array_filter($filters), 500);

$totalCount  = (int) Database::count('organization_officers');
$activeCount = (int) Database::count('organization_officers', "status = 'active'");
$orgsWithOff = (int) Database::scalar('SELECT COUNT(DISTINCT organization_id) FROM organization_officers WHERE status = "active"');
$posCount    = (int) Database::count('officer_positions', "status = 'active'");

$orgs      = Database::all('SELECT id, name, acronym FROM organizations ORDER BY name');
$years     = Database::all('SELECT id, name FROM academic_years ORDER BY start_date DESC');
$positions = Database::all('SELECT id, name FROM officer_positions WHERE is_officer = 1 ORDER BY sort_order');

$PAGE_TITLE       = 'Officer Management';
$PAGE_ACTIVE      = 'officers';
$PAGE_SUB         = 'Appoint, monitor and maintain officer rosters across all campus organizations';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'admin/dashboard.php'], ['label' => 'Officers']];

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="stat-grid">
  <?= ui_stat('Active Officers', $activeCount, 'award', 'green') ?>
  <?= ui_stat('Total Historical Records', $totalCount, 'users') ?>
  <?= ui_stat('Organizations with Officers', $orgsWithOff, 'org') ?>
  <?= ui_stat('Recognized Positions', $posCount, 'layers') ?>
</div>

<section class="card">
  <div class="card-head">
    <h3>Officers Roster</h3>
    <button class="btn sm" type="button" onclick="document.getElementById('modal-assign-officer').showModal()">
      <?= icon('plus', 15) ?><span>Appoint Officer</span>
    </button>
  </div>

  <form method="get" action="<?= Helpers::e(Helpers::url('admin/officers.php')) ?>" class="filter-bar">
    <div class="filter-field">
      <input type="search" name="q" value="<?= Helpers::e($filters['q']) ?>" placeholder="Search student or officer...">
    </div>
    <div class="filter-field">
      <select name="organization_id">
        <option value="">All organizations</option>
        <?php foreach ($orgs as $o): ?>
          <option value="<?= $o['id'] ?>" <?= $filters['organization_id'] === (int) $o['id'] ? 'selected' : '' ?>>
            <?= Helpers::e((string) ($o['acronym'] ?: $o['name'])) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-field">
      <select name="academic_year_id">
        <option value="">All academic years</option>
        <?php foreach ($years as $y): ?>
          <option value="<?= $y['id'] ?>" <?= $filters['academic_year_id'] === (int) $y['id'] ? 'selected' : '' ?>>
            <?= Helpers::e((string) $y['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-field">
      <select name="position_id">
        <option value="">All positions</option>
        <?php foreach ($positions as $p): ?>
          <option value="<?= $p['id'] ?>" <?= $filters['position_id'] === (int) $p['id'] ? 'selected' : '' ?>>
            <?= Helpers::e((string) $p['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-field">
      <select name="status">
        <option value="">All statuses</option>
        <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="resigned" <?= $filters['status'] === 'resigned' ? 'selected' : '' ?>>Resigned</option>
        <option value="removed" <?= $filters['status'] === 'removed' ? 'selected' : '' ?>>Removed</option>
        <option value="archived" <?= $filters['status'] === 'archived' ? 'selected' : '' ?>>Archived</option>
      </select>
    </div>
    <div class="filter-actions">
      <button class="btn sm" type="submit"><?= icon('search', 15) ?><span>Filter</span></button>
      <?php if (array_filter($filters)): ?>
        <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('admin/officers.php')) ?>"><?= icon('refresh', 15) ?><span>Reset</span></a>
      <?php endif; ?>
    </div>
  </form>

  <?php if ($allOfficers === []): ?>
    <?= ui_empty('No officers found matching the criteria.', 'Appoint officers or change the filter options.', 'award') ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Student Officer</th>
            <th>Position</th>
            <th>Organization</th>
            <th>Academic Year / Term</th>
            <th>Appointed By</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($allOfficers as $officer): ?>
            <tr>
              <td>
                <strong><?= Helpers::e((string) $officer['last_name'] . ', ' . (string) $officer['first_name'] . ' ' . (string) ($officer['middle_name'] ?? '')) ?></strong>
                <span class="muted small d-block"><?= Helpers::e((string) $officer['student_id']) ?> · <?= Helpers::e((string) ($officer['course'] ?? '')) ?></span>
              </td>
              <td><?= ui_badge((string) $officer['position_name'], 'blue') ?></td>
              <td><?= Helpers::e((string) ($officer['organization_acronym'] ?: $officer['organization_name'])) ?></td>
              <td>
                <div><?= Helpers::e((string) $officer['academic_year_name']) ?></div>
                <span class="muted small"><?= Helpers::e((string) ($officer['term'] ?? '')) ?></span>
              </td>
              <td><?= Helpers::e((string) ($officer['appointed_by_name'] ?? 'System')) ?></td>
              <td><?= ui_status_badge((string) $officer['status']) ?></td>
              <td>
                <?php if ($officer['status'] === 'active'): ?>
                  <form method="post" action="<?= Helpers::e(Helpers::url('admin/officers.php')) ?>" class="inline" onsubmit="return confirm('Remove this officer from office?')">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="officer_id" value="<?= (int) $officer['id'] ?>">
                    <button class="btn xs ghost text-danger" type="submit" title="Relieve of duties">Remove</button>
                  </form>
                <?php else: ?>
                  <form method="post" action="<?= Helpers::e(Helpers::url('admin/officers.php')) ?>" class="inline">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="action" value="status">
                    <input type="hidden" name="officer_id" value="<?= (int) $officer['id'] ?>">
                    <input type="hidden" name="status" value="active">
                    <button class="btn xs ghost" type="submit">Reactivate</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<!-- Modal: Appoint Officer -->
<dialog id="modal-assign-officer" class="modal-box">
  <div class="card p-4">
    <div class="card-head">
      <h3>Appoint Organization Officer</h3>
      <button type="button" class="btn xs ghost" onclick="document.getElementById('modal-assign-officer').close()">✕</button>
    </div>
    <form method="post" action="<?= Helpers::e(Helpers::url('admin/officers.php')) ?>" class="stack mt">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="assign">

      <div class="field">
        <label class="field-label" for="app-org">Organization</label>
        <select id="app-org" name="organization_id" required>
          <option value="">Select organization</option>
          <?php foreach ($orgs as $o): ?>
            <option value="<?= $o['id'] ?>"><?= Helpers::e((string) $o['name']) ?> (<?= Helpers::e((string) $o['acronym']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label class="field-label" for="app-student">Student (Member)</label>
        <select id="app-student" name="student_id" required>
          <option value="">Select student</option>
          <?php
          $allStudents = Database::all('SELECT id, student_id, first_name, last_name FROM students WHERE status = "active" ORDER BY last_name');
          foreach ($allStudents as $st): ?>
            <option value="<?= $st['id'] ?>"><?= Helpers::e($st['last_name'] . ', ' . $st['first_name'] . ' (' . $st['student_id'] . ')') ?></option>
          <?php endforeach; ?>
        </select>
        <span class="field-hint">Student must be registered in the system.</span>
      </div>

      <div class="field">
        <label class="field-label" for="app-pos">Position</label>
        <select id="app-pos" name="position_id" required>
          <option value="">Select position</option>
          <?php foreach ($positions as $p): ?>
            <option value="<?= $p['id'] ?>"><?= Helpers::e((string) $p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label class="field-label" for="app-term">Term description</label>
        <input id="app-term" type="text" name="term" placeholder="e.g. AY 2026-2027">
      </div>

      <div class="btn-row mt">
        <button class="btn" type="submit"><?= icon('check-circle', 16) ?><span>Appoint Officer</span></button>
        <button class="btn ghost" type="button" onclick="document.getElementById('modal-assign-officer').close()">Cancel</button>
      </div>
    </form>
  </div>
</dialog>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
