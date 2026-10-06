<?php
/**
 * organization/profile.php — identity, classification and accreditation of the
 * organization opened in the officer workspace.
 *
 * Editable here: name, acronym, description, type, category, department,
 * date established and the logo. Code, status, accreditation state and the
 * adviser are read-only records maintained by the Office of Student Affairs.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Permissions::requireCapability('edit_organization');

$org = Permissions::activeOrganization();
if ($org === null) {
    Helpers::flash('error', 'No organization workspace is open.');
    Helpers::redirect('organization/dashboard.php');
}
$orgId = (int) $org['id'];
Permissions::requireOrganization($orgId);

/* ---- POST actions (run BEFORE any output) ---- */
if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) (Helpers::post('action') ?? '');

    try {
        if ($action === 'apply_accreditation') {
            OrgRepo::submitAccreditation($orgId);
            Helpers::flash('success', 'Your accreditation application was submitted for review.');
        } else {
            $name = Security::clean((string) (Helpers::post('name') ?? ''), 180);
            if ($name === '') {
                throw new RuntimeException('The organization name cannot be empty.');
            }

            $type    = (string) (Helpers::post('organization_type') ?? 'Other');
            $date    = (string) (Helpers::post('date_established') ?? '');
            $date    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null;
            $data    = [
                'name'              => $name,
                'acronym'           => Security::clean((string) (Helpers::post('acronym') ?? ''), 30),
                'description'       => Security::clean((string) (Helpers::post('description') ?? ''), 4000),
                'organization_type' => in_array($type, AcademicRepo::ORGANIZATION_TYPES, true) ? $type : 'Other',
                'category_id'       => Helpers::postInt('category_id') > 0 ? Helpers::postInt('category_id') : null,
                'department_id'     => Helpers::postInt('department_id') > 0 ? Helpers::postInt('department_id') : null,
                'date_established'  => $date,
            ];

            if (!empty($_FILES['logo']['name'])) {
                $stored = Uploads::image($_FILES['logo'], 'organizations');
                if (!$stored['ok']) {
                    throw new RuntimeException($stored['message']);
                }
                $data['logo'] = $stored['path'];
            }

            OrgRepo::update($orgId, $data);
            Helpers::flash('success', 'The organization profile was saved.');
        }
    } catch (Throwable $e) {
        Helpers::flash('error', $e->getMessage());
    }
    Helpers::redirect('organization/profile.php');
}

/* ---- read ---- */
$record       = OrgRepo::find($orgId) ?? $org;
$accreditation = OrgRepo::latestAccreditation($orgId);
$types        = array_combine(AcademicRepo::ORGANIZATION_TYPES, AcademicRepo::ORGANIZATION_TYPES);
$canApply     = $accreditation === null
    || !in_array((string) $accreditation['status'], ['pending', 'under_review'], true);
$logoUrl = Uploads::url((string) $record['logo']);

$PAGE_TITLE  = 'Organization Profile';
$PAGE_ACTIVE = 'profile';
$PAGE_SUB    = Helpers::e((string) $record['name']) . ' · ' . Helpers::e((string) $record['organization_code'])
    . ' · ' . ui_status((string) $record['status']);
$PAGE_ACTIONS = '<a class="btn ghost" href="' . Helpers::e(Helpers::url('organization/settings.php')) . '">'
    . icon('settings', 16) . '<span>Contact &amp; links</span></a>'
    . '<a class="btn" href="' . Helpers::e(Helpers::url('organization/documents.php')) . '">'
    . icon('folder', 16) . '<span>Documents</span></a>';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'organization/dashboard.php'], ['label' => 'Organization Profile']];

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="grid cols-2">
  <section class="card">
    <div class="card-head"><h3>Organization identity</h3></div>
    <form method="post" enctype="multipart/form-data">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="save">

      <?= ui_input('name', 'Organization name', (string) $record['name'], [], true) ?>
      <?= ui_input('acronym', 'Acronym', (string) $record['acronym'], ['hint' => 'Short form used on codes and badges.']) ?>
      <?= ui_textarea('description', 'Description', (string) ($record['description'] ?? ''), ['rows' => '5', 'hint' => 'Purpose, membership and main activities.']) ?>

      <div class="grid cols-2">
        <?= ui_select('organization_type', 'Organization type', $types, (string) $record['organization_type'], [], true) ?>
        <?= ui_select('category_id', 'Category', AcademicRepo::categoryOptions(), (string) ($record['category_id'] ?? ''), ['placeholder' => '— No category —']) ?>
      </div>

      <div class="grid cols-2">
        <?= ui_select('department_id', 'Department', AcademicRepo::departmentOptions(), (string) ($record['department_id'] ?? ''), ['placeholder' => '— Not department-based —']) ?>
        <?= ui_input('date_established', 'Date established', !empty($record['date_established']) ? (string) $record['date_established'] : '', ['type' => 'date']) ?>
      </div>

      <div class="org-cell mb-tight">
        <?= ui_org_badge($record, 56) ?>
        <div class="person-meta">
          <strong><?= Helpers::e($logoUrl !== null && $logoUrl !== '' ? 'Logo uploaded' : 'No logo yet') ?></strong>
          <small>JPG, PNG, GIF or WEBP · up to <?= (int) PROFILE_MAX_MB ?> MB.</small>
        </div>
      </div>

      <label class="field">
        <span class="field-label">Organization logo</span>
        <input type="file" name="logo" accept="image/jpeg,image/png,image/gif,image/webp">
        <span class="field-hint">Leave empty to keep the current logo.</span>
      </label>

      <div class="form-actions">
        <button class="btn" type="submit"><?= icon('check', 16) ?><span>Save profile</span></button>
      </div>
    </form>
  </section>

  <div>
    <section class="card">
      <div class="card-head"><h3>Official record</h3><span class="muted small">Managed by the administrator</span></div>
      <dl class="detail-list">
        <dt>Organization code</dt><dd><code><?= Helpers::e((string) $record['organization_code']) ?></code></dd>
        <dt>Status</dt><dd><?= ui_status_badge((string) $record['status']) ?></dd>
        <dt>Accreditation</dt>
        <dd><?= ui_status_badge((string) $record['accreditation_status']) ?>
            <?php if (!empty($record['accreditation_expires_at'])): ?>
              <span class="muted small"> · expires <?= Helpers::e(Helpers::fmtDate((string) $record['accreditation_expires_at'])) ?></span>
            <?php endif; ?>
        </dd>
        <dt>Adviser</dt>
        <dd><?= !empty($record['adviser_name']) ? Helpers::e((string) $record['adviser_name'])
            : '<span class="muted">No adviser assigned yet.</span>' ?></dd>
        <dt>Category</dt>
        <dd><?= !empty($record['category_name']) ? Helpers::e((string) $record['category_name']) : '<span class="muted">—</span>' ?></dd>
        <dt>Department</dt>
        <dd><?= !empty($record['department_name']) ? Helpers::e((string) $record['department_name']) : '<span class="muted">—</span>' ?></dd>
        <dt>Registered on</dt><dd><?= Helpers::e(Helpers::fmtDate((string) $record['created_at'])) ?></dd>
      </dl>
    </section>

    <section class="card">
      <div class="card-head">
        <h3>Accreditation</h3>
        <?= $accreditation !== null ? ui_status_badge((string) $accreditation['status']) : ui_badge('Not applied', 'grey') ?>
      </div>
      <?php if ($accreditation === null): ?>
        <?= ui_empty('No accreditation application has been filed.', 'Submit one when the requirements are complete.', 'award') ?>
      <?php else: ?>
        <dl class="detail-list">
          <dt>Filed</dt><dd><?= Helpers::e(Helpers::fmtDateTime((string) $accreditation['submitted_at'])) ?></dd>
          <dt>Academic year</dt><dd><?= Helpers::e((string) ($accreditation['academic_year_name'] ?? '—')) ?></dd>
          <dt>Reviewer</dt>
          <dd><?= !empty($accreditation['reviewer_name']) ? Helpers::e((string) $accreditation['reviewer_name'])
              : '<span class="muted">Awaiting review.</span>' ?></dd>
          <dt>Decision notes</dt>
          <dd><?= !empty($accreditation['decision_notes']) ? Helpers::e((string) $accreditation['decision_notes'])
              : '<span class="muted">—</span>' ?></dd>
          <?php if (!empty($accreditation['expires_at'])): ?>
            <dt>Valid until</dt><dd><?= Helpers::e(Helpers::fmtDate((string) $accreditation['expires_at'])) ?></dd>
          <?php endif; ?>
        </dl>
      <?php endif; ?>

      <?php if ($canApply): ?>
        <form method="post" class="inline-form mt-tight" data-confirm="Submit a new accreditation application for this organization?">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="apply_accreditation">
          <button class="btn sm" type="submit"><?= icon('upload', 15) ?><span>Apply for accreditation</span></button>
        </form>
      <?php else: ?>
        <p class="hint">An application is already waiting for a decision.</p>
      <?php endif; ?>
    </section>
  </div>
</div>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
