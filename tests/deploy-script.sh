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

up_output="$(./deploy.sh --dry-run up)"
assert_contains "$up_output" "docker compose -f docker-compose.yml up -d"

down_output="$(./deploy.sh --dry-run down)"
assert_contains "$down_output" "docker compose -f docker-compose.yml down"

restart_output="$(./deploy.sh --dry-run restart)"
assert_contains "$restart_output" "docker compose -f docker-compose.yml restart app updater web-nginx"

update_output="$(./deploy.sh --dry-run update)"
assert_contains "$update_output" "git pull --ff-only"
assert_contains "$update_output" "docker compose -f docker-compose.yml restart app updater web-nginx"

echo "deploy script tests passed"
