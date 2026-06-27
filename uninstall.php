<?php
/**
 * /uninstall.php
 *
 * @package Relevanssi Light
 * @author  Mikko Saari
 * @license https://wordpress.org/about/gpl/ GNU General Public License
 * @see     https://www.relevanssi.com/light/
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

global $wpdb;

if ( function_exists( 'is_multisite' ) && is_multisite() ) {
	$blogids    = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );
	$old_blogid = $wpdb->blogid;
	foreach ( $blogids as $uninstall_blog_id ) {
		switch_to_blog( $uninstall_blog_id );
		relevanssi_light_uninstall();
		restore_current_blog();
	}
} else {
	relevanssi_light_uninstall();
}

/**
 * Removes Relevanssi Light features from the database tables and options.
 *
 * For MySQL: Removes the relevanssi_light_data column, the
 * relevanssi_light_fulltext index, and the relevanssi_light option.
 *
 * For SQLite: Drops the wp_relevanssi_light_fts FTS5 virtual table
 * and the relevanssi_light option.
 */
function relevanssi_light_uninstall() {
	global $wpdb;

	if ( function_exists( 'relevanssi_light_is_sqlite' ) && relevanssi_light_is_sqlite() ) {
		// SQLite path: drop the FTS5 virtual table.
		$fts_table = $wpdb->prefix . 'relevanssi_light_fts';
		$sql       = "DROP TABLE IF EXISTS $fts_table";
		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery
	} else {
		// MySQL path: drop column and fulltext index.
		$sql = "ALTER TABLE $wpdb->posts DROP COLUMN `relevanssi_light_data`";
		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery

		$sql = "ALTER TABLE $wpdb->posts DROP INDEX `relevanssi_light_fulltext`";
		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery
	}

	delete_option( 'relevanssi_light' );
	delete_option( 'relevanssi_light_activated' );
}
