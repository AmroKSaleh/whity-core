/**
 * Backward-compatible re-export shim: `ElementLayer`/`BlockInstanceContent`
 * moved to `@whity-org/ui/documents/element-layer` (WC
 * doc-designer-ui-extraction) so they can be reused and Storybook-iterated
 * outside the Next.js app. Every existing `./element-layer` import keeps
 * working unchanged.
 */
export * from '@whity-org/ui/documents/element-layer';
