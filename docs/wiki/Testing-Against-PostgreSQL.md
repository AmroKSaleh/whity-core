# Testing Against PostgreSQL

Tests run SQLite. Production runs PostgreSQL. An entire class of defect lives in
that gap, and no amount of extra assertions on SQLite will find it.

Two real examples, both from one week of an adopter's work:

- `GROUP_CONCAT(x SEPARATOR ',')` — written against PostgreSQL, which has no
  `GROUP_CONCAT` at all. Reached production; the endpoint 500'd.
- A `CAST(id AS TEXT)` removed from a comparison — **every test still passed**,
  because SQLite compares INTEGER to VARCHAR happily. PostgreSQL refuses:
  `operator does not exist: character varying = integer`.

The second one is the one to internalise. Nothing about the statement is
malformed, so no helper function, query builder or linter can catch it — it is
the *engine's type-comparison semantics* that differ. The only thing that finds
it is executing the statement on the engine you actually ship against.

So: **run the real engine.** This page is how.

---

## Running whity-core's suites on real PostgreSQL

`tests/Support/SchemaFromMigrations::make()` returns SQLite by default and real
PostgreSQL when `PHPUNIT_PG_DSN` is set. Nothing else in a test changes.

```bash
# A throwaway server (never point this at a live database)
docker run -d --name whity_test_pg -p 55432:5432 \
  -e POSTGRES_USER=whity -e POSTGRES_PASSWORD=whity_dev -e POSTGRES_DB=whity_core \
  postgres:15-alpine

docker exec whity_frankenphp env \
  PHPUNIT_PG_DSN="pgsql:host=host.docker.internal;port=55432;dbname=whity_core" \
  PHPUNIT_PG_USER=whity PHPUNIT_PG_PASSWORD=whity_dev \
  vendor/bin/phpunit --no-coverage tests/Api/RolesApiHandlerRealEngineTest.php
```

Environment variables:

| Variable | Meaning |
| --- | --- |
| `PHPUNIT_PG_DSN` | PDO pgsql DSN. Its **presence** is the entire switch; absent ⇒ SQLite. |
| `PHPUNIT_PG_USER` / `PHPUNIT_PG_PASSWORD` | Credentials. |
| `PHPUNIT_PG_NO_TEMPLATE` | Set to `1` to disable the template cache below (diagnostics only). |

### Why it is fast now

Each `make()` used to re-run all ~90 migrations: **58.9 s per test**, which is
why the Integration suite took 30 minutes and why other suites were never moved
onto PostgreSQL at all. It now builds **one template database per server**, keyed
on a hash of `database/migrations/`, and hands each test a
`CREATE DATABASE … TEMPLATE` copy of it:

| Approach | Per `make()` |
| --- | --- |
| Re-run every migration (old) | 58.9 s |
| …with `synchronous_commit = off` | 34.8 s |
| `CREATE DATABASE … TEMPLATE` (current) | **0.54 s** |

The template rebuilds itself automatically when any migration file changes, and
is shared by every process on that server. If it cannot be used — a role without
`CREATEDB`, PostgreSQL older than 13, a DSN with no `dbname=` — the old
per-test build is used instead and the reason is written to stderr. That
fallback is slow, not wrong; CI fails the job rather than quietly taking 100×
longer.

### What CI runs

| Job | Engine | Scope |
| --- | --- | --- |
| `test` (4 shards) | SQLite | The whole suite, with coverage |
| `postgres-migrations` | PostgreSQL | `migrate` / `seed` / idempotent re-`migrate`, plus `tests/Security` |
| `postgres-integration` (5 shards) | PostgreSQL | `tests/Integration` |
| `postgres-dialect` (2 shards) | PostgreSQL | Every real-engine test *outside* those two suites |

`postgres-dialect` selects its files by content — anything referencing
`SchemaFromMigrations` or `RelationsSchema` — so a real-engine test you add
today is covered on both engines tomorrow with no workflow edit. Tests that
never touch SQL are deliberately not re-run.

---

## Running a PLUGIN's tests on real PostgreSQL

A plugin depends only on `whity/plugin-sdk`, so the harness ships there:
`Whity\Sdk\Testing\RealEnginePdo`.

```php
use Whity\Sdk\Testing\RealEnginePdo;

$pdo = RealEnginePdo::make();          // SQLite, or PostgreSQL if PHPUNIT_PG_DSN is set
foreach ($this->migrations() as $migration) {
    $migration->up($pdo);
}
```

`TenantIsolationConformanceTestCase` already uses it, so **a plugin extending
that base case needs no code change at all** — setting the variable is enough:

```bash
docker run -d --name plugin_pg -p 5432:5432 \
  -e POSTGRES_PASSWORD=postgres -e POSTGRES_DB=plugin_test postgres:15-alpine

vendor/bin/phpunit                                     # SQLite, fast loop
PHPUNIT_PG_DSN="pgsql:host=127.0.0.1;port=5432;dbname=plugin_test" \
PHPUNIT_PG_USER=postgres PHPUNIT_PG_PASSWORD=postgres \
  vendor/bin/phpunit                                   # real PostgreSQL
```

Every `RealEnginePdo::make()` call creates its own schema in the target
database and drops it at process exit, so parallel runs cannot collide and the
database is left as it was found.

### In a plugin's CI

Add a PostgreSQL service and a second PHPUnit step. Worked example (from
`whity-plugins/.github/workflows/ci.yml`, which also shows the whity-core `sdk/`
checkout a path-repository dependency needs):

```yaml
  tests-postgres:
    name: PHPUnit on real PostgreSQL (${{ matrix.package }})
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        package: [theme-creator, whity-plugin-store]

    services:
      postgres:
        image: postgres:15-alpine
        env:
          POSTGRES_USER: whity
          POSTGRES_PASSWORD: whity_dev
          POSTGRES_DB: plugin_test
        ports:
          - 5432:5432
        options: >-
          --health-cmd "pg_isready -U whity -d plugin_test"
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5

    steps:
      - uses: actions/checkout@v7
        with: { path: whity-plugins }
      - uses: actions/checkout@v7
        with:
          repository: AmroKSaleh/whity-core
          sparse-checkout: sdk
          path: whity-core

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          # pdo_pgsql is the whole point; without it the DSN is ignored and the
          # job silently re-runs the SQLite suite.
          extensions: mbstring, pdo, pdo_pgsql, pgsql
          coverage: none

      - name: Install dependencies
        working-directory: whity-plugins/${{ matrix.package }}
        run: composer install --no-interaction --no-progress

      - name: PHPUnit on real PostgreSQL
        working-directory: whity-plugins/${{ matrix.package }}
        env:
          PHPUNIT_PG_DSN: "pgsql:host=localhost;port=5432;dbname=plugin_test"
          PHPUNIT_PG_USER: whity
          PHPUNIT_PG_PASSWORD: whity_dev
        run: vendor/bin/phpunit --no-coverage
```

Keep the SQLite job too. The fast local loop is what makes the habit
sustainable; the PostgreSQL job is what makes it true.

**Prove the job has teeth before trusting it.** Break something in a
dialect-specific way on purpose — change a `CAST(id AS TEXT) = code` comparison
to `id = code` — and confirm SQLite stays green while PostgreSQL fails. A
dual-engine job that cannot fail on a real dialect bug is theatre.

---

## The traps

Everything below was verified by running the statement on both engines
(SQLite 3 via `pdo_sqlite`, PostgreSQL 15 via `pdo_pgsql`, PHP 8.4). Each one
**passes silently on SQLite**.

### Type comparison

| You write | SQLite | PostgreSQL |
| --- | --- | --- |
| `WHERE varchar_col = 7` | matches | `operator does not exist: character varying = integer` |
| `WHERE int_col = varchar_col` | matches | `operator does not exist: integer = character varying` |
| `WHERE bool_col = 1` | matches | `operator does not exist: boolean = integer` |

This is the class that removing a `CAST` reopens. Note the nuance: **bound
parameters are safe** — `WHERE code = ?` with a PHP `int` works on both, because
PDO sends the value untyped and PostgreSQL infers the column's type. The danger
is in *literals* and *column-to-column* comparisons, which is exactly where a
`CAST` gets "cleaned up".

### Functions that only exist on one side

| SQLite-only | PostgreSQL equivalent |
| --- | --- |
| `GROUP_CONCAT(x, ',')` | `string_agg(x, ',')` |
| `IFNULL(a, b)` | `COALESCE(a, b)` |
| `datetime('now')`, `strftime(...)` | `NOW()`, `to_char(...)` |
| `INSERT OR IGNORE INTO t …` | `INSERT INTO t … ON CONFLICT DO NOTHING` |

`GROUP_CONCAT(x SEPARATOR ',')` is neither: MySQL's spelling, a syntax error on
both. `ON CONFLICT` itself works on both (SQLite ≥ 3.24), so prefer it.

### Query semantics

| Behaviour | SQLite | PostgreSQL |
| --- | --- | --- |
| `SELECT tenant_id, name … GROUP BY tenant_id` | allowed | `column "t.name" must appear in the GROUP BY clause` |
| `WHERE name LIKE 'B%'` matching `'beta'` | matches (LIKE is case-**in**sensitive for ASCII) | no match (use `ILIKE`) |
| `ORDER BY name ASC` with NULLs | NULLs **first** | NULLs **last** |
| `"literal"` in an expression | falls back to a string literal | `column "literal" does not exist` |
| `PDOStatement::rowCount()` after a `SELECT` | `0` | the row count |

The `ORDER BY` one silently changes what page 1 of a paginated endpoint
contains. The `rowCount()` one flips an emptiness check from "always empty" to
"correct", or vice-versa.

### Schema and identifiers

| Behaviour | SQLite | PostgreSQL |
| --- | --- | --- |
| `symmetric` (or another reserved word) as a column name | accepted | syntax error — must be quoted |
| Column created as `"tenantId"`, read as `tenantId` | resolves | `column "tenantid" does not exist` (unquoted folds to lower case) |
| `INSERT` of 16 chars into `VARCHAR(8)` | accepted | `value too long for type character varying(8)` |
| Orphan row for a declared `REFERENCES` | **accepted** (FKs off unless `PRAGMA foreign_keys = ON`) | `foreign key violation` |
| Insert an explicit `id`, then insert without one | next id is `max(id) + 1` | `duplicate key value violates unique constraint` |

The foreign-key one is worth re-reading: a SQLite test can insert data that
production would reject outright, and the fixture it builds is one production
could never contain.

The last row bites any fixture that seeds rows at fixed ids. SQLite's
`INTEGER PRIMARY KEY AUTOINCREMENT` derives the next value from the table, so an
explicit id moves the counter. PostgreSQL's `SERIAL` is a *separate* sequence
that an explicit id does not touch — so the next `INSERT` without an id reuses a
number already taken. Either seed everything explicitly, or bump the sequence
after seeding:

```sql
SELECT setval('roles_id_seq', (SELECT MAX(id) FROM roles));
```

In whity-core's own fixtures, call `SchemaFromMigrations::syncSequences($pdo)`
right after seeding at explicit ids — it fixes every sequence in the schema and
is a no-op on SQLite, so it goes in unconditionally.

### Transactions

| Behaviour | SQLite | PostgreSQL |
| --- | --- | --- |
| A statement errors inside a transaction, the code catches it and carries on | later statements work | every later statement fails with `current transaction is aborted` (`25P02`) |

A `try { … } catch { /* optional step */ }` inside a transaction is a normal
pattern on SQLite and poisons the whole transaction on PostgreSQL. Use a
`SAVEPOINT` if a step is genuinely optional.

### PHP-side types

| Column | SQLite fetch | PostgreSQL fetch |
| --- | --- | --- |
| `BOOLEAN` / `INTEGER` flag | `int` (`0` / `1`) | `bool` (`false` / `true`) |

`assertSame(1, $row['flag'])` passes on SQLite and fails on PostgreSQL — and
`if ($row['flag'] === 1)` is a production bug, not a test bug.
`SchemaFromMigrations::make(true)` turns on `ATTR_STRINGIFY_FETCHES` so SQLite
returns strings, which catches the strict-comparison half of this on the fast
loop; it does not model the boolean difference, which is what the real engine is
for.

---

## Related

- [`CONTRIBUTING.md`](../../CONTRIBUTING.md) — testing requirements and the PR gate.
- [Plugin Development](Plugin-Development.md) — building a plugin against the SDK.
- [Tenant Isolation](TENANT_ISOLATION.md) — the conformance kit these harnesses plug into.
