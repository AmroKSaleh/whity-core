.PHONY: dev dev-start dev-stop help

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

.DEFAULT_GOAL := help
