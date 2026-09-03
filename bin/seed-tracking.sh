#!/usr/bin/env bash
#
# Write OGOship tracking meta onto an order, imitating the server-side
# integration, so the customer-facing display can be verified end to end.
#
#   bin/seed-tracking.sh 42
#   bin/seed-tracking.sh 42 JJFI123 https://tracking.example.test/JJFI123 Delivered
#   bin/seed-tracking.sh 42 JJFI123 -            # number but no tracking link
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ $# -lt 1 ]]; then
	echo "Usage: $(basename "$0") <order_id> [tracking_number] [tracking_url] [status]" >&2
	echo >&2
	echo "Find a seeded order id with:  bin/wp wc shop_order list --user=1" >&2
	exit 1
fi

ORDER_ID="$1"
NUMBER="${2:-JJFI00000000000012345}"
URL="${3:-https://tracking.example.test/${NUMBER}}"
STATUS="${4:-Delivered}"

cd "$SCRIPT_DIR/../.docker"
docker compose exec -T wpcli wp --path=/var/www/html \
	eval-file /seed/tracking.php "$ORDER_ID" "$NUMBER" "$URL" "$STATUS"
