<?php
/**
 * admin/organizations.php — organization registry, filters, edit form and
 * approval queue.
 *
 * Server-side filters (q, status, category, department, type) over OrgRepo::list,
 * the ?edit=<id> form (identity, classification, contact and adviser — same
 * fields the officer profile keeps, plus the adviser the OSA assigns), and the
 * approve / reject / archive / restore decisions of the admin dashboard.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Permissions::requireCapability('manage_organizations');

/* ---- registration decisions (before any output) ---- */
if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) (Helpers::post('action') ?? '');
    $orgId  = Helpers::postInt('organization_id');

    if (in_array($action, ['approve', 'reject', 'archive', 'restore'], true) && $orgId > 0) {
        $org = OrgRepo::find($orgId);
        if ($org === null) {
            Helpers::flash('error', 'That organization no longer exists.');
        } else {
            $status = ['approve' => 'active', 'reject' => 'rejected', 'archive' => 'archived', 'restore' => 'active'][$action];
            $reason = (string) (Helpers::post('reason') ?? '');
            if ($action === 'reject' && $reason === '') {
                $reason = 'The application did not meet the registration requirements.';
            }
            try {
                OrgRepo::setStatus($orgId, $status, $reason);
                Helpers::flash('success', $org['name'] . ' is now ' . ui_status($status) . '.');
            } catch (Throwable $e) {
                Helpers::flash('error', $e->getMessage());
            }
        }
        Helpers::redirect('admin/organizations.php');
    }

    /* ---- edit form save ---- */
    if ($action === 'save') {
        $org = OrgRepo::find($orgId);
        if ($org === null) {
            Helpers::flash('error', 'That organization no longer exists.');
            Helpers::redirect('admin/organizations.php');
        }
        try {
            $name = Security::clean((string) (Helpers::post('name') ?? ''), 180);
            if ($name === '') {
                throw new RuntimeException('The organization name cannot be empty.');
            }
            $type    = (string) (Helpers::post('organization_type') ?? 'Other');
            $date    = (string) (Helpers::post('date_established') ?? '');
            $date    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null;
            $email   = trim((string) (Helpers::post('contact_email') ?? ''));
            $phone   = trim((string) (Helpers::post('contact_number') ?? ''));
            $social  = trim((string) (Helpers::post('social_link') ?? ''));
            $adviser = Helpers::postInt('adviser_id');

            if ($email !== '' && !Security::validEmail($email)) {
                throw new RuntimeException('Please enter a valid contact e-mail address.');
            }
            if ($adviser > 0 && Database::count('advisers', 'id = :id', ['id' => $adviser]) === 0) {
                throw new RuntimeException('The selected adviser does not exist.');
            }

            $data = [
                'name'              => $name,
                'acronym'           => Security::clean((string) (Helpers::post('acronym') ?? ''), 30),
                'description'       => Security::clean((string) (Helpers::post('description') ?? ''), 4000),
                'organization_type' => in_array($type, AcademicRepo::ORGANIZATION_TYPES, true) ? $type : 'Other',
                'category_id'       => Helpers::postInt('category_id') > 0 ? Helpers::postInt('category_id') : null,
                'department_id'     => Helpers::postInt('department_id') > 0 ? Helpers::postInt('department_id') : null,
                'date_established'  => $date,
                'adviser_id'        => $adviser > 0 ? $adviser : null,
                'contact_email'     => $email !== '' ? $email : null,
                'contact_number'    => $phone !== '' ? $phone : null,
                'social_link'       => $social !== '' ? $social : null,
            ];

            if (!empty($_FILES['logo']['name'])) {
                $stored = Uploads::image($_FILES['logo'], 'organizations');
                if (!$stored['ok']) {
                    throw new RuntimeException($stored['message']);
                }
                $data['logo'] = $stored['path'];
            }

            OrgRepo::update($orgId, $data);
            Helpers::flash('success', $org['name'] . ' was updated.');
            Helpers::redirect('admin/organizations.php');
        } catch (Throwable $e) {
            Helpers::flash('error', $e->getMessage());
            Helpers::redirect('admin/organizations.php?edit=' . $orgId);
        }
    }
    Helpers::redirect('admin/organizations.php');
}

/* ---- read ---- */
$filters = [
    'q'                 => (string) (Helpers::get('q') ?? ''),
    'status'            => (string) (Helpers::get('status') ?? ''),
    'category_id'       => (string) (Helpers::get('category_id') ?? ''),
    'department_id'     => (string) (Helpers::get('department_id') ?? ''),
    'organization_type' => (string) (Helpers::get('type') ?? ''),
];

$stats  = OrgRepo::dashboardStats();
$result = OrgRepo::list($filters, Helpers::page(), 20);
$page   = min(Helpers::page(), $result['pages']);
$rows   = $result['rows'];

$query = http_build_query(array_filter([
    'q'             => $filters['q'],
    'status'        => $filters['status'],
    'category_id'   => $filters['category_id'],
    'department_id' => $filters['department_id'],
    'type'          => $filters['organization_type'],
]));

$statusOptions = [];
foreach (AcademicRepo::ORGANIZATION_STATUSES as $statusOption) {
    $statusOptions[$statusOption] = ui_status($statusOption);
}
$typeOptions = array_combine(AcademicRepo::ORGANIZATION_TYPES, AcademicRepo::ORGANIZATION_TYPES);

/* ---- edit record (?edit=<id>) ---- */
$editId  = (int) (Helpers::get('edit') ?? 0);
$editOrg = $editId > 0 ? OrgRepo::find($editId) : null;
if ($editId > 0 && $editOrg === null) {
    Helpers::flash('warning', 'That organization no longer exists.');
    Helpers::redirect('admin/organizations.php');
}
$adviserOptions = $editOrg !== null ? UserRepo::adviserOptions() : [];

/* ---- page meta (before header) ---- */
$PAGE_TITLE  = $editOrg !== null ? 'Edit Organization' : 'Organizations';
$PAGE_ACTIVE = 'organizations';
$PAGE_SUB    = $editOrg !== null
    ? 'Change the record of ' . Helpers::e((string) $editOrg['name']) . ' — status and accreditation are managed from the actions below.'
    : 'Manage the registry of student organizations and every registration decision.';
$PAGE_ACTIONS = '<a class="btn ghost" href="' . Helpers::e(Helpers::url('organizations.php')) . '">' . icon('eye', 16)
    . '<span>Public directory</span></a>';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'admin/dashboard.php'], ['label' => 'Organizations', 'href' => 'admin/organizations.php']];
if ($editOrg !== null) {
    $PAGE_BREADCRUMBS[] = ['label' => 'Edit'];
}

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="stat-grid">
  <?= ui_stat('Total organizations', $stats['total'], 'org') ?>
  <?= ui_stat('Active', $stats['active'], 'check-circle') ?>
  <?= ui_stat('Pending applications', $stats['pending'], 'clock', $stats['pending'] > 0 ? 'amber' : 'grey') ?>
  <?= ui_stat('Suspended', $stats['suspended'], 'alert', $stats['suspended'] > 0 ? 'red' : 'grey') ?>
  <?= ui_stat('Archived', $stats['archived'], 'folder', '', '') ?>
  <?= ui_stat('Student members', $stats['members'], 'users') ?>
</div>

<?php if ($editOrg !== null): ?>
<section class="card">
  <div class="card-head">
    <div>
      <h3>Edit organization record</h3>
      <p class="sub muted small"><?= Helpers::e((string) $editOrg['name']) ?> · <?= Helpers::e((string) $editOrg['organization_code']) ?> · <?= ui_status_badge((string) $editOrg['status']) ?></p>
    </div>
  </div>

  <form method="post" enctype="multipart/form-data">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="organization_id" value="<?= (int) $editOrg['id'] ?>">

    <?= ui_input('name', 'Organization name', (string) $editOrg['name'], [], true) ?>
    <?= ui_input('acronym', 'Acronym', (string) $editOrg['acronym'], ['hint' => 'Short form used on codes and badges.']) ?>
    <?= ui_textarea('description', 'Description', (string) ($editOrg['description'] ?? ''), ['rows' => '5', 'hint' => 'Purpose, membership and main activities.']) ?>

    <div class="grid cols-2">
      <?= ui_select('organization_type', 'Organization type', $typeOptions, (string) $editOrg['organization_type'], [], true) ?>
      <?= ui_select('category_id', 'Category', AcademicRepo::categoryOptions(), (string) ($editOrg['category_id'] ?? ''), ['placeholder' => '— No category —']) ?>
    </div>

    <div class="grid cols-2">
      <?= ui_select('department_id', 'Department', AcademicRepo::departmentOptions(), (string) ($editOrg['department_id'] ?? ''), ['placeholder' => '— Not department-based —']) ?>
      <?= ui_input('date_established', 'Date established', !empty($editOrg['date_established']) ? (string) $editOrg['date_established'] : '', ['type' => 'date']) ?>
    </div>

    <div class="grid cols-2">
      <?= ui_select('adviser_id', 'Faculty adviser', $adviserOptions, (string) ($editOrg['adviser_id'] ?? ''), ['placeholder' => '— No adviser —']) ?>
      <label class="field">
        <span class="field-label">Logo</span>
        <input type="file" name="logo" id="logo" accept="image/png,image/jpeg,image/webp">
        <span class="field-hint">Leave empty to keep the current logo. JPG, PNG or WEBP up to 2 MB.</span>
      </label>
    </div>

    <div class="grid cols-2">
      <?= ui_input('contact_email', 'Contact e-mail', (string) ($editOrg['contact_email'] ?? ''), ['type' => 'email', 'placeholder' => 'org@zdspgc.edu.ph']) ?>
      <?= ui_input('contact_number', 'Contact number', (string) ($editOrg['contact_number'] ?? ''), ['placeholder' => '09XXXXXXXXX']) ?>
    </div>

    <?= ui_input('social_link', 'Social media link', (string) ($editOrg['social_link'] ?? ''), ['type' => 'url', 'placeholder' => 'https://facebook.com/organization.page', 'hint' => 'Shown on the public organization profile.']) ?>

    <div class="form-actions">
      <button class="btn" type="submit"><?= icon('check', 16) ?><span>Save changes</span></button>
      <a class="btn grey" href="<?= Helpers::e(Helpers::url('admin/organizations.php')) ?>"><?= icon('close', 16) ?><span>Cancel</span></a>
    </div>
  </form>
</section>
<?php endif; ?>

<?= ui_filter_form('admin/organizations.php',
    ui_filter_input('q', 'Search', $filters['q'])
    . ui_filter_select('status', 'Status', $statusOptions, $filters['status'])
    . ui_filter_select('category_id', 'Category', AcademicRepo::categoryOptions(), $filters['category_id'])
    . ui_filter_select('department_id', 'Department', AcademicRepo::departmentOptions(), $filters['department_id'])
    . ui_filter_select('type', 'Type', $typeOptions, $filters['organization_type'])) ?>

<section class="card">
  <div class="card-head">
    <h3>Registered organizations</h3>
    <?= ui_badge((string) $result['total'], 'blue') ?>
  </div>

  <?php if ($rows === []): ?>
    <?= ui_empty('No organization matches this search.', 'Adjust the filters above or clear them to see every record.', 'org') ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>Organization</th>
            <th>Type</th>
            <th>Category</th>
            <th>Status</th>
            <th>Accreditation</th>
            <th class="num">Members</th>
            <th>Adviser</th>
            <th class="actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $org): ?>
            <tr>
              <td>
                <div class="org-cell">
                  <?= ui_org_badge($org, 36) ?>
                  <div>
                    <strong><a href="<?= Helpers::e(Helpers::url('organization.php?id=' . (int) $org['id'])) ?>"><?= Helpers::e((string) $org['name']) ?></a></strong>
                    <small><?= Helpers::e((string) $org['organization_code']) ?><?= (string) $org['acronym'] !== '' ? ' · ' . Helpers::e((string) $org['acronym']) : '' ?></small>
                  </div>
                </div>
              </td>
              <td class="small"><?= Helpers::e((string) $org['organization_type']) ?></td>
              <td class="small"><?= Helpers::e((string) ($org['category_name'] !== '' ? $org['category_name'] : '—')) ?></td>
              <td><?= ui_status_badge((string) $org['status']) ?></td>
              <td><?= ui_status_badge((string) $org['accreditation_status']) ?></td>
              <td class="num"><?= (int) $org['member_count'] ?></td>
              <td class="small"><?= Helpers::e((string) ($org['adviser_name'] !== '' ? $org['adviser_name'] : '—')) ?></td>
              <td class="actions nowrap">
                <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('admin/organizations.php?edit=' . (int) $org['id'])) ?>"
                   title="Edit organization record"><?= icon('edit', 15) ?><span>Edit</span></a>
                <form method="post" class="inline">
                  <?= Security::csrfField() ?>
                  <input type="hidden" name="organization_id" value="<?= (int) $org['id'] ?>">
                  <input type="hidden" name="reason" value="">
                  <?php if ((string) $org['status'] === 'pending'): ?>
                    <button class="btn sm" type="submit" name="action" value="approve"
                            onclick="return confirm('Approve this registration and make the organization active?')">Approve</button>
                    <button class="btn sm danger" type="submit" name="action" value="reject"
                            onclick="var r=prompt('Reason for rejecting this registration:'); if(r===null){return false;} this.form.reason.value=r; return true;">Reject</button>
                  <?php elseif ((string) $org['status'] === 'archived' || (string) $org['status'] === 'rejected'): ?>
                    <button class="btn sm" type="submit" name="action" value="restore"
                            onclick="return confirm('Restore this organization as active?')">Restore</button>
                  <?php else: ?>
                    <button class="btn sm danger" type="submit" name="action" value="archive"
                            onclick="return confirm('Archive this organization? Its records are kept, it stops appearing in the directory.')">Archive</button>
                  <?php endif; ?>
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
