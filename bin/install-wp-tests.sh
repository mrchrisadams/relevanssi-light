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

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo $TMPDIR | sed -e "s/\/$//")
WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress/}

download() {
    if [ `which curl` ]; then
        curl -s "$1" > "$2"
    elif [ `which wget` ]; then
        wget -nv -O "$2" "$1"
    fi
}

if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
	WP_TESTS_TAG="branches/$WP_VERSION"
elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0-9]+ ]]; then
	WP_TESTS_TAG="tags/$WP_VERSION"
elif [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
	WP_TESTS_TAG="trunk"
else
	download https://api.wordpress.org/core/version-check/1.7/ /tmp/tmp-latest-json
	LATEST_VERSION=$(grep -o '"version":"[^"]*"' /tmp/tmp-latest-json | head -1 | sed 's/"version":"//' | sed 's/"//')
	if [[ -z "$LATEST_VERSION" ]]; then
		echo "Latest WordPress version could not be found"
		exit 1
	fi
	WP_TESTS_TAG="tags/$LATEST_VERSION"
fi

set -ex

install_wp() {
	if [ -d "$WP_CORE_DIR" ]; then
		return
	fi
	mkdir -p "$WP_CORE_DIR"
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

	cd "$WP_TESTS_DIR"
	if [ ! -f wp-tests-config.php ]; then
		download "https://raw.githubusercontent.com/WordPress/wordpress-develop/$WP_TESTS_TAG/wp-tests-config-sample.php" "$WP_TESTS_DIR"/wp-tests-config.php
		WP_CORE_DIR=$(echo $WP_CORE_DIR | sed "s:/\+$::")
		sed $ioption "s:dirname( __FILE__ ) . '/wordpress/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR"/wp-tests-config.php
		sed $ioption "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR"/wp-tests-config.php
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

WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}
mkdir -p "$WP_TESTS_DIR"
install_wp
install_test_suite
install_db
