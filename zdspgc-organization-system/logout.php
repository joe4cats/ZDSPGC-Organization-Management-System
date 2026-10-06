<?php
/**
 * logout.php — POST-only sign-out (CSRF protected).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (!Helpers::isPost()) {
    Helpers::flash('warning', 'Use the sign-out button to end your session.');
    Helpers::redirect(Auth::check() ? Permissions::homeForRole() : 'login.php');
}

Security::requireCsrf();
Auth::logout();
Helpers::flash('info', 'You have been signed out.');
Helpers::redirect('login.php');
