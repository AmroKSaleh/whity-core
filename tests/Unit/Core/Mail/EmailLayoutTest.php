<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Mail;

use PHPUnit\Framework\TestCase;
use Whity\Core\Mail\EmailBranding;
use Whity\Core\Mail\EmailContent;
use Whity\Core\Mail\EmailLayout;

/**
 * {@see EmailLayout} renders transactional email into the branded shell (WC-email):
 * correct HTML + text, branding applied, content HTML-escaped, unsafe CTA URLs
 * dropped.
 */
final class EmailLayoutTest extends TestCase
{
    private function branding(): EmailBranding
    {
        return new EmailBranding(
            siteName: 'Acme',
            brandColor: '#123ABC',
            supportEmail: 'help@acme.test',
            footerText: 'Acme Inc · sovereign software',
        );
    }

    public function testRendersBrandedHtmlWithCtaAndFooter(): void
    {
        $layout = new EmailLayout();
        $r = $layout->render(
            new EmailContent(
                heading: 'Welcome to Acme',
                paragraphs: ['Your workspace is ready.', 'Invite your team to get started.'],
                ctaLabel: 'Open workspace',
                ctaUrl: 'https://acme.test/dashboard',
                footnote: 'Link expires in 24 hours.',
            ),
            $this->branding(),
        );

        // HTML: branding + content + CTA.
        self::assertStringContainsString('#123ABC', $r->html);           // brand colour
        self::assertStringContainsString('>A<', $r->html);               // brand tile initial
        self::assertStringContainsString('Welcome to Acme', $r->html);
        self::assertStringContainsString('Your workspace is ready.', $r->html);
        self::assertStringContainsString('href="https://acme.test/dashboard"', $r->html);
        self::assertStringContainsString('Open workspace', $r->html);
        self::assertStringContainsString('Link expires in 24 hours.', $r->html);
        self::assertStringContainsString('Acme Inc · sovereign software', $r->html);
        self::assertStringContainsString('mailto:help@acme.test', $r->html);
        self::assertStringContainsString('&copy; ' . gmdate('Y') . ' Acme', $r->html);

        // Text alternative conveys the same essentials.
        self::assertStringContainsString('Welcome to Acme', $r->text);
        self::assertStringContainsString('Your workspace is ready.', $r->text);
        self::assertStringContainsString('Open workspace: https://acme.test/dashboard', $r->text);
        self::assertStringContainsString('help@acme.test', $r->text);
    }

    public function testEscapesContentToPreventInjection(): void
    {
        $layout = new EmailLayout();
        $r = $layout->render(
            new EmailContent(
                heading: 'Hi <script>alert(1)</script>',
                paragraphs: ['A & B <b>bold</b>'],
            ),
            $this->branding(),
        );

        self::assertStringNotContainsString('<script>alert(1)</script>', $r->html);
        self::assertStringContainsString('&lt;script&gt;', $r->html);
        self::assertStringContainsString('A &amp; B &lt;b&gt;bold&lt;/b&gt;', $r->html);
    }

    public function testDropsUnsafeCtaUrl(): void
    {
        $layout = new EmailLayout();
        $r = $layout->render(
            new EmailContent(
                heading: 'Careful',
                ctaLabel: 'Click',
                ctaUrl: 'javascript:alert(1)',
            ),
            $this->branding(),
        );

        // The dangerous scheme must never reach the HTML, and no button is emitted.
        self::assertStringNotContainsString('javascript:', $r->html);
        self::assertStringNotContainsString('Click', $r->html);
        self::assertStringNotContainsString('Click:', $r->text);
    }

    public function testRendersCalloutWhenPresent(): void
    {
        $layout = new EmailLayout();
        $r = $layout->render(
            new EmailContent(
                heading: 'Invitation',
                callout: 'Sara invited you to Acme Team',
            ),
            $this->branding(),
        );

        self::assertStringContainsString('Sara invited you to Acme Team', $r->html);
        self::assertStringContainsString('Sara invited you to Acme Team', $r->text);
    }

    public function testBrandingFallsBackToDefaultColorInitial(): void
    {
        $b = new EmailBranding('', '#000000', '', '');
        self::assertSame('W', $b->initial());
    }
}
