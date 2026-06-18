<?php

declare(strict_types=1);

namespace Tests\Sdk\Http;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Http\UploadedFile;

/**
 * WC-217: the SDK uploaded-file value object.
 *
 * A small, framework-agnostic shape that wraps a single multipart file part
 * already spilled to a temp file. Plugins type-hint against it (not against
 * PHP's $_FILES array shape) so the upload contract is portable.
 */
final class UploadedFileTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->cleanup = [];
    }

    private function makeTempWith(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'wc217-uf-');
        file_put_contents($path, $contents);
        $this->cleanup[] = $path;
        return $path;
    }

    public function testAccessorsExposeTheConstructedMetadata(): void
    {
        $stream = $this->makeTempWith('hello world');

        $file = new UploadedFile(
            $stream,
            11,
            UPLOAD_ERR_OK,
            'package.zip',
            'application/zip'
        );

        $this->assertSame('package.zip', $file->getClientFilename());
        $this->assertSame('application/zip', $file->getClientMediaType());
        $this->assertSame(11, $file->getSize());
        $this->assertSame(UPLOAD_ERR_OK, $file->getError());
        $this->assertSame($stream, $file->getStreamPath());
    }

    public function testNullClientMetadataIsAllowed(): void
    {
        $stream = $this->makeTempWith('x');

        $file = new UploadedFile($stream, 1, UPLOAD_ERR_OK, null, null);

        $this->assertNull($file->getClientFilename());
        $this->assertNull($file->getClientMediaType());
    }

    public function testMoveToRelocatesTheTempFile(): void
    {
        $stream = $this->makeTempWith('payload-bytes');
        $target = sys_get_temp_dir() . '/wc217-target-' . bin2hex(random_bytes(6)) . '.bin';
        $this->cleanup[] = $target;

        $file = new UploadedFile($stream, 13, UPLOAD_ERR_OK, 'a.bin', 'application/octet-stream');
        $file->moveTo($target);

        $this->assertFileDoesNotExist($stream, 'The original temp file is gone after moveTo');
        $this->assertFileExists($target);
        $this->assertSame('payload-bytes', (string) file_get_contents($target));
    }

    public function testSecondMoveToThrows(): void
    {
        $stream = $this->makeTempWith('once');
        $target = sys_get_temp_dir() . '/wc217-target-' . bin2hex(random_bytes(6)) . '.bin';
        $this->cleanup[] = $target;

        $file = new UploadedFile($stream, 4, UPLOAD_ERR_OK, 'a.bin', 'application/octet-stream');
        $file->moveTo($target);

        $this->expectException(\RuntimeException::class);
        $file->moveTo($target . '.again');
    }
}
