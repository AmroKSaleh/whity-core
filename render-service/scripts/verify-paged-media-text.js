#!/usr/bin/env node
'use strict';
/**
 * Verifies the two paged-media facts that only exist as printed text (#1134).
 *
 * The boot-time capability probe answers twelve questions at computed-style or
 * page-geometry level, and deliberately leaves two alone:
 *
 *   - does an `@page` margin box draw at all?
 *   - do `counter(page)` and `counter(pages)` resolve inside one?
 *
 * Both manifest only as glyphs on a page, so the honest way to read them back
 * is a PDF text extractor — and `pdfjs-dist` is a devDependency, absent from
 * the runtime image on purpose. Asking the CSSOM instead is not a substitute:
 * that is the same class of answer as `CSS.supports('content',
 * 'target-counter(…)')`, which returns `true` for a declaration Chromium then
 * drops at computed-value time.
 *
 * So the work is split. `render-paged-media-probe.js` runs INSIDE the image,
 * where the browser is, and writes a PDF. This runs on the CI runner, where the
 * parser is, and grades it. Neither half can flatter the other.
 *
 * WHY THIS MATTERS AT ALL. The flowing render mode's page furniture is drawn
 * in-document rather than by these features, so nothing ships broken if they
 * vanish. What changes is the ARGUMENT: `docs/wiki/Document-Render-Service.md`
 * records that Chromium 151 supports margin boxes and page counters, and a
 * future design may lean on that. An unpinned browser can withdraw it in a
 * rebuild, and a recorded fact nobody re-checks is how this repository has
 * already had three architectural decisions defended on measurements that no
 * longer reproduce.
 *
 * Usage:
 *   node scripts/verify-paged-media-text.js --pdf out/paged-media.pdf
 *   node scripts/verify-paged-media-text.js --pdf out/paged-media.pdf --expect-pages 3 --json
 *
 * Exit 0 only when every page carries its own correct "N of M" footer.
 */
const fs = require('node:fs');

/** Matches the footer the fixture asks for: `counter(page) " of " counter(pages)`. */
const FOOTER_PATTERN = /(\d+)\s+of\s+(\d+)/;

/**
 * Grade extracted page text. PURE — no PDF, no browser, no filesystem.
 *
 * Kept separate from the extraction so the verdicts are unit-testable, which is
 * the same split `capability-probe.js` makes for the same reason: a rule that
 * can only be exercised by rendering is a rule nobody exercises.
 *
 * @param {string[]} pageTexts One string per physical page, in order.
 * @param {number} expectedPages How many pages the fixture must produce.
 * @returns {{ok: boolean, pages: number, failures: string[]}}
 */
function judgePagedMediaText(pageTexts, expectedPages) {
  const failures = [];

  if (pageTexts.length !== expectedPages) {
    failures.push(
      `expected ${expectedPages} page(s), got ${pageTexts.length} — the fixture's ` +
      'page breaks changed, so every assertion below is about a different document'
    );
  }

  pageTexts.forEach((text, index) => {
    const pageNumber = index + 1;
    const match = FOOTER_PATTERN.exec(text);

    if (!match) {
      // No footer at all is the interesting failure: it means the margin box
      // did not draw, which is the capability this whole script exists to check.
      failures.push(`page ${pageNumber}: no "N of M" footer — the @page margin box drew nothing`);
      return;
    }

    const [, printedPage, printedTotal] = match;

    if (Number(printedPage) !== pageNumber) {
      failures.push(
        `page ${pageNumber}: footer says page ${printedPage} — counter(page) is not tracking`
      );
    }

    if (Number(printedTotal) !== pageTexts.length) {
      failures.push(
        `page ${pageNumber}: footer says ${printedTotal} total, document has ${pageTexts.length} ` +
        '— counter(pages) is not resolving'
      );
    }
  });

  return { ok: failures.length === 0, pages: pageTexts.length, failures };
}

/** @returns {Promise<string[]>} one string per page, in document order. */
async function extractPageTexts(file) {
  // pdfjs-dist ships ESM only and this script is CommonJS, so it is imported
  // dynamically — the same accommodation `verify-flow-pdf.js` makes.
  const pdfjs = await import('pdfjs-dist/legacy/build/pdf.mjs');
  const data = new Uint8Array(fs.readFileSync(file));
  const doc = await pdfjs.getDocument({ data, useSystemFonts: false, isEvalSupported: false }).promise;

  const texts = [];
  for (let pageNumber = 1; pageNumber <= doc.numPages; pageNumber++) {
    const page = await doc.getPage(pageNumber);
    const content = await page.getTextContent();
    texts.push(content.items.map((item) => item.str ?? '').join(' '));
  }

  return texts;
}

function parseArgs(argv) {
  const args = { pdf: null, expectPages: 3, json: false };

  for (let i = 0; i < argv.length; i++) {
    if (argv[i] === '--pdf') args.pdf = argv[++i] ?? null;
    else if (argv[i] === '--expect-pages') args.expectPages = Number(argv[++i]);
    else if (argv[i] === '--json') args.json = true;
  }

  return args;
}

async function main() {
  const { pdf, expectPages, json } = parseArgs(process.argv.slice(2));

  if (!pdf) {
    process.stderr.write('verify-paged-media-text: --pdf <file.pdf> is required\n');
    process.exit(2);
  }

  const verdict = judgePagedMediaText(await extractPageTexts(pdf), expectPages);

  if (json) {
    process.stdout.write(`${JSON.stringify(verdict, null, 2)}\n`);
  } else if (verdict.ok) {
    process.stdout.write(
      `verify-paged-media-text: OK — ${verdict.pages} page(s), every footer names its own ` +
      'page and the correct total (@page margin boxes and counter(page)/counter(pages) both work)\n'
    );
  } else {
    process.stderr.write('verify-paged-media-text: FAILED\n');
    for (const failure of verdict.failures) {
      process.stderr.write(`  ${failure}\n`);
    }
  }

  process.exit(verdict.ok ? 0 : 1);
}

if (require.main === module) {
  main().catch((error) => {
    process.stderr.write(`verify-paged-media-text: ${error && error.stack ? error.stack : error}\n`);
    process.exit(1);
  });
}

module.exports = { judgePagedMediaText, FOOTER_PATTERN };
