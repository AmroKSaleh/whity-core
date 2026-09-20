<?php

declare(strict_types=1);

namespace Whity\Core\Licensing;

use DateTimeImmutable;
use Whity\Core\Billing\LicensedDeviceCount;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;

/**
 * How many more devices a workspace may put into service.
 *
 * ── Why this is its own object ─────────────────────────────────────────────
 *
 * Two completely different code paths put a device into service — an operator
 * bulk-importing serials, and a customer redeeming an activation code on their
 * own hardware — and they live in different layers, reached by different
 * callers, with different notions of who is asking. The one thing they must
 * agree on is the answer to "is there room", because a cap enforced in one
 * place and not the other is not a cap.
 *
 * ── The count is the BILLED count, deliberately ────────────────────────────
 *
 * {@see LicensedDeviceCount} already decides which units count for a tenant —
 * provisioned, activated, or seen in the period — because "per device" is at
 * least three billing models. The cap uses the same decision. A tenant billed
 * from PROVISIONING who was capped on ACTIVATIONS could be charged for ten
 * units while the product told them they had eight left: a contradiction they
 * would discover on an invoice rather than on a screen.
 *
 * It also means the cap bites in the right PLACE without anybody choosing one.
 * On a provisioned basis, importing serials is what fills the allowance, so the
 * import is refused. On an activated basis, importing is free and activation is
 * what fills it. One rule, two enforcement points, and whichever event the
 * customer is billed for is the one that blocks.
 */
final class DeviceAllowance
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly LicensedDeviceCount $devices,
    ) {
    }

    /**
     * How many more units this workspace may put into service, or null for no
     * cap at all.
     *
     * NULL RATHER THAN A LARGE NUMBER, so a caller cannot accidentally render
     * "2147483647 devices remaining" or compare against a sentinel it forgot to
     * special-case. Unlimited is not a quantity.
     */
    public function remaining(int $tenantId, DateTimeImmutable $now): ?int
    {
        $cap = $this->capFor($tenantId);
        if ($cap === EntitlementRegistry::UNLIMITED) {
            return null;
        }

        return max(0, $cap - $this->devices->heldNow($tenantId, $now));
    }

    /** Whether putting one more unit into service would break the tier's cap. */
    public function isExhausted(int $tenantId, DateTimeImmutable $now): bool
    {
        $remaining = $this->remaining($tenantId, $now);

        return $remaining !== null && $remaining < 1;
    }

    /**
     * The cap itself, for a message that names the number.
     *
     * "You have reached your device limit" is not actionable; "your plan
     * includes 10 devices" tells somebody what to buy.
     */
    public function capFor(int $tenantId): int
    {
        return $this->entitlements->limit($tenantId, EntitlementRegistry::DEVICES_MAX);
    }
}
