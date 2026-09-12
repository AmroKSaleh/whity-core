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
     * How many tenants one sweep re-checks.
     *
     * ONE REQUEST PER TENANT, AGAINST A SHARED BUDGET. The billing service rate
     * limits per API key, and this job is not its only user — checkouts, returns
     * and notification follow-ups all spend from the same allowance while a
     * sweep is running. A batch sized at the whole limit would spend it, and the
     * requests it starved would be the ones a customer is waiting on.
     *
     * So the default is a fraction of the budget rather than all of it, and a
     * backlog is drained across several sweeps instead of one long one.
     */
    public const DEFAULT_BATCH = 100;

    /**
     * @param int $limit How many tenants one sweep will re-check, so a backlog
     *                   cannot run forever.
     *
     * @return array{checked: int, changed: int, unreachable: int, skipped: int, rate_limited: bool}
     */
    public function run(int $limit = self::DEFAULT_BATCH): array
    {
        $checked = 0;
        $changed = 0;
        $unreachable = 0;
        $skipped = 0;
        $rateLimited = false;

        if (!$this->portal->isConfigured()) {
            // A deployment that bills nobody has nothing to reconcile. Not an
            // error, and not worth a log line every sweep.
            return ['checked' => 0, 'changed' => 0, 'unreachable' => 0, 'skipped' => 0, 'rate_limited' => false];
        }

        foreach ($this->candidates($limit) as $row) {
            $tenantId = (int) $row['tenant_id'];
            $knownStatus = isset($row['status']) ? (string) $row['status'] : null;

            try {
                $snapshot = $this->portal->accessFor(BillingSubject::forTenant($tenantId));
            } catch (BillingPortalException $e) {
                if ($e->reason === BillingPortalException::REASON_RATE_LIMITED) {
                    // STOP, DO NOT CARRY ON. Every remaining tenant would spend
                    // another request against an allowance that is already
                    // exhausted, starving the checkouts and returns a customer is
                    // actually waiting on — and none of them would get an answer
                    // either. The rest of the batch waits for the next sweep.
                    $rateLimited = true;
                    $unreachable++;
                    $this->logger->warning('Reconciliation stopped early: the billing service is rate limiting', [
                        'checked' => $checked,
                    ]);
                    break;
                }

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
            // Surfaced rather than logged only: a sweep that keeps stopping
            // early is one that never reaches the tail of the tenant list, and
            // those tenants would silently stop being reconciled at all.
            'rate_limited' => $rateLimited,
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
