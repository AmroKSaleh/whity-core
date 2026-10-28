import { test, expect, type APIRequestContext } from '@playwright/test';
import { ADMIN } from './support/constants';
import { createAuthedApi } from './support/api';

/**
 * E2E for "Connect an AI app" (WHIT-697), against the live stack: browser →
 * Next → backend, and then an MCP client — played here by a plain request —
 * calling /mcp with the token the wizard issued.
 *
 * The CI stack starts with MCP_ENABLED=true (e2e.yml); the tenant switch is
 * turned on for this spec and restored afterwards. The app's request goes to the
 * address the wizard handed out, APP_URL + /mcp, which on this stack is the Next
 * server — so this also proves the web tier forwards /mcp.
 */

const CONNECTION_NAME = `e2e-connect-${Date.now()}`;

async function setTenantMcp(api: APIRequestContext, enabled: boolean): Promise<void> {
  const response = await api.patch('/api/v1/settings', { data: { settings: { 'mcp.enabled': enabled ? 'true' : 'false' } } });
  expect(response.ok(), `PATCH mcp.enabled → ${response.status()} ${await response.text()}`).toBe(true);
}

test.describe('Connect an AI app', () => {
  let api: APIRequestContext;

  test.beforeAll(async ({ baseURL }) => {
    api = await createAuthedApi(baseURL ?? '', ADMIN);
    await setTenantMcp(api, true);
  });

  test.afterAll(async () => {
    const list = await api.get('/api/v1/mcp/tokens');
    if (list.ok()) {
      const { tokens } = (await list.json()) as { tokens: { jti: string; name: string }[] };
      for (const token of tokens.filter((t) => t.name === CONNECTION_NAME)) {
        await api.delete(`/api/v1/mcp/tokens/${encodeURIComponent(token.jti)}`);
      }
    }
    await setTenantMcp(api, false);
    await api.dispose();
  });

  test('issues a connection, hands out working setup, and sees the app connect', async ({ page, request }) => {
    await page.goto('/admin/ai-principals');
    await page.getByRole('button', { name: 'Connect an AI app' }).click();

    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('AI apps are turned off')).toHaveCount(0);
    await dialog.getByRole('button', { name: 'Another app' }).click();

    await dialog.getByRole('radio', { name: /Read only/ }).click();
    await expect(dialog.getByText(/Actions the app will be able to use: \d+\./)).toBeVisible();
    await dialog.getByLabel('Name this connection').fill(CONNECTION_NAME);
    await dialog.getByRole('button', { name: 'Create connection' }).click();

    await expect(dialog.getByText('Waiting for Another app to connect…')).toBeVisible();

    // The generic config carries the endpoint and the header, exactly as an app would read them.
    const config = JSON.parse(
      (await dialog.locator('pre code').filter({ hasText: 'mcpServers' }).first().textContent()) ?? '{}'
    ) as { mcpServers: Record<string, { url: string; headers: { Authorization: string } }> };
    const server = Object.values(config.mcpServers)[0];
    expect(server.url).toMatch(/\/mcp$/);
    expect(server.headers.Authorization).toMatch(/^Bearer \S+$/);

    const response = await request.post(server.url, {
      headers: { Authorization: server.headers.Authorization, 'Content-Type': 'application/json' },
      data: { jsonrpc: '2.0', method: 'tools/list', id: 1 },
    });
    expect(response.status(), await response.text()).toBe(200);
    const body = (await response.json()) as { result?: { tools: unknown[] } };
    expect(Array.isArray(body.result?.tools)).toBe(true);

    await expect(dialog.getByText('Connected', { exact: true })).toBeVisible({ timeout: 15_000 });
    await dialog.getByRole('button', { name: 'Done' }).click();

    await expect(page.getByRole('cell', { name: CONNECTION_NAME })).toBeVisible();
  });
});
