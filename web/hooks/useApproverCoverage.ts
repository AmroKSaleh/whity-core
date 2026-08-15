'use client';

import { useEffect, useState } from 'react';
import { api } from '@/lib/api/client';

/**
 * How many accounts in the current tenant can approve a parked password reset.
 *
 * Mirrors `GET /api/v1/password-resets/approver-coverage` (WC-797 §4a). It
 * exists because `auth.password_reset_approval_required` can produce a state
 * nothing in the product can leave: a tenant with a single approver cannot have
 * that approver's own reset approved, and the only exit was direct database
 * access. Everything that can reach that state — enabling the gate, removing an
 * approver, moving one to a role without the permission — warns off this data.
 */
export interface ApproverCoverage {
  tenantId: number;
  /** The smallest roster that survives losing one approver. */
  minimumRecommended: number;
  /** Whether the approval gate is currently on. */
  approvalRequired: boolean;
  approverCount: number;
  approverProfileIds: number[];
  /** Role names that carry `password_resets:approve` in this tenant. */
  approverRoleNames: string[];
  belowMinimum: boolean;
}

/**
 * Fetch the calling tenant's approver coverage.
 *
 * Returns `null` rather than an error on any failure, including a 403 for a
 * caller without `users:read`. A warning is an advisory: failing to render one
 * is a smaller harm than replacing an administrator's screen with an error
 * about a permission they do not need for the task in front of them.
 *
 * @param enabled When false the fetch is skipped (e.g. while a modal is closed).
 */
export function useApproverCoverage(enabled: boolean): {
  coverage: ApproverCoverage | null;
  isLoadingCoverage: boolean;
} {
  const [coverage, setCoverage] = useState<ApproverCoverage | null>(null);
  const [isLoadingCoverage, setIsLoadingCoverage] = useState(false);

  useEffect(() => {
    if (!enabled) {
      return;
    }

    let cancelled = false;

    const fetchCoverage = async (): Promise<void> => {
      setIsLoadingCoverage(true);
      try {
        const { data } = await api.GET('/api/v1/password-resets/approver-coverage');
        if (cancelled || data === undefined) {
          return;
        }
        setCoverage({
          tenantId: data.data.tenant_id,
          minimumRecommended: data.data.minimum_recommended,
          approvalRequired: data.data.approval_required,
          approverCount: data.data.approver_count,
          approverProfileIds: data.data.approver_profile_ids,
          approverRoleNames: data.data.approver_role_names,
          belowMinimum: data.data.below_minimum,
        });
      } catch {
        // Advisory only — see the doc comment above.
      } finally {
        if (!cancelled) {
          setIsLoadingCoverage(false);
        }
      }
    };

    void fetchCoverage();

    return () => {
      cancelled = true;
    };
  }, [enabled]);

  return { coverage, isLoadingCoverage };
}

/**
 * Would taking `password_resets:approve` away from this profile — by removing
 * it from the tenant, or by moving it to `nextRoleName` — leave the tenant
 * below the minimum approver roster?
 *
 * Answers false when the gate is off, when the profile is not an approver, or
 * when the move is sideways into another approving role. Pass `nextRoleName`
 * as null for a removal.
 */
export function wouldStrandTenant(
  coverage: ApproverCoverage | null,
  profileId: number,
  nextRoleName: string | null
): boolean {
  if (coverage === null || !coverage.approvalRequired) {
    return false;
  }
  if (!coverage.approverProfileIds.includes(profileId)) {
    return false;
  }
  if (nextRoleName !== null && coverage.approverRoleNames.includes(nextRoleName)) {
    return false;
  }

  return coverage.approverCount - 1 < coverage.minimumRecommended;
}
