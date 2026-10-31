'use strict';

/**
 * The shared headless-Chromium instance (ADR 0012).
 *
 * Launches ONE headless-Chromium instance, lazily, on the first render call,
 * and reuses it across requests (a fresh `page` per render, closed after) —
 * relaunching Chromium per request would be far too slow for a service whose
 * whole reason to exist is bursty/seasonal batch export load.
 *
 * The pages themselves are driven by src/flow/render.js (documents and label
 * sheets); this file owns only the browser's lifecycle and launch flags.
 */

/*
 * puppeteer-core is ESM-only from v25, and this package is CommonJS, so it is
 * loaded lazily through dynamic import rather than required at the top.
 *
 * Node could do it either way — `require()` of an ESM module works from 22.12
 * — but `engines` here says `>=22`, which admits 22.0 through 22.11 where it
 * does not. Dynamic import works on all of them.
 *
 * JEST CANNOT DO IT EITHER WAY, which is the reason the load moved out of
 * module scope at all. jest-runtime refuses `require()` of an ESM module and
 * says so in as many words (`createRequireEsmError`), and it does that while
 * merely LOADING a module — so a top-level require here failed five suites and
 * thirty-six tests that never touch Chromium, purely because src/server.js
 * reaches this file through the import graph. Jest 30 does not change this;
 * it only produces a clearer message.
 *
 * So the dependency is loaded at the moment it is used, by which point a test
 * has either injected a launcher or is not exercising this path.
 */
let injectedLaunch = null;

/**
 * Replace the browser launcher. Tests only; returns the previous value so a
 * caller can restore it.
 *
 * This mirrors `runCapabilityProbe(deps.launch)` in src/capability-probe.js,
 * which has always taken its launcher as an argument. The seam is here for the
 * same reason it is there — and because the alternative, mocking the module
 * itself, is what stopped working.
 *
 * @param {?Function} fn
 * @returns {?Function}
 */
function setLauncherForTests(fn) {
  const previous = injectedLaunch;
  injectedLaunch = fn;
  return previous;
}

async function launchChromium(options) {
  if (injectedLaunch) {
    return injectedLaunch(options);
  }

  const mod = await import('puppeteer-core');

  return (mod.default ?? mod).launch(options);
}

const DEFAULT_CHROMIUM_PATH = '/usr/bin/chromium';

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
    browserPromise = launchChromium({
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

/**
 * The shared, lazily-launched Chromium instance.
 *
 * Used by src/flow/render.js for every document and label sheet: Chromium is
 * the expensive thing in this service and one instance serving every request
 * is the whole reason `launchBrowser` memoises.
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

module.exports = {
  getBrowser,
  shutdown,
  CHROMIUM_LAUNCH_ARGS,
  chromiumExecutablePath,
  setLauncherForTests,
};
