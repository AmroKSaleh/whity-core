<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Whity\Http\Middleware\EnforceTenantIsolation;

/**
 * Which paths are reachable WITHOUT a session, asked of the middleware that
 * decides it.
 *
 * THE BUG THIS EXISTS FOR. The payment webhook shipped registered as an
 * unauthenticated route, with a handler that verified signatures correctly and
 * a suite of tests that all passed. Every request to it returned 401, because
 * {@see EnforceTenantIsolation} refuses a path it does not recognise BEFORE the
 * router ever matches it — and a bank cannot hold a session, so no callback
 * could be delivered, no invoice could settle, and a tenant who had paid stayed
 * locked out.
 *
 * The handler's own tests could not have caught it. They call
 * `PaymentWebhookApiHandler::receive()` directly, which is the right way to
 * test a handler and says nothing whatever about whether a request reaches it.
 * The route was registered with `null` for auth, which reads like it settles
 * the question and does not: it tells the ROUTER not to require a session, and
 * the middleware above the router has its own list.
 *
 * So this asserts the property at the layer that owns it, for every route whose
 * whole purpose is to be reachable by somebody with no account: a bank, a
 * courier holding a printed decision, a stranger filling in a public form.
 */
final class PublicRouteReachabilityTest extends TestCase
{
    /**
     * Paths that MUST bypass tenant resolution, each with the reason it cannot
     * have a session.
     *
     * @return iterable<string, array{string}>
     */
    public static function pathsWithNoPossibleSession(): iterable
    {
        yield 'a bank posting a settlement callback' => ['/api/v1/payments/webhook/cliq'];
        yield 'any payment provider, by name' => ['/api/v1/payments/webhook/anything'];
        yield 'a citizen verifying a printed document' => ['/api/v1/document-verifications/sometoken'];
        yield 'a browser fetching the UI language before login' => ['/api/v1/translations/ar/admin'];
    }

    /** @dataProvider pathsWithNoPossibleSession */
    public function testAPathWithNoPossibleSessionIsReachable(string $path): void
    {
        self::assertTrue(
            EnforceTenantIsolation::pathBypassesTenantResolution($path),
            "{$path} is refused before it is routed, so nobody without an account can reach it — "
            . 'which for this path means it can never be used at all.'
        );
    }

    /**
     * AND THE OPENING IS EXACTLY ONE SEGMENT WIDE.
     *
     * This is the lesson `/api/v1/translations/` records in the middleware: it
     * was an open prefix, and the first admin route added beneath it inherited
     * public reachability silently. The payment surface is where that mistake
     * would be most expensive, so anything deeper than the provider name must
     * still be refused.
     *
     * @dataProvider pathsThatMustStayPrivate
     */
    public function testTheOpeningDoesNotExtendBeyondTheCallbackItself(string $path): void
    {
        self::assertFalse(
            EnforceTenantIsolation::pathBypassesTenantResolution($path),
            "{$path} bypasses tenant resolution, which it must not — the webhook opening is "
            . 'anchored to one segment precisely so the next route under /payments/ is not '
            . 'public by accident.'
        );
    }

    /** @return iterable<string, array{string}> */
    public static function pathsThatMustStayPrivate(): iterable
    {
        yield 'anything deeper than the provider name' => ['/api/v1/payments/webhook/cliq/replay'];
        yield 'the payments root' => ['/api/v1/payments'];
        yield 'a sibling under payments' => ['/api/v1/payments/methods'];
        yield 'a tenant reading its own invoices' => ['/api/v1/billing/invoices'];
        yield 'a tenant starting a payment' => ['/api/v1/billing/invoices/7/pay'];
        yield 'the operator plan catalogue' => ['/api/v1/plans'];
        yield 'the operator promotion catalogue' => ['/api/v1/promotions'];
    }

    /**
     * The billing surface a CUSTOMER uses is not public, and that distinction is
     * the whole point of anchoring: the callback is reachable because a bank has
     * no account, while everything a person reaches has one and must resolve a
     * tenant before RBAC can say anything about it.
     */
    public function testTheTenantFacingBillingSurfaceStaysBehindAuth(): void
    {
        foreach (['/api/v1/billing/invoices', '/api/v1/billing/methods'] as $path) {
            self::assertFalse(EnforceTenantIsolation::pathBypassesTenantResolution($path), $path);
        }
    }
}
