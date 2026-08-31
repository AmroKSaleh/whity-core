<?php

declare(strict_types=1);

namespace Tests\Ops;

use PHPUnit\Framework\TestCase;
use Whity\Core\BuildIdentity;

/**
 * #1049: the BUILD-TIME half of `GET /api/build`.
 *
 * `BuildApiHandler` can only report what something froze into the image, so a
 * suite that only exercises the reader proves half a feature. The Dockerfile's
 * release stage runs `scripts/write-build-identity.php`, and if that script
 * silently writes nothing — or writes something the runtime rejects — the
 * endpoint answers `source: unknown` on every released image and nobody finds
 * out until they need it. That is exactly the shape v0.2.2 shipped `/web-build`
 * in: an endpoint that answered 200 while saying nothing.
 *
 * So this runs the real script the real way (a subprocess, an environment
 * variable, a file on disk) and then reads the result back through
 * {@see BuildIdentity} — the same class the running worker uses. The two halves
 * meet here or they meet nowhere.
 */
final class WriteBuildIdentityScriptTest extends TestCase
{
    private string $outputDir;

    protected function setUp(): void
    {
        $this->outputDir = sys_get_temp_dir() . '/whity_write_build_identity_' . uniqid('', true);
        mkdir($this->outputDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $file = $this->outputDir . '/' . BuildIdentity::FILE_NAME;
        if (is_file($file)) {
            unlink($file);
        }
        if (is_dir($this->outputDir)) {
            rmdir($this->outputDir);
        }
    }

    /**
     * The release path: the commit arrives as the build arg release.yml already
     * passes, and the file it writes is one the runtime accepts as a `build`
     * identity naming that commit.
     */
    public function testItFreezesTheBuildArgCommitIntoAFileTheRuntimeReadsBack(): void
    {
        $commit = str_repeat('a1b2c3d4', 5);

        [$exit] = $this->runScript($commit);
        self::assertSame(0, $exit);

        $identity = BuildIdentity::fromBakedFile($this->outputDir);
        self::assertInstanceOf(BuildIdentity::class, $identity);
        self::assertSame($commit, $identity->commit);
        self::assertSame(BuildIdentity::SOURCE_BUILD, $identity->source);
        self::assertNotNull($identity->builtAt);

        // The version is written for whoever reads the file directly; the
        // runtime reports the constant the worker loaded, so the file's copy is
        // informational and must not be mistaken for the commit.
        $decoded = json_decode((string) file_get_contents($this->outputDir . '/' . BuildIdentity::FILE_NAME), true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('core_version', $decoded);
        self::assertArrayHasKey('commit', $decoded);
        self::assertSame(\Whity\Core\CoreVersion::VERSION, $decoded['core_version']);
        self::assertNotSame($decoded['core_version'], $decoded['commit']);
    }

    /**
     * Two builds, two files, two commits — from one unchanged script. The build
     * side of the same guard `BuildApiHandlerTest` puts on the read side: a
     * capture that produced a constant would be as useless as the version
     * constant it replaces.
     */
    public function testDifferentBuildsProduceDifferentCommits(): void
    {
        $this->runScript(str_repeat('1', 40));
        $first = BuildIdentity::fromBakedFile($this->outputDir);

        $this->runScript(str_repeat('2', 40));
        $second = BuildIdentity::fromBakedFile($this->outputDir);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotSame($first->commit, $second->commit);
    }

    /**
     * NO COMMIT MEANS NO FILE. Writing `{"commit": null}` would produce an
     * artifact that looks like a captured identity and contains none, so the
     * script declines and says why on stderr — and the runtime falls through to
     * its honest `unknown`.
     */
    public function testItRefusesToWriteAFileItCannotFillIn(): void
    {
        [$exit, $stderr] = $this->runScript('');

        self::assertSame(0, $exit, 'a local build without the arg must not be a hard failure');
        self::assertFileDoesNotExist($this->outputDir . '/' . BuildIdentity::FILE_NAME);
        self::assertStringContainsString('no commit could be established', $stderr);
        self::assertNull(BuildIdentity::fromBakedFile($this->outputDir));
    }

    /**
     * A build arg that is not an object id is refused rather than frozen in. A
     * value like `$GITHUB_SHA` left unexpanded, or `unknown`, would otherwise be
     * reported to a monitor as this deployment's commit.
     */
    public function testANonCommitBuildArgIsRefused(): void
    {
        [, $stderr] = $this->runScript('${GITHUB_SHA}');

        self::assertFileDoesNotExist($this->outputDir . '/' . BuildIdentity::FILE_NAME);
        self::assertStringContainsString('no commit could be established', $stderr);
    }

    /**
     * Run the real script against a scratch tree.
     *
     * The target root is passed as an argument for two reasons: the
     * repository's own (gitignored) build-identity.json is never touched by a
     * test run, and — the one that matters — the scratch directory has no
     * `.git`, so the "no commit" cases actually exercise having no commit
     * instead of being quietly rescued by the checkout the suite runs inside.
     *
     * @return array{0: int, 1: string} Exit code and stderr.
     */
    private function runScript(string $commit): array
    {
        $script = dirname(__DIR__, 2) . '/scripts/write-build-identity.php';
        self::assertFileExists($script);

        $env = getenv();
        $env['WHITY_BUILD_COMMIT'] = $commit;

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, $script, $this->outputDir],
            $descriptors,
            $pipes,
            dirname(__DIR__, 2),
            $env
        );
        self::assertIsResource($process);

        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return [proc_close($process), is_string($stderr) ? $stderr : ''];
    }
}
