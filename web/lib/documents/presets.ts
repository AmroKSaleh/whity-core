/**
 * Backward-compatible re-export shim: moved to
 * `@amroksaleh/features/document-designer` with the rest of the designer so
 * the Tauri desktop client can render the same code. Every existing
 * `@/lib/documents/presets` import keeps working unchanged.
 */
export { PAGE_PRESETS, DEFAULT_TEXT_STYLE, blankTemplate, newPageId } from '@amroksaleh/features/document-designer';
