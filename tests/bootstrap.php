<?php
/**
 * PHPUnit bootstrap file for MySQL tests.
 *
 * @package Relevanssi_Light
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Load the plugin before the test suite starts.
 */
tests_add_filter( 'muplugins_loaded', function() {
	require dirname( __DIR__ ) . '/relevanssi-light.php';
} );

require_once $_tests_dir . '/includes/bootstrap.php';
