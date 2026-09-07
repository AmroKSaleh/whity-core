/**
 * Leaving the application for somewhere else entirely.
 *
 * WHY THIS IS NOT `router.push()`. Next's router is for routes this app owns.
 * A payment checkout lives on a provider's domain, and handing an external URL
 * to the router either fails or, worse, renders it as an internal path.
 *
 * WHY IT IS NOT AN INLINE `window.location.assign`. Two reasons, and the second
 * is the one that matters.
 *
 * The first is that a redirect out of the application is worth having in one
 * place: it is where the https check below lives, so no caller has to remember
 * it. A checkout URL carries the customer, and sometimes an authorisation
 * token, in its query string; over plain HTTP that is handed to whoever is on
 * the network path. The server refuses to produce one, and this refuses to
 * follow one, because a check on one side of a boundary is a check that can be
 * bypassed by the other.
 *
 * The second is that `window.location` is not writable or redefinable under
 * jsdom, so a component that navigates inline cannot be tested at all — the
 * branch is simply unexercised, which for the payment redirect means the path
 * a future card provider will take is taken entirely on trust until the day it
 * matters. A module can be mocked; a read-only global cannot.
 */
export function navigateExternal(url: string): void {
  if (!url.startsWith('https://')) {
    // Refused rather than followed. The caller has been handed a URL that
    // should never have been produced, and following it would leak whatever
    // its query string carries.
    throw new Error(`Refusing to navigate to a non-HTTPS destination: ${url}`);
  }

  window.location.assign(url);
}
