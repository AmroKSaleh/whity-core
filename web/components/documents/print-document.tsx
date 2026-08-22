'use client';

/**
 * Backward-compatible re-export shim for the print/PDF renderer.
 *
 * NOTE THE IMPORT PATH. This resolves through the dedicated
 * `@amroksaleh/features/document-designer/print-document` subpath rather than
 * the slice's barrel, and that is load-bearing rather than stylistic:
 * `render-service/harness/entry.tsx` imports THIS file, and going via the
 * barrel would pull the entire editor (radix, tabler icons, the canvas) into
 * the production PDF bundle and leave tree-shaking to remove it again. The
 * subpath keeps the harness graph exactly the shape it was.
 */
export { PrintDocument } from '@amroksaleh/features/document-designer/print-document';
