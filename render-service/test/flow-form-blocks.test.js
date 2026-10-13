'use strict';

/**
 * The flowing mode's THEME, its richer running head/foot slots, and the blocks a
 * printed form or exam paper needs: key facts, fields to fill in by hand, a list
 * panel, a question with its answer space, and a divider.
 *
 * Before these existed a question paper printed as a run of plain paragraphs:
 * choices one per line, answer space as blank white, the candidate's details
 * as an empty two-column table and no identity anywhere on the page. Every
 * thing below is something a caller could not express.
 *
 * What matters most:
 *
 * - DEFAULTS DO NOT MOVE. A document without a theme must print exactly as it
 *   did, so every colour the stylesheet used is now a custom property whose
 *   default is the old literal.
 * - EVERY CALLER STRING IS ESCAPED AND ISOLATED. Stems, choice text, labels and
 *   slot text are author-supplied, and an Arabic paper is full of Latin runs
 *   ("12 V", "10 Ω") that reorder without isolation.
 * - IMAGES ARE EMBEDDED, NEVER FETCHED, exactly as for `figure`: a running-head
 *   logo with an http src would be an outbound request from the render tier.
 */

const { validateFlowPayload, normaliseFlowDocument } = require('../src/flow/document');
const { buildFlowHtml } = require('../src/flow/html');

const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

const doc = (content, extra = {}) => ({ content, ...extra });

/** The block markup only: the inlined stylesheet and paginator mention every class. */
function body(payload) {
  const out = buildFlowHtml(normaliseFlowDocument(payload));
  const start = out.indexOf('<div id="flow-source"');
  const end = out.indexOf('<div id="flow-pages">');
  return out.slice(start, end);
}

function rootStyle(payload) {
  const out = buildFlowHtml(normaliseFlowDocument(payload));
  return out.slice(out.indexOf(':root {'), out.indexOf('</style>'));
}

describe('theme', () => {
  test('is optional, and without it the stylesheet defaults stand', () => {
    expect(validateFlowPayload(doc([{ type: 'paragraph', text: 'x' }]))).toBeNull();
    const style = rootStyle(doc([{ type: 'paragraph', text: 'x' }]));
    expect(style).not.toContain('--flow-ink');
    expect(buildFlowHtml(normaliseFlowDocument(doc([{ type: 'paragraph', text: 'x' }])))).toContain('<body>');
  });

  test('sets each colour as a custom property and marks the body themed', () => {
    const payload = doc([{ type: 'paragraph', text: 'x' }], {
      theme: { ink: '#032230', muted: '#4b5f69', accent: '#0c95d4', line: '#c9d6dc', tint: '#eef6fb' },
    });
    expect(validateFlowPayload(payload)).toBeNull();
    const style = rootStyle(payload);
    expect(style).toContain('--flow-ink: #032230');
    expect(style).toContain('--flow-muted: #4b5f69');
    expect(style).toContain('--flow-accent: #0c95d4');
    expect(style).toContain('--flow-line: #c9d6dc');
    expect(style).toContain('--flow-tint: #eef6fb');
    expect(buildFlowHtml(normaliseFlowDocument(payload))).toContain('<body class="flow-themed">');
  });

  test('refuses anything that is not a hex colour, so nothing reaches the stylesheet unescaped', () => {
    expect(validateFlowPayload(doc([{ type: 'paragraph', text: 'x' }], { theme: { ink: 'red;}</style><script>' } }))).toMatch(/theme\.ink/);
    expect(validateFlowPayload(doc([{ type: 'paragraph', text: 'x' }], { theme: { ink: 'blue' } }))).toMatch(/theme\.ink/);
    expect(validateFlowPayload(doc([{ type: 'paragraph', text: 'x' }], { theme: { glow: '#ffffff' } }))).toMatch(/theme\.glow/);
    expect(validateFlowPayload(doc([{ type: 'paragraph', text: 'x' }], { theme: 'dark' }))).toMatch(/"theme"/);
  });
});

describe('running head and foot slots', () => {
  test('a slot may still be a plain string', () => {
    expect(validateFlowPayload(doc([{ type: 'paragraph', text: 'x' }], { header: { start: 'Uni', end: '{{page}}' } }))).toBeNull();
  });

  test('a slot may carry text, a second line, an embedded image and a boxed label', () => {
    const payload = doc([{ type: 'paragraph', text: 'x' }], {
      header: {
        start: { text: 'جامعة المثال', subtext: 'EE201 · الامتحان النهائي', image: { src: PNG, alt: 'Crest', heightMm: 13 } },
        end: { badge: { label: 'النموذج', value: 'A' } },
      },
      footer: { end: { text: 'Prepared with', image: { src: PNG, alt: 'Brand', heightMm: 4.5 } } },
    });
    expect(validateFlowPayload(payload)).toBeNull();
    const normalised = normaliseFlowDocument(payload);
    expect(normalised.header.start).toEqual({
      text: 'جامعة المثال',
      subtext: 'EE201 · الامتحان النهائي',
      image: { src: PNG, alt: 'Crest', heightMm: 13 },
    });
    expect(normalised.header.end.badge).toEqual({ label: 'النموذج', value: 'A' });
  });

  test('refuses a slot image that is not embedded', () => {
    const remote = doc([{ type: 'paragraph', text: 'x' }], { header: { start: { image: { src: 'https://example.com/logo.png' } } } });
    expect(validateFlowPayload(remote)).toMatch(/header\.start\.image\.src/);
    const script = doc([{ type: 'paragraph', text: 'x' }], { header: { start: { image: { src: 'data:text/html,<script>1</script>' } } } });
    expect(validateFlowPayload(script)).toMatch(/header\.start\.image\.src/);
  });

  test('refuses an image height outside what a margin band can hold', () => {
    const tall = doc([{ type: 'paragraph', text: 'x' }], { header: { start: { image: { src: PNG, heightMm: 60 } } } });
    expect(validateFlowPayload(tall)).toMatch(/heightMm/);
  });

  test('refuses an unknown slot name and an unknown slot key', () => {
    expect(validateFlowPayload(doc([{ type: 'paragraph', text: 'x' }], { header: { middle: 'x' } }))).toMatch(/header\.middle/);
    expect(validateFlowPayload(doc([{ type: 'paragraph', text: 'x' }], { footer: { start: { html: '<b>x</b>' } } }))).toMatch(/footer\.start\.html/);
  });
});

describe('facts', () => {
  test('renders each label and value, escaped and isolated', () => {
    const payload = doc([{ type: 'facts', items: [{ label: 'المدة', value: '120 دقيقة' }, { label: 'Total <marks>', value: '25' }] }], { direction: 'rtl' });
    expect(validateFlowPayload(payload)).toBeNull();
    const html = body(payload);
    expect(html).toContain('data-flow-unit="facts"');
    // Escaped, and on an Arabic page the Latin words are isolated as well.
    expect(html).toContain('<bdi>Total</bdi> &lt;<bdi>marks</bdi>&gt;');
    expect(html).not.toContain('<marks>');
    expect(html).toContain('<bdi>120</bdi> دقيقة');
  });

  test('refuses an empty or oversized list, and a non-string value', () => {
    expect(validateFlowPayload(doc([{ type: 'facts', items: [] }]))).toMatch(/items/);
    expect(validateFlowPayload(doc([{ type: 'facts', items: Array(7).fill({ label: 'a', value: 'b' }) }]))).toMatch(/items/);
    expect(validateFlowPayload(doc([{ type: 'facts', items: [{ label: 'a', value: 5 }] }]))).toMatch(/value/);
  });
});

describe('fields', () => {
  test('draws a writing line or a row of character boxes per field', () => {
    const payload = doc([
      {
        type: 'fields',
        title: 'Candidate',
        fields: [
          { label: 'Name', kind: 'line', span: 'full' },
          { label: 'Student number', kind: 'boxes', boxes: 8 },
          { label: 'Signature' },
        ],
      },
    ]);
    expect(validateFlowPayload(payload)).toBeNull();
    const html = body(payload);
    expect(html).toContain('data-flow-unit="fields"');
    expect(html).toContain('flow-field-full');
    expect((html.match(/class="flow-field-box"/g) || []).length).toBe(8);
    expect((html.match(/class="flow-field-line"/g) || []).length).toBe(2);
  });

  test('refuses an unknown kind and an absurd box count', () => {
    expect(validateFlowPayload(doc([{ type: 'fields', fields: [{ label: 'a', kind: 'dropdown' }] }]))).toMatch(/kind/);
    expect(validateFlowPayload(doc([{ type: 'fields', fields: [{ label: 'a', kind: 'boxes', boxes: 0 }] }]))).toMatch(/boxes/);
    expect(validateFlowPayload(doc([{ type: 'fields', fields: [{ label: 'a', kind: 'boxes', boxes: 41 }] }]))).toMatch(/boxes/);
    expect(validateFlowPayload(doc([{ type: 'fields', fields: [] }]))).toMatch(/fields/);
  });
});

describe('list', () => {
  test('renders an ordered list in a tinted panel', () => {
    const payload = doc([{ type: 'list', title: 'Instructions', tone: 'tint', ordered: true, items: ['One', 'Two <b>'] }]);
    expect(validateFlowPayload(payload)).toBeNull();
    const html = body(payload);
    expect(html).toContain('flow-panel-tint');
    expect(html).toContain('<ol');
    expect(html).toContain('Two &lt;b&gt;');
  });

  test('refuses an unknown tone and a non-string item', () => {
    expect(validateFlowPayload(doc([{ type: 'list', tone: 'loud', items: ['a'] }]))).toMatch(/tone/);
    expect(validateFlowPayload(doc([{ type: 'list', items: [1] }]))).toMatch(/items/);
  });
});

describe('question', () => {
  const choice = {
    type: 'question',
    number: '1',
    text: 'Two 10 Ω resistors in parallel give:',
    marks: '2 marks',
    choices: { columns: 2, items: [{ label: 'A', text: '5 Ω' }, { label: 'B', text: '10 Ω' }, { label: 'C', text: '20 Ω' }, { label: 'D', text: '100 Ω' }] },
  };

  test('renders the number, stem, marks and the choices in the asked number of columns', () => {
    expect(validateFlowPayload(doc([choice]))).toBeNull();
    const html = body(doc([choice]));
    expect(html).toContain('data-flow-unit="question"');
    expect(html).toContain('class="flow-question-number"');
    expect(html).toContain('class="flow-question-marks"');
    expect(html).toContain('flow-choices-2');
    expect((html.match(/class="flow-choice"/g) || []).length).toBe(4);
  });

  test('a choice with no label prints an empty bubble (true or false)', () => {
    const tf = { type: 'question', number: '3', text: 'x', choices: { columns: 2, items: [{ text: 'True' }, { text: 'False' }] } };
    expect(validateFlowPayload(doc([tf]))).toBeNull();
    expect(body(doc([tf]))).toContain('<span class="flow-choice-mark"></span>');
  });

  test('answer space: ruled lines, or a box with a unit', () => {
    const lines = { type: 'question', number: '6', text: 'x', answer: { kind: 'lines', lines: 5 } };
    expect(validateFlowPayload(doc([lines]))).toBeNull();
    expect((body(doc([lines])).match(/class="flow-answer-line"/g) || []).length).toBe(5);

    const box = { type: 'question', number: '4', text: 'x', answer: { kind: 'box', unit: 'W', widthMm: 60 } };
    expect(validateFlowPayload(doc([box]))).toBeNull();
    const html = body(doc([box]));
    expect(html).toContain('class="flow-answer-box"');
    expect(html).toContain('width:60mm');
  });

  test('isolates Latin runs inside an Arabic stem', () => {
    const ar = { type: 'question', number: '4', text: 'مصدر جهده 12 V يمرر 3 A', marks: '3 درجات' };
    const html = body(doc([ar], { direction: 'rtl' }));
    expect(html).toContain('<bdi');
  });

  test('refuses what cannot be printed sensibly', () => {
    expect(validateFlowPayload(doc([{ type: 'question', number: '1' }]))).toMatch(/text/);
    expect(validateFlowPayload(doc([{ ...choice, choices: { columns: 5, items: choice.choices.items } }]))).toMatch(/columns/);
    expect(validateFlowPayload(doc([{ ...choice, choices: { items: [] } }]))).toMatch(/choices\.items/);
    expect(validateFlowPayload(doc([{ type: 'question', text: 'x', answer: { kind: 'lines', lines: 0 } }]))).toMatch(/lines/);
    expect(validateFlowPayload(doc([{ type: 'question', text: 'x', answer: { kind: 'lines', lines: 41 } }]))).toMatch(/lines/);
    expect(validateFlowPayload(doc([{ type: 'question', text: 'x', answer: { kind: 'canvas' } }]))).toMatch(/answer\.kind/);
    expect(validateFlowPayload(doc([{ type: 'question', text: 'x', answer: { kind: 'box', widthMm: 500 } }]))).toMatch(/widthMm/);
  });

  test('is never split across a page: the paginator only splits paragraphs and tables', () => {
    const html = body(doc([choice]));
    expect(html).not.toContain('data-flow-unit="paragraph"');
  });
});

describe('divider', () => {
  test('renders a rule with optional words in the middle', () => {
    expect(validateFlowPayload(doc([{ type: 'divider', text: 'End of questions' }]))).toBeNull();
    expect(validateFlowPayload(doc([{ type: 'divider' }]))).toBeNull();
    expect(body(doc([{ type: 'divider', text: 'End' }]))).toContain('class="flow-divider"');
    expect(validateFlowPayload(doc([{ type: 'divider', text: 3 }]))).toMatch(/text/);
  });
});
