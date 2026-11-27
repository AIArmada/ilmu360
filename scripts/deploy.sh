#!/bin/sh
# Applies this checkout's code changes to the self-managed staging vhost.
#
# The vhost is served by Octane/FrankenPHP on 127.0.0.1:8450 (nginx proxies to
# it), so deploy must reload Octane workers and Horizon after the caches change.
# The FrankenPHP config is tracked in scripts/frankenphp/Caddyfile and synced
# into storage because Octane reads it from there.
#
# Static assets are served straight from disk; run `npm run build` first when
# the change touches resources/.
#
# Usage: ./scripts/deploy.sh                 (or: composer deploy)
set -e

REPO_ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$REPO_ROOT"

if [ ! -f artisan ]; then
    echo "No artisan file in $REPO_ROOT — not a Laravel root." >&2
    exit 1
fi

echo "==> Ensuring the pinned FrankenPHP binary"
"$REPO_ROOT/scripts/install-frankenphp.sh"

CADDYFILE="storage/app/frankenphp/Caddyfile"
CADDYFILE_CHANGED=0
if [ ! -f "$CADDYFILE" ] || ! cmp -s "$REPO_ROOT/scripts/frankenphp/Caddyfile" "$CADDYFILE"; then
    cp "$REPO_ROOT/scripts/frankenphp/Caddyfile" "$CADDYFILE"
    CADDYFILE_CHANGED=1
fi

echo "==> Clearing Laravel caches"
php artisan optimize:clear --ansi

echo "==> Running database migrations"
php artisan migrate --force --ansi

echo "==> Publishing Filament assets for the installed packages"
php artisan filament:assets --no-interaction --ansi

echo "==> Rebuilding optimized caches (config, routes, events, views, Filament components)"
php artisan optimize --ansi
php artisan filament:optimize --ansi

if [ "$CADDYFILE_CHANGED" = "1" ]; then
    echo "==> Restarting Octane for the new FrankenPHP config"
    php artisan octane:stop --no-interaction || true
else
    echo "==> Reloading Octane workers"
    php artisan octane:reload --no-interaction || true
fi

echo "==> Restarting Horizon workers"
php artisan horizon:terminate || true

echo "==> Done. Staging now serves the current working tree."
