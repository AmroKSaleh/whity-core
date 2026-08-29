/**
 * The SSO error vocabulary, in its own module rather than exported from
 * `page.tsx`.
 *
 * An App Router page may export only a known set of things — the default
 * component, `metadata`, `dynamic` and friends. An arbitrary named export
 * type-checks under Turbopack, which is the path CI's build takes, and FAILS
 * `next build --webpack`. So it was green in CI and red for anybody building
 * the other way, which is the worst place for a build error to live: it
 * surfaces to whoever next builds locally, most likely while they are chasing
 * something unrelated.
 *
 * The docblock below moved WITH the constant deliberately. It carries the
 * `@i18n-keys` annotation the extractor reads, and leaving it behind would
 * have quietly dropped eleven strings from the auth catalogue.
 */

/**
 * SSO return markers the backend appends to /login?sso_error=… (see
 * SsoAuthHandler), mapped to their translation key and English fallback.
 *
 * Unknown reasons fall through to the generic failure, so a new backend reason
 * never surfaces a raw slug to a user. The map is keyed by the BACKEND's slug
 * and holds our key — the two namespaces stay separate deliberately, so
 * renaming a translation key never has to be coordinated with a backend release.
 *
 * The keys below reach `t()` through a variable (`t(entry.key, entry.fallback)`),
 * which no static scanner can read — so they are declared here, and the
 * extractor takes the catalogue from this block rather than pretending the scan
 * saw them. The declaration is what a translator gets, so the two must not
 * drift; `web/__tests__/login-sso-key-declaration.test.ts` fails if they do.
 *
 */
export const SSO_ERROR_KEYS: Record<string, { key: string; fallback: string }> = {
  sso_disabled: {
    key: 'sso.error.disabled',
    fallback: 'Single sign-on is currently disabled for this instance.',
  },
  provider_unavailable: {
    key: 'sso.error.providerUnavailable',
    fallback: 'That sign-in provider is unavailable right now. Please try again later.',
  },
  unknown_provider: {
    key: 'sso.error.unknownProvider',
    fallback: 'That sign-in provider is not available.',
  },
  email_unverified: {
    key: 'sso.error.emailUnverified',
    fallback: 'Your email with that provider is not verified. Verify it and try again.',
  },
  link_conflict: {
    key: 'sso.error.linkConflict',
    fallback: 'An account with that email already exists. Sign in with your password to link it.',
  },
  no_account: {
    key: 'sso.error.noAccount',
    fallback: 'No account here matches that identity. Ask an administrator for an invite.',
  },
  no_membership: {
    key: 'sso.error.noMembership',
    fallback: 'Your account has no active workspace yet. Ask an administrator for access.',
  },
  state_mismatch: {
    key: 'sso.error.stateMismatch',
    fallback: 'Your sign-in session could not be verified. Please try again.',
  },
  expired: {
    key: 'sso.error.expired',
    fallback: 'Your sign-in attempt timed out. Please try again.',
  },
  denied: { key: 'sso.error.denied', fallback: 'Sign-in was cancelled.' },
  failed: { key: 'sso.error.failed', fallback: 'Sign-in failed. Please try again.' },
};
