#!/usr/bin/env bash
#
# Build the distributable plugin zip.
#
#   bin/build.sh            -> dist/ogoship-for-woocommerce-<version>.zip
#
# The version is read from the plugin header, so there is exactly one place to
# bump it. Nothing here needs PHP on the host.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
SLUG="ogoship-for-woocommerce"
SRC="$ROOT_DIR/$SLUG"
DIST="$ROOT_DIR/dist"

version="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$SRC/$SLUG.php" | head -1 | tr -d '[:space:]')"
if [[ -z "$version" ]]; then
	echo "Could not read Version from $SRC/$SLUG.php" >&2
	exit 1
fi

# The readme's Stable tag is what wordpress.org actually serves. A mismatch
# silently ships the wrong version, so refuse to build.
stable="$(sed -n 's/^Stable tag:[[:space:]]*//p' "$SRC/readme.txt" | head -1 | tr -d '[:space:]')"
if [[ "$stable" != "$version" ]]; then
	echo "Version mismatch: plugin header says $version, readme.txt Stable tag says $stable." >&2
	exit 1
fi

echo "==> Building $SLUG $version"

staging="$(mktemp -d)"
trap 'rm -rf "$staging"' EXIT
target="$staging/$SLUG"

# Ship only what the plugin needs at runtime. Everything to do with testing,
# linting and local development stays out of the zip.
rsync -a \
	--exclude '.*' \
	--exclude 'tests/' \
	--exclude 'vendor/' \
	--exclude 'composer.json' \
	--exclude 'composer.lock' \
	--exclude 'phpcs.xml.dist' \
	--exclude 'phpstan.neon.dist' \
	--exclude 'phpunit.xml.dist' \
	"$SRC/" "$target/"

# One licence for the whole repository, copied in at build time so the zip
# still carries it.
cp "$ROOT_DIR/LICENSE" "$target/LICENSE"

mkdir -p "$DIST"
zip_path="$DIST/$SLUG-$version.zip"
rm -f "$zip_path"
( cd "$staging" && zip -qr "$zip_path" "$SLUG" )

echo "==> $zip_path"
unzip -l "$zip_path" | tail -1
