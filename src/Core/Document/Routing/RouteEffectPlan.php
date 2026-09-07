<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

/**
 * What an effect asks the platform to do (#1032).
 *
 * AN EFFECT PLANS; THE RUNNER PERFORMS
 * ------------------------------------
 * The obvious alternative was to let each effect DO its own work — resolve a
 * mailer, send, catch. It is rejected for two reasons that are worth having
 * written down, because the alternative will look simpler to the next person.
 *
 * First, an effect runs SYNCHRONOUSLY, after the commit, inside a request that
 * has already succeeded. An effect that performed its own I/O could hold that
 * request open for as long as somebody's SMTP server felt like taking, and a
 * plugin-contributed one could do it without anybody reviewing the call. A plan
 * is a value: producing one cannot block.
 *
 * Second, it keeps effects TESTABLE the way rule resolvers are. A resolver that
 * returns recipients can be asserted against; one that sends mail can only be
 * observed through a double. This class is the same bargain
 * {@see \Whity\Sdk\Routing\RoutingRuleResolverInterface} already makes.
 *
 * WHY THE SHAPE IS NARROW ON PURPOSE
 * ----------------------------------
 * Today a plan is "notify these people, with this type, carrying this data",
 * because notification is what the platform can actually perform and #1032's
 * worked example is sending an email. A wider plan would be speculative: fields
 * for outcomes nothing can carry out yet, which is the stored-intention failure
 * one level up.
 *
 * It is also precisely why {@see RouteEffectInterface} has not been published
 * to the SDK. The first genuinely different effect kind will add a field here,
 * and a contract vendored into every device host and version-pinned cannot
 * quietly gain one.
 */
final class RouteEffectPlan
{
    /**
     * @param list<int>            $profileIds Whom to notify. May not be empty —
     *                                         see {@see notify()}.
     * @param string               $type       The notification type, which is
     *                                         what selects the template.
     * @param array<string, mixed> $data       Template variables.
     */
    private function __construct(
        public readonly array $profileIds,
        public readonly string $type,
        public readonly array $data,
    ) {
    }

    /**
     * Notify these people.
     *
     * @param list<int>            $profileIds
     * @param array<string, mixed> $data
     *
     * @throws InvalidRouteEffectException When the audience is empty or the type
     *         is blank. Both are refused rather than accepted-and-ignored: an
     *         effect that returns a plan reaching nobody is claiming it will do
     *         something, and the honest answer to "nobody to tell" is to return
     *         NULL from the effect, which the runner records as `skipped` with a
     *         reason a reader can see.
     */
    public static function notify(array $profileIds, string $type, array $data = []): self
    {
        $unique = array_values(array_unique(array_filter(
            $profileIds,
            static fn (mixed $id): bool => is_int($id) && $id > 0
        )));

        if ($unique === []) {
            throw InvalidRouteEffectException::forEmptyAudience($type);
        }
        if (trim($type) === '') {
            throw InvalidRouteEffectException::forMissingNotificationType();
        }

        return new self($unique, $type, $data);
    }

    /** How many people this plan reaches. What the runner records on success. */
    public function audienceSize(): int
    {
        return count($this->profileIds);
    }
}
