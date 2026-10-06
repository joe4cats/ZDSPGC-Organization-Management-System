<?php
/**
 * admin/settings.php — system configuration & institutional policies.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

Auth::requireRole('admin');
Permissions::requireCapability('manage_settings');

if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) (Helpers::post('action') ?? '');

    try {
        if ($action === 'save_settings') {
            $keys = [
                'system_name',
                'school_name',
                'academic_year_label',
                'max_upload_mb',
                'accreditation_validity_years',
                'organization_registration_open',
                'membership_requires_officer_approval',
                'attendance_grace_minutes',
                'maintenance_mode',
                'contact_email',
            ];

            foreach ($keys as $key) {
                if (isset($_POST[$key])) {
                    $val = trim((string) $_POST[$key]);
                    Database::update('settings', [
                        'setting_value' => $val,
                        'updated_at'    => Helpers::now(),
                    ], 'setting_key = :k', ['k' => $key]);
                }
            }

            Audit::log('SETTINGS_UPDATED', 'settings', 'System settings updated by administrator ' . Auth::user()['full_name']);
            Helpers::flash('success', 'System settings saved successfully.');
        }
    } catch (Throwable $e) {
        Helpers::flash('error', $e->getMessage());
    }

    Helpers::redirect('admin/settings.php');
}

$settingsRaw = Database::all('SELECT * FROM settings ORDER BY setting_group, label');
$settings = [];
foreach ($settingsRaw as $s) {
    $settings[(string) $s['setting_key']] = (string) $s['setting_value'];
}

$counts = Schema::counts();

$PAGE_TITLE       = 'System Settings';
$PAGE_ACTIVE      = 'settings';
$PAGE_SUB         = 'Configure global application parameters, upload constraints, and institutional policies';
$PAGE_BREADCRUMBS = [['label' => 'Dashboard', 'href' => 'admin/dashboard.php'], ['label' => 'Settings']];

require __DIR__ . '/../includes/layout/header.php';
?>

<div class="grid cols-2">
  <section class="card">
    <div class="card-head">
      <h3>Institutional Configuration</h3>
      <?= ui_badge('Settings Table', 'blue') ?>
    </div>

    <form method="post" action="<?= Helpers::e(Helpers::url('admin/settings.php')) ?>" class="stack">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="save_settings">

      <?= ui_input('system_name', 'System Name', $settings['system_name'] ?? APP_NAME, ['placeholder' => 'ZDSPGC Organization Management System'], true) ?>
      <?= ui_input('school_name', 'Institution Name', $settings['school_name'] ?? SCHOOL_NAME, ['placeholder' => 'Zamboanga del Sur Provincial Government College'], true) ?>
      <?= ui_input('academic_year_label', 'Current Academic Year Label', $settings['academic_year_label'] ?? '2026–2027', ['placeholder' => '2026–2027'], true) ?>
      <?= ui_input('contact_email', 'Official Contact E-mail', $settings['contact_email'] ?? 'orgsys@zdspgc.edu.ph', ['type' => 'email'], true) ?>

      <div class="field">
        <label class="field-label" for="max_upload_mb">Max Upload Size (MB)</label>
        <input id="max_upload_mb" type="number" name="max_upload_mb" min="1" max="64" value="<?= Helpers::e($settings['max_upload_mb'] ?? '8') ?>" required>
        <span class="field-hint">Maximum file size permitted for document attachments and reports.</span>
      </div>

      <div class="field">
        <label class="field-label" for="accreditation_validity_years">Accreditation Validity (Years)</label>
        <input id="accreditation_validity_years" type="number" name="accreditation_validity_years" min="1" max="5" value="<?= Helpers::e($settings['accreditation_validity_years'] ?? '2') ?>" required>
      </div>

      <div class="field">
        <label class="field-label" for="attendance_grace_minutes">Attendance Grace Period (Minutes)</label>
        <input id="attendance_grace_minutes" type="number" name="attendance_grace_minutes" min="0" max="120" value="<?= Helpers::e($settings['attendance_grace_minutes'] ?? '15') ?>" required>
        <span class="field-hint">Time after event start before QR scans are marked as Late.</span>
      </div>

      <div class="field">
        <label class="field-label">Organization Policies</label>
        <div class="stack gap-tight">
          <label class="check">
            <input type="hidden" name="organization_registration_open" value="0">
            <input type="checkbox" name="organization_registration_open" value="1" <?= ($settings['organization_registration_open'] ?? '1') === '1' ? 'checked' : '' ?>>
            <span>Allow new student organization registration applications</span>
          </label>
          <label class="check">
            <input type="hidden" name="membership_requires_officer_approval" value="0">
            <input type="checkbox" name="membership_requires_officer_approval" value="1" <?= ($settings['membership_requires_officer_approval'] ?? '1') === '1' ? 'checked' : '' ?>>
            <span>Membership applications require officer review and approval</span>
          </label>
          <label class="check">
            <input type="hidden" name="maintenance_mode" value="0">
            <input type="checkbox" name="maintenance_mode" value="1" <?= ($settings['maintenance_mode'] ?? '0') === '1' ? 'checked' : '' ?>>
            <span>Maintenance mode (non-admins cannot modify records)</span>
          </label>
        </div>
      </div>

      <button class="btn" type="submit">
        <?= icon('check-circle', 16) ?><span>Save Configuration</span>
      </button>
    </form>
  </section>

  <div class="stack">
    <section class="card">
      <div class="card-head">
        <h3>System Environment & Database</h3>
        <?= ui_badge('Live', 'green') ?>
      </div>
      <table class="tbl compact">
        <tbody>
          <tr><th>PHP Version</th><td><?= PHP_VERSION ?></td></tr>
          <tr><th>Database Host</th><td><?= Helpers::e(DB_HOST . ':' . DB_PORT) ?></td></tr>
          <tr><th>Database Name</th><td><code><?= Helpers::e(DB_NAME) ?></code></td></tr>
          <tr><th>Session Name</th><td><code><?= Helpers::e(SESSION_NAME) ?></code></td></tr>
          <tr><th>Server Software</th><td><?= Helpers::e($_SERVER['SERVER_SOFTWARE'] ?? 'Apache / PHP CLI') ?></td></tr>
          <tr><th>Server Time</th><td><?= date('Y-m-d H:i:s T') ?></td></tr>
          <tr><th>System Version</th><td>v<?= Helpers::e(APP_VERSION) ?></td></tr>
        </tbody>
      </table>
    </section>

    <section class="card">
      <div class="card-head">
        <h3>Database Table Records</h3>
      </div>
      <div class="stat-grid">
        <?php foreach ($counts as $table => $c): ?>
          <?= ui_stat(ucwords(str_replace('_', ' ', $table)), $c, 'database') ?>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="card">
      <div class="card-head">
        <h3>System Maintenance</h3>
      </div>
      <p class="muted small mb">Run database migrations or verify system schema integrity:</p>
      <div class="btn-row">
        <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('install.php')) ?>">
          <?= icon('download', 14) ?><span>Installer & Schema Tool</span>
        </a>
        <a class="btn sm ghost" href="<?= Helpers::e(Helpers::url('admin/activity-logs.php')) ?>">
          <?= icon('log', 14) ?><span>View Audit Logs</span>
        </a>
      </div>
    </section>
  </div>
</div>

<?php require __DIR__ . '/../includes/layout/footer.php'; ?>
