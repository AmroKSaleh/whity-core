'use client';

import { AuthGate } from '@/components/auth-gate';

/**
 * The FULL-SCREEN shell, for workspaces that own the whole viewport: no app
 * sidebar, no padding, no max-width column — the page gets the entire window
 * and supplies its own chrome (see the document editor's top bar, which carries
 * the exit affordance the missing sidebar would otherwise provide).
 *
 * A sibling route group to `(protected)`, sharing its `<AuthGate>`. Route groups
 * don't appear in URLs, so a page moved here keeps its path exactly
 * (`/admin/documents` stays `/admin/documents`) — but it must exist in only ONE
 * group, or the two would collide on the same route.
 *
 * `fixed inset-0` for the same reason `(protected)` uses it: it binds the shell
 * to the viewport instead of to its content, so `body` can never grow a second
 * outer scrollbar and the editor's own panes stay the only scroll containers.
 */
export default function EditorLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <AuthGate>
      <div className="fixed inset-0 overflow-hidden bg-background">{children}</div>
    </AuthGate>
  );
}
