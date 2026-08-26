#!/usr/bin/env node
'use strict';

/**
 * Generates a synthetic flowing document large enough to be worth
 * paginating (#1072).
 *
 * `documents.render_enabled` defaults to false, so a fresh install renders
 * nothing at all and a render-tier feature has no natural way to be
 * exercised. This script is that way: it produces, from a seed and with no
 * input files, a document of the size the flowing mode exists for — well over
 * a hundred pages, dozens of tables, high dozens of figures, front matter
 * that references every one of them — which the CI job and any developer can
 * render and check without a database, a tenant, a template or a setting.
 *
 * EVERY WORD OF IT IS INVENTED. The prose is filler drawn from a fixed word
 * pool, the tables hold arithmetic on the row index, and the figures are
 * generated SVG. Nothing here is derived from any real document, and nothing
 * in it belongs to any sector or institution — which also means the fixture
 * can live in the repository and be printed in a CI log without a second
 * thought.
 *
 * Each table and figure caption carries a stable INPUT-side token (TBL-034,
 * FIG-0042). The renderer's own numbering ("Table 34") is assigned
 * separately, at render time, so the two can be compared: scripts/verify-flow-pdf.js
 * finds the page a token is actually printed on and checks the front-matter
 * entry that names it.
 *
 * Usage:
 *   node scripts/generate-flow-fixture.js --direction rtl --out fixture.json
 *   node scripts/generate-flow-fixture.js --direction ltr --sections 30 --out f.json
 */

const fs = require('node:fs');
const path = require('node:path');

/* A tiny, deterministic PRNG (mulberry32). The fixture must be identical from
 * run to run or timings and page counts cannot be compared between two
 * approaches, and a flaky page count would make the CI check flaky. */
function mulberry32(seed) {
  let a = seed >>> 0;
  return function random() {
    a += 0x6d2b79f5;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

/* Generic structural vocabulary — the words a document uses to talk about its
 * own parts. No sector terms, no institution names, no borrowed phrasing. */
const AR_WORDS = [
  'نص', 'عنصر', 'قسم', 'بيان', 'مثال', 'وحدة', 'صفحة', 'فقرة', 'بند', 'رقم',
  'قيمة', 'عمود', 'سطر', 'ملحق', 'مرجع', 'فهرس', 'عنوان', 'وصف', 'ملاحظة',
  'تفصيل', 'مجموعة', 'نموذج', 'إطار', 'مستوى', 'ترتيب', 'محتوى', 'هامش',
  'متن', 'فاصل', 'تسلسل', 'مقطع', 'حجم', 'تجريبي', 'افتراضي', 'عام', 'بديل',
  'تكميلي', 'مبدئي', 'إضافي', 'مكرر', 'موحد', 'مختصر', 'موسع', 'مرتب',
  'متغير', 'ثابت', 'مقارن', 'مجمل', 'تفصيلي', 'مشترك',
];

const LA_WORDS = [
  'lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur', 'adipiscing',
  'elit', 'sed', 'eiusmod', 'tempor', 'incididunt', 'labore', 'dolore',
  'magna', 'aliqua', 'enim', 'minim', 'veniam', 'quis', 'nostrud',
  'exercitation', 'ullamco', 'laboris', 'aliquip', 'commodo', 'consequat',
  'duis', 'aute', 'irure', 'reprehenderit', 'voluptate', 'velit', 'esse',
  'cillum', 'fugiat', 'nulla', 'pariatur', 'excepteur', 'sint', 'occaecat',
  'cupidatat', 'proident', 'sunt', 'culpa', 'officia', 'deserunt', 'mollit',
  'anim', 'laborum',
];

/* Latin identifiers deliberately dropped into Arabic prose. A Latin run
 * inside an RTL sentence is the bidi case that goes wrong quietly, so the
 * fixture has to contain plenty of them or the RTL checks prove nothing. */
const MIXED_TOKENS = ['REF-A17', 'v2.4', 'ID/8891', 'SET-03', 'N=120', 'X-14', 'code/7'];

function pick(random, pool) {
  return pool[Math.floor(random() * pool.length)];
}

function sentence(random, direction, wordCount) {
  const pool = direction === 'rtl' ? AR_WORDS : LA_WORDS;
  const words = [];
  for (let i = 0; i < wordCount; i += 1) {
    words.push(pick(random, pool));
    // Roughly one sentence in six carries a Latin identifier.
    if (direction === 'rtl' && random() < 1 / 40) {
      words.push(pick(random, MIXED_TOKENS));
    }
  }
  const text = words.join(' ');
  return text.charAt(0).toUpperCase() + text.slice(1) + '.';
}

function paragraph(random, direction, min, max) {
  const sentences = [];
  const count = 3 + Math.floor(random() * 3);
  for (let i = 0; i < count; i += 1) {
    sentences.push(sentence(random, direction, min + Math.floor(random() * (max - min))));
  }
  return sentences.join(' ');
}

function headingText(random, direction, token) {
  const pool = direction === 'rtl' ? AR_WORDS : LA_WORDS;
  const words = [];
  for (let i = 0; i < 2 + Math.floor(random() * 3); i += 1) {
    words.push(pick(random, pool));
  }
  const text = words.join(' ');
  return `${text.charAt(0).toUpperCase() + text.slice(1)} ${token}`;
}

/* A generated placeholder figure: bands, a grid and a label, all drawn from
 * the index so no two look alike and none of them is a real image. */
function figureSvg(index, random) {
  const hue = (index * 37) % 360;
  const bars = [];
  for (let i = 0; i < 7; i += 1) {
    const h = 20 + Math.floor(random() * 80);
    bars.push(
      `<rect x="${16 + i * 38}" y="${120 - h}" width="26" height="${h}" fill="hsl(${(hue + i * 12) % 360} 55% 62%)"/>`
    );
  }
  const svg =
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 140" width="300" height="140">` +
    `<rect width="300" height="140" fill="hsl(${hue} 30% 96%)" stroke="hsl(${hue} 35% 70%)" stroke-width="2"/>` +
    `<line x1="12" y1="120" x2="288" y2="120" stroke="hsl(${hue} 30% 55%)" stroke-width="1.5"/>` +
    bars.join('') +
    `<text x="150" y="136" font-family="monospace" font-size="9" text-anchor="middle" fill="hsl(${hue} 40% 35%)">FIGURE-${String(index).padStart(4, '0')}</text>` +
    `</svg>`;
  return 'data:image/svg+xml;base64,' + Buffer.from(svg, 'utf8').toString('base64');
}

function tableBlock(random, direction, token, rowCount) {
  const columns =
    direction === 'rtl'
      ? ['المعرّف', 'القيمة الأولى', 'القيمة الثانية', 'الفرق', 'الحالة']
      : ['Identifier', 'First value', 'Second value', 'Delta', 'State'];
  const states = direction === 'rtl' ? ['مبدئي', 'مؤكد', 'مراجَع'] : ['draft', 'final', 'revised'];
  const rows = [];
  for (let r = 1; r <= rowCount; r += 1) {
    const a = 100 + ((r * 37) % 900);
    const b = 100 + ((r * 53) % 900);
    rows.push([
      `${token}-R${String(r).padStart(2, '0')}`,
      String(a),
      String(b),
      String(b - a),
      pick(random, states),
    ]);
  }
  return {
    type: 'table',
    caption: `${token} ${sentence(random, direction, 4).replace(/\.$/, '')}`,
    columns,
    rows,
  };
}

function build(options) {
  const random = mulberry32(options.seed);
  const direction = options.direction;
  const content = [];

  // The one marker that says, from inside the printed PDF, where the
  // generated front matter stops and the body starts. Unnumbered and left out
  // of the contents list, so it exists only as a landmark for the verifier —
  // which must not be allowed to take the renderer's word for the boundary.
  content.push({
    type: 'heading',
    level: 1,
    text: 'BODY-START-SENTINEL',
    unnumbered: true,
    inContents: false,
  });

  let tableIndex = 0;
  let figureIndex = 0;
  const totalTables = options.tables;
  const totalFigures = options.figures;

  for (let s = 1; s <= options.sections; s += 1) {
    const sectionToken = `SEC-${String(s).padStart(3, '0')}`;
    content.push({ type: 'heading', level: 1, text: headingText(random, direction, sectionToken) });
    content.push({ type: 'paragraph', text: paragraph(random, direction, 14, 26) });

    const subsections = 3 + Math.floor(random() * 2);
    for (let b = 1; b <= subsections; b += 1) {
      const subToken = `${sectionToken}.${b}`;
      content.push({ type: 'heading', level: 2, text: headingText(random, direction, subToken) });

      const paragraphs = 3 + Math.floor(random() * 3);
      for (let p = 0; p < paragraphs; p += 1) {
        content.push({ type: 'paragraph', text: paragraph(random, direction, 12, 30) });

        // Tables and figures are spread evenly through the body rather than
        // clustered, so page breaks fall inside them often enough to exercise
        // row-splitting and figure placement.
        const progress = (content.length / options.targetBlocks);
        if (tableIndex < totalTables && random() < (totalTables / options.targetBlocks) * 1.6) {
          tableIndex += 1;
          const token = `TBL-${String(tableIndex).padStart(3, '0')}`;
          content.push(tableBlock(random, direction, token, 5 + Math.floor(random() * 22)));
        }
        if (figureIndex < totalFigures && random() < (totalFigures / options.targetBlocks) * 1.6) {
          figureIndex += 1;
          const token = `FIG-${String(figureIndex).padStart(4, '0')}`;
          content.push({
            type: 'figure',
            src: figureSvg(figureIndex, random),
            heightMm: 38 + Math.floor(random() * 26),
            alt: token,
            caption: `${token} ${sentence(random, direction, 5).replace(/\.$/, '')}`,
          });
        }
        void progress;
      }

      if (b === subsections && random() < 0.35) {
        content.push({ type: 'heading', level: 3, text: headingText(random, direction, `${subToken}.a`) });
        content.push({ type: 'paragraph', text: paragraph(random, direction, 12, 24) });
      }
    }
  }

  // Anything the distribution above did not place gets appended in a trailing
  // section, so the fixture always contains EXACTLY the requested number of
  // tables and figures — a verification target that changed with the seed
  // would be no target at all.
  if (tableIndex < totalTables || figureIndex < totalFigures) {
    content.push({
      type: 'heading',
      level: 1,
      text: headingText(random, direction, `SEC-${String(options.sections + 1).padStart(3, '0')}`),
    });
    while (tableIndex < totalTables) {
      tableIndex += 1;
      const token = `TBL-${String(tableIndex).padStart(3, '0')}`;
      content.push({ type: 'paragraph', text: paragraph(random, direction, 12, 20) });
      content.push(tableBlock(random, direction, token, 5 + Math.floor(random() * 18)));
    }
    while (figureIndex < totalFigures) {
      figureIndex += 1;
      const token = `FIG-${String(figureIndex).padStart(4, '0')}`;
      content.push({ type: 'paragraph', text: paragraph(random, direction, 10, 18) });
      content.push({
        type: 'figure',
        src: figureSvg(figureIndex, random),
        heightMm: 38 + Math.floor(random() * 26),
        alt: token,
        caption: `${token} ${sentence(random, direction, 5).replace(/\.$/, '')}`,
      });
    }
  }

  const rtl = direction === 'rtl';

  return {
    title: rtl ? 'مستند تجريبي مُولَّد' : 'Generated sample document',
    direction,
    lang: rtl ? 'ar' : 'en',
    page: {
      preset: 'a4',
      margin: { topMm: 25, rightMm: 20, bottomMm: 25, leftMm: 20 },
    },
    header: {
      start: '{{title}}',
      end: '{{section}}',
    },
    footer: {
      center: rtl ? 'صفحة {{page}} من {{pages}}' : 'Page {{page}} of {{pages}}',
    },
    frontMatter: [
      { kind: 'contents' },
      { kind: 'tables' },
      { kind: 'figures' },
    ],
    content,
  };
}

function parseArgs(argv) {
  const args = {
    direction: 'rtl',
    sections: 30,
    tables: 60,
    figures: 90,
    seed: 20260826,
    out: '',
  };
  for (let i = 2; i < argv.length; i += 1) {
    const key = argv[i];
    const value = argv[i + 1];
    if (key === '--direction') { args.direction = value === 'ltr' ? 'ltr' : 'rtl'; i += 1; }
    else if (key === '--sections') { args.sections = Number(value); i += 1; }
    else if (key === '--tables') { args.tables = Number(value); i += 1; }
    else if (key === '--figures') { args.figures = Number(value); i += 1; }
    else if (key === '--seed') { args.seed = Number(value); i += 1; }
    else if (key === '--out') { args.out = value; i += 1; }
  }
  args.targetBlocks = args.sections * 4 * 5;
  return args;
}

function main() {
  const args = parseArgs(process.argv);
  const payload = build(args);
  const json = JSON.stringify(payload);
  if (args.out) {
    fs.mkdirSync(path.dirname(path.resolve(args.out)), { recursive: true });
    fs.writeFileSync(args.out, json);
    const tables = payload.content.filter((b) => b.type === 'table').length;
    const figures = payload.content.filter((b) => b.type === 'figure').length;
    // eslint-disable-next-line no-console
    console.log(
      `[flow-fixture] ${args.out}: ${payload.content.length} blocks, ${tables} tables, ` +
        `${figures} figures, ${(Buffer.byteLength(json) / 1024).toFixed(0)} KiB, dir=${args.direction}`
    );
  } else {
    process.stdout.write(json);
  }
}

if (require.main === module) {
  main();
}

module.exports = { build, figureSvg, mulberry32 };
