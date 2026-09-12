<?php

declare(strict_types=1);

namespace Whity\Core\Billing\External;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Re-ask about everyone we think has paid, and let the billing service win.
 *
 * ASSUME A NOTIFICATION WILL BE MISSED. Deliveries are best-effort with a
 * bounded number of retries, and this endpoint will be down during a deploy, or
 * behind an expired certificate on a Sunday, or simply slow enough that an event
 * exhausts its attempts. A product whose access control depends only on
 * notifications arriving will eventually charge someone and not let them in.
 *
 * So notifications are a LATENCY OPTIMISATION over this job — they make access
 * appear in a second rather than at the next sweep — and this is the mechanism.
 * A missed delivery then costs a delay instead of a wrong answer.
 *
 * ── Why it never revokes on silence ─────────────────────────────────────────
 *
 * A tenant whose access cannot be checked KEEPS what they have. If the billing
 * service is unreachable, the honest local answer is "I do not know", and the
 * one thing that must not follow from not knowing is locking out a paying
 * customer. Their outage would otherwise become ours, amplified: every tenant
 * revoked at once, at exactly the moment nobody can process a payment to fix it.
 *
 * Being told "no" is entirely different, and is applied immediately.
 */
final class AccessReconciliationRun
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly BillingPortal $portal,
        private readonly AccessRecorder $recorder,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param int $limit How many tenants one sweep will re-check, so a backlog
     *                   cannot run forever.
     *
     * @return array{checked: int, changed: int, unreachable: int, skipped: int}
     */
    public function run(int $limit = 500): array
    {
        $checked = 0;
        $changed = 0;
        $unreachable = 0;
        $skipped = 0;

        if (!$this->portal->isConfigured()) {
            // A deployment that bills nobody has nothing to reconcile. Not an
            // error, and not worth a log line every sweep.
            return ['checked' => 0, 'changed' => 0, 'unreachable' => 0, 'skipped' => 0];
        }

        foreach ($this->candidates($limit) as $row) {
            $tenantId = (int) $row['tenant_id'];
            $knownStatus = isset($row['status']) ? (string) $row['status'] : null;

            try {
                $snapshot = $this->portal->accessFor(BillingSubject::forTenant($tenantId));
            } catch (BillingPortalException $e) {
                if ($e->isTransient()) {
                    // Not known, so not changed. The next sweep asks again.
                    $unreachable++;
                    continue;
                }

                $this->logger->error('Could not reconcile a tenant against the billing service', [
                    'tenant_id' => $tenantId,
                    'reason' => $e->reason,
                ]);
                $skipped++;
                continue;
            }

            $checked++;
            $this->recorder->record($tenantId, $snapshot);

            // Compared against what we held BEFORE the write, so the count means
            // "the billing service disagreed with us" rather than "a row was
            // touched" — which is the number worth putting in front of someone,
            // because a sweep that corrects forty tenants is telling them their
            // notifications are not arriving.
            if ($snapshot->status !== null && $snapshot->status !== $knownStatus) {
                $changed++;
                $this->logger->info('Reconciliation corrected a tenant\'s access', [
                    'tenant_id' => $tenantId,
                    'was' => $knownStatus,
                    'now' => $snapshot->status,
                ]);
            }
        }

        return [
            'checked' => $checked,
            'changed' => $changed,
            'unreachable' => $unreachable,
            'skipped' => $skipped,
        ];
    }

    /**
     * Tenants worth asking about.
     *
     * ANY TENANT WITH A RECORDED BILLING STATE, including lapsed ones. Sweeping
     * only the tenants we believe have access would never notice a payment that
     * restored someone — and a customer who paid to come back is precisely the
     * one who must not wait for a notification that already failed to arrive.
     *
     * The system tenant is excluded: it is never subscribed and
     * SubscriptionService refuses to record anything against it.
     *
     * @return list<array<string, mixed>>
     *
     * @tenant-guard-ignore: this sweep has no tenant context by design — it is
     * the job that FINDS which tenants to re-check, exactly as the billing run's
     * dueSubscriptions() does. Every read and write that follows binds the
     * tenant id this returns.
     */
    private function candidates(int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT tenant_id, status
               FROM tenant_plan
              WHERE tenant_id <> 0
                AND (status IS NOT NULL OR external_ref IS NOT NULL)
              ORDER BY tenant_id ASC
              LIMIT :limit'
        );
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }
}
