'use strict';

/**
 * Inline runs in flowing paragraphs (WHIT-681).
 *
 * WHAT WAS WRONG. A flow paragraph was `{ type, text: string, align? }` — a
 * plain string — so a letter that said something in bold, or linked to
 * anything, printed as undifferentiated prose. The canvas half of the designer
 * has had inline runs since it shipped (`TextRun` in
 * `packages/ui/src/documents/types.ts`, carrying `bold` and `italic` over
 * slices of a text element), and the flowing half never got them. Elmak
 * reported it as "a paragraph prints as plain text and loses emphasis", and
 * that is exactly right for flow and exactly wrong for canvas, which is what
 * made it confusing to place.
 *
 * WHAT IS DELIBERATELY NOT HERE. Mixed-direction runs were in the same report
 * and are NOT a gap: `isolateForeignRuns` already walks a paragraph for runs in
 * the opposite direction to the document and wraps each in `<bdi>`. The work
 * below has to keep that true INSIDE a mark rather than add it — a bolded
 * Latin serial inside an Arabic sentence must still be isolated, and the
 * obvious implementation (escape the run, wrap it in `<strong>`) silently
 * drops the isolation.
 *
 * THE INVARIANT, and why it is tested as hard as the rendering. `TextRun`'s
 * contract is that concatenating `run.text` in order reconstructs the plain
 * `text` field exactly. That is not tidiness: `text` is what every plain-text
 * consumer reads — interpolation of `{{fields}}`, the outline summary, the
 * table of contents, Markdown export. If runs and text can disagree, the
 * document renders one thing and exports another, and nothing notices. So a
 * payload whose runs do not reconstruct its text is REFUSED rather than
 * reconciled: guessing which of the two the author meant is how the wrong one
 * gets printed.
 *
 * LINKS ARE AN INJECTION SURFACE. A document body is authored by a user and
 * rendered to HTML inside a browser we drive. An `href` is the one field here
 * that is not escaped into text but interpreted as a URL, so the scheme is
 * allow-listed. `javascript:` in a printed letter is nonsense; `javascript:` in
 * the renderer is script execution in the render container.
 */

const { validateFlowPayload, normaliseFlowDocument } = require('../src/flow/document');
const { buildFlowHtml } = require('../src/flow/html');

/** A payload with one paragraph, overridable. */
const withParagraph = (paragraph) => ({
  content: [{ type: 'paragraph', text: 'body', ...paragraph }],
});

/** The rendered HTML for one paragraph. */
const renderParagraph = (paragraph, payload = {}) =>
  buildFlowHtml(normaliseFlowDocument({ ...withParagraph(paragraph), ...payload }));

describe('validating inline runs', () => {
  test('a paragraph with no runs is still valid — runs are additive', () => {
    expect(validateFlowPayload(withParagraph({}))).toBeNull();
  });

  test('accepts runs that reconstruct the text exactly', () => {
    expect(
      validateFlowPayload(
        withParagraph({
          text: 'a bold word',
          runs: [{ text: 'a ' }, { text: 'bold', bold: true }, { text: ' word' }],
        })
      )
    ).toBeNull();
  });

  test('refuses runs that do not reconstruct the text', () => {
    // The whole point of the invariant. Here the runs say "a BOLD word" and
    // `text` says "a bold word"; printed output and every plain-text consumer
    // would disagree permanently.
    expect(
      validateFlowPayload(
        withParagraph({
          text: 'a bold word',
          runs: [{ text: 'a ' }, { text: 'BOLD', bold: true }, { text: ' word' }],
        })
      )
    ).toMatch(/runs.*reconstruct|reconstruct.*text/i);
  });

  test('refuses runs that are not an array, and runs without text', () => {
    expect(validateFlowPayload(withParagraph({ runs: 'bold' }))).toMatch(/runs/);
    expect(validateFlowPayload(withParagraph({ text: 'x', runs: [{ bold: true }] }))).toMatch(/runs/);
  });

  test('accepts the link schemes a document can legitimately carry', () => {
    for (const href of ['https://example.test/a', 'http://example.test/a', 'mailto:someone@example.test']) {
      expect(
        validateFlowPayload(withParagraph({ text: 'link', runs: [{ text: 'link', href }] }))
      ).toBeNull();
    }
  });

  test('stores a padded but legitimate URL trimmed, so the checked value is the rendered one', () => {
    const payload = withParagraph({ text: 'link', runs: [{ text: 'link', href: '  https://example.test/a\n' }] });

    expect(validateFlowPayload(payload)).toBeNull();
    expect(payload.content[0].runs[0].href).toBe('https://example.test/a');
  });

  test('refuses a javascript: or data: href', () => {
    // Not hypothetical: this HTML is loaded in a real browser to be printed.
    for (const href of ['javascript:alert(1)', 'data:text/html,<script>alert(1)</script>']) {
      expect(
        validateFlowPayload(withParagraph({ text: 'link', runs: [{ text: 'link', href }] }))
      ).toMatch(/href/);
    }
  });
});

describe('rendering inline runs', () => {
  test('a paragraph without runs renders exactly as it always did', () => {
    const html = renderParagraph({ text: 'plain body' });
    expect(html).toContain('plain body');
    expect(html).not.toContain('<strong>');
    expect(html).not.toContain('<em>');
  });

  test('emits strong, em and a for the three marks', () => {
    const html = renderParagraph({
      text: 'bold italic link',
      runs: [
        { text: 'bold', bold: true },
        { text: ' ' },
        { text: 'italic', italic: true },
        { text: ' ' },
        { text: 'link', href: 'https://example.test/a' },
      ],
    });

    expect(html).toContain('<strong>bold</strong>');
    expect(html).toContain('<em>italic</em>');
    expect(html).toContain('href="https://example.test/a"');
    expect(html).toContain('>link</a>');
  });

  test('a run can be bold and italic at once', () => {
    const html = renderParagraph({ text: 'both', runs: [{ text: 'both', bold: true, italic: true }] });
    expect(html).toMatch(/<strong><em>both<\/em><\/strong>|<em><strong>both<\/strong><\/em>/);
  });

  test('escapes run text, so a body cannot inject markup', () => {
    const html = renderParagraph({
      text: '<script>alert(1)</script>',
      runs: [{ text: '<script>alert(1)</script>', bold: true }],
    });
    expect(html).not.toContain('<script>alert(1)</script>');
    expect(html).toContain('&lt;script&gt;');
  });

  test('escapes the href attribute', () => {
    const html = renderParagraph({
      text: 'q',
      runs: [{ text: 'q', href: 'https://example.test/a?x=1&y="2"' }],
    });
    expect(html).toContain('&amp;');
    expect(html).not.toContain('y="2"');
  });

  test('headings carry runs too, and the contents entry stays plain', () => {
    // Headings are validated for runs, so they must RENDER them — accepting a
    // field and then dropping it is worse than refusing it. The contents
    // entry is built from `text`, which the invariant guarantees still says
    // the same thing without the markup.
    const html = buildFlowHtml(
      normaliseFlowDocument({
        content: [{ type: 'heading', level: 1, text: 'A stressed title', runs: [{ text: 'A ' }, { text: 'stressed', italic: true }, { text: ' title' }] }],
        contents: {},
      })
    );

    expect(html).toContain('<em>stressed</em>');
    expect(html).toContain('A stressed title');
  });

  test('keeps bidi isolation INSIDE a mark', () => {
    // The regression this file exists to prevent. A Latin run inside an Arabic
    // paragraph is wrapped in <bdi> today; wrapping it in <strong> must not be
    // what removes that.
    const html = renderParagraph(
      {
        text: 'الجهد 12 V في الدائرة',
        runs: [
          { text: 'الجهد ' },
          { text: '12 V', bold: true },
          { text: ' في الدائرة' },
        ],
      },
      { direction: 'rtl', lang: 'ar' }
    );

    expect(html).toContain('<bdi>');
    // The isolate belongs with the run, inside its mark — not swallowing it.
    expect(html).toMatch(/<strong><bdi>12 V<\/bdi><\/strong>/);
  });
});
