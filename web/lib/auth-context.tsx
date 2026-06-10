'use client';

import { createContext, useContext, useState, useEffect, useCallback, type ReactNode } from 'react';
import { apiClient as apiClientModule } from './api-client';

interface User {
  id: number;
  email: string;
  role: string;
}

interface AuthContextType {
  user: User | null;
  isLoading: boolean;
  error: string | null;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  isAuthenticated: () => boolean;
  refreshAuth: () => Promise<void>;
  apiClient: (
    url: string,
    options?: RequestInit
  ) => Promise<Response>;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

interface JWTPayload {
  exp?: number;
  id?: number;
  email?: string;
  role?: string;
  [key: string]: any;
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
  } catch (error) {
    return null;
  }
}

interface AuthProviderProps {
  children: ReactNode;
}

export function AuthProvider({ children }: AuthProviderProps) {
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Initialize user from /api/me on mount (uses httpOnly cookies)
  useEffect(() => {
    const initializeAuth = async () => {
      try {
        const response = await fetch('/api/me', {
          credentials: 'include',
        });

        if (response.ok) {
          const data = await response.json();
          if (data.user) {
            setUser(data.user);
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
      const response = await fetch('/api/login', {
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
        const userId = payload?.id || payload?.user_id;
        if (payload && userId && payload.email) {
          setUser({
            id: userId,
            email: payload.email,
            role: payload.role || '',
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
      await fetch('/api/auth/logout', {
        method: 'POST',
        credentials: 'include',
        // CSRF defense (WC-160): required on the auth POSTs.
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
    } catch (error) {
      console.error('Logout request failed:', error);
    } finally {
      setUser(null);
      setError(null);
    }
  };

  const refreshAuth = async (): Promise<void> => {
    try {
      const response = await fetch('/api/me', {
        credentials: 'include',
      });

      if (response.ok) {
        const data = await response.json();
        if (data.user) {
          setUser(data.user);
        }
      } else {
        setUser(null);
      }
    } catch (error) {
      console.error('Failed to refresh auth:', error);
      setUser(null);
    }
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
    isLoading,
    error,
    login,
    logout,
    isAuthenticated,
    refreshAuth,
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
