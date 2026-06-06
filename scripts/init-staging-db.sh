#!/bin/bash
#
# Initialize the WC-32 STAGING database (migrate + seed).
#
# Mirrors scripts/init-db.sh but targets the isolated staging containers
# (whity_staging_postgres / whity_staging_frankenphp) brought up by
# docker-compose.staging.yml. Run AFTER the stack is up:
#
#   docker compose -p whity-staging -f docker-compose.staging.yml \
#     --env-file .env.staging up -d --build
#   ./scripts/init-staging-db.sh
#
# Reads DB_* from .env.staging if present (override with env vars).

set -e

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; NC='\033[0m'

PG_CONTAINER=${PG_CONTAINER:-whity_staging_postgres}
APP_CONTAINER=${APP_CONTAINER:-whity_staging_frankenphp}

if [ -f .env.staging ]; then
    # shellcheck disable=SC2046
    export $(grep -v '^#' .env.staging | grep -E '^[A-Za-z_]+=' | xargs)
fi

DB_USER=${DB_USER:-whity}
DB_NAME=${DB_NAME:-whity_core}

echo -e "${YELLOW}Initializing STAGING database...${NC}"

# Wait for PostgreSQL to be ready.
echo -e "${YELLOW}Waiting for PostgreSQL (${PG_CONTAINER})...${NC}"
for i in {1..30}; do
    if docker exec "${PG_CONTAINER}" pg_isready -U "${DB_USER}" -h localhost > /dev/null 2>&1; then
        echo -e "${GREEN}PostgreSQL is ready${NC}"
        break
    fi
    echo -e "${YELLOW}Attempt $i/30 - waiting...${NC}"
    sleep 1
    if [ "$i" -eq 30 ]; then
        echo -e "${RED}PostgreSQL failed to start${NC}"
        exit 1
    fi
done

# Create the database if it does not exist.
echo -e "${YELLOW}Ensuring database '${DB_NAME}' exists...${NC}"
if docker exec "${PG_CONTAINER}" psql -U "${DB_USER}" -d postgres -tc \
    "SELECT 1 FROM pg_database WHERE datname = '${DB_NAME}'" | grep -q 1; then
    echo -e "${GREEN}Database already exists${NC}"
else
    docker exec "${PG_CONTAINER}" psql -U "${DB_USER}" -d postgres -c "CREATE DATABASE ${DB_NAME};"
    echo -e "${GREEN}Database created${NC}"
fi

# Run migrations + seed via the app CLI inside the staging worker container.
echo -e "${YELLOW}Running migrations...${NC}"
docker exec "${APP_CONTAINER}" php public/index.php migrate run
echo -e "${GREEN}Migrations complete${NC}"

echo -e "${YELLOW}Seeding database...${NC}"
docker exec "${APP_CONTAINER}" php public/index.php seed
echo -e "${GREEN}Seeding complete${NC}"

echo -e "${GREEN}Staging database initialization complete${NC}"
