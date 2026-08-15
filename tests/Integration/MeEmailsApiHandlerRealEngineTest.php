<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\MeEmailsApiHandler;
use Whity\Auth\TokenValidator;
use Whity\Core\Identity\EmailVerificationProvider;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Request;
use Whity\Core\Store\SharedStoreInterface;

/**
 * Real-engine tests for {@see MeEmailsApiHandler} (WC-54fb5c37): the caller
 * lists / adds / resends-verification-for / promotes / removes their own
 * profile_emails rows, scoped to their profile, with the "always at least one
 * email, never remove the primary while others exist" lockout guards.
 * TokenValidator is mocked to supply claims; the verification provider and
 * shared store are mocked/faked since delivery and rate-limiting are not this
 * handler's own concern to re-verify here.
 */
final class MeEmailsApiHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;
    private ProfileEmailRepository $emails;
    private int $profileId;
    private int $otherProfileId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->emails = new ProfileEmailRepository($this->pdo);
        $this->profileId = $this->seedProfile('me');
        $this->otherProfileId = $this->seedProfile('other');
    }

    private function seedProfile(string $name): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO profiles
            (display_name, password_hash, two_factor_enabled, two_factor_secret,
             two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (:dn, :ph, false, NULL, 0, 0, NOW(), NOW())");
        if ($stmt === false) {
            self::fail('prepare failed');
        }
        $stmt->execute([':dn' => $name, ':ph' => password_hash('x', PASSWORD_BCRYPT)]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * A no-op provider that appends every call it received into `$calls` (passed
     * by reference so the caller can assert on it — the anonymous class itself
     * can't be usefully type-hinted past the EmailVerificationProvider interface,
     * so tracking calls via an external array avoids property access PHPStan
     * cannot see through).
     *
     * @param list<array{profileId: int, email: string}> $calls
     */
    private function fakeProvider(array &$calls = []): EmailVerificationProvider
    {
        return new class ($calls) implements EmailVerificationProvider {
            /**
             * @param list<array{profileId: int, email: string}> $calls
             */
            // Read externally through the reference by the test that passed
            // $calls in — PHPStan cannot trace reads across a by-ref alias.
            // @phpstan-ignore property.onlyWritten
            public function __construct(private array &$calls)
            {
            }

            public function sendVerification(int $profileId, string $email): void
            {
                $this->calls[] = ['profileId' => $profileId, 'email' => $email];
            }
        };
    }

    /** In-memory SharedStoreInterface fake — no external store dependency needed for these tests. */
    private function fakeStore(): SharedStoreInterface
    {
        return new class implements SharedStoreInterface {
            /** @var array<string, int> */
            private array $counts = [];

            public function increment(string $key, int $ttlSeconds): int
            {
                $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
                return $this->counts[$key];
            }

            public function decrement(string $key): int
            {
                $this->counts[$key] = max(0, ($this->counts[$key] ?? 0) - 1);
                return $this->counts[$key];
            }

            public function count(string $key): int
            {
                return $this->counts[$key] ?? 0;
            }

            public function ttl(string $key): int
            {
                return 60;
            }

            public function delete(string $key): void
            {
                unset($this->counts[$key]);
            }

            public function prune(): int
            {
                return 0;
            }
        };
    }

    private function handlerFor(?int $profileId, ?EmailVerificationProvider $provider = null): MeEmailsApiHandler
    {
        $tv = $this->createMock(TokenValidator::class);
        $tv->method('validateAccessToken')->willReturn($profileId === null ? null : ['profile_id' => $profileId]);

        $unused = [];
        return new MeEmailsApiHandler(
            $tv,
            $this->emails,
            $provider ?? $this->fakeProvider($unused),
            $this->fakeStore(),
            new AuditLogger($this->pdo),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(\Whity\Sdk\Http\Response $res): array
    {
        $d = json_decode($res->getBody(), true);
        return is_array($d) ? $d : [];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(string $method, string $path, array $body): Request
    {
        return new Request($method, $path, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    // ==================== list ====================

    public function testListReturnsOnlyCallersEmailsNewestFirstBySeedOrder(): void
    {
        $this->emails->insert($this->profileId, 'a@example.com', verified: true, isPrimary: true);
        $this->emails->insert($this->profileId, 'b@example.com', verified: false);
        $this->emails->insert($this->otherProfileId, 'x@example.com', verified: true, isPrimary: true);

        $res = $this->handlerFor($this->profileId)->list(new Request('GET', '/api/me/emails', [], ''));
        self::assertSame(200, $res->getStatusCode());
        $data = $this->decode($res)['data'];
        self::assertCount(2, $data);
        self::assertSame(['a@example.com', 'b@example.com'], array_column($data, 'email'));
        self::assertTrue($data[0]['isPrimary']);
        self::assertFalse($data[1]['verified']);
    }

    public function testUnauthenticatedIs401(): void
    {
        $res = $this->handlerFor(null)->list(new Request('GET', '/api/me/emails', [], ''));
        self::assertSame(401, $res->getStatusCode());
    }

    // ==================== add ====================

    public function testAddCreatesUnverifiedNonPrimaryAndTriggersVerification(): void
    {
        $calls = [];
        $res = $this->handlerFor($this->profileId, $this->fakeProvider($calls))->add(
            $this->jsonRequest('POST', '/api/me/emails', ['email' => 'New@Example.com'])
        );

        self::assertSame(201, $res->getStatusCode(), $res->getBody());
        $body = $this->decode($res)['data'];
        self::assertSame('new@example.com', $body['email'], 'normalized to lowercase');
        self::assertFalse($body['verified']);
        self::assertFalse($body['isPrimary']);

        self::assertCount(1, $calls);
        self::assertSame('new@example.com', $calls[0]['email']);
        self::assertSame($this->profileId, $calls[0]['profileId']);
    }

    public function testAddRejectsInvalidEmail(): void
    {
        $res = $this->handlerFor($this->profileId)->add(
            $this->jsonRequest('POST', '/api/me/emails', ['email' => 'not-an-email'])
        );
        self::assertSame(422, $res->getStatusCode());
    }

    public function testAddRejectsAlreadyRegisteredEmailEvenForAnotherProfile(): void
    {
        $this->emails->insert($this->otherProfileId, 'taken@example.com', verified: true, isPrimary: true);

        $res = $this->handlerFor($this->profileId)->add(
            $this->jsonRequest('POST', '/api/me/emails', ['email' => 'taken@example.com'])
        );
        self::assertSame(409, $res->getStatusCode());
    }

    public function testAddEnforcesTheMaximumEmailsCap(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->emails->insert($this->profileId, "n{$i}@example.com", verified: true, isPrimary: $i === 0);
        }

        $res = $this->handlerFor($this->profileId)->add(
            $this->jsonRequest('POST', '/api/me/emails', ['email' => 'overflow@example.com'])
        );
        self::assertSame(422, $res->getStatusCode());
    }

    // ==================== resend-verification ====================

    public function testResendVerificationSendsAgainForUnverifiedOwnEmail(): void
    {
        $id = $this->emails->insert($this->profileId, 'pending@example.com', verified: false);
        $calls = [];

        $res = $this->handlerFor($this->profileId, $this->fakeProvider($calls))->resendVerification(
            new Request('POST', "/api/me/emails/{$id}/resend-verification", [], ''),
            ['id' => (string) $id]
        );
        self::assertSame(202, $res->getStatusCode());
        self::assertCount(1, $calls);
    }

    public function testResendVerificationRejectsAlreadyVerifiedEmail(): void
    {
        $id = $this->emails->insert($this->profileId, 'done@example.com', verified: true, isPrimary: true);

        $res = $this->handlerFor($this->profileId)->resendVerification(
            new Request('POST', "/api/me/emails/{$id}/resend-verification", [], ''),
            ['id' => (string) $id]
        );
        self::assertSame(400, $res->getStatusCode());
    }

    public function testResendVerificationOnForeignEmailIs404(): void
    {
        $foreignId = $this->emails->insert($this->otherProfileId, 'foreign@example.com', verified: false);

        $res = $this->handlerFor($this->profileId)->resendVerification(
            new Request('POST', "/api/me/emails/{$foreignId}/resend-verification", [], ''),
            ['id' => (string) $foreignId]
        );
        self::assertSame(404, $res->getStatusCode());
    }

    // ==================== set-primary ====================

    public function testSetPrimaryPromotesAVerifiedEmail(): void
    {
        $this->emails->insert($this->profileId, 'a@example.com', verified: true, isPrimary: true);
        $id = $this->emails->insert($this->profileId, 'b@example.com', verified: true);

        $res = $this->handlerFor($this->profileId)->setPrimary(
            new Request('POST', "/api/me/emails/{$id}/set-primary", [], ''),
            ['id' => (string) $id]
        );
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        self::assertTrue($this->decode($res)['data']['isPrimary']);

        $primary = $this->emails->findPrimaryForProfile($this->profileId);
        self::assertNotNull($primary);
        self::assertSame('b@example.com', $primary['email']);
    }

    public function testSetPrimaryRejectsUnverifiedEmail(): void
    {
        $this->emails->insert($this->profileId, 'a@example.com', verified: true, isPrimary: true);
        $id = $this->emails->insert($this->profileId, 'unverified@example.com', verified: false);

        $res = $this->handlerFor($this->profileId)->setPrimary(
            new Request('POST', "/api/me/emails/{$id}/set-primary", [], ''),
            ['id' => (string) $id]
        );
        self::assertSame(400, $res->getStatusCode());
    }

    public function testCannotSetForeignEmailAsPrimary(): void
    {
        $foreignId = $this->emails->insert($this->otherProfileId, 'foreign@example.com', verified: true, isPrimary: true);

        $res = $this->handlerFor($this->profileId)->setPrimary(
            new Request('POST', "/api/me/emails/{$foreignId}/set-primary", [], ''),
            ['id' => (string) $foreignId]
        );
        self::assertSame(404, $res->getStatusCode());
    }

    // ==================== remove ====================

    public function testRemoveDeletesANonPrimaryOwnEmail(): void
    {
        $this->emails->insert($this->profileId, 'a@example.com', verified: true, isPrimary: true);
        $id = $this->emails->insert($this->profileId, 'b@example.com', verified: false);

        $res = $this->handlerFor($this->profileId)->remove(
            new Request('DELETE', "/api/me/emails/{$id}", [], ''),
            ['id' => (string) $id]
        );
        self::assertSame(204, $res->getStatusCode());
        self::assertNull($this->emails->findById($id));
    }

    public function testCannotRemoveTheOnlyEmail(): void
    {
        $id = $this->emails->insert($this->profileId, 'only@example.com', verified: true, isPrimary: true);

        $res = $this->handlerFor($this->profileId)->remove(
            new Request('DELETE', "/api/me/emails/{$id}", [], ''),
            ['id' => (string) $id]
        );
        self::assertSame(409, $res->getStatusCode());
        self::assertNotNull($this->emails->findById($id));
    }

    public function testCannotRemoveThePrimaryEmailWhileAnotherExists(): void
    {
        $primaryId = $this->emails->insert($this->profileId, 'primary@example.com', verified: true, isPrimary: true);
        $this->emails->insert($this->profileId, 'secondary@example.com', verified: true);

        $res = $this->handlerFor($this->profileId)->remove(
            new Request('DELETE', "/api/me/emails/{$primaryId}", [], ''),
            ['id' => (string) $primaryId]
        );
        self::assertSame(409, $res->getStatusCode());
        self::assertNotNull($this->emails->findById($primaryId));
    }

    public function testCannotRemoveAnotherProfilesEmail(): void
    {
        $foreignId = $this->emails->insert($this->otherProfileId, 'foreign@example.com', verified: true, isPrimary: true);
        $this->emails->insert($this->otherProfileId, 'foreign2@example.com', verified: true);

        $res = $this->handlerFor($this->profileId)->remove(
            new Request('DELETE', "/api/me/emails/{$foreignId}", [], ''),
            ['id' => (string) $foreignId]
        );
        self::assertSame(404, $res->getStatusCode());
        self::assertNotNull($this->emails->findById($foreignId));
    }
}
