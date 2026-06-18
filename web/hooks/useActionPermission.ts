import { useMemo } from 'react';
import { useCapabilities } from '@/hooks/useCapabilities';

/**
 * The UI-gating decision for a single capability-guarded action.
 *
 * Exactly one of `disabled` / `hidden` is ever true when the permission is
 * missing; both are false (and `allowed` true) when it is held.
 */
export interface ActionPermission {
  /** True only when the caller holds the permission (server stays authoritative). */
  allowed: boolean;
  /** Render nothing — used for destructive actions the caller cannot perform. */
  hidden: boolean;
  /** Render but disable — used for non-destructive actions the caller cannot perform. */
  disabled: boolean;
  /** Human-readable explanation for the gated state, or `null` when allowed. */
  reason: string | null;
}

/** Per-call options for {@link useActionPermission}. */
export interface UseActionPermissionOptions {
  /**
   * When `true`, a missing permission HIDES the control instead of disabling
   * it — so a user is never tempted to click a destructive action (delete,
   * revoke, …) they cannot complete. Defaults to `false` (disable + explain).
   */
  destructive?: boolean;
}

/**
 * Derives the HYBRID UI-gating decision for a permission-guarded action.
 *
 * Policy:
 *   - Holds `permission`            → `{ allowed:true,  hidden:false, disabled:false, reason:null }`
 *   - Lacks it, non-destructive     → `{ allowed:false, hidden:false, disabled:true,  reason:'Requires <permission>' }`
 *   - Lacks it, destructive         → `{ allowed:false, hidden:true,  disabled:false, reason:'Requires <permission>' }`
 *
 * Built on {@link useCapabilities}, which is fail-closed: while capabilities
 * are loading `hasPermission` returns `false`, so this hook treats the caller
 * as lacking the permission (a non-destructive control is disabled, a
 * destructive one hidden) until the real permission set arrives. These slugs
 * are UI hints only — the server remains the authority on every write.
 */
export function useActionPermission(
  permission: string,
  opts?: UseActionPermissionOptions
): ActionPermission {
  const { hasPermission } = useCapabilities();
  const destructive = opts?.destructive === true;
  const allowed = hasPermission(permission);

  return useMemo<ActionPermission>(() => {
    if (allowed) {
      return { allowed: true, hidden: false, disabled: false, reason: null };
    }
    const reason = `Requires ${permission}`;
    return {
      allowed: false,
      hidden: destructive,
      disabled: !destructive,
      reason,
    };
  }, [allowed, destructive, permission]);
}
