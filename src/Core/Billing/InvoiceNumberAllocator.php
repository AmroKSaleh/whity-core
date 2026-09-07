<?php

declare(strict_types=1);

namespace Whity\Core\Billing;

use DateTimeImmutable;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Sdk\Sql\SequenceAllocator;

/**
 * The invoice number, and the series it came from.
 *
 * WHAT A NUMBER IS FOR. Not identity — the row already has an id. An invoice
 * number is what a customer quotes on a transfer, what an accountant ties a
 * payment to, and in most jurisdictions what a tax authority expects to see in
 * an unbroken sequence. All three want it human-shaped and stable, which is why
 * it is a formatted string rather than the primary key.
 *
 * GAPLESS IS NOT A DATABASE PROPERTY, and this is the honest place to say so.
 * {@see SequenceAllocator} yields numbers that are unique and monotonic; a
 * crash between allocating and committing leaves a hole no constraint can
 * prevent. What CAN be done, and is done here, is to make holes rare enough to
 * be investigable:
 *
 *   - a number is allocated at ISSUE, never at draft, so an abandoned draft
 *     never consumes one (the schema enforces the pairing);
 *   - the allocation and the write that stores it belong to one caller-owned
 *     transaction, so a failure rolls both back together;
 *   - nothing else happens between them.
 *
 * Where a tax authority genuinely requires gaplessness, the answer is a
 * reconciliation report that FINDS holes, not a claim in a docblock that there
 * are none. {@see self::gapReportFor()} exists so that report can be written
 * against the same understanding of a series that allocation uses.
 *
 * THE SERIES IS THE COUNTER, and that is the whole trick. `(series, number)` is
 * unique in the schema, and `series` is literally the name of the counter the
 * number came from. So one index enforces the only claim numbering makes —
 * a sequence never issues the same number twice — under every combination of
 * scope and reset, without the constraint knowing which was configured.
 */
final class InvoiceNumberAllocator
{
    /** One sequence for the whole platform: the operator is the seller. */
    public const SCOPE_SHARED = 'shared';

    /** One per tenant: a resale deployment where each tenant is its own seller. */
    public const SCOPE_PER_TENANT = 'per_tenant';

    public const RESET_NEVER = 'never';
    public const RESET_YEARLY = 'yearly';
    public const RESET_MONTHLY = 'monthly';

    /** The counter name prefix, so invoice counters cannot collide with others. */
    private const COUNTER_PREFIX = 'invoice';

    public function __construct(
        private readonly SequenceAllocator $sequences,
    ) {
    }

    /**
     * Allocate the next number in the appropriate series.
     *
     * CALL THIS INSIDE THE TRANSACTION THAT STORES THE RESULT. The allocation
     * participates in whatever transaction is open, which is what lets a failed
     * issue give the number back instead of burning it. Calling it outside one,
     * or doing anything fallible between it and the insert, converts a rollback
     * into a permanent hole.
     *
     * @param string $format Placeholders {YYYY} {YY} {MM} {SEQ} {SEQ:n}.
     *
     * @return array{series: string, number: string}
     */
    public function allocate(
        int $tenantId,
        string $format,
        string $scope,
        string $reset,
        DateTimeImmutable $issuedAt,
    ): array {
        $series = self::seriesFor($tenantId, $scope, $reset, $issuedAt);

        // Per-tenant scope stores the counter under the tenant; a shared series
        // is a platform-wide fact and lives under the system tenant. Both are
        // rows in one table with one predicate — see SequenceCounters.
        $next = $scope === self::SCOPE_PER_TENANT
            ? $this->sequences->next($tenantId, $series)
            : $this->sequences->nextPlatformWide($series);

        return [
            'series' => $series,
            'number' => self::render($format, $next, $issuedAt),
        ];
    }

    /**
     * The name of the counter a given invoice would draw from.
     *
     * Public because the gap report and any future "what will the next number
     * be" screen must agree with allocation about what a series IS. Two
     * implementations of that would drift, and the drift would look like
     * missing invoices.
     */
    public static function seriesFor(
        int $tenantId,
        string $scope,
        string $reset,
        DateTimeImmutable $issuedAt,
    ): string {
        $parts = [self::COUNTER_PREFIX];

        if ($scope === self::SCOPE_PER_TENANT) {
            $parts[] = 't' . $tenantId;
        }

        // The reset period is part of the series NAME rather than something
        // that zeroes a counter. Zeroing is a scheduled job that can fail to
        // run, or run twice; a new name simply starts at one, on the first
        // invoice of the period, with nothing to schedule.
        $parts[] = match ($reset) {
            self::RESET_YEARLY => $issuedAt->format('Y'),
            self::RESET_MONTHLY => $issuedAt->format('Y-m'),
            default => 'all',
        };

        return implode(':', $parts);
    }

    /**
     * Substitute the placeholders.
     *
     * {SEQ:n} zero-pads to n digits, which is what makes invoice numbers sort
     * as strings the way people expect. It does NOT truncate a number that
     * outgrows the padding: INV-2026-100000 after INV-2026-99999 is ugly, and
     * a number silently wrapping to 00000 is a duplicate.
     */
    public static function render(string $format, int $sequence, DateTimeImmutable $issuedAt): string
    {
        $rendered = strtr($format, [
            '{YYYY}' => $issuedAt->format('Y'),
            '{YY}' => $issuedAt->format('y'),
            '{MM}' => $issuedAt->format('m'),
            '{DD}' => $issuedAt->format('d'),
        ]);

        $rendered = (string) preg_replace_callback(
            '/\{SEQ(?::(\d+))?\}/',
            static function (array $match) use ($sequence): string {
                $width = isset($match[1]) ? (int) $match[1] : 0;

                return str_pad((string) $sequence, $width, '0', STR_PAD_LEFT);
            },
            $rendered
        );

        return $rendered;
    }

    /**
     * The sequence numbers a series has issued but that no invoice carries.
     *
     * THIS IS THE ANSWER TO "ARE WE GAPLESS", and it is a report rather than a
     * guarantee because that is what is honestly available. It compares the
     * counter's high-water mark against the numbers actually stored, so a hole
     * left by a rolled-back issue shows up as a specific missing number that
     * somebody can account for — which is what a tax authority actually wants,
     * and what a claim of gaplessness could never provide.
     *
     * @param list<int> $issuedSequenceNumbers Sequence values present on invoices.
     *
     * @return list<int> The missing ones, ascending.
     */
    public function gapReportFor(int $tenantId, string $series, array $issuedSequenceNumbers, bool $perTenant): array
    {
        $highWater = $perTenant
            ? $this->sequences->peek($tenantId, $series)
            : $this->sequences->peek(0, $series);

        $present = array_flip($issuedSequenceNumbers);
        $gaps = [];

        for ($n = 1; $n <= $highWater; $n++) {
            if (!isset($present[$n])) {
                $gaps[] = $n;
            }
        }

        return $gaps;
    }

    /** The registry keys this reads, so callers need not restate them. */
    public const SETTING_FORMAT = SettingsRegistry::BILLING_INVOICE_NUMBER_FORMAT;
    public const SETTING_SCOPE = SettingsRegistry::BILLING_INVOICE_NUMBER_SCOPE;
    public const SETTING_RESET = SettingsRegistry::BILLING_INVOICE_NUMBER_RESET;
}
