'use strict';

/**
 * Puppeteer orchestration (ADR 0012 / WC-docdesigner Track 2).
 *
 * Launches ONE headless-Chromium instance, lazily, on the first render call,
 * and reuses it across requests (a fresh `page` per render, closed after) —
 * relaunching Chromium per request would be far too slow for a service whose
 * whole reason to exist is bursty/seasonal batch export load.
 *
 * Each render: opens a page, injects `{template, dataRows, sheet, blocks}` as
 * `window.__RENDER_PROPS__` BEFORE navigation (`evaluateOnNewDocument`), loads
 * the bundled harness (dist/harness/index.html, served statically by
 * src/server.js), injects the exact-mm `@page` CSS size (mirroring the
 * on-screen print stylesheet in
 * web/components/documents/document-designer.tsx), waits for the harness to
 * signal `window.__RENDER_READY__` (React committed + fonts settled + every
 * barcode/QR image loaded), then calls `page.pdf()` with
 * `preferCSSPageSize: true` so the injected `@page` rule — not a
 * width/height option — determines the exact physical size, and
 * `break-after: page` (in harness/styles.css) paginates one output PDF page
 * per `.doc-print-page`/tiled-sheet element, exactly like the browser print
 * flow this reuses.
 */

const puppeteer = require('puppeteer-core');

const DEFAULT_CHROMIUM_PATH = '/usr/bin/chromium';
const NAV_TIMEOUT_MS = Number(process.env.RENDER_NAV_TIMEOUT_MS || 20000);
const READY_TIMEOUT_MS = Number(process.env.RENDER_READY_TIMEOUT_MS || 20000);

let browserPromise = null;

function launchBrowser() {
  if (!browserPromise) {
    browserPromise = puppeteer.launch({
      executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || DEFAULT_CHROMIUM_PATH,
      headless: true,
      args: [
        // Standard, well-documented flags for running Chromium as root inside
        // a container without a working sandbox namespace.
        '--no-sandbox',
        '--disable-setuid-sandbox',
        // /dev/shm is small by default in Docker; Chromium falls back to
        // /tmp instead of crashing under memory pressure.
        '--disable-dev-shm-usage',
        '--disable-gpu',
      ],
    });
    browserPromise.catch(() => {
      // A failed launch must not permanently poison every future call — the
      // next render() attempt should try launching again.
      browserPromise = null;
    });
  }
  return browserPromise;
}

/** Whether a template render is tiled onto N-up sheet pages. */
function isSheetEnabled(sheet) {
  return Boolean(sheet && sheet.enabled);
}

/** The physical output page size (mm), mirroring the designer's own
 * `@page` computation (document-designer.tsx): the sheet size when tiled,
 * otherwise the template's own page size. */
function pageSizeMm(template, sheet) {
  if (isSheetEnabled(sheet)) {
    return { widthMm: sheet.sheetWidthMm, heightMm: sheet.sheetHeightMm };
  }
  return { widthMm: template.page.widthMm, heightMm: template.page.heightMm };
}

/**
 * Render one {template, dataRows, sheet, blocks} payload to PDF bytes.
 *
 * @param {{template: object, dataRows: object[], sheet?: object|null, blocks?: object}} payload
 * @param {{harnessUrl: string}} opts The harness's own served URL (http://127.0.0.1:<port>/_harness/index.html).
 * @returns {Promise<Buffer>}
 */
async function renderToPdf(payload, opts) {
  const browser = await launchBrowser();
  const page = await browser.newPage();
  try {
    await page.evaluateOnNewDocument((props) => {
      window.__RENDER_PROPS__ = props;
    }, {
      template: payload.template,
      dataRows: payload.dataRows,
      sheet: payload.sheet ?? undefined,
      blocks: payload.blocks ?? {},
    });

    await page.goto(opts.harnessUrl, { waitUntil: 'load', timeout: NAV_TIMEOUT_MS });

    const { widthMm, heightMm } = pageSizeMm(payload.template, payload.sheet);
    await page.addStyleTag({ content: `@page { size: ${widthMm}mm ${heightMm}mm; margin: 0; }` });

    await page.waitForFunction('window.__RENDER_READY__ === true', { timeout: READY_TIMEOUT_MS });

    const pdf = await page.pdf({
      printBackground: true,
      preferCSSPageSize: true,
      margin: { top: '0mm', right: '0mm', bottom: '0mm', left: '0mm' },
    });

    return Buffer.from(pdf);
  } finally {
    await page.close().catch(() => {});
  }
}

async function shutdown() {
  if (browserPromise) {
    const browser = await browserPromise.catch(() => null);
    if (browser) {
      await browser.close().catch(() => {});
    }
    browserPromise = null;
  }
}

module.exports = { renderToPdf, shutdown };
