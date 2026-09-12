/**
 * API Client with automatic silent token refresh
 *
 * This module provides an apiClient function that:
 * 1. Makes fetch requests with credentials (httpOnly cookies auto-attach)
 * 2. On 401 response, automatically calls /api/v1/auth/refresh
 * 3. If refresh succeeds, retries the original request
 * 4. If refresh fails or skipRefresh is true, returns the original response
 * 5. On 402 (the payment wall), sends the reader to the page where they can pay
 */

export interface ApiClientOptions extends RequestInit {
  /**
   * If true, prevents automatic token refresh on 401 response.
   * Used to prevent infinite loops on refresh endpoint itself.
   */
  skipRefresh?: boolean;
}

/**
 * Attempts to refresh the access token
 * @returns true if refresh succeeded, false otherwise
 */
async function refreshAccessToken(): Promise<boolean> {
  try {
    // Use relative URL to go through Next.js proxy (handles CORS with credentials)
    const response = await fetch('/api/v1/auth/refresh', {
      method: 'POST',
      credentials: 'include',
      // CSRF defense (WC-160): the backend rejects auth POSTs without this
      // custom header, which cross-site forms cannot set.
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });

    // Refresh successful if response is 200-399
    return response.ok;
  } catch {
    // Network errors or other failures - refresh failed
    return false;
  }
}

/**
 * Make API requests with automatic token refresh on 401
 *
 * @param url - The endpoint URL (relative or absolute)
 * @param options - RequestInit options with optional skipRefresh flag
 * @returns The Response object (no exceptions thrown)
 *
 * Behavior:
 * - Makes request with credentials: 'include' for httpOnly cookies
 * - If 200-399: returns immediately
 * - If 401 and skipRefresh not set:
 *   - Calls /api/v1/auth/refresh
 *   - If refresh succeeds: retries original request with skipRefresh: true
 *   - If refresh fails: returns original 401 response
 * - For any other status: returns as-is
 */
/**
 * Where a walled tenant is sent.
 *
 * The billing routes are exempt from the payment wall precisely so that a
 * tenant who cannot use anything else can still reach the thing that un-walls
 * them. Sending them anywhere else would be sending them to another 402.
 */
const BILLING_PATH = '/billing';

/**
 * Has a redirect already been started this page-load?
 *
 * A dashboard fires a dozen requests at once and EVERY ONE of them comes back
 * 402 for a walled tenant. Without this, each would call navigation in turn —
 * a dozen redirects to the same place, and any of them able to interrupt the
 * one before it.
 */
let walledRedirectStarted = false;

/**
 * THE PAYMENT WALL IS INVISIBLE OTHERWISE, and that is the bug this fixes.
 *
 * A tenant that has not paid gets 402 on every call. Nothing in the UI knew
 * what that meant, so each screen simply rendered whatever it renders with no
 * data: a dashboard with an empty sidebar, no error, nothing to click. The
 * product looked broken rather than unpaid, and the one action that would fix
 * it — paying — was the one thing not on screen.
 *
 * NEVER REDIRECTS AWAY FROM BILLING. The billing pages are exempt from the
 * wall, but a 402 from some unrelated call made while sitting on one of them
 * would otherwise bounce the reader off the page they need, possibly in a loop.
 */
function redirectToBilling(): void {
  if (typeof window === 'undefined' || walledRedirectStarted) {
    return;
  }

  if (window.location.pathname.startsWith(BILLING_PATH)) {
    return;
  }

  walledRedirectStarted = true;
  // `replace`, not `assign`: the page they could not use does not belong in
  // history, and Back from billing should not land on it again.
  window.location.replace(BILLING_PATH);
}

export async function apiClient(
  url: string,
  options?: ApiClientOptions
): Promise<Response> {
  // Use relative URL for /api paths to go through Next.js proxy (handles CORS properly)
  // Use direct backend URL for other endpoints
  let fullUrl = url;
  if (!url.startsWith('http')) {
    if (url.startsWith('/api')) {
      // Use Next.js proxy for /api paths
      fullUrl = url;
    } else {
      // Direct backend URL for non-API paths
      const apiUrl = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000';
      fullUrl = `${apiUrl}${url}`;
    }
  }

  // Extract skipRefresh from options and remove it before passing to fetch
  const { skipRefresh = false, ...fetchOptions } = options || {};

  // Always include credentials for httpOnly cookies, and always send the
  // CSRF defense header (WC-160) — harmless on unprotected routes, required
  // on cookie-authenticated state changes. Caller-supplied headers win on
  // clash; the Headers wrapper accepts every HeadersInit shape (plain object,
  // Headers instance, tuple array) without corruption.
  const withCsrfHeader = (init?: HeadersInit): Headers => {
    const headers = new Headers(init);
    if (!headers.has('X-Requested-With')) {
      headers.set('X-Requested-With', 'XMLHttpRequest');
    }
    return headers;
  };

  const requestInit: RequestInit = {
    ...fetchOptions,
    credentials: 'include',
    headers: withCsrfHeader(fetchOptions.headers),
  };

  // Make the initial request
  const response = await fetch(fullUrl, requestInit);

  // The payment wall. Handled BEFORE the 401 branch below because it is not an
  // authentication problem and refreshing a token cannot resolve it — the
  // caller is perfectly authenticated and simply may not use this.
  if (response.status === 402) {
    redirectToBilling();

    return response;
  }

  // If successful or not a 401, return immediately
  if (response.ok || response.status !== 401 || skipRefresh) {
    return response;
  }

  // We have a 401 and skipRefresh is false, attempt refresh
  const refreshSucceeded = await refreshAccessToken();

  if (!refreshSucceeded) {
    // Refresh failed, return the original 401 response
    return response;
  }

  // Refresh succeeded, retry original request with skipRefresh: true
  const retryInit: RequestInit = {
    ...fetchOptions,
    credentials: 'include',
    headers: withCsrfHeader(fetchOptions.headers),
  };

  const retryResponse = await fetch(fullUrl, retryInit);

  return retryResponse;
}
