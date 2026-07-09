<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

/**
 * Verifies that a tenant controls a domain via a DNS TXT challenge (WC-628738f5).
 *
 * The tenant publishes a TXT record at `_whity-verify.<domain>` whose value is
 * `whity-verify=<token>`, where `<token>` is the opaque secret this server issued
 * for that registration. Only someone who controls the domain's DNS can publish
 * it, so a matching record proves ownership — which is the precondition for the
 * domain to auto-provision memberships (closing the cross-tenant harvesting hole
 * where a tenant could claim a domain it does not own).
 */
final class DomainOwnershipVerifier
{
    private const LABEL_PREFIX = '_whity-verify.';
    private const VALUE_PREFIX = 'whity-verify=';

    public function __construct(private readonly DnsTxtResolver $resolver)
    {
    }

    /**
     * The DNS host the tenant must publish the challenge TXT record at.
     */
    public static function challengeHost(string $domain): string
    {
        return self::LABEL_PREFIX . strtolower(trim($domain));
    }

    /**
     * The exact TXT record value the tenant must publish.
     */
    public static function challengeValue(string $token): string
    {
        return self::VALUE_PREFIX . $token;
    }

    /**
     * True when a TXT record proving control of `$domain` with `$token` is present.
     *
     * The comparison is constant-time per candidate to avoid leaking the token
     * through timing, and tolerant of surrounding quotes/whitespace some resolvers
     * add.
     */
    public function isVerified(string $domain, string $token): bool
    {
        if ($token === '') {
            return false;
        }

        $expected = self::challengeValue($token);
        foreach ($this->resolver->txtRecords(self::challengeHost($domain)) as $record) {
            $candidate = trim(trim($record), '"');
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }
        return false;
    }
}
