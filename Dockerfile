FROM dunglas/frankenphp:latest

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

