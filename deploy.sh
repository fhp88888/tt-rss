#!/usr/bin/env bash
set -euo pipefail

COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
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
EOF
}

run() {
	echo "+ $*"

	if [[ "$DRY_RUN" == "false" ]]; then
		"$@"
	fi
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
		run docker compose -f "$COMPOSE_FILE" up -d
		;;
	down)
		run docker compose -f "$COMPOSE_FILE" down
		;;
	restart)
		run docker compose -f "$COMPOSE_FILE" restart "${SERVICES[@]}"
		;;
	update)
		run git pull --ff-only
		run docker compose -f "$COMPOSE_FILE" restart "${SERVICES[@]}"
		;;
	*)
		echo "Unknown command: $command" >&2
		usage >&2
		exit 2
		;;
esac
