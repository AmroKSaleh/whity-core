# CLI Reference

The Whity Core CLI tool provides a command-line interface for common operations staff tasks.

## Installation

The CLI tool is located at `bin/whity-cli`. Ensure it is executable:

```bash
chmod +x bin/whity-cli
```

## Global Usage

```bash
whity-cli <command> [action] [arguments] [options]
```

Use `whity-cli --help` to see all available commands.

---

## Migration Management

Manage database migrations.

### Actions

- **status**: Show status of all migrations.
  ```bash
  whity-cli migrate status
  ```
- **run**: Run all pending migrations.
  ```bash
  whity-cli migrate run
  ```
- **rollback**: Rollback the last migration.
  ```bash
  whity-cli migrate rollback
  ```

---

## Plugin Management

Manage system plugins.

### Actions

- **list**: List all discovered plugins and their status.
  ```bash
  whity-cli plugin list
  ```
- **enable <id>**: Enable a plugin by its ID.
  ```bash
  whity-cli plugin enable AdminStats
  ```
- **disable <id>**: Disable a plugin by its ID.
  ```bash
  whity-cli plugin disable AdminStats
  ```
- **reload**: Reload the plugin registry (plugins hotload automatically).
  ```bash
  whity-cli plugin reload
  ```

---

## Tenant Management

Manage system tenants.

### Actions

- **list**: List all tenants.
  ```bash
  whity-cli tenant list
  ```
- **create <name> [--slug=s]**: Create a new tenant.
  ```bash
  whity-cli tenant create "My New Company" --slug=my-company
  ```
- **update <id> [--name=n] [--slug=s]**: Update a tenant.
  ```bash
  whity-cli tenant update 1 --name="Updated Name"
  ```
- **delete <id>**: Delete a tenant.
  ```bash
  whity-cli tenant delete 1
  ```

---

## Translations (i18n)

Derive the English catalogue from the `t()` calls in the source, and seed it
into the `translations` table. Full guide:
[Internationalization](Internationalization.md).

### `i18n:extract`

Rebuilds `database/i18n/<domain>.json` from the source. The English text comes
from the second argument of each `t()` call, so there is no parallel file to
keep in sync.

```bash
whity-cli i18n:extract              # write the catalogue
whity-cli i18n:extract --check      # verify only, exit 1 on drift (what CI runs)
```

Fails on a key a scanner cannot read — a computed key with no `@i18n-keys`
declaration or reasoned `@i18n-dynamic-ignore:`, a call with no English
fallback, or one key carrying two different English strings.

### `i18n:sync`

Inserts catalogue keys that have no row yet, as English system defaults
(`tenant_id IS NULL`).

```bash
whity-cli i18n:sync                 # seed missing keys
whity-cli i18n:sync --dry-run       # report what it would insert, write nothing
```

**It never overwrites an existing row, in any language.** An English string
edited in the console stays edited (and is reported as divergent); a finished
Arabic translation is untouchable. Running it twice changes nothing the second
time. It also reports keys the database still has that no source references, and
domains it does not manage — and deletes neither.

Only English is seeded. Every other language is filled in by a human at
`/admin/translations`, which reports what is still missing per language and
domain.

---

## Authentication

The CLI tool uses a system-generated JWT token for authentication against the API handlers. It automatically assumes the `admin` role and operates on the system tenant (ID: 1) by default.
