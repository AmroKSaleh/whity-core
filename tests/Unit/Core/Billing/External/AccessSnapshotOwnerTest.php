<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Billing\External;

use PHPUnit\Framework\TestCase;
use Whity\Core\Billing\External\AccessSnapshot;

/**
 * Reading WHO OWNS a subject's billing out of the access reply.
 *
 * This platform ships a billing engine and can also be pointed at an external billing service,
 * so one payer can be reachable by both. The service publishes an owner on the reply we already
 * fetch; these tests are about reading it without breaking a deployment whose service has never
 * heard of the field.
 */
final class AccessSnapshotOwnerTest extends TestCase
{
    /**
     * An owner that is not us means hands off, WHOEVER it is.
     *
     * Named for us rather than for one vendor, so a second billing service is recognised
     * without a code change. Matching a particular service's name would leave every other
     * service unguarded while looking perfectly correct.
     */
    public function testAnySubjectOwnedBySomebodyElseIsRecognised(): void
    {
        foreach (['some-billing-service', 'another-one', 'operator'] as $owner) {
            $snapshot = AccessSnapshot::fromPayload('tenant-42', [
                'has_access' => true,
                'billed_by' => $owner,
            ]);

            self::assertTrue(
                $snapshot->billedByService,
                "An owner this platform does not recognise left the subject unguarded: {$owner}",
            );
        }
    }

    /** And when the service says the subject is the product's, it is ours to bill. */
    public function testASubjectTheServiceSaysWeOwnIsNotClaimed(): void
    {
        $snapshot = AccessSnapshot::fromPayload('tenant-42', [
            'has_access' => true,
            'billed_by' => AccessSnapshot::BILLED_BY_PRODUCT,
        ]);

        self::assertFalse($snapshot->billedByService);
    }

    /**
     * A MISSING FIELD MUST NOT STOP A DEPLOYMENT BILLING, which is the decision this test
     * exists to pin.
     *
     * The tempting default is the cautious-looking one: treat silence as "the service owns
     * them" and refuse to bill. That turns an older billing service, or one that is not this
     * one at all, into a deployment that quietly stops invoicing everybody — with no error, no
     * log line, and nothing to connect the symptom to a JSON key nobody sent. The live
     * double-charge is still covered by the external-reference guard in SubscriptionBillingRun,
     * so reading silence as "not said" leaves this purely additive.
     */
    public function testAnAbsentOwnerDoesNotClaimTheSubject(): void
    {
        $snapshot = AccessSnapshot::fromPayload('tenant-42', ['has_access' => true]);

        self::assertFalse(
            $snapshot->billedByService,
            'A billing service that never sent an owner silently stopped local billing.',
        );
    }

    /**
     * A value in a shape nobody agreed is NOT an owner.
     *
     * null, empty, a number, a boolean, an array: none of these names anybody, and reading one
     * as "somebody else owns this" would stop a deployment billing on the strength of a typo in
     * a serialiser.
     */
    public function testAMalformedOwnerDoesNotClaimTheSubject(): void
    {
        foreach ([null, '', 1, 0, true, false, ['x'], 1.5] as $value) {
            $snapshot = AccessSnapshot::fromPayload('tenant-42', [
                'has_access' => true,
                'billed_by' => $value,
            ]);

            self::assertFalse(
                $snapshot->billedByService,
                'A malformed owner value claimed the subject: ' . var_export($value, true),
            );
        }
    }

    /**
     * Ownership is independent of ACCESS, which is the distinction the column exists for.
     *
     * A subject the service owns but has never billed has no access and no subscription
     * reference — and is exactly the one the existing external-reference guard cannot see.
     */
    public function testASubjectCanBeOwnedWithoutHavingAccess(): void
    {
        $snapshot = AccessSnapshot::fromPayload('tenant-42', [
            'has_access' => false,
            'billed_by' => 'some-billing-service',
        ]);

        self::assertTrue($snapshot->billedByService);
        self::assertFalse($snapshot->hasAccess);
        self::assertNull($snapshot->subscriptionRef);
    }

    /** A tenant nobody has transacted with is nobody's, which is what `none` must mean. */
    public function testAnUnknownSubjectIsNotOwned(): void
    {
        self::assertFalse(AccessSnapshot::none('tenant-42')->billedByService);
    }
}
