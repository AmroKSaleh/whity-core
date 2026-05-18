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

## Authentication

The CLI tool uses a system-generated JWT token for authentication against the API handlers. It automatically assumes the `admin` role and operates on the system tenant (ID: 1) by default.
