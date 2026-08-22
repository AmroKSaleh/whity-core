'use client';

/**
 * Injected-translator keys this file renders through `t`. Declared here for
 * the i18n catalogue extractor: it cannot infer a domain from a prop-injected
 * translator (see RolesTranslate — deliberately NOT typed `TranslateFn`, so
 * these files stay unscanned like DemoCatalog does via NavTranslate), so the
 * keys are enumerated below instead. Feature copy resolves in the `admin`
 * domain, shared UI chrome in `common`.
 *
 * @i18n-keys admin
 *   roles.create.cancel = Cancel
 *   roles.create.description.label = Description
 *   roles.create.description.placeholder = Role description
 *   roles.create.error = Failed to create role
 *   roles.create.name.label = Role Name
 *   roles.create.name.placeholder = e.g., Editor
 *   roles.create.permissions.label = Permissions
 *   roles.create.permissions.loading = Loading permissions...
 *   roles.create.permissionsError = Failed to fetch permissions
 *   roles.create.scope.error = Failed to load tenants
 *   roles.create.scope.global = Global — shared by every tenant
 *   roles.create.scope.globalHint = A global role is a single role every tenant uses. Editing it later changes it for all of them.
 *   roles.create.scope.label = Create this role in
 *   roles.create.scope.loading = Loading tenants...
 *   roles.create.scope.own = My own tenant
 *   roles.create.submit = Create Role
 *   roles.create.submitting = Creating...
 *   roles.create.subtitle = Add a new role to your system with permissions.
 *   roles.create.success = Role created successfully
 *   roles.create.title = Create New Role
 *   roles.create.validation.descriptionRequired = Description is required
 *   roles.create.validation.nameRequired = Name is required
 * @i18n-keys common
 *   ui.dialog.close = Close
 */

import { useMemo, useState, useEffect } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@amroksaleh/ui/dialog';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@amroksaleh/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@amroksaleh/ui/select';
import {
  Form,
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormMessage,
} from '@amroksaleh/ui/form';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import { PermissionCheckbox } from './permission-checkbox';
import type {
  Permission,
  RoleScope,
  RoleScopeSeam,
  RoleTenantOption,
  RolesAdapter,
  RolesTranslate,
} from './types';

/** The Select's own value for "the caller's own tenant" — the default. */
const OWN_SCOPE = 'own';
/** The Select's own value for "a global base role". */
const GLOBAL_SCOPE = 'global';

/**
 * Translate the picker's string value into the adapter's {@link RoleScope}.
 *
 * `'own'` becomes `undefined` rather than a third enum value on the wire: the
 * create request then carries no ownership fields at all, which is byte for byte
 * what every client sent before #888 and what the server reads as "the caller's
 * own tenant".
 */
function toScope(value: string): RoleScope | undefined {
  if (value === OWN_SCOPE) return undefined;
  if (value === GLOBAL_SCOPE) return GLOBAL_SCOPE;
  const tenantId = Number(value);
  return Number.isInteger(tenantId) ? tenantId : undefined;
}

// Built from `t` rather than declared at module scope: a validation message is
// user-facing text like any other, and a schema frozen at import time would
// always speak English.
const buildCreateRoleSchema = (t: RolesTranslate) =>
  z.object({
    name: z.string().min(1, t('roles.create.validation.nameRequired', 'Name is required')),
    description: z
      .string()
      .min(1, t('roles.create.validation.descriptionRequired', 'Description is required')),
    permissionIds: z.array(z.number()),
  });

type CreateRoleFormData = z.infer<ReturnType<typeof buildCreateRoleSchema>>;

interface CreateRoleModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess: () => void;
  /** Injected data-source adapter. */
  adapter: RolesAdapter;
  /** Injected translator (resolved by RolesScreen). */
  t: RolesTranslate;
  /** Optional notifier. */
  onNotify?: (message: string, type: 'success' | 'error') => void;
  /** Optional prefill (used by "Clone role"); resets the form when the modal opens. */
  initial?: { name: string; description: string; permissionIds: number[] };
  /**
   * Cross-tenant create seam (#888); absent ⇒ no picker and no scope fields on
   * the request, which is exactly the pre-#888 behaviour.
   */
  scope?: RoleScopeSeam;
}

export function CreateRoleModal({
  isOpen,
  onOpenChange,
  onSuccess,
  adapter,
  t,
  onNotify,
  initial,
  scope,
}: CreateRoleModalProps) {
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [permissions, setPermissions] = useState<Permission[]>([]);
  const [isLoadingPermissions, setIsLoadingPermissions] = useState(false);
  const [tenants, setTenants] = useState<RoleTenantOption[]>([]);
  const [isLoadingTenants, setIsLoadingTenants] = useState(false);
  // Kept OUTSIDE the zod form: it is not a field of the role, it is where the
  // role goes, and every value it can hold is already valid — the picker cannot
  // express the combinations the server rejects.
  const [selectedScope, setSelectedScope] = useState<string>(OWN_SCOPE);

  const schema = useMemo(() => buildCreateRoleSchema(t), [t]);

  const form = useForm<CreateRoleFormData>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: '',
      description: '',
      permissionIds: [],
    },
  });

  useEffect(() => {
    if (!isOpen || permissions.length > 0) return;
    let cancelled = false;
    setIsLoadingPermissions(true);
    adapter
      .listPermissions()
      .then((data) => {
        if (!cancelled) setPermissions(data);
      })
      .catch((error: unknown) => {
        if (cancelled) return;
        const message =
          error instanceof Error && error.message
            ? error.message
            : t('roles.create.permissionsError', 'Failed to fetch permissions');
        onNotify?.(message, 'error');
      })
      .finally(() => {
        if (!cancelled) setIsLoadingPermissions(false);
      });
    return () => {
      cancelled = true;
    };
    // Only re-run on open; t/onNotify/adapter identity changes must not refetch.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, permissions.length]);

  // The tenant list is fetched only when a host supplied the scope seam — i.e.
  // only for a caller who may actually choose — and only when the modal opens,
  // so the roles LIST does not pay for a picker nobody has asked for yet.
  useEffect(() => {
    if (!isOpen || scope === undefined || tenants.length > 0) return;
    let cancelled = false;
    setIsLoadingTenants(true);
    scope
      .loadTenants()
      .then((data) => {
        if (!cancelled) setTenants(data);
      })
      .catch((error: unknown) => {
        if (cancelled) return;
        const message =
          error instanceof Error && error.message
            ? error.message
            : t('roles.create.scope.error', 'Failed to load tenants');
        onNotify?.(message, 'error');
      })
      .finally(() => {
        if (!cancelled) setIsLoadingTenants(false);
      });
    return () => {
      cancelled = true;
    };
    // Only re-run on open; t/onNotify/scope identity changes must not refetch.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, scope === undefined, tenants.length]);

  // On open, seed the form: a clone prefills name/description/permissions, a
  // plain create resets to blanks. The scope resets too — a modal reopened after
  // a create for tenant 7 must not silently still be pointed at tenant 7.
  useEffect(() => {
    if (isOpen) {
      form.reset({
        name: initial?.name ?? '',
        description: initial?.description ?? '',
        permissionIds: initial?.permissionIds ?? [],
      });
      setSelectedScope(OWN_SCOPE);
    }
  }, [isOpen, initial, form]);

  const onSubmit = async (data: CreateRoleFormData) => {
    try {
      setIsSubmitting(true);
      const targetScope = scope === undefined ? undefined : toScope(selectedScope);
      await adapter.createRole({
        name: data.name,
        description: data.description,
        permissions: data.permissionIds,
        ...(targetScope !== undefined ? { scope: targetScope } : {}),
      });
      onNotify?.(t('roles.create.success', 'Role created successfully'), 'success');
      form.reset();
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error && error.message
          ? error.message
          : t('roles.create.error', 'Failed to create role');
      onNotify?.(message, 'error');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handlePermissionChange = (selectedIds: number[]) => {
    form.setValue('permissionIds', selectedIds);
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto" closeLabel={t('ui.dialog.close', 'Close')}>
        <DialogHeader>
          <DialogTitle>{t('roles.create.title', 'Create New Role')}</DialogTitle>
          <DialogDescription>
            {t('roles.create.subtitle', 'Add a new role to your system with permissions.')}
          </DialogDescription>
        </DialogHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
            <FormField
              control={form.control}
              name="name"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('roles.create.name.label', 'Role Name')}</FormLabel>
                  <FormControl>
                    <Input
                      placeholder={t('roles.create.name.placeholder', 'e.g., Editor')}
                      {...field}
                    />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <FormField
              control={form.control}
              name="description"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('roles.create.description.label', 'Description')}</FormLabel>
                  <FormControl>
                    <Input
                      placeholder={t('roles.create.description.placeholder', 'Role description')}
                      {...field}
                    />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            {/*
              #888 — where the role LIVES, chosen explicitly instead of inferred
              from whoever happens to be logged in. Rendered only when the host
              supplied the seam, which in practice means a system-tenant
              operator: for everyone else the answer is "my own tenant", there is
              nothing to choose, and a disabled control explaining that would be
              noise on the screen of every tenant administrator in the product.
            */}
            {scope !== undefined && (
              <div className="space-y-2">
                <FormLabel htmlFor="role-create-scope">
                  {t('roles.create.scope.label', 'Create this role in')}
                </FormLabel>
                <Select value={selectedScope} onValueChange={setSelectedScope}>
                  <SelectTrigger id="role-create-scope" data-testid="role-create-scope">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value={OWN_SCOPE}>
                      {t('roles.create.scope.own', 'My own tenant')}
                    </SelectItem>
                    <SelectItem value={GLOBAL_SCOPE}>
                      {t('roles.create.scope.global', 'Global — shared by every tenant')}
                    </SelectItem>
                    {tenants.map((tenant) => (
                      <SelectItem key={tenant.id} value={String(tenant.id)}>
                        {tenant.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {isLoadingTenants && (
                  <p className="text-xs text-muted-foreground">
                    {t('roles.create.scope.loading', 'Loading tenants...')}
                  </p>
                )}
                {selectedScope === GLOBAL_SCOPE && (
                  <p
                    className="text-xs text-muted-foreground"
                    data-testid="role-create-global-hint"
                  >
                    {t(
                      'roles.create.scope.globalHint',
                      'A global role is a single role every tenant uses. Editing it later changes it for all of them.'
                    )}
                  </p>
                )}
              </div>
            )}

            <div className="space-y-2">
              <FormLabel>{t('roles.create.permissions.label', 'Permissions')}</FormLabel>
              {isLoadingPermissions ? (
                <div className="text-sm text-muted-foreground py-4 text-center">
                  {t('roles.create.permissions.loading', 'Loading permissions...')}
                </div>
              ) : (
                <PermissionCheckbox
                  permissions={permissions}
                  selectedIds={form.watch('permissionIds')}
                  onChange={handlePermissionChange}
                  t={t}
                />
              )}
            </div>

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => onOpenChange(false)}
              >
                {t('roles.create.cancel', 'Cancel')}
              </Button>
              <Button type="submit" disabled={isSubmitting || isLoadingPermissions}>
                {isSubmitting
                  ? t('roles.create.submitting', 'Creating...')
                  : t('roles.create.submit', 'Create Role')}
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
}
