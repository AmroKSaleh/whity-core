<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Notification;

use PHPUnit\Framework\TestCase;
use Whity\Core\Notification\PassthroughRenderer;

/**
 * Unit tests for {@see PassthroughRenderer}: verbatim subject/body with `{{var}}`
 * interpolation from the notification data bag, unknown tokens left intact, and
 * a null bodyHtml passthrough.
 */
final class PassthroughRendererTest extends TestCase
{
    public function testInterpolatesKnownTokensFromData(): void
    {
        $rendered = (new PassthroughRenderer())->render('user.invited', 'email', null, [
            'subject' => 'Welcome, {{name}}',
            'body'    => 'Your code is {{ code }}.',
            'data'    => ['name' => 'Alice', 'code' => 42],
        ]);

        self::assertSame('Welcome, Alice', $rendered->subject);
        self::assertSame('Your code is 42.', $rendered->body);
        self::assertNull($rendered->bodyHtml);
    }

    public function testLeavesUnknownTokensUntouched(): void
    {
        $rendered = (new PassthroughRenderer())->render('t', 'email', null, [
            'subject' => 'Hi {{missing}}',
            'body'    => '',
            'data'    => [],
        ]);

        self::assertSame('Hi {{missing}}', $rendered->subject, 'an unknown token is left visible, not blanked');
    }

    public function testRendersBodyHtmlWhenProvided(): void
    {
        $rendered = (new PassthroughRenderer())->render('t', 'email', 'en-US', [
            'subject'  => 's',
            'body'     => 'plain {{x}}',
            'bodyHtml' => '<b>{{x}}</b>',
            'data'     => ['x' => 'Z'],
        ]);

        self::assertSame('<b>Z</b>', $rendered->bodyHtml);
    }

    public function testEmptyAndTokenlessStringsPassThroughUnchanged(): void
    {
        $rendered = (new PassthroughRenderer())->render('t', 'in_app', null, [
            'subject' => '',
            'body'    => 'no tokens here',
            'data'    => ['x' => 'unused'],
        ]);

        self::assertSame('', $rendered->subject);
        self::assertSame('no tokens here', $rendered->body);
    }

    public function testNonScalarDataValueIsNotInterpolated(): void
    {
        $rendered = (new PassthroughRenderer())->render('t', 'email', null, [
            'subject' => 'x {{obj}}',
            'body'    => '',
            'data'    => ['obj' => ['nested' => true]],
        ]);

        self::assertSame('x {{obj}}', $rendered->subject, 'a non-scalar value must not be stringified into the template');
    }
}
