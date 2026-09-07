/* eslint-disable */
/**
 * The paginator (#1072, first half). Runs INSIDE the page, before
 * `page.pdf()` is called.
 *
 * WHY THIS EXISTS AT ALL
 *
 * Chrome paginates a flowing document perfectly well on its own. What it does
 * not do is tell anyone where it put things: it implements no
 * `target-counter()`, and no DOM API reports which printed page an element
 * landed on. So a contents list of the form "Table 34 …… 78" cannot be
 * produced from a document Chrome paginated, because the 78 is not knowable
 * from inside the document.
 *
 * The two ways out are to render twice and recover the numbers from the first
 * pass, or to take pagination away from Chrome so the numbers are known by
 * construction. This file is the second. It measures every unit of content
 * once, packs the units into explicit page boxes itself, and therefore KNOWS
 * which page each anchor is on before a single byte of PDF exists. Chrome
 * then prints one PDF page per box, because each box is exactly the physical
 * page size and carries `break-after: page`.
 *
 * The measured comparison against the two-pass alternative — including how
 * wrong the usual `offsetTop / pageHeight` estimate turns out to be — is in
 * the pull request for #1072 and in docs/wiki/Document-Render-Service.md.
 *
 * WHAT IT GUARANTEES
 *
 *   - `window.__FLOW_RESULT__.pageCount` is the exact number of PDF pages.
 *   - `window.__FLOW_RESULT__.anchors[id]` is the 1-based page each numbered
 *     table, figure and heading is on, in the SAME numbering the front matter
 *     prints — because both are read from the same record.
 *   - `window.__FLOW_RESULT__.overflow` lists any page whose content box was
 *     overrun. It must be empty; a non-empty list means a unit was placed
 *     somewhere it does not fit and the page numbers after it are suspect.
 */
(function () {
  'use strict';

  var doc = window.__FLOW_DOC__;
  var bidi = window.__flowBidi;

  /* Vertical slack, in px, tolerated when deciding whether a unit fits.
   * Sub-pixel layout rounding routinely leaves a unit 0.02px over the line;
   * treating that as an overflow would move the last line of most pages. */
  var FIT_EPSILON = 0.75;

  /* Minimum space that must remain below a heading for it to stay on the
   * page: roughly two body lines. A heading alone at the foot of a page is
   * the single most obvious sign of a document nobody paginated on purpose. */
  var HEADING_KEEP_MM = 14;

  /* Orphan/widow control for split paragraphs, in lines. */
  var MIN_LINES = 2;

  /* The front-matter length depends on the page numbers it prints, and those
   * page numbers depend on the front-matter length. The loop converges in two
   * or three passes; this is the guard against a pathological oscillation
   * (a list that is exactly one line from a page boundary). */
  var MAX_FRONT_MATTER_PASSES = 8;

  var state = {
    pxPerMm: 96 / 25.4,
    contentWidthPx: 0,
    contentHeightPx: 0,
    anchorPage: Object.create(null),
    problems: [],
    frontMatterPasses: 0,
  };

  /* ---------------------------------------------------------------- utils */

  function mmToPx(value) {
    return value * state.pxPerMm;
  }

  function measurePxPerMm() {
    var probe = document.createElement('div');
    probe.style.cssText = 'position:absolute;visibility:hidden;width:100mm;height:0';
    document.body.appendChild(probe);
    var width = probe.getBoundingClientRect().width;
    probe.parentNode.removeChild(probe);
    return width > 0 ? width / 100 : 96 / 25.4;
  }

  function fillTokens(template, values) {
    return String(template === null || template === undefined ? '' : template).replace(
      /\{\{\s*([a-zA-Z]+)\s*\}\}/g,
      function (_all, key) {
        return values[key] === undefined || values[key] === null ? '' : String(values[key]);
      }
    );
  }

  /* --------------------------------------------------------- page factory */

  var pagesRoot = document.getElementById('flow-pages');

  function createPage() {
    var page = document.createElement('div');
    page.className = 'flow-page';
    var content = document.createElement('div');
    content.className = 'flow-page-content';
    page.appendChild(content);
    return { el: page, content: content };
  }

  /* --------------------------------------------------------- fragmenting */

  /**
   * Pack `units` (an ordered array of already-laid-out top-level elements)
   * into page boxes appended to `host`.
   *
   * Every element is MOVED, never cloned, so the measurement made in the
   * off-screen source column — which is exactly the content-box width — is the
   * measurement that applies once the element is in a page.
   *
   * @returns {Array} the page records created.
   */
  function paginate(units, host) {
    var pages = [];
    var current = null;
    var contentTop = 0;
    var limit = 0;

    function openPage() {
      current = createPage();
      host.appendChild(current.el);
      pages.push(current);
      contentTop = current.content.getBoundingClientRect().top;
      limit = contentTop + state.contentHeightPx;
    }

    function bottomOf(el) {
      return el.getBoundingClientRect().bottom;
    }

    /* A per-block layout hint (#1186), read off the unit the HTML emitted.
     * Attributes rather than CSS because THIS function decides pages: it
     * measures every unit and packs the boxes itself, so `break-inside: avoid`
     * is advice Chromium is never asked for. */
    function hint(el, name) {
      return !!(el && el.getAttribute && el.getAttribute(name) === '1');
    }

    openPage();

    var queue = units.slice();
    var index = 0;
    var guard = 0;
    var guardCeiling = queue.length * 40 + 20000;

    while (index < queue.length) {
      guard += 1;
      if (guard > guardCeiling) {
        // Something is not shrinking. Stop rather than hang the render: the
        // overflow report below turns this into a loud failure upstream.
        state.problems.push({ page: pages.length, reason: 'fragmentation did not converge' });
        break;
      }

      var node = queue[index];

      if (node.getAttribute && node.getAttribute('data-flow-unit') === 'pageBreak') {
        index += 1;
        if (current.content.firstChild) {
          openPage();
        }
        continue;
      }

      /* "Start this block on a new page." No `continue`: the page is opened
       * and the SAME unit is then placed on it. Opening a page that is already
       * empty would emit a blank one, so a block asking to start a page it is
       * already starting costs nothing. */
      if (hint(node, 'data-break-before') && current.content.firstChild) {
        openPage();
      }

      current.content.appendChild(node);
      var overflows = bottomOf(node) > limit + FIT_EPSILON;

      if (!overflows) {
        if (node.getAttribute && node.getAttribute('data-flow-unit') === 'heading') {
          var remaining = limit - bottomOf(node);
          var isLastUnit = index === queue.length - 1;
          if (!isLastUnit && remaining < mmToPx(HEADING_KEEP_MM) && current.content.firstChild !== node) {
            // A heading that fits but leaves no room for anything under it
            // belongs at the top of the next page, with its section.
            current.content.removeChild(node);
            openPage();
            continue;
          }
        }
        /* "Keep this with what follows." The heading rule above is the fixed
         * version of this — two body lines of room — and this is the authored
         * one: the NEXT unit is measured for real rather than approximated,
         * because "keep this heading with its table" is a promise about a
         * table, whose height nothing can guess.
         *
         * The unit is only moved when it is not alone on the page. A block that
         * is first and whose successor still will not fit cannot be helped by
         * another page break, and moving it would loop forever. */
        if (hint(node, 'data-keep-with-next') && index + 1 < queue.length) {
          var follower = queue[index + 1];
          var followerIsBreak =
            follower.getAttribute && follower.getAttribute('data-flow-unit') === 'pageBreak';
          if (!followerIsBreak) {
            current.content.appendChild(follower);
            var followerFits = bottomOf(follower) <= limit + FIT_EPSILON;
            current.content.removeChild(follower);
            if (!followerFits && current.content.firstChild !== node) {
              current.content.removeChild(node);
              openPage();
              continue;
            }
          }
        }

        index += 1;
        continue;
      }

      current.content.removeChild(node);

      var isFirstOnPage = !current.content.firstChild;
      var tail = null;

      /* "Do not let a page break fall inside this." The unit moves whole to the
       * next page instead of being split. If it is already alone on the page
       * and still does not fit, the block below places it anyway and records
       * the overflow — a promise that cannot be kept is reported rather than
       * quietly broken. */
      if (!hint(node, 'data-keep-together')) {
        if (node.getAttribute && node.getAttribute('data-flow-unit') === 'paragraph') {
          tail = splitParagraph(node, current.content, limit);
        } else if (node.getAttribute && node.getAttribute('data-flow-unit') === 'table') {
          tail = splitTable(node, current.content, limit);
        }
      }

      if (tail) {
        // `node` (the head) is now placed on the current page; continue with
        // the tail on a fresh page.
        queue[index] = tail;
        openPage();
        continue;
      }

      if (isFirstOnPage) {
        // Taller than a whole content box and not splittable: place it anyway
        // and record the overflow rather than looping forever. The caller
        // treats a non-empty overflow list as a failed render.
        current.content.appendChild(node);
        scaleFigureToFit(node, limit - contentTop);
        if (bottomOf(node) > limit + FIT_EPSILON) {
          state.problems.push({
            page: pages.length,
            reason: 'unit taller than the content box',
            anchor: node.getAttribute ? node.getAttribute('data-anchor') : null,
          });
        }
        index += 1;
        continue;
      }

      openPage();
    }

    return pages;
  }

  /**
   * Split a paragraph at a LINE boundary so that the head fits above `limit`.
   *
   * The head is left appended to `container`; the tail (a clone of the same
   * element carrying the remaining inline content) is returned, or null when
   * the paragraph cannot be split usefully — too few lines fit, or too few
   * would be left over, which is the orphan/widow rule.
   */
  function splitParagraph(node, container, limit) {
    container.appendChild(node);

    var probe = document.createRange();
    probe.selectNodeContents(node);
    var lines = Array.prototype.filter.call(probe.getClientRects(), function (r) {
      return r.height > 0.5;
    });

    if (lines.length < MIN_LINES * 2) {
      container.removeChild(node);
      return null;
    }

    var kept = 0;
    for (var i = 0; i < lines.length; i += 1) {
      if (lines[i].bottom <= limit + FIT_EPSILON) {
        kept = i + 1;
      } else {
        break;
      }
    }

    if (kept < MIN_LINES || lines.length - kept < MIN_LINES) {
      container.removeChild(node);
      return null;
    }

    var cut = findOffsetForLine(node, kept, limit);
    if (cut === null) {
      container.removeChild(node);
      return null;
    }

    var range = document.createRange();
    range.setStart(node, 0);
    range.setEnd(cut.node, cut.offset);
    var headContent = range.extractContents();

    var head = node.cloneNode(false);
    head.appendChild(headContent);
    head.setAttribute('data-flow-split', 'head');
    node.setAttribute('data-flow-split', 'tail');
    container.replaceChild(head, node);

    if (head.getBoundingClientRect().bottom > limit + FIT_EPSILON) {
      // The split did not actually help (it can't normally happen, but a
      // float or a tall inline could). Put the paragraph back whole.
      container.replaceChild(node, head);
      while (head.firstChild) {
        node.insertBefore(head.firstChild, node.firstChild);
      }
      node.removeAttribute('data-flow-split');
      container.removeChild(node);
      return null;
    }

    if (!node.textContent.replace(/\s+/g, '')) {
      return null;
    }
    return node;
  }

  /**
   * Find the (text node, offset) at which line `lineCount` ends.
   *
   * Binary search over the paragraph's flattened character offsets: the
   * bounding rectangle of the prefix range ends at the bottom of whatever
   * line the prefix currently reaches, so the largest prefix whose bottom is
   * still above `limit` ends on the last line that fits.
   */
  function findOffsetForLine(node, lineCount, limit) {
    var texts = [];
    var total = 0;
    var walker = document.createTreeWalker(node, NodeFilter.SHOW_TEXT, null);
    var textNode = walker.nextNode();
    while (textNode) {
      texts.push({ node: textNode, start: total, length: textNode.data.length });
      total += textNode.data.length;
      textNode = walker.nextNode();
    }
    if (total === 0) {
      return null;
    }

    function locate(globalOffset) {
      for (var i = 0; i < texts.length; i += 1) {
        if (globalOffset <= texts[i].start + texts[i].length) {
          return { node: texts[i].node, offset: globalOffset - texts[i].start };
        }
      }
      var last = texts[texts.length - 1];
      return { node: last.node, offset: last.length };
    }

    var range = document.createRange();
    function prefixBottom(globalOffset) {
      var at = locate(globalOffset);
      range.setStart(node, 0);
      range.setEnd(at.node, at.offset);
      var rect = range.getBoundingClientRect();
      return rect.height === 0 ? -Infinity : rect.bottom;
    }

    var low = 1;
    var high = total;
    var best = 0;
    while (low <= high) {
      var mid = (low + high) >> 1;
      if (prefixBottom(mid) <= limit + FIT_EPSILON) {
        best = mid;
        low = mid + 1;
      } else {
        high = mid - 1;
      }
    }
    if (best <= 0 || best >= total) {
      return null;
    }

    // Prefer a whitespace boundary so the break does not fall mid-word. Only
    // look back a short distance: a single unbroken token longer than that is
    // better broken than pushed to the next page.
    var flat = texts
      .map(function (t) {
        return t.node.data;
      })
      .join('');
    var cut = best;
    for (var back = 0; back < 40 && cut > 1; back += 1) {
      if (/\s/.test(flat.charAt(cut - 1))) {
        break;
      }
      cut -= 1;
    }
    if (cut <= 1) {
      cut = best;
    }

    return locate(cut);
  }

  /**
   * Split a table figure between rows, repeating the header row on the
   * continuation and marking its caption as continued. The anchor stays on
   * the head, which is where the front-matter entry should point.
   */
  function splitTable(node, container, limit) {
    container.appendChild(node);

    var table = node.querySelector('table');
    var tbody = table ? table.querySelector('tbody') : null;
    if (!tbody || tbody.rows.length < 2) {
      container.removeChild(node);
      return null;
    }

    var rows = Array.prototype.slice.call(tbody.rows);
    var kept = 0;
    for (var i = 0; i < rows.length; i += 1) {
      if (rows[i].getBoundingClientRect().bottom <= limit + FIT_EPSILON) {
        kept = i + 1;
      } else {
        break;
      }
    }

    if (kept < 1 || kept >= rows.length) {
      container.removeChild(node);
      return null;
    }

    var tail = node.cloneNode(false);
    tail.removeAttribute('data-anchor');
    tail.setAttribute('data-flow-split', 'tail');

    var caption = node.querySelector('figcaption');
    if (caption) {
      var contCaption = document.createElement('figcaption');
      contCaption.className = caption.className;
      var label = caption.querySelector('.flow-caption-label');
      contCaption.innerHTML =
        '<span class="flow-caption-label">' +
        (label ? label.innerHTML : '') +
        '</span> <span class="flow-caption-continued">' +
        bidi.escapeHtml(doc.labels.continued || '') +
        '</span>';
      tail.appendChild(contCaption);
    }

    var tailTable = document.createElement('table');
    var thead = table.querySelector('thead');
    if (thead) {
      tailTable.appendChild(thead.cloneNode(true));
    }
    var tailBody = document.createElement('tbody');
    for (var j = kept; j < rows.length; j += 1) {
      tailBody.appendChild(rows[j]);
    }
    tailTable.appendChild(tailBody);
    tail.appendChild(tailTable);

    if (node.getBoundingClientRect().bottom > limit + FIT_EPSILON) {
      // Removing the rows was not enough (an enormous caption, say). Undo.
      for (var k = 0; k < tailBody.rows.length; ) {
        tbody.appendChild(tailBody.rows[0]);
      }
      container.removeChild(node);
      return null;
    }

    return tail;
  }

  /** Shrink an oversized figure so it fits one content box, rather than
   * letting it be clipped by the page box. */
  function scaleFigureToFit(node, availablePx) {
    if (!node.getAttribute || node.getAttribute('data-flow-unit') !== 'figure') {
      return;
    }
    var frame = node.querySelector('.flow-figure-image');
    if (!frame) {
      return;
    }
    var excess = node.getBoundingClientRect().height - availablePx;
    if (excess <= 0) {
      return;
    }
    var frameHeight = frame.getBoundingClientRect().height;
    var target = Math.max(20, frameHeight - excess - 2);
    frame.style.height = target + 'px';
  }

  /* ----------------------------------------------------- cross-references */

  /** Record, for every anchored element now inside `pages`, which page it is
   * on. `offset` is how many pages precede this run. */
  function recordAnchors(pages, offset) {
    for (var i = 0; i < pages.length; i += 1) {
      var anchored = pages[i].el.querySelectorAll('[data-anchor]');
      for (var j = 0; j < anchored.length; j += 1) {
        var id = anchored[j].getAttribute('data-anchor');
        if (state.anchorPage[id] === undefined) {
          state.anchorPage[id] = offset + i + 1;
        }
      }
    }
  }

  function tocEntryHtml(entry, pageNumber, level) {
    var labelHtml = entry.label
      ? '<span class="flow-caption-label">' +
        bidi.isolateForeignRuns(entry.label, doc.direction) +
        '</span> ' +
        bidi.isolateForeignRuns(entry.text, doc.direction)
      : (entry.number ? '<bdi>' + bidi.escapeHtml(entry.number) + '</bdi> ' : '') +
        bidi.isolateForeignRuns(entry.text, doc.direction);

    return (
      '<div class="flow-toc-entry flow-toc-level-' +
      level +
      '" data-toc-for="' +
      bidi.escapeHtml(entry.anchorId) +
      '">' +
      '<span class="flow-toc-label">' +
      labelHtml +
      '</span>' +
      '<span class="flow-toc-leader"></span>' +
      '<span class="flow-toc-page"><bdi>' +
      bidi.escapeHtml(String(pageNumber)) +
      '</bdi></span>' +
      '</div>'
    );
  }

  /**
   * Build the front-matter units for a given assumed front-matter length.
   *
   * `offset` is how many pages the front matter itself occupies, which is
   * what turns a body page INDEX into the page number a reader will see.
   */
  function buildFrontMatterUnits(offset, host) {
    host.innerHTML = '';
    var units = [];

    for (var s = 0; s < doc.frontMatter.length; s += 1) {
      var section = doc.frontMatter[s];
      if (s > 0) {
        var brk = document.createElement('div');
        brk.className = 'flow-page-break';
        brk.setAttribute('data-flow-unit', 'pageBreak');
        host.appendChild(brk);
        units.push(brk);
      }

      var title = document.createElement('h1');
      title.className = 'flow-frontmatter-title';
      title.setAttribute('data-flow-unit', 'heading');
      title.setAttribute('data-front-matter-title', section.kind);
      title.innerHTML = bidi.isolateForeignRuns(section.title, doc.direction);
      host.appendChild(title);
      units.push(title);

      var entries =
        section.kind === 'contents'
          ? doc.index.headings.filter(function (h) {
              return h.level <= section.maxLevel;
            })
          : section.kind === 'tables'
            ? doc.index.tables
            : doc.index.figures;

      for (var e = 0; e < entries.length; e += 1) {
        var entry = entries[e];
        var pageNumber = (state.anchorPage[entry.anchorId] || 0) + offset;
        var wrapper = document.createElement('div');
        wrapper.setAttribute('data-flow-unit', 'paragraph');
        wrapper.className = 'flow-toc-wrapper';
        wrapper.innerHTML = tocEntryHtml(entry, pageNumber, entry.level || 1);
        // The wrapper would add a level of nesting the paginator does not
        // need; hoist the entry itself.
        var node = wrapper.firstChild;
        node.setAttribute('data-flow-unit', 'atomic');
        host.appendChild(node);
        units.push(node);
      }
    }

    return units;
  }

  /* --------------------------------------------------- running head/foot */

  function sectionForPage(pageNumber, sectionMarks, fallback) {
    var current = fallback;
    for (var i = 0; i < sectionMarks.length; i += 1) {
      if (sectionMarks[i].page <= pageNumber) {
        current = sectionMarks[i];
      } else {
        break;
      }
    }
    return current;
  }

  function runningBand(spec, className, values) {
    var band = document.createElement('div');
    band.className = 'flow-running ' + className;
    var slots = ['start', 'center', 'end'];
    var any = false;
    for (var i = 0; i < slots.length; i += 1) {
      var slot = document.createElement('div');
      slot.className = 'flow-running-slot flow-running-slot-' + slots[i];
      var template = spec ? spec[slots[i]] : '';
      var text = fillTokens(template, values);
      if (text) {
        any = true;
      }
      slot.innerHTML = bidi.isolateForeignRuns(text, doc.direction);
      band.appendChild(slot);
    }
    if (!any) {
      band.className += ' flow-running-empty';
    }
    return { el: band, any: any };
  }

  function applyRunningFurniture(pages, sectionMarks) {
    var total = pages.length;
    for (var i = 0; i < total; i += 1) {
      var pageNumber = i + 1;
      var section = sectionForPage(pageNumber, sectionMarks, null);
      var values = {
        page: pageNumber,
        pages: total,
        title: doc.title || '',
        section: section ? (section.number ? section.number + ' ' + section.text : section.text) : '',
        sectionNumber: section ? section.number || '' : '',
        sectionTitle: section ? section.text : '',
      };
      var header = runningBand(doc.header, 'flow-running-header', values);
      var footer = runningBand(doc.footer, 'flow-running-footer', values);
      if (header.any) {
        pages[i].el.appendChild(header.el);
      }
      if (footer.any) {
        pages[i].el.appendChild(footer.el);
      }
    }
  }

  /* ------------------------------------------------------------- verify */

  /** Independent of the packing decisions: re-measure every placed page and
   * confirm nothing sticks out of its content box. */
  function verifyPages(pages) {
    for (var i = 0; i < pages.length; i += 1) {
      var content = pages[i].content;
      var box = content.getBoundingClientRect();
      var children = content.children;
      for (var j = 0; j < children.length; j += 1) {
        var rect = children[j].getBoundingClientRect();
        if (rect.bottom > box.bottom + FIT_EPSILON + 1) {
          state.problems.push({
            page: i + 1,
            reason: 'content overruns the page box by ' + Math.round(rect.bottom - box.bottom) + 'px',
            anchor: children[j].getAttribute ? children[j].getAttribute('data-anchor') : null,
          });
        }
      }
    }
  }

  /**
   * Every printed front-matter number must equal the page recorded for the
   * same anchor.
   *
   * The two are produced at DIFFERENT times: the number is printed while the
   * front matter is being built, against whatever front-matter length that
   * pass assumed, and the recorded page is fixed afterwards, once the length
   * is known. A generated list changes the page numbers it prints, because the
   * list itself occupies pages — so if that loop ever failed to converge, the
   * list would be printed against a length the document does not have and
   * every number in it would be stale by the difference. That is the failure
   * mode of every implementation of this, it is invisible in a small fixture
   * (where the list is one page and the numbers happen to survive), and
   * nothing about the output looks wrong.
   *
   * This is the cheap check that the two agree. It is not a substitute for
   * reading the numbers back out of the finished PDF — that is what
   * scripts/verify-flow-pdf.js does, and it is the only check that does not
   * take this file's word for anything — but it catches a stale list before a
   * PDF is ever produced.
   */
  function verifyPrintedNumbers(pages) {
    var checked = 0;
    for (var i = 0; i < pages.length; i += 1) {
      var entries = pages[i].el.querySelectorAll('.flow-toc-entry[data-toc-for]');
      for (var j = 0; j < entries.length; j += 1) {
        var anchorId = entries[j].getAttribute('data-toc-for');
        var slot = entries[j].querySelector('.flow-toc-page');
        var printed = slot ? parseInt(slot.textContent.replace(/[^0-9]/g, ''), 10) : NaN;
        var recorded = state.anchorPage[anchorId];
        checked += 1;
        if (!(recorded > 0)) {
          state.problems.push({
            page: i + 1,
            reason: 'front-matter entry points at an anchor with no recorded page',
            anchor: anchorId,
          });
        } else if (printed !== recorded) {
          state.problems.push({
            page: i + 1,
            reason: 'front-matter entry prints page ' + printed + ' but the anchor is recorded on ' + recorded,
            anchor: anchorId,
          });
        }
      }
    }
    return checked;
  }

  /* --------------------------------------------------------------- main */

  function run() {
    var started = Date.now();
    state.pxPerMm = measurePxPerMm();

    var contentWidthMm = doc.page.widthMm - doc.page.margin.left - doc.page.margin.right;
    var contentHeightMm = doc.page.heightMm - doc.page.margin.top - doc.page.margin.bottom;
    state.contentWidthPx = mmToPx(contentWidthMm);
    state.contentHeightPx = mmToPx(contentHeightMm);

    var root = document.documentElement;
    root.style.setProperty('--flow-content-width', contentWidthMm + 'mm');
    root.style.setProperty('--flow-content-height', contentHeightMm + 'mm');
    root.style.setProperty(
      '--flow-margin-inline-start',
      (doc.direction === 'rtl' ? doc.page.margin.right : doc.page.margin.left) + 'mm'
    );

    var source = document.getElementById('flow-source');
    var bodyUnits = Array.prototype.slice.call(source.children);

    // Pass 1: the body. Its internal pagination does not depend on the front
    // matter at all, so it is computed once and never redone.
    var bodyHost = document.createElement('div');
    bodyHost.id = 'flow-body-pages';
    pagesRoot.appendChild(bodyHost);
    var bodyPages = paginate(bodyUnits, bodyHost);
    recordAnchors(bodyPages, 0);
    source.className = 'flow-consumed';

    // Pass 2..n: the front matter, whose LENGTH changes the page numbers it
    // prints, which can change its length. Iterate to a fixed point.
    var frontHost = document.createElement('div');
    frontHost.id = 'flow-front-pages';
    pagesRoot.insertBefore(frontHost, bodyHost);

    var frontSource = document.createElement('div');
    frontSource.id = 'flow-front-source';
    frontSource.style.cssText =
      'position:absolute;top:0;left:-100000px;visibility:hidden;width:' + contentWidthMm + 'mm';
    document.body.appendChild(frontSource);

    var frontPages = [];
    var offset = 0;
    if (doc.frontMatter.length > 0) {
      for (var pass = 0; pass < MAX_FRONT_MATTER_PASSES; pass += 1) {
        state.frontMatterPasses = pass + 1;
        frontHost.innerHTML = '';
        var units = buildFrontMatterUnits(offset, frontSource);
        frontPages = paginate(units, frontHost);
        if (frontPages.length === offset) {
          break;
        }
        offset = frontPages.length;
      }
      // One final build at the converged offset, so the printed numbers match
      // the length that was actually produced even if the loop hit its
      // ceiling on an oscillating document.
      if (frontPages.length !== offset) {
        frontHost.innerHTML = '';
        var finalUnits = buildFrontMatterUnits(frontPages.length, frontSource);
        frontPages = paginate(finalUnits, frontHost);
        offset = frontPages.length;
      }
    }
    frontSource.parentNode.removeChild(frontSource);

    // The body's anchors were recorded before the front matter existed; shift
    // them by the front matter's final length so every recorded page number
    // is a real, 1-based page of the finished document.
    var shifted = Object.create(null);
    for (var id in state.anchorPage) {
      shifted[id] = state.anchorPage[id] + offset;
    }
    state.anchorPage = shifted;

    var allPages = frontPages.concat(bodyPages);

    // Running heads name the section a page belongs to, which is the last
    // level-1 heading at or before it.
    var sectionMarks = [];
    for (var h = 0; h < doc.index.headings.length; h += 1) {
      var heading = doc.index.headings[h];
      if (heading.level === 1 && state.anchorPage[heading.anchorId]) {
        sectionMarks.push({
          page: state.anchorPage[heading.anchorId],
          number: heading.number,
          text: heading.text,
        });
      }
    }
    applyRunningFurniture(allPages, sectionMarks);

    verifyPages(allPages);
    var entriesChecked = verifyPrintedNumbers(allPages);

    window.__FLOW_RESULT__ = {
      frontMatterEntriesChecked: entriesChecked,
      pageCount: allPages.length,
      frontMatterPages: frontPages.length,
      bodyPages: bodyPages.length,
      frontMatterPasses: state.frontMatterPasses,
      anchors: state.anchorPage,
      problems: state.problems,
      paginateMs: Date.now() - started,
    };
    window.__FLOW_READY__ = true;
  }

  /** Fonts and images must have settled before anything is measured: a
   * paragraph measured in a fallback font wraps differently, and an <img>
   * with no intrinsic size yet measures as zero. */
  function whenSettled(callback) {
    var images = Array.prototype.slice.call(document.querySelectorAll('#flow-source img'));
    var pending = images.filter(function (img) {
      return !img.complete;
    });
    var waits = [document.fonts ? document.fonts.ready : Promise.resolve()];
    for (var i = 0; i < pending.length; i += 1) {
      waits.push(
        new Promise(function (resolve) {
          var img = pending[i];
          img.addEventListener('load', resolve, { once: true });
          img.addEventListener('error', resolve, { once: true });
        })
      );
    }
    Promise.all(waits).then(function () {
      requestAnimationFrame(function () {
        requestAnimationFrame(callback);
      });
    });
  }

  whenSettled(function () {
    try {
      run();
    } catch (err) {
      window.__FLOW_ERROR__ = String((err && err.stack) || err);
      window.__FLOW_READY__ = true;
    }
  });
})();
