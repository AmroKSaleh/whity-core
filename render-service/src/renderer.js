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
// Printing is its own wait, and it was the one this file did not name. Left
// unset, `page.pdf()` takes puppeteer's implicit 30 s protocol timeout — a
// hard ceiling nobody chose and no environment variable could move, sitting
// far BELOW the batch sizes documents.render_max_rows/_max_pages allow (a
// 500-row job over a three-page template is ~1500 pages and reaches it while
// the settings still consider the request well within bounds). The default
// stays 30 s so behaviour is unchanged; naming it makes it raiseable by an
// operator who has also raised the limits and RENDER_TIMEOUT_SECONDS on the
// core side. See docs/wiki/Document-Render-Service.md.
const PDF_TIMEOUT_MS = Number(process.env.RENDER_PDF_TIMEOUT_MS || 30000);

/**
 * The launch flags, named rather than inlined so the boot-time capability
 * probe (src/capability-probe.js, #1134) can launch with EXACTLY these. A
 * probe run under different flags is measuring a browser this service does not
 * run, which is the same class of mistake as verifying something other than
 * the thing you shipped.
 *
 * Naming them changes nothing about how `launchBrowser()` behaves.
 */
const CHROMIUM_LAUNCH_ARGS = [
  // Standard, well-documented flags for running Chromium as root inside
  // a container without a working sandbox namespace.
  '--no-sandbox',
  '--disable-setuid-sandbox',
  // /dev/shm is small by default in Docker; Chromium falls back to
  // /tmp instead of crashing under memory pressure.
  '--disable-dev-shm-usage',
  '--disable-gpu',
];

/** The browser binary this service launches. Exported for the same reason. */
function chromiumExecutablePath() {
  return process.env.PUPPETEER_EXECUTABLE_PATH || DEFAULT_CHROMIUM_PATH;
}

let browserPromise = null;

function launchBrowser() {
  if (!browserPromise) {
    browserPromise = puppeteer.launch({
      executablePath: chromiumExecutablePath(),
      headless: true,
      args: CHROMIUM_LAUNCH_ARGS,
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
      timeout: PDF_TIMEOUT_MS,
    });

    return Buffer.from(pdf);
  } finally {
    await page.close().catch(() => {});
  }
}

/**
 * The shared, lazily-launched Chromium instance.
 *
 * Exported for the FLOWING render mode (src/flow/render.js, #1072), which is a
 * separate document pipeline but must not launch a second browser: Chromium is
 * the expensive thing in this service and one instance serving both modes is
 * the whole reason `launchBrowser` memoises. Nothing about `renderToPdf`'s own
 * behaviour changes by exporting this.
 */
function getBrowser() {
  return launchBrowser();
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

module.exports = { renderToPdf, getBrowser, shutdown, CHROMIUM_LAUNCH_ARGS, chromiumExecutablePath };
