<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Core\Http\FrontendUrls;

/**
 * Every link the backend builds into a frontend page resolves through
 * FrontendUrls (headless, WHIT-693). The invariant that matters most: with no
 * new variable set, every link is exactly what it was before.
 */
final class FrontendUrlsTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function bundledPaths(): array
    {
        return [
            'email verification' => [FrontendUrls::EMAIL_VERIFICATION, '/verify-email'],
            'password reset' => [FrontendUrls::PASSWORD_RESET, '/reset-password'],
            '2fa recovery' => [FrontendUrls::TWO_FACTOR_RECOVERY, '/account-recovery'],
            'invitation' => [FrontendUrls::INVITATION_ACCEPT, '/accept-invitation'],
            'sso success' => [FrontendUrls::SSO_SUCCESS, '/dashboard'],
            'sso login' => [FrontendUrls::SSO_LOGIN, '/login'],
            'public form' => [FrontendUrls::PUBLIC_FORM, '/f'],
            'document verify' => [FrontendUrls::DOCUMENT_VERIFY, '/verify'],
            'error details' => [FrontendUrls::ERROR_DETAILS, '/admin/errors'],
        ];
    }

    #[DataProvider('bundledPaths')]
    public function testDefaultsToAppUrlPlusTheBundledAdminPath(string $link, string $path): void
    {
        $urls = new FrontendUrls(['APP_URL' => 'https://whity.example/']);

        $this->assertSame('https://whity.example', $urls->base());
        $this->assertSame('https://whity.example' . $path, $urls->link($link));
    }

    #[DataProvider('bundledPaths')]
    public function testFrontendUrlMovesEveryLinkOffTheBackendOrigin(string $link, string $path): void
    {
        $urls = new FrontendUrls([
            'APP_URL' => 'https://api.example',
            'FRONTEND_URL' => 'https://app.example/',
        ]);

        $this->assertSame('https://app.example', $urls->base());
        $this->assertSame('https://app.example' . $path, $urls->link($link));
    }

    #[DataProvider('bundledPaths')]
    public function testPerLinkOverrideWinsAndIsUsedVerbatim(string $link, string $path): void
    {
        $urls = new FrontendUrls([
            'APP_URL' => 'https://api.example',
            'FRONTEND_URL' => 'https://app.example',
            $link => 'https://www.example/custom/route/',
        ]);

        $this->assertSame('https://www.example/custom/route', $urls->link($link));
    }

    #[DataProvider('bundledPaths')]
    public function testNoAddressIsEmptyNeverARelativePath(string $link, string $path): void
    {
        $urls = new FrontendUrls([]);

        $this->assertSame('', $urls->base());
        $this->assertSame('', $urls->link($link));
        // Only a browser redirect may fall back to a same-origin path.
        $this->assertSame($path, $urls->linkOrPath($link));
    }

    public function testErrorDetailsKeepsTheLegacyWhityPublicUrl(): void
    {
        $urls = new FrontendUrls([
            'APP_URL' => 'https://api.example',
            'WHITY_PUBLIC_URL' => 'https://ops.example/',
        ]);

        $this->assertSame('https://ops.example/admin/errors', $urls->link(FrontendUrls::ERROR_DETAILS));
        // ...and it affects nothing else.
        $this->assertSame('https://api.example/dashboard', $urls->link(FrontendUrls::SSO_SUCCESS));
    }

    public function testFormAndQrLinksFollowTheirOverrides(): void
    {
        $urls = new FrontendUrls([
            'APP_URL' => 'https://api.example',
            'PUBLIC_FORM_URL' => 'https://www.example/forms',
            'DOCUMENT_VERIFY_URL' => 'https://www.example/check',
        ]);
        $pdo = new \PDO('sqlite::memory:');

        $form = new \Whity\Core\Form\PublicFormLink($urls->base(), $urls->link(FrontendUrls::PUBLIC_FORM));
        $qr = new \Whity\Core\Document\Qr\DocumentQrService(
            $pdo,
            new \Whity\Core\Document\Qr\DocumentQrTokenRepository($pdo),
            new \Whity\Core\Document\Qr\DocumentQrScanRepository($pdo),
            $urls->base(),
            $urls->link(FrontendUrls::DOCUMENT_VERIFY)
        );

        $this->assertSame('https://www.example/forms/abc', $form->publicUrl('abc'));
        $this->assertSame('https://www.example/check/tok', $qr->verificationUrl('tok'));

        // An override alone is enough address to mint, even with no origin.
        $bare = new \Whity\Core\Form\PublicFormLink('', 'https://www.example/forms');
        $this->assertTrue($bare->isConfigured());
        // And with neither, it is still refused rather than made relative.
        $this->assertFalse((new \Whity\Core\Form\PublicFormLink('', ''))->isConfigured());
    }

    public function testUnknownLinkIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new FrontendUrls([]))->link('NOT_A_LINK');
    }
}
