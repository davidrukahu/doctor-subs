#!/usr/bin/env bash
#
# Doctor Subs - local release zip build.
#
# Creates a shippable plugin zip in /tmp that mirrors what the
# GitHub Actions release workflow produces. Use for local smoke tests
# before tagging.
#
# Usage:
#   ./scripts/build-zip.sh               # uses version from doctor-subs.php header
#   ./scripts/build-zip.sh 2.0.0         # override version string
#
# Output:
#   /tmp/doctor-subs-<VERSION>.zip

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

VERSION="${1:-}"
if [[ -z "$VERSION" ]]; then
	VERSION=$(grep -oE "Version:[[:space:]]*[^[:space:]]+" doctor-subs.php | head -1 | awk '{print $2}')
fi
if [[ -z "$VERSION" ]]; then
	echo "Could not determine version" >&2
	exit 1
fi

ZIP="/tmp/doctor-subs-${VERSION}.zip"
BUILD_DIR="/tmp/dr-subs-build/doctor-subs"

echo "==> Building doctor-subs ${VERSION}"

rm -rf "/tmp/dr-subs-build"
mkdir -p "$BUILD_DIR"

rsync -a \
	--exclude=".git/" \
	--exclude=".github/" \
	--exclude=".claude/" \
	--exclude=".taskmaster/" \
	--exclude=".cursor/" \
	--exclude=".impeccable.md" \
	--exclude=".env.example" \
	--exclude=".gitignore" \
	--exclude=".DS_Store" \
	--exclude="branding/" \
	--exclude="build/" \
	--exclude="composer.json" \
	--exclude="composer.lock" \
	--exclude="design-brief/" \
	--exclude="node_modules/" \
	--exclude="phpcs.xml" \
	--exclude="scripts/" \
	--exclude="tests/" \
	--exclude="TODOS.md" \
	--exclude="v2-STATUS.md" \
	--exclude="_review.md" \
	--exclude="vendor/" \
	--exclude="*.zip" \
	./ "$BUILD_DIR/"

cd /tmp/dr-subs-build
rm -f "$ZIP"
zip -qr "$ZIP" doctor-subs
cd - >/dev/null

SIZE=$(du -h "$ZIP" | cut -f1)
FILES=$(unzip -l "$ZIP" | tail -1 | awk '{print $2}')

echo "==> Built: $ZIP"
echo "    Size:  $SIZE"
echo "    Files: $FILES"
echo ""
echo "To smoke-test in a local WP install:"
echo "    wp plugin install $ZIP --activate"
