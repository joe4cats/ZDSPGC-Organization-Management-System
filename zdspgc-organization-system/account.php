<?php
/**
 * account.php — profile, avatar and password for every role.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

Auth::requireLogin();

$user    = Auth::user();
$profile = Auth::studentProfile();
$notice  = '';
$error   = '';

if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) (Helpers::post('action') ?? '');

    try {
        if ($action === 'profile') {
            $name  = Security::clean((string) (Helpers::post('full_name') ?? ''), 160);
            $phone = Security::clean((string) (Helpers::post('phone') ?? ''), 30);

            if ($name === '') {
                throw new RuntimeException('Your name cannot be empty.');
            }
            if ($phone !== '' && preg_match('/^[0-9+\-\s()]{7,20}$/', $phone) !== 1) {
                throw new RuntimeException('That contact number does not look valid.');
            }

            $row = ['full_name' => $name, 'phone' => $phone, 'updated_at' => Helpers::now()];
            if (!empty($_FILES['avatar']['name'])) {
                $stored = Uploads::image($_FILES['avatar'], 'profiles');
                if (!$stored['ok']) {
                    throw new RuntimeException($stored['message']);
                }
                $row['avatar'] = $stored['path'];
            }
            Database::update('users', $row, 'id = :id', ['id' => (int) $user['id']]);
            $_SESSION['name'] = $name;
            Security::audit('PROFILE_UPDATED', 'users', 'Updated own profile');
            $notice = 'Your profile was saved.';
        } elseif ($action === 'password') {
            $current = (string) (Helpers::post('current_password') ?? '');
            $new     = (string) (Helpers::post('new_password') ?? '');
            $confirm = (string) (Helpers::post('confirm_password') ?? '');

            $hash = (string) Database::value('SELECT password_hash FROM users WHERE id = :id', ['id' => (int) $user['id']], '');
            if (!password_verify($current, $hash)) {
                throw new RuntimeException('Your current password is incorrect.');
            }
            $problem = Security::passwordProblem($new);
            if ($problem !== null) {
                throw new RuntimeException($problem);
            }
            if ($new !== $confirm) {
                throw new RuntimeException('The two new passwords do not match.');
            }

            Database::update('users', ['password_hash' => Auth::hash($new), 'updated_at' => Helpers::now()], 'id = :id', ['id' => (int) $user['id']]);
            Database::delete('remember_tokens', 'user_id = :u', ['u' => (int) $user['id']]);
            Security::audit('PASSWORD_CHANGED', 'auth', 'Changed own password');
            $notice = 'Your password was changed. Other devices were signed out.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$PAGE_TITLE  = 'My Profile';
$PAGE_ACTIVE = 'account';
$PAGE_SUB    = 'Your account information for ' . SCHOOL_NAME;

require __DIR__ . '/includes/layout/header.php';
?>

<?php if ($notice !== ''): ?>
  <div class="flash flash-success"><?= icon('check-circle', 18) ?><span><?= Helpers::e($notice) ?></span></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="flash flash-error"><?= icon('alert', 18) ?><span><?= Helpers::e($error) ?></span></div>
<?php endif; ?>

<div class="grid cols-2">
  <div>
    <section class="card">
      <div class="card-head"><h3>Account</h3></div>
      <div class="person-cell mb-tight">
        <?= ui_avatar((string) $user['full_name'], Uploads::url((string) $user['avatar']), 56) ?>
        <div class="person-meta">
          <strong class="strong-lg"><?= Helpers::e((string) $user['full_name']) ?></strong>
          <small><?= Helpers::e(Auth::roleLabel()) ?></small>
        </div>
      </div>
      <dl class="detail-list">
        <dt>Username</dt><dd><code><?= Helpers::e((string) $user['username']) ?></code></dd>
        <dt>E-mail</dt><dd><?= Helpers::e((string) $user['email']) ?></dd>
        <dt>Role</dt><dd><?= ui_badge(Auth::roleLabel(), 'blue') ?></dd>
        <dt>Status</dt><dd><?= ui_status_badge((string) $user['status']) ?></dd>
        <dt>Last sign-in</dt><dd><?= Helpers::e(Helpers::fmtDateTime((string) ($user['last_login_at'] ?? ''))) ?></dd>
        <?php if ($profile !== null): ?>
          <dt>Student ID</dt><dd><?= Helpers::e((string) $profile['student_id']) ?></dd>
          <dt>Course</dt><dd><?= Helpers::e((string) $profile['course']) ?></dd>
          <dt>Year &amp; section</dt><dd><?= Helpers::e((string) $profile['year_level'] . ' · ' . (string) $profile['section']) ?></dd>
        <?php endif; ?>
      </dl>
    </section>

    <section class="card">
      <div class="card-head"><h3>Change password</h3></div>
      <form method="post" autocomplete="off">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="password">
        <?= ui_input('current_password', 'Current password', '', ['type' => 'password'], true) ?>
        <?= ui_input('new_password', 'New password', '', ['type' => 'password', 'hint' => 'At least 8 characters, with a letter and a number.'], true) ?>
        <?= ui_input('confirm_password', 'Confirm new password', '', ['type' => 'password'], true) ?>
        <div class="form-actions">
          <button class="btn" type="submit"><?= icon('lock', 16) ?><span>Update password</span></button>
        </div>
      </form>
    </section>
  </div>

  <section class="card">
    <div class="card-head"><h3>Edit profile</h3></div>
    <form method="post" enctype="multipart/form-data">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="profile">
      <?= ui_input('full_name', 'Full name', (string) $user['full_name'], [], true) ?>
      <?= ui_input('phone', 'Contact number', (string) $user['phone'], ['type' => 'tel']) ?>

      <label class="field">
        <span class="field-label">Profile picture</span>
        <input type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp">
        <span class="field-hint">JPG, PNG, GIF or WEBP · up to <?= (int) PROFILE_MAX_MB ?> MB.</span>
      </label>

      <?php if ($profile !== null): ?>
        <fieldset>
          <legend>Academic record (managed by the registrar)</legend>
          <?= ui_input('student_id', 'Student ID', (string) $profile['student_id'], ['readonly' => 'readonly']) ?>
          <?= ui_input('course', 'Course', (string) $profile['course'], ['readonly' => 'readonly']) ?>
          <p class="hint">These fields come from the official student record and cannot be edited here.</p>
        </fieldset>
      <?php endif; ?>

      <div class="form-actions">
        <button class="btn" type="submit"><?= icon('check', 16) ?><span>Save profile</span></button>
        <a class="btn grey" href="<?= Helpers::e(Helpers::url('logout.php')) ?>" onclick="return confirm('Sign out of this device?')">
          <?= icon('logout', 16) ?><span>Sign out</span>
        </a>
      </div>
    </form>
  </section>
</div>

<?php require __DIR__ . '/includes/layout/footer.php'; ?>
