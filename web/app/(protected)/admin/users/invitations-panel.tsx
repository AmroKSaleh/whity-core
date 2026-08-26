'use client';

import { useMemo, useState } from 'react';
import { api } from '@/lib/api/client';
import type { components } from '@/lib/api/schema';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { USERS_READ, USERS_WRITE } from '@/lib/capabilities';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { Button } from '@amroksaleh/ui/button';
import { Badge } from '@amroksaleh/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@amroksaleh/ui/dropdown-menu';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@amroksaleh/ui/form';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@amroksaleh/ui/select';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import { IconMenu2, IconSend } from '@tabler/icons-react';
import { useTranslation, type TranslateFn } from '@amroksaleh/features/i18n';
import { useDateDisplay } from '@amroksaleh/features/datetime';
import { useRoleOptions } from './use-role-options';

/**
 * Pending invitations, on the Users screen rather than a page of its own
 * (WHIT-417).
 *
 * Deliberately here: an invitation is a user who has not arrived yet, and an
 * administrator asking "is Sara in?" should not have to know whether she was
 * added or invited to find out. It also puts Invite next to Create User, which
 * is the actual choice being made — the same decision with and without an
 * administrator typing somebody's password.
 *
 * WHAT THIS SCREEN CANNOT SHOW, and why: nothing here says whether an invited
 * address already has an account elsewhere on the platform. The API does not
 * return it (see InvitationsApiHandler), because an administrator may type any
 * address into the invite form and an "already registered" badge would turn
 * this table into an account-enumeration tool.
 */

type Invitation = components['schemas']['InvitationItem'];

/**
 * The status labels reach `t()` through this table rather than as literals at
 * the call site, which no static scanner can read — so they are declared here
 * and the extractor takes the catalogue from this block. The English stays on
 * the record as the runtime fallback.
 *
 * `expired` is not a stored status: the API derives it from `expires_at` so
 * every client says the same thing about the same row.
 *
 * @i18n-keys admin
 *   invitations.status.pending = Pending
 *   invitations.status.accepted = Accepted
 *   invitations.status.revoked = Revoked
 *   invitations.status.superseded = Superseded
 *   invitations.status.expired = Expired
 */
const STATUS_LABELS: Record<Invitation['status'], { key: string; english: string }> = {
  pending: { key: 'invitations.status.pending', english: 'Pending' },
  accepted: { key: 'invitations.status.accepted', english: 'Accepted' },
  revoked: { key: 'invitations.status.revoked', english: 'Revoked' },
  superseded: { key: 'invitations.status.superseded', english: 'Superseded' },
  expired: { key: 'invitations.status.expired', english: 'Expired' },
};

const buildInviteSchema = (t: TranslateFn) =>
  z.object({
    email: z.string().email(t('invitations.invite.validation.email', 'Invalid email address')),
    role: z.string().min(1, t('invitations.invite.validation.role', 'Role is required')),
  });

type InviteFormData = z.infer<ReturnType<typeof buildInviteSchema>>;

export function InvitationsPanel() {
  const { addToast } = useToast();
  const { hasPermission } = useCapabilities();
  const t = useTranslation('admin');
  const dates = useDateDisplay();

  const canRead = hasPermission(USERS_READ);
  const canWrite = hasPermission(USERS_WRITE);

  const [isInviteOpen, setIsInviteOpen] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);

  const {
    data,
    loading: isLoading,
    refetch,
  } = useFetch(async () => {
    if (!canRead) {
      return [] as Invitation[];
    }
    const { data: body, error } = await api.GET('/api/v1/invitations');
    if (error !== undefined || body === undefined) {
      throw new Error(t('invitations.error.load', 'Failed to load invitations'));
    }
    return body.data;
  }, [canRead]);

  const invitations = data ?? [];

  const handleResend = async (invitation: Invitation) => {
    setBusyId(invitation.id);
    try {
      const { error, response } = await api.POST('/api/v1/invitations/{id}/resend', {
        params: { path: { id: invitation.id } },
      });
      if (error !== undefined || !response.ok) {
        throw new Error(
          error?.error ?? t('invitations.resend.error', 'Failed to resend the invitation')
        );
      }
      addToast(
        t('invitations.resend.success', 'A new invitation link was sent to {email}.', {
          email: invitation.email,
        }),
        'success'
      );
      refetch();
    } catch (err) {
      addToast(
        err instanceof Error
          ? err.message
          : t('invitations.resend.error', 'Failed to resend the invitation'),
        'error'
      );
    } finally {
      setBusyId(null);
    }
  };

  const handleRevoke = async (invitation: Invitation) => {
    setBusyId(invitation.id);
    try {
      const { error, response } = await api.DELETE('/api/v1/invitations/{id}', {
        params: { path: { id: invitation.id } },
      });
      if (error !== undefined || !response.ok) {
        throw new Error(
          error?.error ?? t('invitations.revoke.error', 'Failed to revoke the invitation')
        );
      }
      addToast(
        t('invitations.revoke.success', 'The invitation for {email} was revoked.', {
          email: invitation.email,
        }),
        'success'
      );
      refetch();
    } catch (err) {
      addToast(
        err instanceof Error
          ? err.message
          : t('invitations.revoke.error', 'Failed to revoke the invitation'),
        'error'
      );
    } finally {
      setBusyId(null);
    }
  };

  const statusLabel = (status: Invitation['status']): string =>
    t(STATUS_LABELS[status].key, STATUS_LABELS[status].english);

  const columns: DataTableColumn<Invitation>[] = [
    {
      accessorKey: 'email',
      header: t('invitations.table.email', 'Email'),
      enableSorting: true,
      enableColumnFilter: true,
    },
    {
      accessorKey: 'role_name',
      header: t('invitations.table.role', 'Role'),
      enableSorting: true,
    },
    {
      accessorKey: 'status',
      header: t('invitations.table.status', 'Status'),
      enableSorting: true,
      cell: (invitation) => (
        <Badge variant={invitation.status === 'pending' ? 'outline' : 'secondary'}>
          {statusLabel(invitation.status)}
        </Badge>
      ),
    },
    // #1068: both go together, and both were rendering the raw wire string
    // before it — `accessorKey` with no `cell` is `String(value)`. The STATUS
    // column above still says whether an invitation is live, which is the
    // question this panel exists to answer; the resend and withdraw actions
    // are gated on that status and not on a date.
    ...dates.dateColumns<Invitation>([
      {
        id: 'expires_at',
        header: t('invitations.table.expiresAt', 'Expires'),
        value: (invitation) => invitation.expires_at,
        enableSorting: true,
      },
      {
        id: 'created_at',
        header: t('invitations.table.createdAt', 'Sent'),
        value: (invitation) => invitation.created_at,
        enableSorting: true,
      },
    ]),
  ];

  const rowActions = (invitation: Invitation) => {
    // Only a live invitation can be resent or withdrawn; the closed states are
    // history, kept visible so "we did invite them" stays answerable.
    if (!canWrite || invitation.status !== 'pending') return null;
    return (
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            variant="ghost"
            size="icon-sm"
            disabled={busyId === invitation.id}
            aria-label={t('invitations.rowActions.label', 'Invitation actions')}
          >
            <IconMenu2 />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem onClick={() => void handleResend(invitation)}>
            {t('invitations.rowActions.resend', 'Resend')}
          </DropdownMenuItem>
          <DropdownMenuItem variant="destructive" onClick={() => void handleRevoke(invitation)}>
            {t('invitations.rowActions.revoke', 'Revoke')}
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    );
  };

  if (!canRead) {
    return null;
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div className="space-y-1.5">
          <CardTitle>{t('invitations.title', 'Invitations')}</CardTitle>
          <CardDescription>
            {t(
              'invitations.description',
              'People who have been invited but have not accepted yet. An invitation link is single-use and expires.'
            )}
          </CardDescription>
        </div>
        {canWrite && (
          <Button className="gap-2 shrink-0" onClick={() => setIsInviteOpen(true)}>
            <IconSend />
            {t('invitations.invite', 'Invite')}
          </Button>
        )}
      </CardHeader>
      <CardContent>
        <DataTable
          ariaLabel={t('users.invitations.table.label', 'Pending invitations')}
          columns={columns}
          data={invitations}
          getRowId={(invitation) => String(invitation.id)}
          rowActions={rowActions}
          isLoading={isLoading}
          pagination={{ pageSize: 10 }}
        />
      </CardContent>

      <InviteModal
        isOpen={isInviteOpen}
        onOpenChange={setIsInviteOpen}
        onSuccess={() => {
          setIsInviteOpen(false);
          refetch();
        }}
      />
    </Card>
  );
}

interface InviteModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess: () => void;
}

/**
 * Invite by email. No password field, deliberately — the point of the whole
 * flow is that nobody but the invitee ever chooses or handles their password.
 */
function InviteModal({ isOpen, onOpenChange, onSuccess }: InviteModalProps) {
  const { addToast } = useToast();
  const t = useTranslation('admin');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const { roleOptions, isLoadingRoles } = useRoleOptions(isOpen);

  const schema = useMemo(() => buildInviteSchema(t), [t]);
  const form = useForm<InviteFormData>({
    resolver: zodResolver(schema),
    defaultValues: { email: '', role: '' },
  });

  const onSubmit = async (values: InviteFormData) => {
    try {
      setIsSubmitting(true);
      const { error, response } = await api.POST('/api/v1/invitations', {
        body: { email: values.email, role: values.role },
      });

      if (error !== undefined || !response.ok) {
        throw new Error(
          error?.error ?? t('invitations.invite.error', 'Failed to send the invitation')
        );
      }

      addToast(
        t('invitations.invite.success', 'An invitation was sent to {email}.', {
          email: values.email,
        }),
        'success'
      );
      form.reset();
      onSuccess();
    } catch (err) {
      addToast(
        err instanceof Error
          ? err.message
          : t('invitations.invite.error', 'Failed to send the invitation'),
        'error'
      );
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('invitations.invite.title', 'Invite someone')}</DialogTitle>
          <DialogDescription>
            {t(
              'invitations.invite.description',
              'They receive a single-use link and choose their own password. If they already have an account, this workspace is added to it.'
            )}
          </DialogDescription>
        </DialogHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
            <FormField
              control={form.control}
              name="email"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('invitations.invite.email.label', 'Email')}</FormLabel>
                  <FormControl>
                    <Input type="email" placeholder="sara@example.com" {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <FormField
              control={form.control}
              name="role"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('invitations.invite.role.label', 'Role')}</FormLabel>
                  <Select onValueChange={field.onChange} value={field.value}>
                    <FormControl>
                      <SelectTrigger>
                        <SelectValue
                          placeholder={
                            isLoadingRoles
                              ? t('invitations.invite.role.loading', 'Loading roles…')
                              : t('invitations.invite.role.placeholder', 'Select a role')
                          }
                        />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      {roleOptions.map((role) => (
                        <SelectItem key={role.value} value={role.value}>
                          {role.label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <FormMessage />
                </FormItem>
              )}
            />

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                {t('invitations.invite.cancel', 'Cancel')}
              </Button>
              <Button type="submit" disabled={isSubmitting}>
                {isSubmitting
                  ? t('invitations.invite.submit.pending', 'Sending…')
                  : t('invitations.invite.submit', 'Send invitation')}
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
}
