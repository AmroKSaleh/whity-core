This is a [Next.js](https://nextjs.org) project bootstrapped with [`create-next-app`](https://nextjs.org/docs/app/api-reference/cli/create-next-app).

## Getting Started

First, run the development server:

```bash
npm run dev
# or
yarn dev
# or
pnpm dev
# or
bun dev
```

Open [http://localhost:3000](http://localhost:3000) with your browser to see the result.

You can start editing the page by modifying `app/page.tsx`. The page auto-updates as you edit the file.

This project uses [`next/font`](https://nextjs.org/docs/app/building-your-application/optimizing/fonts) to automatically optimize and load [Geist](https://vercel.com/font), a new font family for Vercel.

## End-to-End tests (Playwright)

The `e2e/` suite drives the real admin UI against the live backend with
Playwright (Chromium).

### Prerequisites

1. **Backend stack running** — FrankenPHP + PostgreSQL at `http://localhost:8000`
   (docker compose project `whity-demo`). The frontend's `/api/*` calls are
   proxied to it by the catch-all route handler at `app/api/[...path]/route.ts`.
2. **Seeded accounts** (the suite uses these; override via env if needed):
   - admin: `admin@example.com` / `admin123`
   - regular user: `user@example.com` / `user123`
   - The admin account **must have two-factor auth DISABLED** (the seed default).
     If a login returns HTTP 202, clear it on the dev DB:
     `docker exec whity_postgres psql -U whity -d whity_core -c "UPDATE users SET two_factor_enabled=false WHERE email='admin@example.com';"`
3. **Browser binary** — install once: `npx playwright install chromium`.

The frontend dev server is started automatically by Playwright on port **3010**
(via the `webServer` block; `reuseExistingServer` is on, so an already-running
instance is reused). It does not collide with a developer's own `next dev` on
:3000. Override the port/base URL with `E2E_PORT` / `E2E_BASE_URL`.

### Running

```bash
npm run test:e2e       # headless run of the full suite
npm run test:e2e:ui    # interactive Playwright UI mode
```

### Shared-database discipline

The dev database is shared. All test entities use unique suffixed names and are
cleaned up via the API in `afterEach`. The suite never mutates or deletes the
seeded accounts, the seeded `admin`/`user` roles, or tenants `0`/`1`. Runs are
deterministic and re-runnable.

### Known application bugs (tracked as `test.fixme`)

A few flows hit real app bugs (not test issues); they are kept as `test.fixme`
so they are visible without failing the suite:

- Invalid login shows no error message (`app/login/page.tsx`).
- Edit User modal does not pre-fill Name/Tenant (the users list API omits both).
- OU delete via the UI errors because the `/api/*` proxy turns the backend's
  HTTP 204 into a 500 (`app/api/[...path]/route.ts`); the backend delete itself
  still succeeds.

## Learn More

To learn more about Next.js, take a look at the following resources:

- [Next.js Documentation](https://nextjs.org/docs) - learn about Next.js features and API.
- [Learn Next.js](https://nextjs.org/learn) - an interactive Next.js tutorial.

You can check out [the Next.js GitHub repository](https://github.com/vercel/next.js) - your feedback and contributions are welcome!

## Deploy on Vercel

The easiest way to deploy your Next.js app is to use the [Vercel Platform](https://vercel.com/new?utm_medium=default-template&filter=next.js&utm_source=create-next-app&utm_campaign=create-next-app-readme) from the creators of Next.js.

Check out our [Next.js deployment documentation](https://nextjs.org/docs/app/building-your-application/deploying) for more details.
