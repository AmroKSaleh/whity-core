'use client';

import type { CSSProperties } from 'react';
import type { DocElement, TextStyle } from '@/lib/documents/types';
import { interpolate, resolveBound } from '@/lib/documents/storage';
import { BarcodeSvg } from './barcode-svg';

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
      return <TextBox style={el.style}>{el.text}</TextBox>;
    case 'dynamicText':
      return <TextBox style={el.style}>{preview ? interpolate(el.template, data) : el.template}</TextBox>;
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
    lineHeight: 1.2,
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
    <div dir={dir} style={css}>
      {children}
    </div>
  );
}
