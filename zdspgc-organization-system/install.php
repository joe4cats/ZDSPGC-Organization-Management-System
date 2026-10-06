<?php
/**
 * install.php — system database installer and administrator setup.
 *
 * Creates the database and tables (database/schema.sql), initialises institutional
 * configuration, and provides initial administrator account creation.
 */

declare(strict_types=1);

define('ZDSPGC_SKIP_INSTALL_CHECK', true);
require_once __DIR__ . '/includes/bootstrap.php';

/* An already installed system with users may only be reconfigured by an administrator. */
if (Schema::isInstalled() && Database::count('users') > 0 && !Auth::check()) {
    // If not signed in as admin, require login
    if (!Auth::check() || Auth::role() !== 'admin') {
        Helpers::flash('info', 'The system is installed. Please sign in as an administrator.');
        Helpers::redirect('login.php');
    }
}

$step   = Helpers::post('step') ?? '';
$report = [];
$error  = '';

if ($step !== '' && Helpers::isPost()) {
    Security::requireCsrf();
    try {
        if ($step === 'install') {
            $report['Database'] = Schema::databaseExists()
                ? 'Existing database "' . DB_NAME . '" found.'
                : 'Database "' . DB_NAME . '" created.';
            Schema::createDatabase();
            $migrated = Schema::migrate();
            $report['Schema'] = $migrated['statements'] . ' statements executed, '
                . count($migrated['tables']) . ' tables ready.';

            $report['Uploads'] = count(Uploads::createDemoFiles()) . ' storage directories and sample template files ready in uploads/.';
            $report['Result']  = 'Installation complete. You can now create your initial Administrator account.';
        } elseif ($step === 'reinstall') {
            $result = Schema::reinstall(false);
            $report['Schema'] = $result['statements'] . ' statements executed, ' . count($result['tables']) . ' tables ready.';
            Uploads::createDemoFiles();
            $report['Result'] = 'The database schema was reset from scratch.';
        } elseif ($step === 'create_admin') {
            $fullName = trim((string) (Helpers::post('admin_name') ?? ''));
            $username = trim((string) (Helpers::post('admin_username') ?? ''));
            $email    = trim((string) (Helpers::post('admin_email') ?? ''));
            $password = (string) (Helpers::post('admin_password') ?? '');
            $confirm  = (string) (Helpers::post('admin_password_confirm') ?? '');

            if ($fullName === '' || $username === '' || $email === '') {
                throw new RuntimeException('All administrator fields are required.');
            }
            if ($password === '') {
                throw new RuntimeException('Please provide a secure administrator password.');
            }
            if ($password !== $confirm) {
                throw new RuntimeException('The administrator passwords do not match.');
            }
            $problem = Security::passwordProblem($password);
            if ($problem !== null) {
                throw new RuntimeException($problem);
            }

            $userId = UserRepo::create([
                'username'  => $username,
                'email'     => $email,
                'full_name' => $fullName,
                'role'      => 'admin',
                'status'    => 'active',
            ], $password);

            Audit::log('ADMIN_CREATED', 'users', 'Initial administrator created: ' . $username, $userId);
            $report['Administrator'] = 'Administrator account "' . Helpers::e($username) . '" created successfully!';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$connected   = false;
$connectNote = '';
try {
    Database::serverConn()->query('SELECT 1');
    $connected = true;
} catch (Throwable $e) {
    $connectNote = $e->getMessage();
}

$dbExists   = Schema::databaseExists();
$installed  = Schema::isInstalled();
$adminCount = $installed ? (int) Database::count('users', "role = 'admin'") : 0;
$counts     = $installed ? Schema::counts() : [];

$PAGE_TITLE  = 'System Installation';
$PAGE_ACTIVE = '';
require __DIR__ . '/includes/layout/header.php';
?>

<div class="install-grid">
  <section class="card">
    <h2>1 · Database connection</h2>
    <table class="tbl compact">
      <tbody>
        <tr><th>Server</th><td><?= Helpers::e(DB_HOST . ':' . DB_PORT) ?></td></tr>
        <tr><th>Database</th><td><?= Helpers::e(DB_NAME) ?></td></tr>
        <tr><th>User</th><td><?= Helpers::e(DB_USER) ?></td></tr>
        <tr><th>Status</th><td><?= $connected ? ui_badge('Connected', 'green') : ui_badge('Cannot connect', 'red') ?></td></tr>
        <tr><th>Database exists</th><td><?= $dbExists ? ui_badge('Yes', 'green') : ui_badge('No', 'amber') ?></td></tr>
        <tr><th>System installed</th><td><?= $installed ? ui_badge('Yes', 'green') : ui_badge('No', 'amber') ?></td></tr>
      </tbody>
    </table>
    <?php if (!$connected): ?>
      <div class="flash flash-error">
        <?= icon('alert', 18) ?>
        <span>Start MySQL in the XAMPP control panel, then check config/database.php (host, port, user, password).</span>
      </div>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>2 · Install or update schema</h2>
    <?php if ($error !== ''): ?>
      <div class="flash flash-error"><?= icon('alert', 18) ?><span><?= Helpers::e($error) ?></span></div>
    <?php endif; ?>
    <?php foreach ($report as $label => $message): ?>
      <p class="install-step"><?= ui_badge($label, 'green') ?> <?= Helpers::e($message) ?></p>
    <?php endforeach; ?>

    <form method="post" action="install.php" class="stack">
      <?= Security::csrfField() ?>
      <div class="btn-row">
        <button class="btn" type="submit" name="step" value="install" <?= $connected ? '' : 'disabled' ?>>
          <?= icon('download', 17) ?><span><?= $installed ? 'Apply schema updates' : 'Install database' ?></span>
        </button>
        <?php if ($installed): ?>
          <button class="btn danger" type="submit" name="step" value="reinstall" <?= $connected ? '' : 'disabled' ?>
                  onclick="return confirm('This resets database tables. Continue?')">
            <?= icon('refresh', 17) ?><span>Rebuild schema</span>
          </button>
        <?php endif; ?>
      </div>
    </form>
    <p class="hint">Database tables are defined in database/schema.sql.</p>
  </section>

  <section class="card">
    <h2>3 · Administrator account</h2>
    <?php if ($adminCount === 0 && $installed): ?>
      <p class="muted small mb">No administrator account exists yet. Create your primary administrator account below:</p>
      <form method="post" action="install.php" class="stack">
        <?= Security::csrfField() ?>
        <input type="hidden" name="step" value="create_admin">
        <?= ui_input('admin_name', 'Full name', '', ['placeholder' => 'System Administrator'], true) ?>
        <?= ui_input('admin_username', 'Username', '', ['placeholder' => 'admin'], true) ?>
        <?= ui_input('admin_email', 'E-mail address', '', ['type' => 'email', 'placeholder' => 'admin@zdspgc.edu.ph'], true) ?>
        <?= ui_input('admin_password', 'Password', '', ['type' => 'password', 'hint' => 'At least 8 characters with letters and numbers.'], true) ?>
        <?= ui_input('admin_password_confirm', 'Confirm password', '', ['type' => 'password'], true) ?>
        <button class="btn" type="submit">
          <?= icon('check-circle', 17) ?><span>Create Administrator Account</span>
        </button>
      </form>
    <?php elseif ($adminCount > 0): ?>
      <div class="flash flash-success">
        <?= icon('check-circle', 18) ?>
        <span>System administrator account configured (<?= $adminCount ?> active). You can sign in using your credentials.</span>
      </div>
      <div class="btn-row mt">
        <a class="btn" href="<?= Helpers::e(Helpers::url('login.php')) ?>">
          <?= icon('logout', 17) ?><span>Go to sign-in</span>
        </a>
      </div>
    <?php else: ?>
      <p class="muted small">Please install the database schema first to enable administrator account creation.</p>
    <?php endif; ?>
  </section>

  <?php if ($installed): ?>
  <section class="card">
    <h2>4 · Current contents</h2>
    <div class="stat-grid">
      <?php foreach ($counts as $table => $count): ?>
        <?= ui_stat(ucwords(str_replace('_', ' ', $table)), $count, 'database') ?>
      <?php endforeach; ?>
    </div>
    <div class="btn-row mt">
      <a class="btn" href="<?= Helpers::e(Helpers::url('login.php')) ?>"><?= icon('logout', 17) ?><span>Go to sign-in</span></a>
      <a class="btn ghost" href="<?= Helpers::e(Helpers::url('index.php')) ?>"><?= icon('external', 17) ?><span>Public directory</span></a>
    </div>
  </section>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout/footer.php'; ?>
