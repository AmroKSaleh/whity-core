'use client';

import { useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { BILLING_PAY } from '@/lib/capabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { Button } from '@amroksaleh/ui/button';
import { Badge } from '@amroksaleh/ui/badge';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { useTranslation } from '@amroksaleh/features/i18n';
import { formatMoney } from '@amroksaleh/ui/money/currency';
import { navigateExternal } from '@/lib/external-navigate';
import { AvailablePlans } from './available-plans';

/**
 * BILLING: what this tenant owes, and how to pay it.
 *
 * The tenant's own account, not the operator's catalogue — that is
 * `/admin/billing`. Everything here is scoped to the caller's tenant by the
 * server; nothing on this screen can reach another tenant's invoices.
 *
 * AMOUNTS ARE FORMATTED FROM MINOR UNITS, never divided by 100. The API sends
 * both the integer and a preformatted figure precisely because the Jordanian
 * dinar has three decimal places: 5000 fils is 5.000 JOD, and a screen that
 * divides by 100 shows every customer an amount ten times too large. Here the
 * integer goes through `formatMoney`, which knows the exponent and hands the
 * locale to `Intl` — so the Arabic build gets Arabic-Indic digits and the right
 * symbol placement without this file knowing anything about Arabic.
 *
 * RTL COMES FROM LOGICAL PROPERTIES, not from a direction check. Every spacing
 * and alignment class here is `ms-`/`me-`/`text-start`/`text-end` rather than
 * left/right, so the layout mirrors itself under `DirectionProvider` with no
 * branch to keep in step. A screen that special-cased RTL would drift the first
 * time somebody added a margin.
 *
 * PAYING IS ONE FLOW FOR EVERY RAIL. The response says what to do — send the
 * browser somewhere, show a reference to type into a banking app, or report it
 * already settled — and this branches on that once. When the card provider is
 * chosen it produces `redirect`, which is already handled below.
 *
 * A FAILED ATTEMPT IS SHOWN, not hidden. "Why does it say I have not paid" is
 * answered by the attempt that failed and the bank's reason for it; a history
 * of successes only cannot answer it, and the customer then asks a human.
 */

interface Invoice {
  id: number;
  number: string | null;
  status: string;
  currency: string;
  total_minor: number;
  amount_paid_minor: number;
  balance_minor: number;
  total_formatted: string;
  balance_formatted: string;
  issued_at: string | null;
  due_at: string | null;
  paid_at: string | null;
  seller_name: string;
  buyer_name: string;
}

interface InvoiceLine {
  id: number;
  description: string;
  quantity: number;
  unit_amount_minor: number;
  tax_minor: number;
  total_minor: number;
}

interface PaymentRow {
  id: number;
  provider: string;
  status: string;
  amount_minor: number;
  currency: string;
  failure_reason: string | null;
  occurred_at: string;
}

interface InvoiceDetail extends Invoice {
  lines: InvoiceLine[];
  payments: PaymentRow[];
}

interface PaymentMethod {
  provider: string;
  uses_redirect: boolean;
  uses_push_transfer: boolean;
  supports_unattended_charge: boolean;
}

interface Instruction {
  kind: 'redirect' | 'transfer' | 'settled';
  provider: string;
  reference?: string;
  redirect_url?: string;
  display?: Record<string, string>;
  settled?: boolean;
}

/**
 * The keys the three computed `t()` calls on this page can reach.
 *
 * Declared rather than suppressed, because they CAN be enumerated: an invoice
 * status comes from a database CHECK, a transaction status from another, and a
 * provider name from the registry. All three are closed sets, so the extractor
 * gets the real list and a translator sees every string.
 *
 * @i18n-keys admin
 *   billing.invoices.status.draft = Draft
 *   billing.invoices.status.open = Unpaid
 *   billing.invoices.status.paid = Paid
 *   billing.invoices.status.void = Cancelled
 *   billing.invoices.status.uncollectible = Written off
 *   billing.payment.status.succeeded = Received
 *   billing.payment.status.failed = Failed
 *   billing.payment.status.pending = In progress
 *   billing.payment.status.refunded = Refunded
 *   billing.pay.method.cliq = CliQ bank transfer
 *   billing.pay.method.mock = Test payment method
 *   billing.pay.method.card = Card
 */

/** Statuses a customer sees, and how loudly. */
const STATUS_VARIANT: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
  paid: 'secondary',
  open: 'default',
  draft: 'outline',
  void: 'outline',
  uncollectible: 'destructive',
};

export default function BillingPage() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const { hasPermission } = useCapabilities();
  const t = useTranslation('admin');
  const canPay = hasPermission(BILLING_PAY);

  const { data, loading, error, refetch } = useFetch(async () => {
    const res = await apiClient('/api/v1/billing/invoices');
    if (!res.ok) {
      throw new Error(t('billing.invoices.error.load', 'Failed to load invoices'));
    }
    return ((await res.json()).data ?? []) as Invoice[];
  }, [apiClient]);

  const [detail, setDetail] = useState<InvoiceDetail | null>(null);
  const [paying, setPaying] = useState<Invoice | null>(null);

  const openDetail = async (invoice: Invoice) => {
    const res = await apiClient(`/api/v1/billing/invoices/${invoice.id}`);
    if (!res.ok) {
      addToast(t('billing.invoices.error.detail', 'Could not open that invoice'), 'error');
      return;
    }
    setDetail((await res.json()).data as InvoiceDetail);
  };

  const money = (minor: number, currency: string) => formatMoney(minor, currency);

  const columns: DataTableColumn<Invoice>[] = [
    {
      accessorKey: 'number',
      header: t('billing.invoices.column.number', 'Invoice'),
      enableSorting: true,
      cell: (row) => (
        <span className="font-medium">
          {row.number ?? t('billing.invoices.draftLabel', 'Draft')}
        </span>
      ),
    },
    {
      accessorKey: 'status',
      header: t('billing.invoices.column.status', 'Status'),
      enableSorting: true,
      cell: (row) => (
        <Badge variant={STATUS_VARIANT[row.status] ?? 'outline'}>
          {t(`billing.invoices.status.${row.status}`, row.status)}
        </Badge>
      ),
    },
    {
      accessorKey: 'total_minor',
      header: t('billing.invoices.column.total', 'Total'),
      cell: (row) => <span>{money(row.total_minor, row.currency)}</span>,
    },
    {
      accessorKey: 'balance_minor',
      header: t('billing.invoices.column.balance', 'Outstanding'),
      cell: (row) => (
        <span className={row.balance_minor > 0 ? 'font-medium' : 'text-muted-foreground'}>
          {money(row.balance_minor, row.currency)}
        </span>
      ),
    },
    {
      accessorKey: 'due_at',
      header: t('billing.invoices.column.due', 'Due'),
      enableSorting: true,
      cell: (row) => (
        <span className="text-muted-foreground">{row.due_at?.slice(0, 10) ?? '—'}</span>
      ),
    },
  ];

  // The pay button appears only on an invoice that can actually take a payment
  // — open, with something still owed. Showing it on a paid one and refusing
  // afterwards would be a button whose only outcome is an error.
  const rowActions = (row: Invoice) => (
    <div className="flex items-center gap-2">
      <Button variant="ghost" size="sm" onClick={() => void openDetail(row)}>
        {t('billing.invoices.action.view', 'View')}
      </Button>
      {canPay && row.status === 'open' && row.balance_minor > 0 && (
        <Button size="sm" onClick={() => setPaying(row)} data-testid={`pay-${row.id}`}>
          {t('billing.invoices.action.pay', 'Pay')}
        </Button>
      )}
    </div>
  );

  return (
    <div className="space-y-6">
      <AdminHeader
        title={t('billing.invoices.title', 'Billing')}
        description={t(
          'billing.invoices.description',
          'Your invoices, what is still owed, and how to pay.'
        )}
      />

      {/* WHAT CAN BE BOUGHT COMES FIRST, above the invoice history. This page is
          where a walled tenant is sent, and for one that has never paid the
          history below is empty — so the offer has to be the thing they see,
          not something under a table of nothing. It renders nothing at all on a
          deployment that sells nothing. */}
      <AvailablePlans />

      {/* A LOAD FAILURE IS SAID OUT LOUD. An empty table and a failed request
          look identical, and "no invoices" is a far more comforting thing to
          read than "we could not fetch them" — so a customer who owes money
          would be told the opposite of the truth. */}
      {error && (
        <p className="text-sm text-destructive" data-testid="invoices-unreadable">
          {t('billing.invoices.error.load', 'Failed to load invoices')}
        </p>
      )}

      <DataTable
        columns={columns}
        data={data ?? []}
        isLoading={loading}
        rowActions={rowActions}
        emptyState={{
          title: t('billing.invoices.empty', 'No invoices yet'),
          description: t(
            'billing.invoices.emptyDescription',
            'Invoices appear here once your subscription is billed.'
          ),
        }}
      />

      {detail && (
        <InvoiceDetailDialog
          invoice={detail}
          onClose={() => setDetail(null)}
          t={t}
          money={money}
        />
      )}

      {paying && (
        <PayInvoiceDialog
          invoice={paying}
          onClose={() => setPaying(null)}
          onPaid={() => {
            setPaying(null);
            void refetch();
          }}
          t={t}
          money={money}
        />
      )}
    </div>
  );
}

/**
 * One invoice in full.
 *
 * The payment history includes failures and pending attempts, with the
 * provider's reason where there is one — see the module note.
 */
function InvoiceDetailDialog({
  invoice,
  onClose,
  t,
  money,
}: {
  invoice: InvoiceDetail;
  onClose: () => void;
  t: (key: string, fallback: string) => string;
  money: (minor: number, currency: string) => string;
}) {
  return (
    <Dialog open onOpenChange={onClose}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>
            {invoice.number ?? t('billing.invoices.draftLabel', 'Draft')}
          </DialogTitle>
          <DialogDescription>
            {t('billing.detail.from', 'From')} {invoice.seller_name} ·{' '}
            {t('billing.detail.to', 'To')} {invoice.buyer_name}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <section>
            <h3 className="mb-2 text-sm font-medium">{t('billing.detail.lines', 'Lines')}</h3>
            <ul className="space-y-1 text-sm" data-testid="invoice-lines">
              {invoice.lines.map((line) => (
                <li key={line.id} className="flex items-baseline justify-between gap-4">
                  <span className="text-start">
                    {line.description}
                    {line.quantity > 1 && (
                      <span className="text-muted-foreground"> × {line.quantity}</span>
                    )}
                  </span>
                  <span className="shrink-0 tabular-nums">
                    {money(line.total_minor, invoice.currency)}
                  </span>
                </li>
              ))}
            </ul>
          </section>

          <section className="border-t pt-3 text-sm">
            <div className="flex items-baseline justify-between">
              <span className="font-medium">{t('billing.detail.total', 'Total')}</span>
              <span className="tabular-nums font-medium">
                {money(invoice.total_minor, invoice.currency)}
              </span>
            </div>
            <div className="flex items-baseline justify-between text-muted-foreground">
              <span>{t('billing.detail.paid', 'Paid')}</span>
              <span className="tabular-nums">
                {money(invoice.amount_paid_minor, invoice.currency)}
              </span>
            </div>
            <div className="flex items-baseline justify-between">
              <span>{t('billing.detail.outstanding', 'Outstanding')}</span>
              <span className="tabular-nums">
                {money(invoice.balance_minor, invoice.currency)}
              </span>
            </div>
          </section>

          <section>
            <h3 className="mb-2 text-sm font-medium">
              {t('billing.detail.payments', 'Payment history')}
            </h3>
            {invoice.payments.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                {t('billing.detail.noPayments', 'Nothing has been paid yet.')}
              </p>
            ) : (
              <ul className="space-y-1 text-sm" data-testid="invoice-payments">
                {invoice.payments.map((payment) => (
                  <li key={payment.id} className="flex items-baseline justify-between gap-4">
                    <span className="text-start">
                      <Badge
                        variant={
                          payment.status === 'succeeded'
                            ? 'secondary'
                            : payment.status === 'failed'
                              ? 'destructive'
                              : 'outline'
                        }
                        className="me-2"
                      >
                        {t(`billing.payment.status.${payment.status}`, payment.status)}
                      </Badge>
                      <span className="text-muted-foreground">
                        {payment.occurred_at.slice(0, 10)}
                      </span>
                      {/* The bank's own words, because "why does it say I have
                          not paid" is answered by the attempt that failed. */}
                      {payment.failure_reason && (
                        <span className="ms-2 text-destructive">{payment.failure_reason}</span>
                      )}
                    </span>
                    <span className="shrink-0 tabular-nums">
                      {money(payment.amount_minor, payment.currency)}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            {t('billing.action.close', 'Close')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/**
 * Choose a rail, then do what it says.
 *
 * ONE BRANCH, on `kind`. A per-rail dialog would need a new one for every
 * provider added; this needs none.
 */
function PayInvoiceDialog({
  invoice,
  onClose,
  onPaid,
  t,
  money,
}: {
  invoice: Invoice;
  onClose: () => void;
  onPaid: () => void;
  t: (key: string, fallback: string) => string;
  money: (minor: number, currency: string) => string;
}) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const [instruction, setInstruction] = useState<Instruction | null>(null);
  const [busy, setBusy] = useState(false);

  const { data: methods, loading } = useFetch(async () => {
    const res = await apiClient('/api/v1/billing/methods');
    if (!res.ok) {
      throw new Error(t('billing.pay.error.methods', 'Could not load payment methods'));
    }
    return ((await res.json()).data ?? []) as PaymentMethod[];
  }, [apiClient]);

  const start = async (provider: string) => {
    setBusy(true);
    try {
      const res = await apiClient(`/api/v1/billing/invoices/${invoice.id}/pay`, {
        method: 'POST',
        body: JSON.stringify({ provider }),
      });
      if (!res.ok) {
        addToast(t('billing.pay.error.start', 'The payment could not be started'), 'error');
        return;
      }

      const next = (await res.json()).data as Instruction;

      // The one branch. A future card provider lands in the first case.
      if (next.kind === 'redirect' && next.redirect_url) {
        // Through the shared helper, which re-checks https and — the reason it
        // is a module rather than an inline call — can be mocked, so the branch
        // no rail on this instance produces YET is still exercised.
        navigateExternal(next.redirect_url);
        return;
      }
      if (next.kind === 'settled') {
        addToast(t('billing.pay.settled', 'Payment received'), 'success');
        onPaid();
        return;
      }
      setInstruction(next);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog open onOpenChange={onClose}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('billing.pay.title', 'Pay this invoice')}</DialogTitle>
          <DialogDescription>
            {money(invoice.balance_minor, invoice.currency)} ·{' '}
            {invoice.number ?? t('billing.invoices.draftLabel', 'Draft')}
          </DialogDescription>
        </DialogHeader>

        {instruction === null ? (
          <div className="space-y-2">
            {loading ? (
              <p className="text-sm text-muted-foreground">
                {t('billing.pay.loadingMethods', 'Loading payment methods…')}
              </p>
            ) : (methods ?? []).length === 0 ? (
              // An honest empty state: no rail is configured, which is an
              // operator problem the customer cannot solve by trying again.
              <p className="text-sm text-muted-foreground" data-testid="no-payment-methods">
                {t(
                  'billing.pay.noMethods',
                  'No payment method is available. Please contact support.'
                )}
              </p>
            ) : (
              (methods ?? []).map((method) => (
                <Button
                  key={method.provider}
                  variant="outline"
                  className="w-full justify-start"
                  disabled={busy}
                  onClick={() => void start(method.provider)}
                  data-testid={`method-${method.provider}`}
                >
                  {t(`billing.pay.method.${method.provider}`, method.provider)}
                </Button>
              ))
            )}
          </div>
        ) : (
          <TransferInstructions instruction={instruction} t={t} />
        )}

        <DialogFooter>
          <Button variant="outline" onClick={instruction ? onPaid : onClose}>
            {instruction
              ? t('billing.action.done', 'Done')
              : t('billing.action.cancel', 'Cancel')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/**
 * What to type into a banking app.
 *
 * THE REFERENCE IS THE IMPORTANT THING ON THIS SCREEN — it is the only link
 * between the money and the invoice, and it is about to be re-typed by a human
 * on a phone. So it is large, monospaced, and `select-all`, and it is never
 * shown in a direction-dependent layout that could reverse it: `dir="ltr"` is
 * pinned on the reference itself, because a code made of Latin letters and
 * digits must not be mirrored when the interface is Arabic.
 */
function TransferInstructions({
  instruction,
  t,
}: {
  instruction: Instruction;
  t: (key: string, fallback: string) => string;
}) {
  return (
    <div className="space-y-4" data-testid="transfer-instructions">
      <p className="text-sm">
        {t(
          'billing.pay.transferIntro',
          'Send the amount from your own bank or wallet app to the alias below, and put this reference in the message field.'
        )}
      </p>

      {instruction.display?.alias && (
        <div>
          <p className="text-xs text-muted-foreground">{t('billing.pay.alias', 'Send to')}</p>
          <p className="font-mono text-lg select-all" dir="ltr">
            {instruction.display.alias}
          </p>
          {instruction.display.bank && (
            <p className="text-xs text-muted-foreground">{instruction.display.bank}</p>
          )}
        </div>
      )}

      <div>
        <p className="text-xs text-muted-foreground">
          {t('billing.pay.reference', 'Reference (type this exactly)')}
        </p>
        {/* dir="ltr" on the value itself: a Latin-and-digits code must not be
            mirrored when the surrounding interface is Arabic. */}
        <p
          className="font-mono text-xl font-semibold select-all"
          dir="ltr"
          data-testid="transfer-reference"
        >
          {instruction.reference}
        </p>
      </div>

      <p className="text-xs text-muted-foreground">
        {t(
          'billing.pay.transferPending',
          'Your invoice updates automatically once the transfer clears. This usually takes a few minutes.'
        )}
      </p>
    </div>
  );
}
