'use client';

/**
 * Backward-compatible re-export shim: the Document & Label Designer moved to
 * `@amroksaleh/features/document-designer` so the same code renders in the
 * Next.js app and in the Tauri desktop template, rather than being forked into
 * a hand-maintained twin. Every existing `@/components/documents/editor-commands` import
 * keeps working unchanged.
 */
export { buildEditorMenus, buildEditorToolbar, listEditorShortcuts, useEditorChrome, type AlignKind, type EditorCommandContext, type ToolbarButton, type ToolbarGroup, type ZoomAction } from '@amroksaleh/features/document-designer';
