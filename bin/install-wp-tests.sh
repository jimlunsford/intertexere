#!/usr/bin/env bash
set -euo pipefail

DB_NAME="${1-wordpress_test}"
DB_USER="${2-root}"
DB_PASS="${3-root}"
DB_HOST="${4-127.0.0.1:3306}"
WP_VERSION="${5-7.1}"
WP_CORE_DIR="${WP_CORE_DIR-/tmp/wordpress}"
WP_TESTS_DIR="${WP_TESTS_DIR-/tmp/wordpress-tests-lib}"

download() {
	if command -v curl >/dev/null 2>&1; then
		curl -fsSL "$1" -o "$2"
	else
		wget -nv -O "$2" "$1"
	fi
}

if [ ! -d "$WP_CORE_DIR/wp-includes" ]; then
	tmp_core="$(mktemp -d)"
	download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" "$tmp_core/wordpress.tar.gz"
	tar -xzf "$tmp_core/wordpress.tar.gz" -C "$tmp_core"
	mkdir -p "$WP_CORE_DIR"
	cp -R "$tmp_core/wordpress/." "$WP_CORE_DIR/"
	rm -rf "$tmp_core"
fi

if [ ! -d "$WP_TESTS_DIR/includes" ]; then
	tmp_tests="$(mktemp -d)"
	download "https://github.com/WordPress/wordpress-develop/archive/refs/tags/${WP_VERSION}.tar.gz" "$tmp_tests/tests.tar.gz"
	tar -xzf "$tmp_tests/tests.tar.gz" -C "$tmp_tests"
	mkdir -p "$WP_TESTS_DIR"
	cp -R "$tmp_tests/wordpress-develop-${WP_VERSION}/tests/phpunit/." "$WP_TESTS_DIR/"
	cp "$tmp_tests/wordpress-develop-${WP_VERSION}/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config-sample.php"
	rm -rf "$tmp_tests"
fi

db_host_name="${DB_HOST%%:*}"
db_host_port="${DB_HOST##*:}"
if [ "$db_host_name" = "$db_host_port" ]; then
	db_host_port="3306"
fi

mysql --protocol=tcp --host="$db_host_name" --port="$db_host_port" --user="$DB_USER" --password="$DB_PASS" \
	-e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;"

sed \
	-e "s/youremptytestdbnamehere/${DB_NAME}/" \
	-e "s/yourusernamehere/${DB_USER}/" \
	-e "s/yourpasswordhere/${DB_PASS}/" \
	-e "s|localhost|${DB_HOST}|" \
	-e "s|dirname( __FILE__ ) . '/src/'|'${WP_CORE_DIR}/'|" \
	"$WP_TESTS_DIR/wp-tests-config-sample.php" > "$WP_TESTS_DIR/wp-tests-config.php"
