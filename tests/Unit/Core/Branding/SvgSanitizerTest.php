<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Branding;

use PHPUnit\Framework\TestCase;
use Whity\Core\Branding\SvgRejectedException;
use Whity\Core\Branding\SvgSanitizer;

/**
 * Security corpus for the SVG sanitizer (Tenant Branding). Every known SVG-XSS
 * vector must be stripped or the document rejected. These are the gate for
 * accepting SVG uploads at all.
 */
final class SvgSanitizerTest extends TestCase
{
    private SvgSanitizer $san;

    protected function setUp(): void
    {
        $this->san = new SvgSanitizer();
    }

    public function testKeepsBenignShapes(): void
    {
        $in = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="#0af"/><path d="M0 0L10 10"/></svg>';
        $out = $this->san->sanitize($in);
        self::assertStringContainsString('<rect', $out);
        self::assertStringContainsString('<path', $out);
    }

    public function testStripsScriptElement(): void
    {
        $out = $this->san->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect/></svg>');
        self::assertStringNotContainsStringIgnoringCase('<script', $out);
        self::assertStringNotContainsString('alert(1)', $out);
    }

    public function testStripsOnloadAndEventHandlers(): void
    {
        $out = $this->san->sanitize('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect onclick="x()"/></svg>');
        self::assertStringNotContainsStringIgnoringCase('onload', $out);
        self::assertStringNotContainsStringIgnoringCase('onclick', $out);
    }

    public function testStripsForeignObject(): void
    {
        $out = $this->san->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><script>1</script></body></foreignObject></svg>');
        self::assertStringNotContainsStringIgnoringCase('foreignObject', $out);
        self::assertStringNotContainsStringIgnoringCase('<script', $out);
    }

    public function testStripsAnimateAndSet(): void
    {
        $out = $this->san->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><a><animate attributeName="href" to="javascript:alert(1)"/></a><set/></svg>');
        self::assertStringNotContainsStringIgnoringCase('<animate', $out);
        self::assertStringNotContainsStringIgnoringCase('<set', $out);
    }

    public function testStripsJavascriptAndExternalHrefButKeepsFragment(): void
    {
        $out = $this->san->sanitize('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><use xlink:href="#frag"/><use xlink:href="javascript:alert(1)"/><image href="https://evil.example/x.png"/></svg>');
        self::assertStringNotContainsStringIgnoringCase('javascript:', $out);
        self::assertStringNotContainsString('evil.example', $out);
        self::assertStringContainsString('#frag', $out);
    }

    public function testStripsDataUri(): void
    {
        $out = $this->san->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><image href="data:text/html,<script>1</script>"/></svg>');
        self::assertStringNotContainsStringIgnoringCase('data:text/html', $out);
    }

    public function testStripsCssExpressionAndUrlInStyle(): void
    {
        $out = $this->san->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><rect style="fill:url(http://evil/x);width:expression(alert(1))"/></svg>');
        self::assertStringNotContainsStringIgnoringCase('expression(', $out);
        self::assertStringNotContainsString('http://evil', $out);
    }

    public function testRejectsDoctypeXxe(): void
    {
        $this->expectException(SvgRejectedException::class);
        $this->san->sanitize('<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>');
    }

    public function testRejectsBillionLaughs(): void
    {
        $this->expectException(SvgRejectedException::class);
        $this->san->sanitize('<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;">]><svg xmlns="http://www.w3.org/2000/svg"><text>&lol2;</text></svg>');
    }

    public function testRejectsNonSvgRoot(): void
    {
        $this->expectException(SvgRejectedException::class);
        $this->san->sanitize('<html><body>nope</body></html>');
    }

    public function testRejectsMalformedXml(): void
    {
        $this->expectException(SvgRejectedException::class);
        $this->san->sanitize('<svg><rect></svg>');
    }

    public function testStripsStyleElementWithUrl(): void
    {
        $out = $this->san->sanitize('<svg xmlns="http://www.w3.org/2000/svg"><style>rect{fill:url(http://evil/x)}</style><rect/></svg>');
        self::assertStringNotContainsStringIgnoringCase('<style', $out);
        self::assertStringNotContainsString('http://evil', $out);
    }
}
