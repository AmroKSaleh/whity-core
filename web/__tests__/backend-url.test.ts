/**
 * @jest-environment node
 *
 * WC-171: the server-side proxies resolve the backend origin at RUNTIME so
 * one web build serves many deployments. WHITY_BACKEND_URL (never inlined)
 * wins; NEXT_PUBLIC_API_URL stays a dev fallback; localhost:8000 is last.
 */

import { backendUrl } from '@/lib/backend-url';

describe('backendUrl', () => {
  const saved = {
    runtime: process.env.WHITY_BACKEND_URL,
    build: process.env.NEXT_PUBLIC_API_URL,
  };

  afterEach(() => {
    if (saved.runtime === undefined) {
      delete process.env.WHITY_BACKEND_URL;
    } else {
      process.env.WHITY_BACKEND_URL = saved.runtime;
    }
    if (saved.build === undefined) {
      delete process.env.NEXT_PUBLIC_API_URL;
    } else {
      process.env.NEXT_PUBLIC_API_URL = saved.build;
    }
  });

  it('prefers the runtime WHITY_BACKEND_URL over everything', () => {
    process.env.WHITY_BACKEND_URL = 'http://keyhub-backend:80';
    process.env.NEXT_PUBLIC_API_URL = 'http://build-time-value:1';

    expect(backendUrl()).toBe('http://keyhub-backend:80');
  });

  it('falls back to NEXT_PUBLIC_API_URL when no runtime override is set', () => {
    delete process.env.WHITY_BACKEND_URL;
    process.env.NEXT_PUBLIC_API_URL = 'http://dev-backend:8000';

    expect(backendUrl()).toBe('http://dev-backend:8000');
  });

  it('defaults to localhost:8000 when nothing is configured', () => {
    delete process.env.WHITY_BACKEND_URL;
    delete process.env.NEXT_PUBLIC_API_URL;

    expect(backendUrl()).toBe('http://localhost:8000');
  });
});
