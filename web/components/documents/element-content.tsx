/**
 * Backward-compatible re-export shim: `ElementContent` (the leaf renderer for
 * one `DocElement`'s visual content) moved to
 * `@whity-org/ui/documents/element-content` (WC doc-designer-ui-extraction) so
 * it can be reused and Storybook-iterated outside the Next.js app. Every
 * existing `./element-content` import keeps working unchanged.
 */
export * from '@whity-org/ui/documents/element-content';
