/**
 * Trusted client-IP derivation for the API proxy (WC-b19ff21a).
 *
 * The Next.js API proxy is the platform's single trusted front door to the
 * backend. To give the backend a client IP it can trust for rate limiting and
 * audit, the proxy derives the real client IP HERE and forwards it in an
 * internal header ({@link CLIENT_IP_HEADER}); the backend trusts only that
 * header and ignores raw client-supplied `X-Forwarded-For` / `X-Real-IP`.
 *
 * ## Why the Nth-from-the-right entry
 *
 * `X-Forwarded-For` reads left→right as `client, proxy1, proxy2, …`, where each
 * proxy APPENDS the address it received the connection from. The RIGHTMOST
 * entries are therefore written by infrastructure we control and cannot be
 * forged by the client; the leftmost entries are client-claimed and spoofable.
 * With `hops` trusted proxies in front of this app, the real client is the
 * `hops`-th entry counting from the right — anything an attacker prepends only
 * lands further left and is ignored.
 *
 * ## TRUSTED_PROXY_HOPS (operator contract)
 *
 * `hops` comes from the `TRUSTED_PROXY_HOPS` env var and MUST match the number
 * of trusted proxies between the public internet and this Next.js app:
 *   - `1` — one reverse proxy / ingress / cloud LB in front (the common case).
 *   - `2` — e.g. CDN → LB → app.
 *   - `0` (DEFAULT) — trust nothing from `X-Forwarded-For`; returns null, so no
 *     (spoofable) client IP is propagated. This is the FAIL-SAFE default: per-IP
 *     rate limiting and audit IPs are simply absent until an operator who knows
 *     their topology opts in by setting a correct hop count. Setting it too low
 *     would trust a client-claimed entry (spoofable); too high yields null.
 *
 * NOTE — Next.js does NOT sanitize `X-Forwarded-For`: it forwards a
 * client-supplied header through unchanged (verified on the real path). So
 * `hops` counts appending proxies IN FRONT OF Next.js, not Next.js itself. A
 * value `≥ 1` is safe only when such a proxy appends the connecting peer (nginx
 * `proxy_add_x_forwarded_for`, AWS ALB, …); with nothing in front, keep `0`.
 */
export const CLIENT_IP_HEADER = 'x-whity-client-ip';

/** IPv6 textual maximum; also the backend audit column width. */
const MAX_IP_LENGTH = 45;

/**
 * Read the configured number of trusted proxy hops from the environment.
 * Non-numeric / negative values collapse to 0 (fail-safe: no trusted IP).
 */
export function trustedProxyHops(): number {
  const raw = process.env.TRUSTED_PROXY_HOPS;
  const hops = raw === undefined ? 0 : Number.parseInt(raw, 10);
  return Number.isInteger(hops) && hops > 0 ? hops : 0;
}

/**
 * Derive the trusted client IP from an incoming request's `X-Forwarded-For`.
 *
 * Returns null when no trustworthy IP can be determined (hops disabled, header
 * absent, or not enough entries for the configured hop count). The result is
 * capped at {@link MAX_IP_LENGTH}.
 *
 * @param request The incoming request to the Next.js proxy.
 * @param hops    Trusted proxy hop count; defaults to {@link trustedProxyHops}.
 */
export function trustedClientIp(request: Request, hops: number = trustedProxyHops()): string | null {
  if (hops < 1) {
    return null;
  }

  const forwardedFor = request.headers.get('x-forwarded-for');
  if (!forwardedFor) {
    return null;
  }

  const parts = forwardedFor
    .split(',')
    .map(part => part.trim())
    .filter(part => part.length > 0);

  const index = parts.length - hops;
  if (index < 0 || index >= parts.length) {
    return null;
  }

  const ip = parts[index];
  return ip ? ip.slice(0, MAX_IP_LENGTH) : null;
}
