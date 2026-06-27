# Running the Tests

This guide explains how to run the Relevanssi Light test suite locally.

There are **two** test configurations:

| Backend       | PHPUnit config              | Bootstrap                    |
|---------------|-----------------------------|------------------------------|
| MySQL/MariaDB | `phpunit.xml.dist`          | `tests/bootstrap.php`        |
| SQLite (FTS5) | `phpunit.sqlite.xml.dist`   | `tests/bootstrap-sqlite.php` |

Both run the **same test classes** (`tests/test-searching.php`). The backend
is determined entirely by which bootstrap is loaded — the tests themselves
contain no backend-specific logic.

---

## Prerequisites

- **PHP** ≥ 7.2 (tested on PHP 8.5)
  - Extensions: `mysqli`, `pdo`, `pdo_sqlite`, `sqlite3`
- **Composer**
- **MySQL or MariaDB** running locally (for the MySQL tests only)
- A database user that can `CREATE DATABASE` (the test suite creates a
  throwaway database called `wordpress_test`)

Check your environment:

```bash
php -m | grep -E 'mysqli|pdo_sqlite|sqlite3'
mysqladmin ping
```

---

## 1. Install Composer dependencies

```bash
composer install --prefer-source
```
Use `--prefer-source` so that `wordpress/sqlite-database-integration` is
cloned with its full monorepo structure (needed to build the SQLite driver
at test time).

---

## 2. Set up the WordPress test framework (one-time)

```bash
bash bin/install-wp-tests.sh wordpress_test <db-user> <db-password> localhost latest
```

This downloads WordPress and the WP PHPUnit test library to `/tmp/wordpress/`
and `/tmp/wordpress-tests-lib/`, and creates the `wordpress_test` database.

**macOS note:** `sys_get_temp_dir()` returns a path under `/var/folders/`,
not `/tmp/`. The PHPUnit configs hardcode `/tmp/wordpress-tests-lib` for CI
compatibility, so you need symlinks:

```bash
TMPDIR=$(php -r 'echo rtrim(sys_get_temp_dir(), "/\\");')
ln -sf "$TMPDIR/wordpress-tests-lib" /tmp/wordpress-tests-lib
ln -sf "$TMPDIR/wordpress" /tmp/wordpress
```

Verify:

```bash
ls /tmp/wordpress-tests-lib/includes/functions.php   # should exist
```

If the `wordpress_test` database already exists, this step is harmless in
its own right — the `install-wp-tests.sh` script uses `|| true` on the
`mysqladmin create` line, so re-running is safe.

---

## 3. Run the MySQL tests

```bash
vendor/bin/phpunit
```

Expected output:

```
..........                                                        10 / 10 (100%)

OK (10 tests, 14 assertions)
```

The config file is `phpunit.xml.dist`. It loads `tests/bootstrap.php`, which
requires your local MySQL/MariaDB instance.

If you see connection errors, verify your DB credentials are embedded in
`/tmp/wordpress-tests-lib/wp-tests-config.php` (or
`$TMPDIR/wordpress-tests-lib/wp-tests-config.php` on macOS). The `DB_USER`
and `DB_PASSWORD` values must match what you passed to `install-wp-tests.sh`.

---

## 4. Run the SQLite tests

No MySQL database is required for this step.

First, install the SQLite test config:

```bash
bash bin/install-wp-tests-sqlite.sh
```

This re-uses the WordPress download from step 2, replaces
`wp-tests-config.php` with `tests/wp-tests-config-sqlite.php` (dummy DB
credentials + SQLite `DB_DIR`/`DB_FILE` constants), and runs `composer install`.

Then run the tests:

```bash
vendor/bin/phpunit -c phpunit.sqlite.xml.dist
```

Expected output:

```
..........                                                        10 / 10 (100%)

OK (10 tests, 14 assertions)
```

The SQLite bootstrap (`tests/bootstrap-sqlite.php`) builds the
`wordpress/sqlite-database-integration` plugin from the vendor source and
copies its `db.php` drop-in into the WP test install's `wp-content/` directory
before WordPress boots. The SQLite database file is created in `/tmp/relevanssi-light-sqlite-test/`.

---

## 5. Switching between MySQL and SQLite configs

The `wp-tests-config.php` file is shared between both backends. The install
scripts overwrite it, so to switch:

**Switch to MySQL:**
```bash
bash bin/install-wp-tests.sh wordpress_test <db-user> <db-password> localhost latest true
```
(The `true` at the end skips re-creating the database.)

**Switch to SQLite:**
```bash
bash bin/install-wp-tests-sqlite.sh
```

Then run the appropriate PHPUnit command from steps 3 or 4.

---

## 6. Running both suites in sequence

```bash
# MySQL
bash bin/install-wp-tests.sh wordpress_test <db-user> <db-password> localhost latest true
vendor/bin/phpunit

# SQLite
bash bin/install-wp-tests-sqlite.sh
vendor/bin/phpunit -c phpunit.sqlite.xml.dist
```

---

## What the tests cover

| Test                                              | What it verifies                          |
|---------------------------------------------------|-------------------------------------------|
| `test_search_finds_post_by_title`                | Search by title                           |
| `test_search_finds_post_by_content`              | Search by body content                    |
| `test_search_finds_post_by_excerpt`              | Search by excerpt                        |
| `test_search_returns_empty_for_nonexistent_term` | No false positives                        |
| `test_search_respects_post_status`               | Drafts excluded                           |
| `test_search_orders_by_relevance`                | Relevance ranking                        |
| `test_search_finds_post_by_custom_field`         | Custom field indexing (with filter)       |
| `test_search_does_not_find_custom_field_without_filter` | Custom field exclusion (without filter) |
| `test_posts_search_filter_modifies_where_clause` | SQL WHERE clause is modified              |
| `test_posts_request_adds_relevance_ordering`     | Relevance ORDER BY is injected           |

---

## Troubleshooting

### "Error establishing a database connection"

- **MySQL tests:** Check that MySQL/MariaDB is running and that
  `wp-tests-config.php` has the correct `DB_USER`/`DB_PASSWORD`.
- **SQLite tests:** The `db.php` drop-in wasn't copied. Delete
  `/tmp/wordpress/wp-content/db.php` and re-run; the bootstrap will rebuild it.

### "Table 'wptests_posts' doesn't exist"

The WordPress test framework may not have been set up. Re-run step 2.

### Tests pass on SQLite but fail on MySQL

This is usually a FULLTEXT index issue with MariaDB's InnoDB engine. The
test suite includes a `refresh_fts_index()` helper that commits and restarts
the test transaction before searching — MariaDB FTS doesn't index rows within
an uncommitted transaction. If you still see issues, check that the
`relevanssi_light_data` column and `relevanssi_light_fulltext` index exist:

```bash
mysql wordpress_test -e "SHOW COLUMNS FROM wptests_posts LIKE 'relevanssi_light_data'"
mysql wordpress_test -e "SHOW INDEX FROM wptests_posts WHERE Key_name = 'relevanssi_light_fulltext'"
```

### `composer` can't find `wordpress/sqlite-database-integration`

The `composer.json` includes a VCS repository pointing to the GitHub repo.
Ensure you have network access and that GitHub auth is configured:

```bash
gh auth status
# or
composer config --global github-oauth.github.com <token>
```

### macOS: tests can't find `/tmp/wordpress-tests-lib`

Create the symlinks (see step 2). The PHPUnit configs hardcode `/tmp/` paths
for CI compatibility.
