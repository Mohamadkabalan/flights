#!/usr/bin/env bash
#
# Container entrypoint for the web service.
#
# Makes `docker compose up` a true one-command start: it prepares the
# environment, waits for MySQL to accept connections, runs migrations, and then
# hands off to the container's main command (the artisan dev server).
set -e

cd /var/www/html

# 1. Ensure a .env exists. On a fresh clone only .env.example is present.
if [ ! -f .env ]; then
    echo "[entrypoint] No .env found — copying from .env.example"
    cp .env.example .env
fi

# 2. Generate an APP_KEY if it is empty (idempotent: only sets it once).
if ! grep -q "^APP_KEY=base64:" .env; then
    echo "[entrypoint] Generating application key"
    php artisan key:generate --force
fi

# 3. Wait for MySQL to be reachable before migrating. The DB container may take
#    a few seconds to accept connections even after the port is open.
echo "[entrypoint] Waiting for MySQL at ${DB_HOST:-mysql}:${DB_PORT:-3306}..."
until php -r '
    $h = getenv("DB_HOST") ?: "mysql";
    $p = getenv("DB_PORT") ?: "3306";
    exit(@fsockopen($h, (int)$p) ? 0 : 1);
' 2>/dev/null; do
    sleep 2
done
echo "[entrypoint] MySQL is up."

# 4. Run migrations (idempotent — already-run migrations are skipped).
echo "[entrypoint] Running migrations"
php artisan migrate --force

# 5. Hand off to the container's main process (CMD / compose `command`).
echo "[entrypoint] Starting: $*"
exec "$@"
