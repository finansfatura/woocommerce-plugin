#!/usr/bin/env bash
# Builds the WordPress.org release zip: finansfatura-for-woocommerce.zip with a
# single top-level folder named after the wp.org slug. Everything listed in
# .distignore (tests, phpunit config, git metadata, composer.lock) is left out —
# wp.org's automated check rejects "application files" such as phpunit.xml.dist.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="finansfatura-for-woocommerce"
OUT="${1:-$ROOT/dist}"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$OUT" "$STAGE/$SLUG"
rsync -a --exclude-from="$ROOT/.distignore" --exclude 'dist' "$ROOT/" "$STAGE/$SLUG/"

# Only the runtime dependency belongs in the zip; dev packages must not leak in.
if [ -d "$STAGE/$SLUG/vendor" ]; then
  find "$STAGE/$SLUG/vendor" -mindepth 1 -maxdepth 1 -type d \
    ! -name composer ! -name finansfatura -exec rm -rf {} +
fi

rm -f "$OUT/$SLUG.zip"
(cd "$STAGE" && zip -qr "$OUT/$SLUG.zip" "$SLUG" -x '*.DS_Store')
echo "Built $OUT/$SLUG.zip"
unzip -l "$OUT/$SLUG.zip" | tail -1
