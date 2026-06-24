/**
 * WC-318: blocks renderer enhancements — issues #323, #324, #325.
 *
 *   #325 — FormBlock dataSource: pre-populate fields from a GET endpoint on mount
 *   #323 — textInput sensitive: true — renders as password input, sentinel skip on submit
 *   #324 — fileInput encoding: 'base64' — converts file to base64 data URI before submit
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

// ---- mocks ----

jest.mock('@/lib/api-client', () => ({
  apiClient: jest.fn(),
}));

jest.mock('@/hooks/useActionPermission', () => ({
  useActionPermission: jest.fn(),
}));

const mockApiClient = apiClient as jest.MockedFunction<typeof apiClient>;
const mockUseActionPermission =
  actionPermissionModule.useActionPermission as jest.MockedFunction<
    typeof actionPermissionModule.useActionPermission
  >;

function stubResponse(ok: boolean, status: number, body: unknown): Response {
  return {
    ok,
    status,
    json: () => Promise.resolve(body),
  } as unknown as Response;
}

function allowedPermission(): ActionPermission {
  return { allowed: true, hidden: false, disabled: false, reason: null };
}

beforeEach(() => {
  mockApiClient.mockReset();
  mockUseActionPermission.mockReturnValue(allowedPermission());
});

function Wrap({ children }: { children: React.ReactNode }) {
  return <ToastProvider>{children}</ToastProvider>;
}

function renderWrapped(ui: React.ReactElement) {
  return render(ui, { wrapper: Wrap });
}

// ---- #325: dataSource ----

describe('#325 — FormBlock dataSource', () => {
  function makeFormWithDataSource(): Block {
    return {
      type: 'form',
      submit: { method: 'PUT', endpoint: '/api/v1/x/settings' },
      dataSource: { method: 'GET', path: '/api/v1/x/settings' },
      children: [
        { type: 'textInput', name: 'api_key', label: 'API Key' } as Block,
        { type: 'textInput', name: 'site_name', label: 'Site Name' } as Block,
        { type: 'submitButton', label: 'Save' } as Block,
      ],
    } as Block;
  }

  it('pre-populates fields from GET response on mount', async () => {
    mockApiClient.mockResolvedValueOnce(
      stubResponse(true, 200, { api_key: 'secret-key', site_name: 'Acme Corp' })
    );

    renderWrapped(<BlockRenderer blocks={[makeFormWithDataSource()]} />);

    await waitFor(() => {
      expect(screen.getByRole('textbox', { name: /api key/i })).toHaveValue('secret-key');
      expect(screen.getByRole('textbox', { name: /site name/i })).toHaveValue('Acme Corp');
    });

    expect(mockApiClient).toHaveBeenCalledWith(
      '/api/v1/x/settings',
      expect.objectContaining({ method: 'GET' })
    );
  });

  it('fields are disabled while dataSource is loading', () => {
    // Never resolves — keeps the form in loading state.
    mockApiClient.mockReturnValue(new Promise(() => {}));

    renderWrapped(<BlockRenderer blocks={[makeFormWithDataSource()]} />);

    expect(screen.getByRole('textbox', { name: /api key/i })).toBeDisabled();
    expect(screen.getByRole('textbox', { name: /site name/i })).toBeDisabled();
  });

  it('if dataSource fetch fails, form renders empty and shows a load-error message', async () => {
    mockApiClient.mockRejectedValueOnce(new Error('Network error'));

    renderWrapped(<BlockRenderer blocks={[makeFormWithDataSource()]} />);

    await waitFor(() => {
      expect(screen.getByRole('textbox', { name: /api key/i })).not.toBeDisabled();
    });

    expect(screen.getByRole('textbox', { name: /api key/i })).toHaveValue('');
    expect(screen.getByText(/failed to load/i)).toBeInTheDocument();
  });

  it('pre-populated value is sent unchanged in submit payload when not edited', async () => {
    mockApiClient
      .mockResolvedValueOnce(
        stubResponse(true, 200, { api_key: 'my-key', site_name: 'Acme' })
      )
      .mockResolvedValueOnce(stubResponse(true, 200, {}));

    renderWrapped(<BlockRenderer blocks={[makeFormWithDataSource()]} />);

    await waitFor(() =>
      expect(screen.getByRole('textbox', { name: /api key/i })).toHaveValue('my-key')
    );

    await userEvent.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() => expect(mockApiClient).toHaveBeenCalledTimes(2));
    const [, options] = mockApiClient.mock.calls[1] as [string, { body: string }];
    const payload = JSON.parse(options.body) as Record<string, unknown>;
    expect(payload['api_key']).toBe('my-key');
    expect(payload['site_name']).toBe('Acme');
  });

  it('form without dataSource is unaffected (renders empty, no extra fetch)', async () => {
    mockApiClient.mockResolvedValue(stubResponse(true, 200, {}));

    renderWrapped(
      <BlockRenderer
        blocks={[
          {
            type: 'form',
            submit: { method: 'POST', endpoint: '/api/v1/x/save' },
            children: [
              { type: 'textInput', name: 'msg', label: 'Message' } as Block,
              { type: 'submitButton', label: 'Send' } as Block,
            ],
          } as Block,
        ]}
      />
    );

    expect(screen.getByRole('textbox', { name: /message/i })).toHaveValue('');
    expect(screen.getByRole('textbox', { name: /message/i })).not.toBeDisabled();

    await userEvent.click(screen.getByRole('button', { name: /send/i }));
    await waitFor(() => expect(mockApiClient).toHaveBeenCalledTimes(1));
    // Only the submit call — no GET prefetch.
    expect(mockApiClient).toHaveBeenCalledWith('/api/v1/x/save', expect.anything());
  });
});

// ---- #323: sensitive textInput ----

describe('#323 — textInput sensitive: true', () => {
  const SENTINEL = '••••••';

  function makeSensitiveFormWithDataSource(): Block {
    return {
      type: 'form',
      submit: { method: 'PUT', endpoint: '/api/v1/x/settings' },
      dataSource: { method: 'GET', path: '/api/v1/x/settings' },
      children: [
        {
          type: 'textInput',
          name: 'api_key',
          label: 'API Key',
          sensitive: true,
        } as Block,
        { type: 'submitButton', label: 'Save' } as Block,
      ],
    } as Block;
  }

  function makeSensitiveFormPlain(): Block {
    return {
      type: 'form',
      submit: { method: 'PUT', endpoint: '/api/v1/x/settings' },
      children: [
        {
          type: 'textInput',
          name: 'api_key',
          label: 'API Key',
          sensitive: true,
        } as Block,
        { type: 'submitButton', label: 'Save' } as Block,
      ],
    } as Block;
  }

  it('renders as a password input (type="password")', () => {
    renderWrapped(<BlockRenderer blocks={[makeSensitiveFormPlain()]} />);

    const input = screen.getByLabelText(/api key/i);
    expect(input).toHaveAttribute('type', 'password');
  });

  it('when dataSource returns the sentinel, field displays sentinel', async () => {
    mockApiClient.mockResolvedValueOnce(
      stubResponse(true, 200, { api_key: SENTINEL })
    );

    renderWrapped(<BlockRenderer blocks={[makeSensitiveFormWithDataSource()]} />);

    await waitFor(() =>
      expect(screen.getByLabelText(/api key/i)).toHaveValue(SENTINEL)
    );
  });

  it('submitting without changing the sentinel omits the sensitive field from payload', async () => {
    mockApiClient
      .mockResolvedValueOnce(stubResponse(true, 200, { api_key: SENTINEL }))
      .mockResolvedValueOnce(stubResponse(true, 200, {}));

    renderWrapped(<BlockRenderer blocks={[makeSensitiveFormWithDataSource()]} />);

    await waitFor(() =>
      expect(screen.getByLabelText(/api key/i)).toHaveValue(SENTINEL)
    );

    await userEvent.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() => expect(mockApiClient).toHaveBeenCalledTimes(2));
    const [, options] = mockApiClient.mock.calls[1] as [string, { body: string }];
    const payload = JSON.parse(options.body) as Record<string, unknown>;
    expect(payload).not.toHaveProperty('api_key');
  });

  it('submitting a new value sends the new value', async () => {
    mockApiClient.mockResolvedValueOnce(stubResponse(true, 200, {}));

    renderWrapped(<BlockRenderer blocks={[makeSensitiveFormPlain()]} />);

    const input = screen.getByLabelText(/api key/i);
    await userEvent.type(input, 'new-secret');

    await userEvent.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() => expect(mockApiClient).toHaveBeenCalledTimes(1));
    const [, options] = mockApiClient.mock.calls[0] as [string, { body: string }];
    const payload = JSON.parse(options.body) as Record<string, unknown>;
    expect(payload['api_key']).toBe('new-secret');
  });
});

// ---- #324: fileInput encoding: 'base64' ----

/**
 * jsdom's FileReader fires onload as a macrotask. We mock it to fire
 * synchronously (via a resolved Promise — microtask) so the test can
 * reliably check the encoded value is present in the submit payload.
 */
class SyncFileReader {
  result: string | ArrayBuffer | null = null;
  onload: ((e: ProgressEvent<FileReader>) => void) | null = null;
  onerror: ((e: ProgressEvent<FileReader>) => void) | null = null;
  readAsDataURL(file: File): void {
    void Promise.resolve().then(() => {
      this.result = `data:${file.type};base64,UE5HX0JZVEVT`; // stable stub
      this.onload?.({ target: this } as unknown as ProgressEvent<FileReader>);
    });
  }
  readAsText(file: File): void {
    void file.text().then((text) => {
      this.result = text;
      this.onload?.({ target: this } as unknown as ProgressEvent<FileReader>);
    });
  }
}

describe('#324 — fileInput encoding: base64', () => {
  let FileReaderSpy: jest.SpyInstance;

  beforeEach(() => {
    FileReaderSpy = jest
      .spyOn(globalThis, 'FileReader')
      .mockImplementation(() => new SyncFileReader() as unknown as FileReader);
  });

  afterEach(() => {
    FileReaderSpy.mockRestore();
  });

  function makeBase64Form(): Block {
    return {
      type: 'form',
      submit: { method: 'PUT', endpoint: '/api/v1/x/settings' },
      children: [
        {
          type: 'fileInput',
          name: 'logo',
          label: 'Logo',
          accept: '.png,.jpg',
          encoding: 'base64',
        } as Block,
        { type: 'submitButton', label: 'Save' } as Block,
      ],
    } as Block;
  }

  it('with no file selected, logo field is omitted from submit payload', async () => {
    mockApiClient.mockResolvedValueOnce(stubResponse(true, 200, {}));

    renderWrapped(<BlockRenderer blocks={[makeBase64Form()]} />);

    await userEvent.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() => expect(mockApiClient).toHaveBeenCalledTimes(1));
    const [, options] = mockApiClient.mock.calls[0] as [string, { body: string }];
    const payload = JSON.parse(options.body) as Record<string, unknown>;
    expect(payload).not.toHaveProperty('logo');
  });

  it('converts selected file to base64 data URI before submit', async () => {
    mockApiClient.mockResolvedValueOnce(stubResponse(true, 200, {}));

    renderWrapped(<BlockRenderer blocks={[makeBase64Form()]} />);

    const file = new File(['PNG_BYTES'], 'logo.png', { type: 'image/png' });
    const input = screen.getByLabelText(/logo/i);
    await userEvent.upload(input, file);

    // Wait for the mock FileReader's microtask to resolve and update form state.
    await waitFor(() => {
      // The context value is updated when onload fires; clicking submit will
      // include the base64 value only after state is set.
    });

    await userEvent.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() => expect(mockApiClient).toHaveBeenCalledTimes(1));
    const [, options] = mockApiClient.mock.calls[0] as [string, { body: string }];
    const payload = JSON.parse(options.body) as Record<string, unknown>;
    expect(typeof payload['logo']).toBe('string');
    expect(payload['logo'] as string).toMatch(/^data:image\/png;base64,/);
  });
});
