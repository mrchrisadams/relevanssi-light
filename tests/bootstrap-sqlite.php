<?php
/**
 * PHPUnit bootstrap file for SQLite tests.
 *
 * @package Relevanssi_Light
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

/**
 * Install the wordpress/sqlite-database-integration plugin and its db.php
 * drop-in into the WP test install's wp-content/ directory.
 *
 * The wordpress/sqlite-database-integration package is a monorepo whose
 * Composer package only ships a loader. The actual WordPress plugin must
 * be built (resolve the driver symlink + copy files) before it can be
 * used. This function performs that build step and copies the result
 * into the WP test install so WordPress auto-loads db.php.
 */
function _relevanssi_light_install_sqlite_dropin() {
	global $_tests_dir;

	$plugin_dir  = dirname( __DIR__ ) . '/vendor/wordpress/sqlite-database-integration';
	$wp_core_dir = rtrim( getenv( 'WP_CORE_DIR' ) ?: ( dirname( $_tests_dir ) . '/wordpress/' ), '/' );
	$wp_content  = $wp_core_dir . '/wp-content';
	$wp_plugins  = $wp_content . '/plugins';
	$dest_plugin = $wp_plugins . '/sqlite-database-integration';

	// If the drop-in is already installed, nothing to do.
	if ( file_exists( $wp_content . '/db.php' ) && file_exists( $dest_plugin . '/wp-includes/sqlite/db.php' ) ) {
		return;
	}

	if ( ! is_dir( $plugin_dir ) ) {
		return;
	}

	// Build the plugin: copy the plugin package, resolve the driver symlink.
	$build_src = $plugin_dir . '/packages/plugin-sqlite-database-integration';
	if ( ! is_dir( $build_src ) ) {
		return;
	}

	// Remove old install.
	if ( is_dir( $dest_plugin ) ) {
		_relevanssi_light_rrmdir( $dest_plugin );
	}

	// Copy the plugin package.
	_relevanssi_light_rcopy( $build_src, $dest_plugin );

	// Resolve the database symlink: replace wp-includes/database symlink with
	// a real copy of the driver source.
	$database_link = $dest_plugin . '/wp-includes/database';
	if ( is_link( $database_link ) || is_dir( $database_link ) ) {
		_relevanssi_light_rrmdir( $database_link );
	}
	_relevanssi_light_rcopy(
		$plugin_dir . '/packages/mysql-on-sqlite/src',
		$database_link
	);

	// Copy db.copy as db.php (the drop-in that WordPress auto-loads).
	copy( $dest_plugin . '/db.copy', $wp_content . '/db.php' );
}

/**
 * Recursively copy a directory.
 *
 * @param string $src Source directory.
 * @param string $dst Destination directory.
 */
function _relevanssi_light_rcopy( $src, $dst ) {
	if ( is_link( $src ) ) {
		$link_target = readlink( $src );
		// Resolve relative symlinks from the link's directory.
		if ( '/' !== $link_target[0] ) {
			$link_target = dirname( $src ) . '/' . $link_target;
		}
		$src = realpath( $link_target );
	}
	$dir = opendir( $src );
	if ( ! $dir ) {
		return;
	}
	if ( ! is_dir( $dst ) ) {
		mkdir( $dst, 0777, true );
	}
	while ( false !== ( $file = readdir( $dir ) ) ) {
		if ( '.' === $file || '..' === $file ) {
			continue;
		}
		$src_path = $src . '/' . $file;
		$dst_path = $dst . '/' . $file;
		if ( is_link( $src_path ) ) {
			$link_target = readlink( $src_path );
			if ( '/' !== $link_target[0] ) {
				$link_target = dirname( $src_path ) . '/' . $link_target;
			}
			$src_path = realpath( $link_target );
		}
		if ( is_dir( $src_path ) ) {
			_relevanssi_light_rcopy( $src_path, $dst_path );
		} else {
			copy( $src_path, $dst_path );
		}
	}
	closedir( $dir );
}

/**
 * Recursively remove a directory.
 *
 * @param string $dir Directory path.
 */
function _relevanssi_light_rrmdir( $dir ) {
	if ( is_link( $dir ) ) {
		unlink( $dir );
		return;
	}
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$files = scandir( $dir );
	foreach ( $files as $file ) {
		if ( '.' === $file || '..' === $file ) {
			continue;
		}
		$path = $dir . '/' . $file;
		if ( is_dir( $path ) ) {
			_relevanssi_light_rrmdir( $path );
		} else {
			unlink( $path );
		}
	}
	rmdir( $dir );
}

// Install the drop-in BEFORE WordPress boots.
_relevanssi_light_install_sqlite_dropin();

require_once $_tests_dir . '/includes/functions.php';

/**
 * Load the plugin before the test suite starts.
 */
tests_add_filter( 'muplugins_loaded', function() {
	require dirname( __DIR__ ) . '/relevanssi-light.php';
} );

require_once $_tests_dir . '/includes/bootstrap.php';
