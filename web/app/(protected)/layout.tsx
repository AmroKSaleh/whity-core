'use client';

import { AuthGate } from '@/components/auth-gate';
import { Sidebar } from '@/components/sidebar';

/**
 * The standard app shell: sidebar + a padded, max-width content column.
 *
 * The authentication/first-run rules live in `<AuthGate>` (shared with
 * `app/(editor)/layout.tsx`, the full-screen shell), so this layout is now only
 * about CHROME.
 */
export default function ProtectedLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <AuthGate>
      {/* `fixed inset-0` (not `h-screen`, which is only a same-magnitude
          *coincidence* with the viewport, not a binding to it) takes the shell
          out of document flow entirely: it always exactly covers the viewport
          no matter how tall its content wants to be, so `body` never grows past
          one viewport height and the window itself can never scroll. `main`'s
          own `overflow-auto` remains the ONLY scroll container — without this,
          a tall page (e.g. Settings after WC-fbdc31a2 added the email-addresses
          card) grows `body` too, producing a second, outer window scrollbar
          that clips the fixed sidebar out of view as you scroll it. */}
      <div className="fixed inset-0 flex overflow-hidden bg-background">
        {/* Sidebar - responsive widths handled in component */}
        <Sidebar />

        {/* Main Content */}
        <main className="flex-1 overflow-auto">
          <div className="p-6 md:p-8 max-w-7xl">{children}</div>
        </main>
      </div>
    </AuthGate>
  );
}
