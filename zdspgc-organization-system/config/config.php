<?php
/**
 * =============================================================================
 *  ZDSPGC Organization Management System — Application configuration
 *  config/config.php
 * =============================================================================
 *  Values here describe the application itself (branding, security, uploads).
 *  Database credentials live in config/database.php.
 *
 *  Nothing in this file needs to be edited for a normal XAMPP install except
 *  APP_SECRET before going live.
 * =============================================================================
 */

declare(strict_types=1);

/** Absolute filesystem path of the project root (…/zdspgc-organization-system). */
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

/* -----------------------------------------------------------------------------
 * 1. Application identity / branding
 * -------------------------------------------------------------------------- */
if (!defined('APP_NAME'))    define('APP_NAME', 'ZDSPGC Organization Management System');
if (!defined('APP_SHORT'))   define('APP_SHORT', 'ZDSPGC OrgSys');
if (!defined('SCHOOL_NAME')) define('SCHOOL_NAME', 'Zamboanga del Sur Provincial Government College');
if (!defined('SCHOOL_CAMPUS')) define('SCHOOL_CAMPUS', 'Vicenzo Sagun, Zamboanga del Sur');
if (!defined('APP_VERSION')) define('APP_VERSION', '1.0.0');

/** Public address used in QR links / e-mails. No trailing slash. Empty = auto. */
if (!defined('PUBLIC_BASE_URL')) define('PUBLIC_BASE_URL', '');

/** 'local' shows PHP errors on screen. Switch to 'production' on a live server. */
if (!defined('APP_ENV'))     define('APP_ENV', 'local');

/** Asia/Manila — every date/time in the system is stored in this zone. */
if (!defined('APP_TIMEZONE')) define('APP_TIMEZONE', 'Asia/Manila');

/**
 * HMAC secret used for signed QR attendance tokens and remember-me validators.
 * CHANGE THIS to a long random string before deploying to a real server.
 * Rotating it instantly voids every printed/issued attendance QR code.
 */
if (!defined('APP_SECRET'))  define('APP_SECRET', 'zdspgc-orgsys-change-me-7c41b0f2e9a835d6c14b9302');

/* -----------------------------------------------------------------------------
 * 2. Sessions & authentication
 * -------------------------------------------------------------------------- */
if (!defined('SESSION_NAME'))           define('SESSION_NAME', 'ZDSPGCORGSYS');
if (!defined('SESSION_IDLE_MINUTES'))   define('SESSION_IDLE_MINUTES', 60);
if (!defined('LOGIN_MAX_ATTEMPTS'))     define('LOGIN_MAX_ATTEMPTS', 5);
if (!defined('LOGIN_LOCKOUT_MINUTES'))  define('LOGIN_LOCKOUT_MINUTES', 5);
/** "Remember me" cookie lifetime (days) when the user ticks the box. */
if (!defined('REMEMBER_DAYS'))          define('REMEMBER_DAYS', 30);

/* -----------------------------------------------------------------------------
 * 3. Demo / pilot mode
 * -------------------------------------------------------------------------- */
if (!defined('DEMO_MODE')) define('DEMO_MODE', false);


/* -----------------------------------------------------------------------------
 * 3. Uploads
 * -------------------------------------------------------------------------- */
if (!defined('UPLOAD_MAX_MB'))       define('UPLOAD_MAX_MB', 8);
if (!defined('UPLOAD_MAX_BYTES'))    define('UPLOAD_MAX_BYTES', UPLOAD_MAX_MB * 1024 * 1024);
if (!defined('PROFILE_MAX_MB'))      define('PROFILE_MAX_MB', 2);
/** Allowed document extensions => MIME types that must match (fileinfo checked). */
if (!defined('ALLOWED_UPLOADS')) {
    define('ALLOWED_UPLOADS', [
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls'  => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'csv'  => ['text/plain', 'text/csv', 'application/csv', 'application/octet-stream'],
    ]);
}
/** Allowed profile picture / organization logo extensions. */
if (!defined('ALLOWED_IMAGES')) {
    define('ALLOWED_IMAGES', [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
    ]);
}
if (!defined('UPLOAD_DIR')) define('UPLOAD_DIR', APP_ROOT . '/uploads');

/* -----------------------------------------------------------------------------
 * 4. Attendance / QR rules
 * -------------------------------------------------------------------------- */
/** Minutes after the event start that still count as "Present" instead of "Late". */
if (!defined('DEFAULT_GRACE_MINUTES')) define('DEFAULT_GRACE_MINUTES', 15);
/** How early a student may scan the event QR before the scheduled start. */
if (!defined('EARLY_CHECKIN_MINUTES')) define('EARLY_CHECKIN_MINUTES', 60);
/** How late (hours after the event end) a scan is still accepted. */
if (!defined('LATE_CHECKIN_HOURS'))    define('LATE_CHECKIN_HOURS', 6);
/** Max check-in API calls per minute per session (accident/bot protection). */
if (!defined('CHECKIN_RATE_PER_MIN'))  define('CHECKIN_RATE_PER_MIN', 300);

/**
 * Optional integration with an external ZDSPGC QR attendance deployment.
 * When true the organization system also accepts the exact signed tokens
 * produced by the standalone "ZDSPGC Event QR Attendance" system
 * (payload: version|E|event_code|nonce signed with the same APP_SECRET).
 * Keep both configs' APP_SECRET identical to enable the bridge.
 */
if (!defined('INTEGRATE_ATTENDANCE_SHARE')) define('INTEGRATE_ATTENDANCE_SHARE', true);

/* -----------------------------------------------------------------------------
 * 5. Local credential overrides (machine specific — never committed to git).
 *    Copy config/database.local.example.php to config/database.local.php when
 *    the database password/port differ from the defaults. Keep this block last.
 * -------------------------------------------------------------------------- */
if (is_file(__DIR__ . '/database.local.php')) {
    require_once __DIR__ . '/database.local.php';
}
