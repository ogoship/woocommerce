#!/usr/bin/env bash
#
# Build the distributable plugin zip.
#
#   bin/build.sh            -> dist/ogoship-for-woocommerce-<version>.zip
#                              plus a copy in the current directory
#
# Set TRACE=1 to echo every command as it runs.
#
# The version is read from the plugin header, so there is exactly one place to
# bump it. Nothing here needs PHP on the host.
#
set -Eeuo pipefail

# Without this, a missing tool or a failed copy exits with a bare status code
# and no clue as to which step gave up.
trap 'status=$?; echo "!! build failed (exit $status) at ${BASH_SOURCE[0]}:$LINENO: $BASH_COMMAND" >&2' ERR

if [[ -n "${TRACE:-}" ]]; then
	PS4='+ ${BASH_SOURCE[0]}:$LINENO: '
	set -x
fi

INVOKE_DIR="$PWD"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
SLUG="ogoship-for-woocommerce"
SRC="$ROOT_DIR/$SLUG"
DIST="$ROOT_DIR/dist"

# Git Bash on Windows ships no zip by default, and a half installed one (exe
# present, DLLs missing) starts and dies just the same. Either way the build
# otherwise fails with a bare "exit 127" mid-run.
problems=()
probe() {
	local tool="$1"
	shift
	if ! command -v "$tool" >/dev/null 2>&1; then
		problems+=("$tool: not found on PATH")
		return
	fi
	local rc=0
	"$tool" "$@" >/dev/null 2>&1 || rc=$?
	# 127 here means the exe is on PATH but the loader could not start it.
	if (( rc == 127 )); then
		problems+=("$tool: $(command -v "$tool") exists but will not run (missing DLLs?)")
	fi
}
probe zip -h
probe unzip -v
if (( ${#problems[@]} )); then
	echo "Cannot build, required tools are unusable:" >&2
	printf '  %s\n' "${problems[@]}" >&2
	echo "On Git Bash for Windows, install a complete build (e.g. MSYS2: pacman -S zip unzip)." >&2
	exit 127
fi

echo "==> Source:      $SRC"
echo "==> Destination: $DIST"

version="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$SRC/$SLUG.php" | head -1 | tr -d '[:space:]')"
if [[ -z "$version" ]]; then
	echo "Could not read Version from $SRC/$SLUG.php" >&2
	exit 1
fi

# The readme's Stable tag is what wordpress.org actually serves, and the VERSION
# constant is what the plugin reports over REST and prints on the status screen.
# Either one drifting out of step ships a build that lies about itself, so check
# both against the header and refuse to build on a mismatch.
mismatches=()
check_version() {
	local what="$1" found="$2"
	if [[ -z "$found" ]]; then
		mismatches+=("$what: not found")
	elif [[ "$found" != "$version" ]]; then
		mismatches+=("$what: $found")
	fi
}
check_version "readme.txt Stable tag" \
	"$(sed -n 's/^Stable tag:[[:space:]]*//p' "$SRC/readme.txt" | head -1 | tr -d '[:space:]')"
check_version "$SLUG.php VERSION constant" \
	"$(sed -n "s/^const VERSION[[:space:]]*=[[:space:]]*'\([^']*\)'.*/\1/p" "$SRC/$SLUG.php" | head -1 | tr -d '[:space:]')"
if (( ${#mismatches[@]} )); then
	echo "Version mismatch: plugin header says $version, but:" >&2
	printf '  %s\n' "${mismatches[@]}" >&2
	exit 1
fi

echo "==> Building $SLUG $version"

staging="$(mktemp -d)"
trap 'rm -rf "$staging"' EXIT
target="$staging/$SLUG"

# Ship only what the plugin needs at runtime. Everything to do with testing,
# linting and local development stays out of the zip.
#
# Copy first and prune after, rather than rsync --exclude: Git Bash on Windows
# has no rsync of its own, and a borrowed MSYS2 one re-globs its command line,
# so '.*' silently expands to '..' and drags the whole repository in.
echo "==> Staging files in $target"
mkdir -p "$target"
cp -a "$SRC/." "$target/"

echo "==> Pruning development files"
# Dot files at any depth, plus the zip a previous run may have left in place.
find "$target" -depth \( -name '.*' -o -name "$SLUG-*.zip" \) -print -exec rm -rf {} +
for path in tests vendor composer.json composer.lock \
	phpcs.xml.dist phpstan.neon.dist phpunit.xml.dist; do
	if [[ -e "$target/$path" ]]; then
		rm -rfv "$target/$path"
	fi
done

# One licence for the whole repository, copied in at build time so the zip
# still carries it.
echo "==> Adding LICENSE"
cp -v "$ROOT_DIR/LICENSE" "$target/LICENSE"

mkdir -p "$DIST"
zip_path="$DIST/$SLUG-$version.zip"
rm -f "$zip_path"
echo "==> Zipping $zip_path"
( cd "$staging" && zip -r "$zip_path" "$SLUG" )

echo "==> $zip_path"
unzip -l "$zip_path" | tail -1

# Drop a copy where the script was run from, so the zip is at hand without
# digging into dist/.
if [[ "$INVOKE_DIR" != "$DIST" ]]; then
	cp -v "$zip_path" "$INVOKE_DIR/$SLUG-$version.zip"
	echo "==> $INVOKE_DIR/$SLUG-$version.zip"
fi
