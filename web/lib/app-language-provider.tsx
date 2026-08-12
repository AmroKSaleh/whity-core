'use client';

import { LanguageProvider } from '@amroksaleh/features/i18n';
import { useAuth } from '@/lib/auth-context';

/**
 * Binds the shared LanguageProvider to THIS app's notion of "who is signed in".
 *
 * The provider itself is deliberately auth-agnostic (non-Next shells consume
 * it too), so it takes an opaque `identityKey` rather than reading an auth
 * context. This component is the Next binding: it hands over the profile id.
 *
 * Why it matters: signing in is a client-side navigation, so without this the
 * provider would keep the language it resolved for the anonymous visitor —
 * a user whose profile says Arabic would land on an English, left-to-right
 * dashboard until they happened to reload the page. The layout cannot do this
 * itself; it is a server component and cannot read auth state.
 */
export function AppLanguageProvider({ children }: { children: React.ReactNode }) {
  const { user } = useAuth();

  return <LanguageProvider identityKey={user?.id ?? null}>{children}</LanguageProvider>;
}
