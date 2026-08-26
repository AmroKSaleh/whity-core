'use strict';

/**
 * Bidi isolation and HTML escaping for the flowing render mode (#1072).
 *
 * ONE implementation, loaded twice: `require`d by src/flow/html.js when the
 * body content is generated in Node, and injected verbatim into the page (see
 * html.js) so the browser-side paginator can use the identical rule when it
 * generates the front-matter lists and the running headers. Two copies of
 * this rule would be two chances to isolate a page number in one place and
 * not the other, and the failure is invisible in Latin text.
 *
 * The problem it solves:
 *
 *   A contents entry in an Arabic document is a right-to-left line ending in
 *   a page number, which is a run of Latin digits. The characters between the
 *   caption's own Latin identifier and that trailing number — spaces, dots,
 *   dashes — are bidi-NEUTRAL. The Unicode bidi algorithm resolves a neutral
 *   run between two left-to-right runs as itself left-to-right, so the
 *   identifier, the neutrals and the page number all merge into ONE
 *   left-to-right run and are printed in the reverse of the order they were
 *   written in. "Table 34 ... 78" comes out as "78 ... 34", on every line of
 *   the list, looking entirely deliberate.
 *
 *   `<bdi>` opens a bidi ISOLATE: text inside it neither absorbs its
 *   neighbours nor is absorbed by them, so each run keeps its own order and
 *   the line keeps the document's.
 */

const HTML_ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

function escapeHtml(value) {
  return String(value === null || value === undefined ? '' : value).replace(
    /[&<>"']/g,
    function (c) {
      return HTML_ESCAPES[c];
    }
  );
}

/* Strong right-to-left scripts: Hebrew/Arabic/Syriac/Thaana/NKo/Samaritan
 * (U+0590-U+08FF), Arabic Presentation Forms-A (U+FB1D-U+FDFF) and -B
 * (U+FE70-U+FEFF). */
const RTL_CLASS = '\\u0590-\\u08FF\\uFB1D-\\uFDFF\\uFE70-\\uFEFF';

/* A Latin/digit run: the punctuation that binds an identifier together, so
 * "A-7", "12.3" and "ISO/9" are isolated whole rather than in pieces, plus the
 * SPACE, so a multi-word Latin phrase inside an Arabic sentence ("Sample A-7")
 * is isolated as ONE run. Isolating its words separately would be correct bidi
 * and wrong typography: each word would keep its own order while the words
 * themselves ran right-to-left.
 *
 * Deliberately one character class rather than `TOKEN(?: +TOKEN)*`, which is
 * how this started: a quantifier inside a quantifier backtracks polynomially
 * on a long run of spaces, so a caller could hand this a caption and make it
 * the slowest thing in the render. One class matches the same runs in linear
 * time. The trailing space it now also swallows is put back outside the
 * isolate below, which the caller-facing behaviour already required. */
const LTR_RUN = /[A-Za-z0-9][A-Za-z0-9._\-\/+:#() ]*/g;

/* Also one class, also linear — see the note on LTR_RUN. */
function rtlRun() {
  return new RegExp('[' + RTL_CLASS + '][' + RTL_CLASS + '\\s.,()\\-]*', 'g');
}

/**
 * HTML-escape `text` and wrap every run whose direction disagrees with
 * `direction` in `<bdi>`.
 *
 * @param {string} text
 * @param {'rtl'|'ltr'} direction The document's BASE direction.
 * @returns {string} HTML.
 */
function isolateForeignRuns(text, direction) {
  const source = String(text === null || text === undefined ? '' : text);
  if (source === '') {
    return '';
  }

  const pattern = direction === 'rtl' ? new RegExp(LTR_RUN.source, 'g') : rtlRun();

  let out = '';
  let last = 0;
  let match = pattern.exec(source);
  while (match !== null) {
    out += escapeHtml(source.slice(last, match.index));
    const run = match[0];
    const trimmed = run.trimEnd();
    out += '<bdi>' + escapeHtml(trimmed) + '</bdi>';
    // Trailing whitespace the run happened to swallow belongs OUTSIDE the
    // isolate; inside it, the gap between the isolated run and the next word
    // collapses and the spacing reads wrong in RTL.
    if (trimmed.length !== run.length) {
      out += escapeHtml(run.slice(trimmed.length));
    }
    last = match.index + run.length;
    match = pattern.exec(source);
  }
  out += escapeHtml(source.slice(last));
  return out;
}

if (typeof module !== 'undefined' && module.exports) {
  module.exports = { escapeHtml, isolateForeignRuns, RTL_CLASS };
}
if (typeof window !== 'undefined') {
  window.__flowBidi = { escapeHtml: escapeHtml, isolateForeignRuns: isolateForeignRuns };
}
