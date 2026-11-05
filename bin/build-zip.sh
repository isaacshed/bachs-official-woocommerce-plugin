#!/usr/bin/env bash
# Build the WordPress.org release zip from the committed tree.
#
# Usage: bin/build-zip.sh            (builds the current HEAD)
#        bin/build-zip.sh v1.0.0     (builds a tag or commit)
#
# Output: dist/bachs-payment-gateway-for-woocommerce-<version>.zip, containing a single
# bachs-payment-gateway-for-woocommerce/ folder. Dev files are excluded via .gitattributes.
set -euo pipefail

SLUG="bachs-payment-gateway-for-woocommerce"
REF="${1:-HEAD}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

main_version="$(git show "$REF:$SLUG.php" | sed -n 's/^[ *]*Version:[[:space:]]*//p' | head -1 | tr -d '\r')"
const_version="$(git show "$REF:$SLUG.php" | sed -n "s/.*define( 'BACHS_WC_VERSION', '\([^']*\)'.*/\1/p")"
stable_tag="$(git show "$REF:readme.txt" | sed -n 's/^Stable tag:[[:space:]]*//p' | tr -d '\r')"

if [[ "$main_version" != "$const_version" || "$main_version" != "$stable_tag" ]]; then
	echo "Version mismatch: header=$main_version constant=$const_version stable tag=$stable_tag" >&2
	exit 1
fi

if [[ -n "$(git status --porcelain)" && "$REF" == "HEAD" ]]; then
	echo "Warning: uncommitted changes are NOT included in the zip." >&2
fi

mkdir -p dist
OUT="dist/$SLUG-$main_version.zip"
rm -f "$OUT"
git archive --format=zip --prefix="$SLUG/" -o "$OUT" "$REF"

echo "$OUT"
unzip -l "$OUT" | tail -n +4 | head -n -2 | awk '{print $4}'
