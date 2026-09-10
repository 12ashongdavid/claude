<?php
// PHPUnit bootstrap. Points the app at a disposable test database and
// resets it to a clean, known state (setup.sql's schema + seed data)
// before every run, so tests never depend on whatever state a previous
// run - or a developer's real pk_ams database - happened to be in.

// These must be defined BEFORE config/database.php runs its own
// define() calls: define() is a no-op on a constant that already
// exists, so whatever we set here wins over the app's own defaults.
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'pk_ams_test');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

if (DB_NAME === 'pk_ams') {
    fwrite(STDERR, "Refusing to run tests against the real 'pk_ams' database - set DB_NAME to a disposable test database.\n");
    exit(1);
}

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec('DROP DATABASE IF EXISTS `' . DB_NAME . '`');
} catch (PDOException $e) {
    fwrite(STDERR, "Could not reach MySQL/MariaDB at " . DB_HOST . " to reset the test database: " . $e->getMessage() . "\n");
    exit(1);
}

require_once __DIR__ . '/../config/database.php';

// Trigger getDB()'s own auto-bootstrap (creates the DB fresh from
// setup.sql, including its seed data) so every test run starts identical.
getDB();
