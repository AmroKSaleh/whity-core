# Whity Core Framework

Welcome to the Whity Core documentation.

## Quick Links

- **[Sprint 1 Setup Guide](Sprint-1-Setup.md)** — Local development setup for Sprint 1 MVP
- **[Installation](Installation.md)** — Setup guide
- **[Plugin Development](Plugin-Development.md)** — Build plugins

## System documentation

- **[Architecture](Architecture.md)** — Full system guide: request lifecycle, plugins, RBAC, multi-tenancy, schema, deployment (start here).
- **[Permission System](PERMISSION_SYSTEM.md)** — RBAC permissions, registry, role hierarchy, enforcement.
- **[Tenant Isolation](TENANT_ISOLATION.md)** — Multi-tenancy, `TenantContext`, query scoping.
- **[Sign in with Google (SSO)](SSO-Google-Setup.md)** — End-to-end federated sign-in setup: Google OAuth client, provider config, trust tiers, first-login behaviour, troubleshooting.
- **[Email (SMTP) Setup](Email-SMTP-Setup.md)** — Configure outbound email end to end: cPanel/SMTP credentials, the `mail.*` settings + write-only encrypted password, local Mailpit testing, troubleshooting.
- **[Hook System](HOOK_SYSTEM.md)** — Plugin event/extension mechanism.
- **[Plugin UI Blocks](Plugin-UI-Blocks.md)** — Server-driven UI: the platform-neutral block contract (display / data-bound / interactive) plugins declare and each platform renders.
- **[Family Relations](RELATIONS.md)** — Person-node graph, relationship types, the relations API + admin hub.

## Contributing

- **[Development Workflow](Development-Workflow.md)** — How work is planned and shipped: the Instruction Set (IS) and the Tasker flow (`WC-XX` numbering, task lifecycle, parallel execution).
- **[Package Releases](Package-Releases.md)** — How the publishable packages (`@amroksaleh/ui`, the PHP plugin SDK) are versioned and released: monorepo + independently-versioned packages, changesets, version-gated publish.
- **[CONTRIBUTING.md](../../CONTRIBUTING.md)** — Dev setup, coding standards, testing, and the PR process.
- **[Plugin Development](Plugin-Development.md)** — Build plugins against `PluginInterface`.

## What is Whity Core?

Whity Core is a white-labeled multi-tenant PHP 8.4 framework for SaaS applications, served by FrankenPHP persistent workers over a shared PostgreSQL database.

**Features:**
- ✅ Multi-tenant (shared PostgreSQL, tenant isolation via `tenant_id` + request-scoped context)
- ✅ Extensible plugins (auto-discovery, hot-reload, lifecycle isolation)
- ✅ Built-in RBAC security (`resource:action` permissions, role hierarchy)
- ✅ FrankenPHP persistent workers
- ✅ Production-ready

## Getting Started

```bash
composer require amroksaleh/whity-core:^1.0
```

See [Installation](Installation.md) for details.

## License

AGPL v3.0 with Commons Clause (free for non-commercial)

Commercial licensing: amroksaleh@gmail.com
