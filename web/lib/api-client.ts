/**
 * API Client with automatic silent token refresh
 *
 * This module provides an apiClient function that:
 * 1. Makes fetch requests with credentials (httpOnly cookies auto-attach)
 * 2. On 401 response, automatically calls /api/auth/refresh
 * 3. If refresh succeeds, retries the original request
 * 4. If refresh fails or skipRefresh is true, returns the original response
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
    const response = await fetch('/api/auth/refresh', {
      method: 'POST',
      credentials: 'include',
    });

    // Refresh successful if response is 200-399
    return response.ok;
  } catch (error) {
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
 *   - Calls /api/auth/refresh
 *   - If refresh succeeds: retries original request with skipRefresh: true
 *   - If refresh fails: returns original 401 response
 * - For any other status: returns as-is
 */
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

  // Always include credentials for httpOnly cookies
  const requestInit: RequestInit = {
    ...fetchOptions,
    credentials: 'include',
  };

  // Make the initial request
  const response = await fetch(fullUrl, requestInit);

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
  };

  const retryResponse = await fetch(fullUrl, retryInit);

  return retryResponse;
}
