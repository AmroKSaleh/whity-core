'use client';

import { useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { LICENSING_MANAGE, LICENSING_ISSUE } from '@/lib/capabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { Badge } from '@amroksaleh/ui/badge';
import { Button } from '@amroksaleh/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { useDateDisplay } from '@amroksaleh/features/datetime';
import { useTranslation } from '@amroksaleh/features/i18n';

/**
 * LICENSED DEVICES, AND THE CODES THAT ACTIVATE THEM.
 *
 * The API for this shipped months before the screen did, which meant the whole
 * feature was real and unusable: provisioning a unit or issuing a code required
 * curl, and sales do not have curl. A capability nobody can reach is a
 * capability that does not exist.
 *
 * ── Three jobs, three permissions ───────────────────────────────────────────
 *
 * Reading the estate, importing stock, and MINTING A CODE are deliberately
 * separate. The last is a commercial act — something was sold — and a warehouse
 * hand unpacking boxes has no business performing it. The buttons follow the
 * permissions rather than the other way round.
 *
 * ── The code is shown once ──────────────────────────────────────────────────
 *
 * It is stored canonically and never retrievable in full again, the same
 * contract as a generated API token. So the dialog that reveals it says so, and
 * says it before the person closes the only window that will ever contain it.
 *
 * Bulk import is a TEXTAREA, not a file picker: stock arrives as a column
 * pasted out of a spreadsheet far more often than as a tidy CSV, and a paste
 * box accepts both without anybody converting anything.
 */

interface LicensedDevice {
  id: number;
  serial_number: string;
  label: string | null;
  status: string;
  provisioned_at: string | null;
  activated_at: string | null;
  last_seen_at: string | null;
}

interface IssuedCode {
  id: number;
  code: string;
  max_redemptions: number;
  expires_at: string | null;
}

export default function LicensingPage() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const { hasPermission } = useCapabilities();
  const t = useTranslation('admin');
  const dates = useDateDisplay();

  const canManage = hasPermission(LICENSING_MANAGE);
  const canIssue = hasPermission(LICENSING_ISSUE);

  const { data, loading, error, refetch } = useFetch(async () => {
    const res = await apiClient('/api/v1/licensing/devices');
    if (!res.ok) {
      throw new Error(t('licensing.error.load', 'Failed to load devices'));
    }
    return ((await res.json()).data ?? []) as LicensedDevice[];
  }, [apiClient]);

  const [importing, setImporting] = useState(false);
  const [serials, setSerials] = useState('');
  const [busy, setBusy] = useState(false);
  const [issuedFor, setIssuedFor] = useState<LicensedDevice | null>(null);
  const [issued, setIssued] = useState<IssuedCode | null>(null);

  const devices = data ?? [];

  const provision = async () => {
    // One per line, blanks dropped. The server skips serials it already has
    // rather than failing the batch, because re-pasting a spreadsheet is the
    // normal way this goes wrong.
    const list = serials
      .split(/[\r\n,;\t]+/)
      .map((s) => s.trim())
      .filter((s) => s !== '');

    if (list.length === 0) {
      addToast(t('licensing.import.empty', 'Paste at least one serial number.'), 'error');
      return;
    }

    setBusy(true);
    try {
      const res = await apiClient('/api/v1/licensing/devices', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ serial_numbers: list }),
      });

      if (!res.ok) {
        addToast(t('licensing.import.failed', 'Could not import those serials.'), 'error');
        return;
      }

      const body = await res.json();
      // BOTH NUMBERS, ALWAYS. "Imported 400" when 100 were already present
      // reads as a bigger delivery than arrived; the skipped count is how a
      // partial import stays legible rather than mysterious.
      addToast(
        t('licensing.import.done', 'Imported {created}, already present {existing}.')
          .replace('{created}', String(body.created ?? 0))
          .replace('{existing}', String(body.already_present ?? 0)),
        'success'
      );
      setSerials('');
      setImporting(false);
      void refetch();
    } finally {
      setBusy(false);
    }
  };

  const issueCode = async (device: LicensedDevice) => {
    setBusy(true);
    try {
      const res = await apiClient('/api/v1/licensing/codes', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ licensed_device_id: device.id }),
      });

      if (!res.ok) {
        addToast(t('licensing.issue.failed', 'Could not issue a code.'), 'error');
        return;
      }

      setIssued((await res.json()) as IssuedCode);
      setIssuedFor(device);
    } finally {
      setBusy(false);
    }
  };

  const statusBadge = (status: string) => {
    if (status === 'active') {
      return <Badge variant="success">{t('licensing.status.active', 'In service')}</Badge>;
    }
    if (status === 'retired') {
      return <Badge variant="secondary">{t('licensing.status.retired', 'Retired')}</Badge>;
    }
    return <Badge variant="secondary">{t('licensing.status.provisioned', 'Stock')}</Badge>;
  };

  const columns: DataTableColumn<LicensedDevice>[] = [
    {
      accessorKey: 'serial_number',
      header: t('licensing.column.serial', 'Serial number'),
      enableSorting: true,
      cell: (row) => <span className="font-medium">{row.serial_number}</span>,
    },
    {
      accessorKey: 'label',
      header: t('licensing.column.label', 'Label'),
      cell: (row) => <span className="text-muted-foreground">{row.label ?? ''}</span>,
    },
    {
      accessorKey: 'status',
      header: t('licensing.column.status', 'Status'),
      cell: (row) => statusBadge(row.status),
    },
    {
      accessorKey: 'activated_at',
      header: t('licensing.column.activated', 'Activated'),
      enableSorting: true,
      // THE DATE THAT DECIDES THE BILL on the default basis, which is why it is
      // the one column of the three timestamps shown.
      cell: (row) => (
        <span className="text-muted-foreground">{dates.date(row.activated_at) ?? ''}</span>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <AdminHeader
        title={t('licensing.title', 'Licensed devices')}
        description={t(
          'licensing.description',
          'Hardware this workspace is licensed for, and the codes that activate it.'
        )}
        action={
          canManage ? (
            <Button onClick={() => setImporting(true)} data-testid="import-serials">
              {t('licensing.import.open', 'Import serials')}
            </Button>
          ) : undefined
        }
      />

      {/* A LOAD FAILURE IS SAID OUT LOUD: an empty table and a failed request
          look identical, and "no devices" is a far more comfortable thing to
          read than "we could not fetch them". */}
      {error !== null && (
        <p className="text-sm text-destructive" data-testid="devices-unreadable">
          {t('licensing.error.load', 'Failed to load devices')}
        </p>
      )}

      <DataTable
        columns={columns}
        data={devices}
        isLoading={loading}
        rowActions={
          canIssue
            ? (row: LicensedDevice) => (
                <Button
                  size="sm"
                  onClick={() => void issueCode(row)}
                  disabled={busy || row.status === 'retired'}
                  data-testid={`issue-${row.id}`}
                >
                  {t('licensing.issue.action', 'Issue code')}
                </Button>
              )
            : undefined
        }
        emptyState={{
          title: t('licensing.empty.title', 'No devices yet'),
          description: t(
            'licensing.empty.description',
            'Import the serial numbers that arrived, then issue an activation code for each unit you sell.'
          ),
        }}
      />

      <Dialog open={importing} onOpenChange={(open) => !open && setImporting(false)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('licensing.import.title', 'Import serial numbers')}</DialogTitle>
            <DialogDescription>
              {t(
                'licensing.import.help',
                'One per line. Serials this workspace already has are skipped, so re-pasting a list is safe.'
              )}
            </DialogDescription>
          </DialogHeader>

          <textarea
            className="min-h-40 w-full rounded-md border bg-transparent p-2 font-mono text-sm"
            value={serials}
            onChange={(e) => setSerials(e.target.value)}
            data-testid="serials-input"
            dir="ltr"
            placeholder="SN-000001&#10;SN-000002"
          />

          <DialogFooter>
            <Button variant="secondary" onClick={() => setImporting(false)} disabled={busy}>
              {t('common.cancel', 'Cancel')}
            </Button>
            <Button onClick={() => void provision()} disabled={busy} data-testid="confirm-import">
              {busy
                ? t('licensing.import.working', 'Importing…')
                : t('licensing.import.confirm', 'Import')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog
        open={issued !== null}
        onOpenChange={(open) => {
          if (!open) {
            setIssued(null);
            setIssuedFor(null);
          }
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('licensing.issued.title', 'Activation code')}</DialogTitle>
            <DialogDescription>
              {/* SAID BEFORE THEY CLOSE THE WINDOW, not after. The code is stored
                  canonically and is never retrievable in full again — the same
                  contract as a generated API token — so this is the only time it
                  will ever be on screen. */}
              {t(
                'licensing.issued.once',
                'Copy this now — it is shown once and cannot be retrieved again.'
              )}
            </DialogDescription>
          </DialogHeader>

          <p
            className="select-all rounded-md border bg-muted p-3 text-center font-mono text-lg tracking-widest"
            data-testid="issued-code"
            dir="ltr"
          >
            {issued?.code ?? ''}
          </p>

          {issuedFor !== null && (
            <p className="text-sm text-muted-foreground">
              {t('licensing.issued.for', 'For')} <span className="font-medium">{issuedFor.serial_number}</span>
            </p>
          )}

          <DialogFooter>
            <Button
              onClick={() => {
                setIssued(null);
                setIssuedFor(null);
                void refetch();
              }}
            >
              {t('licensing.issued.done', 'Done')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
