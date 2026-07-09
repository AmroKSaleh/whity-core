<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Identity;

use PHPUnit\Framework\TestCase;
use Whity\Core\Identity\DnsTxtResolver;
use Whity\Core\Identity\DomainOwnershipVerifier;

/**
 * Unit tests for {@see DomainOwnershipVerifier} (WC-628738f5) with a stubbed DNS
 * resolver — proves the DNS TXT challenge logic without touching the network.
 */
final class DomainOwnershipVerifierTest extends TestCase
{
    private const TOKEN = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';

    public function testChallengeHostAndValueFormat(): void
    {
        self::assertSame('_whity-verify.acme.com', DomainOwnershipVerifier::challengeHost('Acme.com'));
        self::assertSame('whity-verify=' . self::TOKEN, DomainOwnershipVerifier::challengeValue(self::TOKEN));
    }

    public function testVerifiesWhenMatchingTxtRecordPresent(): void
    {
        $verifier = $this->verifierFor('_whity-verify.acme.com', ['whity-verify=' . self::TOKEN]);
        self::assertTrue($verifier->isVerified('acme.com', self::TOKEN));
    }

    public function testVerifiesAmongMultipleRecordsAndToleratesQuotesWhitespace(): void
    {
        $verifier = $this->verifierFor('_whity-verify.acme.com', [
            'v=spf1 include:_spf.google.com ~all',
            '  "whity-verify=' . self::TOKEN . '" ',
        ]);
        self::assertTrue($verifier->isVerified('acme.com', self::TOKEN));
    }

    public function testFailsWhenNoRecords(): void
    {
        $verifier = $this->verifierFor('_whity-verify.acme.com', []);
        self::assertFalse($verifier->isVerified('acme.com', self::TOKEN));
    }

    public function testFailsWhenTokenMismatch(): void
    {
        $verifier = $this->verifierFor('_whity-verify.acme.com', ['whity-verify=not-the-token']);
        self::assertFalse($verifier->isVerified('acme.com', self::TOKEN));
    }

    public function testFailsForEmptyToken(): void
    {
        // An empty token must never match — otherwise a bare 'whity-verify=' record
        // would spuriously verify.
        $verifier = $this->verifierFor('_whity-verify.acme.com', ['whity-verify=']);
        self::assertFalse($verifier->isVerified('acme.com', ''));
    }

    public function testLooksUpTheChallengeHostNotTheBareDomain(): void
    {
        // A record published at the bare domain (not the _whity-verify label) must
        // NOT count — the resolver is only ever asked for the challenge host.
        $verifier = $this->verifierFor('acme.com', ['whity-verify=' . self::TOKEN]);
        self::assertFalse($verifier->isVerified('acme.com', self::TOKEN));
    }

    /**
     * @param list<string> $records
     */
    private function verifierFor(string $expectedHost, array $records): DomainOwnershipVerifier
    {
        $resolver = new class ($expectedHost, $records) implements DnsTxtResolver {
            /** @param list<string> $records */
            public function __construct(private string $host, private array $records)
            {
            }

            public function txtRecords(string $host): array
            {
                return $host === $this->host ? $this->records : [];
            }
        };

        return new DomainOwnershipVerifier($resolver);
    }
}
