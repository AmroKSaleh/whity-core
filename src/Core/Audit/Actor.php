<?php

declare(strict_types=1);

namespace Whity\Core\Audit;

/**
 * Who did something (WHIT-753): a person, an agent acting for a person, or the
 * system. Recorded on every audit row and domain event, so an action can always
 * be shown as "Rana", "Billing assistant for Rana" or "Whity".
 *
 * Immutable value object.
 */
final class Actor
{
    public const PERSON = 'person';
    public const AGENT  = 'agent';
    public const SYSTEM = 'system';

    private function __construct(
        public readonly string $kind,
        public readonly ?int $profileId,
        public readonly ?string $agentPrincipalId,
        public readonly ?int $onBehalfOfProfileId,
    ) {
    }

    /** A person acting themselves, through a session or their own client. */
    public static function person(int $profileId): self
    {
        return new self(self::PERSON, $profileId, null, null);
    }

    /**
     * An agent credential acting for the person who granted it. The profile is
     * that person's: an agent never acts as anyone else, and never as more.
     */
    public static function agent(string $agentPrincipalId, int $onBehalfOfProfileId): self
    {
        return new self(self::AGENT, $onBehalfOfProfileId, $agentPrincipalId, $onBehalfOfProfileId);
    }

    /** Jobs, automations and the CLI: no person and no agent. */
    public static function system(): self
    {
        return new self(self::SYSTEM, null, null, null);
    }
}
