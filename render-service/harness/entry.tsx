/**
 * Render harness entry point (ADR 0012 / WC-docdesigner Track 2).
 *
 * This is NOT a reimplementation of the document/label renderer — it is a
 * minimal mount point that loads the ACTUAL `PrintDocument` component from
 * `web/components/documents/print-document.tsx` (bundled in unchanged, via an
 * esbuild path alias — see scripts/build-harness.js) with props read off
 * `window.__RENDER_PROPS__`, which the Node server injects before navigation
 * (see src/renderer.js). Reusing the real React renderer is the whole point
 * of ADR 0012: exported PDFs stay pixel-parity with the on-screen preview
 * because they run the SAME rendering code, not a second hand-rolled one.
 *
 * `window.__RENDER_READY__` is flipped once React has committed AND the
 * browser has settled fonts/images, so `renderer.js` knows when it is safe to
 * call `page.pdf()`.
 */
import { createRoot } from 'react-dom/client';
import { PrintDocument } from '../../web/components/documents/print-document';
import type { DocTemplate } from '../../web/lib/documents/types';
import type { DocBlock } from '../../web/lib/documents/blocks';
import type { SheetSpec } from '../../web/lib/documents/sheet';

declare global {
  interface Window {
    __RENDER_PROPS__?: {
      template: DocTemplate;
      dataRows: Record<string, string>[];
      blocks?: Record<string, DocBlock>;
      sheet?: SheetSpec;
    };
    __RENDER_READY__?: boolean;
  }
}

function mount() {
  const props = window.__RENDER_PROPS__;
  const container = document.getElementById('root');
  if (!props || !container) {
    // Nothing to render — leave __RENDER_READY__ unset so a caller waiting on
    // it fails loudly (a timeout) rather than silently producing a blank PDF.
    return;
  }

  const root = createRoot(container);
  root.render(
    <PrintDocument
      template={props.template}
      datasets={props.dataRows}
      blocks={props.blocks ?? {}}
      sheet={props.sheet}
    />
  );

  // Wait a paint cycle before inspecting the DOM: `root.render()` schedules
  // work rather than committing it synchronously, so images/text are not
  // guaranteed to exist yet on the very next microtask. Once painted, wait for
  // web-font shaping (Arabic) AND every barcode/QR data-URI <img> to finish
  // loading, then one more rAF so the settled layout has actually painted,
  // before signalling ready.
  requestAnimationFrame(() => {
    requestAnimationFrame(() => {
      Promise.all([
        document.fonts ? document.fonts.ready : Promise.resolve(),
        waitForImages(container),
      ]).then(() => {
        requestAnimationFrame(() => {
          requestAnimationFrame(() => {
            window.__RENDER_READY__ = true;
          });
        });
      });
    });
  });
}

/** Resolve once every <img> currently in `root` has finished loading (or errored). */
function waitForImages(root: HTMLElement): Promise<void> {
  const images = Array.from(root.querySelectorAll('img'));
  if (images.length === 0) return Promise.resolve();

  return Promise.all(
    images.map(
      (img) =>
        new Promise<void>((resolve) => {
          if (img.complete) {
            resolve();
            return;
          }
          img.addEventListener('load', () => resolve(), { once: true });
          img.addEventListener('error', () => resolve(), { once: true });
        })
    )
  ).then(() => undefined);
}

mount();
