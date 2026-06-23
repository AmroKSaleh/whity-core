<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Http\StreamedResponse;

/**
 * Unit tests for {@see StreamedResponse}.
 */
final class StreamedResponseTest extends TestCase
{
    // ── construction & accessors ────────────────────────────────────────────

    public function testGetBodyReturnsEmptyString(): void
    {
        $r = new StreamedResponse(200, static function () {});

        $this->assertSame('', $r->getBody());
    }

    public function testGetStatusCode(): void
    {
        $r = new StreamedResponse(206, static function () {});

        $this->assertSame(206, $r->getStatusCode());
    }

    public function testGetHeadersNormalised(): void
    {
        $r = new StreamedResponse(200, static function () {}, ['Content-Type' => 'application/octet-stream']);

        $this->assertArrayHasKey('content-type', $r->getHeaders());
        $this->assertSame('application/octet-stream', $r->getHeaders()['content-type']);
    }

    // ── send ────────────────────────────────────────────────────────────────

    public function testSendCallsStreamer(): void
    {
        $called = false;
        $r = new StreamedResponse(200, static function () use (&$called) {
            $called = true;
        });

        ob_start();
        $r->send();
        ob_end_clean();

        $this->assertTrue($called);
    }

    public function testSendEmitsStreamerOutput(): void
    {
        $r = new StreamedResponse(200, static function () {
            echo 'hello stream';
        });

        ob_start();
        $r->send();
        $out = ob_get_clean();

        $this->assertSame('hello stream', $out);
    }

    // ── withHeaders ─────────────────────────────────────────────────────────

    public function testWithHeadersReturnsSameType(): void
    {
        $r = new StreamedResponse(200, static function () {});
        $r2 = $r->withHeaders(['X-Foo' => 'bar']);

        $this->assertInstanceOf(StreamedResponse::class, $r2);
    }

    public function testWithHeadersMergesHeaders(): void
    {
        $r = new StreamedResponse(200, static function () {}, ['Content-Type' => 'text/plain']);
        $r2 = $r->withHeaders(['X-Custom' => 'yes']);

        $this->assertSame('text/plain', $r2->getHeaders()['content-type']);
        $this->assertSame('yes', $r2->getHeaders()['x-custom']);
    }

    public function testWithHeadersExtraOverridesExisting(): void
    {
        $r = new StreamedResponse(200, static function () {}, ['Content-Type' => 'text/plain']);
        $r2 = $r->withHeaders(['Content-Type' => 'application/octet-stream']);

        $this->assertSame('application/octet-stream', $r2->getHeaders()['content-type']);
    }

    public function testWithHeadersStreamerIsPreserved(): void
    {
        $called = false;
        $r = new StreamedResponse(200, static function () use (&$called) {
            $called = true;
        });
        $r2 = $r->withHeaders(['X-Foo' => 'bar']);

        ob_start();
        $r2->send();
        ob_end_clean();

        $this->assertTrue($called);
    }

    public function testOriginalNotMutatedByWithHeaders(): void
    {
        $r = new StreamedResponse(200, static function () {}, ['Content-Type' => 'text/plain']);
        $r->withHeaders(['X-Added' => '1']);

        $this->assertArrayNotHasKey('x-added', $r->getHeaders());
    }

    // ── fromFile ─────────────────────────────────────────────────────────────

    public function testFromFileReturns200ForNoRange(): void
    {
        $path = $this->makeTempFile('hello file');
        $r = StreamedResponse::fromFile($path, 'text/plain', 'attachment', 'test.txt');

        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('text/plain', $r->getHeaders()['content-type']);
        $this->assertSame('attachment; filename="test.txt"', $r->getHeaders()['content-disposition']);
        $this->assertSame('10', $r->getHeaders()['content-length']);
        $this->assertSame('bytes', $r->getHeaders()['accept-ranges']);
    }

    public function testFromFileStreamsFullContent(): void
    {
        $path = $this->makeTempFile('hello file');
        $r = StreamedResponse::fromFile($path);

        ob_start();
        $r->send();
        $out = ob_get_clean();

        $this->assertSame('hello file', $out);
    }

    public function testFromFileRange206(): void
    {
        $path = $this->makeTempFile('0123456789');
        $r = StreamedResponse::fromFile($path, 'application/octet-stream', 'attachment', null, 'bytes=2-5');

        $this->assertSame(206, $r->getStatusCode());
        $this->assertSame('bytes 2-5/10', $r->getHeaders()['content-range']);
        $this->assertSame('4', $r->getHeaders()['content-length']);
    }

    public function testFromFileRangeStreamsOnlyRequestedBytes(): void
    {
        $path = $this->makeTempFile('0123456789');
        $r = StreamedResponse::fromFile($path, 'application/octet-stream', 'attachment', null, 'bytes=2-5');

        ob_start();
        $r->send();
        $out = ob_get_clean();

        $this->assertSame('2345', $out);
    }

    public function testFromFileRangeOpenEnd(): void
    {
        $path = $this->makeTempFile('0123456789');
        $r = StreamedResponse::fromFile($path, 'application/octet-stream', 'attachment', null, 'bytes=7-');

        $this->assertSame(206, $r->getStatusCode());
        $this->assertSame('bytes 7-9/10', $r->getHeaders()['content-range']);
        $this->assertSame('3', $r->getHeaders()['content-length']);
    }

    public function testFromFileRangeSuffixForm(): void
    {
        $path = $this->makeTempFile('0123456789');
        // bytes=-3 means last 3 bytes: 7-9
        $r = StreamedResponse::fromFile($path, 'application/octet-stream', 'attachment', null, 'bytes=-3');

        $this->assertSame(206, $r->getStatusCode());
        $this->assertSame('bytes 7-9/10', $r->getHeaders()['content-range']);
    }

    public function testFromFileRangeOutOfBoundsReturns416(): void
    {
        $path = $this->makeTempFile('0123456789');
        $r = StreamedResponse::fromFile($path, 'application/octet-stream', 'attachment', null, 'bytes=20-30');

        $this->assertSame(416, $r->getStatusCode());
        $this->assertSame('bytes */10', $r->getHeaders()['content-range']);
    }

    public function testFromFileThrowsForMissingFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StreamedResponse::fromFile('/nonexistent/path/to/file.bin');
    }

    public function testFromFileUsesBasenameWhenFilenameOmitted(): void
    {
        $path = $this->makeTempFile('data');
        $r = StreamedResponse::fromFile($path, 'text/plain');

        $this->assertStringContainsString('filename="', $r->getHeaders()['content-disposition'] ?? '');
    }

    // ── xAccelRedirect ───────────────────────────────────────────────────────

    public function testXAccelRedirectSetsHeader(): void
    {
        $r = StreamedResponse::xAccelRedirect('/internal/file.bin', 'application/pdf', 'inline', 'report.pdf');

        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('/internal/file.bin', $r->getHeaders()['x-accel-redirect']);
        $this->assertSame('application/pdf', $r->getHeaders()['content-type']);
        $this->assertSame('inline; filename="report.pdf"', $r->getHeaders()['content-disposition']);
    }

    public function testXAccelRedirectNoFilenameUsesDispositionOnly(): void
    {
        $r = StreamedResponse::xAccelRedirect('/internal/file.bin', 'application/pdf', 'attachment');

        $this->assertSame('attachment', $r->getHeaders()['content-disposition']);
    }

    public function testXAccelRedirectStreamerEmitsNothing(): void
    {
        $r = StreamedResponse::xAccelRedirect('/internal/file.bin');

        ob_start();
        $r->send();
        $out = ob_get_clean();

        $this->assertSame('', $out);
    }

    // ── Response::withHeaders (parent) ───────────────────────────────────────

    public function testParentWithHeadersReturnsCoreResponse(): void
    {
        $r = \Whity\Core\Response::json(['ok' => true]);
        $r2 = $r->withHeaders(['X-Custom' => 'yes']);

        $this->assertSame('yes', $r2->getHeaders()['x-custom']);
        $this->assertSame('application/json', $r2->getHeaders()['content-type']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeTempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wc_stream_test_');
        assert($path !== false);
        file_put_contents($path, $content);
        $this->registerCleanup($path);
        return $path;
    }

    /** @var list<string> */
    private array $tempFiles = [];

    private function registerCleanup(string $path): void
    {
        $this->tempFiles[] = $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
}
