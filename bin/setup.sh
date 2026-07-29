#!/usr/bin/env bash
#
# Provision the local WooCommerce dev store from nothing.
#
# Idempotent: safe to re-run. Use bin/reset.sh first if you want a clean slate.
#
#   bin/setup.sh
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
DOCKER_DIR="$ROOT_DIR/.docker"

cd "$DOCKER_DIR"

if [[ ! -f .env ]]; then
	cp .env.example .env
	echo "Created .docker/.env from the example."
fi

# shellcheck disable=SC1091
set -a; source .env; set +a

# HTTPS, not HTTP: WooCommerce only authenticates REST consumer keys when
# is_ssl() is true, and OGOship uses nothing else. See .docker/Caddyfile.
WP_URL="https://localhost:${WP_PORT:-8443}"

wp() { docker compose exec -T wpcli wp --path=/var/www/html "$@"; }

# Fail with a useful message rather than Docker's "port is already allocated".
# A port held by this stack's own containers is fine -- setup.sh is re-runnable.
own_ports() {
	docker compose ps --quiet 2>/dev/null \
		| xargs -r docker inspect --format '{{range $p, $c := .NetworkSettings.Ports}}{{range $c}}{{.HostPort}} {{end}}{{end}}' 2>/dev/null
}

check_port() {
	local port="$1" label="$2"
	lsof -nP -iTCP:"$port" -sTCP:LISTEN >/dev/null 2>&1 || return 0
	if own_ports | tr ' ' '\n' | grep -qx "$port"; then
		return 0
	fi
	echo "Port ${port} (${label}) is already in use by something else." >&2
	echo "Set ${label} to a free port in .docker/.env and re-run." >&2
	exit 1
}
check_port "${WP_PORT:-8443}" WP_PORT
check_port "${MAILPIT_PORT:-8026}" MAILPIT_PORT

echo "==> Starting containers"
docker compose up -d

echo "==> Waiting for WordPress files to be extracted"
# The wordpress image copies core into the volume on first boot; WP-CLI fails
# with a bare "not a WordPress installation" until that finishes.
for _ in $(seq 1 60); do
	if docker compose exec -T wpcli test -f /var/www/html/wp-includes/version.php; then
		break
	fi
	sleep 2
done

echo "==> Waiting for the database"
for _ in $(seq 1 60); do
	if wp db check >/dev/null 2>&1; then break; fi
	sleep 2
done

if wp core is-installed >/dev/null 2>&1; then
	echo "==> WordPress already installed, skipping core install"
else
	echo "==> Installing WordPress"
	wp core install \
		--url="$WP_URL" \
		--title="${WP_TITLE:-OGOship Plugin Dev Store}" \
		--admin_user="${WP_ADMIN_USER:-admin}" \
		--admin_password="${WP_ADMIN_PASSWORD:-admin}" \
		--admin_email="${WP_ADMIN_EMAIL:-dev@example.test}" \
		--skip-email
fi

# Keep the canonical URL in step with WP_PORT on re-runs, otherwise WordPress
# redirects to the old port and every request 301s away from the test.
wp option update siteurl "$WP_URL" >/dev/null
wp option update home "$WP_URL" >/dev/null

echo "==> Writing wp-config constants"
wp config set WP_DEBUG true --raw --type=constant >/dev/null
wp config set WP_DEBUG_LOG true --raw --type=constant >/dev/null
# Log to file rather than print: stray notices in the HTML break Playwright
# selectors and corrupt REST JSON.
wp config set WP_DEBUG_DISPLAY false --raw --type=constant >/dev/null
# Deterministic tests: nothing fires on its own between assertions.
wp config set DISABLE_WP_CRON true --raw --type=constant >/dev/null
wp config set WP_ENVIRONMENT_TYPE local --type=constant >/dev/null

# Pretty permalinks: the REST API works either way, but /wp-json/ URLs in the
# docs and the e2e suite assume them.
wp rewrite structure '/%postname%/' --hard >/dev/null

echo "==> Installing WooCommerce"
wp plugin is-installed woocommerce >/dev/null 2>&1 || wp plugin install woocommerce
wp plugin activate woocommerce >/dev/null

echo "==> Configuring the store"
wp option update woocommerce_store_address "Testikatu 1"     >/dev/null
wp option update woocommerce_store_city "Helsinki"           >/dev/null
wp option update woocommerce_store_postcode "00100"          >/dev/null
wp option update woocommerce_default_country "${WC_COUNTRY:-FI}" >/dev/null
wp option update woocommerce_currency "${WC_CURRENCY:-EUR}"  >/dev/null
wp option update woocommerce_calc_taxes "yes"                >/dev/null
# Silence the setup wizard so wp-admin lands where the e2e suite expects.
wp option update woocommerce_onboarding_profile '{"completed":true,"skipped":true}' --format=json >/dev/null

# Take the store out of "Coming soon" mode (WooCommerce's Launch Your Store).
# New stores default to it, and it serves a placeholder page to anyone who is
# not logged in -- including the order-received page, which is exactly what the
# customer-facing tracking tests fetch anonymously.
wp option update woocommerce_coming_soon no >/dev/null
wp option update woocommerce_store_pages_only no >/dev/null
wp option patch update woocommerce_task_list_hidden_lists 0 setup >/dev/null 2>&1 || true
wp option update woocommerce_admin_notices '[]' --format=json >/dev/null

# WooCommerce writes an email's settings option only once the merchant saves it
# in wp-admin, so on a fresh store it does not exist and the e2e suite cannot
# switch the completed-order email between HTML and plain text. Seed it.
wp option update woocommerce_customer_completed_order_settings \
	'{"enabled":"yes","email_type":"html"}' --format=json >/dev/null

# Cash on delivery gives us a checkout that completes without a real gateway.
wp option patch update woocommerce_cod_settings enabled yes >/dev/null 2>&1 \
	|| wp option update woocommerce_cod_settings '{"enabled":"yes","title":"Cash on delivery"}' --format=json >/dev/null

echo "==> Enabling HPOS (custom order tables)"
# Default the dev store to HPOS, since that is what real stores now run. The
# integration suite flips it back off to cover the legacy post-table path.
#
# Two separate switches, easy to confuse: the *feature* flag only reveals the
# setting in wp-admin, while `woocommerce_custom_orders_table_enabled` is the
# one CustomOrdersTableController actually reads. Setting only the first leaves
# HPOS reported as off.
wp option update woocommerce_feature_custom_order_tables_enabled yes >/dev/null
wp wc hpos enable --with-sync --ignore-plugin-compatibility --user=1 >/dev/null 2>&1 || true

echo "==> Activating the OGOship plugin"
wp plugin activate ogoship-for-woocommerce >/dev/null

echo "==> Seeding products and orders"
wp eval-file /seed/products.php
wp eval-file /seed/orders.php

echo "==> Creating a WooCommerce REST key for the simulated OGOship server"
wp eval-file /seed/api-key.php > "$DOCKER_DIR/.api-keys"
chmod 600 "$DOCKER_DIR/.api-keys"

echo
echo "=========================================================="
echo " Store       : $WP_URL"
echo " Admin       : $WP_URL/wp-admin  (${WP_ADMIN_USER:-admin} / ${WP_ADMIN_PASSWORD:-admin})"
echo " Mailpit     : http://localhost:${MAILPIT_PORT:-8026}"
echo "=========================================================="
echo " The certificate is self-signed by Caddy's local CA:"
echo "   curl -k ...   /   ignoreHTTPSErrors in Playwright"
echo "=========================================================="
echo "WooCommerce REST credentials for simulating the OGOship server"
echo "(also saved to .docker/.api-keys):"
cat "$DOCKER_DIR/.api-keys"
echo
echo "Next: bin/seed-tracking.sh <order_id>   # writes tracking meta as RapidWarehouse would"
