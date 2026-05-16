# Whity Core

**Open-source white-labeled multi-tenant platform framework**

Whity Core is the foundational framework for building data-driven applications with **sovereign multi-tenant deployments**, **dynamic plugin hot-loading**, and **role-based access control**.

## Key Features

- ✅ **Sovereign Deployments** — Each customer runs isolated Docker instance, no shared infrastructure
- ✅ **Plugin Hot-Loading** — Drop plugins to `/plugins/` directory, live on next request (no restart)
- ✅ **Stateless Controllers** — FrankenPHP persistent workers, strict isolation between requests  
- ✅ **RBAC Tenant Isolation** — All queries filtered by tenant_id, enforced at database layer
- ✅ **OpenAPI-First** — Backend generates schema, frontend/mobile auto-generate types
- ✅ **Zero-Downtime Updates** — Atomic code overlay + schema migrations + auto-rollback

## Architecture

```
Web / Desktop / Mobile Clients
         ↓  HTTP/REST
    FrankenPHP Server (Port 8000)
    ├─ Auth middleware (JWT)
    ├─ RBAC enforcement
    ├─ Plugin loader (PHP reflection)
    └─ PostgreSQL connection pool
         ↑
    tenant.toml (branding, features)
    /plugins/ (hot-loadable domain logic)
```

## Documentation

- **[Installation Guide](docs/wiki/Installation.md)** — Setup, configuration, troubleshooting
- **[Plugin Development](docs/wiki/Plugin-Development.md)** — How to build plugins
- **[Architecture](docs/wiki/Architecture.md)** — Design principles and runtime model
- **[CONTRIBUTING.md](CONTRIBUTING.md)** — Development guidelines
- **[SECURITY.md](SECURITY.md)** — Security vulnerability reporting

## Quick Start

```bash
# Clone and start
git clone https://github.com/AmroKSaleh/whity-core.git
cd whity-core
docker-compose up

# Run migrations
docker-compose exec frankenphp php whity migrate

# Health check
curl http://localhost:8000/api/health
```

## Technology Stack

| Layer | Technology | Why |
|-------|-----------|-----|
| Backend Runtime | PHP 8.4+ | Native reflection, dynamic execution |
| Concurrency | FrankenPHP / Swoole | Persistent workers, 100k+ concurrent |
| Database | PostgreSQL 15+ | JSONB, relational integrity, ACID |
| Web Client | React 18 + TypeScript | Heavy state management |
| Desktop | Electron | Native OS access |
| Mobile | Flutter + Dart | Native ARM compilation |

## Project Structure

```
whity-core/
├── src/Core/              (PluginLoader, RBAC, Router)
├── src/Auth/              (Authentication, Permissions)
├── src/Http/              (Controllers, Middleware)
├── src/Database/          (Migrations, Queries)
├── database/migrations/   (SQL files)
├── tests/                 (Unit + Feature tests)
├── docker/                (Dockerfile, docker-compose)
└── public/index.php       (Entry point)
```

## Documentation

- **[CONTRIBUTING.md](CONTRIBUTING.md)** — Development guide, architecture principles
- **[SECURITY.md](SECURITY.md)** — Security policies, vulnerability reporting
- **[LICENSE](LICENSE)** — AGPL v3.0 (free/non-profit use) + Commons Clause (commercial restricted)

## License

**AGPL v3.0 + Commons Clause**

✅ Free for: non-profit, internal use, educational, research, open-source  
❌ Restricted: commercial SaaS, hosting as a service, reselling

For commercial use, contact: **amroksaleh@gmail.com**

See [LICENSE](LICENSE) for full legal text.

## Getting Help

- 📚 **Docs:** [CONTRIBUTING.md](CONTRIBUTING.md)
- 💬 **Discussions:** [GitHub Discussions](https://github.com/AmroKSaleh/whity-core/discussions)
- 🐛 **Issues:** [GitHub Issues](https://github.com/AmroKSaleh/whity-core/issues)
- 🔒 **Security:** [SECURITY.md](SECURITY.md)

## Roadmap

- 🚀 Sprint 1: FrankenPHP foundation + plugin loader + RBAC (in progress)
- ⏳ Sprint 2: OpenAPI schema generation + code generation
- ⏳ Sprint 3: CLI tool + zero-downtime updates
- ⏳ Sprint 4: Staging + testing infrastructure
- ⏳ Sprint 5: KeyHub/Elmak plugins
- ⏳ Sprint 6: Feature completion
- ⏳ Sprint 7: Integration + E2E testing
- ⏳ Sprint 8: Launch + documentation

See [Issues](https://github.com/AmroKSaleh/whity-core/issues) for details.

---

Built with ❤️  for building better platform infrastructure.
