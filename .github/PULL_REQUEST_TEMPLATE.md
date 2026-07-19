## What

<!-- One or two sentences: what changed and why. -->

## Why

<!-- The problem this solves, or the task/issue it addresses. -->

## Verify checklist

- [ ] `declare(strict_types=1);` on every new/changed PHP file (PSR-12)
- [ ] `phpstan analyse src tests plugins sdk` — level 8, clean
- [ ] Full PHPUnit suite passes locally (100%)
- [ ] For migration / data-layer / SQL / auth changes: verified against **real
      PostgreSQL**, not just SQLite
- [ ] Every tenant-owned query carries an explicit `tenant_id` predicate from
      `TenantContext`
- [ ] No raw exception messages / stack traces returned to the client
- [ ] New API routes: declared in `CoreApiSchemas` **and** `public/openapi.json`
      regenerated (`generate:openapi`), or added to `KNOWN_UNDOCUMENTED` with a
      reason
- [ ] TypeScript: no `any`, `eslint` clean, `tsc --noEmit` clean (web changes)
- [ ] For security-sensitive or large changes: adversarial review completed
      before merge
- [ ] CI green — both the SQLite job and the real-Postgres job

## Notes for reviewers

<!-- Anything a reviewer should know: known trade-offs, deferred follow-ups, manual verification steps taken. -->
