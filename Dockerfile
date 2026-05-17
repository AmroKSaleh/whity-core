FROM dunglas/frankenphp:latest

# Install required packages for PostgreSQL extension
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && rm -rf /var/lib/apt/lists/*

# Install PostgreSQL extension
RUN docker-php-ext-install pgsql pdo_pgsql

# Set working directory
WORKDIR /app
