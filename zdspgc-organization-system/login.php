<?php
/**
 * login.php — sign-in for every role.
 *
 * The identity field accepts an e-mail address, a username or a Student ID.
 * Success honours the page the user was trying to reach (session 'intended').
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (Auth::check()) {
    Helpers::redirect(Permissions::homeForRole());
}

$error = '';

if (Helpers::isPost()) {
    Security::requireCsrf();

    $identity = (string) (Helpers::post('identity') ?? '');
    $password = (string) (Helpers::post('password') ?? '');
    $remember = Helpers::inputBool('remember');

    if ($identity === '' || $password === '') {
        $error = 'Please enter your e-mail / Student ID and your password.';
    } else {
        $result = Auth::attempt($identity, $password, $remember);
        if ($result['ok']) {
            $intended = (string) ($_SESSION['intended'] ?? '');
            unset($_SESSION['intended']);
            Helpers::flash('success', $result['message']);
            Helpers::redirect($intended !== '' ? $intended : Permissions::homeForRole());
        }
        $error = $result['message'];
    }
}

$flashes = Helpers::takeFlashes();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign in · <?= Helpers::e(APP_NAME) ?></title>
  <meta name="description" content="Sign in to the ZDSPGC Organization Management System.">
  <link rel="icon" type="image/png" href="<?= Helpers::e(Helpers::url('assets/img/logo.png')) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="<?= Helpers::e(Helpers::url('assets/css/style.css')) ?>">
  <style>
    .auth-register-box {
      margin-top: var(--sp-5);
      padding-top: var(--sp-4);
      border-top: 1px solid var(--line-soft);
      text-align: center;
      font-size: var(--fs-sm);
      color: var(--ink-600);
    }
    .auth-register-box a {
      font-weight: 600;
      color: var(--brand-700);
      margin-left: 4px;
    }
    .auth-register-box a:hover {
      color: var(--brand-500);
      text-decoration: underline;
    }
    @media (max-width: 520px) {
      .auth-head { padding: var(--sp-4) var(--sp-4); }
      .auth-card { padding: var(--sp-6) var(--sp-5) var(--sp-5); }
    }
  </style>
</head>
<body class="auth-page">

  <!-- ─── Top navigation bar ─────────────────────────────────── -->
  <header class="auth-head">
    <a class="brand" href="<?= Helpers::e(Helpers::url('index.php')) ?>">
      <img class="brand-logo"
           src="<?= Helpers::e(Helpers::url('assets/img/logo.png')) ?>"
           alt="ZDSPGC seal"
           width="42" height="42">
      <span class="brand-text">
        <span class="name">ZDSPGC Organization System</span>
        <span class="sub">Student organizations · <?= Helpers::e(SCHOOL_CAMPUS) ?></span>
      </span>
    </a>
    <a class="btn ghost sm" href="<?= Helpers::e(Helpers::url('index.php')) ?>">
      <?= icon('external', 14) ?><span>Public directory</span>
    </a>
  </header>

  <!-- ─── Main card ──────────────────────────────────────────── -->
  <main class="auth-main">
    <div class="auth-card">

      <div class="auth-card-header">
        <img class="auth-card-logo"
             src="<?= Helpers::e(Helpers::url('assets/img/logo.png')) ?>"
             alt="ZDSPGC official seal"
             width="64" height="64">
        <span class="auth-card-badge">Portal Access</span>
        <h1 style="font-size: var(--fs-xl); margin-bottom: 4px;">Welcome Back</h1>
        <p class="muted small" style="margin-bottom: 0;">Sign in to your ZDSPGC institutional account</p>
      </div>

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

      <form id="login-form" method="post" action="<?= Helpers::e(Helpers::url('login.php')) ?>" autocomplete="on">
        <?= Security::csrfField() ?>

        <div class="field">
          <label class="field-label" for="identity">
            E-mail / Username / Student ID
          </label>
          <div class="input-with-icon">
            <span class="input-icon"><?= icon('user', 18) ?></span>
            <input
              id="identity"
              type="text"
              name="identity"
              placeholder="e.g. 2024-00412 or username"
              autocomplete="username"
              required
              autofocus>
          </div>
        </div>

        <div class="field">
          <label class="field-label" for="password">Password</label>
          <div class="input-with-icon pw-wrap">
            <span class="input-icon"><?= icon('lock', 18) ?></span>
            <input
              id="password"
              type="password"
              name="password"
              placeholder="Enter your password"
              autocomplete="current-password"
              required>
            <button
              type="button"
              class="pw-toggle"
              id="pw-toggle-btn"
              aria-label="Show / hide password"
              title="Toggle password visibility">
              <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                   fill="none" stroke="currentColor" stroke-width="2"
                   stroke-linecap="round" stroke-linejoin="round" id="pw-toggle-ico">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                <circle cx="12" cy="12" r="3"/>
              </svg>
            </button>
          </div>
        </div>

        <div class="auth-row-between">
          <label class="check">
            <input type="checkbox" name="remember" value="1">
            <span>Keep me signed in</span>
          </label>
          <a href="<?= Helpers::e(Helpers::url('forgot-password.php')) ?>" class="small">Forgot password?</a>
        </div>

        <button class="btn block" type="submit">
          <?= icon('logout', 17) ?><span>Sign in to Portal</span>
        </button>
      </form>

      <div class="auth-register-box">
        <span>Don't have an account yet?</span>
        <a href="<?= Helpers::e(Helpers::url('register.php')) ?>">Register as a student</a>
      </div>

    </div><!-- /.auth-card -->
  </main>

  <!-- ─── Footer ─────────────────────────────────────────────── -->
  <footer class="auth-foot">
    © <?= date('Y') ?> <?= Helpers::e(SCHOOL_NAME) ?> · <?= Helpers::e(SCHOOL_CAMPUS) ?> · v<?= Helpers::e(APP_VERSION) ?>
  </footer>

  <script src="<?= Helpers::e(Helpers::url('assets/js/app.js')) ?>" defer></script>
</body>
</html>
