/**
 * WC-532 A5: math + markdown display blocks and the Markdown-aware
 * richTextInput (submits Markdown source, shows a live preview).
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { BlockRenderer } from '@/components/plugin/blocks/block-renderer';
import type { Block } from '@/lib/plugin-features';
import { apiClient } from '@/lib/api-client';
import * as actionPermissionModule from '@/hooks/useActionPermission';
import type { ActionPermission } from '@/hooks/useActionPermission';
import { ToastProvider } from '@/lib/toast-context';

// TextEncoder/TextDecoder (needed by KaTeX) are polyfilled globally in jest.setup.js.

jest.mock('@/lib/api-client', () => ({ apiClient: jest.fn() }));
jest.mock('@/hooks/useActionPermission', () => ({ useActionPermission: jest.fn() }));

const mockApiClient = apiClient as jest.MockedFunction<typeof apiClient>;
const mockUseActionPermission =
  actionPermissionModule.useActionPermission as jest.MockedFunction<typeof actionPermissionModule.useActionPermission>;

function stubResponse(ok: boolean, status: number, body: unknown): Response {
  return { ok, status, json: () => Promise.resolve(body) } as unknown as Response;
}

beforeEach(() => {
  jest.clearAllMocks();
  mockUseActionPermission.mockReturnValue({ allowed: true, hidden: false, disabled: false, reason: null } as ActionPermission);
});

function renderWrapped(ui: React.ReactElement) {
  return render(ui, { wrapper: ({ children }) => <ToastProvider>{children}</ToastProvider> });
}

describe('BlockRenderer — WC-532 A5', () => {
  it('math block renders KaTeX', () => {
    const { container } = renderWrapped(
      <BlockRenderer blocks={[{ type: 'math', expression: 'e^{i\\pi}+1=0', block: true } as Block]} />
    );
    expect(container.querySelector('.katex')).not.toBeNull();
  });

  it('markdown block renders formatted content (no raw HTML injection)', () => {
    const { container } = renderWrapped(
      <BlockRenderer blocks={[{ type: 'markdown', content: '## Hi\n\n**bold** <script>x</script>' } as Block]} />
    );
    expect(container.querySelector('h2')?.textContent).toBe('Hi');
    expect(container.querySelector('strong')?.textContent).toBe('bold');
    expect(container.querySelector('script')).toBeNull();
  });

  it('richTextInput submits Markdown source and shows a live preview', async () => {
    mockApiClient.mockResolvedValue(stubResponse(true, 200, {}));
    const blocks: Block[] = [
      {
        type: 'form',
        submit: { method: 'POST', endpoint: '/api/v1/x/save' },
        children: [
          { type: 'richTextInput', name: 'notes', label: 'Notes' } as Block,
          { type: 'submitButton', label: 'Save' } as Block,
        ],
      } as Block,
    ];
    const { container } = renderWrapped(<BlockRenderer blocks={blocks} />);

    const textarea = screen.getByRole('textbox', { name: /notes/i });
    await userEvent.type(textarea, '# Title');

    // Live preview renders the markdown as it's typed.
    await waitFor(() => expect(container.querySelector('[data-slot="richtext-preview"] h1')?.textContent).toBe('Title'));

    await userEvent.click(screen.getByRole('button', { name: /save/i }));
    await waitFor(() => expect(mockApiClient).toHaveBeenCalledTimes(1));
    const [, options] = mockApiClient.mock.calls[0] as [string, { body: string }];
    expect(JSON.parse(options.body)['notes']).toBe('# Title');
  });
});
