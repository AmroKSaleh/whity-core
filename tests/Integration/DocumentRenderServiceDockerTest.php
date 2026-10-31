<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Whity\Core\Document\Render\DocumentRenderRejectedException;
use Whity\Core\Document\Render\RenderServiceClient;
use Whity\Core\Document\Render\RenderServiceUnavailableException;

/**
 * The REAL `whity_render` integration proof (ADR 0012): builds the actual
 * Docker image, runs it as a throwaway container, and asserts a genuine
 * Puppeteer render round-trip through the production
 * {@see RenderServiceClient} — not a fake client (see
 * {@see \Tests\Api\DocumentCreateApiRealEngineTest}, which exercises the PHP
 * issue path with a no-network double).
 *
 * DELIBERATELY OPT-IN, not part of any default suite run: building a
 * Chromium-bearing Docker image takes real time (and needs a working Docker
 * daemon), which would slow down and destabilise every ordinary `phpunit`
 * invocation for a change that has nothing to do with rendering. Set
 * RENDER_SERVICE_DOCKER_TEST=1 to actually run it:
 *
 *   RENDER_SERVICE_DOCKER_TEST=1 vendor/bin/phpunit --filter DocumentRenderServiceDockerTest
 *
 * Without that env var this whole class self-skips (a fast, harmless "S" in
 * every normal run — including the CI "test"/"postgres-integration" jobs,
 * which never set it). The render service's OWN CI job (a dedicated GitHub
 * Actions job gated on render-service/** changes — see
 * .github/workflows/automated-tests.yml) runs an equivalent real round-trip
 * on every PR that touches the render service, so this exact proof is not
 * skipped everywhere — just out of the default PHP suite's hot path.
 */
final class DocumentRenderServiceDockerTest extends TestCase
{
    private const IMAGE_TAG = 'whity-render:phpunit-test';
    private const HOST_PORT = 18131;
    private const SHARED_SECRET = 'phpunit_docker_test_render_secret_zz'; // >= 32 chars

    private static ?string $containerName = null;

    /**
     * The host that reaches the published port. Almost always '127.0.0.1' —
     * that's correct when THIS test process itself runs natively (a real
     * dev machine, or the "postgres-integration"-style CI job, where the
     * `docker run -p` publish binds on the same host `docker` itself is
     * called from). It resolves to 'host.docker.internal' instead in the one
     * case where it wouldn't be: this phpunit process is ITSELF running
     * inside a container (e.g. this repo's own `whity-core:dev` docker-run
     * convention) that reaches the docker DAEMON via a mounted socket to spin
     * up the sibling whity_render container — that sibling's published port
     * binds on the HOST, which is a DIFFERENT network namespace from this
     * (nested) caller's own loopback.
     */
    private static ?string $renderHost = null;

    public static function setUpBeforeClass(): void
    {
        if (getenv('RENDER_SERVICE_DOCKER_TEST') !== '1') {
            self::markTestSkipped(
                'Set RENDER_SERVICE_DOCKER_TEST=1 to run the real whity_render Docker round-trip '
                . '(builds the image + runs a throwaway container). Skipped by default so the '
                . 'standard suite stays fast and Docker-independent.'
            );
        }

        [$exitCode] = self::runCommand(['docker', 'version']);
        if ($exitCode !== 0) {
            self::fail('RENDER_SERVICE_DOCKER_TEST=1 was set but `docker` is not usable on this machine.');
        }

        $repoRoot = dirname(__DIR__, 2);
        [$buildExit, $buildOutput] = self::runCommand(
            ['docker', 'build', '-f', 'render-service/Dockerfile', '-t', self::IMAGE_TAG, '.'],
            $repoRoot,
            300
        );
        if ($buildExit !== 0) {
            self::fail("docker build failed (exit {$buildExit}):\n" . $buildOutput);
        }

        self::$containerName = 'whity_render_phpunit_' . bin2hex(random_bytes(4));
        [$runExit, $runOutput] = self::runCommand([
            'docker', 'run', '-d',
            '--name', self::$containerName,
            '-e', 'RENDER_SHARED_SECRET=' . self::SHARED_SECRET,
            '-p', self::HOST_PORT . ':8130',
            self::IMAGE_TAG,
        ]);
        if ($runExit !== 0) {
            self::$containerName = null;
            self::fail("docker run failed (exit {$runExit}):\n" . $runOutput);
        }

        self::waitForHealth();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$containerName !== null) {
            self::runCommand(['docker', 'rm', '-f', self::$containerName]);
            self::$containerName = null;
        }
    }

    /**
     * A real flowing render of what an issued document sends — Arabic text,
     * rich-text runs, math, a barcode and the verification QR — through
     * {@see RenderServiceClient::renderFlow()} and REAL Puppeteer/headless
     * Chromium, not a mock. Arabic is included (the hard cross-cutting RTL
     * requirement); legibility (not tofu) was verified by hand with a
     * screenshot when this proof was written, since a font-name string check
     * is not a reliable automatable signal for glyph shaping — this test
     * asserts structurally instead (valid, non-trivial PDF bytes with several
     * embedded font subsets, and the page count the service reports), per the
     * project's "no golden-image pixel diffing, structural/smoke checks only"
     * guidance.
     *
     * The old canvas mode (`POST /render`, the Document designer's
     * template + dataRows) is gone with the designer; documents are issued
     * from editor templates through the flowing mode only (editor M4c).
     */
    public function testRealFlowRenderRoundTripProducesAValidPdf(): void
    {
        $rendered = $this->client(self::SHARED_SECRET)->renderFlow([
            'page' => ['preset' => 'a4'],
            'direction' => 'rtl',
            'lang' => 'ar',
            'content' => [
                ['type' => 'heading', 'level' => 1, 'text' => 'مخبز المثال'],
                ['type' => 'paragraph', 'text' => 'Rich text sample', 'runs' => [
                    ['text' => 'Rich ', 'bold' => true],
                    ['text' => 'text ', 'italic' => true],
                    ['text' => 'sample'],
                ]],
                ['type' => 'math', 'tex' => 'E = mc^2'],
                ['type' => 'barcode', 'symbology' => 'code128', 'value' => 'INV-2026-0042', 'showText' => true],
                ['type' => 'qr', 'value' => 'https://docs.example.test/verify/abc', 'reference' => 'ABCD-EFGH'],
            ],
        ]);

        self::assertStringStartsWith('%PDF-', $rendered->bytes, 'render service response must be a real PDF');
        self::assertGreaterThan(5000, strlen($rendered->bytes), 'a real multi-block render should be well beyond a blank page');
        self::assertGreaterThanOrEqual(1, $rendered->pageCount, 'the service reports how many pages it produced');

        // Rough "did real content actually get shaped" sanity check — several
        // distinct font subsets (body text + KaTeX math, at least) rather
        // than an exact golden-image/pixel diff (too fragile across
        // environments, per project convention).
        $fontCount = substr_count($rendered->bytes, 'BaseFont');
        self::assertGreaterThanOrEqual(2, $fontCount, 'expected multiple embedded font subsets for a real multi-block render');
    }

    /**
     * A payload the service refuses comes back as a REJECTION carrying the
     * service's own reason, not as an outage: the issuer reports the two as
     * `rejected` and `unavailable`, and only the real service can prove the
     * client tells them apart.
     */
    public function testAnInvalidPayloadIsRejectedWithTheServicesReason(): void
    {
        try {
            $this->client(self::SHARED_SECRET)->renderFlow([
                'content' => [['type' => 'heading', 'level' => 9, 'text' => 'Too deep']],
            ]);
            self::fail('a heading level of 9 must be refused');
        } catch (DocumentRenderRejectedException $e) {
            self::assertStringContainsString('level', $e->clientMessage);
        }
    }

    /**
     * The wrong shared secret is refused by the service, and the client
     * reports it as the service being unavailable — what the PHP side relies
     * on for its 503 mapping when an instance is misconfigured.
     */
    public function testTheWrongSecretIsRefused(): void
    {
        $this->expectException(RenderServiceUnavailableException::class);

        $this->client('wrong-secret-wrong-secret-wrong-secret')->renderFlow([
            'content' => [['type' => 'paragraph', 'text' => 'Hello']],
        ]);
    }

    private function client(string $secret): RenderServiceClient
    {
        return new RenderServiceClient('http://' . self::$renderHost . ':' . self::HOST_PORT, $secret);
    }

    private static function waitForHealth(): void
    {
        $candidates = ['127.0.0.1', 'host.docker.internal'];
        $deadline = microtime(true) + 30;
        while (microtime(true) < $deadline) {
            foreach ($candidates as $host) {
                $health = @file_get_contents('http://' . $host . ':' . self::HOST_PORT . '/health');
                if (is_string($health) && str_contains($health, '"ok"')) {
                    self::$renderHost = $host;
                    return;
                }
            }
            usleep(500_000);
        }
        [, $logs] = self::runCommand(['docker', 'logs', (string) self::$containerName]);
        self::fail("whity_render container never became healthy (tried: " . implode(', ', $candidates) . ").\n" . $logs);
    }

    /**
     * @param list<string> $command
     * @return array{0: int, 1: string}
     */
    private static function runCommand(array $command, ?string $cwd = null, int $timeoutSeconds = 60): array
    {
        $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptorSpec, $pipes, $cwd);
        if (!is_resource($process)) {
            return [1, 'failed to start process'];
        }

        stream_set_timeout($pipes[1], $timeoutSeconds);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [$exitCode, ($stdout ?: '') . ($stderr ?: '')];
    }
}
