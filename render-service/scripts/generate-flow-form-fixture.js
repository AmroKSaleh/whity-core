#!/usr/bin/env node
'use strict';

/**
 * A printed FORM in the flowing mode (WHIT-673): a themed document whose
 * running head carries an embedded image, two lines of text and a boxed label,
 * and whose body uses every form block - facts, fields, a tinted list, a marks
 * table, questions with choices in columns, an answer box, ruled answer lines,
 * and a divider.
 *
 * The Jest suite proves the markup; only a real Chromium can prove the result
 * paginates. Forty questions are more than one page holds, so the document has
 * to break between them - and since a question is never split, a render that
 * returns at all has placed every one of them whole (a unit that could not be
 * placed is reported as a problem and fails the render).
 *
 *   node generate-flow-form-fixture.js --direction rtl --out form-rtl.json
 *   node generate-flow-form-fixture.js --direction ltr --out form-ltr.json
 */

const fs = require('node:fs');

function parseArgs(argv) {
  const args = { direction: 'rtl', out: null };
  for (let i = 2; i < argv.length; i += 1) {
    if (argv[i] === '--direction') args.direction = argv[i + 1];
    if (argv[i] === '--out') args.out = argv[i + 1];
  }
  return args;
}

/** A 1x1 PNG: an embedded image the running head can carry. */
const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

const WORDS = {
  rtl: {
    uni: 'جامعة المثال',
    sub: 'EE201 · الامتحان النهائي',
    form: 'النموذج',
    facts: [['التاريخ', '14 Jan 2027'], ['المدة', '120 دقيقة'], ['الدرجة الكلية', '80'], ['عدد الأسئلة', '40']],
    who: 'بيانات الطالب',
    name: 'اسم الطالب',
    number: 'الرقم الجامعي',
    section: 'الشعبة',
    rules: 'تعليمات',
    rule: 'أجب عن جميع الأسئلة.',
    stem: (n) => `السؤال رقم ${n}: مقاومتان قيمة كل منهما 10 Ω على التوازي، ما مقاومتهما المكافئة؟`,
    marks: '2 درجتان',
    labels: ['أ', 'ب', 'ج', 'د'],
    end: 'انتهت الأسئلة',
    page: 'صفحة {{page}} من {{pages}}',
  },
  ltr: {
    uni: 'University of Example',
    sub: 'EE201 · Final examination',
    form: 'Form',
    facts: [['Date', '14 Jan 2027'], ['Duration', '120 minutes'], ['Total marks', '80'], ['Questions', '40']],
    who: 'Candidate',
    name: 'Name',
    number: 'Student number',
    section: 'Section',
    rules: 'Instructions',
    rule: 'Answer every question.',
    stem: (n) => `Question ${n}: two 10 Ω resistors in parallel give what equivalent resistance?`,
    marks: '2 marks',
    labels: ['A', 'B', 'C', 'D'],
    end: 'End of questions',
    page: 'Page {{page}} of {{pages}}',
  },
};

function build(direction) {
  const w = WORDS[direction];
  const content = [
    { type: 'facts', items: w.facts.map(([label, value]) => ({ label, value })) },
    {
      type: 'fields',
      title: w.who,
      fields: [
        { label: w.name, kind: 'line', span: 'full' },
        { label: w.number, kind: 'boxes', boxes: 8 },
        { label: w.section },
      ],
    },
    { type: 'list', title: w.rules, tone: 'tint', ordered: true, items: [w.rule, w.rule, w.rule] },
    { type: 'table', columns: ['1', '2', '3', '4', '5'], rows: [['2', '2', '2', '2', '2'], ['', '', '', '', '']] },
    { type: 'pageBreak' },
  ];
  for (let n = 1; n <= 40; n += 1) {
    if (n % 10 === 0) {
      content.push({ type: 'question', number: String(n), text: w.stem(n), marks: w.marks, answer: { kind: 'lines', lines: 4 } });
    } else if (n % 10 === 5) {
      content.push({ type: 'question', number: String(n), text: w.stem(n), marks: w.marks, answer: { kind: 'box', unit: 'Ω', widthMm: 50 } });
    } else {
      content.push({
        type: 'question',
        number: String(n),
        text: w.stem(n),
        marks: w.marks,
        choices: { columns: 2, items: w.labels.map((label, i) => ({ label, text: `${(i + 1) * 5} Ω` })) },
      });
    }
  }
  content.push({ type: 'divider', text: w.end });

  return {
    direction,
    title: w.sub,
    page: { preset: 'a4', margin: { topMm: 34, rightMm: 16, bottomMm: 20, leftMm: 16 } },
    // A document theme sets only the print colour and where it goes (E.44, E.45).
    theme: { accent: '#0c95d4', accentUse: 'title-and-headings' },
    header: {
      start: { image: { src: PNG, alt: '', heightMm: 12 }, text: w.uni, subtext: w.sub },
      end: { badge: { label: w.form, value: 'A' } },
    },
    footer: { start: 'EE201-F-1 · A', center: w.page },
    content,
  };
}

const args = parseArgs(process.argv);
const json = JSON.stringify(build(args.direction === 'ltr' ? 'ltr' : 'rtl'));
if (args.out) {
  fs.writeFileSync(args.out, json);
} else {
  process.stdout.write(json);
}
