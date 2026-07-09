<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\MeIdentitiesApiHandler;
use Whity\Auth\TokenValidator;
use Whity\Core\Identity\ExternalIdentityRepository;
use Whity\Core\Request;

/**
 * Real-engine tests for {@see MeIdentitiesApiHandler} (WC-f3b17bd2): the caller
 * lists / unlinks their own SSO identities, scoped to their profile, with the
 * last-sign-in-method lockout guard. TokenValidator is mocked to supply claims.
 */
final class MeIdentitiesApiHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;
    private ExternalIdentityRepository $identities;
    private int $profileId;
    private int $otherProfileId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->identities = new ExternalIdentityRepository($this->pdo);
        $this->profileId = $this->seedProfile('with-pw', password_hash('x', PASSWORD_BCRYPT));
        $this->otherProfileId = $this->seedProfile('other', password_hash('x', PASSWORD_BCRYPT));
    }

    private function seedProfile(string $name, string $passwordHash): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO profiles
            (display_name, password_hash, two_factor_enabled, two_factor_secret,
             two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (:dn, :ph, false, NULL, 0, 0, NOW(), NOW())");
        if ($stmt === false) {
            self::fail('prepare failed');
        }
        $stmt->execute([':dn' => $name, ':ph' => $passwordHash]);
        return (int) $this->pdo->lastInsertId();
    }

    private function handlerFor(?int $profileId): MeIdentitiesApiHandler
    {
        $tv = $this->createMock(TokenValidator::class);
        $tv->method('validateAccessToken')->willReturn($profileId === null ? null : ['profile_id' => $profileId]);
        return new MeIdentitiesApiHandler($tv, $this->identities, $this->pdo);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(\Whity\Sdk\Http\Response $res): array
    {
        $d = json_decode($res->getBody(), true);
        return is_array($d) ? $d : [];
    }

    public function testListReturnsOnlyCallersIdentitiesWithoutSubject(): void
    {
        $this->identities->link($this->profileId, 'google', 'iss-g', 'sub-g', 'a@b.com');
        $this->identities->link($this->profileId, 'microsoft', 'iss-m', 'sub-m', 'a@corp.com');
        $this->identities->link($this->otherProfileId, 'google', 'iss-g', 'sub-other', 'x@y.com');

        $res = $this->handlerFor($this->profileId)->list(new Request('GET', '/api/me/identities', [], ''));
        self::assertSame(200, $res->getStatusCode());
        $data = $this->decode($res)['data'];
        self::assertCount(2, $data);
        self::assertArrayHasKey('provider_key', $data[0]);
        self::assertArrayNotHasKey('subject', $data[0], 'the opaque subject is not exposed');
    }

    public function testUnauthenticatedIs401(): void
    {
        $res = $this->handlerFor(null)->list(new Request('GET', '/api/me/identities', [], ''));
        self::assertSame(401, $res->getStatusCode());
    }

    public function testUnlinkRemovesOwnIdentity(): void
    {
        $id = $this->identities->link($this->profileId, 'google', 'iss-g', 'sub-g', 'a@b.com');
        $this->identities->link($this->profileId, 'microsoft', 'iss-m', 'sub-m', 'a@corp.com');

        $res = $this->handlerFor($this->profileId)->unlink(
            new Request('DELETE', '/api/me/identities/' . $id, [], ''),
            ['id' => (string) $id]
        );
        self::assertSame(204, $res->getStatusCode());
        self::assertNull($this->identities->findByIssuerSubject('iss-g', 'sub-g'));
    }

    public function testCannotUnlinkAnotherProfilesIdentity(): void
    {
        $foreignId = $this->identities->link($this->otherProfileId, 'google', 'iss-g', 'sub-other', 'x@y.com');

        $res = $this->handlerFor($this->profileId)->unlink(
            new Request('DELETE', '/api/me/identities/' . $foreignId, [], ''),
            ['id' => (string) $foreignId]
        );
        self::assertSame(404, $res->getStatusCode());
        self::assertNotNull($this->identities->findByIssuerSubject('iss-g', 'sub-other'), 'the foreign link survives');
    }

    public function testCannotUnlinkLastIdentityOfPasswordlessAccount(): void
    {
        // Passwordless (SSO-provisioned) profile with a single linked identity.
        $ssoOnly = $this->seedProfile('sso-only', '');
        $id = $this->identities->link($ssoOnly, 'google', 'iss-g', 'sub-sso', 's@sso.com');

        $res = $this->handlerFor($ssoOnly)->unlink(
            new Request('DELETE', '/api/me/identities/' . $id, [], ''),
            ['id' => (string) $id]
        );
        self::assertSame(409, $res->getStatusCode());
        self::assertNotNull($this->identities->findByIssuerSubject('iss-g', 'sub-sso'), 'the only sign-in method is preserved');
    }

    public function testPasswordlessAccountCanUnlinkWhenAnotherIdentityRemains(): void
    {
        $ssoOnly = $this->seedProfile('sso-multi', '');
        $id = $this->identities->link($ssoOnly, 'google', 'iss-g', 'sub-a', 's@sso.com');
        $this->identities->link($ssoOnly, 'microsoft', 'iss-m', 'sub-b', 's@corp.com');

        $res = $this->handlerFor($ssoOnly)->unlink(
            new Request('DELETE', '/api/me/identities/' . $id, [], ''),
            ['id' => (string) $id]
        );
        self::assertSame(204, $res->getStatusCode(), $res->getBody());
    }
}
