'use client';

import type { CSSProperties } from 'react';
import type { DocElement, TextRun, TextStyle } from '@/lib/documents/types';
import { interpolate, resolveBound } from '@/lib/documents/storage';
import { BarcodeSvg } from './barcode-svg';
import { MathText } from '@amroksaleh/ui/math-text';

/**
 * Renders the visual CONTENT of one element, filling its (already positioned)
 * box. `data` is the sample/bound data map; `preview` controls whether dynamic
 * text is interpolated (preview) or shown as raw `{{tokens}}` (edit, clearer for
 * authoring). Barcodes/QR/images always resolve against the data so the canvas
 * shows a realistic render while editing.
 */
export function ElementContent({
  el,
  data,
  preview,
}: {
  el: DocElement;
  data: Record<string, string>;
  preview: boolean;
}) {
  switch (el.type) {
    case 'text':
      return (
        <TextBox style={el.style}>
          <RichText plain={el.text} runs={el.runs} />
        </TextBox>
      );
    case 'dynamicText': {
      const runs = el.runs && (preview ? el.runs.map((r) => ({ ...r, text: interpolate(r.text, data) })) : el.runs);
      return (
        <TextBox style={el.style}>
          <RichText plain={preview ? interpolate(el.template, data) : el.template} runs={runs || undefined} />
        </TextBox>
      );
    }
    case 'math':
      return (
        <div className="flex h-full w-full items-center justify-center overflow-hidden">
          <MathText expression={el.expression} block={el.block} />
        </div>
      );
    case 'image': {
      // Only render absolute http(s) image URLs — parse-and-check the protocol so
      // javascript:/data: (incl. script-carrying SVG data-URIs) can't reach the
      // <img>. (Uploaded/data-URI logos await the backend image-upload endpoint.)
      const src = safeImageSrc(resolveBound(el.binding, el.src, data));
      if (src === '') {
        return (
          <div className="flex h-full w-full items-center justify-center rounded-sm border border-dashed border-border bg-muted/30 text-[8px] text-muted-foreground">
            {el.binding ? `{{${el.binding}}}` : 'image'}
          </div>
        );
      }
      // eslint-disable-next-line @next/next/no-img-element -- design canvas renders arbitrary/data-URI images; next/image is inappropriate here.
      return <img src={src} alt="" className="h-full w-full" style={{ objectFit: el.fit }} draggable={false} />;
    }
    case 'barcode':
      return (
        <BarcodeSvg
          symbology={el.symbology}
          value={el.binding ? resolveBound(el.binding, el.value, data) : interpolate(el.value, data)}
          showText={el.showText}
        />
      );
    case 'qr':
      return (
        <BarcodeSvg
          symbology="qrcode"
          value={el.binding ? resolveBound(el.binding, el.value, data) : interpolate(el.value, data)}
          eclevel={el.eclevel}
        />
      );
    case 'rect':
      return (
        <div
          className="h-full w-full"
          style={{
            background: el.fill,
            border: el.strokeWidth > 0 ? `${el.strokeWidth}mm solid ${el.stroke}` : undefined,
            borderRadius: `${el.radius}mm`,
          }}
        />
      );
    case 'line':
      return <div className="h-full w-full" style={{ background: el.stroke }} />;
    case 'blockInstance':
      // Block instances are resolved to their sub-elements by the canvas/print
      // renderer, not here; nothing to draw as a leaf.
      return null;
    default: {
      const _exhaustive: never = el;
      return <>{String(_exhaustive)}</>;
    }
  }
}

/** Allow only absolute http(s) image URLs into the <img> src (XSS-safe sink). */
function safeImageSrc(raw: string): string {
  try {
    const u = new URL(raw);
    return u.protocol === 'http:' || u.protocol === 'https:' ? u.href : '';
  } catch {
    return '';
  }
}

function TextBox({ style, children }: { style: TextStyle; children: React.ReactNode }) {
  const dir = style.direction ?? 'auto';
  const css: CSSProperties = {
    fontSize: `${style.fontSize}pt`,
    fontWeight: style.fontWeight,
    fontStyle: style.fontStyle,
    textAlign: style.align,
    color: style.color,
    display: 'flex',
    flexDirection: 'column',
    justifyContent: style.vAlign === 'top' ? 'flex-start' : style.vAlign === 'middle' ? 'center' : 'flex-end',
    lineHeight: style.lineHeight ?? 1.2,
    letterSpacing: style.letterSpacing ? `${style.letterSpacing}pt` : undefined,
    whiteSpace: 'pre-wrap',
    overflow: 'hidden',
    wordBreak: 'break-word',
    height: '100%',
    width: '100%',
    // 'auto' relies on the dir attribute for per-paragraph inference (Arabic /
    // mixed content); explicit ltr/rtl set the base direction.
    ...(dir === 'auto' ? {} : { direction: dir }),
  };
  return (
    <div dir={dir} data-testid="doc-textbox" style={css}>
      {children}
    </div>
  );
}

/**
 * Renders text content for a `TextBox`: either the plain string (legacy — the
 * whole text takes the `TextBox`'s own fontWeight/fontStyle from `TextStyle`),
 * or, when `runs` are present, one span per run with its own bold/italic
 * override (`undefined` on a run inherits the container's style, matching the
 * legacy look for any run that never got explicitly formatted).
 */
function RichText({ plain, runs }: { plain: string; runs?: TextRun[] }) {
  if (!runs || runs.length === 0) return <>{plain}</>;
  return (
    <>
      {runs.map((r, i) => (
        <span
          key={i}
          style={{
            fontWeight: r.bold === undefined ? undefined : r.bold ? 'bold' : 'normal',
            fontStyle: r.italic === undefined ? undefined : r.italic ? 'italic' : 'normal',
          }}
        >
          {r.text}
        </span>
      ))}
    </>
  );
}
