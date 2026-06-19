/**
 * WC-227: web BlockRenderer for `screen: 'blocks'` plugin features.
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
 */

import React from 'react';
import { render, screen, within } from '@testing-library/react';
import { BlockRenderer } from '@/components/plugin/blocks/block-renderer';
import type { Block } from '@/lib/plugin-features';

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
