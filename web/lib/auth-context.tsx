'use client';

import { createContext, useContext, useState, useEffect, useCallback, type ReactNode } from 'react';
import { apiClient as apiClientModule } from './api-client';

interface User {
  id: number;
  email: string;
  role: string;
  tenant_id: number;
}

/**
 * A single tenant membership belonging to the authenticated profile.
 * Returned by GET /api/me (WC-f8164c87) for the sidenav tenant-switcher.
 */
export interface Membership {
  tenant_id: number;
  tenant_name: string;
  role: string;
}

interface AuthContextType {
  user: User | null;
  /** The authenticated profile's active memberships (WC-f8164c87). Empty array
   * for unauthenticated sessions. */
  memberships: Membership[];
  isLoading: boolean;
  error: string | null;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  isAuthenticated: () => boolean;
  refreshAuth: () => Promise<void>;
  /**
   * Switch the active tenant for the current session (WC-f8164c87).
   * POSTs to /api/v1/auth/switch-tenant and re-fetches /api/v1/me on success,
   * so user and memberships are updated without a full page reload.
   */
  switchTenant: (tenantId: number) => Promise<void>;
  apiClient: (
    url: string,
    options?: RequestInit
  ) => Promise<Response>;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

interface JWTPayload {
  exp?: number;
  /**
   * Canonical identity claims (ADR 0005 §5): profile_id is the identity and
   * active_tenant_id the session's tenant. The legacy {user_id, tenant_id}
   * dual-claim window was removed by the identity hard cutover (WC-idcut-E/F).
   */
  profile_id?: number;
  active_tenant_id?: number;
  email?: string;
  role?: string;
  [key: string]: unknown;
}

function decodeJWT(token: string): JWTPayload | null {
  try {
    const parts = token.split('.');
    if (parts.length !== 3) {
      return null;
    }
    const payload = parts[1];
    const decoded = atob(payload);
    return JSON.parse(decoded);
  } catch {
    return null;
  }
}

interface AuthProviderProps {
  children: ReactNode;
}

export function AuthProvider({ children }: AuthProviderProps) {
  const [user, setUser] = useState<User | null>(null);
  const [memberships, setMemberships] = useState<Membership[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Initialize user from /api/v1/me on mount (uses httpOnly cookies)
  useEffect(() => {
    const initializeAuth = async () => {
      try {
        let response = await fetch('/api/v1/me', {
          credentials: 'include',
        });

        // access_token has a 15-min TTL; silently refresh before giving up so
        // that a stored browser session (e.g. Playwright storageState) survives
        // longer test runs without requiring a fresh login.
        if (response.status === 401) {
          const refresh = await fetch('/api/v1/auth/refresh', {
            method: 'POST',
            credentials: 'include',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
          });
          if (refresh.ok) {
            response = await fetch('/api/v1/me', { credentials: 'include' });
          }
        }

        if (response.ok) {
          const data = await response.json();
          if (data.user) {
            setUser(data.user);
          }
          if (Array.isArray(data.memberships)) {
            setMemberships(data.memberships as Membership[]);
          }
        }
      } catch (error) {
        console.error('Failed to initialize auth:', error);
      } finally {
        setIsLoading(false);
      }
    };

    initializeAuth();
  }, []);

  const login = async (email: string, password: string): Promise<void> => {
    setIsLoading(true);
    setError(null);

    try {
      const response = await fetch('/api/v1/login', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          // CSRF defense (WC-160): required on the auth POSTs.
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ email, password }),
        credentials: 'include',
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        throw new Error(errorData.message || 'Login failed');
      }

      const data = await response.json();

      // Authentication state lives entirely in the server-set httpOnly cookies
      // (#51); the JWT is never persisted client-side. We only mirror the user
      // profile into React state for rendering. Prefer the response `user`
      // object; fall back to decoding the response token purely to populate that
      // profile (it is not stored).
      if (data.user) {
        setUser(data.user);
      } else if (typeof data.token === 'string') {
        const payload = decodeJWT(data.token);
        // Canonical claims only (ADR 0005 §5): profile_id is the identity,
        // active_tenant_id the session's tenant. Used purely to populate the
        // in-memory profile for rendering; the token is never persisted.
        const profileId = payload?.profile_id;
        const tenantId =
          typeof payload?.active_tenant_id === 'number' ? payload.active_tenant_id : 0;
        if (payload && profileId && payload.email) {
          setUser({
            id: profileId,
            email: payload.email,
            role: payload.role || '',
            tenant_id: tenantId,
          });
        }
      }
    } catch (err) {
      const errorMessage = err instanceof Error ? err.message : 'Login failed';
      setError(errorMessage);
      throw err;
    } finally {
      setIsLoading(false);
    }
  };

  const logout = async (): Promise<void> => {
    try {
      await fetch('/api/v1/auth/logout', {
        method: 'POST',
        credentials: 'include',
        // CSRF defense (WC-160): required on the auth POSTs.
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
    } catch (error) {
      console.error('Logout request failed:', error);
    } finally {
      setUser(null);
      setMemberships([]);
      setError(null);
    }
  };

  const refreshAuth = async (): Promise<void> => {
    try {
      const response = await fetch('/api/v1/me', {
        credentials: 'include',
      });

      if (response.ok) {
        const data = await response.json();
        if (data.user) {
          setUser(data.user);
        }
        if (Array.isArray(data.memberships)) {
          setMemberships(data.memberships as Membership[]);
        }
      } else {
        setUser(null);
        setMemberships([]);
      }
    } catch (error) {
      console.error('Failed to refresh auth:', error);
      setUser(null);
      setMemberships([]);
    }
  };

  /**
   * Switch the active tenant (WC-f8164c87).
   *
   * POSTs to /api/v1/auth/switch-tenant with the chosen tenant_id, then
   * refetches /api/v1/me so user state (including the new tenant_id and
   * memberships) reflects the new session without a page reload.
   */
  const switchTenant = async (tenantId: number): Promise<void> => {
    const switchResponse = await fetch('/api/v1/auth/switch-tenant', {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        // CSRF defense (WC-160): required on state-changing auth POSTs.
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({ tenant_id: tenantId }),
    });

    if (!switchResponse.ok) {
      const errData = await switchResponse.json().catch(() => ({})) as Record<string, unknown>;
      throw new Error((errData['error'] as string | undefined) ?? 'Tenant switch failed');
    }

    // Re-fetch /api/v1/me so the in-memory user/memberships reflect the new
    // active_tenant_id without a full page reload.
    await refreshAuth();
  };

  const isAuthenticated = useCallback((): boolean => {
    return !!user;
  }, [user]);

  const apiClient = useCallback(
    (url: string, options?: RequestInit): Promise<Response> => {
      return apiClientModule(url, options);
    },
    []
  );

  const value: AuthContextType = {
    user,
    memberships,
    isLoading,
    error,
    login,
    logout,
    isAuthenticated,
    refreshAuth,
    switchTenant,
    apiClient,
  };

  return (
    <AuthContext.Provider value={value}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthContextType {
  const context = useContext(AuthContext);
  if (context === undefined) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
}
