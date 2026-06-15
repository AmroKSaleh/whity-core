import { useState, useCallback, useEffect } from 'react';

export function useFetch<T>(
  fetchFn: () => Promise<T>,
  deps: React.DependencyList = []
): { data: T | null; loading: boolean; error: string | null; refetch: () => void } {
  const [data, setData] = useState<T | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // eslint-disable-next-line react-hooks/exhaustive-deps, react-hooks/use-memo
  const stableFetch = useCallback(fetchFn, deps);

  useEffect(() => {
    let cancelled = false;

    const run = async () => {
      setLoading(true);
      setError(null);
      try {
        const result = await stableFetch();
        if (!cancelled) setData(result);
      } catch (err: unknown) {
        if (!cancelled) setError(err instanceof Error ? err.message : 'An error occurred');
      } finally {
        if (!cancelled) setLoading(false);
      }
    };

    void run();

    return () => { cancelled = true; };
  }, [stableFetch]);

  return { data, loading, error, refetch: stableFetch };
}
