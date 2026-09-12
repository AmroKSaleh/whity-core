<?php

declare(strict_types=1);

namespace Whity\Core\Billing;

use DateTimeImmutable;
use PDO;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * How many devices a tenant is billed for — the one place that decides.
 *
 * "PER DEVICE" IS AT LEAST THREE BILLING MODELS, and they produce different
 * amounts from identical facts: billed from provisioning, from activation, or
 * only for units actually seen in the period. Migration 146 records
 * provisioned_at, activated_at and last_seen_at as separate columns precisely so
 * this can be configuration — a deployment that changes its mind changes a
 * setting instead of migrating invoices it has already sent.
 *
 * PER TENANT, falling back to the deployment default. Two customers on
 * per-device plans can genuinely bill differently — one pays for every unit it
 * has been shipped, another only for units its staff actually used this month —
 * and that is a term in a contract, not a property of the installation.
 *
 * ── Why it is a class rather than a private method ──────────────────────────
 *
 * Two mechanisms now need this number and they need it for different moments.
 * The billing run counts a period that has CLOSED, to put a quantity on an
 * invoice. The device-quantity sweep counts what is in service NOW, to keep an
 * external subscription's quantity honest. Both have to read the same setting
 * and apply the same rules, or a customer's invoice and their subscription would
 * disagree about how many devices they have — and the disagreement would be
 * invisible until somebody added up a year of statements.
 */
final class LicensedDeviceCount
{
    /**
     * The basis that cannot be counted forwards.
     *
     * `active_in_period` is RETROSPECTIVE by construction: it counts units seen
     * between two dates, and how many will be seen between now and the end of
     * the month is not knowable now. An invoice raised after the fact can say
     * it; a subscription quantity set in advance cannot.
     */
    public const RETROSPECTIVE_BASIS = 'active_in_period';

    public function __construct(
        private readonly PDO $pdo,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * How many devices were in service across a period that has ended.
     *
     * COUNTED AGAINST THE PERIOD, not against now. A device retired last month
     * must not appear on this month's invoice, and one activated on the last day
     * of the period must — the invoice describes a period, and an invoice that
     * silently re-counts when it is re-read is not an invoice.
     *
     * ZERO IS A REAL ANSWER. A tenant with no devices in service owes nothing
     * for devices, and the caller decides what that means — inventing a phantom
     * unit would put a charge on an invoice for hardware nobody is using.
     */
    public function between(int $tenantId, DateTimeImmutable $periodStart, DateTimeImmutable $periodEnd): int
    {
        return $this->count($this->basisFor($tenantId), $tenantId, $periodStart, $periodEnd);
    }

    /**
     * How many devices are in service right now, for a subscription billing
     * forwards — or null when this tenant's basis cannot answer that.
     *
     * NULL IS NOT ZERO, and the difference is a customer's bill. A tenant billed
     * on {@see self::RETROSPECTIVE_BASIS} has no forward-looking count, and the
     * two ways of faking one are both wrong: an empty window counts nobody and
     * would bill a twelve-device customer for one, while a window running from
     * the period start would only ever grow — the billing service applies
     * decreases at renewal, so a peak reached once would never come back down
     * and the customer's bill would ratchet upward for good.
     *
     * So the sweep is told it cannot know, and leaves the subscription alone.
     * Those tenants are billed correctly by the local billing run, which counts
     * the period after it closes, which is what that basis means.
     */
    public function inServiceNow(int $tenantId, DateTimeImmutable $now): ?int
    {
        $basis = $this->basisFor($tenantId);

        if ($basis === self::RETROSPECTIVE_BASIS) {
            return null;
        }

        // A ZERO-WIDTH WINDOW IS EXACTLY "NOW" for the other two bases: each
        // asks whether a unit had started by the end of the window and had not
        // been retired before its start, and both ends being this instant makes
        // that "started, and not yet retired". The same SQL answers both
        // questions, which is why there is only one.
        return $this->count($basis, $tenantId, $now, $now);
    }

    /**
     * Which units count, for this tenant.
     *
     * An unrecognised value falls through to `activated` below rather than
     * throwing: a typo in a setting must not stop a billing run, and it must
     * not silently widen an invoice either.
     */
    private function basisFor(int $tenantId): string
    {
        $value = $this->settings->effective($tenantId)[SettingsRegistry::LICENSING_BILLING_BASIS] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $default = SettingsRegistry::defaultFor(SettingsRegistry::LICENSING_BILLING_BASIS);

        return is_string($default) && $default !== '' ? $default : 'activated';
    }

    private function count(
        string $basis,
        int $tenantId,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
    ): int {
        // Every arm excludes units retired BEFORE the window began: they were
        // not in service for any of it. A unit retired DURING it still counts,
        // because it was.
        $sql = match ($basis) {
            'provisioned' => 'SELECT COUNT(*) FROM licensed_devices
                               WHERE tenant_id = :tenant_id
                                 AND provisioned_at <= :period_end
                                 AND (retired_at IS NULL OR retired_at >= :period_start)',
            self::RETROSPECTIVE_BASIS => 'SELECT COUNT(*) FROM licensed_devices
                                    WHERE tenant_id = :tenant_id
                                      AND last_seen_at IS NOT NULL
                                      AND last_seen_at >= :period_start
                                      AND last_seen_at <= :period_end',
            // 'activated', and the fallback for an unrecognised value: billing
            // for units put into service is the safest reading of "per device".
            default => 'SELECT COUNT(*) FROM licensed_devices
                         WHERE tenant_id = :tenant_id
                           AND activated_at IS NOT NULL
                           AND activated_at <= :period_end
                           AND (retired_at IS NULL OR retired_at >= :period_start)',
        };

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            ':tenant_id' => $tenantId,
            ':period_end' => $periodEnd->format('Y-m-d H:i:s'),
            ':period_start' => $periodStart->format('Y-m-d H:i:s'),
        ]);

        return max(0, (int) $statement->fetchColumn());
    }
}
