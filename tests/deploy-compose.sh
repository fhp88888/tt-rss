#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

fail() {
	echo "FAIL: $*" >&2
	exit 1
}

assert_contains() {
	local haystack="$1"
	local needle="$2"

	if [[ "$haystack" != *"$needle"* ]]; then
		fail "expected output to contain: $needle"
	fi
}

config_output="$(TTRSS_ENV_FILE=.env-dist docker compose -f docker-compose.yml config)"

assert_contains "$config_output" "target: /var/www/html/tt-rss/data"
assert_contains "$config_output" "source: $(pwd)/data/rss-gallery.csv"
assert_contains "$config_output" "target: /var/www/html/tt-rss/data/rss-gallery.csv"

echo "deploy compose tests passed"
