'use client';

import { useEffect, useState } from 'react';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';

/**
 * A selectable role option for the user create/edit dropdowns.
 *
 * `value` is the role NAME the backend resolves to a tenant-visible `roles.id`
 * (WC-113/WC-121), and `label` is the human-friendly display text.
 */
export interface RoleOption {
  value: string;
  label: string;
}

/**
 * Title-case a role name for display (e.g. `admin` -> `Admin`). The underlying
 * value submitted to the API is always the raw, lower-case role name so the
 * server-side name resolution matches the seeded roles exactly.
 */
function toLabel(name: string): string {
  if (name.length === 0) {
    return name;
  }
  return name.charAt(0).toUpperCase() + name.slice(1);
}

/**
 * Shared source of the role dropdown options for the Users admin create/edit
 * forms.
 *
 * The options are driven from the live `GET /api/roles` endpoint (the same
 * tenant-visible role list the Roles admin page consumes) rather than a
 * hard-coded set. This guarantees every offered role actually exists for the
 * tenant and resolves server-side — removing the phantom "Moderator" option that
 * had no backing seed role and 404'd on submit once the backend began validating
 * role names (WC-121). Both the create and edit modals consume this hook so they
 * always share a single, real role-option source.
 *
 * @param enabled When false the fetch is skipped (e.g. while a modal is closed).
 * @returns The fetched role options and a loading flag.
 */
export function useRoleOptions(enabled: boolean): {
  roleOptions: RoleOption[];
  isLoadingRoles: boolean;
} {
  const { addToast } = useToast();
  const [roleOptions, setRoleOptions] = useState<RoleOption[]>([]);
  const [isLoadingRoles, setIsLoadingRoles] = useState(false);

  // The fetch is defined inside the effect (mirroring the Roles admin modals)
  // rather than as a memoized callback: this keeps the setState calls indirect,
  // so the lint rule against synchronous setState in an effect is satisfied.
  useEffect(() => {
    if (!enabled) {
      return;
    }

    const fetchRoles = async (): Promise<void> => {
      try {
        setIsLoadingRoles(true);
        const { data } = await api.GET('/api/roles');

        if (data === undefined) {
          throw new Error('Failed to fetch roles');
        }

        setRoleOptions(
          data.data.map((role) => ({
            value: role.name,
            label: toLabel(role.name),
          }))
        );
      } catch (error) {
        const message =
          error instanceof Error ? error.message : 'Failed to fetch roles';
        addToast(message, 'error');
      } finally {
        setIsLoadingRoles(false);
      }
    };

    void fetchRoles();
  }, [enabled, addToast]);

  return { roleOptions, isLoadingRoles };
}
