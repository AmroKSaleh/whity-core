/**
 * Backward-compatible re-export shim: the document/label designer model moved
 * to `@amroksaleh/ui/documents/types` (WC doc-designer-ui-extraction) so the
 * portable renderers that consume it (`element-content.tsx`, `element-layer.tsx`)
 * can be reused and Storybook-iterated outside the Next.js app. Every existing
 * `@/lib/documents/types` import keeps working unchanged via this re-export.
 */
export * from '@amroksaleh/ui/documents/types';
