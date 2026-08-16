#!/usr/bin/env bash
# Regenerates assets/demo.webp: boots `php bin/tapper`, drives it with
# examples/BasicExample.php, and records the session with VHS.
#
# Requires: vhs (brew install vhs), webp (brew install webp) for gif2webp.
set -euo pipefail

cd "$(dirname "$0")/.."

if ! command -v vhs >/dev/null 2>&1; then
    echo "error: vhs not found — install it with 'brew install vhs'" >&2
    exit 1
fi

if ! command -v gif2webp >/dev/null 2>&1; then
    echo "error: gif2webp not found — install it with 'brew install webp'" >&2
    exit 1
fi

if [ ! -d vendor ]; then
    echo "==> Installing composer dependencies"
    composer install --no-interaction --no-progress
fi

# A previous run that crashed or was interrupted can leave a stale socket
# and a `php bin/tapper` process holding it open.
pkill -f 'php bin/tapper' 2>/dev/null || true
rm -f tapper.sock tapper.log

echo "==> Recording with VHS"
vhs assets/demo.tape

# VHS doesn't reliably reap the foreground `php bin/tapper` process when the
# recorded shell session tears down.
pkill -f 'php bin/tapper' 2>/dev/null || true
rm -f tapper.sock tapper.log

echo "==> Converting GIF to WebP"
gif2webp -q 80 assets/demo.gif -o assets/demo.webp
rm -f assets/demo.gif

echo "==> Done: assets/demo.webp updated"
