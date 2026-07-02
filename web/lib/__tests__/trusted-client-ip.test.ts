import { CLIENT_IP_HEADER, trustedClientIp, trustedProxyHops } from '@/lib/trusted-client-ip';

/**
 * WC-b19ff21a: trusted client-IP derivation for the API proxy.
 *
 * Uses a minimal header stub (the module only calls request.headers.get) so the
 * test does not depend on a global Request/Headers in the jsdom environment.
 */
function req(headers: Record<string, string>): Request {
  const lower: Record<string, string> = {};
  for (const [k, v] of Object.entries(headers)) {
    lower[k.toLowerCase()] = v;
  }
  return {
    headers: { get: (name: string): string | null => lower[name.toLowerCase()] ?? null },
  } as unknown as Request;
}

describe('trustedClientIp', () => {
  it('returns null when hops < 1 (fail-safe default), even with X-Forwarded-For', () => {
    expect(trustedClientIp(req({ 'x-forwarded-for': '203.0.113.7' }), 0)).toBeNull();
  });

  it('returns the sole entry with one trusted hop', () => {
    expect(trustedClientIp(req({ 'x-forwarded-for': '203.0.113.7' }), 1)).toBe('203.0.113.7');
  });

  it('takes the rightmost entry with one trusted hop (the hop the trusted proxy appended)', () => {
    // Attacker prepends a spoofed value; the real client is what our proxy saw (rightmost).
    expect(trustedClientIp(req({ 'x-forwarded-for': '6.6.6.6, 203.0.113.7' }), 1)).toBe('203.0.113.7');
  });

  it('takes the Nth-from-right entry with multiple trusted hops', () => {
    // Two trusted hops (e.g. LB in front of a CDN-egress): the client is the
    // 2nd entry from the right. XFF "client, proxy1" → index len-hops = 0.
    expect(trustedClientIp(req({ 'x-forwarded-for': '203.0.113.7, 10.0.0.1' }), 2)).toBe('203.0.113.7');
    // With an extra client-prepended entry, hops=2 still trusts only the last
    // two appends — the boundary entry, never the attacker's prepend.
    expect(trustedClientIp(req({ 'x-forwarded-for': 'evil, 203.0.113.7, 10.0.0.1' }), 2)).toBe('203.0.113.7');
  });

  it('ignores entries an attacker prepends beyond the trusted hop count', () => {
    // hops=1: only the rightmost is trusted; anything to its left is client-claimed.
    expect(trustedClientIp(req({ 'x-forwarded-for': 'evil-1, evil-2, 203.0.113.7' }), 1)).toBe('203.0.113.7');
  });

  it('returns null when there are fewer entries than trusted hops', () => {
    expect(trustedClientIp(req({ 'x-forwarded-for': '203.0.113.7' }), 2)).toBeNull();
  });

  it('returns null when X-Forwarded-For is absent', () => {
    expect(trustedClientIp(req({}), 1)).toBeNull();
  });

  it('trims whitespace around entries', () => {
    expect(trustedClientIp(req({ 'x-forwarded-for': '  203.0.113.7  ' }), 1)).toBe('203.0.113.7');
  });

  it('caps the result at 45 characters', () => {
    const long = 'a'.repeat(100);
    expect(trustedClientIp(req({ 'x-forwarded-for': long }), 1)).toHaveLength(45);
  });
});

describe('trustedProxyHops', () => {
  const original = process.env.TRUSTED_PROXY_HOPS;
  afterEach(() => {
    if (original === undefined) {
      delete process.env.TRUSTED_PROXY_HOPS;
    } else {
      process.env.TRUSTED_PROXY_HOPS = original;
    }
  });

  it('defaults to 0 when unset', () => {
    delete process.env.TRUSTED_PROXY_HOPS;
    expect(trustedProxyHops()).toBe(0);
  });

  it('parses a positive integer', () => {
    process.env.TRUSTED_PROXY_HOPS = '2';
    expect(trustedProxyHops()).toBe(2);
  });

  it('collapses invalid / negative values to 0', () => {
    process.env.TRUSTED_PROXY_HOPS = 'nonsense';
    expect(trustedProxyHops()).toBe(0);
    process.env.TRUSTED_PROXY_HOPS = '-3';
    expect(trustedProxyHops()).toBe(0);
  });
});

describe('CLIENT_IP_HEADER', () => {
  it('is the internal header the backend trusts', () => {
    expect(CLIENT_IP_HEADER).toBe('x-whity-client-ip');
  });
});
