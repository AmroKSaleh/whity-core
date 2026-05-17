'use client';

import { createContext, useContext, useState, useEffect, type ReactNode } from 'react';

interface User {
  id: number;
  email: string;
  role: string;
}

interface AuthContextType {
  token: string | null;
  user: User | null;
  isLoading: boolean;
  error: string | null;
  login: (email: string, password: string) => Promise<void>;
  logout: () => void;
  isAuthenticated: () => boolean;
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

function isTokenExpired(token: string): boolean {
  const payload = decodeJWT(token);
  if (!payload || !payload.exp) {
    return true;
  }
  const expiryTime = payload.exp * 1000; // Convert to milliseconds
  return Date.now() >= expiryTime;
}

interface AuthProviderProps {
  children: ReactNode;
}

export function AuthProvider({ children }: AuthProviderProps) {
  const [token, setToken] = useState<string | null>(null);
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Initialize from localStorage on mount
  useEffect(() => {
    const storedToken = localStorage.getItem('whity_auth_token');

    if (storedToken) {
      // Check if token is expired
      if (isTokenExpired(storedToken)) {
        localStorage.removeItem('whity_auth_token');
        setToken(null);
        setUser(null);
      } else {
        // Decode token and extract user info
        const payload = decodeJWT(storedToken);
        if (payload && payload.id && payload.email) {
          setToken(storedToken);
          setUser({
            id: payload.id,
            email: payload.email,
            role: payload.role || '',
          });
        } else {
          localStorage.removeItem('whity_auth_token');
          setToken(null);
          setUser(null);
        }
      }
    }

    setIsLoading(false);
  }, []);

  const login = async (email: string, password: string): Promise<void> => {
    setIsLoading(true);
    setError(null);

    try {
      const apiUrl = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000';
      const response = await fetch(`${apiUrl}/api/login`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ email, password }),
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        throw new Error(errorData.message || 'Login failed');
      }

      const data = await response.json();
      const authToken = data.token;

      // Store token in localStorage
      localStorage.setItem('whity_auth_token', authToken);
      setToken(authToken);

      // Decode and set user from response or token
      if (data.user) {
        setUser(data.user);
      } else {
        const payload = decodeJWT(authToken);
        if (payload && payload.id && payload.email) {
          setUser({
            id: payload.id,
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

  const logout = (): void => {
    setToken(null);
    setUser(null);
    setError(null);
    localStorage.removeItem('whity_auth_token');
  };

  const isAuthenticated = (): boolean => {
    return !!token && !!user;
  };

  const apiClient = async (
    url: string,
    options?: RequestInit
  ): Promise<Response> => {
    const apiUrl = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000';
    const fullUrl = url.startsWith('http') ? url : `${apiUrl}${url}`;

    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      ...(typeof options?.headers === 'object' && !Array.isArray(options.headers)
        ? (options.headers as Record<string, string>)
        : {}),
    };

    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    }

    const response = await fetch(fullUrl, {
      ...options,
      headers,
    });

    return response;
  };

  const value: AuthContextType = {
    token,
    user,
    isLoading,
    error,
    login,
    logout,
    isAuthenticated,
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
