<?php
/**
 * Behavioural tests for Relevanssi Light search.
 *
 * These tests run against both MySQL and SQLite backends. The backend is
 * determined by which bootstrap file is loaded (tests/bootstrap.php for MySQL,
 * tests/bootstrap-sqlite.php for SQLite).
 *
 * @package Relevanssi_Light
 */

/**
 * Tests for Relevanssi Light search behaviour.
 */
class Relevanssi_Light_Search_Test extends WP_UnitTestCase {

	/**
	 * Holds the IDs of posts created in setUp.
	 *
	 * @var array
	 */
	protected $post_ids = array();

	/**
	 * Set up test posts before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure the database is set up (column + fulltext index).
		$this->setup_database();

		// Create some posts with known content.
		$this->post_ids = array();

		$this->post_ids['apple'] = self::factory()->post->create(
			array(
				'post_title'   => 'Apple Pie Recipe',
				'post_content' => 'A delicious apple pie made with fresh apples and cinnamon.',
				'post_excerpt' => 'A classic dessert.',
				'post_status'  => 'publish',
			)
		);

		$this->post_ids['orange'] = self::factory()->post->create(
			array(
				'post_title'   => 'Citrus Fruits',
				'post_content' => 'Oranges and lemons are citrus fruits rich in vitamin C.',
				'post_excerpt' => 'A guide to citrus.',
				'post_status'  => 'publish',
			)
		);

		$this->post_ids['draft'] = self::factory()->post->create(
			array(
				'post_title'   => 'Draft Apple Notes',
				'post_content' => 'This is a draft about apples.',
				'post_status'  => 'draft',
			)
		);

		// Trigger the post data update for custom fields (default: empty).
		foreach ( $this->post_ids as $pid ) {
			relevanssi_light_update_post_data( $pid );
		}

		// Flush the FULLTEXT index cache (see refresh_fts_index()).
		$this->refresh_fts_index();
	}

	/**
	 * Ensure the fulltext index infrastructure exists.
	 *
	 * Calls the plugin's own table alteration function, which handles
	 * both MySQL (column + FULLTEXT index) and SQLite (FTS5 virtual table).
	 */
	protected function setup_database() {
		if ( function_exists( 'relevanssi_light_is_sqlite' ) && relevanssi_light_is_sqlite() ) {
			// SQLite path: ensure the FTS5 virtual table exists.
			relevanssi_light_create_fts_table();
		} else {
			global $wpdb;
			// MySQL path: check if the column already exists; if so, skip.
			$column_exists = $wpdb->get_row(
				"SHOW COLUMNS FROM $wpdb->posts LIKE 'relevanssi_light_data'"
			);
			if ( ! $column_exists ) {
				relevanssi_light_alter_table();
			}
		}
	}

	/**
	 * Commit and restart the WP test transaction.
	 *
	 * InnoDB/MariaDB FULLTEXT indexes do not index rows within an
	 * uncommitted transaction. The WP test framework wraps each test
	 * in a transaction (so it can roll back at the end). This helper
	 * commits (flushing the FTS cache so MATCH ... AGAINST works) and
	 * starts a fresh transaction so that tear_down() can roll back
	 * without error.
	 *
	 * On SQLite this is a no-op (FTS5 indexes are immediately visible
	 * within the same connection).
	 */
	protected function refresh_fts_index() {
		if ( function_exists( 'relevanssi_light_is_sqlite' ) && relevanssi_light_is_sqlite() ) {
			return;
		}
		global $wpdb;
		$wpdb->query( 'COMMIT;' );
		$wpdb->query( 'START TRANSACTION;' );
	}

	/**
	 * Perform a search and return the resulting post IDs.
	 *
	 * @param string $term     The search term.
	 * @param array  $args     Optional. Extra WP_Query args.
	 * @return int[] Array of post IDs.
	 */
	protected function search( $term, $args = array() ) {
		$defaults = array(
			's'           => $term,
			'post_type'   => 'post',
			'post_status'  => 'publish',
			'fields'      => 'ids',
		);
		$query = new WP_Query( array_merge( $defaults, $args ) );
		return array_map( 'intval', $query->posts );
	}

	/**
	 * Test that a search finds a post by its title.
	 */
	public function test_search_finds_post_by_title() {
		$results = $this->search( 'Apple' );

		$this->assertContains( $this->post_ids['apple'], $results );
	}

	/**
	 * Test that a search finds a post by its content.
	 */
	public function test_search_finds_post_by_content() {
		$results = $this->search( 'vitamin' );

		$this->assertContains( $this->post_ids['orange'], $results );
	}

	/**
	 * Test that a search finds a post by its excerpt.
	 */
	public function test_search_finds_post_by_excerpt() {
		$results = $this->search( 'dessert' );

		$this->assertContains( $this->post_ids['apple'], $results );
	}

	/**
	 * Test that a search for a nonexistent term returns no results.
	 */
	public function test_search_returns_empty_for_nonexistent_term() {
		$results = $this->search( 'zzznonexistentterm12345' );

		$this->assertEmpty( $results );
	}

	/**
	 * Test that drafts are not returned in search results.
	 */
	public function test_search_respects_post_status() {
		$results = $this->search( 'apple' );

		$this->assertNotContains( $this->post_ids['draft'], $results );
	}

	/**
	 * Test that search results are ordered by relevance.
	 *
	 * A post with the search term in both title and content should rank
	 * higher than one with the term only in content.
	 */
	public function test_search_orders_by_relevance() {
		// Post with 'apple' in title, content, and excerpt.
		$high_relevance = $this->post_ids['apple'];

		// Create a post that has 'apple' only in content.
		$low_relevance = self::factory()->post->create(
			array(
				'post_title'   => 'Something Else Entirely',
				'post_content' => 'I once saw an apple in the store.',
				'post_status'  => 'publish',
			)
		);
		relevanssi_light_update_post_data( $low_relevance );

		// Flush the FULLTEXT index so the new post is searchable.
		$this->refresh_fts_index();

		$results = $this->search( 'apple' );

		$this->assertContains( $high_relevance, $results );
		$this->assertContains( $low_relevance, $results );

		// The high-relevance post should appear before the low-relevance post.
		$high_pos = array_search( $high_relevance, $results, true );
		$low_pos  = array_search( $low_relevance, $results, true );

		$this->assertNotFalse( $high_pos );
		$this->assertNotFalse( $low_pos );
		$this->assertLessThan( $low_pos, $high_pos, 'High-relevance post should rank higher' );
	}

	/**
	 * Test that custom field content is indexed and searchable.
	 */
	public function test_search_finds_post_by_custom_field() {
		// Enable custom field indexing via the filter.
		add_filter( 'relevanssi_light_custom_fields', function() {
			return array( '_test_meta' );
		} );

		// Create a post with a custom field containing a unique keyword.
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Post With Meta',
				'post_content' => 'This content does not mention the magic word.',
				'post_status'  => 'publish',
			)
		);
		update_post_meta( $post_id, '_test_meta', 'supercalifragilistic' );
		relevanssi_light_update_post_data( $post_id );

		// Flush the FULLTEXT index so the new post is searchable.
		$this->refresh_fts_index();

		// Remove the filter after updating.
		remove_all_filters( 'relevanssi_light_custom_fields' );

		$results = $this->search( 'supercalifragilistic' );

		$this->assertContains( $post_id, $results, 'Post should be found by custom field content' );
	}

	/**
	 * Test that without the custom fields filter, custom field content
	 * is NOT indexed.
	 */
	public function test_search_does_not_find_custom_field_without_filter() {
		// Ensure no custom fields are indexed (default behaviour).
		remove_all_filters( 'relevanssi_light_custom_fields' );

		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Another Meta Post',
				'post_content' => 'No mention of the secret word here.',
				'post_status'  => 'publish',
			)
		);
		update_post_meta( $post_id, '_test_meta', 'unfindablekeywordxyz' );
		relevanssi_light_update_post_data( $post_id );

		// Flush the FULLTEXT index so the new post is searchable.
		$this->refresh_fts_index();

		$results = $this->search( 'unfindablekeywordxyz' );

		$this->assertNotContains( $post_id, $results, 'Post should not be found by custom field content when filter is not set' );
	}

	/**
	 * Test the posts_search filter modifies the WHERE clause.
	 *
	 * On MySQL, the WHERE clause contains MATCH...AGAINST.
	 * On SQLite, the WHERE clause contains an IN(...) clause.
	 */
	public function test_posts_search_filter_modifies_where_clause() {
		$query = new WP_Query( array( 's' => 'apple' ) );

		$request = $query->request;

		if ( function_exists( 'relevanssi_light_is_sqlite' ) && relevanssi_light_is_sqlite() ) {
			// SQLite: the WHERE should contain an IN clause.
			$this->assertStringContainsStringIgnoringCase( 'IN', $request );
		} else {
			// MySQL: the WHERE should contain MATCH...AGAINST.
			$this->assertStringContainsStringIgnoringCase( 'MATCH', $request );
		}
	}

	/**
	 * Test that the query is modified for relevance ordering.
	 *
	 * On MySQL, the request contains a 'relevance' column.
	 * On SQLite, the request contains a FIELD() ORDER BY clause.
	 */
	public function test_posts_request_adds_relevance_ordering() {
		$query = new WP_Query( array( 's' => 'apple' ) );

		$request = $query->request;

		if ( function_exists( 'relevanssi_light_is_sqlite' ) && relevanssi_light_is_sqlite() ) {
			// SQLite: the ORDER BY should contain FIELD().
			$this->assertStringContainsStringIgnoringCase( 'FIELD', $request );
		} else {
			// MySQL: the request should contain 'relevance'.
			$this->assertStringContainsStringIgnoringCase( 'relevance', $request );
		}
	}
}
