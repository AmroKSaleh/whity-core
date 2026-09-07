<?php

declare(strict_types=1);

namespace Tests\Core\Settings;

use PHPUnit\Framework\TestCase;
use Whity\Core\Settings\SettingsRegistry;

/**
 * The invoicing settings, and what each of them refuses.
 *
 * These exist because every one of them differs per deployment — tax rate, tax
 * name, whether prices include it, who the seller is, how invoices are numbered
 * — and a constant in the invoicing code would be right for exactly one
 * customer.
 *
 * The validations are worth testing individually because a settings mistake in
 * this area does not fail loudly at save time unless something makes it. It
 * fails later, on a customer's invoice, which is the worst place to find out
 * that "16" was stored where 1600 was meant.
 */
final class BillingSettingsValidationTest extends TestCase
{
    // ── the defaults are the safe ones ───────────────────────────────────────

    /**
     * ZERO, not any country's rate. Charging tax an operator is not registered
     * to collect is a worse failure than not charging it, and a default correct
     * for one jurisdiction and wrong for every other ships unnoticed.
     */
    public function testTaxDefaultsToNothingRatherThanToSomeCountrysRate(): void
    {
        self::assertSame('0', SettingsRegistry::defaultFor(SettingsRegistry::BILLING_TAX_RATE_BP));
        self::assertSame('', SettingsRegistry::defaultFor(SettingsRegistry::BILLING_TAX_LABEL));
    }

    public function testNumberingDefaultsToOneSequenceForThePlatform(): void
    {
        // 'shared' is right when the operator is the seller, which is the
        // ordinary case; 'per_tenant' is for resale deployments.
        self::assertSame('shared', SettingsRegistry::defaultFor(SettingsRegistry::BILLING_INVOICE_NUMBER_SCOPE));
    }

    // ── tax rate ─────────────────────────────────────────────────────────────

    public function testABasisPointRateIsAccepted(): void
    {
        // 16% — Jordan's general sales tax, as an operator there would set it.
        self::assertNull(SettingsRegistry::validate(SettingsRegistry::BILLING_TAX_RATE_BP, '1600'));
        self::assertNull(SettingsRegistry::validate(SettingsRegistry::BILLING_TAX_RATE_BP, '0'));
        // 7.5%, which is why the unit is basis points and not whole per cent.
        self::assertNull(SettingsRegistry::validate(SettingsRegistry::BILLING_TAX_RATE_BP, '750'));
    }

    /**
     * A rate above 100% is always a units mistake — somebody typed 16 meaning
     * sixteen per cent, got 0.16%, and "corrected" it to 1600%.
     */
    public function testARateAboveOneHundredPerCentIsRefused(): void
    {
        self::assertNotNull(SettingsRegistry::validate(SettingsRegistry::BILLING_TAX_RATE_BP, '10001'));
        self::assertNotNull(SettingsRegistry::validate(SettingsRegistry::BILLING_TAX_RATE_BP, '160000'));
    }

    public function testAFractionalOrNegativeRateIsRefused(): void
    {
        self::assertNotNull(SettingsRegistry::validate(SettingsRegistry::BILLING_TAX_RATE_BP, '16.5'));
        self::assertNotNull(SettingsRegistry::validate(SettingsRegistry::BILLING_TAX_RATE_BP, '-100'));
        self::assertNotNull(SettingsRegistry::validate(SettingsRegistry::BILLING_TAX_RATE_BP, 'sixteen'));
    }

    // ── currency ─────────────────────────────────────────────────────────────

    public function testAThreeLetterCodeIsAccepted(): void
    {
        self::assertNull(SettingsRegistry::validate(SettingsRegistry::BILLING_DEFAULT_CURRENCY, 'JOD'));
        self::assertNull(SettingsRegistry::validate(SettingsRegistry::BILLING_DEFAULT_CURRENCY, 'USD'));
    }

    public function testSomethingThatIsNotACurrencyCodeIsRefused(): void
    {
        self::assertNotNull(SettingsRegistry::validate(SettingsRegistry::BILLING_DEFAULT_CURRENCY, 'dinars'));
        self::assertNotNull(SettingsRegistry::validate(SettingsRegistry::BILLING_DEFAULT_CURRENCY, ''));
    }

    // ── invoice numbering ────────────────────────────────────────────────────

    public function testAFormatCarryingASequencePlaceholderIsAccepted(): void
    {
        foreach (['INV-{YYYY}-{SEQ:5}', '{SEQ}', '{YY}{MM}-{SEQ:4}', 'ACME/{YYYY}/{SEQ:6}'] as $format) {
            self::assertNull(
                SettingsRegistry::validate(SettingsRegistry::BILLING_INVOICE_NUMBER_FORMAT, $format),
                $format
            );
        }
    }

    /**
     * THE ONE THAT MATTERS. A format with no sequence placeholder gives every
     * invoice in a series the same number, and the uniqueness index rejects the
     * second one — at issue time, on a customer's invoice, which is the worst
     * possible moment to discover a settings typo.
     */
    public function testAFormatWithNoSequencePlaceholderIsRefused(): void
    {
        $error = SettingsRegistry::validate(
            SettingsRegistry::BILLING_INVOICE_NUMBER_FORMAT,
            'INV-{YYYY}'
        );

        self::assertNotNull($error);
        self::assertStringContainsString('SEQ', $error);
    }

    public function testAnEmptyFormatIsRefused(): void
    {
        self::assertNotNull(SettingsRegistry::validate(SettingsRegistry::BILLING_INVOICE_NUMBER_FORMAT, ''));
    }

    /** The number has to fit the column it is stored in. */
    public function testAFormatTooLongForTheColumnIsRefused(): void
    {
        self::assertNotNull(SettingsRegistry::validate(
            SettingsRegistry::BILLING_INVOICE_NUMBER_FORMAT,
            str_repeat('X', 60) . '{SEQ:5}'
        ));
    }

    public function testAnUnknownNumberingScopeIsRefused(): void
    {
        self::assertNull(SettingsRegistry::validate(SettingsRegistry::BILLING_INVOICE_NUMBER_SCOPE, 'per_tenant'));
        self::assertNotNull(SettingsRegistry::validate(SettingsRegistry::BILLING_INVOICE_NUMBER_SCOPE, 'per_user'));
    }

    // ── payment terms ────────────────────────────────────────────────────────

    /** Zero is legitimate: due on receipt. */
    public function testDueOnReceiptIsAValidTerm(): void
    {
        self::assertNull(SettingsRegistry::validate(SettingsRegistry::BILLING_PAYMENT_TERMS_DAYS, '0'));
        self::assertNull(SettingsRegistry::validate(SettingsRegistry::BILLING_PAYMENT_TERMS_DAYS, '30'));
    }

    public function testAnAbsurdPaymentTermIsRefused(): void
    {
        self::assertNotNull(SettingsRegistry::validate(SettingsRegistry::BILLING_PAYMENT_TERMS_DAYS, '4000'));
        self::assertNotNull(SettingsRegistry::validate(SettingsRegistry::BILLING_PAYMENT_TERMS_DAYS, '-1'));
    }

    // ── which layer each key belongs to ──────────────────────────────────────

    /**
     * NUMBERING IS GLOBAL-ONLY. A per-tenant override would make the meaning of
     * the (series, number) uniqueness index depend on a setting a tenant admin
     * can change, which is how a sequence quietly starts issuing duplicates.
     */
    public function testNumberingCannotBeOverriddenByATenant(): void
    {
        self::assertTrue(SettingsRegistry::isGlobalOnly(SettingsRegistry::BILLING_INVOICE_NUMBER_FORMAT));
        self::assertTrue(SettingsRegistry::isGlobalOnly(SettingsRegistry::BILLING_INVOICE_NUMBER_SCOPE));
        self::assertTrue(SettingsRegistry::isGlobalOnly(SettingsRegistry::BILLING_INVOICE_NUMBER_RESET));
    }

    /**
     * Tax treatment and seller identity ARE per-tenant, because a white-label
     * deployment has tenants invoicing as themselves, in their own
     * jurisdictions.
     */
    public function testTaxAndSellerIdentityAreTenantOverridable(): void
    {
        foreach ([
            SettingsRegistry::BILLING_TAX_RATE_BP,
            SettingsRegistry::BILLING_TAX_LABEL,
            SettingsRegistry::BILLING_TAX_INCLUSIVE,
            SettingsRegistry::BILLING_DEFAULT_CURRENCY,
            SettingsRegistry::BILLING_PAYMENT_TERMS_DAYS,
            SettingsRegistry::BILLING_SELLER_NAME,
            SettingsRegistry::BILLING_SELLER_ADDRESS,
            SettingsRegistry::BILLING_SELLER_TAX_ID,
        ] as $key) {
            self::assertFalse(SettingsRegistry::isGlobalOnly($key), $key);
            self::assertContains($key, SettingsRegistry::tenantTextKeys(), $key);
        }
    }

    public function testTheTaxInclusiveFlagIsATypedBoolean(): void
    {
        self::assertSame('bool', SettingsRegistry::typeFor(SettingsRegistry::BILLING_TAX_INCLUSIVE));
        self::assertNotNull(SettingsRegistry::validate(SettingsRegistry::BILLING_TAX_INCLUSIVE, 'maybe'));
    }
}
