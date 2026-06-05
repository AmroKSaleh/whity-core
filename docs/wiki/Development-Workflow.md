# Development Workflow — Instruction Set & Tasker

This page explains *how work is planned, governed, and shipped* on Whity Core:
the **Instruction Set (IS)** that defines our conventions, and the **Tasker**
workflow that drives tasks from "ready" to "merged". It complements
[CONTRIBUTING.md](../../CONTRIBUTING.md) — which documents the standards and the
git/PR mechanics in detail — by describing the process those standards live in.

It applies to both human and AI contributors. Humans follow the conceptual
workflow; an AI agent (or a developer using the Tasker integration) interacts
through the Tasker tools called out below.

---

## The model in one picture

```
Tasker project (Whity-Core)
  └─ sections = epics ──> tasks, linked by dependencies into FLOWS
                              │
        get_flow_order ───────┤  pick the next UNBLOCKED task (by flow step)
                              ▼
        get_task WC-XX  ──────┤  reads the task + the full Instruction Set,
                              │  auto-moves it to in_progress
                              ▼
        branch  type/WC-XX-slug  off origin/main
                              ▼
        implement to the Instruction Set  (TDD · strict types · tenant isolation)
                              ▼
        verify (PHPUnit + PHPStan · web lint/build/E2E)
                              ▼
        PR  "WC-XX: …"  ──> CI green ──> review ──> merge to main
                              ▼
        complete_task WC-XX   ←  DONE only after the PR is merged
```

Non-dependent tasks (different flows, no shared files) run **in parallel** on
isolated branches/worktrees; dependent tasks wait for their blocker to merge.

---

## The Instruction Set (IS)

The **Instruction Set** is the project's "how we build here" — a set of directives
stored in Tasker that are **automatically embedded into every task's context**.
When a contributor (or agent) opens a task with `get_task`, the IS comes with it,
so the rules travel with the work instead of living only in a wiki nobody re-reads.

The IS is the **single source of truth** for project conventions. It currently
has ten entries:

| IS entry | In short |
|----------|----------|
| **Git & Commit Workflow** | Branch `type/WC-XX-short-description`; commit `WC-XX: verb + what changed`; **never** add `Co-authored-by`/AI attribution; PR titled `WC-XX: …` via `gh pr create`. |
| **PHP & TypeScript Coding Standards** | PHP: `declare(strict_types=1)`, PSR-12, PHPStan, PHPDoc. TS: strict types, no `any`, ESLint, React 19/Next.js 16. |
| **Architectural Rules & FrankenPHP Safety** | No request state in statics/globals (persistent workers); never bypass `TenantContext`/`ScopesToTenant`; no direct DB in API handlers. |
| **Testing Guidelines & Pass Rates** | Unit tests mandatory; integration tests verify RBAC route protection **and** tenant isolation; 100% green before merge. |
| **Design System Token Governance** | All styling comes from the token pipeline (`base.json` → CSS/JSON/Dart); no raw hex/inline spacing. |
| **API Documentation & OpenAPI Specs** | PHPDoc/JSDoc on public APIs; regenerate the OpenAPI schema when endpoints change; keep wiki docs current. |
| **Task Tracking Lifecycle in Tasker** | `in_progress` when work starts; **`done` only after the PR is merged** — opening a PR is not completion. |
| **Structured Logging & Exception Safety** | Logs carry `tenant_id`; typed domain exceptions; never leak raw internals/stack traces to clients. |
| **Third-Party Dependency Policy** | New Composer/npm packages need architect approval + license + vulnerability review. |
| **Database Migration Rules** | Non-destructive, idempotent migrations with a tested `down()`; seed/data scripts kept separate from structural DDL. |

> **No-legacy note:** Whity Core has no production deployments, so we favour clean
> code over backward-compatibility — delete dead code, drop compat shims, keep
> migrations a clean consolidated set. See the "Clean code over
> backward-compatibility" section of [CONTRIBUTING.md](../../CONTRIBUTING.md).

**Working with the IS (Tasker):**
- `get_project_is <project>` — read the full IS (also auto-injected by `get_task`).
- `list_is_entries <project>` — list entries (id + title) for cheap discovery.
- `create_is_entry` / `update_is_entry` / `delete_is_entry` — evolve the IS. Changing
  the IS changes the rules for *every* future task, so treat IS edits like a
  cross-cutting decision (an [ADR](../../docs/adr) is often warranted).

---

## The Tasker workflow

[Tasker](https://github.com/AmroKSaleh) is the task manager that plans and tracks
the project. Its structure:

- **Project** → **sections** (our epics: FrankenPHP Runtime, Plugin Architecture,
  RBAC, Multi-Tenant Isolation, Design System, Docs, …)
- **Tasks**, each with priority, status, milestones, and an embedded copy of the IS.
- **Dependencies** link tasks into **flows** — a topological order of what to build
  first, second, third.

### WC-XX numbers come from the flow, not GitHub

The `WC-XX` you see in branch names, commit prefixes, and PR titles is the task's
**flow-order step number** from `get_flow_order` — *not* a GitHub issue number.
For example `WC-12` is flow step 12 (the RBAC schema task), regardless of which
GitHub issue tracks it. Get the number from `get_flow_order` before naming a
branch. (For an out-of-flow bug fix, the convention is to use its GitHub issue
number, e.g. `WC-81` for issue #81.)

### Task lifecycle

```
pending ──(start work: get_task)──> in_progress ──(PR merged)──> done
```

- `get_task <WC-XX>` automatically flips a pending task to **in_progress** and
  injects its context + the IS. Pass `peek: true` to inspect without starting.
- Track sub-steps with **milestones** (`add_milestone` / `complete_milestone`).
- `complete_task <WC-XX>` marks **done** — call it **only** once the corresponding
  PR is approved and merged to `main`. A task that is partial, blocked, or merely
  PR-opened stays `in_progress`.

### Finding what to work on

- `get_flow_order <project>` — the dependency-ordered list; the next **unblocked**
  step is what to pick up.
- `rank_tasks <project>` — "what should I work on next?" ranked by priority/due/skip.
- `list_tasks` / `get_task … peek:true` — browse and inspect.

### End-to-end: shipping one task

1. **Pick** the next unblocked task: `get_flow_order` → e.g. `WC-14`.
2. **Start** it: `get_task WC-14` (auto → in_progress; reads task + IS).
3. **Branch** off the latest main: `git checkout -b feature/WC-14-rbac-middleware origin/main`.
4. **Build** to the IS — TDD, strict types, tenant isolation, structured logs.
5. **Verify** (CI parity): `vendor/bin/phpunit` + `vendor/bin/phpstan analyse src tests`
   in the `php:8.4` container; for `web/`, `npm run lint` / `build` / `test:e2e`.
   See [CONTRIBUTING.md](../../CONTRIBUTING.md#testing-requirements) — prefer
   real-engine tests for data-layer logic.
6. **PR**: `gh pr create --base main --title "WC-14: Build RBAC middleware for route-level enforcement"`.
   CI (`.github/workflows/automated-tests.yml`) must be green; address review.
7. **Merge** to `main`.
8. **Close out**: `complete_task WC-14`.

### Working in parallel

Multiple tasks proceed at once when they're **independent** — different flows or
no shared files — each on its own branch (or git worktree) off `main`. A green CI
check is treated as *stale* once `main` moves: re-baseline (`gh pr update-branch`)
before merging so behavioural interactions between concurrently-developed branches
are caught. Dependent tasks (e.g. anything blocked by the RBAC schema) wait for
the blocker to merge, then branch off the updated `main`.

### GitHub integration

Tasker can sync with GitHub — `github_push_task` / `github_push_project` to mirror
tasks as issues, `github_sync_issues` to reconcile, and `github_import_project` to
pull an existing repo's issues in. Tasks reference issues in PR bodies
(`Closes #N` / `Relates to #N`).

---

## Why it's set up this way

- **IS-in-every-task** keeps contributions consistent without relying on memory or
  a wiki re-read — the rules arrive with the work.
- **Flow order** stops anyone building on an unfinished foundation and makes the
  "what's next / what's blocked" answer objective.
- **Merge-gated `done`** keeps the board honest: status reflects what's actually in
  `main`, not what's in flight.
- **Parallel isolation + re-baseline** enables high throughput without branches
  stepping on each other.

## See also

- [CONTRIBUTING.md](../../CONTRIBUTING.md) — the standards in full + git/PR mechanics
- [Architecture](Architecture.md) · [Permission System](PERMISSION_SYSTEM.md) ·
  [Tenant Isolation](TENANT_ISOLATION.md)
- [CLI Reference](CLI_REFERENCE.md) — `whity-cli` commands
- [Architecture Decision Records](../../docs/adr) — recording significant decisions
