'use strict';

/**
 * A flowing document that cannot be laid out (WHIT-674).
 *
 * The paginator already REFUSES such a document - a unit taller than a page,
 * content overrunning a page box - and it must keep refusing: shipping it
 * would put recorded page numbers on a layout that did not happen. What was
 * wrong is how the refusal reached the caller: a bare 500 "Render failed",
 * for input that passed validation and that the caller can fix.
 *
 * So the refusal now carries its problems, and says whether every one of them
 * is the CONTENT's (the caller's to fix: 422 with a sentence) or whether any
 * is the renderer's own (a front-matter loop that did not converge, an anchor
 * with no page: still 500, because no edit to the document fixes those).
 */

const { FlowLayoutError, describeProblem } = require('../src/flow/errors');

describe('FlowLayoutError', () => {
  test('a unit taller than the page is the content: the caller can shorten or split it', () => {
    const err = new FlowLayoutError([{ page: 3, reason: 'unit taller than the content box', kind: 'content', unit: 'question', anchor: null }]);
    expect(err.contentOnly).toBe(true);
    expect(err.message).toMatch(/question/);
    expect(err.message).toMatch(/page 3/);
    expect(err.message).toMatch(/taller than a page/);
  });

  test('a renderer-side problem anywhere keeps the whole refusal the renderer’s', () => {
    const err = new FlowLayoutError([
      { page: 3, reason: 'unit taller than the content box', kind: 'content', unit: 'table' },
      { page: 1, reason: 'front-matter entry prints page 17 but the anchor is recorded on 11', kind: 'renderer' },
    ]);
    expect(err.contentOnly).toBe(false);
  });

  test('a problem with no kind is never assumed to be the caller’s', () => {
    expect(new FlowLayoutError([{ page: 2, reason: 'something' }]).contentOnly).toBe(false);
  });

  test('the message counts the rest, and the problems ride along for the response', () => {
    const problems = [
      { page: 4, reason: 'unit taller than the content box', kind: 'content', unit: 'list' },
      { page: 9, reason: 'content overruns the page box by 30px', kind: 'content', unit: 'fields' },
    ];
    const err = new FlowLayoutError(problems);
    expect(err.message).toMatch(/and 1 more/);
    expect(err.problems).toEqual(problems);
  });

  test('each problem has a sentence a person can act on', () => {
    expect(describeProblem({ page: 2, reason: 'unit taller than the content box', kind: 'content', unit: 'figure' })).toBe(
      'A figure on page 2 is taller than a page and cannot be split; shorten it or split it into smaller blocks.'
    );
    expect(describeProblem({ page: 5, reason: 'content overruns the page box by 12px', kind: 'content', unit: 'paragraph' })).toBe(
      'A paragraph on page 5 runs past the bottom of the page (content overruns the page box by 12px).'
    );
  });
});
