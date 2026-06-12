/**
 * Resolve the backend origin for SERVER-side proxying (WC-171).
 *
 * One web build must serve many deployments (KeyHub, Elmak, ...), so the
 * backend origin has to be a RUNTIME decision: `WHITY_BACKEND_URL` is read
 * from the server process environment on every call — it is deliberately NOT
 * `NEXT_PUBLIC_`-prefixed, because Next inlines `NEXT_PUBLIC_*` values into
 * the build, freezing whatever was set on the build machine.
 *
 * `NEXT_PUBLIC_API_URL` is kept as a dev-time fallback (it still works with
 * `next dev`, where inlining tracks the live env), then localhost:8000.
 */
export function backendUrl(): string {
  return (
    process.env.WHITY_BACKEND_URL ??
    process.env.NEXT_PUBLIC_API_URL ??
    'http://localhost:8000'
  );
}
