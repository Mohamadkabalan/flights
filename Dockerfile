# syntax=docker/dockerfile:1
#
# Self-contained image for the Flight API.
#
# This builds a runnable PHP environment WITHOUT requiring PHP, Composer, or any
# extensions on the host — only Docker is needed. `composer install` runs during
# the image build, so `docker compose up --build` works on a clean clone.
#
# PHP 8.5 CLI is the runtime base, matching the application's composer platform
# requirement (php ^8.5).

FROM php:8.5-cli

# ---------------------------------------------------------------------------
# System packages + PHP extensions required by Laravel, MySQL, and Redis.
#   - git, unzip, libzip : Composer package handling
#   - pdo_mysql          : MySQL driver
#   - bcmath             : Laravel numeric helpers
#   - pcntl              : required by `php artisan horizon` (signal handling)
#   - zip                : Composer dist installs
#   - redis (PECL)       : phpredis client for queue/cache/Horizon
# ---------------------------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libonig-dev \
    && docker-php-ext-install pdo_mysql bcmath pcntl zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Bring in Composer from the official Composer image (no host install needed).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Working directory inside the container.
WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# Install PHP dependencies first (better layer caching).
#
# We copy only the composer manifests, install, THEN copy the rest of the app.
# This way changes to application code do not invalidate the (slow) dependency
# layer. Scripts are skipped here because the full app is not yet present.
# ---------------------------------------------------------------------------
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

# Copy the rest of the application source.
COPY . .

# Bake a ready .env and a fixed application key into the image. Because there is
# no bind mount in production (Option C), the container runs entirely from baked
# state. For a real deployment APP_KEY/config would be injected via environment
# variables instead of baked.
RUN cp .env.docker .env

# Finish the Composer lifecycle now that all files are present: build the
# optimized autoloader and run package discovery.
RUN composer dump-autoload --optimize \
    && composer run-script post-autoload-dump || true

# Ensure Laravel's writable directories are writable by the runtime user.
RUN chmod -R 775 storage bootstrap/cache

# The application listens on 8000 via the artisan dev server (see compose).
EXPOSE 8000

# Default command is overridden per-service in docker-compose.yml (the web
# service serves HTTP; the horizon service runs the queue worker).
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
