'use client';

/**
 * Backward-compatible re-export shim: the Document & Label Designer moved to
 * `@whity-org/features/document-designer` so the same code renders in the
 * Next.js app and in the Tauri desktop template, rather than being forked into
 * a hand-maintained twin. Every existing `@/components/documents/palette` import
 * keeps working unchanged.
 */
export { Palette } from '@whity-org/features/document-designer';
