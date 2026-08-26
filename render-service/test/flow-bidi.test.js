'use strict';

/**
 * Bidi isolation (#1072).
 *
 * The failure this guards is specific and it is quiet. A contents entry in an
 * Arabic document ends in a page number — a run of Latin digits at the end of
 * a right-to-left line. Everything between that number and the previous Latin
 * run in the same line (spaces, dots, dashes, an em dash) is bidi-NEUTRAL, and
 * the Unicode algorithm resolves a neutral run sitting between two
 * left-to-right runs as itself left-to-right. Label identifier, neutrals and
 * page number therefore merge into one left-to-right run and print in reverse
 * of the order they were written: "Table 34 ... 78" becomes "78 ... 34". On
 * every line. Looking typeset.
 *
 * These tests are about the ISOLATION MARKUP. That the isolation actually
 * lands the page number at the correct edge of a real laid-out line is a
 * question about a browser, and is checked in the render-tier CI job against
 * the geometry of a real render.
 */

const { isolateForeignRuns, escapeHtml } = require('../src/flow/bidi');

describe('escapeHtml', () => {
  test('escapes every character that can change markup', () => {
    expect(escapeHtml('<a href="x">&\'</a>')).toBe('&lt;a href=&quot;x&quot;&gt;&amp;&#39;&lt;/a&gt;');
  });

  test('treats null and undefined as empty', () => {
    expect(escapeHtml(null)).toBe('');
    expect(escapeHtml(undefined)).toBe('');
  });
});

describe('isolateForeignRuns in an RTL document', () => {
  test('isolates a bare number', () => {
    expect(isolateForeignRuns('78', 'rtl')).toBe('<bdi>78</bdi>');
  });

  test('isolates each Latin run in a mixed line and leaves the Arabic alone', () => {
    const html = isolateForeignRuns('جدول 34 — TBL-034 وصف', 'rtl');
    expect(html).toBe('جدول <bdi>34</bdi> — <bdi>TBL-034</bdi> وصف');
  });

  // An identifier is one run, punctuation included: isolating "A" and "7"
  // separately would let the hyphen between them reorder.
  test('keeps identifier punctuation inside the isolate', () => {
    expect(isolateForeignRuns('المعرّف REF-A17/2 هنا', 'rtl')).toContain('<bdi>REF-A17/2</bdi>');
  });

  // A multi-word Latin phrase must be ONE isolate. Isolating its words
  // separately is correct bidi and wrong typography: each word keeps its own
  // order while the words themselves run right-to-left.
  test('isolates a multi-word Latin phrase as a single run', () => {
    expect(isolateForeignRuns('نص Sample Label A-7 نهاية', 'rtl')).toBe(
      'نص <bdi>Sample Label A-7</bdi> نهاية'
    );
  });

  test('leaves trailing whitespace outside the isolate', () => {
    // Otherwise the gap between the isolated run and the next word collapses
    // into the isolate and the spacing reads wrong in RTL.
    expect(isolateForeignRuns('أ B ج', 'rtl')).toBe('أ <bdi>B</bdi> ج');
  });

  test('escapes inside the isolate', () => {
    expect(isolateForeignRuns('نص <b>x</b>', 'rtl')).toBe('نص &lt;<bdi>b</bdi>&gt;<bdi>x</bdi>&lt;/<bdi>b</bdi>&gt;');
  });

  test('an all-Arabic string is untouched', () => {
    expect(isolateForeignRuns('نص عربي فقط', 'rtl')).toBe('نص عربي فقط');
  });
});

describe('isolateForeignRuns in an LTR document', () => {
  test('isolates the Arabic run instead', () => {
    expect(isolateForeignRuns('Table 12 - نص عربي here', 'ltr')).toBe('Table 12 - <bdi>نص عربي</bdi> here');
  });

  test('an all-Latin string is untouched', () => {
    expect(isolateForeignRuns('Table 12 of 78', 'ltr')).toBe('Table 12 of 78');
  });

  test('a page number in an LTR document needs no isolate', () => {
    expect(isolateForeignRuns('78', 'ltr')).toBe('78');
  });
});

describe('the same rule is available to the browser', () => {
  // src/flow/html.js ships this module's SOURCE into the page so the
  // paginator's generated front matter and running heads isolate identically.
  // Two copies of the rule would be two chances to isolate a page number in
  // one place and not the other.
  test('the module exposes itself on window when there is one', () => {
    const source = require('node:fs').readFileSync(require.resolve('../src/flow/bidi.js'), 'utf8');
    expect(source).toMatch(/window\.__flowBidi/);
    const sandbox = { window: {} };
    // eslint-disable-next-line no-new-func
    new Function('window', source)(sandbox.window);
    expect(typeof sandbox.window.__flowBidi.isolateForeignRuns).toBe('function');
    expect(sandbox.window.__flowBidi.isolateForeignRuns('نص A-7', 'rtl')).toBe('نص <bdi>A-7</bdi>');
  });
});
