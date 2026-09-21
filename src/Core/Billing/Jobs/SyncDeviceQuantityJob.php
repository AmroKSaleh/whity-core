<?php

declare(strict_types=1);

namespace Whity\Core\Billing\Jobs;

use DateTimeImmutable;
use Whity\Core\Billing\External\DeviceQuantitySyncRun;
use Whity\Sdk\JobInterface;

/**
 * The scheduled sweep that keeps a device subscription billing for real devices.
 *
 * WITHOUT THIS, "PER DEVICE" IS A LABEL ON A PRICE. The tenant is billed for
 * whatever quantity they happened to buy at, for as long as the subscription
 * lives — activate nine more scanners and the invoice never moves.
 *
 * SAFE TO RUN TWICE, and by construction rather than by care: it compares the
 * device count against the quantity and acts only on the difference, so a second
 * pass over the same tenants changes nothing. That is what lets it satisfy
 * {@see JobInterface}'s at-least-once contract honestly — which matters more
 * here than for most jobs, because the thing a careless retry would repeat is
 * charging somebody.
 *
 * INTERNAL, NEVER API-SUBMITTABLE. It is registered without the submittable
 * flag: an endpoint anyone could POST to would let a caller drive this
 * deployment's whole tenant list at a third party, one request per tenant, as
 * often as they liked — and this one ends in charges.
 *
 * IT RESIZES NOBODY ON SILENCE. That property lives in the run itself, and it is
 * why this can be scheduled often without an outage at the billing service
 * turning into wrong numbers on customers' invoices.
 */
final class SyncDeviceQuantityJob implements JobInterface
{
    public const NAME = 'core.billing.sync_device_quantity';

    public function __construct(private readonly DeviceQuantitySyncRun $run)
    {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        // The counts are the result, so `GET /api/jobs/{id}` answers "did
        // anybody's fleet move" without reading a log. `zero_devices` is the one
        // worth watching: it counts customers paying for devices they no longer
        // run, and no sweep can fix that — the billing service's minimum
        // quantity is one, so what they need is a cancellation by a human.
        return $this->run->run(new DateTimeImmutable());
    }
}
