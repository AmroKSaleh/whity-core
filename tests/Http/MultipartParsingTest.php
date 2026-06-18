<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Whity\Http\JsonBody;
use Whity\Sdk\Http\MultipartConfig;
use Whity\Sdk\Http\MultipartParser;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\UploadedFile;

/**
 * WC-217: host-side multipart/form-data parsing.
 *
 * The parser keys off the request's Content-Type + raw body (NOT PHP's $_FILES
 * superglobal) so it is both unit-testable and FrankenPHP worker-safe. File
 * parts are spilled to temp files; text fields stay in the parsed body. A
 * global request-size cap and a per-file cap are enforced during the parse.
 */
final class MultipartParsingTest extends TestCase
{
    private const BOUNDARY = '----WC217Boundary7MA4YWxkTrZu0gW';

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

    /**
     * Build a well-formed multipart body with one text field and one file part.
     */
    private function multipartBody(string $fileContents): string
    {
        $eol = "\r\n";
        $b = '--' . self::BOUNDARY;

        return $b . $eol
            . 'Content-Disposition: form-data; name="name"' . $eol . $eol
            . 'my-plugin' . $eol
            . $b . $eol
            . 'Content-Disposition: form-data; name="package"; filename="plugin.zip"' . $eol
            . 'Content-Type: application/zip' . $eol . $eol
            . $fileContents . $eol
            . $b . '--' . $eol;
    }

    private function multipartRequest(string $body): Request
    {
        return new Request(
            'POST',
            '/api/admin/plugins/upload',
            ['Content-Type' => 'multipart/form-data; boundary=' . self::BOUNDARY],
            $body
        );
    }

    public function testNonMultipartRequestHasNoUploadedFiles(): void
    {
        $request = new Request('POST', '/api/x', ['Content-Type' => 'application/json'], '{"a":1}');
        $this->assertSame([], $request->getUploadedFiles());
    }

    public function testGetRequestHasNoUploadedFiles(): void
    {
        $request = new Request('GET', '/api/x');
        $this->assertSame([], $request->getUploadedFiles());
    }

    public function testParsesFilePartIntoUploadedFilesBag(): void
    {
        $fileContents = 'PK\x03\x04 fake zip bytes here';
        $request = $this->multipartRequest($this->multipartBody($fileContents));

        $files = $request->getUploadedFiles();
        $this->assertArrayHasKey('package', $files);

        $file = $files['package'];
        $this->assertInstanceOf(UploadedFile::class, $file);
        $this->cleanup[] = $file->getStreamPath();

        $this->assertSame('plugin.zip', $file->getClientFilename());
        $this->assertSame('application/zip', $file->getClientMediaType());
        $this->assertSame(strlen($fileContents), $file->getSize());
        $this->assertSame(UPLOAD_ERR_OK, $file->getError());
        $this->assertFileExists($file->getStreamPath());
        $this->assertSame($fileContents, (string) file_get_contents($file->getStreamPath()));
    }

    public function testTextFieldsAreParsedAlongsideTheFilePart(): void
    {
        $config = new MultipartConfig(maxRequestBytes: 1_000_000, maxFileBytes: 1_000_000);
        $result = (new MultipartParser($config))->parse(
            'multipart/form-data; boundary=' . self::BOUNDARY,
            $this->multipartBody('zip-bytes')
        );

        $this->assertSame(['name' => 'my-plugin'], $result->getFields(), 'Text fields are parsed');
        $this->assertArrayNotHasKey(
            'package',
            $result->getFields(),
            'File parts are not duplicated into the text fields'
        );

        $this->cleanup[] = $result->getUploadedFiles()['package']->getStreamPath();
    }

    public function testJsonBodyDecodeStillWorksForJsonRequests(): void
    {
        // The existing JSON text-field path is untouched by multipart support.
        $request = new Request('POST', '/api/x', ['Content-Type' => 'application/json'], '{"a":1,"b":"two"}');
        $this->assertSame(['a' => 1, 'b' => 'two'], JsonBody::decode($request->getBody()));
        $this->assertSame([], $request->getUploadedFiles());
    }

    public function testUrlencodedBodyIsNotTreatedAsMultipart(): void
    {
        $request = new Request(
            'POST',
            '/api/x',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            'a=1&b=two'
        );
        $this->assertSame([], $request->getUploadedFiles());
        $this->assertSame('a=1&b=two', $request->getBody(), 'getBody() is untouched for urlencoded requests');
    }

    public function testOversizedRequestBodyIsRejected(): void
    {
        $config = new MultipartConfig(maxRequestBytes: 64, maxFileBytes: 1024);
        $parser = new MultipartParser($config);

        $this->expectException(\Whity\Sdk\Http\Exception\MultipartException::class);
        $parser->parse(
            'multipart/form-data; boundary=' . self::BOUNDARY,
            $this->multipartBody(str_repeat('A', 256))
        );
    }

    public function testOversizedFilePartIsRejected(): void
    {
        // Request cap is generous; the per-file cap is what trips.
        $config = new MultipartConfig(maxRequestBytes: 1_000_000, maxFileBytes: 16);
        $parser = new MultipartParser($config);

        $this->expectException(\Whity\Sdk\Http\Exception\MultipartException::class);
        $parser->parse(
            'multipart/form-data; boundary=' . self::BOUNDARY,
            $this->multipartBody(str_repeat('A', 128))
        );
    }

    public function testParserSpillsFileToConfiguredTempDir(): void
    {
        $tmpDir = sys_get_temp_dir() . '/wc217-spill-' . bin2hex(random_bytes(6));
        mkdir($tmpDir);
        $config = new MultipartConfig(
            maxRequestBytes: 1_000_000,
            maxFileBytes: 1_000_000,
            tempDir: $tmpDir
        );
        $parser = new MultipartParser($config);

        $result = $parser->parse(
            'multipart/form-data; boundary=' . self::BOUNDARY,
            $this->multipartBody('spilled-bytes')
        );

        $file = $result->getUploadedFiles()['package'];
        $this->cleanup[] = $file->getStreamPath();

        $this->assertStringStartsWith($tmpDir, $file->getStreamPath());
        $this->assertSame(['name' => 'my-plugin'], $result->getFields());

        // tidy
        @unlink($file->getStreamPath());
        @rmdir($tmpDir);
    }
}
