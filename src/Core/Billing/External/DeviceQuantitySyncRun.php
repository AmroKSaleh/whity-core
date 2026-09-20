<?php

declare(strict_types=1);

namespace Whity\Core\Billing\External;

use DateTimeImmutable;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Core\Billing\LicensedDeviceCount;

/**
 * Keep a per-device subscription billing for the devices that actually exist.
 *
 * ── The bug this closes ─────────────────────────────────────────────────────
 *
 * A tenant buys the device add-on with three scanners and is billed for three.
 * They activate nine more. Nothing tells the billing service, so next month they
 * are billed for three again — and the month after, and every month after that.
 * "Per device" was a label on a price, not a thing that happened. It fails the
 * other way too: a customer who retires half their fleet keeps paying for it,
 * which is the version somebody eventually notices and asks for back.
 *
 * The mechanism to fix it already existed — the billing service takes a new
 * quantity, and prorates it — but nothing ever called it with a number that came
 * from the devices. This is the thing that calls it.
 *
 * ── Why a sweep and not an event ────────────────────────────────────────────
 *
 * Activating a device could push the new count immediately, and that is a
 * tempting design: one line in the activation handler. It is the wrong shape.
 * Activation happens in a request the customer is waiting on, against a remote
 * service that can be slow, rate limiting or down — so either the activation
 * fails because billing was unavailable, or it succeeds and the push is lost and
 * the count silently drifts, which is the bug this exists to close.
 *
 * A sweep converges instead. It is idempotent by construction: it reads the
 * count and the quantity and acts only on the difference, so running it twice,
 * or after a missed run, or after a partial failure, all end in the same place.
 * Devices are billed monthly; a sweep measured in minutes is far inside the
 * resolution anyone is paying at.
 *
 * ── What it will not do ─────────────────────────────────────────────────────
 *
 * NEVER RESIZES ON SILENCE. If the billing service cannot be reached, the local
 * answer is "I do not know what they are billed for", and nothing follows from
 * not knowing. The same discipline as {@see AccessReconciliationRun}, for the
 * same reason: their outage must not become a wrong number on our customers'
 * invoices.
 *
 * NEVER SETS ZERO. The billing service's minimum quantity is one, so a tenant
 * whose fleet has gone to nothing cannot be resized down to match — what they
 * actually need is the subscription CANCELLED, which is not a thing this seam
 * can express. It is counted and logged rather than approximated, because
 * leaving them on one device is a small wrong bill and silently pretending that
 * is correct is how it stays wrong.
 */
final class DeviceQuantitySyncRun
{
    /**
     * How many tenants one sweep will check.
     *
     * ONE REQUEST PER TENANT, AGAINST A SHARED BUDGET — the same allowance the
     * access sweep, checkouts and returns all spend from. A batch sized at the
     * whole limit would starve the requests a customer is actually waiting on.
     */
    public const DEFAULT_BATCH = 100;

    public function __construct(
        private readonly PDO $pdo,
        private readonly BillingPortal $portal,
        private readonly LicensedDeviceCount $devices,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return array{
     *     checked: int, changed: int, unchanged: int, skipped: int,
     *     unreachable: int, zero_devices: int, rate_limited: bool
     * }
     */
    public function run(DateTimeImmutable $now, int $limit = self::DEFAULT_BATCH): array
    {
        $result = [
            'checked' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'unreachable' => 0,
            // Surfaced on its own because it is the one outcome that needs a
            // human: a customer paying for devices they no longer run, whom this
            // job cannot fix.
            'zero_devices' => 0,
            'rate_limited' => false,
        ];

        if (!$this->portal->isConfigured()) {
            return $result;
        }

        // Loaded ONCE, not per tenant. It is the same small catalogue for every
        // tenant in the batch, and re-reading it a hundred times would be a
        // hundred queries to learn the same four rows.
        $perDeviceRefs = $this->perDevicePriceRefs();
        if ($perDeviceRefs === []) {
            // This deployment does not sell anything per device. Nothing to
            // sync, and no reason to spend a request finding that out.
            return $result;
        }

        foreach ($this->candidates($limit) as $tenantId) {
            try {
                $lines = $this->portal->subscriptionsFor(BillingSubject::forTenant($tenantId));
            } catch (BillingPortalException $e) {
                if ($this->stopOrCount($e, $tenantId, 'read subscriptions', $result)) {
                    break;
                }
                continue;
            }

            $subscription = $this->deviceSubscription($lines, $perDeviceRefs, $tenantId);
            if ($subscription === null) {
                // No live per-device subscription: the ordinary state of a
                // tenant who has devices recorded but has never bought the
                // add-on for them.
                $result['skipped']++;
                continue;
            }

            $result['checked']++;

            $count = $this->devices->inServiceNow($tenantId, $now);
            if ($count === null) {
                // A basis with no forward-looking answer — see
                // LicensedDeviceCount::inServiceNow(). Guessing one would
                // ratchet the customer's bill upward permanently.
                $this->logger->info('Device quantity not synced: this tenant is billed retrospectively', [
                    'tenant_id' => $tenantId,
                ]);
                $result['skipped']++;
                continue;
            }

            if ($count < 1) {
                $this->logger->warning(
                    'A tenant is paying for devices but has none in service; the subscription needs cancelling.',
                    ['tenant_id' => $tenantId, 'billed_for' => $subscription->quantity]
                );
                $result['zero_devices']++;
                continue;
            }

            if ($subscription->quantity === null) {
                // The service did not say what they are billed for, so there is
                // nothing to compare against. Sending the count anyway would
                // re-bill every tenant on every sweep for a quantity that may
                // never have moved.
                $this->logger->warning('A subscription carried no quantity; not resized', [
                    'tenant_id' => $tenantId,
                ]);
                $result['skipped']++;
                continue;
            }

            if ($subscription->quantity === $count) {
                $result['unchanged']++;
                continue;
            }

            try {
                $this->portal->changeQuantity($subscription->id, $count);
            } catch (BillingPortalException $e) {
                if ($this->stopOrCount($e, $tenantId, 'change a quantity', $result)) {
                    break;
                }
                continue;
            }

            $this->logger->info('Device subscription resized to match the fleet', [
                'tenant_id' => $tenantId,
                'was' => $subscription->quantity,
                'now' => $count,
            ]);
            $result['changed']++;
        }

        return $result;
    }

    /**
     * Classify a portal failure, and say whether the sweep must stop.
     *
     * @param array{
     *     checked: int, changed: int, unchanged: int, skipped: int,
     *     unreachable: int, zero_devices: int, rate_limited: bool
     * } $result
     *
     * @return bool True when the whole sweep must stop.
     */
    private function stopOrCount(
        BillingPortalException $e,
        int $tenantId,
        string $attempting,
        array &$result,
    ): bool {
        if ($e->reason === BillingPortalException::REASON_RATE_LIMITED) {
            // STOP, DO NOT CARRY ON. Every remaining tenant would spend another
            // request against an allowance already exhausted, starving the
            // checkouts a customer is waiting on — and none of them would get an
            // answer either. The rest of the batch waits for the next sweep.
            $result['rate_limited'] = true;
            $result['unreachable']++;
            $this->logger->warning('Device quantity sync stopped early: the billing service is rate limiting', [
                'checked' => $result['checked'],
            ]);

            return true;
        }

        if ($e->isTransient()) {
            $result['unreachable']++;

            return false;
        }

        $this->logger->error('Could not ' . $attempting . ' for a tenant', [
            'tenant_id' => $tenantId,
            'reason' => $e->reason,
        ]);
        $result['skipped']++;

        return false;
    }

    /**
     * The one live per-device subscription this payer holds, or null.
     *
     * MORE THAN ONE IS REFUSED, NOT PICKED BETWEEN. Two live device
     * subscriptions means the tenant is being billed twice for the same fleet,
     * and resizing both to the device count would DOUBLE the error rather than
     * correct it. Whichever one is wrong, a human has to decide — so this says
     * so and leaves both alone.
     *
     * @param list<SubscriptionLine> $lines
     * @param array<string, true>    $perDeviceRefs
     */
    private function deviceSubscription(array $lines, array $perDeviceRefs, int $tenantId): ?SubscriptionLine
    {
        $matches = [];
        foreach ($lines as $line) {
            // The null check is for the type, not for the behaviour: an unpriced
            // line would look up the empty string, which `perDevicePriceRefs()`
            // never stores. Mutation confirms it — removing it fails nothing.
            // It stays because the lookup takes a string, and because a reader
            // should not have to work that out.
            if ($line->isLive() && $line->priceRef !== null && isset($perDeviceRefs[$line->priceRef])) {
                $matches[] = $line;
            }
        }

        if (count($matches) > 1) {
            $this->logger->error('A tenant holds more than one live per-device subscription; not resizing either', [
                'tenant_id' => $tenantId,
                'subscriptions' => count($matches),
            ]);

            return null;
        }

        return $matches[0] ?? null;
    }

    /**
     * Which of the billing service's prices are per device, as a lookup.
     *
     * MATCHED BY THE REFERENCE WE SENT, not by a plan name. `external_ref` is
     * the exact string that opened the checkout, so this cannot drift: renaming
     * a plan on either side changes nothing, and a price that is not sold
     * externally has no ref and never matches.
     *
     * Inactive prices are INCLUDED deliberately. A price withdrawn from sale
     * still has customers subscribed on it, and those are exactly the ones whose
     * fleet keeps changing after the catalogue moved on.
     *
     * @return array<string, true>
     *
     * @tenant-guard-ignore: the plan catalogue is operator-owned and global by
     * design — there is no tenant column to bind. Every read that follows binds
     * a tenant id.
     */
    private function perDevicePriceRefs(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT external_ref FROM plan_prices
              WHERE is_per_device = :on AND external_ref IS NOT NULL'
        );
        $statement->bindValue(':on', true, PDO::PARAM_BOOL);
        $statement->execute();

        $refs = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $ref) {
            if (is_string($ref) && $ref !== '') {
                $refs[$ref] = true;
            }
        }

        return $refs;
    }

    /**
     * Tenants worth asking about.
     *
     * A RECORDED BILLING STATE *AND* A DEVICE ON FILE. Both halves narrow a
     * per-tenant request budget to tenants where the answer could differ: one
     * without a billing relationship holds no subscription to resize, and one
     * with no device row has never had a fleet to count. A tenant who retired
     * their whole fleet still has the rows, so they are still asked about — and
     * they are precisely the over-billed case worth finding.
     *
     * @return list<int>
     *
     * @tenant-guard-ignore: this sweep has no tenant context by design — it is
     * the job that FINDS which tenants to check, exactly as the billing run's
     * dueSubscriptions() does. Every read and write that follows binds the
     * tenant id this returns.
     */
    private function candidates(int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT tp.tenant_id
               FROM tenant_plan tp
              WHERE tp.tenant_id <> 0
                AND (tp.status IS NOT NULL OR tp.external_ref IS NOT NULL)
                AND EXISTS (
                      SELECT 1 FROM licensed_devices ld WHERE ld.tenant_id = tp.tenant_id
                    )
              ORDER BY tp.tenant_id ASC
              LIMIT :limit'
        );
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        $ids = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }
}
