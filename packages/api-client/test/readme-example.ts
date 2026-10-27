import { createApiClient, createMemoryTokenStore } from '../src/index.ts';
import type { TokenStore } from '../src/index.ts';
declare const password: string; declare const code: string; declare const chosen: { tenant_id: number };
declare const SecureStore: { getItemAsync(k: string): Promise<string|null>; setItemAsync(k: string, v: string): Promise<void>; deleteItemAsync(k: string): Promise<void> };
export async function demo() {

const api = createApiClient({
  baseUrl: 'https://api.example.net', // the backend's origin
  auth: 'token',
  tokenStore: createMemoryTokenStore(), // the default; see "Token storage"
});

const { data, error } = await api.POST('/api/v1/login', {
  body: { email: 'jane@example.com', password },
});

if (data && 'requires_2fa' in data) {
  // 202: finish with the code the user types
  await api.POST('/api/v1/login/2fa', { body: { temp_token: data.temp_token, code } });
} else if (data && 'requires_tenant_selection' in data) {
  // the user belongs to several tenants: let them pick one
  await api.POST('/api/v1/auth/select-tenant', {
    body: { selection_token: data.selection_token, tenant_id: chosen.tenant_id },
  });
}

const me = await api.GET('/api/v1/me'); // carries the bearer automatically
await api.POST('/api/v1/auth/logout'); // revokes both tokens, clears the store

}
const tokenStore: TokenStore = {
  get: () => SecureStore.getItemAsync('whity').then((v) => (v ? JSON.parse(v) : null)),
  set: (t) => (t ? SecureStore.setItemAsync('whity', JSON.stringify(t)) : SecureStore.deleteItemAsync('whity')),
};
