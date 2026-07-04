'use client';

import * as React from 'react';
import { Button } from '@amroksaleh/ui/button';
import { useActionPermission } from '@/hooks/useActionPermission';

/**
 * Props for {@link PermissionButton}: the full {@link Button} surface plus the
 * permission slug it guards and an optional `destructive` flag that switches
 * the gating from "disable + explain" to "hide entirely".
 */
export type PermissionButtonProps = React.ComponentProps<typeof Button> & {
  /** The capability slug required to use this action (e.g. `users:write`). */
  permission: string;
  /**
   * When `true`, the control is HIDDEN (rather than disabled) if the caller
   * lacks the permission — appropriate for destructive actions. Defaults to
   * `false`.
   */
  destructive?: boolean;
};

/**
 * A capability-gated `<Button>` implementing the HYBRID RBAC-UI policy via
 * {@link useActionPermission}:
 *
 *   - allowed  → a normal `Button`, forwarding every prop/handler.
 *   - disabled → a disabled `Button` wrapped in an element carrying the
 *                `reason` as a native tooltip (`title`), because a disabled
 *                button emits no pointer events of its own. (The repo has no
 *                styled Tooltip primitive — admin chrome such as the sidebar
 *                uses the native `title` attribute — so we follow that
 *                convention rather than hand-roll an unstyled one.)
 *   - hidden   → renders `null`.
 *
 * `onClick` is suppressed whenever the action is disabled: a disabled native
 * button already won't fire, but we also strip the handler so it can never be
 * invoked programmatically.
 */
export function PermissionButton({
  permission,
  destructive = false,
  onClick,
  disabled,
  ...buttonProps
}: PermissionButtonProps) {
  const decision = useActionPermission(permission, { destructive });

  if (decision.hidden) {
    return null;
  }

  // A consumer may independently disable the button (e.g. a pending request);
  // the permission decision can only ADD a disabled state, never remove one.
  const isDisabled = disabled === true || decision.disabled;

  if (isDisabled) {
    const reason = decision.reason ?? undefined;
    return (
      <span title={reason} className="inline-flex">
        <Button
          {...buttonProps}
          disabled
          aria-disabled
          // Suppress the handler entirely — it can never fire while disabled.
          onClick={undefined}
        />
        {reason !== undefined && (
          // The reason is exposed for assistive tech (a native `title` on a
          // wrapper isn't reliably announced) and as the tooltip text.
          <span className="sr-only" role="note">
            {reason}
          </span>
        )}
      </span>
    );
  }

  return <Button {...buttonProps} onClick={onClick} disabled={disabled} />;
}
