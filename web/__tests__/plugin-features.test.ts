import { fetchPluginFeatures, type PluginFeature } from '@/lib/plugin-features';
import { apiClient } from '@/lib/api-client';

jest.mock('@/lib/api-client', () => ({
  apiClient: jest.fn(),
}));

const apiClientMock = apiClient as jest.MockedFunction<typeof apiClient>;

/** Build a minimal Response-shaped stub without pulling in real fetch. */
function stubResponse(ok: boolean, status: number, body: unknown): Response {
  return {
    ok,
    status,
    json: async () => body,
  } as unknown as Response;
}

const FEATURE: PluginFeature = {
  id: 'hello-greetings',
  plugin: 'HelloWorld',
  label: 'Greetings',
  icon: 'message-circle',
  group: 'plugins',
  order: 10,
  screen: 'crud',
  resource: { basePath: '/api/hello/greetings', titleField: 'message' },
  action: null,
  requiredPermission: 'hello:view',
  capabilities: { canCreate: true, canEdit: true, canDelete: false },
};

describe('fetchPluginFeatures', () => {
  beforeEach(() => {
    apiClientMock.mockReset();
  });

  it('requests GET /api/frontend/features and returns the data array', async () => {
    apiClientMock.mockResolvedValue(stubResponse(true, 200, { data: [FEATURE] }));

    const features = await fetchPluginFeatures();

    expect(apiClientMock).toHaveBeenCalledWith('/api/frontend/features');
    expect(features).toEqual([FEATURE]);
    // The server-computed write capabilities (issue #199) are carried through.
    expect(features[0].capabilities).toEqual({
      canCreate: true,
      canEdit: true,
      canDelete: false,
    });
  });

  it('returns an empty list on a non-ok response', async () => {
    apiClientMock.mockResolvedValue(stubResponse(false, 403, { error: 'nope' }));

    await expect(fetchPluginFeatures()).resolves.toEqual([]);
  });

  it('returns an empty list when the body has no data array', async () => {
    apiClientMock.mockResolvedValue(stubResponse(true, 200, {}));

    await expect(fetchPluginFeatures()).resolves.toEqual([]);
  });

  it('returns an empty list when the body is not JSON', async () => {
    apiClientMock.mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => {
        throw new Error('invalid json');
      },
    } as unknown as Response);

    await expect(fetchPluginFeatures()).resolves.toEqual([]);
  });

  it('returns an empty list when the request itself rejects', async () => {
    apiClientMock.mockRejectedValue(new Error('network down'));

    await expect(fetchPluginFeatures()).resolves.toEqual([]);
  });
});
