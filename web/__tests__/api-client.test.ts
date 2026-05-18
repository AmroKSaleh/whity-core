/**
 * API Client Tests
 *
 * Tests for the apiClient function with automatic token refresh on 401
 */

import { apiClient, type ApiClientOptions } from '@/lib/api-client';

// Mock fetch
global.fetch = jest.fn();

const mockFetch = global.fetch as jest.MockedFunction<typeof fetch>;

describe('apiClient', () => {
  beforeEach(() => {
    mockFetch.mockClear();
    // Set default API URL
    process.env.NEXT_PUBLIC_API_URL = 'http://localhost:8000';
  });

  afterEach(() => {
    jest.clearAllMocks();
  });

  test('includes credentials in request', async () => {
    // Mock a successful 200 response
    mockFetch.mockResolvedValueOnce(
      new Response(JSON.stringify({ success: true }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    );

    await apiClient('/api/users');

    expect(mockFetch).toHaveBeenCalledTimes(1);
    expect(mockFetch).toHaveBeenCalledWith(
      'http://localhost:8000/api/users',
      expect.objectContaining({
        credentials: 'include',
      })
    );
  });

  test('returns 200 response immediately', async () => {
    const successResponse = new Response(JSON.stringify({ data: 'test' }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    });

    mockFetch.mockResolvedValueOnce(successResponse);

    const response = await apiClient('/api/users');

    expect(response.status).toBe(200);
    expect(mockFetch).toHaveBeenCalledTimes(1); // Only one call, no refresh
  });

  test('returns non-401 error response immediately', async () => {
    const errorResponse = new Response(JSON.stringify({ error: 'Not found' }), {
      status: 404,
      headers: { 'Content-Type': 'application/json' },
    });

    mockFetch.mockResolvedValueOnce(errorResponse);

    const response = await apiClient('/api/users/999');

    expect(response.status).toBe(404);
    expect(mockFetch).toHaveBeenCalledTimes(1); // Only one call, no refresh
  });

  test('retries 401 with refresh when credentials provided', async () => {
    // First call returns 401
    const unauthorizedResponse = new Response(
      JSON.stringify({ error: 'Unauthorized' }),
      {
        status: 401,
        headers: { 'Content-Type': 'application/json' },
      }
    );

    // Refresh succeeds
    const refreshResponse = new Response(null, { status: 200 });

    // Retry succeeds
    const retryResponse = new Response(
      JSON.stringify({ data: 'success' }),
      {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }
    );

    mockFetch
      .mockResolvedValueOnce(unauthorizedResponse)
      .mockResolvedValueOnce(refreshResponse)
      .mockResolvedValueOnce(retryResponse);

    const response = await apiClient('/api/protected');

    expect(response.status).toBe(200);
    expect(mockFetch).toHaveBeenCalledTimes(3);

    // First call: original request
    expect(mockFetch.mock.calls[0][0]).toBe('http://localhost:8000/api/protected');

    // Second call: refresh endpoint
    expect(mockFetch.mock.calls[1][0]).toBe('http://localhost:8000/api/auth/refresh');
    expect(mockFetch.mock.calls[1][1]).toEqual(
      expect.objectContaining({
        method: 'POST',
        credentials: 'include',
      })
    );

    // Third call: retry original request
    expect(mockFetch.mock.calls[2][0]).toBe('http://localhost:8000/api/protected');
  });

  test('returns 401 if refresh fails', async () => {
    // First call returns 401
    const unauthorizedResponse = new Response(
      JSON.stringify({ error: 'Unauthorized' }),
      {
        status: 401,
        headers: { 'Content-Type': 'application/json' },
      }
    );

    // Refresh fails with 401
    const refreshFailResponse = new Response(
      JSON.stringify({ error: 'Unauthorized' }),
      {
        status: 401,
        headers: { 'Content-Type': 'application/json' },
      }
    );

    mockFetch
      .mockResolvedValueOnce(unauthorizedResponse)
      .mockResolvedValueOnce(refreshFailResponse);

    const response = await apiClient('/api/protected');

    expect(response.status).toBe(401);
    expect(mockFetch).toHaveBeenCalledTimes(2); // Original request + refresh attempt
  });

  test('returns 401 if refresh network error', async () => {
    // First call returns 401
    const unauthorizedResponse = new Response(
      JSON.stringify({ error: 'Unauthorized' }),
      {
        status: 401,
        headers: { 'Content-Type': 'application/json' },
      }
    );

    // Refresh fails with network error
    mockFetch
      .mockResolvedValueOnce(unauthorizedResponse)
      .mockRejectedValueOnce(new Error('Network error'));

    const response = await apiClient('/api/protected');

    expect(response.status).toBe(401); // Returns original 401 response
    expect(mockFetch).toHaveBeenCalledTimes(2);
  });

  test('does not retry if skipRefresh is true', async () => {
    // Return 401 response
    const unauthorizedResponse = new Response(
      JSON.stringify({ error: 'Unauthorized' }),
      {
        status: 401,
        headers: { 'Content-Type': 'application/json' },
      }
    );

    mockFetch.mockResolvedValueOnce(unauthorizedResponse);

    const options: ApiClientOptions = { skipRefresh: true };
    const response = await apiClient('/api/auth/refresh', options);

    expect(response.status).toBe(401);
    expect(mockFetch).toHaveBeenCalledTimes(1); // Only one call, no refresh attempt
  });

  test('refresh request includes credentials', async () => {
    // First call returns 401
    const unauthorizedResponse = new Response(
      JSON.stringify({ error: 'Unauthorized' }),
      {
        status: 401,
        headers: { 'Content-Type': 'application/json' },
      }
    );

    // Refresh succeeds
    const refreshResponse = new Response(null, { status: 200 });

    // Retry succeeds
    const retryResponse = new Response(
      JSON.stringify({ data: 'success' }),
      {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }
    );

    mockFetch
      .mockResolvedValueOnce(unauthorizedResponse)
      .mockResolvedValueOnce(refreshResponse)
      .mockResolvedValueOnce(retryResponse);

    await apiClient('/api/protected');

    // Check refresh call has credentials
    const refreshCall = mockFetch.mock.calls[1];
    expect(refreshCall[1]).toEqual(
      expect.objectContaining({
        credentials: 'include',
      })
    );
  });

  test('constructs full URL from relative path', async () => {
    mockFetch.mockResolvedValueOnce(
      new Response(JSON.stringify({ success: true }), { status: 200 })
    );

    await apiClient('/api/users');

    expect(mockFetch).toHaveBeenCalledWith(
      'http://localhost:8000/api/users',
      expect.any(Object)
    );
  });

  test('accepts absolute URLs', async () => {
    mockFetch.mockResolvedValueOnce(
      new Response(JSON.stringify({ success: true }), { status: 200 })
    );

    await apiClient('https://api.example.com/users');

    expect(mockFetch).toHaveBeenCalledWith(
      'https://api.example.com/users',
      expect.any(Object)
    );
  });

  test('uses custom API URL from environment', async () => {
    process.env.NEXT_PUBLIC_API_URL = 'https://custom.api.com';

    mockFetch.mockResolvedValueOnce(
      new Response(JSON.stringify({ success: true }), { status: 200 })
    );

    await apiClient('/api/users');

    expect(mockFetch).toHaveBeenCalledWith(
      'https://custom.api.com/api/users',
      expect.any(Object)
    );
  });

  test('passes through additional fetch options', async () => {
    mockFetch.mockResolvedValueOnce(
      new Response(JSON.stringify({ success: true }), { status: 200 })
    );

    const options: ApiClientOptions = {
      method: 'POST',
      headers: { 'X-Custom-Header': 'value' },
    };

    await apiClient('/api/users', options);

    expect(mockFetch).toHaveBeenCalledWith(
      'http://localhost:8000/api/users',
      expect.objectContaining({
        method: 'POST',
        credentials: 'include',
        headers: { 'X-Custom-Header': 'value' },
      })
    );
  });

  test('handles 3xx success responses', async () => {
    mockFetch.mockResolvedValueOnce(
      new Response(null, {
        status: 302,
        headers: { Location: '/new-location' },
      })
    );

    const response = await apiClient('/api/users');

    expect(response.status).toBe(302);
    expect(mockFetch).toHaveBeenCalledTimes(1); // No refresh for 3xx
  });

  test('handles 5xx server error responses', async () => {
    mockFetch.mockResolvedValueOnce(
      new Response(JSON.stringify({ error: 'Internal Server Error' }), {
        status: 500,
      })
    );

    const response = await apiClient('/api/users');

    expect(response.status).toBe(500);
    expect(mockFetch).toHaveBeenCalledTimes(1); // No refresh for 5xx
  });

  test('retry uses same method as original request', async () => {
    const unauthorizedResponse = new Response(
      JSON.stringify({ error: 'Unauthorized' }),
      { status: 401 }
    );

    const refreshResponse = new Response(null, { status: 200 });

    const retryResponse = new Response(
      JSON.stringify({ success: true }),
      { status: 200 }
    );

    mockFetch
      .mockResolvedValueOnce(unauthorizedResponse)
      .mockResolvedValueOnce(refreshResponse)
      .mockResolvedValueOnce(retryResponse);

    const options: ApiClientOptions = {
      method: 'DELETE',
      body: JSON.stringify({ id: 123 }),
    };

    await apiClient('/api/users/123', options);

    // Check retry call preserves method and body
    const retryCall = mockFetch.mock.calls[2];
    expect(retryCall[1]).toEqual(
      expect.objectContaining({
        method: 'DELETE',
        body: JSON.stringify({ id: 123 }),
      })
    );
  });
});
