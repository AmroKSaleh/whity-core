<?php

declare(strict_types=1);

namespace Tests\Unit\Storage\S3;

use PHPUnit\Framework\TestCase;
use Whity\Storage\S3\SigV4Signer;

/**
 * Proves the hand-rolled SigV4 core against AWS's published test-suite vector
 * (WC-b8c5a271). If this passes, the canonical-request → string-to-sign → signing-
 * key → signature chain is correct; the presigned path reuses the same primitive.
 */
final class SigV4SignerTest extends TestCase
{
    // AWS SigV4 test-suite credentials (aws-sig-v4-test-suite / botocore).
    private const ACCESS_KEY = 'AKIDEXAMPLE';
    private const SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

    public function testAuthorizationHeaderMatchesGetVanillaVector(): void
    {
        $signer = new SigV4Signer(self::ACCESS_KEY, self::SECRET_KEY, 'us-east-1', 'service');

        $auth = $signer->authorizationHeader(
            'GET',
            '/',
            [],
            [
                'Host'       => 'example.amazonaws.com',
                'X-Amz-Date' => '20150830T123600Z',
            ],
            hash('sha256', ''),
            '20150830T123600Z',
        );

        // Verbatim expected value from the AWS SigV4 test suite `get-vanilla`.
        self::assertSame(
            'AWS4-HMAC-SHA256 '
            . 'Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, '
            . 'SignedHeaders=host;x-amz-date, '
            . 'Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
            $auth,
        );
    }

    public function testSignedHeadersAreLowercasedSortedAndSemicolonJoined(): void
    {
        $signer = new SigV4Signer(self::ACCESS_KEY, self::SECRET_KEY, 'us-east-1', 's3');

        $auth = $signer->authorizationHeader(
            'PUT',
            '/bucket/key.pdf',
            [],
            [
                'X-Amz-Content-Sha256' => 'UNSIGNED-PAYLOAD',
                'Host'                 => 'bucket.s3.amazonaws.com',
                'X-Amz-Date'           => '20150830T123600Z',
                'Content-Type'         => 'application/pdf',
            ],
            'UNSIGNED-PAYLOAD',
            '20150830T123600Z',
        );

        self::assertStringContainsString(
            'SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date',
            $auth,
        );
        self::assertMatchesRegularExpression('/Signature=[0-9a-f]{64}$/', $auth);
    }

    public function testPresignQueryHasRequiredParamsAndHexSignature(): void
    {
        $signer = new SigV4Signer(self::ACCESS_KEY, self::SECRET_KEY, 'us-east-1', 's3');

        $q = $signer->presignQuery('GET', '/bucket/tenants/1/docs/a.pdf', 'bucket.s3.amazonaws.com', 900, '20150830T123600Z');

        self::assertSame('AWS4-HMAC-SHA256', $q['X-Amz-Algorithm']);
        self::assertSame('AKIDEXAMPLE/20150830/us-east-1/s3/aws4_request', $q['X-Amz-Credential']);
        self::assertSame('20150830T123600Z', $q['X-Amz-Date']);
        self::assertSame('900', $q['X-Amz-Expires']);
        self::assertSame('host', $q['X-Amz-SignedHeaders']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $q['X-Amz-Signature']);
    }

    public function testPresignSignatureChangesWithExpiryAndKey(): void
    {
        $signer = new SigV4Signer(self::ACCESS_KEY, self::SECRET_KEY, 'us-east-1', 's3');

        $a = $signer->presignQuery('GET', '/bucket/a.pdf', 'bucket.s3.amazonaws.com', 900, '20150830T123600Z');
        $b = $signer->presignQuery('GET', '/bucket/a.pdf', 'bucket.s3.amazonaws.com', 901, '20150830T123600Z');
        $c = $signer->presignQuery('GET', '/bucket/b.pdf', 'bucket.s3.amazonaws.com', 900, '20150830T123600Z');

        self::assertNotSame($a['X-Amz-Signature'], $b['X-Amz-Signature'], 'expiry is signed');
        self::assertNotSame($a['X-Amz-Signature'], $c['X-Amz-Signature'], 'object path is signed');
    }
}
