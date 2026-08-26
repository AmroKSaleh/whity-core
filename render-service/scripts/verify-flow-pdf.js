#!/usr/bin/env node
'use strict';

/**
 * Checks a rendered flowing PDF against ITSELF (#1072).
 *
 * The thing that has to be true about a generated contents list is not that it
 * rendered. It is that the entry for a given item names the page that item is
 * actually on. Ninety figure entries carrying plausible-looking wrong numbers
 * is a worse outcome than ninety missing ones, because nothing about the
 * output looks wrong.
 *
 * So this script never asks the renderer anything. It opens the finished PDF,
 * extracts the text of every page with its coordinates, and:
 *
 *   1. finds the page each item token (TBL-034, FIG-0042, SEC-007) is
 *      PRINTED on, in the body — that is the ground truth,
 *   2. finds the front-matter entry that names the same token and reads the
 *      page number PRINTED beside it,
 *   3. compares the two, and
 *   4. separately checks that the running footer on physical page N says N.
 *
 * The boundary between generated front matter and body is taken from the PDF
 * too: the fixture's first body block is an unlisted heading reading
 * BODY-START-SENTINEL, which appears exactly once in the whole document.
 * Taking the renderer's word for where the front matter ends would make the
 * whole check circular.
 *
 * Reading the page number off an entry relies on ONE layout fact: a contents
 * entry is a flex row, so its page number is the item furthest towards the
 * line's end side — leftmost in a right-to-left document, rightmost in a
 * left-to-right one. That is checked rather than assumed: an entry whose
 * extreme item is not an integer is reported as unreadable, not skipped.
 *
 * Usage:
 *   node scripts/verify-flow-pdf.js --pdf out.pdf --direction rtl
 *   node scripts/verify-flow-pdf.js --pdf out.pdf --direction ltr --expect-pages 132 --json
 *
 * Exit code 0 only when every comparison passes.
 */

const fs = require('node:fs');

/* Vertical tolerance, in PDF points, for deciding two glyphs share a line.
 * Baselines within a line vary by a fraction of a point; consecutive lines in
 * this stylesheet are never closer than ~11pt. */
const LINE_TOLERANCE = 3;

/** Tokens the fixture stamps into headings, table captions and figure
 * captions. The negative lookahead keeps "SEC-001" from matching inside
 * "SEC-001.3". */
const TOKEN_PATTERN = /\b(?:TBL-\d{3}|FIG-\d{4}|SEC-\d{3}(?:\.\d+)*(?:\.[a-z])?)\b(?![\d.])/g;

async function loadPdf(file) {
  // pdfjs-dist ships ESM only; this script is CommonJS, so it is imported
  // dynamically. The `legacy` build is the one that runs under Node without a
  // DOM.
  const pdfjs = await import('pdfjs-dist/legacy/build/pdf.mjs');
  const data = new Uint8Array(fs.readFileSync(file));
  return pdfjs.getDocument({ data, useSystemFonts: false, isEvalSupported: false }).promise;
}

/**
 * Extract one page as lines of text with the x-extent of every run, so a
 * caller can ask which run sits at the line's start or end.
 *
 * @returns {Promise<Array<{y: number, text: string, items: Array<{str: string, x: number}>}>>}
 */
async function pageLines(doc, pageNumber) {
  const page = await doc.getPage(pageNumber);
  const content = await page.getTextContent();
  const buckets = [];

  for (const item of content.items) {
    if (typeof item.str !== 'string' || item.str === '') {
      continue;
    }
    const x = item.transform[4];
    const y = item.transform[5];
    let bucket = null;
    for (const candidate of buckets) {
      if (Math.abs(candidate.y - y) <= LINE_TOLERANCE) {
        bucket = candidate;
        break;
      }
    }
    if (!bucket) {
      bucket = { y, items: [] };
      buckets.push(bucket);
    }
    bucket.items.push({ str: item.str, x, width: item.width || 0 });
  }

  buckets.sort((a, b) => b.y - a.y);
  for (const bucket of buckets) {
    bucket.items.sort((a, b) => a.x - b.x);
    // Join with a space wherever there is a visible GAP between two runs.
    // Without this, a contents entry whose page number abuts the start of the
    // label ("41" then "SEC-007.3", laid out at opposite ends of an RTL row
    // but adjacent once sorted by x) concatenates to "41SEC-007.3" and no
    // word-boundary match can find the token in it — which reads as "this
    // document has no contents entries" rather than as a broken extractor.
    let text = '';
    let cursor = null;
    for (const item of bucket.items) {
      if (cursor !== null && item.x - cursor > 1) {
        text += ' ';
      }
      text += item.str;
      cursor = item.x + item.width;
    }
    bucket.text = text;
  }
  page.cleanup();
  return buckets;
}

/** The integer printed furthest towards the line's end side. */
function entryPageNumber(line, direction) {
  const candidates = line.items
    .map((item, index) => ({ index, x: item.x, value: item.str.trim() }))
    .filter((c) => /^\d+$/.test(c.value));
  if (candidates.length === 0) {
    return null;
  }
  // In an RTL document the flex row puts the page number at the far LEFT; in
  // an LTR one, at the far RIGHT.
  candidates.sort((a, b) => (direction === 'rtl' ? a.x - b.x : b.x - a.x));
  const extreme = candidates[0];

  // Guard against reading the label's own number: the page number must also
  // be the extreme ITEM of the line, not merely the extreme integer.
  const extremeItemX = direction === 'rtl'
    ? Math.min(...line.items.map((i) => i.x))
    : Math.max(...line.items.map((i) => i.x));
  const isExtremeItem = Math.abs(extreme.x - extremeItemX) < 1.5;

  return { value: Number(extreme.value), trustworthy: isExtremeItem };
}

function parseArgs(argv) {
  const args = { pdf: '', direction: 'rtl', expectPages: 0, json: false, quiet: false };
  for (let i = 2; i < argv.length; i += 1) {
    const key = argv[i];
    const value = argv[i + 1];
    if (key === '--pdf') { args.pdf = value; i += 1; }
    else if (key === '--direction') { args.direction = value === 'ltr' ? 'ltr' : 'rtl'; i += 1; }
    else if (key === '--expect-pages') { args.expectPages = Number(value); i += 1; }
    else if (key === '--json') { args.json = true; }
    else if (key === '--quiet') { args.quiet = true; }
  }
  return args;
}

async function verify(options) {
  const doc = await loadPdf(options.pdf);
  const pages = [];
  for (let p = 1; p <= doc.numPages; p += 1) {
    pages.push(await pageLines(doc, p));
  }

  const report = {
    pdf: options.pdf,
    direction: options.direction,
    pageCount: doc.numPages,
    bodyStartPage: 0,
    frontMatterPages: 0,
    entriesChecked: 0,
    entriesCorrect: 0,
    mismatches: [],
    unreadableEntries: [],
    missingGroundTruth: [],
    footerMismatches: [],
    errors: [],
  };

  // --- 1. Where does the body start? -------------------------------------
  const sentinelPages = [];
  pages.forEach((lines, index) => {
    if (lines.some((l) => l.text.includes('BODY-START-SENTINEL'))) {
      sentinelPages.push(index + 1);
    }
  });
  if (sentinelPages.length !== 1) {
    report.errors.push(
      `expected BODY-START-SENTINEL on exactly one page, found ${sentinelPages.length} (${sentinelPages.join(', ')})`
    );
    return report;
  }
  report.bodyStartPage = sentinelPages[0];
  report.frontMatterPages = sentinelPages[0] - 1;

  // --- 2. Ground truth: where each token is actually printed --------------
  const truth = new Map();
  for (let p = report.bodyStartPage; p <= doc.numPages; p += 1) {
    for (const line of pages[p - 1]) {
      TOKEN_PATTERN.lastIndex = 0;
      let match = TOKEN_PATTERN.exec(line.text);
      while (match !== null) {
        const token = match[0];
        if (!truth.has(token)) {
          truth.set(token, p);
        }
        match = TOKEN_PATTERN.exec(line.text);
      }
    }
  }

  // --- 3. What the front matter claims ------------------------------------
  const claimed = new Map();
  for (let p = 1; p < report.bodyStartPage; p += 1) {
    for (const line of pages[p - 1]) {
      TOKEN_PATTERN.lastIndex = 0;
      const match = TOKEN_PATTERN.exec(line.text);
      if (match === null) {
        continue;
      }
      const token = match[0];
      const number = entryPageNumber(line, options.direction);
      if (number === null || !number.trustworthy) {
        report.unreadableEntries.push({ token, page: p, line: line.text.slice(0, 80) });
        continue;
      }
      if (!claimed.has(token)) {
        claimed.set(token, { page: number.value, listedOn: p });
      }
    }
  }

  // --- 4. Compare ----------------------------------------------------------
  for (const [token, entry] of claimed) {
    report.entriesChecked += 1;
    const actual = truth.get(token);
    if (actual === undefined) {
      report.missingGroundTruth.push({ token, claimed: entry.page });
      continue;
    }
    if (actual === entry.page) {
      report.entriesCorrect += 1;
    } else {
      report.mismatches.push({ token, claimed: entry.page, actual, listedOn: entry.listedOn });
    }
  }

  // --- 5. Does the running footer on physical page N say N? ---------------
  for (let p = 1; p <= doc.numPages; p += 1) {
    const lines = pages[p - 1];
    if (lines.length === 0) {
      report.footerMismatches.push({ page: p, reason: 'no text on the page at all' });
      continue;
    }
    const footer = lines[lines.length - 1];
    const numbers = footer.items
      .map((i) => i.str.trim())
      .filter((s) => /^\d+$/.test(s))
      .map(Number);
    if (!numbers.includes(p) || !numbers.includes(doc.numPages)) {
      report.footerMismatches.push({
        page: p,
        reason: `footer reads "${footer.text.slice(0, 60)}"`,
        numbers,
      });
    }
  }

  if (options.expectPages && doc.numPages !== options.expectPages) {
    report.errors.push(`expected ${options.expectPages} pages, the PDF has ${doc.numPages}`);
  }

  return report;
}

async function main() {
  const args = parseArgs(process.argv);
  if (!args.pdf) {
    // eslint-disable-next-line no-console
    console.error('usage: verify-flow-pdf.js --pdf <file> [--direction rtl|ltr] [--expect-pages N] [--json]');
    process.exit(2);
  }

  const report = await verify(args);
  const failed =
    report.errors.length > 0 ||
    report.mismatches.length > 0 ||
    report.unreadableEntries.length > 0 ||
    report.missingGroundTruth.length > 0 ||
    report.footerMismatches.length > 0 ||
    report.entriesChecked === 0;

  if (args.json) {
    // eslint-disable-next-line no-console
    console.log(JSON.stringify(report, null, 2));
  } else {
    /* eslint-disable no-console */
    console.log(`PDF:                 ${report.pdf}`);
    console.log(`Pages:               ${report.pageCount}`);
    console.log(`Front matter pages:  ${report.frontMatterPages} (body starts on ${report.bodyStartPage})`);
    console.log(`Entries checked:     ${report.entriesChecked}`);
    console.log(`Entries correct:     ${report.entriesCorrect}`);
    console.log(`Mismatched entries:  ${report.mismatches.length}`);
    console.log(`Unreadable entries:  ${report.unreadableEntries.length}`);
    console.log(`No ground truth:     ${report.missingGroundTruth.length}`);
    console.log(`Footer mismatches:   ${report.footerMismatches.length}`);
    for (const m of report.mismatches.slice(0, 20)) {
      console.log(`  MISMATCH ${m.token}: list says ${m.claimed}, actually on ${m.actual}`);
    }
    for (const m of report.missingGroundTruth.slice(0, 20)) {
      console.log(`  NO GROUND TRUTH ${m.token} (listed as ${m.claimed})`);
    }
    for (const m of report.unreadableEntries.slice(0, 10)) {
      console.log(`  UNREADABLE ${m.token} on front-matter page ${m.page}`);
    }
    for (const m of report.footerMismatches.slice(0, 10)) {
      console.log(`  FOOTER page ${m.page}: ${m.reason}`);
    }
    for (const e of report.errors) {
      console.log(`  ERROR ${e}`);
    }
    console.log(failed ? 'RESULT: FAIL' : 'RESULT: PASS');
    /* eslint-enable no-console */
  }

  process.exit(failed ? 1 : 0);
}

if (require.main === module) {
  main().catch((err) => {
    // eslint-disable-next-line no-console
    console.error(err && err.stack ? err.stack : err);
    process.exit(2);
  });
}

module.exports = { verify, pageLines, entryPageNumber, TOKEN_PATTERN };
