#!/usr/bin/env node
'use strict';

/**
 * Checks, against a REAL laid-out render, that a contents entry puts its page
 * number on the correct edge and that mixed-direction runs inside the label
 * keep their own order (#1072).
 *
 * `src/flow/bidi.js` is unit-tested for the markup it produces. That proves
 * the `<bdi>` elements are there; it does not prove they do anything, because
 * whether a page number lands at the start or the end of a right-to-left line
 * is a question about a browser's bidi implementation and a flex row, not
 * about a string. This script asks the browser.
 *
 * What it asserts, in a real Chromium, on a real paginated document:
 *
 *   1. In an RTL document the entry LABEL sits at the right edge of the
 *      content box and the PAGE NUMBER at the left edge. In an LTR document,
 *      the mirror. Reversed is the classic failure: "Table 34 …… 78"
 *      printing as "78 …… 34".
 *   2. A Latin identifier inside an Arabic label keeps its internal
 *      left-to-right order: the "A" of "A-7" is to the LEFT of its "7". If the
 *      isolate were missing, the identifier could be reordered whole.
 *   3. A page number's digits read left to right: the "1" of "12" is left of
 *      its "2".
 *   4. The running header and footer are inside the page's margin bands and
 *      never overlap the content box — which is what "real margins" has to
 *      mean if a running head is to be drawn at all.
 *
 * All measurements come from `getBoundingClientRect` on the finished page
 * boxes, so they describe the geometry that is about to be printed.
 *
 * Usage: node scripts/check-flow-rtl-geometry.js [--keep-open]
 * Exit code 0 only when every assertion holds in both directions.
 */

const puppeteer = require('puppeteer-core');
const { normaliseFlowDocument } = require('../src/flow/document');
const { buildFlowHtml } = require('../src/flow/html');

/* A small, entirely invented document. Big enough to produce front matter and
 * a couple of body pages, small enough to check by hand. */
function fixture(direction) {
  const rtl = direction === 'rtl';
  const caption = (token) =>
    rtl
      ? `${token} وصف عنصر مع معرّف A-7 داخل نص عربي`
      : `${token} caption with نص A-7 عربي inside Latin text`;

  /* The WORST case for a contents entry, and a completely ordinary caption: one
   * that ENDS in a Latin run. Written as running text, the neutral characters
   * between that run and the trailing page number have strong left-to-right
   * runs on both sides, so the bidi algorithm merges identifier, leader and
   * page number into one left-to-right run and prints them backwards. Measured
   * in Chromium, the page number lands to the RIGHT of the identifier instead
   * of at the line's left edge. Half the items here end this way so the check
   * covers it. */
  const trailingIdentifierCaption = (token) =>
    rtl ? `وصف عنصر ينتهي بمعرّف A-7 ${token}` : `Caption ending in نص عربي A-7 ${token}`;
  const content = [
    { type: 'heading', level: 1, text: 'BODY-START-SENTINEL', unnumbered: true, inContents: false },
  ];
  for (let s = 1; s <= 6; s += 1) {
    content.push({
      type: 'heading',
      level: 1,
      text: rtl ? `قسم تجريبي SEC-${String(s).padStart(3, '0')}` : `Sample section SEC-${String(s).padStart(3, '0')}`,
    });
    content.push({
      type: 'paragraph',
      text: (rtl ? 'نص بديل مكرر للفحص فقط ' : 'Placeholder filler text for checking only ').repeat(24),
    });
    content.push({
      type: 'table',
      caption: (s % 2 === 0 ? caption : trailingIdentifierCaption)(`TBL-${String(s).padStart(3, '0')}`),
      columns: rtl ? ['المعرّف', 'القيمة'] : ['Identifier', 'Value'],
      rows: [['R-01', '110'], ['R-02', '220'], ['R-03', '330']],
    });
    content.push({
      type: 'figure',
      src:
        'data:image/svg+xml;base64,' +
        Buffer.from(
          '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 60" width="120" height="60">' +
            '<rect width="120" height="60" fill="#e8eef6" stroke="#8ba3c7"/></svg>',
          'utf8'
        ).toString('base64'),
      heightMm: 30,
      caption: (s % 2 === 0 ? trailingIdentifierCaption : caption)(`FIG-${String(s).padStart(4, '0')}`),
    });
  }

  return {
    title: rtl ? 'مستند فحص' : 'Check document',
    direction,
    page: { preset: 'a4', margin: { topMm: 25, rightMm: 20, bottomMm: 25, leftMm: 20 } },
    header: { start: '{{title}}', end: '{{section}}' },
    footer: { center: rtl ? 'صفحة {{page}} من {{pages}}' : 'Page {{page}} of {{pages}}' },
    frontMatter: [{ kind: 'contents' }, { kind: 'tables' }, { kind: 'figures' }],
    content,
  };
}

/** In the page: measure one contents entry and one caption per direction. */
function measure(direction) {
  const out = { direction, entries: [], furniture: [], errors: [] };

  const pages = Array.from(document.querySelectorAll('.flow-page'));
  if (pages.length === 0) {
    out.errors.push('no page boxes were produced');
    return out;
  }

  /* Per-character rectangles for a text node, so a reversed run is visible as
   * an x-order that disagrees with the character order. */
  function charBoxes(element, text) {
    const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT, null);
    let node = walker.nextNode();
    while (node) {
      const at = node.data.indexOf(text);
      if (at !== -1) {
        const boxes = [];
        for (let i = 0; i < text.length; i += 1) {
          const range = document.createRange();
          range.setStart(node, at + i);
          range.setEnd(node, at + i + 1);
          const rect = range.getBoundingClientRect();
          boxes.push({ ch: text.charAt(i), x: rect.left, right: rect.right });
        }
        return boxes;
      }
      node = walker.nextNode();
    }
    return null;
  }

  const entries = Array.from(document.querySelectorAll('.flow-toc-entry')).slice(0, 400);
  for (const entry of entries) {
    const label = entry.querySelector('.flow-toc-label');
    const number = entry.querySelector('.flow-toc-page');
    if (!label || !number) {
      out.errors.push('a contents entry is missing its label or its page slot');
      continue;
    }
    const row = entry.getBoundingClientRect();
    const l = label.getBoundingClientRect();
    const n = number.getBoundingClientRect();
    out.entries.push({
      token: (entry.getAttribute('data-toc-for') || ''),
      printed: number.textContent.trim(),
      rowLeft: row.left,
      rowRight: row.right,
      labelLeft: l.left,
      labelRight: l.right,
      numberLeft: n.left,
      numberRight: n.right,
      digitBoxes: charBoxes(number, number.textContent.trim()),
    });
  }

  // A Latin identifier inside a label, measured character by character.
  const identifier = [];
  for (const caption of Array.from(document.querySelectorAll('.flow-caption-text')).slice(0, 40)) {
    const boxes = charBoxes(caption, 'A-7');
    if (boxes) {
      identifier.push(boxes);
    }
  }
  out.identifier = identifier;

  // Running furniture against the content box, page by page.
  for (let i = 0; i < pages.length; i += 1) {
    const page = pages[i];
    const box = page.getBoundingClientRect();
    const content = page.querySelector('.flow-page-content').getBoundingClientRect();
    const header = page.querySelector('.flow-running-header');
    const footer = page.querySelector('.flow-running-footer');
    out.furniture.push({
      page: i + 1,
      pageTop: box.top,
      pageBottom: box.bottom,
      contentTop: content.top,
      contentBottom: content.bottom,
      contentLeft: content.left,
      contentRight: content.right,
      header: header
        ? { top: header.getBoundingClientRect().top, bottom: header.getBoundingClientRect().bottom, text: header.textContent.trim() }
        : null,
      footer: footer
        ? { top: footer.getBoundingClientRect().top, bottom: footer.getBoundingClientRect().bottom, text: footer.textContent.trim() }
        : null,
    });
  }

  return out;
}

function assertDirection(result, direction, failures) {
  const tag = `[${direction}]`;
  if (result.errors.length > 0) {
    failures.push(`${tag} ${result.errors.join('; ')}`);
  }
  if (result.entries.length === 0) {
    failures.push(`${tag} no contents entries were produced at all`);
    return;
  }

  let checkedEdges = 0;
  for (const entry of result.entries) {
    if (direction === 'rtl') {
      // Label hugs the right edge, page number the left.
      if (!(entry.numberRight <= entry.labelLeft + 1)) {
        failures.push(
          `${tag} entry ${entry.token}: the page number is not to the LEFT of the label ` +
            `(number ${entry.numberLeft.toFixed(1)}-${entry.numberRight.toFixed(1)}, ` +
            `label ${entry.labelLeft.toFixed(1)}-${entry.labelRight.toFixed(1)})`
        );
      } else if (!(Math.abs(entry.labelRight - entry.rowRight) < 2)) {
        failures.push(`${tag} entry ${entry.token}: the label does not start at the right edge of the row`);
      } else {
        checkedEdges += 1;
      }
    } else {
      if (!(entry.numberLeft >= entry.labelRight - 1)) {
        failures.push(`${tag} entry ${entry.token}: the page number is not to the RIGHT of the label`);
      } else if (!(Math.abs(entry.labelLeft - entry.rowLeft) < 2)) {
        failures.push(`${tag} entry ${entry.token}: the label does not start at the left edge of the row`);
      } else {
        checkedEdges += 1;
      }
    }

    // A multi-digit page number must read left to right whatever the base
    // direction is: digits are a left-to-right run and an isolate keeps them
    // one.
    const digits = entry.digitBoxes;
    if (digits && digits.length > 1) {
      for (let i = 1; i < digits.length; i += 1) {
        if (!(digits[i].x > digits[i - 1].x)) {
          failures.push(
            `${tag} entry ${entry.token}: page number "${entry.printed}" is printed with its digits reversed`
          );
          break;
        }
      }
    }
  }

  if (checkedEdges === 0) {
    failures.push(`${tag} not one contents entry passed the edge check`);
  }

  // A Latin identifier inside the label.
  if (result.identifier.length === 0) {
    failures.push(`${tag} the identifier "A-7" was not found in any caption, so nothing was checked`);
  }
  for (const boxes of result.identifier) {
    if (!(boxes[0].x < boxes[1].x && boxes[1].x < boxes[2].x)) {
      failures.push(
        `${tag} the Latin identifier "A-7" inside the label is reordered ` +
          `(x: ${boxes.map((b) => b.x.toFixed(1)).join(', ')})`
      );
      break;
    }
  }

  // Running furniture in the margins, never over the content.
  let withHeader = 0;
  let withFooter = 0;
  for (const page of result.furniture) {
    if (page.header) {
      withHeader += 1;
      if (!(page.header.bottom <= page.contentTop + 1)) {
        failures.push(`${tag} page ${page.page}: the running header overlaps the content box`);
      }
      if (!(page.header.top >= page.pageTop - 1)) {
        failures.push(`${tag} page ${page.page}: the running header is outside the page box`);
      }
    }
    if (page.footer) {
      withFooter += 1;
      if (!(page.footer.top >= page.contentBottom - 1)) {
        failures.push(`${tag} page ${page.page}: the running footer overlaps the content box`);
      }
      if (!(page.footer.bottom <= page.pageBottom + 1)) {
        failures.push(`${tag} page ${page.page}: the running footer is outside the page box`);
      }
    }
    if (!(page.contentLeft > page.pageTop - Infinity)) {
      failures.push(`${tag} page ${page.page}: content box has no geometry`);
    }
  }
  if (withFooter !== result.furniture.length) {
    failures.push(
      `${tag} the running footer is on ${withFooter} of ${result.furniture.length} pages; it must be on every page`
    );
  }
  if (withHeader === 0) {
    failures.push(`${tag} no page carries a running header`);
  }
}

async function main() {
  const browser = await puppeteer.launch({
    executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || '/usr/bin/chromium',
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
  });

  const failures = [];
  const summary = {};

  try {
    for (const direction of ['rtl', 'ltr']) {
      const doc = normaliseFlowDocument(fixture(direction));
      const page = await browser.newPage();
      await page.setContent(buildFlowHtml(doc), { waitUntil: 'load', timeout: 60000 });
      await page.waitForFunction('window.__FLOW_READY__ === true', { timeout: 60000 });
      const error = await page.evaluate(() => window.__FLOW_ERROR__ || null);
      if (error) {
        failures.push(`[${direction}] pagination threw in the page: ${error}`);
        await page.close();
        continue;
      }
      const result = await page.evaluate(measure, direction);
      assertDirection(result, direction, failures);
      summary[direction] = {
        pages: result.furniture.length,
        entries: result.entries.length,
        identifiersChecked: result.identifier.length,
        sampleEntry: result.entries[0]
          ? { token: result.entries[0].token, printed: result.entries[0].printed }
          : null,
        sampleHeader: result.furniture.find((f) => f.header) ? result.furniture.find((f) => f.header).header.text : null,
        sampleFooter: result.furniture[0] && result.furniture[0].footer ? result.furniture[0].footer.text : null,
      };
      await page.close();
    }
  } finally {
    await browser.close();
  }

  /* eslint-disable no-console */
  console.log(JSON.stringify(summary, null, 2));
  if (failures.length > 0) {
    console.error(`\n${failures.length} geometry failure(s):`);
    for (const failure of failures.slice(0, 40)) {
      console.error(`  ${failure}`);
    }
    console.error('RESULT: FAIL');
    process.exit(1);
  }
  console.log('RESULT: PASS');
  /* eslint-enable no-console */
}

main().catch((err) => {
  // eslint-disable-next-line no-console
  console.error(err && err.stack ? err.stack : err);
  process.exit(2);
});
