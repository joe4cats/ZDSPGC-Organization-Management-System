<?php
/**
 * organization/settings.php — organization contact details, social media & preferences.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireRole('officer');
Permissions::requireCapability('edit_organization');

$org = Permissions::activeOrganization();
if ($org === null) {
    Helpers::flash('error', 'You must be assigned to an active organization.');
    Helpers::redirect('organization/dashboard.php');
}
$orgId = (int) $org['id'];

if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) (Helpers::post('action') ?? '');

    try {
        if ($action === 'save_settings') {
            $contactEmail  = trim((string) (Helpers::post('contact_email') ?? ''));
            $contactNumber = trim((string) (Helpers::post('contact_number') ?? ''));
            $socialLink    = trim((string) (Helpers::post('social_link') ?? ''));
            $notes         = trim((string) (Helpers::post('notes') ?? ''));

            if ($contactEmail !== '' && !Security::validEmail($contactEmail)) {
                throw new RuntimeException('Please enter a valid e-mail address.');
            }

            Database::update('organizations', [
                'contact_email'  => $contactEmail !== '' ? $contactEmail : null,
                'contact_number' => $contactNumber !== '' ? $contactNumber : null,
                'social_link'    => $socialLink !== '' ? $socialLink : null,
                'notes'          => $notes !== '' ? $notes : null,
                'updated_at'     => Helpers::now(),
            ], 'id = :id', ['id' => $orgId]);

            Audit::log('ORG_SETTINGS_UPDATED', 'organizations', 'Organization settings updated by ' . Auth::user()['full_name'], $orgId);
            Helpers::flash('success', 'Organization preferences saved successfully.');
        }
    } catch (Throwable $e) {
        Helpers::flash('error', $e->getMessage());
    }

    Helpers::redirect('organization/settings.php');
}

$freshOrg = OrgRepo::find($orgId);

$PAGE_TITLE       = 'Organization Settings';
$PAGE_ACTIVE      = 'settings';
$PAGE_SUB         = Helpers::e((string) $org['name']) . ' · Manage communications, public channels and preferences';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'organization/dashboard.php'], ['label' => 'Settings']];

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="grid cols-2">
  <section class="card">
    <div class="card-head">
      <h3>Contact & Communication Channels</h3>
      <?= ui_badge('Public Information', 'blue') ?>
    </div>

    <form method="post" action="<?= Helpers::e(Helpers::url('organization/settings.php')) ?>" class="stack">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="save_settings">

      <?= ui_input('contact_email', 'Official Organization E-mail', (string) ($freshOrg['contact_email'] ?? ''), ['type' => 'email', 'placeholder' => 'org@zdspgc.edu.ph', 'hint' => 'Inquiries from students and administration will be directed here.']) ?>

      <?= ui_input('contact_number', 'Contact Hotline / Mobile', (string) ($freshOrg['contact_number'] ?? ''), ['type' => 'tel', 'placeholder' => '0919-000-0000']) ?>

      <?= ui_input('social_link', 'Official Facebook / Social Media Link', (string) ($freshOrg['social_link'] ?? ''), ['type' => 'url', 'placeholder' => 'https://facebook.com/organization.page', 'hint' => 'Displayed on your public organization profile page.']) ?>

      <div class="field">
        <label class="field-label" for="org-notes">Public Announcements / Membership Guidelines</label>
        <textarea id="org-notes" name="notes" rows="4" placeholder="Brief requirements or general notes for students looking to join..."><?= Helpers::e((string) ($freshOrg['notes'] ?? '')) ?></textarea>
      </div>

      <button class="btn" type="submit"><?= icon('check-circle', 16) ?><span>Save Preferences</span></button>
    </form>
  </section>

  <div class="stack">
    <section class="card">
      <div class="card-head">
        <h3>Organization Identity</h3>
        <a class="btn xs ghost" href="<?= Helpers::e(Helpers::url('organization/profile.php')) ?>">Edit Profile</a>
      </div>
      <table class="tbl compact">
        <tbody>
          <tr><th>Official Name</th><td><?= Helpers::e((string) $freshOrg['name']) ?></td></tr>
          <tr><th>Acronym</th><td><?= Helpers::e((string) ($freshOrg['acronym'] ?: '—')) ?></td></tr>
          <tr><th>Organization Code</th><td><code><?= Helpers::e((string) $freshOrg['organization_code']) ?></code></td></tr>
          <tr><th>Accreditation</th><td><?= ui_status_badge((string) $freshOrg['accreditation_status']) ?></td></tr>
          <tr><th>Assigned Adviser</th><td><?= Helpers::e((string) ($freshOrg['adviser_name'] ?? 'Unassigned')) ?></td></tr>
        </tbody>
      </table>
    </section>

    <section class="card">
      <div class="card-head">
        <h3>Quick Navigation</h3>
      </div>
      <div class="btn-row">
        <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('organization/profile.php')) ?>">
          <?= icon('org', 14) ?><span>Organization Profile</span>
        </a>
        <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('organization.php?id=' . $orgId)) ?>" target="_blank">
          <?= icon('external', 14) ?><span>View Public Page</span>
        </a>
      </div>
    </section>
  </div>
</div>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
