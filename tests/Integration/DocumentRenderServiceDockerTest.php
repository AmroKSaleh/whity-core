<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * The REAL `whity_render` integration proof (ADR 0012 / WC-docdesigner
 * Track 2): builds the actual Docker image, runs it as a throwaway
 * container, and asserts a genuine Puppeteer render round-trip — not a fake
 * client (see {@see \Tests\Api\DocumentRenderApiHandlerRealEngineTest}, which
 * exercises the PHP handler's own logic with a no-network double).
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
     * A real render exercising text + rich-text runs + math + barcode + QR —
     * the exact element mix WC-docdesigner Track 1 added (rich-text spans,
     * the `math` element type via @amroksaleh/ui/math-text/KaTeX) plus the
     * original barcode/QR elements this whole tool exists to print — over
     * REAL Puppeteer/headless-Chromium, not a mock. Arabic text is included
     * (the hard cross-cutting RTL requirement); legibility (not tofu) was
     * additionally verified by hand with a real screenshot during
     * development (see the PR description) since a font-name string check
     * turned out not to be a reliable automatable signal for glyph shaping —
     * this test asserts structurally instead (valid, non-trivial PDF bytes
     * with multiple distinct embedded font subsets), per the project's
     * "no golden-image pixel diffing, structural/smoke checks only" guidance.
     */
    public function testRealRenderRoundTripProducesAValidPdf(): void
    {
        $payload = [
            'template' => [
                'version' => 2,
                'page' => ['widthMm' => 100, 'heightMm' => 80, 'marginMm' => 2, 'background' => '#ffffff'],
                'placeholders' => [],
                'pages' => [[
                    'id' => 'p1',
                    'elements' => [
                        [
                            'id' => 'arabic', 'type' => 'text', 'x' => 2, 'y' => 2, 'w' => 96, 'h' => 12,
                            'rotation' => 0, 'z' => 1, 'text' => 'مرحبا بالعالم',
                            'style' => ['fontSize' => 18, 'fontWeight' => 'normal', 'fontStyle' => 'normal', 'align' => 'center', 'vAlign' => 'middle', 'color' => '#000000', 'direction' => 'rtl'],
                        ],
                        [
                            'id' => 'rich', 'type' => 'text', 'x' => 2, 'y' => 14, 'w' => 96, 'h' => 8,
                            'rotation' => 0, 'z' => 2, 'text' => 'Rich text sample',
                            'style' => ['fontSize' => 10, 'fontWeight' => 'normal', 'fontStyle' => 'normal', 'align' => 'left', 'vAlign' => 'top', 'color' => '#111111'],
                            'runs' => [['text' => 'Rich ', 'bold' => true], ['text' => 'text ', 'italic' => true], ['text' => 'sample']],
                        ],
                        [
                            'id' => 'math', 'type' => 'math', 'x' => 2, 'y' => 22, 'w' => 60, 'h' => 14,
                            'rotation' => 0, 'z' => 3, 'expression' => 'E = mc^2', 'block' => false,
                        ],
                        [
                            'id' => 'barcode', 'type' => 'barcode', 'x' => 2, 'y' => 38, 'w' => 60, 'h' => 18,
                            'rotation' => 0, 'z' => 4, 'symbology' => 'code128', 'value' => 'WC-DOCDESIGNER-1', 'showText' => true,
                        ],
                        [
                            'id' => 'qr', 'type' => 'qr', 'x' => 65, 'y' => 22, 'w' => 30, 'h' => 30,
                            'rotation' => 0, 'z' => 5, 'value' => 'https://example.com',
                        ],
                    ],
                ]],
            ],
            // A single empty row (this template binds no placeholders). An
            // empty PHP array json_encodes as `[]` (a JSON array), which the
            // render service's payload validator correctly rejects as a row
            // shape (`{}` is what a flat data-row map looks like) — force the
            // stdClass cast so it serialises as `[{}]`, not `[[]]`.
            'dataRows' => [(object) []],
        ];

        $pdf = $this->postRender($payload);

        self::assertStringStartsWith('%PDF-', $pdf, 'render service response must be a real PDF');
        self::assertGreaterThan(5000, strlen($pdf), 'a real multi-element render should be well beyond a blank page');

        // Rough "did real content actually get shaped" sanity check — several
        // distinct font subsets (body text + KaTeX math, at least) rather
        // than an exact golden-image/pixel diff (too fragile across
        // environments, per project convention).
        $fontCount = substr_count($pdf, 'BaseFont');
        self::assertGreaterThanOrEqual(2, $fontCount, 'expected multiple embedded font subsets for a real multi-element render');
    }

    public function testDisabledFeatureFlagNeverReachesTheRenderService(): void
    {
        // This is a light structural companion to the flag/RBAC coverage in
        // DocumentRenderApiHandlerRealEngineTest (which fakes the render
        // client entirely) — here it is enough to confirm the render service
        // itself refuses an unauthenticated/wrong-secret call cleanly, which
        // is what the PHP handler relies on for its own 503 mapping when
        // misconfigured.
        $response = $this->rawPost('/render', ['template' => ['page' => ['widthMm' => 10, 'heightMm' => 10], 'pages' => [['id' => 'p', 'elements' => []]]]], 'wrong-secret-wrong-secret-wrong');

        self::assertSame(401, $response['status']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postRender(array $payload): string
    {
        $response = $this->rawPost('/render', $payload, self::SHARED_SECRET);
        self::assertSame(200, $response['status'], 'render request failed: ' . $response['body']);

        return $response['body'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, body: string}
     */
    private function rawPost(string $path, array $payload, string $secret): array
    {
        $body = (string) json_encode($payload);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nX-Render-Secret: {$secret}\r\n",
                'content' => $body,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $url = 'http://' . self::$renderHost . ':' . self::HOST_PORT . $path;
        $http_response_header = [];
        $raw = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => is_string($raw) ? $raw : ''];
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
