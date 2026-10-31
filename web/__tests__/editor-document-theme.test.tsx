/**
 * Editor milestone M4d (document themes, E.44): the page view takes the
 * print colour and where it's used from the document's resolved theme, the
 * letterhead shows the lines and logo the theme names, and Publish says when
 * the document names a theme the workspace doesn't have.
 */
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { render, screen } from '@testing-library/react';

import type { ResolvedThemeTokens } from '@whity-org/ui/editor/contract';
import { EDITOR_LABELS } from '@whity-org/ui/editor/labels';
import { PageView, themedLetterhead, themeStyle, type Letterhead } from '@whity-org/ui/editor/page-view';
import { LabelSheetView } from '@whity-org/ui/editor/label-sheet';
import { PublishCheck } from '@whity-org/ui/editor/publish-check';

const TOKENS: ResolvedThemeTokens = {
  accent: '#B74C05',
  accentOnDark: '#F0A070',
  accentUse: 'title-and-headings',
  letterhead: { logo: 'wide', lines: ['phone', 'address'] },
};
const BASE: Letterhead = { name: 'Example Bakery', lines: ['Mecca Street 112, Amman', 'Tax no. 0123456'] };
const PARTS = { address: 'Mecca Street 112, Amman', taxId: 'Tax no. 0123456', phone: '+962 6 555 0101', email: '' };

describe('themedLetterhead', () => {
  it('shows the lines the theme names, in its order, leaving out the ones not set', () => {
    const lh = themedLetterhead(BASE, { letterhead: { logo: 'square', lines: ['email', 'phone', 'taxId'] } }, PARTS, { wide: null, square: null });
    expect(lh.lines).toEqual(['+962 6 555 0101', 'Tax no. 0123456']);
  });

  it('wide falls back to square, square to wide, and neither leaves the initials', () => {
    const wide = { letterhead: { logo: 'wide' as const, lines: [] } };
    const square = { letterhead: { logo: 'square' as const, lines: [] } };
    expect(themedLetterhead(BASE, wide, PARTS, { wide: '/w.svg', square: '/s.png' })).toMatchObject({ logo: '/w.svg', logoShape: 'wide' });
    expect(themedLetterhead(BASE, wide, PARTS, { wide: null, square: '/s.png' })).toMatchObject({ logo: '/s.png', logoShape: 'square' });
    expect(themedLetterhead(BASE, square, PARTS, { wide: '/w.svg', square: '/s.png' })).toMatchObject({ logo: '/s.png', logoShape: 'square' });
    expect(themedLetterhead(BASE, square, PARTS, { wide: '/w.svg', square: null })).toMatchObject({ logo: '/w.svg', logoShape: 'wide' });
    expect(themedLetterhead(BASE, square, PARTS, { wide: null, square: null })).toMatchObject({ logo: null, logoShape: 'square' });
  });

  it('none shows no logo, even with logos uploaded', () => {
    expect(themedLetterhead(BASE, { letterhead: { logo: 'none', lines: [] } }, PARTS, { wide: '/w.svg', square: '/s.png' })).toMatchObject({
      logo: null,
      logoShape: 'none',
    });
  });

  it('without a theme, or without the parts, keeps the default letterhead', () => {
    expect(themedLetterhead(BASE, null, PARTS, null)).toBe(BASE);
    expect(themedLetterhead(BASE, TOKENS, null, null).lines).toEqual(BASE.lines);
  });
});

describe('the page view', () => {
  function renderPage(theme: ResolvedThemeTokens | null, letterhead: Letterhead) {
    return render(
      <PageView editor={null} title="Payment reminder" letterhead={letterhead} theme={theme} zoom={1} labels={EDITOR_LABELS}>
        <div className="wy-doc">
          <h2>Open invoices</h2>
        </div>
      </PageView>
    );
  }

  it('sets the print colours from the theme and marks where the accent is used', () => {
    const { container } = renderPage(TOKENS, BASE);
    const stack = container.querySelector('.wy-pv__stack') as HTMLElement;
    expect(stack.style.getPropertyValue('--doc-accent')).toBe('#B74C05');
    expect(stack.style.getPropertyValue('--doc-accent-on-dark')).toBe('#F0A070');
    expect(stack.dataset.accentUse).toBe('title-and-headings');
  });

  it('without a theme sets no colour, so the stylesheet falls back to the brand colour', () => {
    const { container } = renderPage(null, BASE);
    const stack = container.querySelector('.wy-pv__stack') as HTMLElement;
    expect(stack.style.getPropertyValue('--doc-accent')).toBe('');
    expect(stack.dataset.accentUse).toBeUndefined();
  });

  it('shows the letterhead lines in order, and a wide logo without a frame', () => {
    const lh = themedLetterhead(BASE, TOKENS, PARTS, { wide: '/w.svg', square: null });
    const { container } = renderPage(TOKENS, lh);
    const letterhead = container.querySelector('.wy-pv__lh') as HTMLElement;
    expect(letterhead.textContent).toContain('+962 6 555 0101Mecca Street 112, Amman');
    const logo = letterhead.querySelector('[data-slot="tenant-logo"]') as HTMLElement;
    expect(logo.dataset.shape).toBe('wide');
    expect(logo.getAttribute('aria-label')).toBe('Example Bakery');
    expect(logo.querySelector('img')?.getAttribute('src')).toBe('/w.svg');
  });

  it('with logo none prints the name without a mark', () => {
    const lh = themedLetterhead(BASE, { ...TOKENS, letterhead: { logo: 'none', lines: [] } }, PARTS, { wide: '/w.svg', square: '/s.png' });
    const { container } = renderPage(TOKENS, lh);
    const letterhead = container.querySelector('.wy-pv__lh') as HTMLElement;
    expect(letterhead.querySelector('[data-slot="tenant-logo"]')).toBeNull();
    expect(letterhead.textContent).toContain('Example Bakery');
  });
});

it('the label sheet takes the same theme', () => {
  const { container } = render(
    <LabelSheetView
      editor={null}
      grid={{ columns: 2, rows: 2, size: { widthMm: 99.1, heightMm: 67.7 }, gapMm: 2.5 }}
      sheet="A4"
      zoom={1}
      theme={TOKENS}
      labels={EDITOR_LABELS}
    >
      <div />
    </LabelSheetView>
  );
  const stack = container.querySelector('.wy-pv__stack') as HTMLElement;
  expect(stack.style.getPropertyValue('--doc-accent')).toBe('#B74C05');
  expect(stack.dataset.accentUse).toBe('title-and-headings');
});

it('themeStyle is nothing without a theme', () => {
  expect(themeStyle(null)).toBeUndefined();
});

describe('editor.css', () => {
  const css = readFileSync(path.resolve(__dirname, '../../packages/ui/src/editor/editor.css'), 'utf8');

  it('gives H1-H3 the print colour in the page view with title-and-headings (E.45)', () => {
    expect(css).toMatch(/\.wy-pv__stack\[data-accent-use="title-and-headings"\] \.wy-doc :is\(h2, h3, h4\) \{ color: var\(--doc-accent, var\(--brand\)\); \}/);
  });

  it('keeps the continuous title the text colour in light mode and uses accentOnDark in dark (E.11)', () => {
    expect(css).toContain('.wy-sheet__title { color: var(--foreground); }');
    expect(css).toContain('.dark .wy-sheet__title { color: var(--doc-accent-on-dark, var(--foreground)); }');
  });
});

describe('Publish check', () => {
  it('names a theme the workspace doesn’t have, isolated, with no Show button', () => {
    render(
      <PublishCheck
        open
        onOpenChange={() => {}}
        blockers={[{ kind: 'theme-unavailable', ref: 'tenant.autumn' }]}
        nextVersion={3}
        onPublish={() => {}}
        onFix={() => {}}
        labels={EDITOR_LABELS}
      />
    );
    const list = screen.getByRole('list', { name: 'What blocks publishing' });
    expect(list.textContent).toContain('This document uses a theme the workspace doesn’t have: tenant.autumn');
    expect(list.textContent).toContain('Choose another theme or restore it.');
    expect(list.querySelector('bdi')?.textContent).toBe('tenant.autumn');
    expect(screen.queryByRole('button', { name: 'Show' })).toBeNull();
    expect((screen.getByRole('button', { name: 'Publish version 3' }) as HTMLButtonElement).disabled).toBe(true);
  });

  it('puts the ref inside the Arabic sentence', () => {
    render(
      <PublishCheck
        open
        onOpenChange={() => {}}
        blockers={[{ kind: 'theme-unavailable', ref: 'tenant.autumn' }]}
        nextVersion={3}
        onPublish={() => {}}
        onFix={() => {}}
        labels={{
          ...EDITOR_LABELS,
          blockerTheme: 'يستخدم هذا المستند نسقًا غير موجود في مساحة العمل: {ref}',
          blockerThemeHint: 'اختر نسقًا آخر أو استعِده.',
        }}
      />
    );
    const bdi = document.querySelector('bdi');
    expect(bdi?.textContent).toBe('tenant.autumn');
    expect(bdi?.parentElement?.textContent).toBe('يستخدم هذا المستند نسقًا غير موجود في مساحة العمل: tenant.autumn');
  });
});

describe('the Arabic catalogue', () => {
  it('has the theme-unavailable blocker in English and Arabic', () => {
    const read = (p: string) => JSON.parse(readFileSync(path.resolve(__dirname, '../../database/i18n', p), 'utf8')).keys;
    const en = read('documents.json');
    const ar = read('ar/documents.json');
    for (const key of ['blockEditor.blockerTheme', 'blockEditor.blockerThemeHint']) {
      expect(en[key]).toBe(EDITOR_LABELS[key.slice('blockEditor.'.length) as keyof typeof EDITOR_LABELS]);
      expect(ar[key]).toBeTruthy();
    }
    expect(ar['blockEditor.blockerTheme']).toContain('{ref}');
  });
});
