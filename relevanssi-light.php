<?php
/**
 * Relevanssi Light
 *
 * /relevanssi-light.php
 *
 * @package Relevanssi Light
 * @author  Mikko Saari
 * @license https://wordpress.org/about/gpl/ GNU General Public License
 * @see     https://www.relevanssi.com/light/
 *
 * @wordpress-plugin
 * Plugin Name: Relevanssi Light (SQLite)
 * Plugin URI: https://www.relevanssi.com/light/
 * Description: Replaces the default WP search with a fulltext index search. Forked with SQLite FTS5 support.
 * Version: 1.3.0-sqlite
 * Author: Mikko Saari
 * Author URI: https://www.mikkosaari.fi/
 * Text Domain: relevanssilight
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

/*
	Copyright 2022 Mikko Saari  (email: mikko@mikkosaari.fi)

	This file is part of Relevanssi Light, a search plugin for WordPress.

	Relevanssi Light is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	Relevanssi Light is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with Relevanssi Light.  If not, see <http://www.gnu.org/licenses/>.
*/

require 'relevanssi-light-admin-ajax.php';
require 'relevanssi-light-menu.php';

add_action( 'init', 'relevanssi_light_init' );
add_action( 'admin_init', 'relevanssi_light_install' );
add_action( 'wp_insert_post', 'relevanssi_light_update_post_data' );
add_action( 'wp_ajax_relevanssi_light_database_alteration', 'relevanssi_light_database_alteration_action' );
add_action( 'wp_ajax_nopriv_relevanssi_light_database_alteration', 'relevanssi_light_database_alteration_action' );
add_action( 'wp_insert_site', 'relevanssi_light_new_blog', 10, 1 );

register_activation_hook( __FILE__, 'relevanssi_light_activate' );

/**
 * Adds the required filters.
 *
 * Includes a check for the DB version number. If the version number is too
 * low, won't add the filters. If the version number is good, filters are added
 * and no more checks for the version number are made in the future.
 */
function relevanssi_light_init() {
	$options = get_option(
		'relevanssi_light',
		array(
			'mysql_version_good' => false,
		)
	);

	if ( ! $options['mysql_version_good'] ) {
		if ( relevanssi_light_is_db_good() ) {
			$options['mysql_version_good'] = true;
			update_option( 'relevanssi_light', $options );
		}
	}

	if ( $options['mysql_version_good'] ) {
		add_filter( 'posts_search', 'relevanssi_light_posts_search', 10, 2 );
		add_filter( 'posts_search_orderby', 'relevanssi_light_posts_search_orderby', 10, 2 );
		add_filter( 'posts_request', 'relevanssi_light_posts_request', 10, 2 );
	}

}

/**
 * Checks whether the current database engine supports fulltext search.
 *
 * Returns true for MySQL >= 5.6, MariaDB >= 10.0.5, or SQLite with FTS5
 * enabled.
 *
 * @return boolean True if the database supports fulltext search, false otherwise.
 */
function relevanssi_light_is_db_good() {
	if ( relevanssi_light_is_sqlite() ) {
		return relevanssi_light_is_fts5_available();
	}
	return relevanssi_light_is_mysql_good();
}

/**
 * Checks whether the database engine is SQLite.
 *
 * @return boolean True if SQLite, false otherwise.
 */
function relevanssi_light_is_sqlite() {
	if ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) {
		return true;
	}
	global $wpdb;
	$server_info = '';
	if ( method_exists( $wpdb, 'db_server_info' ) ) {
		$server_info = $wpdb->db_server_info();
	}
	if ( $server_info && stripos( $server_info, 'sqlite' ) !== false ) {
		return true;
	}
	// Fallback: check the version string reported by SELECT VERSION().
	$db_version = $wpdb->get_var( 'SELECT VERSION()' );
	if ( $db_version && stripos( $db_version, 'sqlite' ) !== false ) {
		return true;
	}
	return false;
}

/**
 * Checks whether FTS5 is available in the SQLite engine.
 *
 * Attempts to create a temporary FTS5 virtual table using the raw PDO
 * connection (bypassing the WP SQLite compat driver, which does not
 * support CREATE VIRTUAL TABLE). If it succeeds, FTS5 is available.
 *
 * @return boolean True if FTS5 is compiled in, false otherwise.
 */
function relevanssi_light_is_fts5_available() {
	$pdo = relevanssi_light_get_pdo();
	if ( ! $pdo ) {
		return false;
	}
	try {
		$pdo->exec( 'CREATE VIRTUAL TABLE IF NOT EXISTS relevanssi_light_fts5_test USING fts5(content)' );
		$pdo->exec( 'DROP TABLE IF EXISTS relevanssi_light_fts5_test' );
		return true;
	} catch ( PDOException $e ) {
		return false;
	}
}

/**
 * Gets the raw PDO connection to the SQLite database.
 *
 * The WP SQLite Integration plugin stores the PDO instance in $GLOBALS['@pdo'].
 * We use this to bypass the MySQL-compat translator for FTS5 operations that
 * the translator does not understand (CREATE VIRTUAL TABLE, FTS5 MATCH, etc.)
 *
 * @return PDO|null The PDO connection, or null if unavailable.
 */
function relevanssi_light_get_pdo() {
	if ( isset( $GLOBALS['@pdo'] ) && $GLOBALS['@pdo'] instanceof PDO ) {
		return $GLOBALS['@pdo'];
	}
	return null;
}

/**
 * Checks whether the DB version is at least MySQL 5.6 or MariaDB 10.0.5.
 *
 * Fulltext indexing is not available for MySQL versions under 5.6. Not that you
 * should be using them for WordPress anyway...
 *
 * @return boolean True if version is at least 5.6, false otherwise.
 */
function relevanssi_light_is_mysql_good() {
	global $wpdb;
	$db_version = $wpdb->get_var( 'SELECT VERSION()' );
	if ( stripos( $db_version, 'mariadb' ) !== false ) {
		list( $version, ) = explode( '-', $db_version, 2 );
		if ( version_compare( $version, '10.0.5', '>=' ) ) {
			return true;
		}
	}
	if ( version_compare( $wpdb->db_version(), '5.6', '>=' ) ) {
		return true;
	}
	return false;
}

/**
 * Returns the FTS5 virtual table name (with WP prefix).
 *
 * @return string The FTS5 table name.
 */
function relevanssi_light_fts_table() {
	global $wpdb;
	return $wpdb->prefix . 'relevanssi_light_fts';
}

/**
 * Sanitizes a search term for use in an FTS5 MATCH expression.
 *
 * Passes through FTS5's native query syntax with minimal sanitization
 * for security. FTS5 supports the following operators:
 *
 *   - `battery solar`    — implicit AND (both terms must match)
 *   - `battery AND solar`— explicit AND
 *   - `battery OR solar` — either term matches
 *   - `battery NOT solar`— first term but not the second
 *   - `solar NEAR energy`— terms within 10 tokens of each other
 *   - `"solar batteries"`— exact phrase match
 *   - `bat*`             — prefix match (starts with "bat")
 *   - `post_title:solar` — column-scoped match
 *   - `(a OR b) AND c`   — parenthesized grouping
 *
 * See: https://www.sqlite.org/fts5.html#full_text_query_syntax
 *
 * The only transformations applied are:
 *   1. Escaping of single quotes (to prevent SQL injection via the
 *      $wpdb->prepare wrapper that ultimately executes the query)
 *   2. Escaping of double quotes inside unquoted tokens (so a bare
 *      word can't close a phrase context)
 *
 * The `boolean_mode` parameter is accepted for API compatibility but
 * has no effect — FTS5 does not have separate "natural language" and
 * "boolean" modes like MySQL. All FTS5 operators are always available.
 *
 * @param string $term        The raw search term.
 * @param bool   $boolean_mode Ignored (FTS5 always allows operators).
 *
 * @return string The sanitized FTS5 MATCH expression, or empty string.
 */
function relevanssi_light_sanitize_fts_term( $term, $boolean_mode = false ) {
	// WordPress adds slashes to query variables (magic quotes), so
	// "solar batteries" arrives as \\"solar batteries\\". Strip them
	// first so FTS5 phrase quotes are preserved correctly.
	$term = wp_unslash( $term );
	$term = trim( $term );
	if ( '' === $term ) {
		return '';
	}

	// Escape single quotes to prevent SQL injection, since the FTS5
	// MATCH argument is interpolated into a SQL query string.
	// Double quotes are left as-is — they are FTS5 phrase delimiters
	// and should be passed through to the engine.
	$term = str_replace( "'", "''", $term );

	return $term;
}
function relevanssi_light_sanitize_mysql_term( $term ) {
	return addslashes( trim( $term ) );
}

/**
 * Queries the FTS5 table for matching post IDs using the raw PDO connection.
 *
 * Returns an associative array of [post_id => relevance_rank], sorted by
 * relevance (best first). On the FTS5 rank scale, lower values = better
 * matches.
 *
 * @param string $fts_term The sanitized FTS5 MATCH expression.
 *
 * @return array Array of post_id => rank, or empty array on failure.
 */
function relevanssi_light_fts_query( $fts_term ) {
	$pdo      = relevanssi_light_get_pdo();
	if ( ! $pdo ) {
		return array();
	}
	$fts_table = relevanssi_light_fts_table();
	try {
		$stmt = $pdo->prepare(
			"SELECT post_id, rank FROM $fts_table WHERE $fts_table MATCH :term ORDER BY rank"
		);
		$stmt->execute( array( ':term' => $fts_term ) );
		$results = array();
		while ( $row = $stmt->fetch( PDO::FETCH_ASSOC ) ) {
			$results[ $row['post_id'] ] = $row['rank'];
		}
		return $results;
	} catch ( PDOException $e ) {
		return array();
	}
}

/**
 * Adds an option that is later checked in an admin_init action in order to run
 * this just once per activation (apparently it's impossible to launch an AJAX
 * action directly from this activation hook).
 */
function relevanssi_light_activate() {
	add_option( 'relevanssi_light_activated', 'yes' );
}

/**
 * Runs the relevanssi_light_database_alteration_action() function through an
 * AJAX action to make it run as an async background action (because it takes
 * a long time to run).
 */
function relevanssi_light_install() {
	$plugin_active_here = false;
	if ( is_plugin_active_for_network( 'relevanssi-light/relevanssi-light.php' )
		&& 'done' !== get_option( 'relevanssi_light_activated' ) ) {
		$plugin_active_here = true;
	}
	if ( is_admin() && 'yes' === get_option( 'relevanssi_light_activated' ) ) {
		$plugin_active_here = true;
	}
	if ( $plugin_active_here ) {
		update_option( 'relevanssi_light_activated', 'done' );
		relevanssi_light_launch_ajax_action(
			'relevanssi_light_database_alteration'
		);
	}
}

/**
 * Installs Relevanssi Light on a new site.
 *
 * Hooks on to 'wp_insert_site' action hooks and runs the installation function
 * 'relevanssi_light_install' on the new site.
 *
 * @param object $site The new site object.
 */
function relevanssi_light_new_blog( $site ) {
	if ( is_plugin_active_for_network( 'relevanssi-light/relevanssi-light.php' ) ) {
		switch_to_blog( $site->id );
		relevanssi_light_install();
		restore_current_blog();
	}
}

/**
 * Makes the required changes to the database.
 *
 * For MySQL/MariaDB: Adds a longtext column `relevanssi_light_data` to the
 * `wp_posts` table and a fulltext index `relevanssi_light_fulltext` which
 * includes the `post_title`, `post_content`, `post_excerpt` and
 * `relevanssi_light_data` columns.
 *
 * For SQLite: Creates an FTS5 virtual table `wp_relevanssi_light_fts` that
 * stores a copy of the post title, content, excerpt and custom field data.
 *
 * @global object $wpdb The WP database interface.
 */
function relevanssi_light_alter_table() {
	global $wpdb;

	if ( relevanssi_light_is_sqlite() ) {
		relevanssi_light_create_fts_table();
		return;
	}

	// MySQL / MariaDB path.
	$column_exists = $wpdb->get_row( "SHOW COLUMNS FROM $wpdb->posts LIKE 'relevanssi_light_data'" );
	if ( ! $column_exists ) {
		$sql = "ALTER TABLE $wpdb->posts ADD COLUMN `relevanssi_light_data` LONGTEXT";
		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery
	}

	$index_exists = $wpdb->get_row( "SHOW index FROM $wpdb->posts where Column_name = 'relevanssi_light_data'" );
	if ( ! $index_exists ) {
		$sql = "ALTER TABLE $wpdb->posts ADD FULLTEXT `relevanssi_light_fulltext` (`post_title`, `post_content`, `post_excerpt`, `relevanssi_light_data` )";
		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery
	}
}

/**
 * Creates the FTS5 virtual table for SQLite fulltext search.
 *
 * Uses the raw PDO connection because the WP SQLite compat driver does not
 * support CREATE VIRTUAL TABLE statements.
 *
 * The virtual table stores a copy of post_title, post_content, post_excerpt,
 * and relevanssi_light_data (custom field content). The post_id is stored
 * but UNINDEXED (not tokenized) so it can be used as a join key back to
 * wp_posts.
 */
function relevanssi_light_create_fts_table() {
	$pdo = relevanssi_light_get_pdo();
	if ( ! $pdo ) {
		return;
	}
	$fts_table = relevanssi_light_fts_table();
	try {
		$pdo->exec(
			"CREATE VIRTUAL TABLE IF NOT EXISTS $fts_table USING fts5(
				post_id UNINDEXED,
				post_title,
				post_content,
				post_excerpt,
				relevanssi_light_data,
				tokenize = 'porter unicode61'
			)"
		);
	} catch ( PDOException $e ) {
		// Table creation failed — FTS5 may not be available.
	}
}

/**
 * Triggers the database alterations and checks the nonce.
 */
function relevanssi_light_database_alteration_action() {
	if ( ! wp_verify_nonce( $_REQUEST['_nonce'], 'relevanssi_light_database_alteration' ) ) {
		wp_send_json_error( 'Nonce check failed.', 403 );
	}

	relevanssi_light_alter_table();

	wp_send_json_success();
}

/**
 * Adds the fulltext search condition to the posts_search filter hook.
 *
 * For MySQL: Uses MATCH ... AGAINST in the WHERE clause.
 * For SQLite: Pre-computes matching post IDs via the raw PDO FTS5 query,
 * then injects an IN(...) clause into the WHERE.
 *
 * @param string   $search Search SQL for WHERE clause.
 * @param WP_Query $query  The current WP_Query object.
 *
 * @return string The modified SQL search query.
 */
function relevanssi_light_posts_search( $search, $query ) {
	$boolean_mode = apply_filters( 'relevanssi_light_boolean_mode', false );
	/**
	 * Sets the mode for the fulltext search. Defaults to NATURAL LANGUAGE.
	 *
	 * @param boolean If true, enables BOOLEAN MODE.
	 */
	if ( ! isset( $query->query['s'] ) || empty( $query->query['s'] ) ) {
		return $search;
	}

	if ( relevanssi_light_is_sqlite() ) {
		$fts_term = relevanssi_light_sanitize_fts_term( $query->query['s'], $boolean_mode );
		if ( '' === $fts_term ) {
			return $search;
		}

		$results = relevanssi_light_fts_query( $fts_term );
		// Stash results for posts_search_orderby to use (it runs after this).
		relevanssi_light_get_last_fts_results( $results );
		if ( empty( $results ) ) {
			// No matches — return a WHERE that matches nothing.
			return ' AND 1=0';
		}

		global $wpdb;
		$post_ids = array_map( 'intval', array_keys( $results ) );
		$id_list  = implode( ',', $post_ids );
		$search   = " AND {$wpdb->posts}.ID IN ($id_list)";
	} else {
		$mode     = $boolean_mode ? 'IN BOOLEAN MODE' : '';
		$term     = relevanssi_light_sanitize_mysql_term( $query->query['s'] );
		$search   = " AND MATCH(post_title,post_excerpt,post_content,relevanssi_light_data) AGAINST('" . $term . "' $mode)";
	}
	return $search;
}

/**
 * Adds the relevance orderby to the posts_search_orderby filter hook.
 *
 * For MySQL: Uses 'relevance DESC' (the MATCH...AGAINST column injected by
 * posts_request).
 * For SQLite: Uses FIELD() to order by FTS5 rank (pre-computed from the
 * raw PDO query, stored in a static variable).
 *
 * @param string   $orderby The ORDER BY clause.
 * @param WP_Query $query   The current WP_Query object.
 *
 * @return string The modified ORDER BY clause.
 */
function relevanssi_light_posts_search_orderby( $orderby, $query ) {
	if ( ! isset( $query->query['s'] ) || empty( $query->query['s'] ) ) {
		return $orderby;
	}

	if ( relevanssi_light_is_sqlite() ) {
		// Build a FIELD() expression that orders results by their FTS5 rank.
		// The post IDs and their ranking were computed in posts_search and
		// stored in a static variable.
		$results = relevanssi_light_get_last_fts_results();
		if ( empty( $results ) ) {
			return $orderby;
		}
		global $wpdb;
		// FTS5 rank: lower = better. FIELD() returns the 1-based position,
		// so we order ascending to get best (lowest rank) first.
		$post_ids = array_map( 'intval', array_keys( $results ) );
		$id_list  = implode( ',', $post_ids );
		$orderby  = " FIELD({$wpdb->posts}.ID, $id_list) ASC";
	} else {
		$orderby = 'relevance DESC';
	}
	return $orderby;
}

/**
 * Stores the last FTS5 query results for use in the orderby filter.
 *
 * The posts_search filter runs before posts_search_orderby, so we stash
 * the results here.
 *
 * @param array|null $results Array of post_id => rank, or null to get.
 *
 * @return array The stored results.
 */
function relevanssi_light_get_last_fts_results( $results = null ) {
	static $stored = array();
	if ( null !== $results ) {
		$stored = $results;
	}
	return $stored;
}

/**
 * Modifies the post query for fulltext search.
 *
 * For MySQL: Adds MATCH ... AGAINST as a relevance column for ORDER BY.
 * For SQLite: No query modification needed — the WHERE clause (posts_search)
 * and ORDER BY (posts_search_orderby) handle everything via pre-computed
 * FTS5 results from the raw PDO connection.
 *
 * @param string   $request The complete SQL query.
 * @param WP_Query $query   The current WP_Query object.
 *
 * @return string The modified SQL search query.
 */
function relevanssi_light_posts_request( $request, $query ) {
	$boolean_mode = apply_filters( 'relevanssi_light_boolean_mode', false );
	/**
	 * Sets the mode for the fulltext search. Defaults to NATURAL LANGUAGE.
	 *
	 * @param boolean If true, enables BOOLEAN MODE.
	 */
	if ( ! isset( $query->query['s'] ) || empty( $query->query['s'] ) ) {
		return $request;
	}

	if ( relevanssi_light_is_sqlite() ) {
		// No modification to the SQL request needed — the posts_search
		// filter already added the IN(...) WHERE clause and stashed the
		// FTS5 results, and posts_search_orderby adds the FIELD() ORDER BY.
		return $request;
	}

	// MySQL path: inject MATCH...AGAINST as a relevance column.
	$mode = $boolean_mode ? 'IN BOOLEAN MODE' : '';
	$term = relevanssi_light_sanitize_mysql_term( $query->query['s'] );
	$request = preg_replace(
		'/FROM/',
		", MATCH(post_title,post_excerpt,post_content,relevanssi_light_data) AGAINST('" . $term . "' $mode) AS relevance FROM",
		$request,
		1
	);
	return $request;
}

if ( ! function_exists( 'relevanssi_light_update_post_data' ) ) {
	/**
	 * Reads post data and custom field content and updates the fulltext index.
	 *
	 * For MySQL: Updates the relevanssi_light_data column on wp_posts (the
	 * fulltext index covers this plus post_title, post_content, post_excerpt
	 * natively).
	 *
	 * For SQLite: Rebuilds the entire FTS5 row (delete + reinsert via raw PDO)
	 * since the FTS5 virtual table is a copy that must include title, content,
	 * excerpt, AND custom field data.
	 *
	 * This is a pluggable function, so feel free to write your own. This
	 * function uses the relevanssi_light_custom_fields filter hook to adjust
	 * the custom fields chosen to be added to the field and thus to the index.
	 *
	 * @param int $post_id The post ID.
	 */
	function relevanssi_light_update_post_data( $post_id ) {
		global $wpdb;

		/**
		 * Filters an array of custom field names to include in the fulltext
		 * index.
		 *
		 * A small trick: if you want to include all custom fields, pass an
		 * empty string in the array, and nothing else.
		 *
		 * @param array An array of custom field names.
		 */
		$custom_fields = apply_filters( 'relevanssi_light_custom_fields', array() );

		// Gather custom field content.
		$extra_content = '';
		if ( ! empty( $custom_fields ) ) {
			$extra_content = array_reduce(
				$custom_fields,
				function ( $content, $field ) use ( $post_id ) {
					$values = get_post_meta( $post_id, $field, false );
					array_walk_recursive(
						$values,
						function ( $value ) use ( &$content ) {
							$content .= ' ' . $value;
						}
					);
					return $content;
				},
				''
			);
		}

		if ( relevanssi_light_is_sqlite() ) {
			// SQLite FTS5 path: rebuild the entire FTS row via raw PDO.
			$pdo = relevanssi_light_get_pdo();
			if ( ! $pdo ) {
				return;
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				return;
			}

			$fts_table = relevanssi_light_fts_table();
			try {
				// Delete existing FTS row for this post, then insert fresh.
				$stmt = $pdo->prepare( "DELETE FROM $fts_table WHERE post_id = :post_id" );
				$stmt->execute( array( ':post_id' => $post_id ) );

				$stmt = $pdo->prepare(
					"INSERT INTO $fts_table (post_id, post_title, post_content, post_excerpt, relevanssi_light_data)
					 VALUES (:post_id, :title, :content, :excerpt, :data)"
				);
				$stmt->execute( array(
					':post_id'  => $post_id,
					':title'    => $post->post_title,
					':content'  => $post->post_content,
					':excerpt'  => $post->post_excerpt,
					':data'     => $extra_content,
				) );
			} catch ( PDOException $e ) {
				// FTS5 operation failed.
			}
			return;
		}

		// MySQL path: update the relevanssi_light_data column on wp_posts.
		if ( empty( $custom_fields ) ) {
			$wpdb->update(
				$wpdb->posts,
				array( 'relevanssi_light_data' => '' ),
				array( 'ID' => $post_id ),
				array( '%s' ),
				array( '%d' )
			);
			return;
		}

		if ( $extra_content ) {
			$wpdb->update(
				$wpdb->posts,
				array( 'relevanssi_light_data' => $extra_content ),
				array( 'ID' => $post_id ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}
}

/**
 * Launches an asynchronous Ajax action.
 *
 * Makes a wp_remote_post() call with the specific action. Handles nonce
 * verification.
 *
 * @see wp_remote_post()
 * @see wp_create_nonce()
 *
 * @param string $action       The action to trigger (also the name of the
 * nonce).
 * @param array  $payload_args The parameters sent to the action. Defaults to
 * an empty array.
 *
 * @return WP_Error|array The wp_remote_post() response or WP_Error on failure.
 */
function relevanssi_light_launch_ajax_action( $action, $payload_args = array() ) {
	$cookies = array();
	foreach ( $_COOKIE as $name => $value ) {
		$cookies[] = "$name=" . rawurlencode(
			is_array( $value ) ? wp_json_encode( $value ) : $value
		);
	}
	$default_payload = array(
		'action' => $action,
		'_nonce' => wp_create_nonce( $action ),
	);
	$payload         = array_merge( $default_payload, $payload_args );
	$args            = array(
		'timeout'  => 1,
		'blocking' => false,
		'body'     => $payload,
		'headers'  => array(
			'cookie' => implode( '; ', $cookies ),
		),
	);
	$url             = admin_url( 'admin-ajax.php' );

	return wp_remote_post( $url, $args );
}
