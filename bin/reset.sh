#!/usr/bin/env bash
#
# Tear the dev store down and delete its data volumes.
#
# Run bin/setup.sh afterwards to get a clean store back. Nothing outside the
# containers is touched -- the plugin source is bind-mounted, not copied.
#
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.docker"

if [[ "${1:-}" != "--yes" ]]; then
	read -r -p "Destroy the dev store and all its data? [y/N] " reply
	[[ "$reply" =~ ^[Yy]$ ]] || { echo "Aborted."; exit 1; }
fi

docker compose down --volumes --remove-orphans
echo "Dev store removed. Run bin/setup.sh to rebuild it."
