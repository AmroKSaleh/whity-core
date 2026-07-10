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
 *
 * dangerouslySetInnerHTML is safe here: KaTeX's renderToString escapes text
 * content and, with `trust` disabled (the default, set explicitly below),
 * refuses the only commands that can emit markup/URLs (\href, \url,
 * \includegraphics). So even a hostile `expression` cannot inject executable
 * HTML — KaTeX's own XSS model covers it, and no user-supplied raw HTML is ever
 * passed through.
 */
export function MathText({ expression, block = false, errorColor = 'inherit' }: MathTextProps) {
  const html = katex.renderToString(expression, {
    throwOnError: false,
    displayMode: block,
    errorColor,
    // Explicit (KaTeX default): keep HTML/URL-emitting commands disabled so the
    // output stays XSS-safe. Do not enable without a sanitization pass.
    trust: false,
  });

  return (
    <span
      className={block ? 'block' : undefined}
      // KaTeX output with trust:false is XSS-safe to inject (see the doc comment).
      // nosemgrep: typescript.react.security.audit.react-dangerouslysetinnerhtml.react-dangerouslysetinnerhtml
      dangerouslySetInnerHTML={{ __html: html }}
    />
  );
}
