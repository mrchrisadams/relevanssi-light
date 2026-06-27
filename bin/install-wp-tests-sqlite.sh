#!/bin/bash
# Set up WP test suite for SQLite (no MySQL database required).
#
# This script:
#   1. Downloads WordPress + the WP test lib (skipping MySQL DB creation).
#   2. Replaces wp-tests-config.php with the SQLite version.
#   3. Ensures Composer dependencies (including wordpress/sqlite-database-integration) are installed.
#
# The actual SQLite db.php drop-in is installed by tests/bootstrap-sqlite.php
# at test time (it builds the plugin from the monorepo source and copies it
# into the WP test install).

set -e

# Step 1: Install WP test suite without creating a MySQL database.
bash bin/install-wp-tests.sh wordpress_test dummy dummy localhost latest true

# Step 2: Replace wp-tests-config with the SQLite version.
WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress/}"
cp tests/wp-tests-config-sqlite.php "$WP_TESTS_DIR/wp-tests-config.php"

# Step 3: Add ABSPATH to the SQLite config (uses dummy DB credentials).
echo "" >> "$WP_TESTS_DIR/wp-tests-config.php"
echo "define( 'ABSPATH', '$WP_CORE_DIR' );" >> "$WP_TESTS_DIR/wp-tests-config.php"

# Step 4: Ensure Composer dependencies are installed.
composer install --no-interaction --prefer-dist
