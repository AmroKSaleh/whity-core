'use client';

import { useEffect, useState } from 'react';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';

/**
 * A selectable OU option for the user edit dropdown.
 *
 * `value` is the numeric OU id (as a string for Select compatibility).
 * `label` is the human-friendly OU name.
 */
export interface OuOption {
  value: string;
  label: string;
}

/**
 * Shared source of OU dropdown options for the Users admin edit form.
 *
 * The options are driven from the live `GET /api/ous` endpoint so only
 * organisational units that belong to the acting tenant are offered. When
 * the modal is closed (`enabled = false`) the fetch is skipped to avoid
 * unnecessary network requests.
 *
 * @param enabled When false the fetch is skipped (e.g. while a modal is closed).
 * @returns The fetched OU options and a loading flag.
 */
export function useOuOptions(enabled: boolean): {
  ouOptions: OuOption[];
  isLoadingOus: boolean;
} {
  const { addToast } = useToast();
  const [ouOptions, setOuOptions] = useState<OuOption[]>([]);
  const [isLoadingOus, setIsLoadingOus] = useState(false);

  useEffect(() => {
    if (!enabled) {
      return;
    }

    const fetchOus = async (): Promise<void> => {
      try {
        setIsLoadingOus(true);
        const { data } = await api.GET('/api/ous');

        if (data === undefined) {
          throw new Error('Failed to fetch organisational units');
        }

        setOuOptions(
          data.data.map((ou) => ({
            value: String(ou.id),
            label: ou.name,
          }))
        );
      } catch (error) {
        const message =
          error instanceof Error ? error.message : 'Failed to fetch organisational units';
        addToast(message, 'error');
      } finally {
        setIsLoadingOus(false);
      }
    };

    void fetchOus();
  }, [enabled, addToast]);

  return { ouOptions, isLoadingOus };
}
