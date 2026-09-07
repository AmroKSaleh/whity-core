<?php

declare(strict_types=1);

namespace Whity\Core\Payment\Cliq;

use DateTimeImmutable;
use Whity\Core\Payment\PaymentEventType;
use Whity\Core\Payment\PaymentProviderException;

/**
 * THE ONE PLACE THAT KNOWS WHAT A CLIQ CALLBACK LOOKS LIKE.
 *
 * ═══ THIS MAPPING IS UNCONFIRMED ═══
 *
 * The field names below have NOT been checked against JoPACC's specification or
 * a sandbox, because neither was available when this was written. They are the
 * plainest reading of a settlement message and they are almost certainly not
 * all correct.
 *
 * That is why they are here, alone, in a class that does nothing else. The
 * SHAPE of the integration — deterministic references, pending-then-confirmed,
 * verification before translation, idempotency on the bank's transaction id,
 * amount matching — is settled and tested. Correcting a field name is then an
 * edit to one constant with a failing test pointing at it, rather than a hunt
 * through an adapter that assumed a payload everywhere it looked.
 *
 * BEFORE THIS RAIL TAKES REAL MONEY, someone must sit with the bank's
 * documentation, fix these constants, and update
 * `CliqPaymentProviderTest::aBankCallback()` to the real payload. The tests
 * will then be exercising the real shape rather than this guess. Until then the
 * adapter is complete and the mapping is provisional, and it is better to say
 * so here than to have it discovered in production.
 *
 * WHY EVERY READER IS DEFENSIVE. A callback is remote input. A missing field is
 * not a crash and a string where a number was expected is not an exception —
 * both are refusals, because the alternative is a 500 that makes the bank retry
 * the same payload forever.
 */
final class CliqWebhookPayload
{
    // ── the unconfirmed field names ──────────────────────────────────────────

    /** The array of settlement records in the envelope. */
    private const KEY_TRANSFERS = 'transfers';

    /** The bank's own identifier — what the ledger keys idempotency on. */
    private const KEY_TRANSACTION_ID = 'transactionId';

    /** The narrative field the payer typed our reference into. */
    private const KEY_REFERENCE = 'remittanceInformation';

    /** Amount, and whether it arrives in minor units or as a decimal string. */
    private const KEY_AMOUNT = 'amount';
    private const KEY_CURRENCY = 'currency';

    /** The bank's own word for what happened. */
    private const KEY_STATUS = 'status';
    private const KEY_REASON = 'reasonDescription';

    /** When the money moved, per the bank — not when we heard. */
    private const KEY_TIMESTAMP = 'valueDate';

    /**
     * The bank's status vocabulary, mapped to ours.
     *
     * Anything unlisted is treated as PENDING rather than as a failure: an
     * unknown status is something we do not understand, and guessing "failed"
     * would feed the dunning machine a failure that may be a success we simply
     * could not read.
     */
    private const STATUS_MAP = [
        'ACSC' => 'succeeded',  // AcceptedSettlementCompleted (ISO 20022)
        'ACCC' => 'succeeded',  // AcceptedCreditSettlementCompleted
        'COMPLETED' => 'succeeded',
        'SUCCESS' => 'succeeded',
        'RJCT' => 'failed',     // Rejected
        'FAILED' => 'failed',
        'CANC' => 'failed',     // Cancelled
        'PDNG' => 'pending',    // Pending
        'ACSP' => 'pending',    // AcceptedSettlementInProcess
    ];

    /**
     * The settlement records in an envelope, tolerating both a list and a
     * single record — banks send both, and which one is not worth a 500.
     *
     * @param array<mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    public static function transfersIn(array $payload): array
    {
        $raw = $payload[self::KEY_TRANSFERS] ?? null;

        if ($raw === null) {
            // A single record posted bare. If it looks like one, treat it as one.
            return isset($payload[self::KEY_TRANSACTION_ID]) ? [self::asStringKeyed($payload)] : [];
        }

        if (!is_array($raw)) {
            return [];
        }

        $transfers = [];
        foreach ($raw as $record) {
            if (is_array($record)) {
                $transfers[] = self::asStringKeyed($record);
            }
        }

        return $transfers;
    }

    /** @param array<string, mixed> $transfer */
    public static function bankTransactionId(array $transfer): string
    {
        $value = $transfer[self::KEY_TRANSACTION_ID] ?? null;

        return is_string($value) || is_int($value) ? (string) $value : '';
    }

    /** @param array<string, mixed> $transfer */
    public static function reference(array $transfer): string
    {
        $value = $transfer[self::KEY_REFERENCE] ?? null;

        return is_string($value) ? $value : '';
    }

    /** @param array<string, mixed> $transfer */
    public static function currency(array $transfer): string
    {
        $value = $transfer[self::KEY_CURRENCY] ?? null;

        // JOD by default because that is what this network settles in, but the
        // field is read rather than assumed: a transfer in another currency
        // must not be silently relabelled as dinars.
        return is_string($value) && $value !== '' ? strtoupper($value) : 'JOD';
    }

    /**
     * The amount in MINOR UNITS, and it must arrive as a DECIMAL STRING.
     *
     * THIS IS THE MOST EXPENSIVE THING IN THIS FILE TO GET WRONG, so it is the
     * strictest. Reading "5.000" JOD as 5 undercharges by a factor of a
     * thousand; reading 5000 as dinars overcharges by the same.
     *
     * A BARE JSON NUMBER IS REFUSED AS AMBIGUOUS. An earlier version here took
     * an integer as minor units, which reads well until you notice that
     * `"amount": 5` cannot say whether it means five dinars or five fils — only
     * the bank's specification can, and it is exactly the thing not yet
     * confirmed. Worse, JSON does not preserve the distinction: an amount
     * encoded as 5.0 decodes to the integer 5 on some configurations, so the
     * "obviously a decimal" case silently became the "obviously minor units"
     * case, off by a thousand, with no error anywhere.
     *
     * A float is refused for the ordinary reason as well — it cannot hold a
     * decimal amount exactly, and this is money that has already moved.
     *
     * Requiring a string is not a compromise: ISO 20022, which this network
     * speaks, represents amounts as decimal strings precisely because they are
     * unambiguous. Parsed at the currency's own precision, "5.000" JOD is 5000
     * fils and nothing else — which is what {@see \Whity\Core\Money\Currency}
     * exists to know. If the real bank turns out to send numbers, this refuses
     * LOUDLY during integration, when somebody is watching, rather than
     * misreading by three orders of magnitude in production.
     *
     * @param array<string, mixed> $transfer
     *
     * @throws PaymentProviderException When the amount is missing or ambiguous.
     */
    public static function amountMinor(array $transfer): int
    {
        $raw = $transfer[self::KEY_AMOUNT] ?? null;

        if (is_string($raw) && trim($raw) !== '') {
            // Refusing to round is the point: an amount with more precision
            // than the currency has is not something to guess at.
            return \Whity\Core\Money\Currency::parse(trim($raw), self::currency($transfer));
        }

        if (is_int($raw) || is_float($raw)) {
            throw new PaymentProviderException(sprintf(
                'A CliQ transfer carried its amount as a JSON number (%s). Refusing it: a '
                . 'bare number cannot say whether it means major or minor units, and the '
                . 'two differ by a thousand in JOD. Amounts must arrive as decimal '
                . 'strings, as ISO 20022 specifies.',
                var_export($raw, true)
            ));
        }

        throw new PaymentProviderException('A CliQ transfer carried no readable amount.');
    }

    /** @param array<string, mixed> $transfer */
    public static function status(array $transfer): PaymentEventType
    {
        $raw = $transfer[self::KEY_STATUS] ?? null;
        $key = is_string($raw) ? strtoupper(trim($raw)) : '';

        return match (self::STATUS_MAP[$key] ?? 'pending') {
            'succeeded' => PaymentEventType::Succeeded,
            'failed' => PaymentEventType::Failed,
            default => PaymentEventType::Pending,
        };
    }

    /** @param array<string, mixed> $transfer */
    public static function reason(array $transfer): ?string
    {
        $value = $transfer[self::KEY_REASON] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * When the bank says the money moved.
     *
     * Null when unreadable, so the caller falls back to now — but never
     * silently uses now when the bank DID say, because a webhook delayed an
     * hour must not date a payment to its own arrival.
     *
     * @param array<string, mixed> $transfer
     */
    public static function occurredAt(array $transfer): ?DateTimeImmutable
    {
        $value = $transfer[self::KEY_TIMESTAMP] ?? null;

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<mixed> $record
     *
     * @return array<string, mixed>
     */
    private static function asStringKeyed(array $record): array
    {
        $out = [];
        foreach ($record as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
