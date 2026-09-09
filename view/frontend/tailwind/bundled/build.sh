#!/bin/sh
# Builds the scoped fallback stylesheet view/frontend/web/css/aiagent.css.
# Usage: sh build.sh <path to a Hyva theme web/tailwind directory with node_modules>
set -e
HERE="$(cd "$(dirname "$0")" && pwd)"
THEME_TAILWIND="$(cd "${1:?path to a Hyva theme web/tailwind directory}" && pwd)"
OUT="$HERE/../../web/css/aiagent.css"
ln -sfn "$THEME_TAILWIND/node_modules" "$HERE/node_modules"
trap 'rm -f "$HERE/node_modules" "$HERE/raw.css"' EXIT
"$THEME_TAILWIND/node_modules/.bin/tailwindcss" --input "$HERE/input.css" --output "$HERE/raw.css" --minify
php "$HERE/scope.php" "$HERE/raw.css" > "$OUT"
echo "wrote $OUT ($(wc -c < "$OUT") bytes)"
