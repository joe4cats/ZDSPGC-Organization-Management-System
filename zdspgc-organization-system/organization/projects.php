<?php
/**
 * organization/projects.php — project & activity management for organization officers.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireRole('officer');
Permissions::requireCapability('manage_projects');

$org = Permissions::activeOrganization();
if ($org === null) {
    Helpers::flash('error', 'You must be assigned to an active organization.');
    Helpers::redirect('organization/dashboard.php');
}
$orgId = (int) $org['id'];

if (Helpers::isPost()) {
    Security::requireCsrf();
    $action    = (string) (Helpers::post('action') ?? '');
    $projectId = Helpers::postInt('project_id');

    try {
        if ($action === 'create' || $action === 'update') {
            $title = trim((string) (Helpers::post('title') ?? ''));
            if ($title === '') {
                throw new RuntimeException('Project title is required.');
            }

            $budget = (string) (Helpers::post('budget') ?? '0');
            if (!is_numeric($budget) || (float) $budget < 0) {
                throw new RuntimeException('Budget must be a non-negative number.');
            }

            $startDate = (string) (Helpers::post('start_date') ?? '');
            $endDate   = (string) (Helpers::post('end_date') ?? '');
            if ($startDate !== '' && $endDate !== '' && $endDate < $startDate) {
                throw new RuntimeException('End date cannot be earlier than start date.');
            }

            $status = (string) (Helpers::post('status') ?? 'proposed');
            if (!in_array($status, AcademicRepo::PROJECT_STATUSES, true)) {
                $status = 'proposed';
            }

            $payload = [
                'organization_id'     => $orgId,
                'title'               => $title,
                'description'         => trim((string) (Helpers::post('description') ?? '')),
                'objectives'          => trim((string) (Helpers::post('objectives') ?? '')),
                'target_participants' => trim((string) (Helpers::post('target_participants') ?? '')),
                'budget'              => $budget,
                'funding_source'      => trim((string) (Helpers::post('funding_source') ?? '')),
                'start_date'          => $startDate !== '' ? $startDate : null,
                'end_date'            => $endDate !== '' ? $endDate : null,
                'status'              => $status,
                'academic_year_id'    => AcademicRepo::activeYearId(),
            ];

            if ($action === 'create') {
                ProjectRepo::create($payload);
                Helpers::flash('success', 'Project created successfully.');
            } else {
                $existing = ProjectRepo::find($projectId);
                if ($existing === null || (int) $existing['organization_id'] !== $orgId) {
                    throw new RuntimeException('Project not found or unauthorized.');
                }
                ProjectRepo::update($projectId, $payload);
                Helpers::flash('success', 'Project updated successfully.');
            }
        } elseif ($action === 'status' && $projectId > 0) {
            $existing = ProjectRepo::find($projectId);
            if ($existing === null || (int) $existing['organization_id'] !== $orgId) {
                throw new RuntimeException('Project not found or unauthorized.');
            }
            $newStatus = (string) (Helpers::post('status') ?? 'proposed');
            if (in_array($newStatus, AcademicRepo::PROJECT_STATUSES, true)) {
                ProjectRepo::setStatus($projectId, $newStatus);
                Helpers::flash('success', 'Project status changed to ' . ui_status($newStatus) . '.');
            }
        }
    } catch (Throwable $e) {
        Helpers::flash('error', $e->getMessage());
    }

    Helpers::redirect('organization/projects.php');
}

$filters = [
    'q'      => (string) (Helpers::get('q') ?? ''),
    'status' => (string) (Helpers::get('status') ?? ''),
];

$allProjects = ProjectRepo::forOrganization($orgId);

// Filter in PHP for responsiveness
$projects = array_filter($allProjects, static function (array $p) use ($filters): bool {
    if ($filters['status'] !== '' && (string) $p['status'] !== $filters['status']) {
        return false;
    }
    if ($filters['q'] !== '') {
        $term = mb_strtolower($filters['q']);
        return str_contains(mb_strtolower((string) $p['title']), $term)
            || str_contains(mb_strtolower((string) $p['description']), $term);
    }
    return true;
});

$counts = ['proposed' => 0, 'ongoing' => 0, 'completed' => 0];
foreach ($allProjects as $p) {
    $st = (string) $p['status'];
    if (isset($counts[$st])) {
        $counts[$st]++;
    }
}

$PAGE_TITLE       = 'Organization Projects';
$PAGE_ACTIVE      = 'projects';
$PAGE_SUB         = Helpers::e((string) $org['name']) . ' · Plan and track organization initiatives and activities';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'organization/dashboard.php'], ['label' => 'Projects']];

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="stat-grid">
  <?= ui_stat('Total Initiatives', count($allProjects), 'layers') ?>
  <?= ui_stat('Proposed', $counts['proposed'], 'clock', $counts['proposed'] > 0 ? 'amber' : 'grey') ?>
  <?= ui_stat('Ongoing', $counts['ongoing'], 'refresh', $counts['ongoing'] > 0 ? 'blue' : 'grey') ?>
  <?= ui_stat('Completed', $counts['completed'], 'check-circle', 'green') ?>
</div>

<section class="card">
  <div class="card-head">
    <h3>Projects & Activities</h3>
    <button class="btn sm" type="button" onclick="document.getElementById('modal-new-project').showModal()">
      <?= icon('plus', 15) ?><span>New Project</span>
    </button>
  </div>

  <form method="get" action="<?= Helpers::e(Helpers::url('organization/projects.php')) ?>" class="filter-bar">
    <div class="filter-field">
      <input type="search" name="q" value="<?= Helpers::e($filters['q']) ?>" placeholder="Search projects...">
    </div>
    <div class="filter-field">
      <select name="status">
        <option value="">All statuses</option>
        <option value="proposed" <?= $filters['status'] === 'proposed' ? 'selected' : '' ?>>Proposed</option>
        <option value="approved" <?= $filters['status'] === 'approved' ? 'selected' : '' ?>>Approved</option>
        <option value="ongoing" <?= $filters['status'] === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
        <option value="completed" <?= $filters['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
        <option value="cancelled" <?= $filters['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
      </select>
    </div>
    <div class="filter-actions">
      <button class="btn sm" type="submit"><?= icon('search', 15) ?><span>Filter</span></button>
      <?php if (array_filter($filters)): ?>
        <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('organization/projects.php')) ?>"><?= icon('refresh', 15) ?><span>Reset</span></a>
      <?php endif; ?>
    </div>
  </form>

  <?php if ($projects === []): ?>
    <?= ui_empty('No projects found.', 'Create a new project proposal to begin planning activities.', 'layers') ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Project Title</th>
            <th>Timeline</th>
            <th>Budget</th>
            <th>Funding Source</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($projects as $project): ?>
            <tr>
              <td>
                <strong><?= Helpers::e((string) $project['title']) ?></strong>
                <?php if (!empty($project['target_participants'])): ?>
                  <span class="muted small d-block">Target: <?= Helpers::e((string) $project['target_participants']) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!empty($project['start_date'])): ?>
                  <?= Helpers::e(Helpers::fmtDate((string) $project['start_date'])) ?>
                  <?php if (!empty($project['end_date'])): ?>
                    <span class="muted small">to <?= Helpers::e(Helpers::fmtDate((string) $project['end_date'])) ?></span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="muted">TBD</span>
                <?php endif; ?>
              </td>
              <td>₱<?= number_format((float) ($project['budget'] ?? 0), 2) ?></td>
              <td><?= Helpers::e((string) ($project['funding_source'] ?: 'Organization Fund')) ?></td>
              <td><?= ui_status_badge((string) $project['status']) ?></td>
              <td>
                <form method="post" action="<?= Helpers::e(Helpers::url('organization/projects.php')) ?>" class="inline">
                  <?= Security::csrfField() ?>
                  <input type="hidden" name="action" value="status">
                  <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                  <select name="status" onchange="this.form.submit()" style="padding: 2px 6px; font-size: 11px;">
                    <?php foreach (AcademicRepo::PROJECT_STATUSES as $st): ?>
                      <option value="<?= $st ?>" <?= $project['status'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<!-- Modal: New Project -->
<dialog id="modal-new-project" class="modal-box">
  <div class="card p-4">
    <div class="card-head">
      <h3>Create New Project / Activity</h3>
      <button type="button" class="btn xs ghost" onclick="document.getElementById('modal-new-project').close()">✕</button>
    </div>
    <form method="post" action="<?= Helpers::e(Helpers::url('organization/projects.php')) ?>" class="stack mt">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="create">

      <?= ui_input('title', 'Project Title', '', ['placeholder' => 'e.g. Annual IT Skills Exhibition'], true) ?>

      <div class="field">
        <label class="field-label" for="proj-desc">Description</label>
        <textarea id="proj-desc" name="description" rows="3" placeholder="Brief background of the project..."></textarea>
      </div>

      <div class="field">
        <label class="field-label" for="proj-obj">Objectives</label>
        <textarea id="proj-obj" name="objectives" rows="3" placeholder="Key goals and outcomes..."></textarea>
      </div>

      <div class="grid cols-2">
        <?= ui_input('target_participants', 'Target Participants', '', ['placeholder' => 'e.g. All BSIT Students (approx. 150)']) ?>
        <?= ui_input('budget', 'Estimated Budget (₱)', '0', ['type' => 'number', 'step' => '0.01']) ?>
      </div>

      <div class="grid cols-2">
        <?= ui_input('funding_source', 'Funding Source', '', ['placeholder' => 'e.g. Org dues, sponsorships']) ?>
        <div class="field">
          <label class="field-label" for="proj-status">Initial Status</label>
          <select id="proj-status" name="status">
            <option value="proposed">Proposed</option>
            <option value="approved">Approved</option>
            <option value="ongoing">Ongoing</option>
          </select>
        </div>
      </div>

      <div class="grid cols-2">
        <?= ui_input('start_date', 'Target Start Date', '', ['type' => 'date']) ?>
        <?= ui_input('end_date', 'Target End Date', '', ['type' => 'date']) ?>
      </div>

      <div class="btn-row mt">
        <button class="btn" type="submit"><?= icon('check-circle', 16) ?><span>Save Project</span></button>
        <button class="btn ghost" type="button" onclick="document.getElementById('modal-new-project').close()">Cancel</button>
      </div>
    </form>
  </div>
</dialog>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
