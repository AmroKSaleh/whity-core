# ADR 0004: Delete app/demo unauthenticated showcase

- **Status:** Accepted
- **Date:** 2026-06-15
- **Task / Issue:** WC-202 (#285)
- **Deciders:** Amro Saleh

## Context

`web/app/demo/` was an unauthenticated public route (`/demo`) that served a shadcn/ui component showcase. It accumulated problems:

- Raw Tailwind color utilities (23 occurrences: `gray-*`, `slate-*`, `blue-*`) that bypassed the design token pipeline, breaking dark mode for the entire route.
- A one-off inline dark toggle that duplicated—and diverged from—the system-level theme context.
- No authentication gate: any unauthenticated visitor could access it, leaking component patterns.
- Zero production value: it was a local dev aid during the initial shadcn/ui integration and was never linked from the main navigation.

The no-legacy stance for Whity-Core (no production deployments yet) means there is no migration risk.

## Decision

We delete `web/app/demo/` and its associated Playwright smoke tests (`web/e2e/demo.spec.ts`). The `/demo` route no longer exists.

Component exploration and design-system documentation belong in the Component Library wiki (`docs/wiki/Component-Library.md`) and the design-system token spec (`web/app/globals.css`), not in a running unauthenticated page.

## Alternatives Considered

- **Move behind auth** — adds a route, maintenance burden, and still doesn't fix the raw-color violations. Rejected: the showcase has no production audience.
- **Convert to a token-compliant gallery behind auth** — could serve as a living style guide. Deferred to Phase E (Batteries-included Admin & UX), where a proper design-system gallery can be designed correctly from the start.
- **Do nothing** — raw colors continue to trigger the new `no-restricted-syntax` ESLint lint rule (37 warnings), and dark mode remains broken on the route. Unacceptable.

## Consequences

- `/demo` returns 404. No redirects are needed (the route was never publicly linked).
- The `no-restricted-syntax` design-token lint warning count drops (the route accounted for 23 of the 37 remaining raw-color warnings).
- A proper component gallery can be introduced in Phase E with correct token usage, authentication, and a defined audience.
