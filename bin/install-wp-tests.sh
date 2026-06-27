#!/usr/bin/env bash

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]"
	exit 1
fi

DB_NAME="$1"
DB_USER="$2"
DB_PASS="$3"
DB_HOST="${4-localhost}"
WP_VERSION="${5-latest}"
SKIP_DB_CREATE="${6-false}"

# Default to a project-local .wp-test/ directory so the WordPress test
# install lives alongside the project (not in /tmp).
PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WP_TESTS_DIR="${WP_TESTS_DIR-$PROJECT_ROOT/.wp-test/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR-$PROJECT_ROOT/.wp-test/wordpress/}"

# TMPDIR is used only for downloading the zip archive.
# Keep it separate from WP_CORE_DIR to avoid mv conflicts.
TMPDIR="${TMPDIR-$PROJECT_ROOT/.wp-test/tmp}"
TMPDIR=$(echo "$TMPDIR" | sed -e "s/\/$//")

download() {
    if [ `which curl` ]; then
        curl -s "$1" > "$2"
    elif [ `which wget` ]; then
        wget -nv -O "$2" "$1"
    fi
}

# The WP test config sample and test framework includes are the same on
# trunk as on any release tag, so we always download them from trunk.
WP_TESTS_TAG="trunk"

# For the WordPress core download, resolve "latest" to a real version number.
if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
	WP_VERSION="nightly"
elif [[ $WP_VERSION == 'latest' ]]; then
	mkdir -p "$TMPDIR"
	download https://api.wordpress.org/core/version-check/1.7/ "$TMPDIR"/tmp-latest-json
	WP_VERSION=$(grep -o '"version":"[^"]*"' "$TMPDIR"/tmp-latest-json | head -1 | sed 's/"version":"//' | sed 's/"//')
	if [[ -z "$WP_VERSION" ]]; then
		echo "Latest WordPress version could not be found"
		exit 1
	fi
fi

set -ex

install_wp() {
	if [ -d "$WP_CORE_DIR" ]; then
		return
	fi
	mkdir -p "$WP_CORE_DIR" "$TMPDIR"
	download "https://wordpress.org/wordpress-$WP_VERSION.zip" "$TMPDIR"/wordpress.zip
	unzip -q "$TMPDIR"/wordpress.zip -d "$TMPDIR"/
	mv "$TMPDIR"/wordpress/* "$WP_CORE_DIR"/
}

install_test_suite() {
	if [[ $(uname -s) == 'Darwin' ]]; then
		local ioption='-i.bak'
	else
		local ioption='-i'
	fi

	mkdir -p "$WP_TESTS_DIR"
	cd "$WP_TESTS_DIR"
	if [ ! -f wp-tests-config.php ]; then
		download "https://raw.githubusercontent.com/WordPress/wordpress-develop/$WP_TESTS_TAG/wp-tests-config-sample.php" "$WP_TESTS_DIR"/wp-tests-config.php
		WP_CORE_DIR=$(echo "$WP_CORE_DIR" | sed -E "s:/+$::")
		# Handle both ABSPATH patterns (wordpress/ for old, src/ for trunk).
		sed $ioption "s#define( 'ABSPATH', dirname( __FILE__ ) . '/wordpress/' );#define( 'ABSPATH', '$WP_CORE_DIR/' );#" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s#define( 'ABSPATH', dirname( __FILE__ ) . '/src/' );#define( 'ABSPATH', '$WP_CORE_DIR/' );#" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR"/wp-tests-config.php
	fi

	# Download the WP PHPUnit test framework (includes/, factory/, etc.)
	if [ ! -d "$WP_TESTS_DIR/includes" ]; then
		mkdir -p "$TMPDIR"
		# Use git sparse-checkout to get only the test framework files.
		git clone --depth 1 --filter=blob:none --sparse https://github.com/WordPress/wordpress-develop.git "$TMPDIR"/wp-develop-tmp
		cd "$TMPDIR"/wp-develop-tmp
		git sparse-checkout set tests/phpunit/includes
		cp -r tests/phpunit/includes "$WP_TESTS_DIR"/includes
		cd "$PROJECT_ROOT"
		rm -rf "$TMPDIR"/wp-develop-tmp
	fi
}

install_db() {
	if [ "$SKIP_DB_CREATE" = "true" ]; then
		return
	fi
	local PARTS=(${DB_HOST//\:/ })
	local DB_HOSTNAME=${PARTS[0]}
	local DB_SOCK_OR_PORT=${PARTS[1]}
	local EXTRA=""
	if ! [ -z $DB_SOCK_OR_PORT ]; then
		if [[ "$DB_SOCK_OR_PORT" =~ ^[0-9]+$ ]]; then
			EXTRA=" --port=$DB_SOCK_OR_PORT"
		else
			EXTRA=" --socket=$DB_SOCK_OR_PORT"
		fi
	fi
	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOSTNAME" $EXTRA || true
}

install_wp
install_test_suite
install_db
