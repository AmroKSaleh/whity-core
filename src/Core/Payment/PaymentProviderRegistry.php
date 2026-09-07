<?php

declare(strict_types=1);

namespace Whity\Core\Payment;

use Whity\Core\Container\HostWiredService;

/**
 * Which rails this deployment has, and which of them can actually take money
 * today.
 *
 * WHY A REGISTRY RATHER THAN A CONSTRUCTOR ARGUMENT. Because the set differs
 * per deployment and per moment: an instance may run CliQ alone, CliQ plus a
 * card provider once one is chosen, or the mock alone in development. Endpoints
 * that resolve a rail by NAME through this can be written once and stay correct
 * as the set changes, which is the requirement that the checkout path must not
 * be structurally CliQ-only.
 *
 * REGISTERED IS NOT THE SAME AS USABLE, and conflating them is the failure this
 * class is shaped to avoid. {@see CardPaymentProviderAdapter} is registered on
 * every instance — it is how the extension point stays visible and testable —
 * but it is not configured, and offering it to a customer would produce a
 * checkout button that throws. So {@see self::available()} filters on
 * configuration and {@see self::all()} does not, and the two have deliberately
 * different names rather than one method with a flag nobody passes.
 *
 * An adapter that does not declare `isConfigured()` is assumed usable: rails
 * like CliQ and the mock either work or are not registered at all.
 */
final class PaymentProviderRegistry implements HostWiredService
{
    /** @var array<string, PaymentProviderAdapter> */
    private array $adapters = [];

    /** @param iterable<PaymentProviderAdapter> $adapters */
    public function __construct(iterable $adapters = [])
    {
        foreach ($adapters as $adapter) {
            $this->register($adapter);
        }
    }

    /**
     * @throws PaymentProviderException When two rails claim one name — which
     *                                  would make `payment_transactions.provider`
     *                                  ambiguous and the idempotency index wrong.
     */
    public function register(PaymentProviderAdapter $adapter): void
    {
        $name = $adapter->name();

        if ($name === '' || strtolower($name) !== $name || str_contains($name, ' ')) {
            throw new PaymentProviderException(sprintf(
                'Provider name "%s" is not storable: it is written into every ledger row '
                . 'and forms half of the idempotency key, so it must be lower case and '
                . 'free of spaces.',
                $name
            ));
        }

        if (isset($this->adapters[$name]) && $this->adapters[$name] !== $adapter) {
            throw new PaymentProviderException(sprintf(
                'Two payment rails both call themselves "%s". Ledger rows would be '
                . 'ambiguous about which produced them, and the (provider, reference) '
                . 'idempotency index would treat two providers as one.',
                $name
            ));
        }

        $this->adapters[$name] = $adapter;
    }

    /**
     * Every registered rail, configured or not.
     *
     * @return array<string, PaymentProviderAdapter>
     */
    public function all(): array
    {
        return $this->adapters;
    }

    /**
     * Only the rails that can take money right now — what a customer may be
     * offered.
     *
     * @return array<string, PaymentProviderAdapter>
     */
    public function available(): array
    {
        return array_filter($this->adapters, static function (PaymentProviderAdapter $adapter): bool {
            return !method_exists($adapter, 'isConfigured') || $adapter->isConfigured() === true;
        });
    }

    public function has(string $name): bool
    {
        return isset($this->adapters[$name]);
    }

    /**
     * A rail by name, for a webhook route or a checkout request.
     *
     * @throws PaymentProviderException When it is not registered.
     */
    public function get(string $name): PaymentProviderAdapter
    {
        return $this->adapters[$name]
            ?? throw new PaymentProviderException("No payment provider is registered as '{$name}'.");
    }

    /**
     * A rail by name, refusing one that cannot currently take money.
     *
     * The distinction from {@see self::get()} matters at the two ends: a
     * WEBHOOK must reach its adapter whether or not it is currently offered to
     * customers, because callbacks arrive for payments started earlier. A
     * CHECKOUT must not, because sending a customer to an unconfigured rail
     * produces an error they cannot act on.
     *
     * @throws PaymentProviderException When it is unregistered or unconfigured.
     */
    public function getUsable(string $name): PaymentProviderAdapter
    {
        $adapter = $this->get($name);

        if (method_exists($adapter, 'isConfigured') && $adapter->isConfigured() !== true) {
            throw new PaymentProviderException(
                "The '{$name}' payment rail is registered but not configured on this "
                . 'instance, so it cannot take a payment.'
            );
        }

        return $adapter;
    }

    /**
     * Rails that can renew a subscription with nobody present.
     *
     * The dunning machine asks this to tell "we will retry the card" apart from
     * "we must ask the human", which are different schedules and different
     * emails.
     *
     * @return array<string, PaymentProviderAdapter>
     */
    public function unattendedCapable(): array
    {
        return array_filter(
            $this->available(),
            static fn (PaymentProviderAdapter $a): bool => $a->capabilities()->supportsUnattendedCharge
        );
    }
}
