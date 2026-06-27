#!/bin/bash
# Set up WP test suite for SQLite (no MySQL database required).
#
# This script:
#   1. Downloads WordPress + the WP test lib (skipping MySQL DB creation).
#   2. Replaces wp-tests-config.php with the SQLite version.
#   3. Ensures Composer dependencies (including wordpress/sqlite-database-integration) are installed.
#   4. Installs the SQLite db.php drop-in into the WP test install.

set -e

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"

# Step 1: Install WP test suite without creating a MySQL database.
bash bin/install-wp-tests.sh wordpress_test dummy dummy localhost latest true

# Step 2: Replace wp-tests-config with the SQLite version.
WP_TESTS_DIR="${WP_TESTS_DIR-$PROJECT_ROOT/.wp-test/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR-$PROJECT_ROOT/.wp-test/wordpress/}"
cp tests/wp-tests-config-sqlite.php "$WP_TESTS_DIR/wp-tests-config.php"

# Step 3: Add ABSPATH to the SQLite config (uses dummy DB credentials).
echo "" >> "$WP_TESTS_DIR/wp-tests-config.php"
echo "define( 'ABSPATH', '$WP_CORE_DIR' );" >> "$WP_TESTS_DIR/wp-tests-config.php"

# Step 4: Ensure Composer dependencies are installed.
composer install --no-interaction --prefer-source

# Step 5: Install the SQLite drop-in into the WP test install.
#
# The sqlite-database-integration monorepo ships the WordPress plugin under
# packages/plugin-sqlite-database-integration/ and the MySQL-on-SQLite driver
# under packages/mysql-on-sqlite/src/.  The plugin contains a relative symlink
# (wp-includes/database -> ../../../mysql-on-sqlite/src) that points to the
# driver within the monorepo.  When the package is installed into vendor/ via a
# Composer path repository (used in CI to avoid GitHub API throttling), the
# upstream .gitattributes marks /packages as export-ignore, so the packages/
# directory is absent from vendor/.  Instead use the pre-cloned repository at
# /tmp/sqlite-database-integration when it is present (CI scenario); fall back
# to the VCS-installed vendor/ path for local development (where the full tree
# is present because git-checkout does not honour export-ignore).
#
# Using `cp -rL` dereferences all symlinks on the way so the destination ends
# up with a fully self-contained copy of the plugin (database/ is a real
# directory, not a broken symlink).
SQLITE_CLONE="/tmp/sqlite-database-integration"
if [ -d "$SQLITE_CLONE/packages/plugin-sqlite-database-integration" ]; then
    PLUGIN_SOURCE="$SQLITE_CLONE/packages/plugin-sqlite-database-integration"
else
    SQLITE_VENDOR="$PROJECT_ROOT/vendor/wordpress/sqlite-database-integration"
    PLUGIN_SOURCE="$SQLITE_VENDOR/packages/plugin-sqlite-database-integration"
fi
WP_CONTENT_DIR="${WP_CORE_DIR%/}/wp-content"
WP_PLUGINS_DIR="$WP_CONTENT_DIR/plugins"
DEST_PLUGIN="$WP_PLUGINS_DIR/sqlite-database-integration"

mkdir -p "$WP_PLUGINS_DIR"

# Remove any stale copy before installing.
rm -rf "$DEST_PLUGIN"

# Copy the plugin, following all symlinks.
cp -rL "$PLUGIN_SOURCE" "$DEST_PLUGIN"

# Copy db.copy as the WordPress db.php drop-in.
# db.copy falls back to realpath(__DIR__ . '/plugins/sqlite-database-integration')
# when the {SQLITE_IMPLEMENTATION_FOLDER_PATH} placeholder is not substituted,
# so no string replacement is needed here.
cp "$DEST_PLUGIN/db.copy" "$WP_CONTENT_DIR/db.php"
