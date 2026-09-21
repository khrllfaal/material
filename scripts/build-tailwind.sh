#!/bin/sh
# Rebuild frontend/vendor/tailwind.css after changing Tailwind classes in
# frontend/*.html. The app ships a static, pre-built CSS file (no CDN, no
# runtime JIT) so it stays fast and stays styled even on a bad connection —
# but that means new classes you type in the HTML won't appear until you
# rerun this script.
set -e
cd "$(dirname "$0")/.."
npx --yes tailwindcss@^3 \
  -c scripts/tailwind.config.js \
  -i scripts/tailwind.input.css \
  -o frontend/vendor/tailwind.css \
  --minify
echo "Rebuilt frontend/vendor/tailwind.css"
