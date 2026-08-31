#!/usr/bin/env node
'use strict';
/**
 * Renders the paged-media text fixture with THIS image's browser (#1134).
 *
 * Half of a check that cannot be done in one place. Two paged-media facts —
 * that `@page` margin boxes draw at all, and that `counter(page)` /
 * `counter(pages)` resolve inside one — manifest only as printed text, so
 * reading them back honestly needs a PDF text extractor. `pdfjs-dist` is a
 * devDependency and is absent from the runtime image, deliberately: shipping a
 * parser into production to serve a diagnostic is a poor trade.
 *
 * The stages hold opposite halves of what the check needs. The build stage has
 * the parser and no browser; the runtime stage has the browser and no parser.
 * So this script does the half only the image can do — render — and writes the
 * bytes out. `verify-paged-media-text.js` does the half only the runner can do.
 *
 * It asserts NOTHING. A renderer that also graded itself would be the circular
 * check `verify-flow-pdf.js` exists to avoid.
 *
 * Usage:
 *   node scripts/render-paged-media-probe.js --out /out/paged-media.pdf
 *
 * Exit 0 when the PDF was written.
 */
const fs = require('node:fs');
const path = require('node:path');

const puppeteer = require('puppeteer-core');

const { CHROMIUM_LAUNCH_ARGS, chromiumExecutablePath } = require('../src/renderer');
const { PAGED_MEDIA_TEXT_HTML } = require('../src/capability-probe');

function parseArgs(argv) {
  const args = { out: null };

  for (let i = 0; i < argv.length; i++) {
    if (argv[i] === '--out') {
      args.out = argv[++i] ?? null;
    }
  }

  return args;
}

async function main() {
  const { out } = parseArgs(process.argv.slice(2));

  if (!out) {
    process.stderr.write('render-paged-media-probe: --out <file.pdf> is required\n');
    process.exit(2);
  }

  const browser = await puppeteer.launch({
    executablePath: chromiumExecutablePath(),
    args: CHROMIUM_LAUNCH_ARGS,
  });

  try {
    const page = await browser.newPage();
    await page.setContent(PAGED_MEDIA_TEXT_HTML, { waitUntil: 'load' });

    // `preferCSSPageSize` so the fixture's own `@page` rule decides the sheet,
    // and `displayHeaderFooter` left OFF on purpose: the footer under test is
    // drawn by CSS, and Puppeteer's own header/footer would put a second,
    // unrelated string on every page for the extractor to trip over.
    const pdf = await page.pdf({ printBackground: true, preferCSSPageSize: true });

    fs.mkdirSync(path.dirname(out), { recursive: true });
    fs.writeFileSync(out, pdf);

    process.stdout.write(`render-paged-media-probe: wrote ${out} (${pdf.length} bytes)\n`);
  } finally {
    await browser.close();
  }
}

main().catch((error) => {
  process.stderr.write(`render-paged-media-probe: ${error && error.stack ? error.stack : error}\n`);
  process.exit(1);
});
