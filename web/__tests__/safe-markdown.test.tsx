/**
 * WC-532 A5: the dependency-free safe-markdown renderer.
 *
 * The security-critical property: it emits React elements (never HTML strings),
 * so arbitrary input is escaped and cannot inject script; links are sanitized;
 * a small formatting subset renders; inline $…$ goes through KaTeX.
 */

import React from 'react';
import { render, screen } from '@testing-library/react';
import { renderMarkdown } from '@/lib/safe-markdown';

// TextEncoder/TextDecoder (needed by KaTeX) are polyfilled globally in jest.setup.js.

describe('renderMarkdown — XSS safety', () => {
  it('escapes raw HTML / script instead of injecting it', () => {
    const { container } = render(<div>{renderMarkdown('Hello <script>alert(1)</script> <img src=x onerror=alert(2)>')}</div>);
    // No script/img elements are ever created — the markup is inert text.
    expect(container.querySelector('script')).toBeNull();
    expect(container.querySelector('img')).toBeNull();
    // The literal text is present (escaped by React).
    expect(container.textContent).toContain('<script>alert(1)</script>');
  });

  it('drops javascript: links, keeping the text', () => {
    const { container } = render(<div>{renderMarkdown('[click](javascript:alert(1))')}</div>);
    expect(container.querySelector('a')).toBeNull();
    expect(container.textContent).toContain('click');
  });

  it('renders safe internal + http links as anchors', () => {
    render(<div>{renderMarkdown('[internal](/plugins) and [external](https://example.com)')}</div>);
    expect(screen.getByRole('link', { name: 'internal' })).toHaveAttribute('href', '/plugins');
    const ext = screen.getByRole('link', { name: 'external' });
    expect(ext).toHaveAttribute('href', 'https://example.com');
    expect(ext).toHaveAttribute('rel', expect.stringContaining('noopener'));
  });
});

describe('renderMarkdown — formatting', () => {
  it('renders bold, italic, inline code, headings, and lists', () => {
    const { container } = render(
      <div>{renderMarkdown('# Heading\n\n**bold** _italic_ `code`\n\n- one\n- two')}</div>
    );
    expect(container.querySelector('h1')?.textContent).toBe('Heading');
    expect(container.querySelector('strong')?.textContent).toBe('bold');
    expect(container.querySelector('em')?.textContent).toBe('italic');
    expect(container.querySelector('code')?.textContent).toBe('code');
    expect(container.querySelectorAll('li')).toHaveLength(2);
  });

  it('renders fenced code blocks as literal text', () => {
    const { container } = render(<div>{renderMarkdown('```\nconst x = 1 < 2;\n```')}</div>);
    const pre = container.querySelector('pre code');
    expect(pre?.textContent).toBe('const x = 1 < 2;');
  });

  it('renders inline $…$ math via KaTeX', () => {
    const { container } = render(<div>{renderMarkdown('Euler: $e^{i\\pi}+1=0$ done')}</div>);
    // KaTeX emits a .katex container.
    expect(container.querySelector('.katex')).not.toBeNull();
    expect(container.textContent).toContain('Euler:');
  });
});
