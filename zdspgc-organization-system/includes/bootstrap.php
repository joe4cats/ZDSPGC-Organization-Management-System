<?php
/**
 * bootstrap.php — the single entry point every page includes first.
 *
 * Loads configuration + core classes, starts the session with safe cookie
 * flags, applies security headers, checks that the database is installed and
 * restores a "remember me" sign-in when a valid cookie is present.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

date_default_timezone_set(APP_TIMEZONE);
mb_internal_encoding('UTF-8');
setlocale(LC_TIME, 'en_US.UTF-8');

if (APP_ENV === 'local') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
}

require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/Audit.php';
require_once __DIR__ . '/Permissions.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Schema.php';
require_once __DIR__ . '/Notifications.php';
require_once __DIR__ . '/Uploads.php';
require_once __DIR__ . '/Qr.php';
require_once __DIR__ . '/Chart.php';
require_once __DIR__ . '/layout/ui.php';

/* Data-access layer: one repository class per module. */
foreach (['AcademicRepo', 'OrgRepo', 'StudentRepo', 'MemberRepo', 'OfficerRepo', 'EventRepo', 'AttendanceRepo',
          'ProjectRepo', 'ProposalRepo', 'DocumentRepo', 'AnnouncementRepo', 'UserRepo'] as $__repo) {
    require_once __DIR__ . '/models/' . $__repo . '.php';
}
unset($__repo);

/* -------------------------------------------------------------------------
 * Session (HttpOnly + SameSite; Secure is added automatically on HTTPS)
 * ---------------------------------------------------------------------- */
if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

/* Baseline security headers. */
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
}

/* -------------------------------------------------------------------------
 * Database installed? Redirect to the installer on a fresh checkout.
 * ---------------------------------------------------------------------- */
$__installed = Schema::hasTables();
if (!$__installed && !defined('ZDSPGC_SKIP_INSTALL_CHECK')) {
    if (defined('ZDSPGC_API_MODE')) {
        Helpers::jsonOut([
            'success' => false,
            'code'    => 'not_installed',
            'message' => 'The database is not installed yet. Open install.php in your browser.',
        ], 503);
    }
    if (Helpers::currentPage() !== 'install.php') {
        Helpers::redirect('install.php');
    }
} elseif ($__installed) {
    Schema::ensureColumns();
}

/* -------------------------------------------------------------------------
 * Remember-me + session hygiene
 * ---------------------------------------------------------------------- */
if ($__installed) {
    try {
        Auth::resumeFromCookie();
    } catch (Throwable $e) {
        // A broken remember-me token must never block the page.
    }
}
Auth::touch();
