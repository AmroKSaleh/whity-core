import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { DemoCatalogList } from '@amroksaleh/features/demo-catalog';
import { LanguageProvider } from '@amroksaleh/features/i18n';
import type { DemoCatalogAdapter, DemoCatalogItem } from '@amroksaleh/features/demo-catalog';

/**
 * Component tests for the multi-client extraction pilot's DemoCatalogList
 * (@amroksaleh/features/demo-catalog). Verifies the component's loading/
 * empty/error/populated states and that it never fetches directly — every
 * assertion here drives a hand-rolled fake `DemoCatalogAdapter`, proving the
 * component's data-source-agnostic contract from the consumer's side.
 */

function fakeAdapter(overrides: Partial<DemoCatalogAdapter> = {}): DemoCatalogAdapter {
  return {
    list: jest.fn().mockResolvedValue([]),
    get: jest.fn().mockResolvedValue(null),
    save: jest.fn(),
    ...overrides,
  };
}

const item = (over: Partial<DemoCatalogItem> = {}): DemoCatalogItem => ({
  id: 1,
  name: 'Sample item',
  description: 'A description',
  status: 'active',
  createdAt: '2026-01-01T00:00:00Z',
  updatedAt: '2026-01-01T00:00:00Z',
  ...over,
});

const LANGUAGES = [
  { code: 'en', name: 'English', direction: 'ltr' },
  { code: 'ar', name: 'العربية', direction: 'rtl' },
];

/** Serves one `plugin` bundle to the LanguageProvider; everything else 401s. */
function mockLanguageApi(bundle: Record<string, string>, language: string) {
  (global.fetch as jest.Mock).mockImplementation((url: string) => {
    if (url === '/api/v1/languages') {
      return Promise.resolve({ ok: true, status: 200, json: async () => ({ languages: LANGUAGES }) });
    }
    if (url === '/api/v1/settings/language') {
      return Promise.resolve({
        ok: true,
        status: 200,
        json: async () => ({ language_code: language, available_languages: LANGUAGES }),
      });
    }
    if (url === `/api/v1/translations/${language}/plugin`) {
      return Promise.resolve({ ok: true, status: 200, json: async () => ({ translations: bundle }) });
    }
    return Promise.resolve({ ok: false, status: 401, json: async () => ({}) });
  });
}

describe('DemoCatalogList', () => {
  beforeEach(() => {
    // Only the bundle test drives fetch; the rest never reach the network,
    // and a real `fetch` firing from LanguageProvider would be a slow surprise.
    global.fetch = jest.fn().mockResolvedValue({ ok: false, status: 401, json: async () => ({}) });
    localStorage.clear();
  });

  it('renders a busy skeleton while the adapter list() call is pending', () => {
    const adapter = fakeAdapter({ list: jest.fn(() => new Promise(() => {})) });

    const { container } = render(
      <DemoCatalogList adapter={adapter} onSelect={jest.fn()} onCreate={jest.fn()} />
    );

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull();
  });

  it('renders an empty state when the adapter resolves an empty list', async () => {
    const adapter = fakeAdapter({ list: jest.fn().mockResolvedValue([]) });

    render(<DemoCatalogList adapter={adapter} onSelect={jest.fn()} onCreate={jest.fn()} />);

    expect(await screen.findByText('No items yet')).toBeInTheDocument();
  });

  it('renders an error state and retries via the adapter when list() rejects', async () => {
    const list = jest.fn().mockRejectedValueOnce(new Error('network down')).mockResolvedValueOnce([item()]);
    const adapter = fakeAdapter({ list });
    const user = userEvent.setup();

    render(<DemoCatalogList adapter={adapter} onSelect={jest.fn()} onCreate={jest.fn()} />);

    expect(await screen.findByText('Something went wrong')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Try again' }));

    expect(await screen.findByText('Sample item')).toBeInTheDocument();
    expect(list).toHaveBeenCalledTimes(2);
  });

  it('renders items with name/description/status and calls onSelect on click', async () => {
    const onSelect = jest.fn();
    const adapter = fakeAdapter({
      list: jest.fn().mockResolvedValue([
        item({ id: 1, name: 'Active one', status: 'active' }),
        item({ id: 2, name: 'Archived one', status: 'archived', description: null }),
      ]),
    });
    const user = userEvent.setup();

    render(<DemoCatalogList adapter={adapter} onSelect={onSelect} onCreate={jest.fn()} />);

    expect(await screen.findByText('Active one')).toBeInTheDocument();
    expect(screen.getByText('Archived one')).toBeInTheDocument();
    expect(screen.getByText('Active')).toBeInTheDocument();
    expect(screen.getByText('Archived')).toBeInTheDocument();

    await user.click(screen.getByText('Active one'));
    expect(onSelect).toHaveBeenCalledWith(1);
  });

  it('calls onCreate when the create button is clicked', async () => {
    const onCreate = jest.fn();
    const adapter = fakeAdapter({ list: jest.fn().mockResolvedValue([]) });
    const user = userEvent.setup();

    render(<DemoCatalogList adapter={adapter} onSelect={jest.fn()} onCreate={onCreate} />);

    await waitFor(() => expect(adapter.list).toHaveBeenCalled());
    await user.click(screen.getByRole('button', { name: /New item/ }));
    expect(onCreate).toHaveBeenCalledTimes(1);
  });

  /**
   * REPLACES a test called "translates labels through an injected t()", which
   * passed a `t` prop and asserted `translated:demoCatalog.list.emptyTitle`.
   *
   * It proved the component COULD be translated. Nothing in the product ever
   * did — web's screen mounted this without a `t`, and so did the SPA harness —
   * so the screen shipped rendering `demoCatalog.list.emptyTitle` at
   * administrators while this test stayed green (#984). Every other assertion
   * in this file named a raw key too, which is how the defect read as intended
   * behaviour.
   *
   * So the property worth pinning is not "an injected translator is used" but
   * "the real one is", end to end: bundle present -> Arabic; key missing ->
   * the English fallback at the call site, never a raw key.
   */
  it('renders the real bundle, and falls back to English rather than a key', async () => {
    const adapter = fakeAdapter({ list: jest.fn().mockResolvedValue([]) });

    // Deliberately partial: `emptyDescription` is absent from the bundle.
    mockLanguageApi({ 'demoCatalog.list.emptyTitle': 'لا توجد عناصر بعد' }, 'ar');

    render(
      <LanguageProvider>
        <DemoCatalogList adapter={adapter} onSelect={jest.fn()} onCreate={jest.fn()} />
      </LanguageProvider>
    );

    expect(await screen.findByText('لا توجد عناصر بعد')).toBeInTheDocument();
    expect(
      await screen.findByText('Create the first item to see it listed here.')
    ).toBeInTheDocument();
    expect(screen.queryByText(/^demoCatalog\./)).not.toBeInTheDocument();
  });
});
