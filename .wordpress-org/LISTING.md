# wordpress.org listing — submission pack

Everything needed to submit **OGOship for WooCommerce** to the WordPress Plugin
Directory, and to keep the listing current afterwards.

The listing body itself is **not written here** — it lives in
`ogoship-for-woocommerce/readme.txt`, which is what wordpress.org renders. This
file holds the things that go in the submission form, the assets, and the
process notes.

---

## 1. Submission form

Submit at <https://wordpress.org/plugins/developers/add/> from the **`ogoship`**
wordpress.org account (the same one that owns the 3.x plugin — keeping both
under one account means the old listing can point at the new one).

| Field | Value |
|---|---|
| Plugin name | `OGOship for WooCommerce` |
| Plugin slug | `ogoship-for-woocommerce` — derived from the name by the review team; verified unused 2026-07-29 |
| Plugin description | The short description below |
| Plugin ZIP | `dist/ogoship-for-woocommerce-1.0.0.zip`, built with `bin/build.sh` |

### Short description (150 char limit)

Used under the plugin title in search results. The `readme.txt` line directly
under the header block must match this exactly.

> Companion plugin for the OGOship fulfillment service. Adds OGOship product fields and shows shipment tracking to your customers.

(128 characters.)

### Tags

`ogoship`, `fulfillment`, `3PL`, `logistics`, `shipment tracking`

Five is the maximum wordpress.org indexes. Deliberately **not** using
`woocommerce` — it is one of the most contested tags in the directory and adds
nothing, since the plugin name already carries it.

---

## 2. Assets

Files here are copied into the plugin's SVN **`assets/`** directory, not into
the plugin zip. They are versioned independently of the plugin, so a screenshot
can be refreshed without cutting a release.

### Screenshots — ready

Regenerate with:

```bash
bin/setup.sh
bin/wp eval-file /seed/screenshot-state.php
node bin/screenshots.mjs
```

`screenshot-N.png` pairs with the Nth line of the readme's `== Screenshots ==`
section, so **the order of that list and the numbering here must stay in step.**

| File | Caption in readme.txt |
|---|---|
| `screenshot-1.png` | The OGOship tab on the product edit screen. |
| `screenshot-2.png` | OGOship fields on an individual variation, showing the inherited value. |
| `screenshot-3.png` | Tracking as the customer sees it on the order page. |
| `screenshot-4.png` | The connection status panel at WooCommerce → Status → OGOship. |

### Banner and icon — still needed

Not generated here: these are brand assets and should come from whoever owns
the OGOship visual identity, not be invented by the plugin build.

| File | Size | Where it shows |
|---|---|---|
| `banner-772x250.png` | 772 × 250 | Top of the plugin page |
| `banner-1544x500.png` | 1544 × 500 | Same, retina |
| `icon-128x128.png` | 128 × 128 | Search results and the WP-admin plugin installer |
| `icon-256x256.png` | 256 × 256 | Same, retina |

Notes for whoever produces them: the banner is heavily cropped on narrow
screens, so keep the logo and any text well inside the middle third. The icon is
rendered as small as 64 px in the installer — a wordmark will not survive; use
the mark alone.

---

## 3. Review notes

Things the plugin review team commonly flag, and where this plugin stands:

- **Licence.** MIT, declared in the plugin header, `readme.txt` and
  `composer.json`, with the full text in `LICENSE` inside the zip. MIT is
  GPL-compatible, which is the directory's requirement.
- **No external requests on install or activation.** The plugin makes no
  outbound HTTP calls at all. OGOship calls *in*, over the store's own
  WooCommerce REST API.
- **No tracking, no analytics, no phone-home.** Nothing is collected or
  transmitted.
- **No bundled third-party code.** No `vendor/` in the zip — the plugin
  autoloads its own classes, and Composer is a development dependency only.
- **Trademark use.** "WooCommerce" appears in the `X for WooCommerce` form the
  directory permits, and never as the leading word. "OGOship" is the submitter's
  own mark.
- **Sanitization and escaping.** Enforced by PHPCS with the WordPress and
  WooCommerce rulesets in CI; every input is sanitized on save and every output
  escaped at the point of use.
- **Data on uninstall.** The plugin intentionally leaves the `_nettivarasto_*`
  product meta in place: it is the merchant's own catalogue data, shared with
  the 3.x plugin and read by OGOship's servers. Deleting it on uninstall would
  destroy warehouse data. This is worth stating in the review reply if asked why
  there is no `uninstall.php`.

---

## 4. After approval

1. Check out the SVN repo:
   `svn co https://plugins.svn.wordpress.org/ogoship-for-woocommerce`
2. Copy the built plugin into `trunk/`, and this directory's PNGs into `assets/`.
3. `svn cp trunk tags/1.0.0`, then commit both.
4. Confirm the live listing serves the version in the readme's `Stable tag`.
   `bin/build.sh` refuses to build when the tag and the plugin header disagree,
   which is the mistake that would otherwise ship the wrong version.

### Deprecating the 3.x listing

The old plugin (`ogoship-nettivarasto-api-for-woocommerce`, 3.8.0, 20+ active
installs) stays published — pulling it would break those stores. Instead:

1. Add an `== Upgrade Notice ==` to its `readme.txt` pointing at the new plugin,
   and open its description with a deprecation paragraph.
2. Do **not** auto-update those installs onto the new plugin. Different slug,
   and the new one deliberately does less: any store still relying on the 3.x
   order push must move to the server-side integration first.
3. Once the remaining installs have migrated, close the old listing.

The migration itself is lossless — both plugins store the same product meta
under the same keys — so a merchant installs the new plugin, deactivates the
old, and keeps their data. The new plugin detects the old one and offers a
one-click deactivation to make that the obvious path.
