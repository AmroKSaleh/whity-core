/**
 * WC-532 A5: a dependency-free, XSS-safe Markdown renderer for the plugin
 * block DSL (the `markdown` display block and the `richTextInput` live preview).
 *
 * Safety model: it emits REACT ELEMENTS, never HTML strings — there is no
 * `dangerouslySetInnerHTML` anywhere, so React escapes every text node and a
 * parser bug can at worst mis-format, never inject script. Only a fixed
 * whitelist of elements is produced (headings, p, strong, em, code, pre, ul/ol,
 * li, a, br) and link hrefs are sanitized to http(s)/mailto/internal-relative.
 * Inline `$…$` renders through the KaTeX `MathText` atom (itself trust:false).
 *
 * Supported subset (intentionally small): ATX headings (# … ######), fenced
 * code blocks (``` … ```), unordered (-, *) and ordered (1.) lists, bold
 * (**…**), italic (*…* / _…_), inline code (`…`), links [text](url), inline
 * math $…$, blank-line-separated paragraphs with single-newline <br>.
 */

import * as React from 'react';
import { MathText } from '@amroksaleh/ui/math-text';

/** Allow only non-javascript, non-data hrefs. Everything else is dropped to text. */
function safeHref(url: string): string | null {
  const u = url.trim();
  if (u.startsWith('/') && !u.startsWith('//')) return u; // internal relative
  if (/^https?:\/\//i.test(u)) return u;
  if (/^mailto:/i.test(u)) return u;
  return null;
}

/**
 * Parse inline markdown into React nodes. Scans for the earliest of the
 * inline constructs and recurses on the remainder; unmatched text is emitted
 * verbatim (React escapes it).
 */
function parseInline(text: string, keyPrefix: string): React.ReactNode[] {
  const nodes: React.ReactNode[] = [];
  let rest = text;
  let k = 0;

  // Each matcher returns [before, element, after] or null.
  const matchers: Array<(s: string) => [string, React.ReactNode, string] | null> = [
    // inline math $…$ (non-greedy, no newline)
    (s) => {
      const m = s.match(/\$([^$\n]+?)\$/);
      if (!m || m.index === undefined) return null;
      return [s.slice(0, m.index), <MathText key={`${keyPrefix}-${k}`} expression={m[1]} />, s.slice(m.index + m[0].length)];
    },
    // inline code `…`
    (s) => {
      const m = s.match(/`([^`\n]+?)`/);
      if (!m || m.index === undefined) return null;
      return [s.slice(0, m.index), <code key={`${keyPrefix}-${k}`} className="rounded bg-muted px-1 py-0.5 text-[0.85em]">{m[1]}</code>, s.slice(m.index + m[0].length)];
    },
    // bold **…**
    (s) => {
      const m = s.match(/\*\*([^\n]+?)\*\*/);
      if (!m || m.index === undefined) return null;
      return [s.slice(0, m.index), <strong key={`${keyPrefix}-${k}`}>{parseInline(m[1], `${keyPrefix}-${k}b`)}</strong>, s.slice(m.index + m[0].length)];
    },
    // link [text](url)
    (s) => {
      const m = s.match(/\[([^\]\n]+?)\]\(([^)\s]+?)\)/);
      if (!m || m.index === undefined) return null;
      const href = safeHref(m[2]);
      const el = href
        ? <a key={`${keyPrefix}-${k}`} href={href} className="text-primary underline" {...(href.startsWith('/') ? {} : { rel: 'noopener noreferrer', target: '_blank' })}>{m[1]}</a>
        : <React.Fragment key={`${keyPrefix}-${k}`}>{m[1]}</React.Fragment>;
      return [s.slice(0, m.index), el, s.slice(m.index + m[0].length)];
    },
    // italic *…* or _…_
    (s) => {
      const m = s.match(/(?:\*([^*\n]+?)\*|_([^_\n]+?)_)/);
      if (!m || m.index === undefined) return null;
      const inner = m[1] ?? m[2] ?? '';
      return [s.slice(0, m.index), <em key={`${keyPrefix}-${k}`}>{parseInline(inner, `${keyPrefix}-${k}i`)}</em>, s.slice(m.index + m[0].length)];
    },
  ];

  // Repeatedly find the earliest match across all matchers.
  // Guard against pathological input with a bounded loop.
  for (let guard = 0; guard < 5000 && rest !== ''; guard++) {
    let best: { before: string; el: React.ReactNode; after: string } | null = null;
    for (const matcher of matchers) {
      const r = matcher(rest);
      if (r && (best === null || r[0].length < best.before.length)) {
        best = { before: r[0], el: r[1], after: r[2] };
      }
    }
    if (best === null) {
      nodes.push(rest);
      break;
    }
    if (best.before !== '') nodes.push(best.before);
    nodes.push(best.el);
    rest = best.after;
    k++;
  }
  return nodes;
}

/** Render a paragraph's lines, joining single newlines with <br>. */
function renderParagraph(lines: string[], key: string): React.ReactNode {
  const parts: React.ReactNode[] = [];
  lines.forEach((line, i) => {
    if (i > 0) parts.push(<br key={`${key}-br-${i}`} />);
    parts.push(...parseInline(line, `${key}-l${i}`));
  });
  return <p key={key} className="leading-relaxed">{parts}</p>;
}

/**
 * Render markdown source into safe React nodes (block-level). No HTML strings
 * are ever produced.
 */
export function renderMarkdown(src: string): React.ReactNode {
  const lines = src.replace(/\r\n/g, '\n').split('\n');
  const blocks: React.ReactNode[] = [];
  let i = 0;
  let key = 0;

  while (i < lines.length) {
    const line = lines[i];

    // blank line → skip
    if (line.trim() === '') { i++; continue; }

    // fenced code block
    if (line.trimStart().startsWith('```')) {
      const body: string[] = [];
      i++;
      while (i < lines.length && !lines[i].trimStart().startsWith('```')) { body.push(lines[i]); i++; }
      if (i < lines.length) i++; // consume closing fence
      blocks.push(
        <pre key={`b${key++}`} className="overflow-x-auto rounded-md bg-muted p-3 text-sm"><code>{body.join('\n')}</code></pre>
      );
      continue;
    }

    // ATX heading
    const h = line.match(/^(#{1,6})\s+(.*)$/);
    if (h) {
      const level = h[1].length;
      const Tag = (`h${level}` as 'h1' | 'h2' | 'h3' | 'h4' | 'h5' | 'h6');
      const sizes = ['text-2xl', 'text-xl', 'text-lg', 'text-base', 'text-sm', 'text-sm'];
      blocks.push(<Tag key={`b${key++}`} className={`font-heading font-semibold ${sizes[level - 1]}`}>{parseInline(h[2], `b${key}`)}</Tag>);
      i++;
      continue;
    }

    // unordered list
    if (/^\s*[-*]\s+/.test(line)) {
      const items: string[] = [];
      while (i < lines.length && /^\s*[-*]\s+/.test(lines[i])) { items.push(lines[i].replace(/^\s*[-*]\s+/, '')); i++; }
      blocks.push(<ul key={`b${key++}`} className="list-disc space-y-1 ps-6">{items.map((it, j) => <li key={j}>{parseInline(it, `b${key}-${j}`)}</li>)}</ul>);
      continue;
    }

    // ordered list
    if (/^\s*\d+\.\s+/.test(line)) {
      const items: string[] = [];
      while (i < lines.length && /^\s*\d+\.\s+/.test(lines[i])) { items.push(lines[i].replace(/^\s*\d+\.\s+/, '')); i++; }
      blocks.push(<ol key={`b${key++}`} className="list-decimal space-y-1 ps-6">{items.map((it, j) => <li key={j}>{parseInline(it, `b${key}-${j}`)}</li>)}</ol>);
      continue;
    }

    // paragraph: gather consecutive non-blank, non-special lines
    const para: string[] = [];
    while (
      i < lines.length &&
      lines[i].trim() !== '' &&
      !lines[i].trimStart().startsWith('```') &&
      !/^(#{1,6})\s+/.test(lines[i]) &&
      !/^\s*[-*]\s+/.test(lines[i]) &&
      !/^\s*\d+\.\s+/.test(lines[i])
    ) { para.push(lines[i]); i++; }
    blocks.push(renderParagraph(para, `b${key++}`));
  }

  return <div className="space-y-2" data-slot="safe-markdown">{blocks}</div>;
}
