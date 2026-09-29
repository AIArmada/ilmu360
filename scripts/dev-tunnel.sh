#!/bin/sh
# Starts the ilmu360-local-dev Cloudflare Tunnel in the foreground.
#
# The tunnel exposes this machine's Herd site as https://dev.ilmu360.com so
# Google OAuth has a publicly valid HTTPS callback during local development.
# Normal development stays on https://ilmu360.test.
#
# Usage: ./scripts/dev-tunnel.sh   (or: composer tunnel)
# Stop:  Ctrl-C
set -e

TOKEN_FILE="$HOME/.cloudflared/ilmu360-tunnel-token"

if [ ! -f "$TOKEN_FILE" ]; then
    TOKEN_FILE="$(dirname "$0")/../.cloudflared/ilmu360-tunnel-token"
fi

if [ ! -f "$TOKEN_FILE" ]; then
    echo "Missing tunnel token." >&2
    echo "Run: cloudflared tunnel token ilmu360-local-dev > ~/.cloudflared/ilmu360-tunnel-token && chmod 600 ~/.cloudflared/ilmu360-tunnel-token" >&2
    exit 1
fi

exec cloudflared tunnel --no-autoupdate run --token-file "$TOKEN_FILE"
