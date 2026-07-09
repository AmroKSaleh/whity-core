<?php

declare(strict_types=1);

namespace Tests\Unit\Storage\S3;

use PHPUnit\Framework\TestCase;
use Whity\Storage\S3\ObjectHttpResponse;
use Whity\Storage\S3\ObjectHttpTransport;
use Whity\Storage\S3\S3Config;
use Whity\Storage\S3\S3StorageDriver;
use Whity\Storage\StorageException;

/**
 * Behavioural tests for {@see S3StorageDriver} over a FAKE transport
 * (WC-b8c5a271): request shape (verb/URL/headers/signature), status→exception
 * mapping, metadata parsing from HEAD, copy semantics, and URL generation. The
 * SigV4 signature value itself is proven separately in SigV4SignerTest.
 */
final class S3StorageDriverTest extends TestCase
{
    private function pathStyleConfig(?string $publicBaseUrl = null): S3Config
    {
        return new S3Config(
            endpoint: 'https://s3.us-east-1.amazonaws.com',
            region: 'us-east-1',
            bucket: 'whity-bucket',
            accessKey: 'AKIDEXAMPLE',
            secretKey: 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
            pathStyle: true,
            publicBaseUrl: $publicBaseUrl,
        );
    }

    public function testPutSignsAndSendsToPathStyleUrl(): void
    {
        $t = new FakeTransport([new ObjectHttpResponse(200, [], '')]);
        $driver = new S3StorageDriver($this->pathStyleConfig(), $t);

        $driver->put('tenants/1/docs/a b.pdf', 'PDFBYTES', ['ContentType' => 'application/pdf']);

        $req = $t->requests[0];
        self::assertSame('PUT', $req['method']);
        // path-style: endpoint/bucket/key, spaces percent-encoded.
        self::assertSame('https://s3.us-east-1.amazonaws.com/whity-bucket/tenants/1/docs/a%20b.pdf', $req['url']);
        self::assertSame('application/pdf', $req['headers']['Content-Type']);
        self::assertSame(hash('sha256', 'PDFBYTES'), $req['headers']['x-amz-content-sha256']);
        self::assertStringContainsString('AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/', $req['headers']['Authorization']);
        self::assertSame('PDFBYTES', $req['body']);
    }

    public function testPutThrowsOnErrorStatus(): void
    {
        $t = new FakeTransport([new ObjectHttpResponse(403, [], 'AccessDenied')]);
        $driver = new S3StorageDriver($this->pathStyleConfig(), $t);

        $this->expectException(StorageException::class);
        $driver->put('tenants/1/x.pdf', 'x');
    }

    public function testGetReturnsBodyAndMapsNotFound(): void
    {
        $ok = new S3StorageDriver($this->pathStyleConfig(), new FakeTransport([new ObjectHttpResponse(200, [], 'HELLO')]));
        self::assertSame('HELLO', $ok->get('tenants/1/x.pdf'));

        $missing = new S3StorageDriver($this->pathStyleConfig(), new FakeTransport([new ObjectHttpResponse(404, [], '')]));
        $this->expectException(StorageException::class);
        $missing->get('tenants/1/missing.pdf');
    }

    public function testGetThrowsOnTruncatedBody(): void
    {
        // 200 with Content-Length larger than the returned body = truncated at the
        // transport read cap or a dropped connection → must NOT return partial data.
        $t = new FakeTransport([new ObjectHttpResponse(200, ['content-length' => '2048'], 'only-a-few-bytes')]);
        $driver = new S3StorageDriver($this->pathStyleConfig(), $t);

        $this->expectException(StorageException::class);
        $driver->get('tenants/1/big.pdf');
    }

    public function testGetAcceptsBodyMatchingContentLength(): void
    {
        $body = 'exact-bytes';
        $t = new FakeTransport([new ObjectHttpResponse(200, ['content-length' => (string) strlen($body)], $body)]);
        $driver = new S3StorageDriver($this->pathStyleConfig(), $t);

        self::assertSame($body, $driver->get('tenants/1/ok.pdf'));
    }

    public function testExistsMapsStatus(): void
    {
        self::assertTrue(
            (new S3StorageDriver($this->pathStyleConfig(), new FakeTransport([new ObjectHttpResponse(200, [], '')])))
                ->exists('tenants/1/x.pdf')
        );
        self::assertFalse(
            (new S3StorageDriver($this->pathStyleConfig(), new FakeTransport([new ObjectHttpResponse(404, [], '')])))
                ->exists('tenants/1/x.pdf')
        );
    }

    public function testDeleteToleratesMissing(): void
    {
        // 204 (deleted) and 404 (already gone) both succeed; 500 throws.
        (new S3StorageDriver($this->pathStyleConfig(), new FakeTransport([new ObjectHttpResponse(204, [], '')])))
            ->delete('tenants/1/x.pdf');
        (new S3StorageDriver($this->pathStyleConfig(), new FakeTransport([new ObjectHttpResponse(404, [], '')])))
            ->delete('tenants/1/x.pdf');

        $this->expectException(StorageException::class);
        (new S3StorageDriver($this->pathStyleConfig(), new FakeTransport([new ObjectHttpResponse(500, [], '')])))
            ->delete('tenants/1/x.pdf');
    }

    public function testMetadataParsedFromHeadHeaders(): void
    {
        $t = new FakeTransport([new ObjectHttpResponse(200, [
            'content-length' => '2048',
            'content-type'   => 'application/pdf',
            'last-modified'  => 'Wed, 21 Oct 2015 07:28:00 GMT',
        ], '')]);
        $t->repeatLast = true; // three HEAD calls
        $driver = new S3StorageDriver($this->pathStyleConfig(), $t);

        self::assertSame(2048, $driver->size('tenants/1/x.pdf'));
        self::assertSame('application/pdf', $driver->mimeType('tenants/1/x.pdf'));
        self::assertSame(strtotime('Wed, 21 Oct 2015 07:28:00 GMT'), $driver->lastModified('tenants/1/x.pdf'));
    }

    public function testCopySendsCopySourceHeader(): void
    {
        $t = new FakeTransport([new ObjectHttpResponse(200, [], '')]);
        $driver = new S3StorageDriver($this->pathStyleConfig(), $t);

        $driver->copy('tenants/1/a.pdf', 'tenants/1/b.pdf');

        $req = $t->requests[0];
        self::assertSame('PUT', $req['method']);
        self::assertStringEndsWith('/whity-bucket/tenants/1/b.pdf', $req['url']);
        self::assertSame('/whity-bucket/tenants/1/a.pdf', $req['headers']['x-amz-copy-source']);
    }

    public function testTemporaryUrlIsPresigned(): void
    {
        $driver = new S3StorageDriver($this->pathStyleConfig(), new FakeTransport([]));
        $url = $driver->temporaryUrl('tenants/1/docs/a.pdf', 900);

        self::assertStringStartsWith('https://s3.us-east-1.amazonaws.com/whity-bucket/tenants/1/docs/a.pdf?', $url);
        self::assertStringContainsString('X-Amz-Algorithm=AWS4-HMAC-SHA256', $url);
        self::assertStringContainsString('X-Amz-Signature=', $url);
        self::assertStringContainsString('X-Amz-Expires=900', $url);
    }

    public function testPublicUrlRequiresConfig(): void
    {
        $noPublic = new S3StorageDriver($this->pathStyleConfig(), new FakeTransport([]));
        $this->expectException(\RuntimeException::class);
        $noPublic->publicUrl('tenants/1/x.pdf');
    }

    public function testPublicUrlUsesConfiguredBase(): void
    {
        $driver = new S3StorageDriver($this->pathStyleConfig('https://cdn.example.com/'), new FakeTransport([]));
        self::assertSame(
            'https://cdn.example.com/tenants/1/docs/a.pdf',
            $driver->publicUrl('tenants/1/docs/a.pdf')
        );
    }

    public function testVirtualHostedStylePutsBucketInHost(): void
    {
        $config = new S3Config(
            endpoint: 'https://s3.amazonaws.com',
            region: 'us-east-1',
            bucket: 'whity-bucket',
            accessKey: 'AKIDEXAMPLE',
            secretKey: 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
            pathStyle: false,
        );
        $t = new FakeTransport([new ObjectHttpResponse(200, [], '')]);
        (new S3StorageDriver($config, $t))->put('tenants/1/x.pdf', 'x');

        $req = $t->requests[0];
        self::assertSame('https://whity-bucket.s3.amazonaws.com/tenants/1/x.pdf', $req['url']);
        self::assertSame('whity-bucket.s3.amazonaws.com', $req['headers']['Host']);
    }
}

/**
 * Records every request and returns queued responses (or repeats the last).
 */
final class FakeTransport implements ObjectHttpTransport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];
    public bool $repeatLast = false;

    /** @param list<ObjectHttpResponse> $responses */
    public function __construct(private array $responses)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body): ObjectHttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        if ($this->responses === []) {
            return new ObjectHttpResponse(200, [], '');
        }
        if ($this->repeatLast && count($this->responses) === 1) {
            return $this->responses[0];
        }
        return array_shift($this->responses);
    }
}
