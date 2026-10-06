<?php
/**
 * =============================================================================
 *  ZDSPGC Organization Management System — Database configuration
 *  config/database.php
 * =============================================================================
 *  Standard XAMPP defaults: host 127.0.0.1, port 3306, user "root", empty
 *  password. If your MySQL runs elsewhere (or needs a password) copy
 *  config/database.local.example.php to config/database.local.php and set the
 *  values there — that file is loaded automatically and is git-ignored.
 * =============================================================================
 */

declare(strict_types=1);

/** MySQL server host (XAMPP default: 127.0.0.1). */
if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');

/** MySQL port. XAMPP default 3306; a second instance often listens on 3307. */
if (!defined('DB_PORT')) define('DB_PORT', '3306');

/** Database name — created by install.php (or manually in phpMyAdmin). */
if (!defined('DB_NAME')) define('DB_NAME', 'zdspgc_orgsys');

/** Database user (XAMPP default: root). */
if (!defined('DB_USER')) define('DB_USER', 'root');

/** Database password (XAMPP default: empty). */
if (!defined('DB_PASS')) define('DB_PASS', '');
