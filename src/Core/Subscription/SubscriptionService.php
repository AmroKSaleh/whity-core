<?php

declare(strict_types=1);

namespace Whity\Core\Subscription;

use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * Per-tenant subscription state + the payment-wall decision (WC-billing).
 *
 * External-state model: an operator/admin reflects an out-of-band payment into a
 * tenant's subscription (a future provider webhook writes the same via
 * `external_ref`). This service stores that state and answers the wall's question
 * — is this request allowed, warned, or blocked?
 *
 * Invariants (safe by construction — a fresh/sovereign deploy is never locked out):
 *   - The SYSTEM tenant (0) is NEVER enforced.
 *   - A tenant with NO subscription record is NEVER blocked.
 *   - Only a LAPSED subscription (canceled / expired / past_due beyond grace) can
 *     block, and then only as strictly as the effective enforcement mode says.
 *
 * Enforcement mode is a PER-TENANT operator policy (`tenant_plan.enforcement_mode`)
 * that falls back to the global `billing.enforcement_default` setting; a tenant
 * admin can never change it. Stateless beyond its injected deps — worker-safe.
 */
final class SubscriptionService
{
    public const SYSTEM_TENANT_ID = 0;

    // Subscription statuses.
    public const STATUS_TRIALING = 'trialing';
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_EXPIRED  = 'expired';

    public const STATUSES = [
        self::STATUS_TRIALING,
        self::STATUS_ACTIVE,
        self::STATUS_PAST_DUE,
        self::STATUS_CANCELED,
        self::STATUS_EXPIRED,
    ];

    // Enforcement modes (kept in sync with SettingsRegistry BILLING_ENFORCEMENT_DEFAULT).
    public const MODE_OFF          = 'off';
    public const MODE_WARN         = 'warn';
    public const MODE_BLOCK_WRITES = 'block_writes';
    public const MODE_BLOCK_ALL    = 'block_all';

    public const MODES = [self::MODE_OFF, self::MODE_WARN, self::MODE_BLOCK_WRITES, self::MODE_BLOCK_ALL];

    private SubscriptionRepository $repo;
    private SettingsService $settings;
    /** @var callable(): int */
    private $clock;

    /**
     * @param (callable(): int)|null $clock Unix-timestamp source (for tests); defaults to time().
     */
    public function __construct(SubscriptionRepository $repo, SettingsService $settings, ?callable $clock = null)
    {
        $this->repo = $repo;
        $this->settings = $settings;
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * The tenant's raw subscription state, or null when none is configured.
     *
     * @return array<string, mixed>|null
     */
    public function getSubscription(int $tenantId): ?array
    {
        if ($tenantId === self::SYSTEM_TENANT_ID) {
            return null;
        }

        return $this->repo->findForTenant($tenantId);
    }

    /**
     * Set (merge) the tenant's subscription state. Only supplied keys change.
     * Validates the status + enforcement_mode enums. The system tenant is rejected
     * — it is never subscribed.
     *
     * @param array{status?: ?string, current_period_end?: ?string, grace_until?: ?string,
     *              enforcement_mode?: ?string, external_ref?: ?string} $changes
     * @throws SubscriptionException On the system tenant or an invalid status/mode.
     */
    public function setSubscription(int $tenantId, array $changes): void
    {
        if ($tenantId === self::SYSTEM_TENANT_ID) {
            throw new SubscriptionException('The system tenant is never subscribed and is never enforced.');
        }

        if (array_key_exists('status', $changes) && $changes['status'] !== null
            && !in_array($changes['status'], self::STATUSES, true)) {
            throw new SubscriptionException("Invalid subscription status: {$changes['status']}");
        }
        if (array_key_exists('enforcement_mode', $changes) && $changes['enforcement_mode'] !== null
            && !in_array($changes['enforcement_mode'], self::MODES, true)) {
            throw new SubscriptionException("Invalid enforcement mode: {$changes['enforcement_mode']}");
        }

        $current = $this->repo->findForTenant($tenantId) ?? [];
        $state = [
            'status'             => $changes['status']             ?? ($current['status'] ?? null),
            'current_period_end' => $changes['current_period_end'] ?? ($current['current_period_end'] ?? null),
            'grace_until'        => $changes['grace_until']        ?? ($current['grace_until'] ?? null),
            'enforcement_mode'   => $changes['enforcement_mode']   ?? ($current['enforcement_mode'] ?? null),
            'external_ref'       => $changes['external_ref']       ?? ($current['external_ref'] ?? null),
        ];

        $this->repo->upsert($tenantId, $state);
    }

    /**
     * The enforcement mode in effect for a tenant: its per-tenant override, else
     * the global `billing.enforcement_default`.
     */
    public function effectiveEnforcementMode(int $tenantId): string
    {
        $sub = $this->getSubscription($tenantId);
        $mode = $sub['enforcement_mode'] ?? null;
        if (is_string($mode) && in_array($mode, self::MODES, true)) {
            return $mode;
        }

        $global = $this->settings->getGlobal()[SettingsRegistry::BILLING_ENFORCEMENT_DEFAULT] ?? self::MODE_WARN;

        return in_array($global, self::MODES, true) ? $global : self::MODE_WARN;
    }

    /**
     * The wall's decision for a request. `$isWrite` marks a mutating request
     * (POST/PUT/PATCH/DELETE) for the block_writes mode.
     */
    public function decide(int $tenantId, bool $isWrite): SubscriptionDecision
    {
        // Never enforce the system tenant.
        if ($tenantId === self::SYSTEM_TENANT_ID) {
            return SubscriptionDecision::allow();
        }

        $sub = $this->repo->findForTenant($tenantId);
        // No subscription configured → never blocked (sovereign / not-yet-billed).
        if ($sub === null || ($sub['status'] ?? null) === null) {
            return SubscriptionDecision::allow();
        }

        $status = (string) $sub['status'];
        if ($this->isCurrent($status, $sub)) {
            return SubscriptionDecision::allow($status, self::MODE_OFF);
        }

        // Lapsed — apply the effective enforcement mode.
        $mode = $this->effectiveEnforcementModeFor($sub);
        return match ($mode) {
            self::MODE_OFF          => SubscriptionDecision::allow($status, $mode),
            self::MODE_WARN         => SubscriptionDecision::warn($status, $mode),
            self::MODE_BLOCK_WRITES => $isWrite
                ? SubscriptionDecision::block($status, $mode)
                : SubscriptionDecision::warn($status, $mode),
            self::MODE_BLOCK_ALL    => SubscriptionDecision::block($status, $mode),
            default                 => SubscriptionDecision::warn($status, $mode),
        };
    }

    /**
     * Whether a subscription still grants access: active/trialing always; past_due
     * only while within its grace window (grace_until, or — when unset — leniently
     * still in grace). canceled/expired never.
     *
     * @param array<string, mixed> $sub
     */
    private function isCurrent(string $status, array $sub): bool
    {
        if ($status === self::STATUS_ACTIVE || $status === self::STATUS_TRIALING) {
            return true;
        }
        if ($status === self::STATUS_PAST_DUE) {
            $graceUntil = $sub['grace_until'] ?? null;
            if (!is_string($graceUntil) || $graceUntil === '') {
                // No explicit grace deadline → still within grace (lenient).
                return true;
            }
            $deadline = strtotime($graceUntil);
            return $deadline === false || ($this->clock)() <= $deadline;
        }

        // canceled / expired.
        return false;
    }

    /**
     * @param array<string, mixed> $sub
     */
    private function effectiveEnforcementModeFor(array $sub): string
    {
        $mode = $sub['enforcement_mode'] ?? null;
        if (is_string($mode) && in_array($mode, self::MODES, true)) {
            return $mode;
        }
        $global = $this->settings->getGlobal()[SettingsRegistry::BILLING_ENFORCEMENT_DEFAULT] ?? self::MODE_WARN;

        return in_array($global, self::MODES, true) ? $global : self::MODE_WARN;
    }
}
