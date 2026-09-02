/**
 * Rendering tests for `ElementContent` — the single shared render path used by
 * the canvas, block-instance content and the print renderer (element-layer.tsx
 * and print-document.tsx both delegate to it for non-blockInstance elements).
 *
 * Covers the two additions from WC doc-designer rich-text + math work:
 *  - the new 'math' element type (renders via the shared @amroksaleh/ui KaTeX
 *    wrapper — same component already covered by math-text.test.tsx)
 *  - inline bold/italic runs on text/dynamicText, including the backward-compat
 *    path (no `runs` field → legacy whole-element style, unchanged output).
 *
 * TextEncoder/TextDecoder (needed by KaTeX) are polyfilled globally in jest.setup.js.
 */

import React from 'react';
import { render } from '@testing-library/react';
import { ElementContent } from '@/components/documents/element-content';
import type { DocElement, MathElement, TextElement, DynamicTextElement } from '@/lib/documents/types';
import { DEFAULT_TEXT_STYLE } from '@/lib/documents/presets';

const BASE = { id: 'e1', x: 0, y: 0, w: 40, h: 14, rotation: 0, z: 1 };

describe('ElementContent — math element', () => {
  it('renders an inline LaTeX expression via KaTeX', () => {
    const el: MathElement = { ...BASE, type: 'math', expression: 'x^2', block: false };
    const { container } = render(<ElementContent el={el} data={{}} preview />);
    expect(container.querySelector('.katex')).not.toBeNull();
    expect(container.querySelector('.katex-display')).toBeNull();
  });

  it('renders display (block) math when `block` is true', () => {
    const el: MathElement = { ...BASE, type: 'math', expression: '\\int_0^1 x\\,dx', block: true };
    const { container } = render(<ElementContent el={el} data={{}} preview />);
    expect(container.querySelector('.katex-display')).not.toBeNull();
  });

  it('does not throw on invalid LaTeX (KaTeX throwOnError: false)', () => {
    const el: MathElement = { ...BASE, type: 'math', expression: '\\frac{' };
    const { container } = render(<ElementContent el={el} data={{}} preview />);
    expect(container.querySelector('.katex-error')).not.toBeNull();
  });
});

describe('ElementContent — text: backward compatibility (no runs)', () => {
  it('renders plain text using the whole-element style, unaffected by the new runs support', () => {
    const el: TextElement = {
      ...BASE,
      type: 'text',
      text: 'Hello world',
      style: { ...DEFAULT_TEXT_STYLE, fontWeight: 'bold', fontStyle: 'italic' },
    };
    const { container, getByTestId } = render(<ElementContent el={el} data={{}} preview />);
    const box = getByTestId('doc-textbox');
    expect(box.textContent).toBe('Hello world');
    expect(box).toHaveStyle({ fontWeight: 'bold', fontStyle: 'italic' });
    // No per-run spans are introduced when `runs` is absent.
    expect(container.querySelectorAll('span')).toHaveLength(0);
  });
});

describe('ElementContent — text: rich-text runs', () => {
  it('renders one span per run, with bold/italic only where the run sets it', () => {
    const el: TextElement = {
      ...BASE,
      type: 'text',
      text: 'Hello bold and italic world',
      style: { ...DEFAULT_TEXT_STYLE },
      runs: [
        { text: 'Hello ' },
        { text: 'bold', bold: true },
        { text: ' and ' },
        { text: 'italic', italic: true },
        { text: ' world' },
      ],
    };
    const { getByTestId } = render(<ElementContent el={el} data={{}} preview />);
    const box = getByTestId('doc-textbox');
    expect(box.textContent).toBe('Hello bold and italic world');
    const spans = box.querySelectorAll('span');
    expect(spans).toHaveLength(5);
    expect(spans[1].textContent).toBe('bold');
    expect(spans[1]).toHaveStyle({ fontWeight: 'bold' });
    expect(spans[3].textContent).toBe('italic');
    expect(spans[3]).toHaveStyle({ fontStyle: 'italic' });
    // Runs that never set bold/italic don't force an explicit "normal" either —
    // they inherit whatever the container (whole-element TextStyle) says.
    expect(spans[0].style.fontWeight).toBe('');
  });

  it('an explicit false clears formatting on a run, overriding the container style', () => {
    const el: TextElement = {
      ...BASE,
      type: 'text',
      text: 'ab',
      style: { ...DEFAULT_TEXT_STYLE, fontWeight: 'bold' },
      runs: [{ text: 'a', bold: false }, { text: 'b' }],
    };
    const { getByTestId } = render(<ElementContent el={el} data={{}} preview />);
    const spans = getByTestId('doc-textbox').querySelectorAll('span');
    expect(spans[0]).toHaveStyle({ fontWeight: 'normal' });
    // The second run has no override, so it renders with no inline fontWeight
    // and picks up the container's bold via inheritance.
    expect(spans[1].style.fontWeight).toBe('');
  });
});

describe('ElementContent — dynamicText: rich-text runs + interpolation', () => {
  const data = { name: 'Acme' };

  it('shows raw {{tokens}} per-run while editing (preview=false)', () => {
    const el: DynamicTextElement = {
      ...BASE,
      type: 'dynamicText',
      template: 'Hello {{name}}',
      style: { ...DEFAULT_TEXT_STYLE },
      runs: [{ text: 'Hello ' }, { text: '{{name}}', bold: true }],
    };
    const { getByTestId } = render(<ElementContent el={el} data={data} preview={false} />);
    const box = getByTestId('doc-textbox');
    expect(box.textContent).toBe('Hello {{name}}');
    expect(box.querySelectorAll('span')[1]).toHaveStyle({ fontWeight: 'bold' });
  });

  it('interpolates each run in Preview, preserving that run\'s formatting', () => {
    const el: DynamicTextElement = {
      ...BASE,
      type: 'dynamicText',
      template: 'Hello {{name}}',
      style: { ...DEFAULT_TEXT_STYLE },
      runs: [{ text: 'Hello ' }, { text: '{{name}}', bold: true }],
    };
    const { getByTestId } = render(<ElementContent el={el} data={data} preview />);
    const box = getByTestId('doc-textbox');
    expect(box.textContent).toBe('Hello Acme');
    const spans = box.querySelectorAll('span');
    expect(spans[1].textContent).toBe('Acme');
    expect(spans[1]).toHaveStyle({ fontWeight: 'bold' });
  });
});

describe('ElementContent — exhaustiveness sanity', () => {
  it('every DocElement variant used elsewhere in the suite renders without throwing', () => {
    const els: DocElement[] = [
      { ...BASE, type: 'text', text: 't', style: { ...DEFAULT_TEXT_STYLE } },
      { ...BASE, type: 'dynamicText', template: 't', style: { ...DEFAULT_TEXT_STYLE } },
      { ...BASE, type: 'math', expression: 'x', block: false },
      { ...BASE, type: 'rect', fill: '#fff', stroke: '#000', strokeWidth: 0.3, radius: 1 },
      { ...BASE, type: 'line', stroke: '#000', strokeWidth: 0.5 },
    ];
    for (const el of els) {
      expect(() => render(<ElementContent el={el} data={{}} preview />)).not.toThrow();
    }
  });

  /**
   * AN ELEMENT TYPE THIS RENDERER DOES NOT KNOW MUST DRAW NOTHING.
   *
   * The `default:` branch is a TypeScript exhaustiveness check, which proves it
   * unreachable for a well-typed DocElement — and the input here is not one. It
   * comes from a template row: an imported JSON file whose elements were
   * hand-edited (`isDocTemplate` validates the envelope, not the element
   * types), a row written straight to the API, or a document authored by a
   * newer client during a rolling deploy.
   *
   * It used to render `String(el)`, which for an object is the literal text
   * `[object Object]` — printed onto the customer's PDF. The cast below is the
   * point of the test: it reproduces exactly what the type system cannot stop
   * from reaching this function at runtime.
   */
  it('draws nothing for an unknown element type, instead of printing [object Object]', () => {
    const alien = { ...BASE, type: 'hologram', spin: 4 } as unknown as DocElement;

    const { container } = render(<ElementContent el={alien} data={{}} preview />);

    expect(container.textContent).toBe('');
    expect(container.textContent).not.toContain('[object');
  });
});
