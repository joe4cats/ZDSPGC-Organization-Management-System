<?php
/**
 * admin/projects.php — register of organization projects.
 *
 * Stat cards and filters (q, status, organization), a create / edit form
 * (ProjectRepo::create / update) and the inline status transition
 * (ProjectRepo::setStatus) offered for every row.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Permissions::requireCapability('manage_projects');

/** @return array{organization_id:int,title:string,description:string,objectives:string,target_participants:string,budget:string,funding_source:string,start_date:string,end_date:string,status:string,academic_year_id:int} */
function projects_form_data(): array
{
    $title = Security::clean((string) (Helpers::post('title') ?? ''), 180);
    if ($title === '') {
        throw new RuntimeException('A project needs a title.');
    }

    $budget = (string) (Helpers::post('budget') ?? '');
    $budget = $budget === '' ? '0' : $budget;
    if (!is_numeric($budget) || (float) $budget < 0) {
        throw new RuntimeException('The budget must be a positive amount.');
    }

    $startDate = (string) (Helpers::post('start_date') ?? '');
    $endDate   = (string) (Helpers::post('end_date') ?? '');
    if ($startDate !== '' && $endDate !== '' && $endDate < $startDate) {
        throw new RuntimeException('The end date cannot be before the start date.');
    }

    $status = (string) (Helpers::post('status') ?? 'proposed');
    if (!in_array($status, AcademicRepo::PROJECT_STATUSES, true)) {
        throw new RuntimeException('Unknown project status.');
    }

    return [
        'organization_id'     => Helpers::postInt('organization_id'),
        'title'               => $title,
        'description'         => Security::clean((string) (Helpers::post('description') ?? ''), 4000),
        'objectives'          => Security::clean((string) (Helpers::post('objectives') ?? ''), 4000),
        'target_participants' => Security::clean((string) (Helpers::post('target_participants') ?? ''), 160),
        'budget'              => $budget,
        'funding_source'      => Security::clean((string) (Helpers::post('funding_source') ?? ''), 160),
        'start_date'          => $startDate,
        'end_date'            => $endDate,
        'status'              => $status,
        'academic_year_id'    => Helpers::postInt('academic_year_id'),
    ];
}

/* ---- POST actions (run BEFORE any output) ---- */
if (Helpers::isPost()) {
    Security::requireCsrf();
    $action    = (string) (Helpers::post('action') ?? '');
    $projectId = Helpers::postInt('project_id');

    try {
        if ($action === 'create') {
            $data = projects_form_data();
            if ($data['organization_id'] < 1) {
                throw new RuntimeException('Choose the organization this project belongs to.');
            }
            $create = ['organization_id' => $data['organization_id'], 'title' => $data['title'],
                'description' => $data['description'], 'objectives' => $data['objectives'],
                'target_participants' => $data['target_participants'], 'budget' => $data['budget'],
                'funding_source' => $data['funding_source'], 'start_date' => $data['start_date'],
                'end_date' => $data['end_date'], 'status' => $data['status']];
            if ($data['academic_year_id'] > 0) {
                $create['academic_year_id'] = $data['academic_year_id'];
            }
            ProjectRepo::create($create);
            Helpers::flash('success', 'Project created.');
        } elseif ($action === 'edit') {
            if ($projectId < 1) {
                throw new RuntimeException('Project not found.');
            }
            $data = projects_form_data();
            ProjectRepo::update($projectId, [
                'title' => $data['title'], 'description' => $data['description'],
                'objectives' => $data['objectives'], 'target_participants' => $data['target_participants'],
                'budget' => $data['budget'], 'funding_source' => $data['funding_source'],
                'start_date' => $data['start_date'], 'end_date' => $data['end_date'],
            ]);
            Helpers::flash('success', 'Project updated.');
        } elseif ($action === 'status') {
            if ($projectId < 1) {
                throw new RuntimeException('Project not found.');
            }
            $status = (string) (Helpers::post('status') ?? '');
            ProjectRepo::setStatus($projectId, $status);
            Helpers::flash('success', 'Project status is now ' . ui_status($status) . '.');
        }
    } catch (Throwable $e) {
        Helpers::flash('error', $e->getMessage());
    }
    Helpers::redirect('admin/projects.php');
}

/* ---- read ---- */
$filters = [
    'q'               => (string) (Helpers::get('q') ?? ''),
    'status'          => (string) (Helpers::get('status') ?? ''),
    'organization_id' => (string) (Helpers::get('organization_id') ?? ''),
];

$result = ProjectRepo::list($filters, Helpers::page(), 20);
$page   = min(Helpers::page(), $result['pages']);
$rows   = $result['rows'];
$query  = http_build_query(array_filter($filters));

$counts = ProjectRepo::statusCounts();

$orgList     = OrgRepo::list([], 1, 300)['rows'];
$orgOptions  = [];
foreach ($orgList as $org) {
    $orgOptions[(int) $org['id']] = (string) $org['name'];
}
$statusOptions = array_combine(AcademicRepo::PROJECT_STATUSES, AcademicRepo::PROJECT_STATUSES);
$yearOptions   = AcademicRepo::yearOptions();

$editId  = Helpers::getInt('edit');
$newMode = Helpers::get('new') === '1';
$edit    = $editId > 0 ? ProjectRepo::find($editId) : null;

if ($editId > 0 && $edit === null) {
    Helpers::flash('error', 'That project no longer exists.');
    Helpers::redirect('admin/projects.php');
}

/* ---- page meta (before header) ---- */
$PAGE_TITLE  = 'Projects';
$PAGE_ACTIVE = 'projects';
$PAGE_SUB    = (int) $counts['total'] . ' project(s) · proposed, approved, ongoing, completed and cancelled work of every organization';
$PAGE_ACTIONS = ($newMode || $edit !== null)
    ? '<a class="btn ghost" href="' . Helpers::e(Helpers::url('admin/projects.php')) . '">' . icon('close', 16) . '<span>Close form</span></a>'
    : '<a class="btn" href="' . Helpers::e(Helpers::url('admin/projects.php?new=1')) . '">' . icon('plus', 16) . '<span>New project</span></a>';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'admin/dashboard.php'], ['label' => 'Projects']];

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="stat-grid">
  <?= ui_stat('Total projects', $counts['total'], 'layers') ?>
  <?= ui_stat('Proposed', $counts['proposed'], 'clipboard', $counts['proposed'] > 0 ? 'amber' : 'grey') ?>
  <?= ui_stat('Approved', $counts['approved'], 'check-circle', $counts['approved'] > 0 ? 'blue' : 'grey') ?>
  <?= ui_stat('Ongoing', $counts['ongoing'], 'play', $counts['ongoing'] > 0 ? 'amber' : 'grey') ?>
  <?= ui_stat('Completed', $counts['completed'], 'award', $counts['completed'] > 0 ? '' : 'grey') ?>
  <?= ui_stat('Cancelled', $counts['cancelled'], 'close', $counts['cancelled'] > 0 ? 'red' : 'grey') ?>
</div>

<?php if ($newMode || $edit !== null): ?>
  <section class="card">
    <div class="card-head">
      <h3><?= $edit !== null ? 'Edit project' : 'New project' ?></h3>
      <?php if ($edit !== null): ?>
        <span class="badge blue"><?= Helpers::e((string) $edit['organization_acronym']) ?></span>
      <?php endif; ?>
    </div>
    <form method="post">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="<?= $edit !== null ? 'edit' : 'create' ?>">
      <?php if ($edit !== null): ?>
        <input type="hidden" name="project_id" value="<?= (int) $edit['id'] ?>">
        <p class="hint">Organization and academic year are fixed once a project exists.
          Organization: <strong><?= Helpers::e((string) $edit['organization_name']) ?></strong>.</p>
      <?php else: ?>
        <?= ui_select('organization_id', 'Organization', $orgOptions, (string) (Helpers::get('organization_id') ?? ''), ['placeholder' => 'Choose an organization'], true) ?>
        <?= ui_select('academic_year_id', 'Academic year', $yearOptions, '', ['placeholder' => 'Current academic year']) ?>
      <?php endif; ?>

      <?= ui_input('title', 'Project title', (string) ($edit['title'] ?? ''), ['placeholder' => 'e.g. Coastal clean-up drive'], true) ?>
      <?= ui_textarea('description', 'Description', (string) ($edit['description'] ?? ''), ['rows' => 3]) ?>
      <?= ui_textarea('objectives', 'Objectives', (string) ($edit['objectives'] ?? ''), ['rows' => 3]) ?>

      <div class="grid cols-2">
        <?= ui_input('target_participants', 'Target participants', (string) ($edit['target_participants'] ?? ''), ['placeholder' => 'e.g. 120 students']) ?>
        <?= ui_input('budget', 'Budget (₱)', (string) ($edit['budget'] ?? '0'), ['type' => 'number', 'step' => '0.01', 'min' => '0']) ?>
        <?= ui_input('funding_source', 'Funding source', (string) ($edit['funding_source'] ?? ''), ['placeholder' => 'e.g. Student activity fee']) ?>
        <?php if ($edit === null): ?>
          <?= ui_select('status', 'Initial status', $statusOptions, 'proposed', [], true) ?>
        <?php endif; ?>
        <?= ui_input('start_date', 'Start date', (string) ($edit['start_date'] ?? ''), ['type' => 'date']) ?>
        <?= ui_input('end_date', 'End date', (string) ($edit['end_date'] ?? ''), ['type' => 'date']) ?>
      </div>

      <div class="form-actions">
        <button class="btn" type="submit"><?= icon('check', 16) ?><span><?= $edit !== null ? 'Save changes' : 'Create project' ?></span></button>
        <a class="btn grey" href="<?= Helpers::e(Helpers::url('admin/projects.php')) ?>">Cancel</a>
      </div>
    </form>
  </section>
<?php endif; ?>

<?= ui_filter_form('admin/projects.php',
    ui_filter_input('q', 'Search', $filters['q'])
    . ui_filter_select('status', 'Status', $statusOptions, $filters['status'])
    . ui_filter_select('organization_id', 'Organization', $orgOptions, $filters['organization_id'])) ?>

<section class="card">
  <div class="card-head">
    <h3>Projects</h3>
    <span class="badge blue"><?= (int) $result['total'] ?> found</span>
  </div>
  <?php if ($rows === []): ?>
    <?= ui_empty('No project matches these filters.', 'Create the first project with the button above.', 'layers') ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Project</th><th>Organization</th><th>Dates</th>
            <th class="num">Budget</th><th>Status</th><th class="actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td>
                <strong><?= Helpers::e(Helpers::excerpt((string) $row['title'], 56)) ?></strong>
                <span class="muted"><?= Helpers::e((string) ($row['academic_year_name'] ?? '—')) ?><?= $row['funding_source'] !== '' ? ' · ' . Helpers::e((string) $row['funding_source']) : '' ?></span>
              </td>
              <td class="small"><?= Helpers::e((string) $row['acronym']) ?><span class="muted"><?= Helpers::e(Helpers::excerpt((string) $row['organization_name'], 40)) ?></span></td>
              <td class="small nowrap">
                <?= Helpers::e(Helpers::fmtDate((string) ($row['start_date'] ?? ''))) ?>
                <?= $row['end_date'] !== null ? ' – ' . Helpers::e(Helpers::fmtDate((string) $row['end_date'])) : '' ?>
              </td>
              <td class="num nowrap"><?= Helpers::e(Helpers::money((string) $row['budget'])) ?></td>
              <td><?= ui_status_badge((string) $row['status']) ?></td>
              <td class="actions">
                <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('admin/projects.php?edit=' . (int) $row['id'])) ?>">
                  <?= icon('edit', 15) ?><span>Edit</span>
                </a>
                <form method="post" class="inline">
                  <?= Security::csrfField() ?>
                  <input type="hidden" name="action" value="status">
                  <input type="hidden" name="project_id" value="<?= (int) $row['id'] ?>">
                  <select name="status" aria-label="Status for <?= Helpers::e(Helpers::excerpt((string) $row['title'], 30)) ?>">
                    <?php foreach ($statusOptions as $value => $label): ?>
                      <option value="<?= Helpers::e((string) $value) ?>"<?= (string) $value === (string) $row['status'] ? ' selected' : '' ?>><?= Helpers::e(ui_status((string) $label)) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn sm" type="submit">Save</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= ui_pagination($page, $result['pages'], $query) ?>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
