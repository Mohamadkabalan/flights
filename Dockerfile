# syntax=docker/dockerfile:1
#
# Production image for the Flight API — PHP-FPM + nginx, supervised together.
#
# Differences from the dev Dockerfile this replaces:
#   - No .env baked in. All config comes from real environment variables
#     (injected by ECS from Secrets Manager / task definition env at runtime).
#   - No migrations and no config:cache baked at build time — config:cache
#     freezes env values at build time, which breaks per-environment secrets.
#   - Serves via PHP-FPM + nginx instead of `php artisan serve`, which is a
#     single-threaded dev server not meant for real traffic.
#   - Both processes run in one container via supervisord, so this maps to a
#     single ECS task definition container, keeping the Fargate setup simple.
#
# PHP 8.5 FPM is the runtime base, matching the application's composer
# platform requirement (php ^8.5).

FROM php:8.5-fpm

# ---------------------------------------------------------------------------
# System packages + PHP extensions required by Laravel, MySQL, and Redis,
# plus nginx and supervisord to run both processes in this one container.
#   - git, unzip, libzip-dev, libonig-dev : Composer package handling
#   - pdo_mysql                          : MySQL/Aurora driver
#   - bcmath                             : Laravel numeric helpers
#   - pcntl                              : required by `php artisan horizon`
#   - zip                                : Composer dist installs
#   - redis (PECL)                       : phpredis client for queue/cache
#   - nginx                              : reverse proxy in front of FPM
#   - supervisor                         : runs nginx + php-fpm as one unit
# ---------------------------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libonig-dev \
        nginx \
        supervisor \
    && docker-php-ext-install pdo_mysql bcmath pcntl zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Bring in Composer from the official Composer image (no host install needed).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# Install PHP dependencies first (better layer caching). Changes to app code
# alone don't invalidate this layer.
# ---------------------------------------------------------------------------
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

# Copy the rest of the application source.
COPY . .

# Finish the Composer lifecycle now that all files are present.
# NOTE: no `cp .env.docker .env` here — this image carries no environment
# file at all. Every config value comes from real env vars at container
# runtime (locally via compose `environment:`, in ECS via task definition
# env / Secrets Manager). Laravel reads getenv() directly when no .env
# exists, which is exactly what we want in production.
RUN composer dump-autoload --optimize \
    && composer run-script post-autoload-dump || true

# Laravel's writable directories. www-data is the user php-fpm/nginx run as.
RUN chmod -R 775 storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# nginx config: reverse-proxies to php-fpm over a local socket, serves
# Laravel's public/ as docroot.
COPY docker/nginx.conf /etc/nginx/sites-available/default

# supervisord config: runs nginx and php-fpm as two processes inside one
# container, restarting either if it dies, with both logs going to
# stdout/stderr so ECS/CloudWatch capture them.
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

EXPOSE 80

# No entrypoint script doing migrations or config caching at boot — see
# Part 0.1/13 of the deploy guide: migrations run as a deliberate one-off
# ECS task, never automatically on container start. This container's only
# job is to serve traffic.
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]