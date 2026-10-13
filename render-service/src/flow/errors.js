'use strict';

/**
 * A flowing document the paginator refused to ship (WHIT-674).
 *
 * The refusal itself is right and stays: a unit placed where it does not fit
 * makes every recorded page number after it a claim about a layout that did
 * not happen. What this adds is WHOSE fault it is.
 *
 *  - kind 'content': the document asked for something a page cannot hold - a
 *    block that is never split (a question, a list panel, a figure, a
 *    keep-together unit) taller than the content box, or content running past
 *    the box. The caller fixes it by shortening or splitting the block, so the
 *    server answers 422 with a sentence that says which and where.
 *  - anything else (kind 'renderer', or no kind at all): the paginator's own
 *    bookkeeping disagreed with itself - a front-matter loop that did not
 *    converge, an anchor with no recorded page. No edit to the document fixes
 *    that, so it stays a 500 and its words stay in the log.
 *
 * A problem with no kind is treated as the renderer's: guessing "your fault"
 * would hand a caller a reason they cannot act on.
 */
class FlowLayoutError extends Error {
  /**
   * @param {Array<{page: number, reason: string, kind?: string, unit?: string|null, anchor?: string|null}>} problems
   */
  constructor(problems) {
    const list = Array.isArray(problems) ? problems : [];
    const first = list[0] || { page: 0, reason: 'unknown' };
    const more = list.length > 1 ? ` (and ${list.length - 1} more)` : '';
    super(`The document cannot be laid out. ${describeProblem(first)}${more}`);
    this.name = 'FlowLayoutError';
    this.problems = list;
    this.contentOnly = list.length > 0 && list.every((p) => p && p.kind === 'content');
  }
}

/** The unit types as a person names them. */
const UNIT_WORDS = {
  paragraph: 'paragraph',
  heading: 'heading',
  table: 'table',
  figure: 'figure',
  qr: 'verification code',
  facts: 'facts row',
  fields: 'fields panel',
  list: 'list',
  question: 'question',
  divider: 'divider',
  atomic: 'block',
};

/** One problem as a sentence a person can act on. */
function describeProblem(problem) {
  const unit = UNIT_WORDS[problem.unit] || 'block';
  const article = /^[aeiou]/.test(unit) ? 'An' : 'A';
  if (/^unit taller than the content box/.test(problem.reason || '')) {
    return `${article} ${unit} on page ${problem.page} is taller than a page and cannot be split; shorten it or split it into smaller blocks.`;
  }
  if (/^content overruns the page box/.test(problem.reason || '')) {
    return `${article} ${unit} on page ${problem.page} runs past the bottom of the page (${problem.reason}).`;
  }
  return `Page ${problem.page}: ${problem.reason}.`;
}

module.exports = { FlowLayoutError, describeProblem };
