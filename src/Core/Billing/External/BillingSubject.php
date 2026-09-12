<?php

declare(strict_types=1);

namespace Whity\Core\Billing\External;

/**
 * Who is paying, named in a way the billing service can hold.
 *
 * The billing service knows a payer only by an opaque string we choose. Today
 * that payer is always a tenant — one subscription per tenant, which is what
 * `tenant_plan` structurally allows, its primary key being the tenant id.
 *
 * THE PREFIX IS NOT DECORATION. A bare `42` would work today and would be
 * unextendable tomorrow: the stated intent is that seats or a tenant's own
 * customers will also pay, and those are different subjects that would collide
 * in the same id space the moment either produced the number 42. `tenant-42`
 * leaves room for a second kind to exist beside it without a migration of every
 * customer record on the other side.
 *
 * PARSING BACK IS STRICT, and that is the direction that matters. A notification
 * arrives carrying an id, and whatever that id resolves to is whose access
 * changes. A lenient parser that read `tenant-42-evil` as 42, or accepted a
 * leading zero, or let a negative through, would be a way to aim a legitimate,
 * correctly-signed event at a tenant it was never about.
 */
final class BillingSubject
{
    private const TENANT_PREFIX = 'tenant-';

    /** The id the billing service should know this tenant by. */
    public static function forTenant(int $tenantId): string
    {
        return self::TENANT_PREFIX . $tenantId;
    }

    /**
     * The tenant an id refers to, or null if it names something else.
     *
     * Null is an ordinary answer, not a failure: once seats or a tenant's own
     * customers can pay, this service will legitimately hold subjects that are
     * not tenants, and an event about one of those must be ignored here rather
     * than guessed at.
     */
    public static function tenantIdFrom(string $subjectRef): ?int
    {
        if (!str_starts_with($subjectRef, self::TENANT_PREFIX)) {
            return null;
        }

        $digits = substr($subjectRef, strlen(self::TENANT_PREFIX));

        // ctype_digit alone would accept '007' and '0'. Re-rendering the parsed
        // integer and demanding the original back rejects every string that is
        // not the canonical spelling of a positive id — including one with
        // trailing content, which is the case with teeth.
        if ($digits === '' || !ctype_digit($digits)) {
            return null;
        }

        $tenantId = (int) $digits;

        return ($tenantId > 0 && (string) $tenantId === $digits) ? $tenantId : null;
    }
}
