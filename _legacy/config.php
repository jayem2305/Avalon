<?php
// Database settings. The defaults match a stock XAMPP install
// (MySQL on localhost, user "root", no password).
// The database and table are created automatically on first request.
//
// Set DB_DRIVER to 'sqlite' (or the AVALON_DB env var) to run without MySQL;
// data is then stored in data/avalon.sqlite.

define('DB_DRIVER', getenv('AVALON_DB') ?: 'mysql');

define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'avalon');
define('DB_USER', 'root');
define('DB_PASS', '');

define('SQLITE_PATH', __DIR__ . '/data/avalon.sqlite');

// Rooms untouched for this many seconds are deleted.
define('ROOM_TTL', 60 * 60 * 24);
