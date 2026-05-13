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

help_output="$(./deploy.sh help)"
assert_contains "$help_output" "Usage: ./deploy.sh"
assert_contains "$help_output" "up"
assert_contains "$help_output" "down"
assert_contains "$help_output" "restart"
assert_contains "$help_output" "update"
assert_contains "$help_output" "TTRSS_ENV_FILE"
assert_contains "$help_output" "OWNER_UID"

up_output="$(./deploy.sh --dry-run up)"
assert_contains "$up_output" "mkdir -p data/cache data/lock data/plugins.local data/templates.local data/themes.local"
assert_contains "$up_output" "rm -f config.php"
assert_contains "$up_output" "chgrp 1000 ."
assert_contains "$up_output" "chmod g+w ."
assert_contains "$up_output" "chown -R 1000:1000 data/cache data/lock data/plugins.local data/templates.local data/themes.local"
assert_contains "$up_output" "docker compose -f docker-compose.yml up -d"

down_output="$(./deploy.sh --dry-run down)"
assert_contains "$down_output" "docker compose -f docker-compose.yml down"

restart_output="$(./deploy.sh --dry-run restart)"
assert_contains "$restart_output" "docker compose -f docker-compose.yml restart app updater web-nginx"

update_output="$(./deploy.sh --dry-run update)"
assert_contains "$update_output" "git pull --ff-only"
assert_contains "$update_output" "docker compose -f docker-compose.yml up -d"

echo "deploy script tests passed"
