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

# Install required packages for the PostgreSQL and zip extensions. libzip-dev
# backs ext-zip, which the staged plugin-upload installer (WC-220) needs to
# safely inspect/extract uploaded .zip packages via ZipArchive.
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# Install PostgreSQL + zip extensions
RUN docker-php-ext-install pgsql pdo_pgsql zip

# Upload limits, raised ABOVE the application's own ceilings on purpose.
#
# PHP ships upload_max_filesize=2M and post_max_size=8M. A form attachment is
# capped at 10 MiB by \Whity\Core\Form\FormUploadPolicy and a plugin package at
# 32 MiB by MultipartConfig — so on the stock ini, PHP refuses a 3 MB paper
# before a single line of that policy runs, with UPLOAD_ERR_INI_SIZE and no
# sentence anybody can act on. Worse, a body over post_max_size is DISCARDED
# entirely: $_POST and $_FILES arrive empty and the request looks like a client
# that sent nothing.
#
# The ini is therefore set ABOVE every application ceiling rather than equal to
# one, so the thing that refuses an oversized upload is always the application —
# which knows which endpoint it is, what that endpoint's limit is, and how to say
# so. The ini is the backstop for a request no endpoint would accept anyway.
#
# memory_limit is raised with them: a 32 MiB package arrives as a temp file but
# is read into a string to be inspected, and 128M leaves no room for the process
# doing that in a worker that is also holding a request.
RUN { \
      echo 'upload_max_filesize = 48M'; \
      echo 'post_max_size = 64M'; \
      echo 'memory_limit = 256M'; \
    } > "$PHP_INI_DIR/conf.d/zz-whity-uploads.ini"

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

# The image's IDENTITY, baked in beside the code it describes (#1049).
#
# `/api/health` reports CoreVersion::VERSION — a CONSTANT IN THE SOURCE, which
# says what the code claims to be and not what is running. Between releases it
# is identical across every commit, so nothing could tell a backend running
# today's develop from one running a checkout three weeks old; this instance's
# backend was found four days behind with a green health probe. GET /api/build
# answers that instead, and this is where its answer comes from.
#
# It CANNOT be derived at runtime here: .dockerignore drops .git, so the tree
# below is a copy with no revision metadata and no git binary worth having. The
# commit therefore arrives as a build arg — the same WHITY_BUILD_COMMIT
# release.yml already passes to all three image legs (its comment there said
# the app image "ignores it"; it no longer does).
#
# Placed AFTER composer install so rebuilding the same context under a new
# commit reuses the dependency layer. A build with no arg does not fail: the
# script writes nothing, /api/build reports source=unknown, and the release
# smoke job — which asserts the SERVED identity names the release commit — is
# what stops that state from being published.
#
# No matching ENV, deliberately: a build arg is already an environment variable
# for the RUN below, and promoting it to a runtime ENV would leave a SECOND
# commit in the container that nothing reads and that an operator could set to
# anything. The baked file is the one source, and nothing the container does
# afterwards can move it.
ARG WHITY_BUILD_COMMIT=""
RUN php scripts/write-build-identity.php
