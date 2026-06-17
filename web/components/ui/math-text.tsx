'use client';

import 'katex/dist/katex.min.css';
import katex from 'katex';

export interface MathTextProps {
  /** LaTeX expression without surrounding $ signs */
  expression: string;
  /** Render as display (block) math; defaults to inline */
  block?: boolean;
  /** Color applied to render errors; defaults to 'inherit' */
  errorColor?: string;
}

/**
 * Renders a LaTeX math expression using KaTeX.
 * dangerouslySetInnerHTML is safe here: KaTeX's renderToString output is
 * sanitized and contains no user-supplied raw HTML.
 */
export function MathText({ expression, block = false, errorColor = 'inherit' }: MathTextProps) {
  const html = katex.renderToString(expression, {
    throwOnError: false,
    displayMode: block,
    errorColor,
  });

  return (
    <span
      className={block ? 'block' : undefined}
      dangerouslySetInnerHTML={{ __html: html }}
    />
  );
}
