# Relevanssi Light: Add Test Harness + Optional SQLite Support

## Context

**Source repo:** https://github.com/msaari/relevanssi-light

**Goal:** Fork the repo, add a proper test harness (none currently exists), write behavioural tests against the existing MySQL-based search, then add optional SQLite database support and verify parity via the same test suite running against both database backends. Submit as a PR.

## Current state of the source repo

The `msaari/relevanssi-light` repo has **no tests, no CI, no PHPUnit configuration**. The only dev tooling is a `.phpcs.xml.dist` for coding standard checks. Files are flat (no `includes/` structure):

- `relevanssi-light.php` — main plugin file (MySQL-based search using `WP_Query` filtering)
- `relevanssi-light-admin-ajax.php` — admin AJAX handling
- `relevanssi-light-menu.php` — admin menu/settings page
- `uninstall.php` — cleanup on uninstall
- `css/`, `js/` — frontend/admin assets
- `readme.md`, `readme.txt` — documentation

The main Relevanssi plugin (separate repo: https://github.com/msaari/relevanssi) DOES have tests at `tests/` using PHPUnit integration tests with a real WordPress test database. It uses Mikko Saari's own `msaari/wp-test-framework` Composer package for the bootstrap, with a very minimal `tests/bootstrap.php` (3 lines: autoload, load framework, load plugin). Tests are integration tests using `self::factory()->post->create_many()` and `relevanssi_do_query()`.

## Decisions made

1. **Use `wp scaffold plugin` approach** (standard WP-CLI scaffolder) rather than mirroring Relevanssi's `rask` framework or a modern Composer/PSR-4 boilerplate. Rationale: familiarity and simplicity. The scaffold generates the standard test infrastructure (`tests/bootstrap.php`, `bin/install-wp-tests.sh`, `phpunit.xml.dist`, `.phpcs.xml.dist`) that most WP developers recognise.

2. **Two PHPUnit configurations** — one for MySQL (default), one for SQLite — sharing identical test classes but with different bootstraps that load (or don't load) the SQLite `db.php` drop-in.

3. **Use `WordPress/sqlite-database-integration` plugin** (the official feature plugin, not the older `aaemnnosttv/wp-sqlite-db`) as the SQLite driver. It passes 99%+ of the WordPress PHPUnit test suite as of mid-2025 and uses a `db.php` drop-in to replace `wpdb`.

## Phase 1: Scaffold + MySQL test harness

### 1.1 Scaffold test infrastructure

The repo already has plugin code, so we don't need `wp scaffold plugin` to generate the plugin itself — just the test files. Run from within a WordPress install that has the forked plugin in `wp-content/plugins/relevanssi-light/`:

```bash
wp scaffold plugin-tests relevanssi-light --ci=github
```

This generates into the existing plugin directory:
- `phpunit.xml.dist`
- `.github/workflows/...` (GitHub Actions CI)
- `bin/install-wp-tests.sh`
- `tests/bootstrap.php`
- `tests/test-sample.php` (delete this)

### 1.2 Add composer.json

The scaffold doesn't generate a `composer.json`. Add one:

```json
{
    "name": "fork/relevanssi-light",
    "description": "Relevanssi Light with optional SQLite support",
    "type": "wordpress-plugin",
    "require": {
        "php": ">=7.2"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.0",
        "yoast/phpunit-polyfills": "^2.0",
        "wp-phpunit/wp-phpunit": "^6.0"
    }
}
```

### 1.3 Adjust tests/bootstrap.php

Ensure it loads `relevanssi-light.php`:

```php
<?php
$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
    $_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}
require_once $_tests_dir . '/includes/functions.php';
require_once $_tests_dir . '/includes/bootstrap.php';
```

The plugin should be manually required at the end:
```php
require_once dirname( __DIR__ ) . '/relevanssi-light.php';
```

### 1.4 Set up MySQL test environment (one-time)

```bash
bash bin/install-wp-tests.sh wordpress_test root root localhost latest
```

This creates a throwaway MySQL database (`wordpress_test`), downloads WordPress to `/tmp/wordpress/`, and generates `/tmp/wordpress-tests-lib/wp-tests-config.php`.

### 1.5 Write behavioural baseline tests

Create `tests/test-searching.php` with integration tests that verify the current MySQL-based search behaviour. Model these on Relevanssi's `test-searching.php` pattern:

- **Setup**: Use `self::factory()->post->create()` / `create_many()` to generate posts with known titles and content. Call whatever indexing function Relevanssi Light uses (check `relevanssi-light.php` for the indexing entry point — it likely hooks into `save_post` or has a manual reindex function).
- **Search**: Instantiate `WP_Query` with `['s' => 'searchterm']` and get results. Relevanssi Light hooks into `pre_get_posts` or `the_posts` to replace the default search.
- **Assertions**:
  - `test_search_finds_post_by_title` — post with search term in title appears in results
  - `test_search_finds_post_by_content` — post with search term in content appears in results
  - `test_search_returns_empty_for_nonexistent_term` — no results for a term that doesn't exist
  - `test_search_respects_post_status` — drafts are not returned
  - `test_exact_match` — Relevanssi Light supports exact match mode; test it if the feature exists
  - `test_post_meta_search` — if Relevanssi Light searches custom fields, test that
  - Any other features found in the codebase (check admin settings for configurable options)

**IMPORTANT**: Read `relevanssi-light.php` carefully to understand:
- How indexing works (does it add a FULLTEXT index to `wp_posts`? Does it create a custom table?)
- How search is intercepted (which WP hook?)
- What admin settings exist and how they affect behaviour

Write tests that cover each configurable behaviour. The goal is to establish a **behavioural baseline** — if these tests pass on MySQL, we know the original functionality is preserved.

### 1.6 Verify MySQL tests pass

```bash
composer install
vendor/bin/phpunit
```

All tests must pass against MySQL before proceeding to Phase 2.

## Phase 2: Add SQLite support

### 2.1 Understand how Relevanssi Light uses the database

Read `relevanssi-light.php` and identify all direct SQL or `$wpdb` calls. Relevanssi Light typically:
- Adds a FULLTEXT index to the `post_title` and `post_content` columns of `wp_posts` (or a custom index table)
- Uses `MATCH(...) AGAINST(...)` in SQL for MySQL full-text search

**SQLite does not support `MATCH ... AGAINST` syntax** and its FTS (Full-Text Search) works differently (FTS5 virtual tables, `MATCH` operator). This is the core compatibility challenge.

There is a working version of the Relevannsi light plugin, that has been modified to work with SQLite. use that code as for implementation. only make changes that would be required for the testing harness to work

/Users/chrisadams/Code/misc/personal-sites/rtl.chrisadams.me.uk/public/wp-content/plugins/local/relevanssi-light/


### 2.4 Make SQLite optional

- The plugin should work out-of-the-box with MySQL (no behaviour change for existing users).
- SQLite support activates automatically when the `sqlite-database-integration` plugin (or its `db.php` drop-in) is detected.
- Add an admin notice if SQLite is detected but the FTS5 extension is not available in PHP's SQLite driver.

## Phase 3: SQLite test harness

### 3.1 Add SQLite dependencies to composer.json

```json
"require-dev": {
    "wordpress/sqlite-database-integration": "^2.0"
}
```

### 3.2 Create SQLite PHPUnit config

Create `phpunit.sqlite.xml.dist`:

```xml
<?xml version="1.0"?>
<phpunit
    bootstrap="tests/bootstrap-sqlite.php"
    backupGlobals="false"
    colors="true"
    convertErrorsToExceptions="true"
    convertNoticesToExceptions="true"
    convertWarningsToExceptions="true"
>
    <testsuites>
        <testsuite>
            <directory suffix=".php">tests</directory>
        </testsuite>
    </testsuites>
    <php>
        <env name="WP_TESTS_DIR" value="/tmp/wordpress-tests-lib"/>
    </php>
</phpunit>
```

### 3.3 Create SQLite bootstrap

Create `tests/bootstrap-sqlite.php`:

```php
<?php
$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
    $_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

// Copy the SQLite db.php drop-in into the WP test install's wp-content/
tests_add_filter( 'muplugins_loaded', function () {
    $sqlite_db_php = dirname( __DIR__ ) . '/vendor/wordpress/sqlite-database-integration/db.php';
    $wp_content    = '/tmp/wordpress/src/wp-content';
    if ( file_exists( $sqlite_db_php ) && is_writable( $wp_content ) ) {
        copy( $sqlite_db_php, $wp_content . '/db.php' );
    }
} );

require_once $_tests_dir . '/includes/bootstrap.php';
require_once dirname( __DIR__ ) . '/relevanssi-light.php';
```

### 3.4 Create SQLite wp-tests-config template

Create `tests/wp-tests-config-sqlite.php`:

```php
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
```

The `DB_DIR` and `DB_FILE` constants are read by the sqlite-database-integration drop-in to determine the SQLite file location.

### 3.5 Create SQLite setup script

Create `bin/install-wp-tests-sqlite.sh`:

```bash
#!/bin/bash
# Set up WP test suite without creating a MySQL database
bash bin/install-wp-tests.sh wordpress_test dummy dummy localhost latest true

# Replace wp-tests-config with SQLite version
WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
cp tests/wp-tests-config-sqlite.php "$WP_TESTS_DIR/wp-tests-config.php"

# Ensure SQLite drop-in is installed
composer require --dev wordpress/sqlite-database-integration
```

### 3.6 Run SQLite tests

```bash
bash bin/install-wp-tests-sqlite.sh
vendor/bin/phpunit -c phpunit.sqlite.xml.dist
```

The **same test classes** (`tests/test-searching.php` etc.) run against SQLite. If they all pass, MySQL/SQLite parity is verified.

## Phase 4: CI (GitHub Actions)

### 4.1 MySQL CI workflow

The scaffold generates `.github/workflows/testing.yml`. Ensure it runs:
- `composer install`
- `bash bin/install-wp-tests.sh ...`
- `vendor/bin/phpunit`

### 4.2 SQLite CI workflow

Add a second job (or matrix entry) that runs:
- `composer install`
- `bash bin/install-wp-tests-sqlite.sh`
- `vendor/bin/phpunit -c phpunit.sqlite.xml.dist`

Use a matrix strategy to run both in the same workflow:

```yaml
strategy:
  matrix:
    database: [mysql, sqlite]
    include:
      - database: mysql
        phpunit-config: phpunit.xml.dist
      - database: sqlite
        phpunit-config: phpunit.sqlite.xml.dist
```



## Key references

- **WP-CLI scaffold plugin docs**: https://developer.wordpress.org/cli/commands/scaffold/plugin/
- **WP-CLI scaffold plugin-tests docs**: https://developer.wordpress.org/cli/commands/scaffold/plugin-tests/
- **install-wp-tests.sh template**: https://github.com/wp-cli/scaffold-command/blob/master/templates/install-wp-tests.sh
- **sqlite-database-integration**: https://github.com/WordPress/sqlite-database-integration
- **sqlite-database-integration constants** (DB_DIR, DB_FILE, FQDBDIR): https://github.com/WordPress/sqlite-database-integration/blob/trunk/constants.php
- **Relevanssi (main plugin) tests**: https://github.com/msaari/relevanssi/tree/master/tests
- **Relevanssi test framework**: `msaari/wp-test-framework` on Packagist
- **wp-phpunit (Composer-installable WP test library)**: https://github.com/wp-phpunit/docs
- **WP_ENV now supports SQLite runtime** (Feb 2026): https://github.com/WordPress/wordpress-playground

## Critical implementation notes for the coding agent

1. **READ THE SOURCE CODE FIRST.** Before writing any tests or abstraction code, carefully read `relevanssi-light.php`, `relevanssi-light-admin-ajax.php`, and `relevanssi-light-menu.php` to understand exactly how Relevanssi Light currently:
   - Creates/maintains its search index (FULLTEXT index on `wp_posts`? Custom table?)
   - Intercepts WordPress search (which hook? `pre_get_posts`? `the_posts`? `posts_search`?)
   - Handles admin settings and AJAX reindexing

3. **Tests must be backend-agnostic.** The test classes in `tests/test-searching.php` must not contain any MySQL- or SQLite-specific logic. They use WordPress APIs (`WP_Query`, `self::factory()`) and assert on results. The backend is determined by which bootstrap is loaded.

4. **SQLite support is OPTIONAL.** The plugin must work identically on MySQL without any changes. SQLite support is only activated when the `sqlite-database-integration` drop-in is detected. Existing users must not be affected.

5. **The PR should be well-structured for review.** Consider splitting into multiple commits:
   - Commit 1: Add test harness + MySQL behavioural tests (no code changes to the plugin)
   - Commit 2: Refactor plugin to use DB abstraction (MySQL-only, no behaviour change)
   - Commit 3: Add SQLite driver implementation
   - Commit 4: Add SQLite test harness + CI matrix
