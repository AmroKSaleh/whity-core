/**
 * @jest-environment node
 *
 * Regression guard: the catch-all API proxy MUST forward null-body HTTP
 * statuses (204 No Content, 205 Reset Content, 304 Not Modified) without a
 * body.  Constructing `new Response(body, { status })` with a non-null body
 * for these statuses throws a TypeError in the Fetch spec ("Invalid response
 * status code"), which the proxy's catch block then re-surfaces as HTTP 500.
 * That was the root cause of every DELETE operation returning 500 to the
 * frontend even though the backend had already applied the change.
 *
 * These tests:
 *   1. Verify that a 204 backend response becomes a 204 client response
 *      (not 500).
 *   2. Verify that 205 and 304 are treated identically.
 *   3. Verify that a normal 200 JSON response still passes its body through.
 */

const BACKEND = 'http://backend.test';

jest.mock('@/lib/backend-url', () => ({
  backendUrl: () => BACKEND,
}));

jest.mock('next/headers', () => ({
  cookies: jest.fn(async () => ({
    getAll: () => [] as { name: string; value: string }[],
  })),
}));

import { DELETE as catchAllDELETE, GET as catchAllGET } from '@/app/api/[...path]/route';

describe('proxy null-body status passthrough', () => {
  const realFetch = global.fetch;

  afterEach(() => {
    global.fetch = realFetch;
    jest.clearAllMocks();
  });

  it('forwards a 204 backend response as 204 (not 500)', async () => {
    global.fetch = jest.fn(async (): Promise<Response> =>
      new Response(null, { status: 204 })
    ) as unknown as typeof fetch;

    const request = new Request('http://localhost:3000/api/ous/42', {
      method: 'DELETE',
    });

    const result = await catchAllDELETE(request);

    expect(result.status).toBe(204);
  });

  it('forwards a 205 backend response as 205 (not 500)', async () => {
    global.fetch = jest.fn(async (): Promise<Response> =>
      new Response(null, { status: 205 })
    ) as unknown as typeof fetch;

    const request = new Request('http://localhost:3000/api/some/resource', {
      method: 'DELETE',
    });

    const result = await catchAllDELETE(request);

    expect(result.status).toBe(205);
  });

  it('forwards a 304 backend response as 304 (not 500)', async () => {
    global.fetch = jest.fn(async (): Promise<Response> =>
      new Response(null, { status: 304 })
    ) as unknown as typeof fetch;

    const request = new Request('http://localhost:3000/api/some/resource', {
      method: 'GET',
    });

    const result = await catchAllGET(request);

    expect(result.status).toBe(304);
  });

  it('still passes the body through on a normal 200 JSON response', async () => {
    global.fetch = jest.fn(async (): Promise<Response> =>
      new Response(JSON.stringify({ id: 1 }), {
        status: 200,
        headers: { 'content-type': 'application/json' },
      })
    ) as unknown as typeof fetch;

    const request = new Request('http://localhost:3000/api/users/1', {
      method: 'GET',
    });

    const result = await catchAllGET(request);

    expect(result.status).toBe(200);
    const body = await result.text();
    expect(body).toContain('"id":1');
  });
});
