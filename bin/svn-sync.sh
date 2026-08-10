#!/usr/bin/env bash
# Stage this working tree into the WordPress.org SVN working copy.
#
# Copies the distributable plugin files into <wc>/trunk and the directory
# graphics from .wordpress-org into <wc>/assets, then reconciles SVN adds and
# deletes. It never commits: the commit is the public, irreversible step and
# stays a human decision.
#
# Usage (from anywhere):
#   ./bin/svn-sync.sh [path-to-svn-working-copy]
#
# Default working copy: ../checkout-script-monitor-svn
set -euo pipefail
cd "$(dirname "$0")/.."          # plugin repo root
REPO="$(pwd)"

WC="${1:-$REPO/../checkout-script-monitor-svn}"
[ -d "$WC" ] || { echo "error: no such directory: $WC" >&2; exit 1; }
WC="$(cd "$WC" && pwd)"
svn info "$WC" >/dev/null 2>&1 || { echo "error: not an SVN working copy: $WC" >&2; exit 1; }

MAIN_FILE="$(ls ./*.php | grep -v uninstall.php | head -1)"
SLUG="$(basename "$MAIN_FILE" .php)"

# --- Guard: the three version strings must agree -----------------------------
# WordPress.org serves whatever /tags/<Stable tag> holds. A mismatch here ships
# the wrong code or a version users can never update to.
header_version="$(sed -n -E 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*(.+)$/\1/p' "$MAIN_FILE" | head -1 | tr -d '[:space:]')"
const_version="$(sed -n -E "s/.*'CSCSM_VERSION',[[:space:]]*'([^']+)'.*/\1/p" "$MAIN_FILE" | head -1)"
stable_tag="$(sed -n -E 's/^Stable tag:[[:space:]]*(.+)$/\1/p' readme.txt | head -1 | tr -d '[:space:]')"

fail=0
for pair in "plugin header:$header_version" "CSCSM_VERSION:$const_version" "readme Stable tag:$stable_tag"; do
  [ -n "${pair#*:}" ] || { echo "error: could not read ${pair%%:*}" >&2; fail=1; }
done
if [ "$header_version" != "$const_version" ] || [ "$header_version" != "$stable_tag" ]; then
  echo "error: version mismatch" >&2
  echo "  plugin header:      $header_version" >&2
  echo "  CSCSM_VERSION:      $const_version" >&2
  echo "  readme Stable tag:  $stable_tag" >&2
  fail=1
fi
grep -q "^= ${header_version} =\$" readme.txt || {
  echo "error: readme.txt has no '= ${header_version} =' changelog entry" >&2; fail=1; }
[ "$fail" -eq 0 ] || exit 1
VERSION="$header_version"

# --- Stage trunk and assets --------------------------------------------------
# --delete makes the trunk an exact mirror: a file removed from the repo is
# removed from the release, not silently left behind.
rsync -a --delete --exclude-from bin/dist-excludes.txt ./ "$WC/trunk/"
rsync -a --delete --exclude '.svn' .wordpress-org/ "$WC/assets/"

# --- Reconcile SVN adds and deletes ------------------------------------------
cd "$WC"
svn add --force . --auto-props --parents --depth infinity -q
missing="$(svn status | awk '/^!/ { print substr($0, 9) }')"
if [ -n "$missing" ]; then
  printf '%s\n' "$missing" | while IFS= read -r path; do svn rm --force "$path"; done
fi

echo
echo "Staged $SLUG $VERSION into $WC"
echo
svn status
echo
echo "Next, from $WC:"
echo "  1. svn commit -m \"Release $VERSION\" --username dennishwu"
echo "  2. svn cp trunk \"tags/$VERSION\""
echo "  3. svn commit -m \"Tag $VERSION\" --username dennishwu"
