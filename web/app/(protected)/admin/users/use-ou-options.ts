'use client';

import { useEffect, useState } from 'react';
import { api } from '@/lib/api/client';
import { fetchAllPagesTyped } from '@/lib/api/fetch-all-pages';
import { useToast } from '@/lib/toast-context';
import type { components } from '@/lib/api/schema';

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
 * Why the option list must not be offered.
 *
 * `loaded`/`total` are carried rather than a formatted sentence because the
 * hook has no translator: the modal renders these through `t()`. `total` is
 * null when even the first page failed, so the size of the gap is unknown.
 */
export interface OuLoadFailure {
  loaded: number;
  total: number | null;
}

/**
 * Shared source of OU dropdown options for the Users admin edit form.
 *
 * The options are driven from the live `GET /api/ous` endpoint so only
 * organisational units that belong to the acting tenant are offered. When
 * the modal is closed (`enabled = false`) the fetch is skipped to avoid
 * unnecessary network requests.
 *
 * The endpoint is paginated (25 per page by default, 100 maximum), so a single
 * request only ever describes the first page — this is the picker that decides
 * which OU a person belongs to, and an option that is merely on page 2 looks
 * exactly like an OU that does not exist. Every page is fetched, and if the set
 * could not be completed the options are withheld entirely: a shorter list is
 * indistinguishable from a correct one, and acting on it writes the wrong OU
 * onto a real person.
 *
 * @param enabled When false the fetch is skipped (e.g. while a modal is closed).
 * @returns The fetched OU options, a loading flag, and the failure to render
 *          in place of the picker when the list could not be completed.
 */
export function useOuOptions(enabled: boolean): {
  ouOptions: OuOption[];
  isLoadingOus: boolean;
  ouLoadFailure: OuLoadFailure | null;
} {
  const { addToast } = useToast();
  const [ouOptions, setOuOptions] = useState<OuOption[]>([]);
  const [isLoadingOus, setIsLoadingOus] = useState(false);
  const [ouLoadFailure, setOuLoadFailure] = useState<OuLoadFailure | null>(null);

  useEffect(() => {
    if (!enabled) {
      return;
    }

    const fetchOus = async (): Promise<void> => {
      try {
        setIsLoadingOus(true);
        setOuLoadFailure(null);

        const result = await fetchAllPagesTyped<
          components['schemas']['OrganizationalUnit']
        >((query) => api.GET('/api/v1/ous', { params: { query } }));

        if (!result.complete) {
          setOuOptions([]);
          setOuLoadFailure({ loaded: result.items.length, total: result.total });
          addToast('Failed to fetch organisational units', 'error');
          return;
        }

        setOuOptions(
          result.items.map((ou) => ({
            value: String(ou.id),
            label: ou.name,
          }))
        );
      } catch (error) {
        const message =
          error instanceof Error ? error.message : 'Failed to fetch organisational units';
        setOuOptions([]);
        setOuLoadFailure({ loaded: 0, total: null });
        addToast(message, 'error');
      } finally {
        setIsLoadingOus(false);
      }
    };

    void fetchOus();
  }, [enabled, addToast]);

  return { ouOptions, isLoadingOus, ouLoadFailure };
}
