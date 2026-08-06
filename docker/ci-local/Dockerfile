# Local stand-in for the GitHub-hosted ubuntu-latest runner used by
# .github/workflows/automated-tests.yml.
#
# The two PHP jobs there ("Unit, static analysis & plugin smoke (SQLite)" and
# "Migrations + Integration + Security on real PostgreSQL") differ only in the
# loaded extensions, so ONE image carries the union of both and the runner
# script picks the job. Keep the versions below in lockstep with the workflow:
#   php-version: '8.4'
#   test job       -> extensions: mbstring, pdo, pdo_sqlite   coverage: pcov
#   postgres job   -> extensions: mbstring, pdo, pdo_pgsql, pgsql
FROM php:8.4-cli

# git + unzip: composer needs them for --prefer-dist and for the OpenAPI drift
# check, which shells out to `git diff --exit-code`.
# libpq-dev + libonig-dev: build deps for pdo_pgsql/pgsql and mbstring.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libonig-dev \
        libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# pdo/pdo_sqlite/sqlite3 are already compiled into php:8.4-cli — only the
# Postgres drivers, mbstring and zip need adding.
RUN docker-php-ext-install -j"$(nproc)" \
        mbstring \
        pdo_pgsql \
        pgsql \
        zip

# The unit job runs `phpunit --coverage-clover` and then enforces a floor via
# scripts/coverage-check.php, so a coverage driver is mandatory. CI uses pcov
# (setup-php `coverage: pcov`) — xdebug would report slightly different numbers
# and is far slower, so match CI exactly.
RUN pecl install pcov && docker-php-ext-enable pcov

# Coverage pushes RSS past the default limit on the full suite; the workflow
# relies on the runner's generous default, so give the container headroom.
RUN printf 'memory_limit=1G\n' > /usr/local/etc/php/conf.d/zz-ci-local.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# `git diff` inside the mounted repo would otherwise refuse to run:
# "detected dubious ownership" (the bind mount is owned by the host user).
RUN git config --global --add safe.directory /app

WORKDIR /app
