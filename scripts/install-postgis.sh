#!/bin/sh
# Installs PostGIS for this app's PostgreSQL server and enables the
# extension in the configured database.
#
# Requires sudo. Run once per environment, then set
# ADDRESSING_SPATIAL_POSTGIS=true in .env and run the addressing migration.
set -eu

REPO_ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$REPO_ROOT"

if [ ! -f artisan ] || [ ! -f .env ]; then
    echo "Run this from the Laravel app root (artisan and .env required)." >&2
    exit 1
fi

DB_CONNECTION=$(sed -n 's/^DB_CONNECTION=//p' .env | tail -1 | tr -d '"' | tr -d "'")

if [ "$DB_CONNECTION" != "pgsql" ]; then
    echo "DB_CONNECTION is '${DB_CONNECTION:-unset}'; PostGIS is only installed for pgsql." >&2
    exit 1
fi

DB_DATABASE=$(sed -n 's/^DB_DATABASE=//p' .env | tail -1 | tr -d '"' | tr -d "'")

if [ -z "$DB_DATABASE" ]; then
    echo "DB_DATABASE is not set in .env." >&2
    exit 1
fi

PG_MAJOR=$(psql --version | sed -E 's/^psql \(PostgreSQL\) ([0-9]+).*/\1/')

if ! printf '%s' "$PG_MAJOR" | grep -Eq '^[0-9]+$'; then
    echo "Could not determine the PostgreSQL major version from: $(psql --version)" >&2
    exit 1
fi

echo "==> Installing PostgreSQL ${PG_MAJOR} and PostGIS"
sudo apt-get update -qq
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y "postgresql-${PG_MAJOR}" "postgresql-${PG_MAJOR}-postgis-3"

echo "==> Creating the postgis extension in ${DB_DATABASE}"
sudo -u postgres psql -d "$DB_DATABASE" -v ON_ERROR_STOP=1 -c 'CREATE EXTENSION IF NOT EXISTS postgis;'

echo "==> Verifying PostGIS"
sudo -u postgres psql -d "$DB_DATABASE" -tAc 'SELECT postgis_lib_version();'

cat <<EOF

PostGIS is installed in ${DB_DATABASE}.
Next:
  1. Set ADDRESSING_SPATIAL_POSTGIS=true in .env
  2. Run: php artisan config:clear && php artisan migrate --force
EOF
