<?php
/**
 * register.php — public student account registration.
 *
 * Creates the student record together with an active student sign-in account
 * (StudentRepo::create). Only visitors who are not signed in may open it; the
 * page renders inside the same public shell as login.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (Auth::check()) {
    Helpers::redirect(Permissions::homeForRole());
}

$error = '';
$old   = [
    'first_name'     => '',
    'last_name'      => '',
    'student_id'     => '',
    'email'          => '',
    'course'         => '',
    'year_level'     => '',
    'section'        => '',
    'department_id'  => '',
    'contact_number' => '',
];

if (Helpers::isPost()) {
    Security::requireCsrf();

    foreach (array_keys($old) as $key) {
        $old[$key] = (string) (Helpers::post($key) ?? '');
    }
    $password = (string) (Helpers::post('password') ?? '');
    $confirm  = (string) (Helpers::post('confirm_password') ?? '');

    try {
        if ($old['first_name'] === '' || $old['last_name'] === '') {
            throw new RuntimeException('Please enter your first and last name.');
        }
        if (preg_match('/^[0-9]{4}-[0-9]{5}$/', $old['student_id']) !== 1) {
            throw new RuntimeException('Student ID must look like 2024-00412.');
        }
        if (!Security::validEmail($old['email'])) {
            throw new RuntimeException('Please enter a valid e-mail address.');
        }
        if ($old['course'] === '' || $old['year_level'] === '' || $old['section'] === '') {
            throw new RuntimeException('Course, year level and section are required.');
        }
        if ($old['contact_number'] !== '' && preg_match('/^[0-9+\-\s()]{7,20}$/', $old['contact_number']) !== 1) {
            throw new RuntimeException('That contact number does not look valid.');
        }
        if (StudentRepo::findByStudentId($old['student_id']) !== null) {
            throw new RuntimeException('Student ID ' . $old['student_id'] . ' is already registered.');
        }
        foreach (UserRepo::list(['q' => $old['email']], 1, 10)['rows'] as $existing) {
            if (mb_strtolower((string) $existing['email']) === mb_strtolower($old['email'])) {
                throw new RuntimeException('That e-mail address is already registered.');
            }
        }
        $problem = Security::passwordProblem($password);
        if ($problem !== null) {
            throw new RuntimeException($problem);
        }
        if ($password !== $confirm) {
            throw new RuntimeException('The two passwords do not match.');
        }

        $studentId = StudentRepo::create([
            'first_name'     => $old['first_name'],
            'last_name'      => $old['last_name'],
            'student_id'     => $old['student_id'],
            'email'          => $old['email'],
            'course'         => $old['course'],
            'year_level'     => $old['year_level'],
            'section'        => $old['section'],
            'department_id'  => $old['department_id'],
            'contact_number' => $old['contact_number'],
        ], $password);

        Security::audit('SELF_REGISTERED', 'students',
            'Student self-registered account ' . strtoupper($old['student_id']), $studentId);
        Helpers::flash('success', 'Your student account has been created. You can now sign in.');
        Helpers::redirect('login.php');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$flashes     = Helpers::takeFlashes();
$departments = AcademicRepo::departmentOptions();
$yearLevels  = ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year', 'Graduate'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Registration · <?= Helpers::e(APP_NAME) ?></title>
  <meta name="description" content="Register as a student to participate in campus organizations and events.">
  <link rel="icon" type="image/png" href="<?= Helpers::e(Helpers::url('assets/img/logo.png')) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="<?= Helpers::e(Helpers::url('assets/css/style.css')) ?>">
  <style>
    .form-section-title {
      font-size: var(--fs-xs);
      font-weight: 700;
      letter-spacing: .08em;
      text-transform: uppercase;
      color: var(--brand-700);
      margin: var(--sp-4) 0 var(--sp-3);
      padding-bottom: 4px;
      border-bottom: 1px solid var(--line-soft);
      display: flex;
      align-items: center;
      gap: 6px;
    }
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
    @media (max-width: 600px) {
      .auth-card.auth-card-wide { padding: var(--sp-6) var(--sp-5) var(--sp-5); }
    }
  </style>
</head>
<body class="auth-page">

  <!-- ─── Top navigation bar ─────────────────────────────────── -->
  <header class="auth-head">
    <a class="brand" href="<?= Helpers::e(Helpers::url('index.php')) ?>">
      <img class="brand-logo" src="<?= Helpers::e(Helpers::url('assets/img/logo.png')) ?>" alt="ZDSPGC seal" width="42" height="42">
      <span class="brand-text">
        <span class="name">ZDSPGC Organization System</span>
        <span class="sub">Student organizations · <?= Helpers::e(SCHOOL_CAMPUS) ?></span>
      </span>
    </a>
    <a class="btn ghost sm" href="<?= Helpers::e(Helpers::url('login.php')) ?>"><?= icon('logout', 15) ?><span>Sign in</span></a>
  </header>

  <!-- ─── Main card ──────────────────────────────────────────── -->
  <main class="auth-main">
    <div class="auth-card auth-card-wide">

      <div class="auth-card-header">
        <img class="auth-card-logo"
             src="<?= Helpers::e(Helpers::url('assets/img/logo.png')) ?>"
             alt="ZDSPGC official seal"
             width="64" height="64">
        <span class="auth-card-badge">Student Portal</span>
        <h1 style="font-size: var(--fs-xl); margin-bottom: 4px;">Student Account Registration</h1>
        <p class="muted small" style="margin-bottom: 0;">Create your account to join organizations, register for events, and track attendance</p>
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

      <form method="post" action="<?= Helpers::e(Helpers::url('register.php')) ?>" autocomplete="on">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="register">

        <div class="form-section-title">
          <?= icon('user', 14) ?><span>Personal Identification</span>
        </div>

        <div class="grid cols-2">
          <?= ui_input('first_name', 'First Name', $old['first_name'], ['placeholder' => 'e.g. Juan'], true) ?>
          <?= ui_input('last_name', 'Last Name', $old['last_name'], ['placeholder' => 'e.g. Dela Cruz'], true) ?>
        </div>

        <div class="grid cols-2">
          <?= ui_input('student_id', 'Student ID', $old['student_id'], ['placeholder' => '2024-00412', 'hint' => 'Format: YYYY-NNNNN'], true) ?>
          <?= ui_input('email', 'Institutional / Personal E-mail', $old['email'], ['type' => 'email', 'placeholder' => 'juan@zdspgc.edu.ph', 'hint' => 'Used for account sign-in & notifications'], true) ?>
        </div>

        <div class="form-section-title">
          <?= icon('award', 14) ?><span>Academic Enrollment</span>
        </div>

        <div class="grid cols-2">
          <?= ui_input('course', 'Course / Academic Program', $old['course'], ['placeholder' => 'BS Information Technology'], true) ?>
          <?= ui_select('year_level', 'Year Level', array_combine($yearLevels, $yearLevels), $old['year_level'], ['placeholder' => 'Select year level'], true) ?>
        </div>

        <div class="grid cols-2">
          <?= ui_input('section', 'Section', $old['section'], ['placeholder' => 'e.g. BSIT-3A'], true) ?>
          <?= ui_select('department_id', 'College Department', $departments, $old['department_id'], ['placeholder' => 'Select department']) ?>
        </div>

        <?= ui_input('contact_number', 'Mobile Contact Number', $old['contact_number'], ['type' => 'tel', 'placeholder' => '0919-000-0000']) ?>

        <div class="form-section-title">
          <?= icon('lock', 14) ?><span>Account Security</span>
        </div>

        <div class="grid cols-2">
          <?= ui_input('password', 'Create Password', '', ['type' => 'password', 'hint' => 'At least 8 chars, letter + number'], true) ?>
          <?= ui_input('confirm_password', 'Confirm Password', '', ['type' => 'password'], true) ?>
        </div>

        <button class="btn block" type="submit" style="margin-top: var(--sp-4);">
          <?= icon('check-circle', 17) ?><span>Complete Student Registration</span>
        </button>
      </form>

      <div class="auth-register-box">
        <span>Already have a student account?</span>
        <a href="<?= Helpers::e(Helpers::url('login.php')) ?>">Sign in here</a>
      </div>

    </div>
  </main>

  <!-- ─── Footer ─────────────────────────────────────────────── -->
  <footer class="auth-foot">
    © <?= date('Y') ?> <?= Helpers::e(SCHOOL_NAME) ?> · <?= Helpers::e(SCHOOL_CAMPUS) ?> · v<?= Helpers::e(APP_VERSION) ?>
  </footer>

  <script src="<?= Helpers::e(Helpers::url('assets/js/app.js')) ?>" defer></script>
</body>
</html>
