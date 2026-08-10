#!/usr/bin/env bash
# Build a distributable plugin zip for CyberShield Checkout Script Monitor.
#
# Produces dist/<slug>.zip with the plugin folder as the archive's top-level
# directory (what WordPress expects at Plugins -> Add New -> Upload Plugin),
# excluding dev-only files. Slug is taken from the main plugin file's name, so
# it stays correct even though the repo folder has a different (historical)
# name. The tree is staged into a temp dir named <slug> so the archive's top
# level matches the slug exactly.
#
# Usage (from anywhere):
#   ./bin/build-zip.sh
set -euo pipefail
cd "$(dirname "$0")/.."          # plugin repo root

MAIN_FILE="$(ls ./*.php | grep -v uninstall.php | head -1)"
SLUG="$(basename "$MAIN_FILE" .php)"
OUT_DIR="$(pwd)/dist"
OUT="$OUT_DIR/$SLUG.zip"
mkdir -p "$OUT_DIR"
rm -f "$OUT"

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir "$STAGE/$SLUG"

# Copy the distributable tree, excluding VCS, dev tooling, and build output.
# The exclusion list is shared with bin/svn-sync.sh so the ZIP and the
# WordPress.org trunk always contain the same files.
rsync -a --exclude-from bin/dist-excludes.txt ./ "$STAGE/$SLUG/"

( cd "$STAGE" && zip -r -q "$OUT" "$SLUG" )

echo "Built: dist/$SLUG.zip"
echo "Upload it: wp-admin -> Plugins -> Add New -> Upload Plugin -> choose dist/$SLUG.zip -> Activate"
