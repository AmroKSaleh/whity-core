<?php

declare(strict_types=1);

namespace Whity\Core\Payment;

use PDO;

/**
 * Where payment events become rows, exactly once each.
 *
 * THE WHOLE PROBLEM IN ONE SENTENCE: every provider redelivers, and the second
 * delivery of one charge must not credit the account again.
 *
 * The obvious implementation reads first — "have we seen this reference?" —
 * and inserts if not. It is wrong in the ordinary case rather than an exotic
 * one: a provider retrying on a timeout sends the same callback to two workers
 * at once, both read "no", and both insert. The window is small, and it is
 * open precisely when a provider is retrying, which is when the duplicate
 * matters most.
 *
 * So there is no read. One `INSERT … ON CONFLICT … DO UPDATE … RETURNING`
 * statement decides everything, on the row the write locks — the same shape,
 * and for the same reason, as {@see \Whity\Database\SequenceCounters}.
 *
 * A SETTLED PAYMENT NEVER BECOMES UNSETTLED
 * -----------------------------------------
 * The `DO UPDATE` carries `WHERE status = 'pending'`, and that condition is the
 * most important thing in this class. Providers do not deliver callbacks in
 * order. A `pending` redelivered after the `succeeded` that superseded it is
 * routine — it is the retry of an earlier notification finally getting through
 * — and an upsert without that guard would flip a paid invoice back to
 * unpaid. The tenant then gets a dunning email, and eventually loses access,
 * for a payment they made and we recorded.
 *
 * Only pending rows move. Anything terminal is left exactly as it is, and the
 * caller is told the event was a duplicate so it does not re-settle an invoice
 * that was already settled.
 *
 * A REFUND IS ITS OWN ROW, negative, with its own provider reference. It is not
 * a mutation of the payment it reverses, because the payment did happen and the
 * ledger's job is to say so.
 */
final class PaymentLedger
{
    /**
     * The event changed the ledger — either a new movement, or a pending one
     * becoming terminal. The caller re-evaluates the invoice.
     */
    public const APPLIED = 'applied';

    /**
     * Already known and already terminal; nothing changed, and the caller must
     * not act on it again.
     */
    public const DUPLICATE = 'duplicate';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Record one event.
     *
     * @param int  $tenantId  Resolved by the caller — a provider's callback
     *                        knows its own transaction, not our tenancy.
     * @param ?int $invoiceId Overrides the event's own, when the caller has
     *                        resolved it more reliably.
     *
     * WHY THERE IS NO 'inserted' VS 'resolved' DISTINCTION. It is knowable —
     * PostgreSQL exposes it as `xmax = 0` — and nothing would act on it. Both
     * outcomes mean the same thing to every caller: the ledger moved, so
     * re-evaluate the invoice. A distinction nothing uses is one that drifts,
     * and this one would have cost a PostgreSQL-only expression in a statement
     * that has to run on both engines.
     *
     * @return self::APPLIED|self::DUPLICATE
     */
    public function record(PaymentEvent $event, int $tenantId, ?int $invoiceId = null): string
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO payment_transactions (
                 tenant_id, invoice_id, provider, external_reference, status,
                 amount_minor, currency, failure_reason, raw_payload, occurred_at,
                 created_at, updated_at
             ) VALUES (
                 :tenant_id, :invoice_id, :provider, :external_reference, :status,
                 :amount_minor, :currency, :failure_reason, :raw_payload, :occurred_at,
                 CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )
             ON CONFLICT (provider, external_reference) WHERE external_reference IS NOT NULL
             DO UPDATE SET
                 status = excluded.status,
                 failure_reason = excluded.failure_reason,
                 occurred_at = excluded.occurred_at,
                 raw_payload = excluded.raw_payload,
                 invoice_id = COALESCE(payment_transactions.invoice_id, excluded.invoice_id),
                 updated_at = CURRENT_TIMESTAMP
             WHERE payment_transactions.status = \'pending\'
             RETURNING id'
        );

        $statement->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $statement->bindValue(
            ':invoice_id',
            $invoiceId ?? $event->invoiceId,
            ($invoiceId ?? $event->invoiceId) === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );
        $statement->bindValue(':provider', $event->provider);
        $statement->bindValue(':external_reference', $event->externalReference);
        $statement->bindValue(':status', $event->type->ledgerStatus());
        $statement->bindValue(':amount_minor', $event->amount->amount, PDO::PARAM_INT);
        $statement->bindValue(':currency', $event->amount->currency);
        $statement->bindValue(
            ':failure_reason',
            $event->failureReason,
            $event->failureReason === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $statement->bindValue(':raw_payload', $event->rawPayload);
        $statement->bindValue(':occurred_at', $event->occurredAt->format('Y-m-d H:i:s'));

        $statement->execute();
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            // The conflict target matched but the DO UPDATE's WHERE excluded the
            // row: it is already terminal. Nothing changed, and the caller must
            // not act on it again.
            return self::DUPLICATE;
        }

        return self::APPLIED;
    }

    /**
     * What this invoice has actually been paid, in minor units.
     *
     * DERIVED, NEVER STORED. A cached copy is a second source of truth that
     * drifts the first time a payment is recorded by a path that forgot to
     * update it, and the drift reads as "the customer says they paid and our
     * system says they did not".
     *
     * Only SETTLED movements count. A pending transfer is money that has not
     * arrived, and treating it as paid marks an invoice settled on the strength
     * of somebody having opened the payment screen.
     */
    public function amountSettledMinor(int $tenantId, int $invoiceId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(amount_minor), 0)
               FROM payment_transactions
              WHERE tenant_id = :tenant_id
                AND invoice_id = :invoice_id
                AND status = :status'
        );
        $statement->execute([
            ':tenant_id' => $tenantId,
            ':invoice_id' => $invoiceId,
            ':status' => 'succeeded',
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * How many attempts on this invoice have FAILED — what the dunning schedule
     * counts against its retry offsets.
     *
     * Pending attempts are excluded deliberately: a transfer in flight is not a
     * failure, and counting it as one would advance the retry schedule against
     * money that is on its way.
     */
    public function failedAttempts(int $tenantId, int $invoiceId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM payment_transactions
              WHERE tenant_id = :tenant_id
                AND invoice_id = :invoice_id
                AND status = :status'
        );
        $statement->execute([
            ':tenant_id' => $tenantId,
            ':invoice_id' => $invoiceId,
            ':status' => 'failed',
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * The movements against one invoice, newest first — the payment history a
     * customer is shown.
     *
     * @return list<array<string, mixed>>
     */
    public function historyFor(int $tenantId, int $invoiceId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, provider, external_reference, status, amount_minor, currency,
                    failure_reason, occurred_at
               FROM payment_transactions
              WHERE tenant_id = :tenant_id AND invoice_id = :invoice_id
              ORDER BY occurred_at DESC, id DESC'
        );
        $statement->execute([':tenant_id' => $tenantId, ':invoice_id' => $invoiceId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'provider' => (string) $row['provider'],
            'external_reference' => $row['external_reference'] === null
                ? null
                : (string) $row['external_reference'],
            'status' => (string) $row['status'],
            'amount_minor' => (int) $row['amount_minor'],
            'currency' => (string) $row['currency'],
            'failure_reason' => $row['failure_reason'] === null ? null : (string) $row['failure_reason'],
            'occurred_at' => (string) $row['occurred_at'],
        ], $rows);
    }


}
