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

## Seeding

Insert the default tenant, the notification-template baseline, and the accounts
an install needs to be usable. Idempotent: re-running creates nothing twice and
never rewrites an existing account's password.

```bash
whity-cli seed
whity-cli seed --with-fixtures
whity-cli seed --with-document-demo
```

`migrate run` **already** creates the bootstrap administrator, so `seed` is not
required for a working install.

| Seeded | When |
|--------|------|
| Bootstrap administrator (system tenant, admin role) — address from `INITIAL_SYSTEM_ADMIN_EMAIL`, default `system@whity.local` | always |
| `admin@example.com`, `user@example.com`, `superuser@example.com` | `APP_ENV=development`, or `--with-fixtures` |
| The **document demo dataset** (see below) | `--with-document-demo` only — never implied by `APP_ENV` |

Passwords come from `INITIAL_SYSTEM_ADMIN_PASSWORD`, `INITIAL_ADMIN_PASSWORD`,
`INITIAL_USER_PASSWORD` and `INITIAL_SUPERUSER_PASSWORD`; an unset one is
generated at random and printed once. Those variables apply **only when the
account is created** — if one is set for an account that already exists and the
stored password does not match it, the seeder reports that the value is inert
rather than resetting a live credential behind your back.

Seeding also reconciles the bootstrap administrator's address with
`INITIAL_SYSTEM_ADMIN_EMAIL`, so an operator who decides to rename it after
`migrate run` has already happened can set the variable and re-seed. See the
[Go-Live Checklist](Go-Live-Checklist.md) for retiring the account afterwards.

### The document demo dataset

Every document surface renders an honest empty state, and honest empty states
look alike — so on an unseeded database "Awaiting me", "Acted on by me" and
"Passed through my unit" are the same blank panel, and two secretaries holding
one role see one template list. The demo dataset exists so those distinctions
can be looked at rather than inferred. It lands in the **Default Tenant**.

**It is off by default in every environment, `APP_ENV=development` included, and
only `--with-document-demo` turns it on.** That is a separate gate from
`--with-fixtures` on purpose. The two answer different questions: demo
*accounts* are infrastructure other things need — the E2E suite seeds in a
development environment precisely because it must log in as
`admin@example.com` — while demo *content* is illustration for a person, and
nothing depends on it. When the two shared one flag, the demo's eight
memberships pushed `admin@example.com` off the first page of a users table that
paginates at ten, and specs with nothing to do with documents began failing on
the missing cell. A shared gate leaves every future change to the seed able to
break an unrelated test.

| Seeded | Makes visible |
|--------|---------------|
| A faculty with two departments, and eight people (one of them in no unit) | "raised by my unit" / "everything below my unit" / "passed through my unit" as three different answers |
| Six documents in different routing states — awaiting, settled, two fanned out with some recipients acted and some not, one passed through a second unit | the inbox, the append-only trail, and per-step progress (a single progress bar over a fan-out cannot be right) |
| Templates and blocks placed at three units, one of them permission-tagged | a faculty secretary and a department secretary holding the **same** role seeing different sets |
| A starred collection beside a custom one | that starring **is** a collection (`system_key = 'starred'`), not a second concept |
| One document with two artifacts | the viewer's "version N of M" and its superseded-version warning |

Every routing state is produced by driving the real engine (`DocumentRouter`),
and every document and artifact by `DocumentIssuer`, so the fixture cannot
express a state the product would refuse to produce. The artifact **bytes** are
generated locally rather than rendered: a real render needs the opt-in
`whity_render` container, which `seed` must not require.

Identities go through `ProfileProvisioner::findOrCreate()` rather than a bespoke
`INSERT`, for the same reason: a hand-rolled profile row can sit in a state the
provisioning path would never produce. One visible consequence — the provisioner
derives a display name from the address's local part, so the demo people appear
as `dean`, `faculty-secretary`, `civil-head` and so on.

The eight demo accounts are all under `@demo.example.com` and share one password
taken from `DEMO_SEED_PASSWORD` — unset, one is generated and printed once, like
the other initial passwords. Logging in as them is the point: sign in as
`faculty-secretary@demo.example.com` and then as `civil-secretary@demo.example.com`
to see the same role produce two different designer libraries.

```bash
DEMO_SEED_PASSWORD=... whity-cli seed --with-document-demo
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
whity-cli i18n:sync                 # seed missing keys (English)
whity-cli i18n:sync --dry-run       # report what it would insert, write nothing
whity-cli i18n:sync --language=ar   # seed database/i18n/ar/ instead
whity-cli i18n:sync --all           # English and every committed language
```

`--language=` **does not machine-translate**, which is worth saying plainly
because a language flag on a seeding command reads like it might. It seeds a
file a person wrote and committed (`database/i18n/ar/documents.json`), for
exactly the same reason English is seeded from a file: strings that exist only
in a database cannot be reviewed in a diff, cannot ship in the image, and do not
survive the database being rebuilt.

Migration `120_seed_translation_catalogues` does the same thing at `migrate
run`, so a fresh install arrives already seeded in every committed language.
This command remains the way to seed a catalogue added *after* that migration
ran, and the way to seed an already-deployed database.

**It never overwrites an existing row, in any language.** An English string
edited in the console stays edited (and is reported as divergent); a finished
Arabic translation is untouchable. Running it twice changes nothing the second
time. It also reports keys the database still has that no source references, and
domains it does not manage — and deletes neither.

### `i18n:coverage`

Per-domain translated/missing counts for every committed language.

```bash
whity-cli i18n:coverage              # print the table
whity-cli i18n:coverage --strict     # exit 1 if any orphan keys exist
```

Reads files and nothing else, so it runs in CI, inside a container with no
database, and on a laptop with the stack down. An **orphan** — a key translated
in a language that English no longer has — is listed separately, because it is
the one thing that makes a coverage number lie: it is left behind by a rename,
it makes the file longer, and it will never render.

Only English is seeded. Every other language is filled in by a human at
`/admin/translations`, which reports what is still missing per language and
domain.

---

## Authentication

The CLI tool uses a system-generated JWT token for authentication against the API handlers. It automatically assumes the `admin` role and operates on the system tenant (ID: 1) by default.
