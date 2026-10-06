<?php
/**
 * forgot-password.php - public password reset, request and confirmation.
 *
 * Step 1 (no token): the visitor requests a reset by e-mail and always sees the
 * same confirmation, whether or not an account matches (no e-mail enumeration).
 * No password-reset helper exists in includes/ and pages may not write SQL, so
 * the raw token lives only in this browser session (stored hashed with sha256,
 * 1 hour expiry, single use) and is printed in the flash message solely when
 * APP_ENV is "local"; otherwise the visitor is asked to contact the
 * administrator.
 * Step 2 (?token=...): chooses a new password, which UserRepo::updateUser()
 * rotates before the link is marked as used.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$error    = '';
$oldEmail = (string) (Helpers::post('email') ?? '');
$token    = (string) (Helpers::post('token') ?? '') !== ''
    ? (string) Helpers::post('token')
    : (string) (Helpers::get('token') ?? '');

if (Helpers::isPost()) {
    Security::requireCsrf();
    $action = (string) (Helpers::post('action') ?? '');

    try {
        if ($action === 'request') {
            $email = (string) (Helpers::post('email') ?? '');
            $limit = Security::rateLimit('password_reset', 5, 300);

            if (!$limit['allowed']) {
                $error = 'Too many reset requests. Please wait ' . (int) ceil($limit['retry_after'] / 60) . ' minute(s) and try again.';
            } elseif (!Security::validEmail($email)) {
                $error = 'Please enter a valid e-mail address.';
            } else {
                $match = null;
                foreach (UserRepo::list(['q' => $email], 1, 10)['rows'] as $row) {
                    if (mb_strtolower((string) $row['email']) === mb_strtolower($email)) {
                        $match = $row;
                        break;
                    }
                }

                Security::audit('PASSWORD_RESET_REQUESTED', 'auth',
                    'Password reset requested for ' . $email,
                    $match !== null ? (int) $match['id'] : null);

                Helpers::flash('success', 'If an account exists for that e-mail address, a password reset has '
                    . 'been requested. Follow the next step to choose a new password.');

                if (APP_ENV !== 'local') {
                    Helpers::flash('info', 'Reset links are handled by the system administrator. Please contact '
                        . 'the Office of Student Affairs to continue with the password change.');
                } elseif ($match !== null) {
                    $raw = bin2hex(random_bytes(24));
                    $_SESSION['password_reset'] = [
                        'user_id'    => (int) $match['id'],
                        'token_hash' => hash('sha256', $raw),
                        'expires_at' => time() + 3600,
                        'ip_address' => Security::ip(),
                        'created_at' => Helpers::now(),
                        'used_at'    => null,
                    ];
                    Helpers::flash('info', 'Local development mode - open this link to choose a new password: '
                        . Helpers::url('forgot-password.php?token=' . $raw));
                }

                Helpers::redirect('forgot-password.php');
            }
        } elseif ($action === 'save') {
            $password = (string) (Helpers::post('password') ?? '');
            $confirm  = (string) (Helpers::post('confirm_password') ?? '');
            $stored   = $_SESSION['password_reset'] ?? null;

            if (!is_array($stored) || $token === ''
                || !hash_equals((string) ($stored['token_hash'] ?? ''), hash('sha256', $token))) {
                $error = 'This reset link is not valid. Please request a new one.';
            } elseif ((int) ($stored['expires_at'] ?? 0) < time()) {
                $error = 'This reset link has expired. Please request a new one.';
            } elseif (!empty($stored['used_at'])) {
                $error = 'This reset link was already used. Please request a new one.';
            } else {
                $problem = Security::passwordProblem($password);
                if ($problem !== null) {
                    $error = $problem;
                } elseif ($password !== $confirm) {
                    $error = 'The two passwords do not match.';
                } else {
                    $userId = (int) ($stored['user_id'] ?? 0);
                    UserRepo::updateUser($userId, [], $password);
                    unset($_SESSION['password_reset']);
                    Security::audit('PASSWORD_RESET_COMPLETED', 'auth',
                        'Password changed through a reset link', $userId);
                    Helpers::flash('success', 'Your password has been changed. You can now sign in.');
                    Helpers::redirect('login.php');
                }
            }
        } else {
            $error = 'This form has expired. Please start again.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$stored = $_SESSION['password_reset'] ?? null;
$tokenValid = $token !== ''
    && is_array($stored)
    && hash_equals((string) ($stored['token_hash'] ?? ''), hash('sha256', $token))
    && (int) ($stored['expires_at'] ?? 0) >= time()
    && empty($stored['used_at']);

$flashes = Helpers::takeFlashes();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forgot password · <?= Helpers::e(APP_NAME) ?></title>
  <link rel="icon" type="image/png" href="<?= Helpers::e(Helpers::url('assets/img/logo.png')) ?>">
  <link rel="stylesheet" href="<?= Helpers::e(Helpers::url('assets/css/style.css')) ?>">
</head>
<body class="auth-page">
  <div class="auth-head">
    <a class="brand" href="<?= Helpers::e(Helpers::url('index.php')) ?>">
      <img class="brand-logo" src="<?= Helpers::e(Helpers::url('assets/img/logo.png')) ?>" alt="ZDSPGC seal" width="44" height="44">
      <span class="brand-text">
        <span class="name">ZDSPGC Organization System</span>
        <span class="sub">Student organizations · <?= Helpers::e(SCHOOL_CAMPUS) ?></span>
      </span>
    </a>
    <a class="btn ghost sm" href="<?= Helpers::e(Helpers::url('login.php')) ?>"><?= icon('logout', 15) ?><span>Sign in</span></a>
  </div>

  <main class="auth-main">
    <div class="auth-card">
      <?php foreach ($flashes as $flash): ?>
        <div class="flash flash-<?= Helpers::e((string) $flash['type']) ?>">
          <?= icon((string) $flash['type'] === 'error' ? 'alert' : 'info', 17) ?>
          <span><?= Helpers::e((string) $flash['message']) ?></span>
        </div>
      <?php endforeach; ?>

      <?php if ($error !== ''): ?>
        <div class="flash flash-error">
          <?= icon('alert', 17) ?><span><?= Helpers::e($error) ?></span>
        </div>
      <?php endif; ?>

      <?php if ($token !== '' && !$tokenValid): ?>
        <h1>Reset link</h1>
        <div class="flash flash-error">
          <?= icon('alert', 17) ?>
          <span>This reset link is no longer valid or has expired.</span>
        </div>
        <p class="small mt">
          <a href="<?= Helpers::e(Helpers::url('forgot-password.php')) ?>">Request a new reset link</a>
          <span class="muted"> · </span>
          <a href="<?= Helpers::e(Helpers::url('login.php')) ?>">Back to sign in</a>
        </p>
      <?php elseif ($token !== ''): ?>
        <h1>Choose a new password</h1>
        <p class="muted small">Enter a new password for your account. The link can be used once.</p>

        <form method="post" action="<?= Helpers::e(Helpers::url('forgot-password.php?token=' . rawurlencode($token))) ?>" autocomplete="off">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="token" value="<?= Helpers::e($token) ?>">
          <?= ui_input('password', 'New password', '', ['type' => 'password', 'hint' => 'At least 8 characters, with a letter and a number.'], true) ?>
          <?= ui_input('confirm_password', 'Confirm new password', '', ['type' => 'password'], true) ?>
          <button class="btn block" type="submit"><?= icon('lock', 17) ?><span>Change my password</span></button>
        </form>

        <p class="small mt">
          <a href="<?= Helpers::e(Helpers::url('forgot-password.php')) ?>">Request a new link</a>
          <span class="muted"> · </span>
          <a href="<?= Helpers::e(Helpers::url('login.php')) ?>">Back to sign in</a>
        </p>
      <?php else: ?>
        <h1>Forgot your password?</h1>
        <p class="muted small">Enter the e-mail address of your account and we will prepare a reset link.</p>

        <form method="post" action="<?= Helpers::e(Helpers::url('forgot-password.php')) ?>" autocomplete="on">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="request">
          <?= ui_input('email', 'E-mail address', $oldEmail, ['type' => 'email'], true) ?>
          <button class="btn block" type="submit"><?= icon('mail', 17) ?><span>Send reset link</span></button>
        </form>

        <p class="small mt">
          <a href="<?= Helpers::e(Helpers::url('login.php')) ?>">Back to sign in</a>
          <span class="muted"> · </span>
          <a href="<?= Helpers::e(Helpers::url('register.php')) ?>">Register as a student</a>
        </p>
      <?php endif; ?>
    </div>
  </main>

  <div class="auth-foot">© <?= date('Y') ?> <?= Helpers::e(SCHOOL_NAME) ?> · v<?= Helpers::e(APP_VERSION) ?></div>
  <script src="<?= Helpers::e(Helpers::url('assets/js/app.js')) ?>" defer></script>
</body>
</html>
