# OGOship for WooCommerce

WordPress plugin that complements the OGOship fulfillment integration.

This repository holds two plugins:

| Directory | Status |
|---|---|
| `ogoship-for-woocommerce/` | **Current.** Companion plugin for the server-side OGOship integration. |
| `woocommerce-nettivarasto-api/` | **Legacy (3.x).** Order push over the old `my.ogoship.com` API. Superseded; kept for reference and for merchants who have not migrated. |

## What the current plugin does

OGOship integrates with WooCommerce **server-side**: it calls this store's
`wc/v3` REST API with a consumer key. Orders, stock and fulfillment all flow
through that connection, with no plugin involved.

So this plugin does only the two things the REST API cannot do on its own:

1. **OGOship product fields.** EAN code, HS code, country of origin, supplier
   details, customs description, purchase price, product group and an
   exclude-from-OGOship flag — on products and on individual variations. These
   are stored as `_nettivarasto_*` post meta and read by OGOship over the REST
   API.
2. **Customer-facing tracking.** Shows the tracking number, link and shipment
   status that OGOship writes onto the order — on the My Account order page,
   the order-received page, and in order emails.

It also exposes `GET /wp-json/ogoship/v1/info` so the OGOship server can
discover the plugin version and capabilities, and adds a
**WooCommerce → Status → OGOship** panel showing whether OGOship is actually
talking to the store.

It never pushes orders, never writes stock, and stores no OGOship credentials.

### Meta key compatibility

The `_nettivarasto_*` prefix is a legacy of the pre-2018 product name and is
kept **verbatim**. The exact key names are a wire contract with two server-side
consumers, so a store can move from the 3.x plugin to this one with no data
migration:

- `ECom/WooCommerceConnector/WooCommerceProductInfoProvider.cs`
- `RapidWarehouse/WooCommerce.Models/Const/WooSettings.cs`

The same applies to the order meta this plugin reads —
`ogoship_tracking`, `ogoship_tracking_url`, `_ogoship_tracking_status` and the
legacy `nettivarasto_tracking`.

## Local development

Requires Docker. PHP and Composer are **not** needed on the host — everything
runs in containers.

```bash
bin/setup.sh          # provision a full WooCommerce store from nothing
```

That gives you:

| | |
|---|---|
| Store / admin | `https://localhost:8443` — `admin` / `admin` |
| Mailpit (catches all outgoing mail) | `http://localhost:8026` |
| REST credentials for simulating OGOship | printed at the end, saved to `.docker/.api-keys` |

The plugin directory is bind-mounted into the container, so edits on the host
take effect on the next request. No rebuild, no copy step.

### Why HTTPS

The store is served over TLS by a Caddy container. This is not optional:
WooCommerce authenticates REST consumer keys only when `is_ssl()` is true
(`WC_REST_Authentication::authenticate`). Older versions allowed plain HTTP when
`WP_DEBUG` was on, but WooCommerce 10.x removed that. Since OGOship reaches
stores exclusively through key-authenticated `wc/v3` calls, a plain-HTTP dev
store cannot exercise a single realistic request.

The certificate is self-signed by Caddy's local CA, so use `curl -k` and
`ignoreHTTPSErrors` in Playwright.

### Everyday commands

```bash
bin/wp <command>              # WP-CLI inside the container, e.g. bin/wp plugin list
bin/seed-tracking.sh 17       # write tracking meta onto order 17 the way OGOship does
bin/composer install          # dev toolchain (phpcs, phpstan, phpunit)
bin/reset.sh                  # destroy the store and start over
```

`bin/seed-tracking.sh` is the important one: it reproduces what
RapidWarehouse's `CompleteOrderAsync` does to an order, which is what makes the
tracking display testable without OGOship in the loop.

### Seeded fixtures

`bin/setup.sh` creates products and orders chosen to cover the awkward cases:

| SKU / order | Covers |
|---|---|
| `TEST-001` | product with no OGOship meta at all |
| `TEST-002` | product with every OGOship field filled in |
| `TEST-VAR` + `TEST-VAR-{S,M,L}` | variable product; only `L` has its own EAN, so `S`/`M` exercise parent inheritance |
| order `completed` | the one to point `bin/seed-tracking.sh` at |
| order `legacy-tracking` | carries only `nettivarasto_tracking`, proving the 3.x fallback still renders |

## Release assets

The wordpress.org listing lives in two places: `ogoship-for-woocommerce/readme.txt`
is the page body, and `.wordpress-org/` holds everything that goes into the
plugin's SVN `assets/` directory. `.wordpress-org/LISTING.md` is the submission
pack — form fields, review notes, and the plan for deprecating the 3.x listing.

Regenerate the screenshots after any UI change:

```bash
bin/wp eval-file /seed/screenshot-state.php   # plausible demo data
node bin/screenshots.mjs                      # -> .wordpress-org/screenshot-N.png
```

`screenshot-N.png` pairs with the Nth line of the readme's `== Screenshots ==`
section, so keep the two in step.

Build the distributable zip with `bin/build.sh`.

## Quality checks

```bash
bin/composer install
bin/composer lint       # PHPCS: WordPress + WooCommerce standards
bin/composer analyse    # PHPStan level 6
bin/composer test       # PHPUnit unit tests
```

## License

MIT. See `LICENSE`. (MIT is GPL-compatible, which is what wordpress.org
requires of hosted plugins.)
