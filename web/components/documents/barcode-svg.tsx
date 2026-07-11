'use client';

import { useMemo } from 'react';
import { toSVG } from 'bwip-js/browser';

/**
 * Renders a barcode or QR/matrix code as crisp SVG via bwip-js, scaled to fill
 * its container. Invalid values (e.g. letters in an EAN) or an empty value show
 * a graceful placeholder instead of throwing.
 */
export function BarcodeSvg({
  symbology,
  value,
  showText = false,
}: {
  symbology: string;
  value: string;
  showText?: boolean;
}) {
  const svg = useMemo(() => {
    const text = value.trim();
    if (text === '') return null;
    try {
      const isMatrix = symbology === 'qrcode' || symbology === 'datamatrix';
      const raw = toSVG({
        bcid: symbology,
        text,
        includetext: !isMatrix && showText,
        textxalign: 'center',
        scale: 3,
        ...(isMatrix ? {} : { height: 10 }),
      });
      return normalizeSvg(raw);
    } catch {
      return null;
    }
  }, [symbology, value, showText]);

  if (svg === null) {
    return (
      <div className="flex h-full w-full items-center justify-center rounded-sm border border-dashed border-border bg-muted/30 text-[8px] text-muted-foreground">
        {value.trim() === '' ? 'no value' : 'invalid for symbology'}
      </div>
    );
  }

  return (
    <div
      className="h-full w-full [&>svg]:h-full [&>svg]:w-full"
      dangerouslySetInnerHTML={{ __html: svg }}
    />
  );
}

/**
 * Make a bwip-js SVG scale to its container: guarantee a viewBox, drop the fixed
 * width/height on the <svg> tag, and keep aspect ratio.
 */
function normalizeSvg(raw: string): string {
  let svg = raw;
  const widthMatch = svg.match(/<svg[^>]*?\swidth="([\d.]+)/);
  const heightMatch = svg.match(/<svg[^>]*?\sheight="([\d.]+)/);
  if (!/viewBox=/.test(svg) && widthMatch && heightMatch) {
    svg = svg.replace('<svg', `<svg viewBox="0 0 ${widthMatch[1]} ${heightMatch[1]}"`);
  }
  svg = svg
    .replace(/(<svg[^>]*?)\swidth="[^"]*"/, '$1')
    .replace(/(<svg[^>]*?)\sheight="[^"]*"/, '$1');
  if (!/preserveAspectRatio=/.test(svg)) {
    svg = svg.replace('<svg', '<svg preserveAspectRatio="xMidYMid meet"');
  }
  return svg;
}
