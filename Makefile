.PHONY: dev dev-start dev-stop help staging-up staging-init staging-down load-test

help:
	@echo "Whity Core Development Commands:"
	@echo ""
	@echo "  make dev          - Start both backend and frontend servers"
	@echo "  make backend      - Start Docker backend (FrankenPHP + PostgreSQL)"
	@echo "  make frontend     - Start only Next.js frontend"
	@echo "  make setup        - Initialize Docker containers and database"
	@echo "  make db-init      - Initialize/create database and run migrations"
	@echo "  make test         - Run all tests"
	@echo "  make build        - Build frontend for production"
	@echo ""
	@echo "Staging & load testing (WC-32):"
	@echo "  make staging-up   - Build/start the isolated staging stack (:8100/:5433)"
	@echo "  make staging-init - Run migrations + seed against the staging stack"
	@echo "  make staging-down - Stop the staging stack (add ARGS=-v to wipe data)"
	@echo "  make load-test    - Run the k6 load test (VUS, DURATION, ADMIN_PASSWORD)"

dev:
	@echo "🚀 Starting Whity Core Development Servers"
	@echo "Backend (Docker - FrankenPHP + PostgreSQL): http://localhost:8000"
	@echo "Frontend (Next.js): http://localhost:3000"
	@echo ""
	@echo "Press Ctrl+C to stop all servers"
	@echo ""
	@./dev-server.sh

backend:
	@echo "🚀 Starting Docker services (FrankenPHP + PostgreSQL)..."
	docker-compose up

setup:
	@echo "⚙️  Setting up Docker containers..."
	docker-compose up -d
	@echo "🗄️  Initializing database..."
	@./scripts/init-db.sh

db-init:
	@echo "🗄️  Initializing database..."
	@./scripts/init-db.sh

frontend:
	@echo "🚀 Starting Next.js frontend..."
	cd web && npm run dev

test:
	@echo "🧪 Running all tests..."
	php vendor/bin/phpunit --no-coverage

build:
	@echo "🔨 Building frontend for production..."
	cd web && npm run build

# --- Staging stack + load testing (WC-32) ----------------------------------
# Isolated, prod-like stack on alt ports; never collides with the dev demo.
STAGING_COMPOSE := docker compose -p whity-staging -f docker-compose.staging.yml --env-file .env.staging

staging-up:
	@echo "🚀 Starting isolated staging stack (frankenphp :8100, postgres :5433)..."
	$(STAGING_COMPOSE) up -d --build

staging-init:
	@echo "🗄️  Migrating + seeding the staging database..."
	@./scripts/init-staging-db.sh

staging-down:
	@echo "🛑 Stopping staging stack..."
	$(STAGING_COMPOSE) down $(ARGS)

load-test:
	@echo "📈 Running k6 load test against the staging stack..."
	@./load-tests/run.sh

.DEFAULT_GOAL := help
