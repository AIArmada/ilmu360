#!/bin/sh
# Applies this checkout's code changes to the self-managed staging vhost.
#
# The vhost serves this working tree directly (nginx root = public/), so there
# is nothing to copy. What does need to happen is:
#
#   1. Laravel caches (config/route/view/compiled) are cleared.
#   2. PHP-FPM is reloaded so OPcache drops stale bytecode.
#
# Step 2 is not optional: /etc/php/8.5/fpm/php.ini sets
# opcache.validate_timestamps = 0, so edited PHP and compiled Blade views are
# served from cache until the pool is reloaded. Clearing caches alone rewrites
# the compiled view at the same path, which the cached Opcodes still shadow.
#
# Static assets are served straight from disk; run `npm run build` first when
# the change touches resources/.
#
# Usage: ./scripts/deploy.sh                 (or: composer deploy)
#        PHP_FPM_SERVICE=php8.4-fpm ./scripts/deploy.sh
set -e

REPO_ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$REPO_ROOT"

if [ ! -f artisan ]; then
    echo "No artisan file in $REPO_ROOT — not a Laravel root." >&2
    exit 1
fi

# Prefer the FPM build that matches the CLI PHP, unless one is forced.
if [ -z "${PHP_FPM_SERVICE:-}" ]; then
    PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
    PHP_FPM_SERVICE="php${PHP_VERSION}-fpm"
fi

echo "==> Clearing Laravel caches"
php artisan optimize:clear --ansi

echo "==> Reloading ${PHP_FPM_SERVICE} (resets OPcache)"
if [ -t 0 ]; then
    sudo systemctl reload "$PHP_FPM_SERVICE"
else
    # No TTY: never block waiting for a password prompt.
    sudo -n systemctl reload "$PHP_FPM_SERVICE"
fi

echo "==> Done. Staging now serves the current working tree."
