<?php
define( 'DB_NAME',   'dummy' );
define( 'DB_USER',   'dummy' );
define( 'DB_PASSWORD', 'dummy' );
define( 'DB_HOST',   'localhost' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );

// SQLite: store DB file in temp dir for throwaway test runs
define( 'DB_DIR', sys_get_temp_dir() . '/relevanssi-light-sqlite-test' );
define( 'DB_FILE', '.ht.sqlite' );
