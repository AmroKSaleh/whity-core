/**
 * @jest-environment node
 *
 * WHIT-587: the served bundle's own identity.
 *
 * A v0.1.0 → v0.2.0 update reported success (`/api/health` said 0.2.0) while
 * the UI a user actually saw was 268 commits behind, and nothing anywhere
 * reported the mismatch. These tests pin the two properties that make it
 * reportable: the identity comes from values FROZEN INTO THE BUILD (not read
 * from the running process's environment, which is exactly what would let a
 * restart claim a freshness it does not have), and a missing value is
 * reported as null rather than guessed at.
 */

import { buildInfo } from '@/lib/build-info';
import { GET } from '@/app/web-build/route';

const KEYS = [
  'WHITY_BUILD_ID',
  'WHITY_BUILD_COMMIT',
  'WHITY_BUILD_CORE_VERSION',
  'WHITY_BUILT_AT',
] as const;

describe('buildInfo', () => {
  const saved = new Map<string, string | undefined>();

  beforeEach(() => {
    for (const key of KEYS) {
      saved.set(key, process.env[key]);
      delete process.env[key];
    }
  });

  afterEach(() => {
    for (const key of KEYS) {
      const value = saved.get(key);
      if (value === undefined) {
        delete process.env[key];
      } else {
        process.env[key] = value;
      }
    }
  });

  it('reports the identity baked in at build time', () => {
    process.env.WHITY_BUILD_ID = 'a1b2c3d4e5f6';
    process.env.WHITY_BUILD_COMMIT = 'a1b2c3d4e5f6';
    process.env.WHITY_BUILD_CORE_VERSION = '0.2.0';
    process.env.WHITY_BUILT_AT = '2026-08-14T09:30:00.000Z';

    expect(buildInfo()).toEqual({
      build_id: 'a1b2c3d4e5f6',
      commit: 'a1b2c3d4e5f6',
      core_version: '0.2.0',
      built_at: '2026-08-14T09:30:00.000Z',
    });
  });

  it('reports null rather than inventing an identity it does not have', () => {
    expect(buildInfo()).toEqual({
      build_id: null,
      commit: null,
      core_version: null,
      built_at: null,
    });
  });

  it('treats an empty value as absent', () => {
    process.env.WHITY_BUILD_COMMIT = '';

    expect(buildInfo().commit).toBeNull();
  });
});

describe('GET /web-build', () => {
  it('serves the build identity as JSON', async () => {
    process.env.WHITY_BUILD_CORE_VERSION = '0.2.0';

    const response = await GET();

    expect(response.status).toBe(200);
    expect(await response.json()).toMatchObject({ core_version: '0.2.0' });

    delete process.env.WHITY_BUILD_CORE_VERSION;
  });

  /**
   * A cached answer here is worse than no answer: it would report the
   * PREVIOUS build's identity, which is precisely the lie this endpoint
   * exists to expose.
   */
  it('is never cached', async () => {
    const response = await GET();

    expect(response.headers.get('cache-control')).toContain('no-store');
  });
});
