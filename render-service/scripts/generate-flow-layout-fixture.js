#!/usr/bin/env node
'use strict';

/**
 * Two documents with IDENTICAL content, differing only in the per-block layout
 * hints (#1186).
 *
 * WHY A PAIR. The paginator packs page boxes itself, inside a real browser,
 * measuring real text — so nothing in the Jest suite can prove it honoured a
 * hint. What CAN be proved, from outside, is that the same content produced a
 * different NUMBER of pages, and that the only difference between the two
 * requests was the hint. One document alone would show a page count that a
 * reader has to take on trust; the pair shows a difference that only the hint
 * can explain.
 *
 * The content is deliberately tiny. Three short paragraphs fit on one page with
 * room to spare, so the "with hints" document can only reach three pages by the
 * two `breakBefore` blocks doing what they say. A long fixture would leave the
 * count explainable by ordinary reflow.
 *
 *   node generate-flow-layout-fixture.js --variant plain  --out plain.json
 *   node generate-flow-layout-fixture.js --variant hinted --out hinted.json
 */

const fs = require('node:fs');

function parseArgs(argv) {
  const args = { variant: 'plain', out: null };
  for (let i = 2; i < argv.length; i += 1) {
    if (argv[i] === '--variant') args.variant = argv[i + 1];
    if (argv[i] === '--out') args.out = argv[i + 1];
  }
  return args;
}

/** Short enough that all three sit on one page together. */
const PARAGRAPHS = [
  'The first paragraph of the layout fixture.',
  'The second paragraph of the layout fixture.',
  'The third paragraph of the layout fixture.',
];

function build(variant) {
  const hinted = variant === 'hinted';

  return {
    page: { preset: 'a4', margin: { topMm: 20, rightMm: 20, bottomMm: 20, leftMm: 20 } },
    direction: 'ltr',
    content: PARAGRAPHS.map((text, i) => {
      const block = { type: 'paragraph', text };
      // The first paragraph starts the document, so a break before it would be
      // a page the renderer correctly declines to open. The other two each
      // claim a page of their own.
      if (hinted && i > 0) block.breakBefore = true;
      return block;
    }),
  };
}

const args = parseArgs(process.argv);
if (args.variant !== 'plain' && args.variant !== 'hinted') {
  console.error(`Unknown variant "${args.variant}". Use plain or hinted.`);
  process.exit(2);
}

const payload = build(args.variant);
const json = JSON.stringify(payload);

if (args.out) {
  fs.writeFileSync(args.out, json);
  console.log(`wrote ${args.variant} layout fixture to ${args.out} (${json.length} bytes)`);
} else {
  process.stdout.write(json);
}
