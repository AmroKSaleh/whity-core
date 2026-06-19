/**
 * WC-227 + WC-231: web BlockRenderer for `screen: 'blocks'` plugin features.
 *
 * The renderer draws a platform-neutral tree of semantic UI blocks (the SP1
 * block set, mirrored from the SDK `BlockContract`) using existing
 * design-token components. These tests assert:
 *   - every block type maps to its expected token component / visible text;
 *   - containers recurse into their children;
 *   - DEFENSIVE: an unknown type / missing required prop renders a quiet
 *     "Unsupported block" placeholder and never throws;
 *   - NO INJECTION: plugin strings are React text children — a `text` value of
 *     `<img src=x onerror=alert(1)>` renders LITERALLY (no <img> is created);
 *   - a `button` with a non-relative href is inert (renders no navigating link).
 *
 * WC-231 additions:
 *   - data-bound blocks (dataTable/dataStat/dataList) show loading skeletons,
 *     error + Retry, empty + emptyText, and ready state (reusing SP1 static
 *     renderers with fetched data).
 *   - INJECTION guard: a fetched cell value `<img src=x onerror=alert(1)>`
 *     renders as literal text, never an HTML element.
 */

import React from 'react';
import { render, screen, within, waitFor } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { BlockRenderer } from '@/components/plugin/blocks/block-renderer';
import type { Block } from '@/lib/plugin-features';
import { apiClient } from '@/lib/api-client';

jest.mock('@/lib/api-client', () => ({
  apiClient: jest.fn(),
}));

const mockApiClient = apiClient as jest.MockedFunction<typeof apiClient>;

function stubResponse(ok: boolean, status: number, body: unknown): Response {
  return {
    ok,
    status,
    json: () => Promise.resolve(body),
  } as unknown as Response;
}

beforeEach(() => {
  mockApiClient.mockReset();
});

describe('BlockRenderer', () => {
  it('renders a heading at the requested semantic level', () => {
    render(
      <BlockRenderer
        blocks={[{ type: 'heading', level: 2, text: 'Dashboard' }]}
      />
    );
    const heading = screen.getByRole('heading', { level: 2 });
    expect(heading).toHaveTextContent('Dashboard');
  });

  it('renders text and its muted tone', () => {
    render(
      <BlockRenderer
        blocks={[{ type: 'text', value: 'Quiet note', tone: 'muted' }]}
      />
    );
    expect(screen.getByText('Quiet note')).toHaveClass('text-muted-foreground');
  });

  it('renders an alert with its title and body', () => {
    render(
      <BlockRenderer
        blocks={[
          {
            type: 'alert',
            variant: 'success',
            title: 'All good',
            body: 'Everything is fine',
          },
        ]}
      />
    );
    expect(screen.getByText('All good')).toBeInTheDocument();
    expect(screen.getByText('Everything is fine')).toBeInTheDocument();
  });

  it('renders a badge with its label', () => {
    render(
      <BlockRenderer
        blocks={[{ type: 'badge', variant: 'info', label: 'Beta' }]}
      />
    );
    expect(screen.getByText('Beta')).toBeInTheDocument();
  });

  it('renders a stat with its label and value', () => {
    render(
      <BlockRenderer
        blocks={[
          { type: 'stat', label: 'Users', value: '1,024', hint: 'this week' },
        ]}
      />
    );
    expect(screen.getByText('Users')).toBeInTheDocument();
    expect(screen.getByText('1,024')).toBeInTheDocument();
    expect(screen.getByText('this week')).toBeInTheDocument();
  });

  it('renders a keyValue definition list', () => {
    render(
      <BlockRenderer
        blocks={[
          {
            type: 'keyValue',
            items: [
              { label: 'Status', value: 'Active' },
              { label: 'Plan', value: 'Pro' },
            ],
          },
        ]}
      />
    );
    expect(screen.getByText('Status')).toBeInTheDocument();
    expect(screen.getByText('Active')).toBeInTheDocument();
    expect(screen.getByText('Plan')).toBeInTheDocument();
    expect(screen.getByText('Pro')).toBeInTheDocument();
  });

  it('renders an ordered list of items', () => {
    const { container } = render(
      <BlockRenderer
        blocks={[{ type: 'list', ordered: true, items: ['One', 'Two'] }]}
      />
    );
    expect(container.querySelector('ol')).not.toBeNull();
    expect(screen.getByText('One')).toBeInTheDocument();
    expect(screen.getByText('Two')).toBeInTheDocument();
  });

  it('renders an unordered list by default', () => {
    const { container } = render(
      <BlockRenderer blocks={[{ type: 'list', items: ['Alpha'] }]} />
    );
    expect(container.querySelector('ul')).not.toBeNull();
    expect(screen.getByText('Alpha')).toBeInTheDocument();
  });

  it('renders a table with its columns and static rows', () => {
    render(
      <BlockRenderer
        blocks={[
          {
            type: 'table',
            columns: [
              { key: 'name', label: 'Name' },
              { key: 'role', label: 'Role' },
            ],
            rows: [
              { name: 'Ada', role: 'Admin' },
              { name: 'Bo', role: 'Editor' },
            ],
          },
        ]}
      />
    );
    expect(screen.getByText('Name')).toBeInTheDocument();
    expect(screen.getByText('Role')).toBeInTheDocument();
    expect(screen.getByText('Ada')).toBeInTheDocument();
    expect(screen.getByText('Admin')).toBeInTheDocument();
    expect(screen.getByText('Bo')).toBeInTheDocument();
    expect(screen.getByText('Editor')).toBeInTheDocument();
  });

  it('renders code content inside a <pre>', () => {
    const { container } = render(
      <BlockRenderer
        blocks={[
          { type: 'code', language: 'json', content: '{ "ok": true }' },
        ]}
      />
    );
    const pre = container.querySelector('pre');
    expect(pre).not.toBeNull();
    expect(pre).toHaveTextContent('{ "ok": true }');
  });

  it('renders an icon by its name', () => {
    const { container } = render(
      <BlockRenderer blocks={[{ type: 'icon', name: 'check' }]} />
    );
    // Tabler icons render an <svg>; the resolver must produce one.
    expect(container.querySelector('svg')).not.toBeNull();
  });

  it('renders a divider', () => {
    const { container } = render(
      <BlockRenderer blocks={[{ type: 'divider' }]} />
    );
    expect(container.querySelector('hr')).not.toBeNull();
  });

  it('renders a section with its title and recurses into children', () => {
    render(
      <BlockRenderer
        blocks={[
          {
            type: 'section',
            title: 'Overview',
            children: [{ type: 'text', value: 'Inside the section' }],
          },
        ]}
      />
    );
    expect(screen.getByText('Overview')).toBeInTheDocument();
    expect(screen.getByText('Inside the section')).toBeInTheDocument();
  });

  it('renders a card with title/description and recurses into children', () => {
    render(
      <BlockRenderer
        blocks={[
          {
            type: 'card',
            title: 'Card title',
            description: 'Card description',
            children: [{ type: 'text', value: 'Card body' }],
          },
        ]}
      />
    );
    expect(screen.getByText('Card title')).toBeInTheDocument();
    expect(screen.getByText('Card description')).toBeInTheDocument();
    expect(screen.getByText('Card body')).toBeInTheDocument();
  });

  it('renders a grid that recurses into its children', () => {
    render(
      <BlockRenderer
        blocks={[
          {
            type: 'grid',
            columns: 2,
            children: [
              { type: 'text', value: 'Cell A' },
              { type: 'text', value: 'Cell B' },
            ],
          },
        ]}
      />
    );
    expect(screen.getByText('Cell A')).toBeInTheDocument();
    expect(screen.getByText('Cell B')).toBeInTheDocument();
  });

  it('renders a row that recurses into its children', () => {
    render(
      <BlockRenderer
        blocks={[
          {
            type: 'row',
            align: 'between',
            children: [{ type: 'text', value: 'Row child' }],
          },
        ]}
      />
    );
    expect(screen.getByText('Row child')).toBeInTheDocument();
  });

  it('renders tabs with each tab label and its panel children', () => {
    render(
      <BlockRenderer
        blocks={[
          {
            type: 'tabs',
            children: [
              {
                type: 'tab',
                label: 'First',
                children: [{ type: 'text', value: 'First panel' }],
              },
              {
                type: 'tab',
                label: 'Second',
                children: [{ type: 'text', value: 'Second panel' }],
              },
            ],
          },
        ]}
      />
    );
    // Both tab triggers are present.
    expect(screen.getByRole('tab', { name: 'First' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Second' })).toBeInTheDocument();
    // The first (default) panel content is visible.
    expect(screen.getByText('First panel')).toBeInTheDocument();
  });

  it('renders an internal link for a relative button href', () => {
    render(
      <BlockRenderer
        blocks={[{ type: 'button', label: 'Go', href: '/admin/x/dash' }]}
      />
    );
    const link = screen.getByRole('link', { name: 'Go' });
    expect(link).toHaveAttribute('href', '/admin/x/dash');
  });

  // ----- Defensive / security -----

  it('renders an "Unsupported block" placeholder for an unknown type and does not throw', () => {
    const blocks = [{ type: 'mystery-widget' }] as unknown as Block[];
    expect(() => render(<BlockRenderer blocks={blocks} />)).not.toThrow();
    expect(screen.getByText(/Unsupported block/i)).toHaveTextContent(
      'mystery-widget'
    );
  });

  it('renders an "Unsupported block" placeholder when a required prop is missing', () => {
    // A heading without `text` is invalid per the contract.
    const blocks = [{ type: 'heading', level: 1 }] as unknown as Block[];
    expect(() => render(<BlockRenderer blocks={blocks} />)).not.toThrow();
    expect(screen.getByText(/Unsupported block/i)).toBeInTheDocument();
  });

  it('renders an "Unsupported block" placeholder for an invalid enum value', () => {
    // `variant` outside the allowed set is invalid.
    const blocks = [
      { type: 'alert', variant: 'explode', body: 'boom' },
    ] as unknown as Block[];
    expect(() => render(<BlockRenderer blocks={blocks} />)).not.toThrow();
    expect(screen.getByText(/Unsupported block/i)).toBeInTheDocument();
  });

  it('renders a malicious text value as a literal string (no HTML injection)', () => {
    const payload = '<img src=x onerror=alert(1)>';
    const { container } = render(
      <BlockRenderer blocks={[{ type: 'text', value: payload }]} />
    );
    // The payload must appear as TEXT, never as a parsed <img> element.
    expect(container.querySelector('img')).toBeNull();
    expect(screen.getByText(payload)).toBeInTheDocument();
  });

  it('renders a non-relative button href as inert (no navigating link)', () => {
    const { container } = render(
      <BlockRenderer
        blocks={[
          { type: 'button', label: 'Evil', href: 'https://evil.example' },
        ]}
      />
    );
    // No anchor should point at the external URL.
    const anchors = Array.from(container.querySelectorAll('a'));
    for (const anchor of anchors) {
      expect(anchor.getAttribute('href')).not.toBe('https://evil.example');
    }
    // The label still renders (as an inert control), and there is no link role.
    expect(screen.getByText('Evil')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Evil' })).toBeNull();
  });

  it('renders a multi-block tree without throwing and shows each child', () => {
    render(
      <BlockRenderer
        blocks={[
          { type: 'heading', level: 1, text: 'Top' },
          { type: 'divider' },
          {
            type: 'section',
            title: 'Body',
            children: [
              { type: 'badge', variant: 'neutral', label: 'tag' },
              { type: 'text', value: 'tail' },
            ],
          },
        ]}
      />
    );
    const region = screen.getByRole('heading', { level: 1 });
    expect(region).toHaveTextContent('Top');
    expect(screen.getByText('tag')).toBeInTheDocument();
    expect(screen.getByText('tail')).toBeInTheDocument();
  });

  it('renders nothing for an empty block list', () => {
    const { container } = render(<BlockRenderer blocks={[]} />);
    expect(within(container).queryByText(/Unsupported block/i)).toBeNull();
  });
});

// ---- WC-231: data-bound block renderers ----

describe('BlockRenderer — data-bound blocks (WC-231)', () => {
  // ---- dataTable ----

  it('dataTable: shows loading skeleton before apiClient resolves', () => {
    mockApiClient.mockReturnValue(new Promise(() => undefined));

    const { container } = render(
      <BlockRenderer
        blocks={[
          {
            type: 'dataTable',
            source: '/api/v1/x/rows',
            columns: [{ key: 'name', label: 'Name' }],
          },
        ]}
      />
    );

    expect(container.querySelector('[data-slot="block-data-loading"]')).not.toBeNull();
  });

  it('dataTable ready: renders column headers and fetched row cells', async () => {
    mockApiClient.mockResolvedValue(
      stubResponse(true, 200, { data: [{ name: 'Ada', role: 'Admin' }] })
    );

    render(
      <BlockRenderer
        blocks={[
          {
            type: 'dataTable',
            source: '/api/v1/x/rows',
            columns: [
              { key: 'name', label: 'Name' },
              { key: 'role', label: 'Role' },
            ],
          },
        ]}
      />
    );

    await waitFor(() => expect(screen.getByText('Name')).toBeInTheDocument());
    expect(screen.getByText('Role')).toBeInTheDocument();
    expect(screen.getByText('Ada')).toBeInTheDocument();
    expect(screen.getByText('Admin')).toBeInTheDocument();
  });

  it('dataTable error: renders error state with Retry button', async () => {
    mockApiClient.mockResolvedValue(
      stubResponse(false, 500, { error: 'fail' })
    );

    render(
      <BlockRenderer
        blocks={[
          {
            type: 'dataTable',
            source: '/api/v1/x/rows',
            columns: [{ key: 'name', label: 'Name' }],
          },
        ]}
      />
    );

    await waitFor(() =>
      expect(screen.getByRole('button', { name: /retry/i })).toBeInTheDocument()
    );
    expect(screen.getByText(/Failed to load data/i)).toBeInTheDocument();
  });

  it('dataTable error: Retry re-invokes apiClient', async () => {
    mockApiClient
      .mockResolvedValueOnce(stubResponse(false, 500, { error: 'fail' }))
      .mockResolvedValueOnce(
        stubResponse(true, 200, { data: [{ name: 'Bo' }] })
      );

    render(
      <BlockRenderer
        blocks={[
          {
            type: 'dataTable',
            source: '/api/v1/x/rows',
            columns: [{ key: 'name', label: 'Name' }],
          },
        ]}
      />
    );

    await waitFor(() =>
      expect(screen.getByRole('button', { name: /retry/i })).toBeInTheDocument()
    );

    await userEvent.click(screen.getByRole('button', { name: /retry/i }));

    await waitFor(() => expect(screen.getByText('Bo')).toBeInTheDocument());
    expect(mockApiClient).toHaveBeenCalledTimes(2);
  });

  it('dataTable empty: renders emptyText', async () => {
    mockApiClient.mockResolvedValue(
      stubResponse(true, 200, { data: [] })
    );

    render(
      <BlockRenderer
        blocks={[
          {
            type: 'dataTable',
            source: '/api/v1/x/rows',
            columns: [{ key: 'name', label: 'Name' }],
            emptyText: 'No rows yet.',
          },
        ]}
      />
    );

    await waitFor(() =>
      expect(screen.getByText('No rows yet.')).toBeInTheDocument()
    );
  });

  it('dataTable: injection guard — fetched cell value renders as literal text, not HTML', async () => {
    const payload = '<img src=x onerror=alert(1)>';
    mockApiClient.mockResolvedValue(
      stubResponse(true, 200, { data: [{ name: payload }] })
    );

    const { container } = render(
      <BlockRenderer
        blocks={[
          {
            type: 'dataTable',
            source: '/api/v1/x/rows',
            columns: [{ key: 'name', label: 'Name' }],
          },
        ]}
      />
    );

    await waitFor(() => expect(screen.getByText(payload)).toBeInTheDocument());
    expect(container.querySelector('img')).toBeNull();
  });

  it('dataTable: missing columns renders UnsupportedBlock', () => {
    const blocks = [
      { type: 'dataTable', source: '/api/v1/x/rows' },
    ] as unknown as Block[];

    render(<BlockRenderer blocks={blocks} />);

    expect(screen.getByText(/Unsupported block/i)).toBeInTheDocument();
  });

  // ---- dataStat ----

  it('dataStat: shows loading skeleton before apiClient resolves', () => {
    mockApiClient.mockReturnValue(new Promise(() => undefined));

    const { container } = render(
      <BlockRenderer
        blocks={[
          {
            type: 'dataStat',
            source: '/api/v1/x/metric',
            label: 'Users',
            valueField: 'value',
          },
        ]}
      />
    );

    expect(container.querySelector('[data-slot="block-data-loading"]')).not.toBeNull();
  });

  it('dataStat ready: renders label and the valueField value', async () => {
    mockApiClient.mockResolvedValue(
      stubResponse(true, 200, { data: { value: '1,284', trend: 'up', hint: '+12%' } })
    );

    render(
      <BlockRenderer
        blocks={[
          {
            type: 'dataStat',
            source: '/api/v1/x/metric',
            label: 'Active Users',
            valueField: 'value',
            trendField: 'trend',
            hintField: 'hint',
          },
        ]}
      />
    );

    await waitFor(() =>
      expect(screen.getByText('Active Users')).toBeInTheDocument()
    );
    expect(screen.getByText('1,284')).toBeInTheDocument();
    expect(screen.getByText('+12%')).toBeInTheDocument();
  });

  it('dataStat error: renders error state with Retry button', async () => {
    mockApiClient.mockResolvedValue(
      stubResponse(false, 403, { error: 'Forbidden' })
    );

    render(
      <BlockRenderer
        blocks={[
          {
            type: 'dataStat',
            source: '/api/v1/x/metric',
            label: 'Users',
            valueField: 'value',
          },
        ]}
      />
    );

    await waitFor(() =>
      expect(screen.getByRole('button', { name: /retry/i })).toBeInTheDocument()
    );
  });

  it('dataStat empty: renders default empty text when emptyText is not set', async () => {
    // valueField not present → parse returns null → empty
    mockApiClient.mockResolvedValue(
      stubResponse(true, 200, { data: {} })
    );

    render(
      <BlockRenderer
        blocks={[
          {
            type: 'dataStat',
            source: '/api/v1/x/metric',
            label: 'Users',
            valueField: 'value',
          },
        ]}
      />
    );

    await waitFor(() =>
      expect(
        screen.getByText(/No data available/i)
      ).toBeInTheDocument()
    );
  });

  it('dataStat empty: renders emptyText when provided', async () => {
    mockApiClient.mockResolvedValue(
      stubResponse(true, 200, { data: {} })
    );

    render(
      <BlockRenderer
        blocks={[
          {
            type: 'dataStat',
            source: '/api/v1/x/metric',
            label: 'Users',
            valueField: 'value',
            emptyText: 'No metric yet.',
          },
        ]}
      />
    );

    await waitFor(() =>
      expect(screen.getByText('No metric yet.')).toBeInTheDocument()
    );
  });

  // ---- dataList ----

  it('dataList: shows loading skeleton before apiClient resolves', () => {
    mockApiClient.mockReturnValue(new Promise(() => undefined));

    const { container } = render(
      <BlockRenderer
        blocks={[
          {
            type: 'dataList',
            source: '/api/v1/x/rows',
            itemField: 'name',
          },
        ]}
      />
    );

    expect(container.querySelector('[data-slot="block-data-loading"]')).not.toBeNull();
  });

  it('dataList ready: renders an item from itemField', async () => {
    mockApiClient.mockResolvedValue(
      stubResponse(true, 200, { data: [{ name: 'Alice' }, { name: 'Bob' }] })
    );

    render(
      <BlockRenderer
        blocks={[
          {
            type: 'dataList',
            source: '/api/v1/x/rows',
            itemField: 'name',
          },
        ]}
      />
    );

    await waitFor(() =>
      expect(screen.getByText('Alice')).toBeInTheDocument()
    );
    expect(screen.getByText('Bob')).toBeInTheDocument();
  });

  it('dataList error: renders error state with Retry button', async () => {
    mockApiClient.mockResolvedValue(
      stubResponse(false, 500, { error: 'fail' })
    );

    render(
      <BlockRenderer
        blocks={[
          {
            type: 'dataList',
            source: '/api/v1/x/rows',
            itemField: 'name',
          },
        ]}
      />
    );

    await waitFor(() =>
      expect(screen.getByRole('button', { name: /retry/i })).toBeInTheDocument()
    );
  });

  it('dataList empty: renders emptyText', async () => {
    mockApiClient.mockResolvedValue(
      stubResponse(true, 200, { data: [] })
    );

    render(
      <BlockRenderer
        blocks={[
          {
            type: 'dataList',
            source: '/api/v1/x/rows',
            itemField: 'name',
            emptyText: 'No items.',
          },
        ]}
      />
    );

    await waitFor(() =>
      expect(screen.getByText('No items.')).toBeInTheDocument()
    );
  });

  it('dataList: injection guard — fetched item renders as literal text', async () => {
    const payload = '<img src=x onerror=alert(1)>';
    mockApiClient.mockResolvedValue(
      stubResponse(true, 200, { data: [{ name: payload }] })
    );

    const { container } = render(
      <BlockRenderer
        blocks={[
          {
            type: 'dataList',
            source: '/api/v1/x/rows',
            itemField: 'name',
          },
        ]}
      />
    );

    await waitFor(() => expect(screen.getByText(payload)).toBeInTheDocument());
    expect(container.querySelector('img')).toBeNull();
  });
});
