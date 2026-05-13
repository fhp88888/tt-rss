#!/usr/bin/env bash
set -euo pipefail

COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
OWNER_UID="${OWNER_UID:-1000}"
OWNER_GID="${OWNER_GID:-1000}"
SERVICES=(app updater web-nginx)
DRY_RUN=false

usage() {
	cat <<EOF
Usage: ./deploy.sh [--dry-run] <command>

Commands:
  up       Start the deploy stack in the background
  down     Stop and remove the deploy stack containers
  restart  Restart application services
  update   Pull Git changes and restart application services
  help     Show this help

Environment:
  COMPOSE_FILE  Compose file to use, defaults to docker-compose.yml
  TTRSS_ENV_FILE  Env file loaded into containers, defaults to .env
  OWNER_UID  Runtime file owner UID, defaults to 1000
  OWNER_GID  Runtime file owner GID, defaults to 1000
EOF
}

run() {
	echo "+ $*"

	if [[ "$DRY_RUN" == "false" ]]; then
		"$@"
	fi
}

prepare_data_dirs() {
	run mkdir -p \
		data/cache \
		data/lock \
		data/plugins.local \
		data/templates.local \
		data/themes.local

	run rm -f config.php
	run chown "$OWNER_UID:$OWNER_GID" .

	run chown -R "$OWNER_UID:$OWNER_GID" \
		data/cache \
		data/lock \
		data/plugins.local \
		data/templates.local \
		data/themes.local
}

if [[ "${1:-}" == "--dry-run" ]]; then
	DRY_RUN=true
	shift
fi

command="${1:-help}"

case "$command" in
	help|-h|--help)
		usage
		;;
	up)
		prepare_data_dirs
		run docker compose -f "$COMPOSE_FILE" up -d
		;;
	down)
		run docker compose -f "$COMPOSE_FILE" down
		;;
	restart)
		prepare_data_dirs
		run docker compose -f "$COMPOSE_FILE" restart "${SERVICES[@]}"
		;;
	update)
		prepare_data_dirs
		run git pull --ff-only
		run docker compose -f "$COMPOSE_FILE" restart "${SERVICES[@]}"
		;;
	*)
		echo "Unknown command: $command" >&2
		usage >&2
		exit 2
		;;
esac
