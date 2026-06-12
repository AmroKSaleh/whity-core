# Pinned to PHP 8.4 — the platform target (composer.json >=8.4, CI runs 8.4).
# :latest drifted to PHP 8.5, whose new deprecations (e.g.
# ReflectionProperty::setAccessible()) pollute every HTTP response in dev.
#
# Two stages (WC-172):
#   * base    — runtime only. Dev and staging composes build THIS stage and
#               bind-mount the checkout over /app, so it ships no code.
#   * release — the publishable image: base + the application tree + a
#               production (no-dev) composer install. The release workflow
#               builds this stage and pushes it to GHCR; container-based
#               deployments run it as-is (see docs/wiki/Core-Update.md).
FROM dunglas/frankenphp:1-php8.4 AS base

# Install required packages for PostgreSQL extension
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && rm -rf /var/lib/apt/lists/*

# Install PostgreSQL extension
RUN docker-php-ext-install pgsql pdo_pgsql

# Set working directory
WORKDIR /app

# Default environment variables for FrankenPHP worker mode
ENV FRANKENPHP_WORKERS=8
ENV FRANKENPHP_TIMEOUT=60s
ENV MAX_REQUESTS=500

FROM base AS release

# Composer needs git + unzip to install dist packages (the frankenphp base
# image ships neither).
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# The application tree (.dockerignore keeps dev artifacts, tests, the web
# frontend and any locally deployed plugins out — only the reference plugins
# ship). composer.json resolves whity/plugin-sdk from the in-repo sdk/ path
# repository, which this COPY brings along.
COPY . /app

RUN composer install --no-dev --prefer-dist --no-progress --no-interaction \
    && composer clear-cache
