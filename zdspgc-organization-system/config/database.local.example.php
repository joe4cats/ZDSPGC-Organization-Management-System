<?php
/**
 * config/database.local.example.php
 *
 * Copy this file to config/database.local.php and adjust the values when your
 * MySQL server does not use the XAMPP defaults (for example a password on the
 * root account, or a second MySQL instance listening on port 3307).
 *
 * config/database.local.php is git-ignored and loaded automatically by
 * config/config.php — never edit config/database.php directly on a server.
 */

declare(strict_types=1);

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3307');   // second XAMPP MySQL instance
define('DB_NAME', 'zdspgc_orgsys');
define('DB_USER', 'root');
define('DB_PASS', '');
